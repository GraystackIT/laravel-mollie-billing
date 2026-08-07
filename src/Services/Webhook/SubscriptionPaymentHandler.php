<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SubscriptionPaymentHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly CouponService $couponService,
        protected readonly VatCalculationService $vatService,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly WalletUsageService $walletService,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly MollieSubscriptionPatcher $patcher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for recurring payment, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? (string) ($metadata['plan_code'] ?? '');
        $interval = $billable->getBillingSubscriptionInterval() ?? (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();

        $newPaymentCountry = strtoupper((string) ($payment->countryCode ?? ''));
        if ($newPaymentCountry !== '' && $newPaymentCountry !== strtoupper((string) ($billable->tax_country_payment ?? ''))) {
            $billable->forceFill(['tax_country_payment' => $newPaymentCountry])->save();
        }

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during recurring', ['error' => $e->getMessage()]);
        }

        $seats = $this->catalog->includedSeats($planCode) + $extraSeats;
        $expectedNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);

        $pendingTrialCoupon = $this->resolvePendingTrialCoupon(
            $billable, $planCode, $interval, $addonCodes, $extraSeats
        );
        $pendingTrialDiscountNet = $pendingTrialCoupon !== null
            ? max(0, (int) $pendingTrialCoupon['discount_net'])
            : 0;

        $couponDiscountNet = $this->couponService->computeMarkerDiscount($billable, $expectedNet);
        $netForCharge = max(0, $expectedNet - $couponDiscountNet - $pendingTrialDiscountNet);
        $vat = $this->vatService->calculate((string) ($billable->getBillingCountry() ?? ''), $netForCharge, $billable);

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        if ($couponDiscountNet > 0) {
            $markerCode = (string) ($this->couponService->getActiveRecurringCouponMarker($billable)['code'] ?? '');
            $lineItems[] = $this->couponLine($couponDiscountNet, $markerCode);
        }
        if ($pendingTrialCoupon !== null && $pendingTrialDiscountNet > 0) {
            $lineItems[] = $this->couponLine(
                $pendingTrialDiscountNet,
                (string) $pendingTrialCoupon['coupon']->code,
            );
        }

        // Reconciled, not merely reported. The lines above are rebuilt from the
        // catalog while Mollie charges whatever the subscription was last PATCHed
        // to, so a price change whose PATCH never landed makes them disagree — and
        // the money that cleared is the one fact. Booking the catalog amount instead
        // over-declared output VAT on every renewal and left `refunded_net` headroom
        // Mollie cannot honour, so a full refund would fail.
        //
        // The expected gross comes from the lines themselves: the recurring amount is
        // built from these same lines (MollieSubscriptionPatcher) and the invoice
        // header sums their per-line VAT, so `round(Σ net × rate)` was a third number
        // matching neither — six lines drift past the 1-cent slack every time.
        $lineItems = $this->support->reconcileWithPaidAmount(
            $payment,
            $billable,
            $lineItems,
            $this->support->netFromLineItems($lineItems),
            (float) ($vat['rate'] ?? 0.0),
        );

        $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);

        if ($couponDiscountNet > 0) {
            try {
                $this->couponService->redeemRecurringCouponForRenewal(
                    $billable,
                    $expectedNet,
                    (int) $invoice->id,
                );
            } catch (\Throwable $e) {
                Log::warning('Recurring coupon redemption failed during renewal webhook', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($pendingTrialCoupon !== null) {
            $this->firstPaymentCouponPricing->redeem(
                $billable,
                $pendingTrialCoupon,
                $planCode,
                $interval,
                (int) $invoice->id,
            );
            $this->clearPendingTrialCouponSlot($billable);
        }

        if ($this->couponService->markerExpired($billable)) {
            try {
                $this->patcher->updateRecurringAmount(
                    billable: $billable,
                    planCode: $planCode,
                    interval: $interval,
                    addons: $addonCodes,
                    extraSeats: $extraSeats,
                    intervalChanged: false,
                    couponDiscountNet: 0,
                );
                $this->couponService->clearActiveRecurringCouponMarker($billable);
            } catch (\Throwable $e) {
                Log::warning('Mollie reset PATCH after coupon expiry failed — queued for retry', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_subscription_patch'] = [
                    'reason' => 'recurring_coupon_expiry_reset',
                    'plan_code' => $planCode,
                    'interval' => $interval,
                    'addons' => $addonCodes,
                    'extra_seats' => $extraSeats,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                if ($this->catalog->usageRollover((string) $type)) {
                    $wallet = $billable->getWallet((string) $type);
                    if ($wallet !== null) {
                        $purchasedRemaining = WalletUsageService::computePurchasedRemaining(
                            WalletUsageService::getPurchasedBalance($wallet),
                            (int) $wallet->balanceInt,
                        );
                        WalletUsageService::setPurchasedBalance($wallet, $purchasedRemaining);
                    }

                    $this->walletService->credit($billable, (string) $type, (int) $units, 'subscription_renewal_rollover');
                } else {
                    $this->walletService->resetAndCredit($billable, (string) $type, (int) $units, 'subscription_renewal');
                }
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $paidAt = $payment->paidAt ?? null;
        $periodStartsAt = $paidAt ? Carbon::parse((string) $paidAt)->setTimezone('UTC') : BillingTime::nowUtc();

        $derivedSeatCount = $invoice->deriveSeatCount();
        $meta = $billable->getBillingSubscriptionMeta();
        if ($derivedSeatCount !== null) {
            $meta['seat_count'] = $derivedSeatCount;
        }

        $currentStatus = $billable->getBillingSubscriptionStatus();
        $wasTrial = $currentStatus === SubscriptionStatus::Trial;
        $wasPastDue = $currentStatus === SubscriptionStatus::PastDue;

        // A successful recurring charge recovers a PastDue billable — drop the
        // failure markers so the dashboard banner and overage retry job stop
        // treating it as overdue. Mirrors ProrataChargeHandler's cleanup.
        if ($wasPastDue) {
            unset($meta['payment_failure'], $meta['past_due_since']);
        }

        $updates = [
            'subscription_period_starts_at' => $periodStartsAt,
            'subscription_meta' => $meta,
        ];

        if ($wasTrial || $wasPastDue) {
            $updates['subscription_status'] = SubscriptionStatus::Active;
        }
        if ($wasTrial) {
            $updates['trial_ends_at'] = null;
            event(new TrialConverted($billable, $planCode));
        }

        $billable->forceFill($updates)->save();

        event(new PaymentSucceeded($billable, $invoice));

        if ($wasTrial) {
            $this->notifyTrialConverted($billable, $invoice);
        }

        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    /**
     * A recurring charge failed. Only a billable that is actually being billed
     * can go PastDue: `Cancelled` still inside its grace period, `Expired`,
     * `New` and already-`PastDue`-with-markers states must survive untouched —
     * a stale delivery for one of those would otherwise revoke access (or reset
     * `past_due_since` and thus the auto-cancel clock) for a charge that no
     * longer relates to the current subscription.
     */
    public function failed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $status = $billable->getBillingSubscriptionStatus();
        if (! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::PastDue], true)) {
            Log::info('Recurring payment failure ignored — subscription is not in a billable state', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_status' => $status->value,
            ]);

            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['payment_failure'] = [
            'payment_id' => (string) $payment->id,
            'failed_at' => BillingTime::nowUtc()->toIso8601String(),
            'reason' => (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown'),
        ];
        $meta['past_due_since'] = $meta['past_due_since']
            ?? BillingTime::nowUtc()->toIso8601String();

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentFailed($billable, (string) $payment->id, $meta['payment_failure']['reason']));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            // The amount comes from the payment: a failed charge produces no invoice,
            // so the notification has nothing to look up and would say "an unknown
            // amount" (it used to quote an unrelated invoice instead).
            Notification::send($recipients, MollieBilling::resolveNotification(
                SubscriptionPaymentFailedNotification::class,
                $billable,
                (string) $payment->id,
                $this->support->amountFromMolliePayment($payment),
                (string) ($payment->amount->currency ?? config('mollie-billing.currency')),
            ));
        }
    }

    /**
     * The first recurring charge after a trial has settled. `TrialConverted` is
     * the app-facing event; the notification goes to the billable's billing
     * admins like every other customer-facing billing mail. The invoice of this
     * exact charge is handed over so the mail states the amount that was really
     * collected instead of looking up "the latest invoice" later.
     */
    protected function notifyTrialConverted(Billable $billable, \GraystackIT\MollieBilling\Models\BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients === []) {
            return;
        }

        Notification::send(
            $recipients,
            MollieBilling::resolveNotification(TrialConvertedNotification::class, $billable, $invoice),
        );
    }

    /**
     * Negative discount line for a renewal invoice.
     *
     * Emits the canonical key set (`label`, `quantity`, `unit_price`,
     * `unit_price_net`, `total_net`) the other handlers use: InvoiceService
     * derives the header net from `total_net` and cross-checks it against
     * `quantity × unit_price`, so a line missing those keys is silently dropped
     * from both totals — the header would stay undiscounted while the persisted
     * lines carry the discount, and the mismatch guard could never fire. `label`
     * keeps the PDF from rendering the raw kind ("coupon") as the line title.
     *
     * @return array<string, mixed>
     */
    protected function couponLine(int $discountNet, string $couponCode): array
    {
        return [
            'kind' => 'coupon',
            'code' => $couponCode !== '' ? $couponCode : null,
            'label' => $couponCode !== '' ? 'Coupon '.$couponCode : 'Coupon discount',
            'quantity' => 1,
            'unit_price' => -$discountNet,
            'unit_price_net' => -$discountNet,
            'total_net' => -$discountNet,
        ];
    }

    /**
     * @param  array<int, string>  $addonCodes
     * @return array{coupon:\GraystackIT\MollieBilling\Models\Coupon, discount_net:int, recurring_discount_net:int, order_amount_net:int}|null
     */
    protected function resolvePendingTrialCoupon(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): ?array {
        $slot = $billable->getBillingSubscriptionMeta()['pending_first_charge_coupon'] ?? null;
        if (! is_array($slot) || empty($slot['code'])) {
            return null;
        }

        try {
            return $this->firstPaymentCouponPricing->price(
                $billable, (string) $slot['code'], $planCode, $interval, $addonCodes, $extraSeats
            );
        } catch (\Throwable $e) {
            Log::warning('Pending trial coupon could not be priced — dropping silently', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => (string) $slot['code'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function clearPendingTrialCouponSlot(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }
        $meta = $billable->getBillingSubscriptionMeta();
        if (! array_key_exists('pending_first_charge_coupon', $meta)) {
            return;
        }
        unset($meta['pending_first_charge_coupon']);
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }
}

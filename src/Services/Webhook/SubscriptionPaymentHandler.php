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
        $actualGross = $this->support->amountFromMolliePayment($payment);

        if (abs($actualGross - (int) $vat['gross']) > 1) {
            event(new PaymentAmountMismatch($billable, (string) $payment->id, (int) $vat['gross'], $actualGross));
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        if ($couponDiscountNet > 0) {
            $vatRate = (float) ($vat['rate'] ?? 0.0);
            $vatAmount = (int) round($couponDiscountNet * $vatRate / 100);
            $lineItems[] = [
                'kind' => 'coupon',
                'description' => 'Coupon discount',
                'qty' => 1,
                'unit_price_net' => -$couponDiscountNet,
                'amount_net' => -$couponDiscountNet,
                'vat_rate' => $vatRate,
                'vat_amount' => -$vatAmount,
                'amount_gross' => -($couponDiscountNet + $vatAmount),
            ];
        }
        if ($pendingTrialCoupon !== null && $pendingTrialDiscountNet > 0) {
            $vatRate = (float) ($vat['rate'] ?? 0.0);
            $vatAmount = (int) round($pendingTrialDiscountNet * $vatRate / 100);
            $lineItems[] = [
                'kind' => 'coupon',
                'description' => 'Coupon '.$pendingTrialCoupon['coupon']->code,
                'qty' => 1,
                'unit_price_net' => -$pendingTrialDiscountNet,
                'amount_net' => -$pendingTrialDiscountNet,
                'vat_rate' => $vatRate,
                'vat_amount' => -$vatAmount,
                'amount_gross' => -($pendingTrialDiscountNet + $vatAmount),
            ];
        }
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

        $rollover = $this->catalog->usageRollover($planCode);

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                if ($rollover) {
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

        $updates = [
            'subscription_period_starts_at' => $periodStartsAt,
            'subscription_meta' => $meta,
        ];

        if ($billable->getBillingSubscriptionStatus() === SubscriptionStatus::Trial) {
            $updates['subscription_status'] = SubscriptionStatus::Active;
            $updates['trial_ends_at'] = null;
            event(new TrialConverted($billable, $planCode));
        }

        $billable->forceFill($updates)->save();

        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    public function failed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
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
            Notification::send($recipients, new SubscriptionPaymentFailedNotification($billable, (string) $payment->id));
        }
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

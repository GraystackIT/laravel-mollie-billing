<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
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
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SubscriptionPaymentHandler
{
    use MutatesBillingMeta;

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
     * Whether an overage charge covering this usage type is created but not settled.
     *
     * Read off the snapshot `ChargeUsageOverageDirectly` persists, which lists exactly
     * the line items it charged for — so a type the charge did not cover still gets its
     * ordinary reset. A snapshot whose cycle already gave up (`STATUS_FAILED`) is not
     * "in flight": that debt was written off with a past-due transition, and preserving
     * it forever would keep the customer at a negative balance no settlement will clear.
     */
    protected function hasUnsettledOverageFor(Billable $billable, string $usageType): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
            return false;
        }

        $lines = (array) ($meta[ChargeUsageOverageDirectly::META_KEY]['line_items'] ?? []);

        foreach ($lines as $line) {
            $type = (string) ($line['type'] ?? $line['code'] ?? '');

            if (strcasecmp($type, $usageType) === 0 && (int) ($line['quantity'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a recurring charge — settled or failed — still relates to a running
     * subscription.
     *
     * One predicate for both directions. `failed()` has refused non-billable states since
     * a charge for a subscription that had ended could otherwise restart the dunning
     * clock; `paid()` had no equivalent, which is the more expensive half.
     */
    protected function isBillableState(Billable $billable): bool
    {
        return in_array(
            $billable->getBillingSubscriptionStatus(),
            [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::PastDue],
            true,
        );
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

        // A parked single-payment coupon only counts if Mollie actually collected less.
        //
        // The coupon is stashed at trial start, but the Mollie subscription is created at the
        // full price and nothing reduces the trial-end charge — so the discount line was put
        // on the invoice, `reconcileWithPaidAmount()` cancelled it back out with an
        // `adjustment`, `PaymentAmountMismatch` fired, and the coupon was redeemed anyway:
        // the customer paid full price, the campaign lost a slot, and a redemption row
        // claimed a discount nobody received. This is the rule
        // `StartSubscriptionCheckout::amountReflectsCoupon()` applies on its own auto-apply
        // path — keep the coupon usable rather than book a promise that was not kept.
        if ($pendingTrialCoupon !== null
            && $pendingTrialDiscountNet > 0
            && ! $this->paidAmountReflects($payment, $expectedNet - $pendingTrialDiscountNet, $billable)
        ) {
            Log::warning('Parked trial coupon not applied — Mollie collected the undiscounted amount', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'coupon_code' => (string) $pendingTrialCoupon['coupon']->code,
                'discount_net' => $pendingTrialDiscountNet,
            ]);

            // Left in the slot on purpose: the code has not been honoured, so it must stay
            // spendable. The operator hears about it through the warning above.
            $pendingTrialCoupon = null;
            $pendingTrialDiscountNet = 0;
        }

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

        $paidAt = $payment->paidAt ?? null;

        // The window this payment bought, computed before the invoice rather than after it.
        //
        // The anchor on the billable still describes the period that just *ended* — it is
        // advanced further down, once the invoice exists — so leaving createForPayment() to
        // read it stamped the renewal invoice with the expired window. `currentPeriodLines()`
        // drops any line whose period_end is more than a day from the billable's own period
        // end, so it could not find the very invoice that paid for the current period: a
        // mid-period downgrade after a renewal composed its refund against nothing and
        // credited 0. MandateOnlyPaymentHandler writes its anchor before invoicing and
        // ProrataChargeHandler stamps the new window explicitly, for the same reason.
        $newPeriodStart = $paidAt !== null
            ? Carbon::parse((string) $paidAt)->setTimezone('UTC')
            : BillingTime::nowUtc();
        $newPeriodEnd = BillingTime::addInterval($newPeriodStart, $interval);

        $invoice = $this->salesInvoiceService->createForPayment(
            $payment,
            'subscription',
            $lineItems,
            $billable,
            $newPeriodStart,
            $newPeriodEnd,
        );

        // The same state rule `failed()` applies, and for the same reason — except this is
        // the side money actually arrives on. Nothing here stops Mollie from charging a
        // subscription whose local cancel never landed: CancelSubscription writes the
        // Cancelled status even when cancelAtMollie() fails (it leaves a
        // `pending_subscription_cancel` marker), and RetrySubscriptionCancelJob gives up
        // after 24h with a single admin mail. From then on every period was booked as an
        // ordinary renewal: period anchor advanced, every wallet reset and recharged,
        // "your invoice is ready" mailed to a customer who cancelled and has no access.
        // Silently, forever.
        //
        // The invoice above is kept deliberately: the money did arrive and the customer is
        // entitled to a document for it (and an operator needs something to refund
        // against). Everything that would treat this as a live subscription is skipped,
        // and the operators are told.
        if (! $this->isBillableState($billable)) {
            $this->support->reportRenewalForEndedSubscription($billable, $payment, $invoice);

            return;
        }

        // A delivery for an *older* renewal must not be booked as a fresh period. Idempotency
        // here is per payment, so a late delivery for a payment that has no invoice yet used
        // to run the whole body: the anchor went back a month — after which
        // `PrepareUsageOverageJob`'s `renewsTomorrow` never matched again until the next
        // renewal, so that period's overage was never collected — and the wallet loop credited
        // a second full period on top, permanently for a rollover type. The invoice above
        // stays: the money did arrive and the customer is entitled to the document.
        $currentAnchor = $billable->getBillingPeriodStartsAt();

        // Strictly earlier, not "not later": a payment settling in the same second as the
        // anchor (a signup whose first renewal is simulated at the same instant, say) is not
        // out of order, and refusing it would skip a legitimate period's quota.
        if ($currentAnchor !== null && $newPeriodStart->lessThan($currentAnchor)) {
            Log::warning('Renewal delivered out of order — invoiced, but not booked as a new period', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_anchor' => $currentAnchor->toIso8601String(),
                'delivered_paid_at' => $newPeriodStart->toIso8601String(),
            ]);

            return;
        }

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
                    // Clamps the purchased balance before crediting — see creditRollover().
                    $this->walletService->creditRollover($billable, (string) $type, (int) $units);
                } else {
                    // A charge for these units may still be settling — with SEPA it
                    // usually is, since Mollie creates the renewal about two days ahead
                    // while the overage charge is created the day before it. Forgiving
                    // the debt here and then crediting the charged quantity when the
                    // overage settles handed the customer that quantity for free, every
                    // cycle. Rollover types are unaffected: they never zero the balance.
                    $this->walletService->resetAndCredit(
                        $billable,
                        (string) $type,
                        (int) $units,
                        'subscription_renewal',
                        preserveDebt: $this->hasUnsettledOverageFor($billable, (string) $type),
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        // Already resolved above, where the invoice's period was computed from it.
        $periodStartsAt = $newPeriodStart;

        $derivedSeatCount = $invoice->deriveSeatCount();

        $currentStatus = $billable->getBillingSubscriptionStatus();
        $wasTrial = $currentStatus === SubscriptionStatus::Trial;
        $wasPastDue = $currentStatus === SubscriptionStatus::PastDue;

        $updates = ['subscription_period_starts_at' => $periodStartsAt];

        if ($wasTrial || $wasPastDue) {
            $updates['subscription_status'] = SubscriptionStatus::Active;
        }
        if ($wasTrial) {
            $updates['trial_ends_at'] = null;
        }

        // Locked read-modify-write, touching only the keys this handler owns.
        //
        // The meta was read at the top of paid(), then a wide window of invoice and
        // wallet work followed, and finally the whole blob was written back. Anything
        // that committed in between was reverted: an overage webhook clearing its
        // `usage_overage*` keys in the same second (they settle a day apart *by design*)
        // came back from the dead and PrepareUsageOverageJob then skipped that billable's
        // overage collection indefinitely; a SyncSeats that raised `seat_count` and
        // PATCHed Mollie was rolled back locally, so Mollie billed n+2 seats while the
        // row said n and the next renewal rebuilt its lines from the stale count.
        $this->mutateBillingMeta(
            $billable,
            static function (array $meta) use ($derivedSeatCount, $wasPastDue): array {
                if ($derivedSeatCount !== null) {
                    $meta['seat_count'] = $derivedSeatCount;
                }

                // A successful recurring charge recovers a PastDue billable — drop the
                // failure markers so the dashboard banner and overage retry job stop
                // treating it as overdue. Mirrors ProrataChargeHandler's cleanup.
                if ($wasPastDue) {
                    $meta = SubscriptionMetaHygiene::withoutDunningEpisode($meta);
                }

                // The override exists to delay ONE charge — a TrialExtension coupon
                // pushing the trial end, a PeriodExtension granting free days. That charge
                // has now happened, so the override is spent:
                // `subscription_period_starts_at` above is the new anchor. Left in place
                // it outranks that anchor in `nextBillingDate()` for as long as it stays
                // in the future, which made a converted trial report a next-billing date a
                // month early — and everything reading that date got it wrong with it: the
                // overage pass fires the day before it, and a prorata plan change measures
                // its factor against it.
                unset($meta['next_charge_date_override']);

                return $meta;
            },
            $updates,
        );

        // AFTER the save, not before. Firing it first meant a throwing app listener left
        // the billable on status Trial with a past `trial_ends_at` — which
        // `hasAccessibleBillingSubscription()` refuses — so a customer who had just paid
        // was locked out for a whole period, and the period anchor was never advanced.
        // The re-delivery could not repair it either: the invoice guard short-circuits.
        if ($wasTrial) {
            event(new TrialConverted($billable, $planCode));
        }

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
        if (! $this->isBillableState($billable)) {
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
     * Whether what Mollie collected matches a given net, once VAT is added.
     *
     * The comparison has to happen on gross, because that is what the payment carries, and
     * with a cent of slack for the rounding difference between per-line and total VAT.
     */
    protected function paidAmountReflects(object $payment, int $net, Billable $billable): bool
    {
        $currency = BillingMoney::normalizeCurrency($payment->amount->currency ?? null);
        $paidGross = BillingMoney::minorFromMollieAmount((string) ($payment->amount->value ?? '0'), $currency);
        $expectedGross = (int) $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            max(0, $net),
            $billable,
        )['gross'];

        return abs($paidGross - $expectedGross) <= 1;
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

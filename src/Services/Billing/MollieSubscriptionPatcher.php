<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Responsible for Mollie subscription changes for future periods.
 *
 * Logic was extracted from UpdateSubscription:
 * - cancelAndRecreateMollieSubscription → updateRecurringAmount() (internally: cancel + create)
 * - cancelMollieSubscriptionForFreeDowngrade → cancelForFreeDowngrade()
 * - updateMollieSubscription → in-place PATCH (runs in the phase-2 path)
 */
class MollieSubscriptionPatcher
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {}

    /**
     * Applies a PlanChangeIntent to the Mollie subscription.
     * - newPlan = Free → cancelForFreeDowngrade()
     * - otherwise → updateRecurringAmount() with new plan/seats/addons
     */
    public function updateForIntent(Billable $billable, PlanChangeIntent $intent): void
    {
        if ($this->catalog->isFreePlan($intent->newPlan, $intent->newInterval)) {
            $this->cancelForFreeDowngrade($billable);
            return;
        }

        $extraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        $totalSeats = $this->catalog->includedSeats($intent->newPlan) + $extraSeats;
        $newAddons = array_keys($intent->newAddons);

        // Preserve any active recurring coupon discount across plan/seat/addon
        // changes — otherwise the PATCH would silently undo the campaign price.
        $couponDiscountNet = 0;
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        if (is_array($marker)) {
            $baseNet = SubscriptionAmount::net(
                $this->catalog,
                $billable,
                $intent->newPlan,
                $intent->newInterval,
                $totalSeats,
                $newAddons,
            );
            $couponDiscountNet = $this->markerDiscount($marker, $baseNet);
        }

        $this->updateRecurringAmount(
            billable: $billable,
            planCode: $intent->newPlan,
            interval: $intent->newInterval,
            addons: $newAddons,
            extraSeats: $extraSeats,
            intervalChanged: $intent->currentInterval !== $intent->newInterval,
            couponDiscountNet: $couponDiscountNet,
            forceResetStartDate: $intent->forceResetStartDate,
        );
    }

    /**
     * For a 100%-coverage recurring discount, return the Mollie startDate that
     * skips the entire discount lifetime: marker.valid_until + 1 day. Falls
     * back to one period from now if the marker has no valid_until (shouldn't
     * happen — the validator enforces a lifetime gate — but defensive).
     */
    private function fullCoverageStartDate(Billable $billable, string $interval): Date
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        $validUntil = is_array($marker) && isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;

        if ($validUntil !== null) {
            return new Date($validUntil->copy()->addDay());
        }

        return new Date(
            $interval === 'yearly' ? BillingTime::nowUtc()->addYear() : BillingTime::nowUtc()->addMonth()
        );
    }

    /**
     * Mirrors `CouponService::computeMarkerDiscount()` — the discount is locked
     * to the recurring net at coupon-apply time so additions (seats/addons) made
     * afterwards aren't silently rabattiert.
     *
     * @param  array<string,mixed>  $marker
     */
    private function markerDiscount(array $marker, int $netAmount): int
    {
        if ($netAmount <= 0) {
            return 0;
        }

        $type = (string) ($marker['discount_type'] ?? '');
        $value = (int) ($marker['discount_value'] ?? 0);

        $baseAmount = isset($marker['base_amount_net'])
            ? min((int) $marker['base_amount_net'], $netAmount)
            : $netAmount;

        if ($type === 'percentage') {
            return (int) round($baseAmount * $value / 100);
        }

        if ($type === 'fixed') {
            return min($value, $baseAmount);
        }

        return 0;
    }

    /**
     * PATCH the Mollie subscription with the new aggregated recurring amount.
     *
     * Mollie's `PATCH /subscriptions/:id` only changes the amount for the *next* recurring period —
     * it does not trigger an immediate charge. This is exactly the desired behavior: the current
     * period stays untouched, and the new amount applies from the next billing onwards.
     *
     * NO cancel+recreate fallback on PATCH failures: `CreateSubscriptionRequest` would cause Mollie
     * to immediately issue a first charge → double debit. Failures are logged and picked up by the
     * RetrySubscriptionPatchJob when needed.
     *
     * @param  array<int, string>  $addons
     *
     * @throws \Throwable  When the Mollie PATCH call fails (the retry job can react to this).
     */
    public function updateRecurringAmount(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        bool $intervalChanged = false,
        int $couponDiscountNet = 0,
        bool $forceResetStartDate = false,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('updateRecurringAmount skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
                'mollie_customer_id' => $customerId,
                'mollie_subscription_id' => $subscriptionId ?: '(empty)',
            ]);
            return;
        }

        $includedSeats = $this->catalog->includedSeats($planCode);
        $totalSeats = $includedSeats + $extraSeats;
        $baseAmountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addons);
        $netAfterDiscount = max(0, $baseAmountNet - max(0, $couponDiscountNet));

        // 100%-coverage: the discount fully covers the recurring net. Mollie
        // rejects subscriptions with amount = 0, so we instead PATCH with the
        // FULL price and defer the next charge to the day after the marker's
        // valid_until — Mollie won't charge anything during the discount lifetime,
        // and once it does the marker is already expired and the full price is
        // billed naturally.
        $isFullCoverage = $netAfterDiscount === 0 && $couponDiscountNet > 0 && $baseAmountNet > 0;
        $amountNet = $isFullCoverage ? $baseAmountNet : $netAfterDiscount;

        $vat = $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $amountNet,
            $billable,
        );

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $value = number_format(((int) $vat['gross']) / 100, 2, '.', '');

        $metadata = [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $addons,
            'extra_seats' => $extraSeats,
        ];

        $mollieInterval = $interval === 'yearly' ? '12 months' : '1 month';

        // Date precedence:
        //  1. full-coverage discount → defer past marker.valid_until
        //  2. interval change OR past-due reset → reset cadence to now + 1 period
        //     (interval-change: else Mollie keeps old cadence with new amount;
        //      past-due-reset: else Mollie immediately retries the failed charge
        //      at the new price right after PATCH).
        //  3. amount-only change → leave cadence untouched (null)
        $startDate = match (true) {
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $intervalChanged || $forceResetStartDate => new Date(
                $interval === 'yearly' ? BillingTime::nowUtc()->addYear() : BillingTime::nowUtc()->addMonth()
            ),
            default => null,
        };

        Mollie::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            amount: new Money($currency, $value),
            description: "{$planCode} subscription",
            interval: $mollieInterval,
            startDate: $startDate,
            metadata: $metadata,
        ));
    }

    /**
     * Shifts the date of the next Mollie billing into the future by the given
     * number of days. Used by the PeriodExtension coupon type.
     * After a successful PATCH an override is set in the subscription meta so
     * that `nextBillingDate()` returns the shifted value.
     */
    public function pushNextChargeDate(Billable $billable, int $days): void
    {
        if (! ($billable instanceof Model) || $days <= 0) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('pushNextChargeDate skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
            ]);

            return;
        }

        $current = $billable->nextBillingDate() ?? BillingTime::nowUtc();
        $newDate = $current->copy()->addDays($days);

        Mollie::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            startDate: new Date($newDate),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['next_charge_date_override'] = $newDate->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Sets the date of the next Mollie billing to an absolute target date.
     * Used by the TrialExtension coupon type: the trial end is computed
     * absolutely (`max(current_trial_end, now) + days`), so the Mollie
     * startDate must follow that same absolute value rather than a relative
     * shift — otherwise a coupon applied after the trial already expired
     * would push the next charge too far into the future.
     * After a successful PATCH an override is set in the subscription meta so
     * that `nextBillingDate()` returns the new value.
     */
    public function setNextChargeDate(Billable $billable, \Carbon\CarbonInterface $target): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('setNextChargeDate skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
            ]);

            return;
        }

        $newDate = $target->copy();

        Mollie::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            startDate: new Date($newDate),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['next_charge_date_override'] = $newDate->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Cancels the Mollie subscription on a free downgrade. Tolerant of API failures.
     */
    public function cancelForFreeDowngrade(Billable $billable): void
    {
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            return;
        }

        try {
            Mollie::send(new CancelSubscriptionRequest($customerId, $subscriptionId));
        } catch (\Throwable $e) {
            Log::warning('Mollie subscription cancel during free-downgrade failed', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

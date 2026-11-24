<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;

/**
 * "When is the next charge, and how much?" — the one thing a customer needs to know
 * about a subscription, and until now the one thing no screen said.
 *
 * The dashboard showed a *date* (the period end) and left the amount to be worked out
 * from the plan card, the seat screen and the addon screen. That was tolerable while
 * period end and charge date were the same day and the amount was the list price. They
 * no longer are: a fully covering coupon or a free plan defers the charge for months
 * while the period keeps turning over, and a plan waiver takes the plan off an amount
 * that still contains paid extras. A customer reading "next billing: 15 Dec" under a
 * 50.00 plan had no way to tell that nothing would be charged until March, or that 15.00
 * would.
 *
 * This reads the answer from the same sources the charge itself is built from —
 * `SubscriptionAmount` for the lines, `CouponService::computeMarkerDiscount()` judged AS
 * OF the charge date for the discount, `ChargeDeferral` for the date — so the screen
 * cannot drift from what Mollie holds. It is a projection, not a promise: usage overage
 * is charged separately and is not in it, and a change made before the date changes it.
 */
class NextChargeSummary
{
    /** No subscription at all. */
    public const STATE_NONE = 'none';

    /** A subscription nothing is collected for (free plan, gift without paid extras). */
    public const STATE_FREE = 'free';

    /** Cancelled or ending: nothing further will be charged. */
    public const STATE_ENDING = 'ending';

    /** The last charge failed; what is due is that payment, not a scheduled one. */
    public const STATE_PAST_DUE = 'past_due';

    /** A charge is scheduled. */
    public const STATE_SCHEDULED = 'scheduled';

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly CouponService $coupons,
    ) {
    }

    /**
     * @return array{
     *     state: string,
     *     date: ?Carbon,
     *     lines: list<array{kind: string, label: string, quantity: int, net: int}>,
     *     discount_net: int,
     *     discount_code: ?string,
     *     plan_free_until: ?Carbon,
     *     net: int,
     *     vat: int,
     *     gross: int,
     *     vat_rate: float,
     *     deferred: bool,
     *     has_scheduled_change: bool,
     *     ends_at: ?Carbon
     * }
     */
    public function for(Billable $billable): array
    {
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');
        $status = $billable->getBillingSubscriptionStatus();
        $endsAt = $billable->getBillingSubscriptionEndsAt();

        $empty = [
            'state' => self::STATE_NONE,
            'date' => null,
            'lines' => [],
            'discount_net' => 0,
            'discount_code' => null,
            'plan_free_until' => null,
            'net' => 0,
            'vat' => 0,
            'gross' => 0,
            'vat_rate' => 0.0,
            'deferred' => false,
            'has_scheduled_change' => false,
            // A standing overage debt the renewal on `date` collects with it, or null.
            // `gross` stays the subscription's own charge (what Mollie holds for the
            // subscription); `total_gross` is everything that leaves the account then.
            'overage_debt' => null,
            'total_gross' => 0,
            'ends_at' => $endsAt !== null ? BillingTime::toUtc($endsAt) : null,
        ];

        if ($planCode === '' || $interval === '' || in_array($status, [SubscriptionStatus::New, SubscriptionStatus::Expired], true)) {
            return $empty;
        }

        if ($status === SubscriptionStatus::Cancelled) {
            return ['state' => self::STATE_ENDING] + $empty;
        }

        if ($billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value) {
            return ['state' => self::STATE_FREE] + $empty;
        }

        // Past due for an unpaid renewal: there is no "next" charge, the last one is open.
        // Past due only for an overage debt is different — the period is paid, the
        // renewal runs on its date, and it is what collects the debt: that customer is
        // shown the charge, with the debt as a line of its own.
        $overage = app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class);
        $debtOnly = \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable);

        if ($status === SubscriptionStatus::PastDue && ! $debtOnly) {
            return ['state' => self::STATE_PAST_DUE] + $empty;
        }

        // `pending()`, not `until()`: on the day of a deferred charge, before Mollie's
        // charge has arrived, the charge is today — not the trial end or a period end.
        $deferredUntil = ChargeDeferral::pending($billable);
        $marker = $this->coupons->getActiveRecurringCouponMarker($billable);

        // A first-payment coupon on a trial pays the charge at the trial's end, and that is
        // the charge the customer is shown: on that day, discounted — at 100 %, for 0.00.
        // The deferral it leaves when it covers everything is only how Mollie, unable to
        // hold 0, skips that charge; naming its date instead read as "the first charge is a
        // month later, at full price", which is not what the customer bought. Up to and
        // including the trial's last day: after it, and before the nightly job has converted
        // the trial, the free charge is behind the customer and the deferred one is next.
        $trialEnd = $billable->getBillingTrialEndsAt();

        if ($status === SubscriptionStatus::Trial
            && is_array($marker)
            && CouponService::isFirstChargeOnly($marker)
            && $trialEnd !== null
            && ! BillingTime::toUtc($trialEnd)->startOfDay()->lessThan(BillingTime::nowUtc()->startOfDay())
        ) {
            $deferredUntil = null;
        }

        $date = $this->chargeDate($billable, $status, $deferredUntil);

        $addons = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();

        $items = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addons);

        $lines = [];
        $catalogNet = 0;

        foreach ($items as $item) {
            $net = (int) ($item['total_net'] ?? 0);
            $catalogNet += $net;
            $lines[] = [
                'kind' => (string) ($item['kind'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'net' => $net,
            ];
        }

        // The discount AS OF the charge. A coupon that runs out before the date is not
        // on the charge; a fully covering one is exactly why the date lies beyond it.
        $discount = $date !== null
            ? $this->coupons->computeMarkerDiscount($billable, $catalogNet, [], $date)
            : $this->coupons->computeMarkerDiscount($billable, $catalogNet);
        $discount = max(0, min($discount, $catalogNet));

        $netDue = $catalogNet - $discount;

        $vat = $this->vat($billable, $netDue);

        $gross = BillingMoney::grossWithLineVat(
            MollieSubscriptionPatcher::recurringLineNets(
                $this->catalog,
                $billable,
                $planCode,
                $interval,
                $addons,
                $extraSeats,
                $netDue,
            ),
            $vat,
        );

        $planFreeUntil = null;

        if ($discount > 0 && is_array($marker) && PlanWaiver::isWaiver($marker) && ! empty($marker['valid_until'])) {
            try {
                $planFreeUntil = Carbon::parse((string) $marker['valid_until'])->setTimezone('UTC');
            } catch (\Throwable) {
                $planFreeUntil = null;
            }
        }

        // Only a debt that is standing (not one whose payment is already on its way) and
        // that will have rested long enough by that day: exactly the condition under
        // which the renewal starts a collection for it. A standing debt always has
        // attempts left — once they are spent it is written off and gone from here.
        $debt = $date !== null && $overage->deferredDebtIsDue($billable, $date)
            ? $overage->deferredDebtAmount($billable)
            : null;
        $debt = $debt !== null ? ['net' => (int) $debt['net'], 'gross' => (int) $debt['gross']] : null;

        return [
            'state' => self::STATE_SCHEDULED,
            'date' => $date,
            'overage_debt' => $debt,
            'total_gross' => $gross + ($debt['gross'] ?? 0),
            'lines' => $lines,
            'discount_net' => $discount,
            'discount_code' => $discount > 0 && is_array($marker) && $planFreeUntil === null
                ? ((string) ($marker['code'] ?? '') ?: null)
                : null,
            'plan_free_until' => $planFreeUntil,
            'net' => $netDue,
            'vat' => $gross - $netDue,
            'gross' => $gross,
            'vat_rate' => $vat,
            'deferred' => $deferredUntil !== null,
            'has_scheduled_change' => ! empty($billable->getBillingSubscriptionMeta()['scheduled_change']),
        ] + $empty;
    }

    /**
     * The day Mollie collects next: the trial end for a trial, a stored deferral where
     * one is in force (the later of the two when both apply), the period end otherwise.
     */
    private function chargeDate(Billable $billable, SubscriptionStatus $status, ?Carbon $deferredUntil): ?Carbon
    {
        if ($status === SubscriptionStatus::Trial) {
            $trialEnd = $billable->getBillingTrialEndsAt();

            if ($trialEnd !== null) {
                $trialEnd = BillingTime::toUtc($trialEnd);

                return $deferredUntil !== null ? BillingTime::latest($trialEnd, $deferredUntil) : $trialEnd;
            }
        }

        $date = $deferredUntil ?? $billable->nextBillingDate();

        return $date !== null ? BillingTime::toUtc($date) : null;
    }

    private function vat(Billable $billable, int $net): float
    {
        try {
            return (float) ($this->vatService->calculate(
                (string) ($billable->getBillingCountry() ?? ''),
                $net,
                $billable,
            )['rate'] ?? 0.0);
        } catch (\Throwable) {
            // A country that cannot be priced is the checkout's problem to report, not
            // this summary's: show the net rather than fail a dashboard.
            return 0.0;
        }
    }
}

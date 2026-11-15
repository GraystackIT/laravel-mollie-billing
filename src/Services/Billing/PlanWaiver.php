<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan that is free on a subscription Mollie charges for everything else.
 *
 * This is what lets a tenant who was *given* the product buy paid addons and seats: the
 * plan line (and whatever addons the gift itself included) is waived, the extras are
 * billed. Before it existed the two could not coexist — a gift was a Local subscription,
 * Local subscriptions cannot be charged, and moving to Mollie threw the gift away.
 *
 * It is deliberately NOT a new pricing mechanism. A waiver is a marker in the one slot a
 * recurring discount already lives in (`subscription_meta.active_recurring_coupon`),
 * shaped as a fixed discount with `kind = plan_waiver`. Everything that prices a
 * recurring charge already asks `CouponService::computeMarkerDiscount()` — the renewal
 * invoice, the Mollie amount, the preview, the full-coverage deferral — so a waiver is
 * honoured by all of them without any of them learning a second rule. What a waiver
 * adds on top is here, and only three things:
 *
 *  - **its ceiling** follows the plan line, not the whole charge ({@see ceiling()}), so
 *    switching to a cheaper plan does not let the difference spill over onto addons;
 *  - **its end** is either a date (a gift of N days) or a number of periods, and an
 *    admin can move it later ({@see extendByDays()}, {@see extendByPeriods()});
 *  - **what happens at the end** is a choice made when it was given: the subscription
 *    ends, or the plan becomes chargeable ({@see endsSubscription()}).
 *
 * The cost of the single slot is that a waiver and an ordinary recurring coupon cannot
 * run at the same time. That is accepted on purpose: two discounts that both reprice the
 * recurring amount, with separate lifetimes, is precisely the kind of interaction this
 * package keeps getting wrong.
 */
class PlanWaiver
{
    use MutatesBillingMeta;

    public const KIND = 'plan_waiver';

    public const ON_EXPIRY_END = 'end';

    public const ON_EXPIRY_CONTINUE_PAID = 'continue_paid';

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * The billable's waiver marker, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function marker(Billable $billable): ?array
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        return is_array($marker) && ($marker['kind'] ?? null) === self::KIND ? $marker : null;
    }

    /**
     * @param  array<string, mixed>  $marker
     */
    public static function isWaiver(array $marker): bool
    {
        return ($marker['kind'] ?? null) === self::KIND;
    }

    /**
     * The invoice lines a waiver takes its amount off, in the form
     * `ProrataComposer::lineKey()` produces: the plan, and each granted addon that is
     * still active (base and per-seat line).
     *
     * @param  array<string, mixed>  $marker
     * @param  array<int, string>  $activeAddons
     * @return list<string>
     */
    public static function appliesTo(array $marker, array $activeAddons): array
    {
        $keys = ['plan'];

        foreach (array_intersect(array_map('strval', (array) ($marker['addon_codes'] ?? [])), $activeAddons) as $code) {
            $keys[] = 'addon:'.$code;
            $keys[] = 'addon_seats:'.$code;
        }

        return $keys;
    }

    /**
     * Whether the waiver is what remains of a gift — it ends on a date — rather than a
     * number of free periods given to a paying customer.
     *
     * @param  array<string, mixed>  $marker
     */
    public function isGift(array $marker): bool
    {
        return self::isWaiver($marker) && ($marker['max_applications'] ?? null) === null;
    }

    public static function normalizeOnExpiry(?string $value): string
    {
        return $value === self::ON_EXPIRY_CONTINUE_PAID
            ? self::ON_EXPIRY_CONTINUE_PAID
            : self::ON_EXPIRY_END;
    }

    /**
     * What the waiver takes off a recurring charge of this configuration: the plan's base
     * price plus the granted addons that are still active.
     *
     * @param  array<int, string>  $grantedAddons  addons the gift itself included
     * @param  array<int, string>  $activeAddons
     */
    public function waivedNet(
        Billable $billable,
        string $planCode,
        string $interval,
        int $seats,
        array $grantedAddons,
        array $activeAddons,
    ): int {
        if ($planCode === '') {
            return 0;
        }

        $stillGranted = array_values(array_intersect($grantedAddons, $activeAddons));

        // Measured as a difference so that whatever a granted addon costs — including a
        // per-seat component — is covered, while the plan's own extra seats are not.
        $withGranted = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $stillGranted);
        $withoutAddons = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, []);

        return max(0, $this->catalog->basePriceNet($planCode, $interval) + ($withGranted - $withoutAddons));
    }

    /**
     * The most a waiver may take off right now.
     *
     * The stored amount is what was waived when the gift was given — the lock that keeps
     * an upgrade from enlarging it. This is the other side: on a cheaper plan, or once a
     * granted addon has been removed, only what those lines cost *now* is waived.
     *
     * The plan, interval and addons default to the billable's own; a caller pricing a
     * configuration the billable is about to move to passes the target.
     *
     * @param  array<string, mixed>  $marker
     * @param  array<int, string>|null  $addonCodes
     */
    public function ceiling(
        Billable $billable,
        array $marker,
        ?string $planCode = null,
        ?string $interval = null,
        ?array $addonCodes = null,
        ?int $seats = null,
    ): int {
        $planCode ??= (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval ??= (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');
        $addonCodes ??= $billable->getActiveBillingAddonCodes();
        $seats ??= $billable->getBillingSeatCount();

        return min(
            (int) ($marker['base_amount_net'] ?? 0),
            $this->waivedNet(
                $billable,
                $planCode,
                $interval,
                $seats,
                array_map('strval', (array) ($marker['addon_codes'] ?? [])),
                $addonCodes,
            ),
        );
    }

    /**
     * Put a waiver on the billable. Local write only — the caller prices or PATCHes the
     * Mollie subscription afterwards, outside any transaction.
     *
     * Exactly one of `$until` / `$periods` describes the lifetime. With periods the end
     * date is derived the way a recurring coupon's is (`N × interval days + 1`), and the
     * count is enforced as well — see `CouponService::isMarkerStillRedeemable()`.
     *
     * @param  array<int, string>  $grantedAddons
     */
    public function grant(
        Billable $billable,
        Coupon $coupon,
        string $planCode,
        string $interval,
        array $grantedAddons,
        ?CarbonInterface $until,
        ?int $periods,
        string $onExpiry,
        ?int $redemptionId = null,
    ): void {
        if (! $billable instanceof Model) {
            return;
        }

        $now = BillingTime::nowUtc();
        $periods = $periods !== null && $periods > 0 ? $periods : null;

        // A number of periods is a number of *charges* not collected, so it is counted
        // from the next charge — not from today. Counted from today (`now + N × 30 + 1`
        // days, the way a coupon redeemed at checkout is) one free period given on day 2
        // of a month ended three days after the regular charge date: on a subscription
        // that bills nothing but the plan the charge is deferred to the waiver's end, so
        // Mollie collected the full price three days late and called that a free month.
        // The end sits one day before the charge that is collected again, because that
        // is the date both full-coverage deferrals add a day to.
        $resumesAt = $until === null
            ? self::advance($this->nextCharge($billable, $interval), $interval, $periods ?? 1)
            : null;

        $validUntil = $until !== null
            ? BillingTime::toUtc($until)
            : $resumesAt->copy()->subDay();

        $waived = $this->waivedNet(
            $billable,
            $planCode,
            $interval,
            max($billable->getBillingSeatCount(), $this->catalog->includedSeats($planCode)),
            $grantedAddons,
            $grantedAddons,
        );

        $marker = [
            'kind' => self::KIND,
            'coupon_id' => $coupon->id,
            'code' => (string) $coupon->code,
            // A fixed discount, so every reader of the slot prices it without knowing
            // what a waiver is.
            'discount_type' => 'fixed',
            'discount_value' => $waived,
            'base_amount_net' => $waived,
            'valid_until' => $validUntil->toIso8601String(),
            'first_applied_at' => $now->toIso8601String(),
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => array_values($grantedAddons),
            'on_expiry' => self::normalizeOnExpiry($onExpiry),
            // Null: the date alone ends it. A number: that many discounted renewals.
            'max_applications' => $until === null ? ($periods ?? 1) : $periods,
            // Period-based only: the first charge that is collected again.
            'charge_resumes_at' => $resumesAt?->toIso8601String(),
            'redemption_id' => $redemptionId,
        ];

        $this->mutateBillingMeta($billable, function (array $meta) use ($marker): array {
            $meta['active_recurring_coupon'] = $marker;

            return $meta;
        });
    }

    /**
     * Move the end of a date-based waiver later. Returns the new end, or null when the
     * billable has no waiver.
     */
    public function extendByDays(Billable $billable, int $days): ?Carbon
    {
        if (! $billable instanceof Model || $days <= 0 || $this->marker($billable) === null) {
            return null;
        }

        $newEnd = null;

        $this->mutateBillingMeta($billable, function (array $meta) use ($days, &$newEnd): ?array {
            $marker = $meta['active_recurring_coupon'] ?? null;

            if (! is_array($marker) || ! self::isWaiver($marker)) {
                return null;
            }

            $current = self::parse($marker['valid_until'] ?? null);
            $from = $current !== null && $current->isFuture() ? $current : BillingTime::nowUtc();
            $newEnd = $from->copy()->addDays($days);

            $marker['valid_until'] = $newEnd->toIso8601String();
            $meta['active_recurring_coupon'] = $marker;

            return $meta;
        });

        return $newEnd;
    }

    /**
     * Append free periods to a waiver. The date moves by whole intervals and the
     * application count, if the waiver has one, grows with it.
     */
    public function extendByPeriods(Billable $billable, int $periods): ?Carbon
    {
        if (! $billable instanceof Model || $periods <= 0 || $this->marker($billable) === null) {
            return null;
        }

        $newEnd = null;

        $nextCharge = $this->nextCharge(
            $billable,
            (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly'),
        );

        $this->mutateBillingMeta($billable, function (array $meta) use ($periods, $nextCharge, &$newEnd): ?array {
            $marker = $meta['active_recurring_coupon'] ?? null;

            if (! is_array($marker) || ! self::isWaiver($marker)) {
                return null;
            }

            $interval = (string) ($marker['interval'] ?? 'monthly');
            $resumesAt = self::parse($marker['charge_resumes_at'] ?? null);

            if ($resumesAt !== null || ($marker['max_applications'] ?? null) !== null) {
                // Counted in charges, like the waiver itself: whole intervals on the
                // billing cycle. One that has already run out starts again at the next
                // charge rather than at a date in the past.
                $from = $resumesAt !== null && $resumesAt->isFuture() ? $resumesAt : $nextCharge;
                $resumesAt = self::advance($from, $interval, $periods);
                $newEnd = $resumesAt->copy()->subDay();
                $marker['charge_resumes_at'] = $resumesAt->toIso8601String();
            } else {
                // A gift with an end date: that date moves.
                $current = self::parse($marker['valid_until'] ?? null);
                $from = $current !== null && $current->isFuture() ? $current : Carbon::instance(BillingTime::nowUtc());
                $newEnd = self::advance($from, $interval, $periods);
            }

            $marker['valid_until'] = $newEnd->toIso8601String();

            if (isset($marker['max_applications']) && $marker['max_applications'] !== null) {
                $marker['max_applications'] = (int) $marker['max_applications'] + $periods;
            }

            $meta['active_recurring_coupon'] = $marker;

            return $meta;
        });

        return $newEnd;
    }

    public function setOnExpiry(Billable $billable, string $onExpiry): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($onExpiry): ?array {
            $marker = $meta['active_recurring_coupon'] ?? null;

            if (! is_array($marker) || ! self::isWaiver($marker)) {
                return null;
            }

            $marker['on_expiry'] = self::normalizeOnExpiry($onExpiry);
            $meta['active_recurring_coupon'] = $marker;

            return $meta;
        });
    }

    /**
     * Take the waiver away. Local write only; the caller re-prices Mollie afterwards.
     * Returns whether there was one.
     */
    public function remove(Billable $billable): bool
    {
        if (! $billable instanceof Model || $this->marker($billable) === null) {
            return false;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            $marker = $meta['active_recurring_coupon'] ?? null;

            if (! is_array($marker) || ! self::isWaiver($marker)) {
                return null;
            }

            unset($meta['active_recurring_coupon']);

            return $meta;
        });

        return true;
    }

    /**
     * Whether the waiver's end means the *subscription* ends, judged at `$nextChargeAt`.
     *
     * True when the waiver will not cover the next charge and it was given with
     * `on_expiry = end`. The caller then cancels at the end of the period that is still
     * covered, instead of letting Mollie start charging for a plan the tenant was given
     * and never agreed to pay for.
     */
    public function endsSubscription(Billable $billable, CarbonInterface $nextChargeAt): bool
    {
        $marker = $this->marker($billable);

        if ($marker === null || self::normalizeOnExpiry($marker['on_expiry'] ?? null) !== self::ON_EXPIRY_END) {
            return false;
        }

        return app(CouponService::class)->markerExpired($billable, $nextChargeAt);
    }

    /**
     * Bring the Mollie subscription in line with the waiver as it now stands: amount,
     * and — when the waiver covers everything — the deferred charge date.
     *
     * Posts to Mollie, so never inside a transaction.
     */
    public function syncMollie(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if ($planCode === '' || $interval === '') {
            return;
        }

        // A cancelled subscription keeps its Mollie id on the row (nothing else could
        // target it if the cancel has to be retried) but there is nothing left at
        // Mollie to re-price: the PATCH is rejected, and the retry it leaves behind
        // mails the admins a day later about a subscription that has ended. A
        // resubscribe prices from the marker when it creates the new one.
        if (! in_array($billable->getBillingSubscriptionStatus(), [
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Trial,
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue,
        ], true)) {
            return;
        }

        $addons = $billable->getActiveBillingAddonCodes();
        $seats = $billable->getBillingSeatCount();
        $net = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addons);

        app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
            billable: $billable,
            planCode: $planCode,
            interval: $interval,
            addons: $addons,
            extraSeats: $billable->getExtraBillingSeats(),
            couponDiscountNet: app(CouponService::class)->computeMarkerDiscount($billable, $net),
        );
    }

    /**
     * The first charge Mollie collects again when a waiver covers the whole amount —
     * the date the full price it has to hold is parked behind. Null for a marker that is
     * not a waiver (an ordinary coupon defers to the day after its own end).
     *
     * Free periods carry the date (`charge_resumes_at`). A gift ends on a day, and
     * "a renewal is plan-free when it falls before the gift's end" — so the first charge
     * is the first renewal on or after that day. The day after the gift's end is
     * the middle of a period that rule calls free, and deferring to it collected the
     * plan up to a whole period early.
     *
     * @param  array<string, mixed>  $marker
     * @param  CarbonInterface  $nextRegularCharge  where the charge sits when nothing defers it
     */
    public static function chargeResumesAt(array $marker, CarbonInterface $nextRegularCharge, string $interval): ?Carbon
    {
        if (! self::isWaiver($marker)) {
            return null;
        }

        $resumesAt = self::parse($marker['charge_resumes_at'] ?? null);

        if ($resumesAt !== null) {
            return $resumesAt;
        }

        $end = self::parse($marker['valid_until'] ?? null);

        if ($end === null) {
            return null;
        }

        // Stepped from the same anchor each time, so a cycle on the 31st does not
        // drift to the 28th and stay there.
        for ($i = 0; $i < 600; $i++) {
            $boundary = self::advance($nextRegularCharge, $interval, $i);

            if ($boundary->greaterThanOrEqualTo($end)) {
                return $boundary;
            }
        }

        return $end->copy()->addDay();
    }

    /**
     * {@see syncMollie()}, with a failure left for the hourly sweep instead of thrown.
     *
     * For every caller that has already written the waiver locally: the PATCH is what
     * makes Mollie agree, and under a waiver that covers everything it is the only thing
     * that keeps the full price Mollie holds from being collected on the old date. A
     * failure the caller merely reports is a failure nobody retries.
     */
    public function syncMollieOrQueue(Billable $billable, ?int $couponId = null): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        try {
            $this->syncMollie($billable);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Mollie re-price after a plan waiver changed failed — queued for retry', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            // Its own reason, not `recurring_coupon_expiry_reset`: that retry PATCHes
            // the full price and CLEARS the marker, which is right when a discount has
            // ended and exactly wrong here — this runs when a waiver was just given or
            // extended as well as when it was taken away. A waiver whose first PATCH
            // failed was deleted by its own retry, and the tenant paid in full for the
            // periods the redemption row says were free. The retry re-reads the
            // billable instead and makes Mollie agree with whatever the marker says by
            // then (RetrySubscriptionPatchJob::syncPlanWaiver()).
            $this->mutateBillingMeta($billable, function (array $meta) use ($couponId, $e): array {
                $meta['pending_subscription_patch'] = [
                    'reason' => 'plan_waiver_sync',
                    'coupon_id' => $couponId,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];

                return $meta;
            });
        }
    }

    /**
     * Put a marker back exactly as it was — the undo of a change whose follow-up failed.
     *
     * @param  array<string, mixed>  $marker
     */
    public function restore(Billable $billable, array $marker): void
    {
        if (! $billable instanceof Model || ! self::isWaiver($marker)) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($marker): array {
            $meta['active_recurring_coupon'] = $marker;

            return $meta;
        });
    }

    /**
     * The charge a period count starts at: the next one Mollie would collect.
     *
     * A trial's first charge is its end, whatever the period anchor says. Otherwise the
     * stored charge date (a period extension moves it), and for an anchor that has
     * slid into the past the next boundary on its cycle.
     */
    private function nextCharge(Billable $billable, string $interval): Carbon
    {
        $now = BillingTime::nowUtc();
        $trialEndsAt = $billable->getBillingTrialEndsAt();

        if (
            $billable->getBillingSubscriptionStatus() === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Trial
            && $trialEndsAt !== null
            && $trialEndsAt->isFuture()
        ) {
            return BillingTime::toUtc($trialEndsAt);
        }

        $next = \GraystackIT\MollieBilling\Support\ChargeDeferral::nextChargeDate($billable);

        if ($next === null || ! $next->isFuture()) {
            $next = \GraystackIT\MollieBilling\Support\ChargeDeferral::regularNextBillingDate($billable);
        }

        return $next !== null && $next->isFuture()
            ? BillingTime::toUtc($next)
            : BillingTime::toUtc(BillingTime::addInterval($now, $interval));
    }

    private static function advance(CarbonInterface $from, string $interval, int $periods): Carbon
    {
        $at = BillingTime::toUtc($from);

        return $interval === 'yearly'
            ? $at->addYearsNoOverflow(max(0, $periods))
            : $at->addMonthsNoOverflow(max(0, $periods));
    }

    public static function intervalDays(string $interval): int
    {
        return $interval === 'yearly' ? 365 : 30;
    }

    private static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}

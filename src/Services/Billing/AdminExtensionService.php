<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionExtended;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The three things an operator does to give a tenant more free time, as one call each.
 *
 * Before this an operator could extend a *trial* from the admin panel and nothing else:
 * extending a running subscription meant creating a coupon by hand and sending the code
 * to the tenant to type in. Each action here still leaves a coupon and a redemption
 * behind — that is the audit trail, and it is what makes the grant list and `revoke`
 * work — but the operator never sees a code.
 *
 *  - {@see extendByDays()}: everything is free for N more days (plan, seats, addons).
 *  - {@see freePlanPeriods()}: only the plan is free, for N billing periods.
 *  - {@see extendGrant()}: a running gift ends later — also after the tenant bought
 *    paid extras and the gift lives on as a plan waiver.
 */
class AdminExtensionService
{
    public function __construct(
        private readonly CouponService $coupons,
        private readonly PlanWaiver $waiver,
    ) {
    }

    /**
     * Push the next charge (Mollie) or the end date (Local) later by N days.
     */
    public function extendByDays(Billable $billable, int $days): CouponRedemption
    {
        $this->assertPositive($days, 'days');

        return $this->issue($billable, 'EXT', [
            'type' => CouponType::PeriodExtension,
            'grant_duration_days' => $days,
        ]);
    }

    /**
     * Waive the plan's base price for N billing periods. Extras stay paid.
     */
    public function freePlanPeriods(Billable $billable, int $periods): CouponRedemption
    {
        $this->assertPositive($periods, 'periods');

        return $this->issue($billable, 'PLANFREE', [
            'type' => CouponType::PlanWaiver,
            'waiver_periods' => $periods,
        ]);
    }

    /**
     * Move the end of a running gift later by N days, and optionally change what
     * happens when it ends.
     *
     * Returns the new end date.
     *
     * @throws InvalidSubscriptionStateException when the billable has no running gift
     */
    public function extendGrant(Billable $billable, int $days, ?string $onExpiry = null): Carbon
    {
        $this->assertPositive($days, 'days');

        if (! $billable instanceof Model) {
            throw new InvalidSubscriptionStateException('This billable cannot be extended.');
        }

        $source = $billable->getBillingSubscriptionSource();

        // The gift as a plan waiver on a Mollie subscription.
        if ($source === SubscriptionSource::Mollie->value && $this->waiver->marker($billable) !== null) {
            // Free plan periods on a paying subscription are a waiver too, but not a
            // gift: they are counted in charges, so moving the date gave nothing the
            // count did not already decide — and `on_expiry = end` would cancel a
            // paying customer's subscription once the free periods were used up.
            if (! $this->waiver->isGift($this->waiver->marker($billable))) {
                throw new InvalidSubscriptionStateException(
                    'There is no running grant to extend on this subscription. Free plan periods are extended by giving more periods.'
                );
            }

            $status = $billable->getBillingSubscriptionStatus();
            $runningOut = $status === SubscriptionStatus::Cancelled
                && ($billable->getBillingSubscriptionEndsAt()?->isFuture() ?? false);

            // A subscription that has ended (or whose grace is over) has no gift left
            // to extend: the marker would move and nothing else would.
            if (! $runningOut && ! in_array($status, [
                SubscriptionStatus::Active,
                SubscriptionStatus::Trial,
                SubscriptionStatus::PastDue,
            ], true)) {
                throw new InvalidSubscriptionStateException(
                    'There is no running grant to extend on this subscription.'
                );
            }

            $before = $this->waiver->marker($billable);

            if ($onExpiry !== null) {
                $this->waiver->setOnExpiry($billable, $onExpiry);
            }

            $newEnd = $this->waiver->extendByDays($billable, $days);
            $billable->refresh();

            // Does the gift, as it now stands, still end the subscription before the
            // next charge? That is the question the renewal asks at the start of every
            // period, and it has to be asked again here: the answer may just have
            // changed, and nothing else looks at this subscription before that charge.
            $nextCharge = $runningOut
                ? $billable->getBillingSubscriptionEndsAt()
                : \GraystackIT\MollieBilling\Support\ChargeDeferral::undeferredNextChargeDate($billable);
            $endsBeforeNextCharge = $nextCharge !== null
                && $this->waiver->endsSubscription($billable, $nextCharge);

            if ($endsBeforeNextCharge) {
                // Already running out: it stays that way, a few days later at most.
                // Still active (the gift was just switched to "end"): cancel now, to the
                // end of the period — the renewal after it would collect the plan.
                if (! $runningOut && $status !== SubscriptionStatus::Trial) {
                    app(CancelSubscription::class)->handle($billable, false, 'grant_expired');
                }

                return $newEnd ?? Carbon::instance(BillingTime::nowUtc());
            }

            // The subscription was already running out with the gift: bring it back,
            // now that the gift reaches further. Resubscribing prices from the marker,
            // so the Mollie subscription it recreates carries the waiver.
            if ($runningOut) {
                try {
                    app(ResubscribeSubscription::class)->handle($billable);
                } catch (\Throwable $e) {
                    // All or nothing. With the extension kept, the operator's second
                    // attempt extended the gift a second time.
                    if ($before !== null) {
                        $this->waiver->restore($billable, $before);
                    }

                    throw $e;
                }
            } else {
                // Amount is unchanged, but a waiver that covers everything defers the
                // charge to its end — and that end has just moved. Queued when it does
                // not land: Mollie holds the full price behind the old date.
                $this->waiver->syncMollieOrQueue($billable, (int) ($before['coupon_id'] ?? 0) ?: null);
            }

            return $newEnd ?? Carbon::instance(BillingTime::nowUtc());
        }

        // The gift as a Local subscription with an end date.
        $currentEnd = $billable->getBillingSubscriptionEndsAt();

        if (
            $source !== SubscriptionSource::Local->value
            || $currentEnd === null
            || $billable->getBillingSubscriptionStatus() !== SubscriptionStatus::Active
        ) {
            throw new InvalidSubscriptionStateException(
                'There is no running grant to extend on this subscription.'
            );
        }

        $newEnd = Carbon::instance($currentEnd->isFuture() ? $currentEnd : BillingTime::nowUtc())
            ->setTimezone('UTC')
            ->addDays($days);

        $billable->forceFill(['subscription_ends_at' => $newEnd])->save();

        // Kept on the billable, not on the coupon: a grant code can be shared by many
        // tenants, and this is a decision about one of them. Read when the gift is
        // carried over to a Mollie subscription (LocalToMollieUpgradeHandler).
        if ($onExpiry !== null) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['grant_on_expiry'] = PlanWaiver::normalizeOnExpiry($onExpiry);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        event(new SubscriptionExtended($billable, $currentEnd, $newEnd, $this->grantCoupon($billable)));

        return $newEnd;
    }

    /**
     * Create a one-off coupon and redeem it for this billable at once.
     *
     * Validated before it is redeemed, like every other redemption — and the coupon is
     * removed again when the validation refuses it, so a rejected attempt leaves nothing
     * behind that could be redeemed by somebody who learns the code.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Billable $billable, string $prefix, array $attributes): CouponRedemption
    {
        $code = $prefix.'-'.Str::upper(Str::random(10));

        $coupon = $this->coupons->create(array_merge([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'active' => true,
            'max_redemptions' => 1,
            'max_redemptions_per_billable' => 1,
        ], $attributes));

        try {
            $this->coupons->validate($code, $billable, []);

            return $this->coupons->redeem($coupon, $billable, []);
        } catch (\Throwable $e) {
            if (! $coupon->redemptions()->exists()) {
                $coupon->delete();
            }

            throw $e;
        }
    }

    private function grantCoupon(Model $billable): ?Coupon
    {
        return CouponRedemption::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            ->where('grant_applied_snapshot->mode', 'full')
            ->latest('id')
            ->first()
            ?->coupon;
    }

    private function assertPositive(int $value, string $what): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("The number of {$what} must be greater than zero.");
        }
    }
}

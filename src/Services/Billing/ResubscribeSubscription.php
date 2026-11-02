<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionResumed;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use Illuminate\Database\Eloquent\Model;

/**
 * Undo a cancellation while the grace period is still running.
 *
 * "Undo" is the operative word: resubscribing restores the state the billable was in
 * before the cancel, it does not start a new subscription. Concretely that means the
 * already-paid period keeps its boundary (so Mollie's first charge lands there, not one
 * interval from now), a cancelled trial goes back to being a trial on its original end
 * date, and a duration-limited access grant keeps its expiry.
 */
class ResubscribeSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly CreateSubscription $createSubscription,
    ) {
    }

    public function handle(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable->getBillingSubscriptionStatus() !== SubscriptionStatus::Cancelled) {
            throw new \RuntimeException('Cannot resubscribe: subscription is not in a cancelled state.');
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();
        if ($endsAt === null || ! $endsAt->isFuture()) {
            throw new \RuntimeException('Cannot resubscribe: grace period has already ended.');
        }

        // An open country mismatch is what cancelled this subscription in the first place,
        // and resuming before it is resolved books the next period on the VAT data under
        // review. Nothing else would stop it: the dashboard only hides the button, and
        // CountryMatchService returns the existing mismatch without cancelling again.
        if ($billable->hasOpenCountryMismatch()) {
            throw new InvalidSubscriptionStateException(
                'Cannot resubscribe: a country mismatch is still open and has to be resolved first.'
            );
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $isLocal = $billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value;

        if ($isLocal) {
            $restoredEndsAt = $this->endsAtBeforeCancel($meta, $endsAt);
            unset($meta['cancelled_from']);

            // Same reason CreateSubscription does it on the Mollie side, and this branch
            // is the one place that was still missing it. A free plan with metered usage
            // does go past due — the overage charge is real money even when the plan is
            // not — and resuming with the old `past_due_since` still in the meta measured
            // the next failed charge against a months-old date, so the nightly pass
            // auto-cancelled the same night with no dunning window at all.
            $meta = SubscriptionMetaHygiene::withoutDunningEpisode($meta);

            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_ends_at' => $restoredEndsAt,
                'subscription_meta' => $meta,
            ])->save();

            SubscriptionResumed::dispatch($billable);

            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode();
        $interval = $billable->getBillingSubscriptionInterval();
        if ($planCode === null || $interval === null) {
            throw new \RuntimeException('Cannot resubscribe: plan or interval missing.');
        }

        $marker = $meta['active_recurring_coupon'] ?? null;
        $recurringDiscountNet = 0;
        if (is_array($marker)) {
            $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $billable->getExtraBillingSeats());
            $baseNet = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
                $this->catalog,
                $billable,
                $planCode,
                $interval,
                $totalSeats,
                $billable->getActiveBillingAddonCodes(),
            );
            // Through CouponService, which is the only place that knows whether the marker
            // is still redeemable. The private mirror below read discount_type,
            // discount_value and base_amount_net and nothing else — not the coupon's
            // `active` flag (deactivating it is the documented way to resolve a
            // recurring_conflict), not the marker's `valid_until`, and not the
            // per-billable application count. The marker survives a cancellation and is
            // only cleared on a renewal, which never happens during a grace period, so a
            // resubscribe re-applied campaigns that had closed or been switched off: Mollie
            // collected the discounted amount and the renewal handler — which does ask
            // CouponService — then fired PaymentAmountMismatch on a payment nobody had
            // asked to discount. MollieSubscriptionPatcher had the same mirror removed for
            // exactly this reason.
            $recurringDiscountNet = app(CouponService::class)->computeMarkerDiscount($billable, $baseNet);
        }

        $spec = [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $billable->getActiveBillingAddonCodes(),
            'extra_seats' => $billable->getExtraBillingSeats(),
            'recurring_discount_net' => $recurringDiscountNet,
        ];

        $trialEndsAt = $billable->getBillingTrialEndsAt();

        if ($trialEndsAt !== null && $trialEndsAt->isFuture()) {
            // Cancelled mid-trial (the state a graceful trial cancel and the
            // country-mismatch flow both produce): nothing has been paid, so the
            // subscription must go back to being a trial that ends when it always
            // would have. Activating it instead would hand out a paid-looking
            // subscription with the first charge a full interval away.
            $spec['trial_ends_at'] = $trialEndsAt;
        } else {
            // Mid-grace: the period the customer paid for runs until $endsAt, so that
            // is where Mollie's first charge belongs. Letting CreateSubscription fall
            // back to `now + 1 interval` would give away the remainder of the paid
            // period a second time.
            $spec['paid_period_ends_at'] = $endsAt;
        }

        unset($meta['cancelled_from']);
        $billable->forceFill(['subscription_meta' => $meta])->save();

        // CreateSubscription writes status (Trial or Active), `trial_ends_at`,
        // `subscription_ends_at = null` and the period window itself.
        $this->createSubscription->handle($billable, $spec);

        SubscriptionResumed::dispatch($billable);
    }

    /**
     * The `subscription_ends_at` the billable had *before* it was cancelled.
     *
     * CancelSubscription records it because both graceful branches overwrite the column
     * with a grace boundary, which makes an unlimited plan and a duration-limited access
     * grant indistinguishable afterwards. Only a recorded `null` (the plan really had no
     * expiry) clears the date — with no marker at all, e.g. rows cancelled by an older
     * release or by the past-due auto-cancel, the surviving expiry is kept. Turning a
     * finite grant into a perpetual subscription on a guess is the one outcome nothing
     * downstream can reclaim.
     *
     * @param  array<string, mixed>  $meta
     */
    private function endsAtBeforeCancel(array $meta, \Carbon\CarbonInterface $current): ?\Carbon\CarbonInterface
    {
        $recorded = $meta['cancelled_from'] ?? null;

        if (! is_array($recorded) || ! array_key_exists('ends_at', $recorded)) {
            return $current;
        }

        return $recorded['ends_at'] !== null
            ? \Carbon\Carbon::parse((string) $recorded['ends_at'])
            : null;
    }
}

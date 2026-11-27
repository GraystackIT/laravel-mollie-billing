<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionResumed;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingAnchor;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use GraystackIT\MollieBilling\Exceptions\PreviousSubscriptionStillRunningException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;

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
    use MutatesBillingMeta;

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly CreateSubscription $createSubscription,
    ) {
    }

    /**
     * Serialised with every other change to the subscription (`UpdateSubscription`'s
     * `billing:subscription-change:{id}` lock), and decided on the row as it is under that
     * lock. Two resumes at once — a second tab, a second operator — both read `cancelled`
     * before either had written, and each created a subscription at Mollie: the customer
     * was charged twice every period. The loser is refused rather than queued, as an
     * overlapping change is; arriving after the first has finished, it finds the row no
     * longer cancelled and is refused by the state check.
     */
    public function handle(Billable $billable): void
    {
        $lock = Cache::lock('billing:subscription-change:'.$billable->getKey(), 180);

        if (! $lock->get()) {
            throw new SubscriptionChangeInProgressException($billable);
        }

        try {
            if ($billable instanceof Model && $billable->exists) {
                $billable->refresh();
            }

            $this->resume($billable);
        } finally {
            $lock->release();
        }
    }

    private function resume(Billable $billable): void
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
            throw (new InvalidSubscriptionStateException(
                'Cannot resubscribe: a country mismatch is still open and has to be resolved first.'
            ))->withTranslation('billing::exceptions.state.resubscribe_mismatch_open');
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

            // Cancelled mid-trial: back to the trial it was, on its original end date —
            // the rule the Mollie branch below has always followed. `Active` here handed
            // out the plan with no end date and no payment behind it, and nothing ever
            // reclaimed it: the trial job only looks at status `Trial`.
            $trialEndsAt = $billable->getBillingTrialEndsAt();
            $resumesTrial = $trialEndsAt !== null && $trialEndsAt->isFuture();

            // A Local grace is thirty days, not the rest of a paid period, so it usually
            // runs across a period boundary — which the nightly pass, rightly, does not
            // renew for a subscription that is running out. Resumed with the old anchor,
            // `nextBillingDate()` stayed in the past for good: the pass's "renews
            // tomorrow" test never matched it again, the quota was never refilled, and
            // UpdateSubscription refused every change with "wait for the renewal". So the
            // resume does the turn-over the pass skipped: the period now running, on the
            // subscription's own anchor day, with its quota.
            $turnover = $resumesTrial ? null : $this->lapsedPeriodStart($billable);
            $attributes = [
                'subscription_status' => $resumesTrial ? SubscriptionStatus::Trial : SubscriptionStatus::Active,
                'subscription_ends_at' => $restoredEndsAt,
            ];

            if ($turnover !== null) {
                $attributes['subscription_period_starts_at'] = $turnover['start'];
                $meta = BillingAnchor::record($meta, $turnover['day'], $turnover['start']);
            }

            // Anchor first, as on the nightly turn-over: a refill that failed half-way
            // must not be repeated in full.
            $billable->forceFill($attributes + ['subscription_meta' => $meta])->save();

            // Not for a grant that ends by that boundary — the nightly pass gives such a
            // grant no quota for a period it does not cover either.
            if ($turnover !== null
                && ($restoredEndsAt === null || $restoredEndsAt->greaterThan($turnover['start']->endOfDay()))
            ) {
                app(WebhookSupport::class)->creditPeriodQuota(
                    $billable,
                    (string) $billable->getBillingSubscriptionPlanCode(),
                    $billable->getBillingSubscriptionInterval(),
                    'subscription_renewal',
                );
            }

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

        // The cancel this resume undoes may not have reached Mollie: then the old
        // subscription still collects, and the one created below would charge beside it.
        // Finished here first — or the resume is refused, with nothing written.
        $this->finishPendingCancel($billable);
        $meta = $billable->getBillingSubscriptionMeta();

        unset($meta['cancelled_from']);
        $billable->forceFill(['subscription_meta' => $meta])->save();

        // CreateSubscription writes status (Trial or Active), `trial_ends_at`,
        // `subscription_ends_at = null` and the period window itself.
        $this->createSubscription->handle($billable, $spec);

        SubscriptionResumed::dispatch($billable);
    }

    /**
     * Send the cancel `CancelSubscription` could not deliver, before a new subscription is
     * created beside the old one.
     *
     * `pending_subscription_cancel` is the record that the old subscription still runs at
     * Mollie; the hourly sweep retries it for 24 hours and then gives up. A resume in that
     * window created a second subscription, and both charged on the same day — the old
     * one's next charge is the paid period's end, which is exactly where the resume starts
     * the new one. Tried once more here, outside any transaction: done (or already gone
     * at Mollie) clears the marker, anything else refuses the resume with nothing written,
     * so the same click works again once Mollie answers.
     */
    private function finishPendingCancel(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        $marker = $billable->getBillingSubscriptionMeta()['pending_subscription_cancel'] ?? null;

        if (! is_array($marker)) {
            return;
        }

        $customerId = (string) ($marker['mollie_customer_id'] ?? '');
        $subscriptionId = (string) ($marker['mollie_subscription_id'] ?? '');

        if ($customerId !== '' && $subscriptionId !== '') {
            try {
                MollieCall::send(new CancelSubscriptionRequest($customerId, $subscriptionId));
            } catch (\Throwable $e) {
                if (! MollieSubscriptionAnswer::cancelledAlready($e)) {
                    throw new PreviousSubscriptionStillRunningException($billable, $e->getMessage());
                }
            }

            MollieSubscriptionGate::forget($subscriptionId);
        }

        $this->mutateBillingMeta($billable, static function (array $meta): ?array {
            if (! array_key_exists('pending_subscription_cancel', $meta)) {
                return null;
            }

            unset($meta['pending_subscription_cancel']);

            return $meta;
        });

        $billable->refresh();
    }

    /**
     * The start of the period running now, when the billable's own period ended while it
     * was cancelled — null when it has not.
     *
     * Stepped on the anchor day, as `nextBillingDate()` and the nightly pass count: a
     * period on the 31st turns over on 30 April and on 31 May again. Several boundaries
     * give one period, the current one; the quota is not owed in arrears.
     *
     * @return array{start: CarbonImmutable, day: int}|null
     */
    private function lapsedPeriodStart(Billable $billable): ?array
    {
        $periodStart = $billable->getBillingPeriodStartsAt();
        $interval = $billable->getBillingSubscriptionInterval();

        if ($periodStart === null || $interval === null) {
            return null;
        }

        $day = BillingAnchor::day($billable) ?? CarbonImmutable::instance($periodStart)->utc()->day;
        $now = BillingTime::nowUtc();
        $start = CarbonImmutable::instance($periodStart);

        // Bounded: 1200 monthly steps are a century.
        for ($i = 0; $i < 1200; $i++) {
            $next = BillingAnchor::next($start, $interval, $day);

            if ($next->greaterThan($now)) {
                break;
            }

            $start = $next;
        }

        return $start->equalTo($periodStart) ? null : ['start' => $start, 'day' => $day];
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

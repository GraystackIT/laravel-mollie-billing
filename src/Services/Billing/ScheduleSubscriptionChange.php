<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionChangeApplyFailed;
use GraystackIT\MollieBilling\Events\SubscriptionChangeCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionChangeRescheduled;
use GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ScheduleSubscriptionChange
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly ValidateSubscriptionChange $validator,
    ) {
    }

    public function schedule(Billable $billable, array|SubscriptionUpdateRequest $request): void
    {
        $dto = SubscriptionUpdateRequest::from($request);

        DB::transaction(function () use ($billable, $dto): void {
            /** @var Model&Billable $billable */
            if ($billable instanceof Model) {
                // Scope-free, like every other lock read in the package: with an
                // app-defined applyBillingScope() the scoped query can match nothing, and
                // then this looks like it worked — first() quietly returns null, no row is
                // locked, refresh() still loads fresh data (it uses
                // newQueryWithoutScopes()) and the write below runs unserialised against
                // whatever else is touching the same billable.
                $billable->newQuery()
                    ->withoutGlobalScope(BillingScope::class)
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            // Refused, not silently accepted: `apply()` drops a change whose status is no
            // longer applicable, so scheduling one for a Cancelled-in-grace customer
            // promised a "scheduled for <date>" that would never happen — the portal
            // rendered the confirmation, the daily dispatcher then discarded it without
            // ever telling anyone. A cancelled customer wanting a different plan has to
            // resubscribe; that path exists.
            if (! $this->isApplicable($billable)) {
                throw new InvalidSubscriptionStateException(
                    'Cannot schedule a subscription change while the subscription is '
                    .($billable->getBillingSubscriptionStatus()?->value ?? 'in no billable state')
                    .'. Resubscribe first.'
                );
            }

            $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
            $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
            $currentSeats = $billable->getBillingSeatCount();
            $currentAddons = $billable->getActiveBillingAddonCodes();

            $newPlan = $dto->planCode ?? $currentPlan;
            $newInterval = $dto->interval ?? $currentInterval;
            $planChanged = $newPlan !== $currentPlan;

            // Re-assert the hidden-plan rule against the locked row. UpdateSubscription
            // already ran it, but on the caller's model — which may predate a plan change
            // committed in the meantime. Only then does `planChanged` flip to true here,
            // and the guard has to see that: a scheduled move onto a hidden plan would
            // otherwise be stored now and blow up when it is applied at period end.
            if (! $dto->allowHiddenPlan) {
                $this->validator->assertPlanSelectable($billable, $newPlan);
            }

            // A target the catalog does not sell must be refused here too, not just in
            // update(). Stored now, it would price at 0 when it is applied at period end
            // — and `isPermanentApplyFailure()` already expects this exception, which is
            // how the gap showed: nothing could throw it on this path.
            if (($planChanged || $newInterval !== $currentInterval)
                && ! $this->catalog->planOffersInterval($newPlan, $newInterval)
            ) {
                throw new IntervalNotOfferedException($newPlan, $newInterval);
            }

            // Auto-derive seats from the new plan. `getBillingSeatCount()` has to be in the
            // max, exactly as in UpdateSubscription::buildContext() and
            // ValidateSubscriptionChange::validateSeats() — without it a billable on 14
            // seats with 10 used would have the change applied with 10, silently losing
            // four paid seats and dropping the renewal amount.
            $usedSeats = $billable->getUsedBillingSeats();
            $seatsExplicit = $dto->seats !== null;
            $newSeats = $dto->seats ?? max($usedSeats, $currentSeats, $this->catalog->includedSeats($newPlan));

            // Auto-filter incompatible addons — and persist the filtered map, not the raw
            // request. `validateAddons()` re-runs the same filter at apply time, so the
            // money was always right; the *stored* payload was not, and it is what the
            // portal and the admin panel render as "scheduled for <date>". A customer
            // moving from pro to starter was shown an addon starter cannot host, right up
            // to the moment it silently disappeared.
            $addonPayload = $dto->addons !== null
                ? array_filter($dto->addons, fn ($q) => (int) $q > 0)
                : null;

            if ($planChanged && $addonPayload !== null) {
                $addonPayload = array_filter(
                    $addonPayload,
                    fn ($q, string $code) => $this->catalog->planAllowsAddon($newPlan, $code),
                    ARRAY_FILTER_USE_BOTH,
                );
            }

            $scheduledAt = $this->periodEndFor($billable);

            $scheduledChange = [
                'plan_code' => $newPlan,
                'interval' => $newInterval,
                'seats' => $newSeats,
                'addons' => $addonPayload,
                'coupon_code' => $dto->couponCode,
                'coupon_codes' => $dto->couponCodes,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ];

            // Only persisted when set, so existing meta payloads stay byte-identical.
            // apply() replays this array through UpdateSubscription, which re-runs the
            // hidden-plan guard — without the flag a deliberately allowed move onto a
            // hidden plan would be rejected at period end.
            if ($dto->allowHiddenPlan) {
                $scheduledChange['allow_hidden_plan'] = true;
            }

            // `seats` is stored either way so the admin/portal listings can render the
            // resulting count, but a derived value must not reach apply() as an explicit
            // one: `seatsExplicit` bypasses the validator's auto-derive, which is what
            // clamps a preserved seat count down for a target plan that sells no extra
            // seats. Without the flag such a change would be accepted at schedule time
            // and throw SeatDowngradeRequiredException at period end.
            if (! $seatsExplicit) {
                $scheduledChange['seats_auto_derived'] = true;
            }

            $meta = $billable->getBillingSubscriptionMeta();
            $previous = $meta['scheduled_change'] ?? null;
            $meta['scheduled_change'] = $scheduledChange;

            if ($billable instanceof Model) {
                $billable->forceFill([
                    'subscription_meta' => $meta,
                    'scheduled_change_at' => $scheduledAt,
                ])->save();
            }

            if ($previous !== null) {
                event(new SubscriptionChangeRescheduled($billable, $scheduledChange, (array) $previous));
            } else {
                event(new SubscriptionChangeScheduled($billable, $scheduledChange, $scheduledAt));
            }
        });
    }

    public function cancel(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable instanceof Model) {
            $billable->refresh();
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $previous = $meta['scheduled_change'] ?? null;

        if ($previous === null) {
            return;
        }

        unset($meta['scheduled_change']);

        if ($billable instanceof Model) {
            $billable->forceFill([
                'subscription_meta' => $meta,
                'scheduled_change_at' => null,
            ])->save();
        }

        event(new SubscriptionChangeCancelled($billable, (array) $previous));
    }

    public function apply(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable instanceof Model) {
            $billable->refresh();
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $scheduledChange = $meta['scheduled_change'] ?? null;

        if ($scheduledChange === null) {
            return;
        }

        // A change scheduled for a subscription that no longer runs must be dropped, not
        // applied. `update()` is entered with `internal = true`, which waives the
        // lapsed-period guard, so nothing else stops it from rewriting plan/addon codes,
        // rebalancing wallets and charging overage against a customer who cancelled.
        // Dropping it also unsticks the daily dispatcher: `scheduled_change_at` is only
        // cleared on success, so an Expired billable (whose validator throws) would
        // otherwise re-fail this job every single day.
        if (! $this->isApplicable($billable)) {
            $this->cancel($billable);

            return;
        }

        $payload = (array) $scheduledChange;
        $allowHiddenPlan = ($payload['allow_hidden_plan'] ?? false) === true;
        $payload['apply_at'] = 'immediate';
        $payload['internal'] = true;
        unset($payload['scheduled_at'], $payload['allow_hidden_plan']);

        // Seats that were auto-derived at schedule time must be re-derived against live
        // state, not replayed as an explicit count — see schedule().
        if (($payload['seats_auto_derived'] ?? false) === true) {
            unset($payload['seats']);
        }
        unset($payload['seats_auto_derived']);

        // Trusted re-entry: the override was approved when the change was scheduled,
        // so it is re-applied through the typed API here. `from()` ignores the array
        // key on purpose — see SubscriptionUpdateRequest::from().
        $dto = SubscriptionUpdateRequest::from($payload);
        if ($allowHiddenPlan) {
            $dto = $dto->withAllowHiddenPlan();
        }

        try {
            app(UpdateSubscription::class)->update($billable, $dto);

            // Clear scheduled change on success.
            if ($billable instanceof Model) {
                $billable->refresh();
                $meta = $billable->getBillingSubscriptionMeta();
                unset($meta['scheduled_change']);
                $billable->forceFill([
                    'subscription_meta' => $meta,
                    'scheduled_change_at' => null,
                ])->save();
            }
        } catch (\Throwable $e) {
            event(new SubscriptionChangeApplyFailed($billable, (array) $scheduledChange, $e));

            $this->notifyAdminsOfApplyFailure($billable, (array) $scheduledChange, $e);

            // Seats are the one refusal here that the customer can still resolve: removing
            // team members — or dropping extra seats — makes this very stored change
            // applicable tomorrow. So it is neither dropped nor re-thrown. Re-throwing
            // failed the job every night for as long as the customer kept the members
            // (ApplyScheduledChangesJob allows a single attempt, so each night left
            // another entry in failed_jobs), while the customer kept paying the old price
            // with nothing but an event to tell anyone.
            if ($e instanceof SeatDowngradeRequiredException) {
                Log::warning('Keeping a scheduled change the current seat usage blocks', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }

            // A change the validator will refuse for the same reason tomorrow is dropped
            // rather than retried. `isApplicable()` above only screens on status, so a
            // change that survived a downgrade to free passed it (a Local free plan is
            // Active) and then threw LocalSubscriptionUpgradeRequiresMolliePathException
            // — and re-throwing without clearing `scheduled_change_at` made the daily
            // dispatcher fail on that billable every night, indefinitely.
            //
            // Transient failures still propagate, so the queue's own retry can have them.
            if ($this->isPermanentApplyFailure($e)) {
                Log::warning('Dropping a scheduled change its own validation permanently refuses', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'reason' => $e->getMessage(),
                ]);

                $this->cancel($billable);

                return;
            }

            throw $e;
        }
    }

    /**
     * Tell the operators a stored change did not apply.
     *
     * This used to be an `if (! empty($recipients)) { }` with a comment saying a proper
     * notification class was out of scope — so the block read as if admins were informed
     * and nobody was. `SubscriptionChangeApplyFailed` reaches the app's listeners, but a
     * change that silently stops applying is exactly the kind of thing an operator has to
     * hear about without having written a listener first.
     *
     * @param  array<string, mixed>  $scheduledChange
     */
    private function notifyAdminsOfApplyFailure(Billable $billable, array $scheduledChange, \Throwable $e): void
    {
        try {
            $admins = MollieBilling::notifyAdmin();
            $admins = is_array($admins) ? $admins : iterator_to_array($admins);

            if ($admins === []) {
                return;
            }

            Notification::send($admins, MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'A scheduled subscription change could not be applied',
                [
                    'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'plan_code' => $scheduledChange['plan_code'] ?? null,
                    'interval' => $scheduledChange['interval'] ?? null,
                    'scheduled_at' => $scheduledChange['scheduled_at'] ?? null,
                    'error' => $e->getMessage(),
                ],
            ));
        } catch (\Throwable) {
            // Announcing a failure must never be what fails the run.
        }
    }

    /**
     * Whether this failure will recur identically on the next run.
     *
     * Deliberately a whitelist of *domain* refusals rather than "anything that is not a
     * network error": a stored change is real customer intent, and dropping it because
     * of a transient fault would lose it silently. Everything not listed here is
     * re-thrown so the queue can retry.
     */
    private function isPermanentApplyFailure(\Throwable $e): bool
    {
        return $e instanceof LocalSubscriptionUpgradeRequiresMolliePathException
            || $e instanceof HiddenPlanNotSelectableException
            || $e instanceof IntervalNotOfferedException;
    }

    /**
     * Whether a stored change may still be applied to this billable.
     *
     * Only a subscription that is actually running can receive one. PastDue is included:
     * a plan change there is handled by the dedicated past-due reset path, which charges
     * a full first period. Cancelled, Expired and New are not — there is nothing to
     * change, and for Cancelled the customer explicitly asked to stop.
     */
    /**
     * When "end of period" actually falls.
     *
     * For a trial that is `nextBillingDate()` OR the trial end, whichever comes first.
     * `nextBillingDate()` is `period_start + interval` (day 30 of a 30-day month), but a
     * trial's first charge lands on `trial_ends_at` (day 14) — CreateSubscription sets
     * Mollie's `startDate` to exactly that. Anchoring on day 30 scheduled the change into
     * the middle of the first *paid* period, where it was applied as an immediate,
     * `internal` update: the lapsed-period guard is waived for those, so nothing stopped
     * it from raising the very prorata charge the customer had scheduled the change to
     * avoid.
     */
    public function periodEndFor(Billable $billable): \Carbon\CarbonInterface
    {
        $next = $billable->nextBillingDate();
        $trialEnd = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Trial
            ? $billable->getBillingTrialEndsAt()
            : null;

        if ($next !== null && $trialEnd !== null) {
            return $trialEnd->lessThan($next) ? $trialEnd : $next;
        }

        return $next ?? $trialEnd ?? BillingTime::nowUtc();
    }

    private function isApplicable(Billable $billable): bool
    {
        return in_array($billable->getBillingSubscriptionStatus(), [
            SubscriptionStatus::Active,
            SubscriptionStatus::Trial,
            SubscriptionStatus::PastDue,
        ], true);
    }
}

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
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
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
    use MutatesBillingMeta;

    /**
     * How long a due change that Mollie was not told about waits for the renewal of its
     * period. SEPA Direct Debit settles within a handful of business days; past a week
     * something else is wrong and holding the customer's change back helps nobody.
     */
    public const WAIT_FOR_RENEWAL_DAYS = 7;

    /**
     * How many nightly runs try to apply a due change before it is given up. The first
     * failure is attempt one.
     */
    public const MAX_APPLY_ATTEMPTS = 3;

    /**
     * On a stored change: the prorata payment one of its attempts created before failing.
     * See {@see rememberPaymentOfFailedAttempt()}.
     */
    public const OWN_PAYMENT_KEY = 'charge_payment_id';

    /**
     * On a stored change: the token of a prorata payment one of its attempts asked Mollie
     * for without learning the answer (`InvoiceService::CHARGE_IN_CREATION_KEY`).
     */
    public const OWN_CHARGE_TOKEN_KEY = 'charge_token';

    /** The payment {@see withdrawOwnChargeInCreation()} found and withdrew, for the drop notice. */
    private ?string $withdrawnPaymentId = null;

    /** Where the last dropped change is kept so the screens can say what became of it. */
    public const DROPPED_KEY = 'scheduled_change_dropped';

    /** How long the screens keep saying it. */
    public const DROPPED_NOTICE_DAYS = 14;

    /**
     * A scheduled change that has failed to apply and is waiting for its next attempt:
     * which attempt comes next, out of how many, and what went wrong last time. Null while
     * nothing has failed.
     *
     * `payment_id` names the prorata payment an attempt created before it failed — the
     * change is then waiting for a payment that is in flight, and no further one is made.
     *
     * @return array{attempts: int, next_attempt: int, max: int, last_error: ?string, payment_id: ?string}|null
     */
    public static function retryState(Billable $billable): ?array
    {
        $change = $billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null;
        $attempts = is_array($change) ? (int) ($change['apply_attempts'] ?? 0) : 0;

        if ($attempts < 1) {
            return null;
        }

        return [
            'attempts' => $attempts,
            'next_attempt' => min(self::MAX_APPLY_ATTEMPTS, $attempts + 1),
            'max' => self::MAX_APPLY_ATTEMPTS,
            'last_error' => isset($change['last_apply_error']) ? (string) $change['last_apply_error'] : null,
            'payment_id' => ! empty($change[self::OWN_PAYMENT_KEY]) ? (string) $change[self::OWN_PAYMENT_KEY] : null,
        ];
    }

    /**
     * The scheduled change that was dropped most recently, for as long as the customer
     * should still be told: not once a new change is scheduled or DROPPED_NOTICE_DAYS
     * have passed. `plan_code` is empty for a change that was about seats or addons only.
     *
     * `payment_on_its_way` is true while a payment the dropped change had created can still
     * arrive: it is returned in full when it does, and the screen says so until then.
     *
     * @return array{plan_code: string, interval: ?string, reason: string, dropped_at: string, attempts: int, payment_on_its_way: bool}|null
     */
    public static function droppedNotice(Billable $billable): ?array
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $dropped = $meta[self::DROPPED_KEY] ?? null;

        if (! is_array($dropped) || ! empty($meta['scheduled_change']) || empty($dropped['dropped_at'])) {
            return null;
        }

        if (BillingTime::toUtc((string) $dropped['dropped_at'])->lt(BillingTime::nowUtc()->subDays(self::DROPPED_NOTICE_DAYS))) {
            return null;
        }

        return [
            'plan_code' => (string) ($dropped['plan_code'] ?? ''),
            'interval' => isset($dropped['interval']) ? (string) $dropped['interval'] : null,
            'reason' => (string) ($dropped['reason'] ?? 'default'),
            'dropped_at' => (string) $dropped['dropped_at'],
            'attempts' => (int) ($dropped['attempts'] ?? 0),
            'payment_on_its_way' => self::paymentStillExpected($meta, (string) ($dropped['payment_id'] ?? '')),
        ];
    }

    /**
     * Whether the payment a withdrawn change had created may still settle — the marker
     * `UpdateSubscription` leaves for it is consumed when the payment is returned.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function paymentStillExpected(array $meta, string $paymentId): bool
    {
        return $paymentId !== ''
            && (string) ($meta['orphaned_prorata_payment']['payment_id'] ?? '') === $paymentId;
    }

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly ValidateSubscriptionChange $validator,
    ) {
    }

    public function schedule(Billable $billable, array|SubscriptionUpdateRequest $request): void
    {
        $dto = SubscriptionUpdateRequest::from($request);

        /** @var array<string, mixed>|null $previous */
        $previous = null;

        DB::transaction(function () use ($billable, $dto, &$previous): void {
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
                $status = $billable->getBillingSubscriptionStatus();

                throw (new InvalidSubscriptionStateException(
                    'Cannot schedule a subscription change while the subscription is '
                    .($status?->value ?? 'in no billable state')
                    .'. Resubscribe first.'
                ))->withTranslation('billing::exceptions.state.schedule_not_running', ['status' => $status?->label() ?? '—']);
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

            // Same rule, same place as the immediate path: see
            // ValidateSubscriptionChange::assertIntervalChangeAllowed().
            if ($newInterval !== $currentInterval) {
                $this->validator->assertIntervalChangeAllowed($billable);
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

            // Derived exactly as in UpdateSubscription::buildContext() and
            // ValidateSubscriptionChange::validateSeats() — BillingPolicy::derivedSeats().
            // A plan change lands on the seats in use or included by the new plan; an
            // interval change keeps the count (a billable on 14 seats with 10 used keeps
            // its 14).
            $usedSeats = $billable->getUsedBillingSeats();
            $seatsExplicit = $dto->seats !== null;
            $newSeats = $dto->seats ?? BillingPolicy::derivedSeats($currentSeats, $usedSeats, $this->catalog->includedSeats($newPlan), $planChanged);

            // Auto-filter incompatible addons — and persist the filtered map, not the raw
            // request. `validateAddons()` re-runs the same filter at apply time, so the
            // money was always right; the *stored* payload was not, and it is what the
            // portal and the admin panel render as "scheduled for <date>". A customer
            // moving from pro to starter was shown an addon starter cannot host, right up
            // to the moment it silently disappeared.
            //
            // Both documented shapes are accepted: a code => quantity map and a plain list
            // of codes. The filter below is right for the map and empties the list —
            // `(int) 'analytics'` is 0 — so a change scheduled with the list form was
            // stored with no addons at all and removed every addon when it was applied.
            $addonPayload = null;

            if ($dto->addons !== null) {
                $addonPayload = array_is_list($dto->addons)
                    ? array_fill_keys(array_map('strval', $dto->addons), 1)
                    : array_filter($dto->addons, fn ($q) => (int) $q > 0);
            }

            if ($planChanged && $addonPayload !== null) {
                $addonPayload = array_filter(
                    $addonPayload,
                    fn ($q, string $code) => $this->catalog->planAllowsAddon($newPlan, $code),
                    ARRAY_FILTER_USE_BOTH,
                );
            }

            // The same refusal the immediate path makes: seats or a paid addon are not
            // added while the subscription is past due, now or "at the period end".
            $this->validator->assertNoPastDueExpansion(
                $billable,
                $planChanged || $newInterval !== $currentInterval,
                $seatsExplicit,
                $currentSeats,
                $newSeats,
                $addonPayload === null ? [] : array_values(array_diff(array_map('strval', array_keys($addonPayload)), $currentAddons)),
                $newInterval,
            );

            $this->assertNoPlanExpansionWhilePastDue(
                $billable,
                $newPlan,
                $newInterval,
                $newSeats,
                $addonPayload === null ? $currentAddons : array_map('strval', array_keys($addonPayload)),
            );

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

        // After the commit, never inside it: this is a Mollie call.
        DB::afterCommit(fn () => $this->alignMollieWithSchedule($billable, is_array($previous) ? $previous : null));
    }

    /**
     * Tell Mollie about a scheduled change NOW, so the renewal that opens the new period
     * is already created at the new amount.
     *
     * A PATCH of the subscription amount never touches the running period — it prices the
     * *next* recurring payment. And Mollie creates that payment before our dispatcher can
     * act: `apply()` only runs once `scheduled_change_at <= now`, i.e. at or after the
     * charge date, while a SEPA debit is created around two days ahead and stays `pending`
     * for days. Patching at apply time therefore reached the renewal after next. The one
     * in flight settled at the OLD amount, the period had lapsed so the prorata factor was
     * 0 and nothing was credited — a customer who booked Pro → Starter for the period end
     * paid Pro for the first Starter period, with a `PaymentAmountMismatch` event as the
     * only trace.
     *
     * Only for a change that keeps the interval and still bills something:
     *
     * - An interval change needs Mollie's cadence moved, which is the patcher's own
     *   business and cannot be done ahead of time without moving the charge date.
     * - A free target has no amount to patch to; cancelling ahead of time could not be
     *   taken back if the customer withdraws the change.
     *
     * Both are caught on the other side instead — {@see SupersededRenewalRefund}.
     *
     * @param  array<string, mixed>|null  $previous  the scheduled change this one replaces
     */
    private function alignMollieWithSchedule(Billable $billable, ?array $previous): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $billable->refresh();
        $change = $billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null;

        if (! is_array($change) || ! $this->billsThroughMollie($billable)) {
            return;
        }

        $intent = $this->prepatchIntent($billable, $change);

        if ($intent === null) {
            // Not a change Mollie can be told about ahead of time. If the one it replaces
            // was, Mollie still holds that amount and has to be put back.
            if (($previous['mollie_prepatched'] ?? false) === true) {
                $this->restoreRecurringAmount($billable, $previous);
            }

            return;
        }

        try {
            app(MollieSubscriptionPatcher::class)->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            // Not fatal: the change is stored and will be applied. Without the marker the
            // renewal hook leaves it to the dispatcher, and a renewal that arrives at the
            // old, higher amount is refunded down by SupersededRenewalRefund.
            Log::warning('Could not pre-patch Mollie for a scheduled change — the renewal will be reconciled instead', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            if (($previous['mollie_prepatched'] ?? false) === true) {
                $this->restoreRecurringAmount($billable, $previous);
            }

            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            if (! is_array($meta['scheduled_change'] ?? null)) {
                return null;
            }

            $meta['scheduled_change']['mollie_prepatched'] = true;

            return $meta;
        });
    }

    /**
     * Re-announce a stored change to Mollie after something else PATCHed the subscription.
     *
     * An immediate seat or addon change PATCHes the amount of the configuration the
     * billable is on NOW, which overwrites the amount announced for the scheduled change
     * — and the renewal at the boundary is then created for a plan the customer is about
     * to leave. Called by whoever just patched; a no-op without a stored change.
     */
    public function realignScheduled(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        try {
            $billable->refresh();
            $change = $billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null;

            if (is_array($change)) {
                $this->alignMollieWithSchedule($billable, $change);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not re-align Mollie with the scheduled change', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The intent that moves Mollie to a scheduled change's target, or null when the change
     * is not one that can be patched ahead of time.
     *
     * @param  array<string, mixed>  $change
     */
    private function prepatchIntent(Billable $billable, array $change): ?PlanChangeIntent
    {
        $currentPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $currentInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');
        $newPlan = (string) ($change['plan_code'] ?? $currentPlan);
        $newInterval = (string) ($change['interval'] ?? $currentInterval);

        if ($currentPlan === '' || $newPlan === '' || $newInterval !== $currentInterval) {
            return null;
        }

        $currentAddons = $billable->getActiveBillingAddonCodes();
        $newAddons = $this->addonCodesOf($change['addons'] ?? null) ?? $currentAddons;

        if ($newPlan !== $currentPlan) {
            $newAddons = array_values(array_filter(
                $newAddons,
                fn (string $code): bool => $this->catalog->planAllowsAddon($newPlan, $code),
            ));
        }

        // An auto-derived count is re-derived against live state, as apply() does.
        $currentSeats = $billable->getBillingSeatCount();
        $derivedSeats = BillingPolicy::derivedSeats($currentSeats, $billable->getUsedBillingSeats(), $this->catalog->includedSeats($newPlan), $newPlan !== $currentPlan);
        $newSeats = ($change['seats_auto_derived'] ?? false) === true || ! isset($change['seats'])
            ? $derivedSeats
            : (int) $change['seats'];

        $targetNet = SubscriptionAmount::net($this->catalog, $billable, $newPlan, $newInterval, $newSeats, $newAddons);

        if ($targetNet <= 0) {
            return null;
        }

        return new PlanChangeIntent(
            billable: $billable,
            currentPlan: $currentPlan,
            newPlan: $newPlan,
            currentInterval: $currentInterval,
            newInterval: $newInterval,
            currentSeats: $currentSeats,
            newSeats: $newSeats,
            currentAddons: array_fill_keys($currentAddons, 1),
            newAddons: array_fill_keys($newAddons, 1),
        );
    }

    /**
     * PATCH Mollie back to what the billable is on right now — a pre-patched change was
     * withdrawn or replaced by one that cannot be patched ahead.
     *
     * @param  array<string, mixed>  $withdrawn
     */
    private function restoreRecurringAmount(Billable $billable, array $withdrawn): void
    {
        if (! ($billable instanceof Model) || ! $this->billsThroughMollie($billable)) {
            return;
        }

        $plan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        if ($plan === '') {
            return;
        }

        $addons = array_fill_keys($billable->getActiveBillingAddonCodes(), 1);
        $seats = $billable->getBillingSeatCount();

        $intent = new PlanChangeIntent(
            billable: $billable,
            currentPlan: (string) ($withdrawn['plan_code'] ?? $plan),
            newPlan: $plan,
            currentInterval: $interval,
            newInterval: $interval,
            currentSeats: (int) ($withdrawn['seats'] ?? $seats),
            newSeats: $seats,
            currentAddons: $addons,
            newAddons: $addons,
        );

        try {
            app(MollieSubscriptionPatcher::class)->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            Log::warning('Could not restore the Mollie amount after a scheduled change was withdrawn — queued for retry', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            $intentData = [
                ...$intent->toArray(),
                'billable_type' => (string) config('mollie-billing.billable_model'),
                'billable_id' => $billable->getKey(),
            ];
            $firstAttemptAt = BillingTime::nowUtc()->toIso8601String();

            $this->mutateBillingMeta($billable, function (array $meta) use ($intentData, $firstAttemptAt, $e): array {
                $meta['pending_subscription_patch'] = [
                    'intent' => $intentData,
                    'first_attempt_at' => $firstAttemptAt,
                    'last_error' => $e->getMessage(),
                ];

                return $meta;
            });

            RetrySubscriptionPatchJob::dispatch($intentData, $firstAttemptAt);
        }
    }

    private function billsThroughMollie(Billable $billable): bool
    {
        return $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value
            && $this->isApplicable($billable)
            && ! empty($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null);
    }

    /**
     * Addon codes from a stored `addons` payload, which may be a list of codes or a
     * code => quantity map. Null when the change does not touch addons.
     *
     * @return array<int, string>|null
     */
    private function addonCodesOf(mixed $addons): ?array
    {
        if (! is_array($addons)) {
            return null;
        }

        if (array_is_list($addons)) {
            return array_values(array_map('strval', $addons));
        }

        return array_values(array_map('strval', array_keys(array_filter($addons, fn ($q) => (int) $q > 0))));
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

        // The withdrawn change had already been announced to Mollie — take it back, or
        // the next renewal is collected at an amount for a plan the customer is not on.
        if (is_array($previous) && ($previous['mollie_prepatched'] ?? false) === true) {
            DB::afterCommit(fn () => $this->restoreRecurringAmount($billable, $previous));
        }

        // One of its attempts got as far as creating the prorata payment. The change that
        // waits for that payment goes with it — otherwise the payment settles days later
        // and carries out a change the customer was told is off.
        $ownPayment = is_array($previous) ? (string) ($previous[self::OWN_PAYMENT_KEY] ?? '') : '';
        $ownToken = is_array($previous) ? (string) ($previous[self::OWN_CHARGE_TOKEN_KEY] ?? '') : '';

        if ($ownPayment !== '') {
            DB::afterCommit(fn () => $this->withdrawOwnPendingChange($billable, $ownPayment));
        } elseif ($ownToken !== '') {
            DB::afterCommit(fn () => $this->withdrawOwnChargeInCreation($billable, $ownToken));
        }

        event(new SubscriptionChangeCancelled($billable, (array) $previous));
    }

    /**
     * Take back the change that is waiting for a payment this scheduled change created.
     *
     * Only ever its own: the id was recorded by {@see rememberPaymentOfFailedAttempt()},
     * and `withdrawPendingChangeForPayment()` leaves a waiting change alone that names a
     * different payment — an immediate seat change the customer made in the meantime is
     * not this change's to withdraw.
     *
     * Never throws: this runs while a change is being given up, which must complete.
     */
    private function withdrawOwnPendingChange(Billable $billable, string $paymentId): void
    {
        try {
            app(UpdateSubscription::class)->withdrawPendingChangeForPayment($billable, $paymentId);
        } catch (\Throwable $e) {
            Log::warning('Could not withdraw the change waiting for a dropped scheduled change\'s payment', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The counterpart of {@see withdrawOwnPendingChange()} for a payment whose creation
     * went unanswered: Mollie is asked for it by its token. Found → it is taken over and
     * withdrawn like any waiting change (cancelled where possible, otherwise returned when
     * it settles). Not found → the note goes. Mollie cannot be asked → the note goes as
     * well: with nothing claiming it, a payment that settles later is returned in full by
     * the webhook. What it may not do is stay, or the webhook would carry the dropped
     * change out.
     */
    private function withdrawOwnChargeInCreation(Billable $billable, string $token): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $invoices = app(InvoiceService::class);

        try {
            $marker = $billable->refresh()->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null;

            if (! is_array($marker) || (string) ($marker['token'] ?? '') !== $token) {
                return;
            }

            $payment = $invoices->resolveChargeInCreation($billable, $marker);

            if ($payment !== null && $invoices->adoptChargeInCreation($billable, $token, (string) $payment->id)) {
                if (app(UpdateSubscription::class)->withdrawPendingChangeForPayment($billable, (string) $payment->id)) {
                    $this->withdrawnPaymentId = (string) $payment->id;
                }

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not ask Mollie about the unanswered payment of a dropped scheduled change — it is returned if it settles', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $invoices->forgetChargeInCreation($billable, $token);
    }

    /** The payment a change is currently waiting for, or '' when none is. */
    private function pendingChargePaymentId(Billable $billable): string
    {
        return (string) ($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /**
     * Note on the stored change the token of a payment the failed attempt asked Mollie for
     * without learning the answer — so that dropping the change can find and withdraw it.
     */
    private function rememberChargeInCreation(Billable $billable, string $tokenBefore): void
    {
        $token = (string) ($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY]['token'] ?? '');

        if ($token === '' || $token === $tokenBefore || ! ($billable instanceof Model)) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($token): ?array {
            if (! is_array($meta['scheduled_change'] ?? null)) {
                return null;
            }

            $meta['scheduled_change'][self::OWN_CHARGE_TOKEN_KEY] = $token;

            return $meta;
        });
    }

    /**
     * Note on the stored change that the attempt which just failed had already created its
     * prorata payment.
     *
     * `update()` can throw after `InvoiceService::createCharge()` returned — an app
     * listener on `PlanChangePending`, a failed write. The payment is then in flight and
     * `pending_prorata_change` names it, while the stored change is still here and is
     * tried again. The following attempts create no second payment (the validator refuses
     * a change while another waits for its payment); what was missing is the link between
     * the two, so that giving the stored change up also gives up what waits for its
     * payment. Compared against the id seen before the attempt, so a payment that belongs
     * to a different change is never claimed.
     */
    private function rememberPaymentOfFailedAttempt(Billable $billable, string $paymentBefore, string $tokenBefore = ''): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        try {
            $billable->refresh();
            $paymentAfter = $this->pendingChargePaymentId($billable);

            if ($paymentAfter === '' || $paymentAfter === $paymentBefore) {
                // No id came back — but Mollie may have taken the payment all the same.
                $this->rememberChargeInCreation($billable, $tokenBefore);

                return;
            }

            $this->mutateBillingMeta($billable, function (array $meta) use ($paymentAfter): ?array {
                if (! is_array($meta['scheduled_change'] ?? null)) {
                    return null;
                }

                $meta['scheduled_change'][self::OWN_PAYMENT_KEY] = $paymentAfter;

                return $meta;
            });
        } catch (\Throwable $e) {
            Log::warning('Could not note the payment a failed scheduled change created', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply a due scheduled change while its renewal is being booked.
     *
     * Called by `SubscriptionPaymentHandler::paid()` before it prices the renewal. The
     * payment in hand opens the period the change was scheduled for, so the change has to
     * be in place before the invoice lines are built — otherwise a renewal Mollie already
     * collected at the new amount is invoiced as the old plan plus an `adjustment`, and
     * the dispatcher then applies the change mid-period with a prorata on top (an upgrade
     * was charged its difference twice).
     *
     * Only for a change Mollie was told about ahead of time (`mollie_prepatched`) or one
     * whose target bills nothing. Anything else — an interval change, a change whose
     * pre-patch failed — is left to the dispatcher and to {@see SupersededRenewalRefund},
     * exactly as before.
     *
     * Never throws: a renewal must be booked whatever happens to the change.
     *
     * @return bool whether a change was applied
     */
    public function applyAtRenewal(Billable $billable): bool
    {
        if (! ($billable instanceof Model)) {
            return false;
        }

        try {
            $billable->refresh();
            $change = $billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null;

            if (! is_array($change)) {
                return false;
            }

            // Active or Trial only. A paid renewal on a PastDue subscription is the
            // recovery of the period that failed, not the opening of the next one.
            if (! in_array($billable->getBillingSubscriptionStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)) {
                return false;
            }

            // This renewal has to be the one at the boundary. Two days of slack, because
            // the local anchor and Mollie's charge date can sit a day apart.
            $scheduledAt = isset($change['scheduled_at'])
                ? \Carbon\Carbon::parse((string) $change['scheduled_at'])->setTimezone('UTC')
                : null;

            if ($scheduledAt === null || $scheduledAt->greaterThan(BillingTime::nowUtc()->addDays(2))) {
                return false;
            }

            $currentInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

            if ((string) ($change['interval'] ?? $currentInterval) !== $currentInterval) {
                return false;
            }

            $prepatched = ($change['mollie_prepatched'] ?? false) === true;
            $billsNothing = $this->prepatchIntent($billable, $change) === null;

            if (! $prepatched && ! $billsNothing) {
                return false;
            }

            $dto = $this->replayRequest($change)->asPeriodBoundary();

            app(UpdateSubscription::class)->update($billable, $dto);

            $this->clearAppliedChange($billable);

            return true;
        } catch (\Throwable $e) {
            Log::warning('A scheduled change could not be applied with its renewal — left to the dispatcher', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The request that replays a stored change through `UpdateSubscription`.
     *
     * @param  array<string, mixed>  $scheduledChange
     */
    private function replayRequest(array $scheduledChange): SubscriptionUpdateRequest
    {
        $payload = $scheduledChange;
        $allowHiddenPlan = ($payload['allow_hidden_plan'] ?? false) === true;
        $payload['apply_at'] = 'immediate';
        unset($payload['scheduled_at'], $payload['allow_hidden_plan'], $payload['internal'], $payload['mollie_prepatched']);

        // Seats that were auto-derived at schedule time must be re-derived against live
        // state, not replayed as an explicit count — see schedule().
        if (($payload['seats_auto_derived'] ?? false) === true) {
            unset($payload['seats']);
        }
        unset($payload['seats_auto_derived']);

        // Trusted re-entry: the override was approved when the change was scheduled,
        // so it is re-applied through the typed API here, and so is the `internal`
        // marker. `from()` ignores both array keys on purpose — see
        // SubscriptionUpdateRequest::from().
        $dto = SubscriptionUpdateRequest::from($payload)->asInternalReentry();

        return $allowHiddenPlan ? $dto->withAllowHiddenPlan() : $dto;
    }

    private function clearAppliedChange(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        unset($meta['scheduled_change']);
        $billable->forceFill([
            'subscription_meta' => $meta,
            'scheduled_change_at' => null,
        ])->save();
    }

    public function apply(Billable $billable, bool $onRequest = false): void
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

        // Not yet: the renewal that opens the new period has not been booked.
        if ($this->waitsForItsRenewal($billable, (array) $scheduledChange)) {
            return;
        }

        $dto = $this->replayRequest((array) $scheduledChange);
        $paymentBefore = $this->pendingChargePaymentId($billable);
        $tokenBefore = (string) ($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY]['token'] ?? '');

        try {
            // A change that was scheduled while everything was fine and meets a past-due
            // subscription tonight: a dearer plan or interval is refused like any
            // expansion — not carried out as the past-due reset, which would charge a
            // full period of a plan the customer chose before they stopped paying.
            $this->assertNoPlanExpansionWhilePastDue(
                $billable,
                (string) ($scheduledChange['plan_code'] ?? $billable->getBillingSubscriptionPlanCode()),
                (string) ($scheduledChange['interval'] ?? $billable->getBillingSubscriptionInterval()),
                (int) ($scheduledChange['seats'] ?? $billable->getBillingSeatCount()),
                is_array($scheduledChange['addons'] ?? null)
                    ? array_map('strval', array_is_list($scheduledChange['addons']) ? $scheduledChange['addons'] : array_keys($scheduledChange['addons']))
                    : $billable->getActiveBillingAddonCodes(),
            );

            $result = app(UpdateSubscription::class)->update($billable, $dto);

            // Clear scheduled change on success.
            $this->clearAppliedChange($billable);

            // Applied before its period end — "Apply now", or a dispatcher run ahead of the
            // boundary — an upgrade carries a prorata charge and waits for it like any
            // immediate one: the plan is not switched yet. Mollie, though, still holds the
            // target's amount from the announcement, and `mollie_prepatched` — the only
            // record of that — has just gone with the scheduled change. A declined charge
            // or a withdrawn waiting change then left the customer on the old plan while
            // every renewal was collected at the new one's price (refunded down after the
            // fact by SupersededRenewalRefund, or not at all if that refund failed). So
            // Mollie is put back to what the customer is on now, which is exactly where an
            // ordinary immediate upgrade waits from; Phase 2 PATCHes it forward once the
            // charge clears, and every path that gives the change up has nothing to undo.
            if (($result['pendingPaymentConfirmation'] ?? false) === true
                && ($scheduledChange['mollie_prepatched'] ?? false) === true
            ) {
                $this->restoreRecurringAmount($billable->refresh(), (array) $scheduledChange);
            }
        } catch (\Throwable $e) {
            // Whoever gives this change up later has to know that a payment is on its way.
            $this->rememberPaymentOfFailedAttempt($billable, $paymentBefore, $tokenBefore);

            // The customer asked for it now and is looking at the screen: they are told
            // there, and the change stays booked for its date. Dropping it — or counting
            // an attempt against it — is for the unattended run, where nobody is.
            if ($onRequest) {
                throw $e;
            }

            $this->resolveApplyFailure($billable, (array) $scheduledChange, $e);
        }
    }

    /**
     * While past due, no plan or interval change that costs more than what the customer
     * has now — neither stored for the period end nor carried out on its night.
     *
     * `ValidateSubscriptionChange::assertNoPastDueExpansion()` already refuses added seats
     * and paid addons, and every plan or interval change under a pure overage debt. What
     * it lets through is a plan or interval change after a failed renewal, because
     * *immediately* that is the past-due reset — the customer's way out, charged at once.
     * Scheduled, it is not a way out of anything: the debt stays open until the period
     * end, and then the dearer plan would be charged in full. A cheaper one stays
     * possible; the screen says the open payment remains due.
     *
     * @param  array<int, string>  $newAddons
     *
     * @throws \GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException
     */
    private function assertNoPlanExpansionWhilePastDue(Billable $billable, string $newPlan, string $newInterval, int $newSeats, array $newAddons): void
    {
        if (! $billable->isBillingPastDue()) {
            return;
        }

        $currentPlan = (string) $billable->getBillingSubscriptionPlanCode();
        $currentInterval = (string) $billable->getBillingSubscriptionInterval();

        if ($newPlan === $currentPlan && $newInterval === $currentInterval) {
            return;
        }

        $current = SubscriptionAmount::net($this->catalog, $billable, $currentPlan, $currentInterval, $billable->getBillingSeatCount(), $billable->getActiveBillingAddonCodes());
        $new = SubscriptionAmount::net($this->catalog, $billable, $newPlan, $newInterval, $newSeats, array_values($newAddons));

        if ($new > $current) {
            throw new \GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException($billable);
        }
    }

    /**
     * A stored change did not apply — deal with it without anybody having to.
     *
     * This used to mail the admins on every failure and then either keep the change
     * (seat usage), drop it (three "permanent" exceptions) or re-throw, which failed the
     * nightly job. An operator got a mail per night and could do nothing with it that the
     * package could not do itself:
     *
     * - **Refused by the rules** ({@see refusalReason()}): it will be refused again
     *   tomorrow. Dropped at once, and the customer is told why — they are the one who
     *   can change it (release seats, settle a payment, pick another plan). No admin.
     * - **Anything else** is taken to be transient: Mollie unreachable, a lock held, a
     *   payment that could not be created. The change stays, `apply_attempts` counts up
     *   and the dispatcher brings it back the following night — it selects on
     *   `scheduled_change_at <= now`, which still holds. Nobody is told while that runs.
     * - **After MAX_APPLY_ATTEMPTS** it is dropped, the customer is told, and now — when
     *   the automatic way is exhausted — the admins are.
     *
     * Dropping goes through `cancel()`, so an amount announced to Mollie is taken back.
     * `SubscriptionChangeApplyFailed` is dispatched once, when the change is given up —
     * one audit entry per change, not one per night.
     *
     * Never re-throws: the job allows a single attempt, and the retry is the next night's
     * dispatch, not the queue's.
     *
     * @param  array<string, mixed>  $scheduledChange
     */
    private function resolveApplyFailure(Billable $billable, array $scheduledChange, \Throwable $e): void
    {
        $attempts = (int) ($scheduledChange['apply_attempts'] ?? 0) + 1;
        $refusal = $this->refusalReason($e);

        if ($refusal === null && $attempts < self::MAX_APPLY_ATTEMPTS) {
            Log::warning('Scheduled change could not be applied — it is tried again on the next run', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'attempt' => $attempts,
                'of' => self::MAX_APPLY_ATTEMPTS,
                'error' => $e->getMessage(),
            ]);

            if ($billable instanceof Model) {
                $this->mutateBillingMeta($billable, function (array $meta) use ($attempts, $e): ?array {
                    if (! is_array($meta['scheduled_change'] ?? null)) {
                        return null;
                    }

                    $meta['scheduled_change']['apply_attempts'] = $attempts;
                    $meta['scheduled_change']['last_apply_error'] = mb_substr($e->getMessage(), 0, 500);

                    return $meta;
                });
            }

            return;
        }

        $reason = $refusal ?? 'attempts_exhausted';

        Log::warning('Dropping a scheduled change that cannot be applied', [
            'billable' => $billable instanceof Model ? $billable->getKey() : null,
            'reason' => $reason,
            'attempts' => $attempts,
            'error' => $e->getMessage(),
        ]);

        try {
            event(new SubscriptionChangeApplyFailed($billable, $scheduledChange, $e));
        } catch (\Throwable) {
            // A listener must not keep the change from being resolved.
        }

        // Read before cancel() removes it: which payment, if any, this change had created.
        $previous = $billable instanceof Model
            ? (array) ($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? [])
            : [];

        $this->withdrawnPaymentId = null;
        $this->cancel($billable);

        // A payment the change had created and Mollie would not cancel: it is returned
        // when it arrives, and the customer is told that now rather than left to wonder
        // about a debit for a change that was called off.
        $ownPayment = '';
        $paymentOnItsWay = false;

        // Kept for the screens: the mail may go unread, and "my scheduled change is gone"
        // without a word is a support ticket.
        if ($billable instanceof Model) {
            $billable->refresh();
            $ownPayment = (string) ($previous[self::OWN_PAYMENT_KEY] ?? '') ?: (string) $this->withdrawnPaymentId;
            $paymentOnItsWay = self::paymentStillExpected($billable->getBillingSubscriptionMeta(), $ownPayment);

            $this->mutateBillingMeta($billable, function (array $meta) use ($scheduledChange, $reason, $attempts, $refusal, $ownPayment, $paymentOnItsWay): array {
                $meta[self::DROPPED_KEY] = [
                    'plan_code' => (string) ($scheduledChange['plan_code'] ?? ''),
                    'interval' => $scheduledChange['interval'] ?? null,
                    'reason' => $reason,
                    'attempts' => $refusal === null ? $attempts : 0,
                    'dropped_at' => BillingTime::nowUtc()->toIso8601String(),
                    ...($paymentOnItsWay ? ['payment_id' => $ownPayment] : []),
                ];

                return $meta;
            });
        }

        $this->notifyCustomerOfDroppedChange($billable, $scheduledChange, $reason, $paymentOnItsWay);

        if ($refusal === null) {
            $this->notifyAdminsOfApplyFailure($billable, $scheduledChange, $e, $attempts);
        }
    }

    /**
     * Why the rules refuse this change — the key of the sentence the customer is sent —
     * or null when the failure is not a refusal and may not recur.
     *
     * A whitelist of *domain* refusals, on purpose: a stored change is real customer
     * intent, and treating an unknown exception as "refused" would drop it for a fault
     * that is gone tomorrow. `InvalidSubscriptionStateException` is deliberately absent —
     * its most common cause here is another change still waiting for its payment, which
     * resolves itself.
     */
    private function refusalReason(\Throwable $e): ?string
    {
        return match (true) {
            $e instanceof SeatDowngradeRequiredException => 'seats_in_use',
            $e instanceof \GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException => 'payment_outstanding',
            $e instanceof LocalSubscriptionUpgradeRequiresMolliePathException,
            $e instanceof HiddenPlanNotSelectableException,
            $e instanceof IntervalNotOfferedException,
            $e instanceof \GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException,
            $e instanceof \GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException,
            $e instanceof \GraystackIT\MollieBilling\Exceptions\LocalSubscriptionDoesNotSupportPaidExtrasException,
            $e instanceof \GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException => 'not_available',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $scheduledChange
     */
    private function notifyCustomerOfDroppedChange(Billable $billable, array $scheduledChange, string $reason, bool $paymentOnItsWay = false): void
    {
        try {
            $notification = MollieBilling::resolveNotification(
                \GraystackIT\MollieBilling\Notifications\ScheduledChangeNotAppliedNotification::class,
                $billable,
                (string) ($scheduledChange['plan_code'] ?? $billable->getBillingSubscriptionPlanCode() ?? ''),
                $reason,
                $paymentOnItsWay,
            );

            $recipients = MollieBilling::notifyBillingAdmins($billable);
            $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

            if ($recipients !== []) {
                Notification::send($recipients, $notification);

                return;
            }

            $email = $billable->getBillingEmail();

            if (is_string($email) && $email !== '') {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable) {
            // Announcing must never be what fails the run.
        }
    }

    /**
     * Whether a due change has to wait for the renewal of the period it was scheduled for.
     *
     * The dispatcher runs once `scheduled_change_at <= now`, which for a SEPA customer is
     * days before the renewal settles: the period has lapsed, so the change is applied with
     * a prorata factor of 0 — nothing charged, nothing credited — and the renewal Mollie
     * created *before* it heard about the change then settles at the OLD amount for a
     * period spent on the NEW configuration. For a change Mollie was told about when it
     * was scheduled (`mollie_prepatched`) that is fine: the renewal already carries the
     * new amount. For every other change it is not:
     *
     * - an upgrade whose announcement failed was simply free for a period;
     * - an interval change charged the new interval in full while the old interval's
     *   renewal was still on its way, and had to be untangled afterwards;
     * - a downgrade relied on the refund net to get its difference back.
     *
     * So such a change waits until the renewal is in — `applyAtRenewal()` applies a
     * free-plan target with it, and everything else is applied on the next dispatcher run
     * as an ordinary change in a period that has been paid for, with an ordinary prorata.
     *
     * Bounded: if no renewal arrives within WAIT_FOR_RENEWAL_DAYS the change is applied
     * anyway, as before. A PastDue subscription does not wait at all — its renewal failed,
     * and the past-due reset is the path for it. "Apply now" mid-period is not affected:
     * the boundary has not been reached.
     *
     * @param  array<string, mixed>  $change
     */
    private function waitsForItsRenewal(Billable $billable, array $change): bool
    {
        if (($change['mollie_prepatched'] ?? false) === true || ! $this->billsThroughMollie($billable)) {
            return false;
        }

        if (! in_array($billable->getBillingSubscriptionStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)) {
            return false;
        }

        $boundary = $this->periodEndFor($billable);
        $now = BillingTime::nowUtc();

        // The boundary lies ahead (applied early, on request) — or the renewal has been
        // booked and the anchor moved on: either way there is nothing to wait for.
        if ($boundary->greaterThan($now)) {
            return false;
        }

        return $now->lessThan($boundary->copy()->addDays(self::WAIT_FOR_RENEWAL_DAYS));
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
    private function notifyAdminsOfApplyFailure(Billable $billable, array $scheduledChange, \Throwable $e, int $attempts = 1): void
    {
        try {
            $admins = MollieBilling::notifyAdmin();
            $admins = is_array($admins) ? $admins : iterator_to_array($admins);

            if ($admins === []) {
                return;
            }

            Notification::send($admins, MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'A scheduled subscription change could not be applied after '.$attempts.' attempts and was dropped — the customer has been informed',
                [
                    'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'plan_code' => $scheduledChange['plan_code'] ?? null,
                    'interval' => $scheduledChange['interval'] ?? null,
                    'scheduled_at' => $scheduledChange['scheduled_at'] ?? null,
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                ],
            ));
        } catch (\Throwable) {
            // Announcing a failure must never be what fails the run.
        }
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

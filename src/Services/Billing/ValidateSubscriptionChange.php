<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionDoesNotSupportPaidExtrasException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralized validation for subscription changes.
 *
 * This service runs all pre-flight checks before a plan change is applied
 * or before a deferred upgrade payment is created. It is called:
 *
 * - In Phase 1 (UpdateSubscription::update()) — to validate for the user
 *   before any payment is created.
 * - In Phase 2 (applyPendingPlanChange()) — to re-validate before applying
 *   a deferred change, in case state changed between payment and webhook.
 *
 * Override: Apps can bind their own implementation to customize validation
 * rules (e.g. grace periods for seats, custom addon rules):
 *
 *   $this->app->bind(ValidateSubscriptionChange::class, MyCustomValidator::class);
 */
class ValidateSubscriptionChange
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {}

    /**
     * Run all validation checks for the given subscription change.
     *
     * Mutates $context->newSeats (auto-derive) and $context->newAddons
     * (strip incompatible) as needed.
     *
     * @throws SeatDowngradeRequiredException
     * @throws DowngradeRequiresMandateException
     * @throws InvalidSubscriptionStateException
     * @throws HiddenPlanNotSelectableException
     */
    public function validate(Billable $billable, SubscriptionChangeContext $context): void
    {
        $this->validateSubscriptionEstablished($billable);
        $this->validateTargetPlanNotHidden($billable, $context);
        $this->validateTargetIsSold($billable, $context);

        if ($context->intervalChanged) {
            $this->assertIntervalChangeAllowed($billable);
        }
        $this->validateSeats($billable, $context);
        $this->validateAddons($billable, $context);
        $this->validateLocalSubscriptionExtras($billable, $context);

        // Not on the Phase-2 re-validation: that change was accepted — and paid for —
        // before; a plan change is what ends the past-due state in the first place.
        if (! $context->isPendingReapply) {
            $this->assertSubscriptionStillRuns(
                $billable,
                $context->planChanged || $context->intervalChanged,
                $context->seatsExplicit,
                $context->currentSeats,
                $context->newSeats,
                array_values(array_diff($context->newAddons, $context->currentAddons)),
                $context->newInterval,
            );

            $this->assertNoPastDueExpansion(
                $billable,
                $context->planChanged || $context->intervalChanged,
                $context->seatsExplicit,
                $context->currentSeats,
                $context->newSeats,
                array_values(array_diff($context->newAddons, $context->currentAddons)),
                $context->newInterval,
            );
        }
        $this->validateWalletUsage($billable, $context);
        $this->validateMollieReadiness($billable, $context);
    }

    /**
     * Ensure the billable has an established subscription to change at all.
     *
     * A plan change presupposes an existing subscription. A billable with
     * `subscription_source = None` never completed checkout — e.g. its first
     * payment was cancelled or abandoned, leaving it at status `New` with no
     * Mollie mandate. Such a billable has no plan to "change" and no payment
     * instrument to bill: without this guard, the portal's plan-change screen
     * would silently rewrite `subscription_plan_code` with no money flow,
     * neither validateLocalSubscriptionExtras (only fires for Local) nor
     * validateMollieReadiness (only fires for Mollie + prorata) catching it.
     *
     * The correct path for these billables is the checkout flow, which obtains
     * a mandate and creates the subscription. Local and Mollie subscriptions
     * (both established) are unaffected.
     *
     * @throws InvalidSubscriptionStateException When no subscription has been established
     */
    protected function validateSubscriptionEstablished(Billable $billable): void
    {
        // null (never persisted) and None (DB default) both mean "no subscription
        // established yet" — only Local and Mollie are real sources to change.
        $source = $billable->getBillingSubscriptionSource();

        if ($source === null || $source === SubscriptionSource::None->value) {
            throw (new InvalidSubscriptionStateException(
                'Cannot change plan — no active subscription. Complete checkout first.'
            ))->withTranslation('billing::exceptions.state.no_active_subscription');
        }
    }

    /**
     * Reject a hidden plan as the target of a change.
     *
     * Only fires when the plan actually changes, which is exactly the boundary
     * the ticket asks for: a billable already sitting on a hidden plan may keep
     * it and may switch interval within it — `planChanged` is false in both
     * cases. Only moving *onto* a hidden plan from outside is refused.
     *
     * @throws HiddenPlanNotSelectableException
     */
    protected function validateTargetPlanNotHidden(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $context->planChanged || $context->allowHiddenPlan) {
            return;
        }

        $this->assertPlanSelectable($billable, $context->newPlan);
    }

    /**
     * Assert that a plan code may be selected as a change target.
     *
     * Kept public and context-free so callers that do not build a
     * SubscriptionChangeContext can reuse the exact same rule — notably
     * UpdateSubscription, which hands scheduled changes off to
     * ScheduleSubscriptionChange *before* validate() ever runs.
     *
     * @throws HiddenPlanNotSelectableException
     */
    public function assertPlanSelectable(Billable $billable, ?string $targetPlanCode): void
    {
        if ($targetPlanCode === null || $targetPlanCode === '') {
            return;
        }

        // Keeping the current plan is always allowed, hidden or not.
        if ($targetPlanCode === $billable->getBillingSubscriptionPlanCode()) {
            return;
        }

        if ($this->catalog->planIsHidden($targetPlanCode)) {
            throw new HiddenPlanNotSelectableException($billable, $targetPlanCode);
        }
    }

    /**
     * Refuse adding seats or a paid addon while the subscription is past due.
     *
     * A past-due period has not been paid, and its end has passed: a seat or addon added
     * there prorates with a factor of 0. It was accepted, free for the unpaid period, and
     * Mollie was PATCHed to the higher amount — capacity handed to a customer whose last
     * charge had just failed. So the expansion is refused until the open payment is
     * settled. What stays possible:
     *
     * - fewer seats, an addon switched off — the customer may always shrink;
     * - an addon that bills nothing ({@see SubscriptionCatalogInterface::addonIsFree()});
     * - a plan or interval change: that is the past-due reset, which opens a new period
     *   and charges it in full, whatever seats and addons it carries.
     *
     * Only a seat count the caller asked for counts. A derived one can exceed the stored
     * count because more members are assigned than seats exist, and refusing to switch an
     * addon OFF for that reason would be absurd.
     *
     * Public so `ScheduleSubscriptionChange::schedule()` and the preview apply the same
     * rule — a change stored for the period end is the same expansion, and a period end
     * that has already passed is "now".
     *
     * @param  array<int, string>  $addedAddons
     *
     * @throws PastDueExpansionNotAllowedException
     */
    /**
     * Nothing that is charged, and no plan or interval move, on a subscription that has
     * been cancelled or has expired — see {@see SubscriptionEndedChangeNotAllowedException}.
     *
     * Same shape and same callers as {@see assertNoPastDueExpansion()}: the immediate
     * path, the scheduled path and the preview have to give one answer.
     *
     * @param  array<int, string>  $addedAddons
     *
     * @throws \GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException
     */
    public function assertSubscriptionStillRuns(
        Billable $billable,
        bool $planOrIntervalChanged,
        bool $seatsExplicit,
        int $currentSeats,
        int $newSeats,
        array $addedAddons,
        string $interval,
    ): void {
        if (! in_array($billable->getBillingSubscriptionStatus(), [
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled,
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Expired,
        ], true)) {
            return;
        }

        $extraSeats = $seatsExplicit ? max(0, $newSeats - $currentSeats) : 0;

        $paidAddons = array_filter(
            array_map('strval', $addedAddons),
            fn (string $code): bool => ! $this->catalog->addonIsFree($code, $interval),
        );

        if (! $planOrIntervalChanged && ($extraSeats > 0 || $paidAddons !== [])
            && \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::appliesTo($billable)) {
            // Cancelled at the period end and still running: say until when, and that
            // withdrawing the cancellation is the way to book (decision 5, 26 Nov 2026).
            throw $paidAddons !== []
                ? \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::addons($billable)
                : \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::seats($billable);
        }

        if ($planOrIntervalChanged || $extraSeats > 0 || $paidAddons !== []) {
            throw new \GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException($billable);
        }
    }

    public function assertNoPastDueExpansion(
        Billable $billable,
        bool $planOrIntervalChanged,
        bool $seatsExplicit,
        int $currentSeats,
        int $newSeats,
        array $addedAddons,
        string $interval,
    ): void {
        if (! $billable->isBillingPastDue()) {
            return;
        }

        if ($planOrIntervalChanged) {
            // The past-due reset is the way out of a *failed renewal*: nothing of the
            // period was paid, so the new plan opens a fresh one and charges it in full.
            // A subscription that is past due only because an overage debt stands has
            // paid its period — the reset would charge a second full period for it, and
            // the rule is that a customer who owes books nothing new.
            if (\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable)) {
                throw PastDueExpansionNotAllowedException::planChange($billable);
            }

            return;
        }

        $extraSeats = $seatsExplicit ? max(0, $newSeats - $currentSeats) : 0;

        $paidAddons = array_values(array_filter(
            array_map('strval', $addedAddons),
            fn (string $code): bool => ! $this->catalog->addonIsFree($code, $interval),
        ));

        if ($extraSeats > 0 || $paidAddons !== []) {
            throw new PastDueExpansionNotAllowedException($billable, $extraSeats, $paidAddons);
        }
    }

    /**
     * Refuse an interval change while a plan waiver runs.
     *
     * A waiver is denominated in the interval it was given in: its amount is that
     * interval's plan price, and its end is a date or a number of periods of that length.
     * None of it translates. Moving a plan that is free until 17 March from monthly to
     * yearly charged a full year minus one month's price (`ceiling()` caps the waiver at
     * the amount it was granted for), for a plan the tenant holds as a gift; the other way
     * round a yearly gift became a discount on twelve separate months, or — counted in
     * periods — twelve free months turned into twelve free years.
     *
     * There is no reading of "the same gift, on the other interval" that does not either
     * charge for what was given or give more than was given, so the change is refused
     * with a reason, immediately and as a scheduled change. Plan, seat and addon changes
     * are unaffected, and the interval can be changed once the waiver has ended.
     *
     * Public so `ScheduleSubscriptionChange::schedule()` applies the same rule before it
     * stores anything.
     *
     * @throws InvalidSubscriptionStateException
     */
    public function assertIntervalChangeAllowed(Billable $billable): void
    {
        $marker = app(PlanWaiver::class)->marker($billable);

        if ($marker === null) {
            return;
        }

        $until = ! empty($marker['valid_until']) ? substr((string) $marker['valid_until'], 0, 10) : null;

        throw (new InvalidSubscriptionStateException(
            'The billing interval cannot be changed while the plan is free of charge'
            .($until !== null ? ' (until '.$until.')' : '')
            .'. Change it once the free period has ended.'
        ))->withTranslation(
            $until !== null
                ? 'billing::exceptions.state.interval_locked_while_free_until'
                : 'billing::exceptions.state.interval_locked_while_free',
            $until !== null ? ['date' => $until] : [],
        );
    }

    /**
     * Refuse a target the catalog does not actually sell.
     *
     * `basePriceNet()` and `seatPriceNet()` cannot express "not offered" — they answer
     * `0`/`null` for an unknown (plan, interval) pair, and every pricing path downstream
     * reads that as *free*. `isFreePlan()` then becomes true, which takes the change into
     * the free-downgrade branch: the Mollie subscription is cancelled and a real refund is
     * issued, before the enum cast on `subscription_interval` rejects the value and rolls
     * the local transaction back. The billable is left looking Active and subscribed while
     * nothing bills it at Mollie any more.
     *
     * `StartSubscriptionCheckout` has guarded this since the interval toggle turned out to
     * be enough to obtain a paid plan for nothing; the change path is the same kind of
     * surface, since `SubscriptionUpdateRequest::from()` is documented as taking request
     * input. Only checked when plan or interval actually move: an unchanged pair is already
     * the billable's reality, and refusing to touch seats or addons because the catalog
     * dropped an interval underneath an existing customer helps nobody.
     *
     * @throws IntervalNotOfferedException
     */
    protected function validateTargetIsSold(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $context->planChanged && ! $context->intervalChanged) {
            return;
        }

        if (! $this->catalog->planOffersInterval($context->newPlan, $context->newInterval)) {
            throw new IntervalNotOfferedException($context->newPlan, $context->newInterval);
        }
    }

    /**
     * Validate seat count for the target plan.
     *
     * Two block conditions when the new plan does not support extra seats
     * (`seat_price_net === null`) and the caller did not pass `seats` explicitly:
     *
     * - `usedSeats > newIncludedSeats` — real team members exceed the quota.
     *   The user must remove members before downgrading.
     * - `currentSeatCount > newIncludedSeats` — paid (but unused) extra seats
     *   exist that the new plan cannot host. The user must explicitly drop
     *   them via the UI's "drop extra seats" toggle (which sets
     *   `seats` and bypasses this check).
     *
     * If extra seats are supported on the new plan (`seat_price_net !== null`),
     * the count is auto-derived to preserve seats across the change.
     *
     * Override: Bind your own ValidateSubscriptionChange to change this behavior,
     * e.g. to always block downgrades or to allow grace periods.
     *
     * @throws SeatDowngradeRequiredException When the new plan cannot accommodate the existing seat count
     */
    protected function validateSeats(Billable $billable, SubscriptionChangeContext $context): void
    {
        $newIncludedSeats = $this->catalog->includedSeats($context->newPlan);
        $seatPriceNet = $this->catalog->seatPriceNet($context->newPlan, $context->newInterval);

        // When seats were explicitly provided by the caller, respect the value —
        // except when the new plan does not sell extra seats. Without
        // `seat_price_net` we would charge nothing for the extra capacity, so
        // any explicit increase above the included quota is rejected.
        //
        // Local subscriptions are skipped here so that
        // validateLocalSubscriptionExtras() can throw its dedicated
        // LocalSubscriptionDoesNotSupportPaidExtrasException with the richer
        // upgrade-path messaging.
        if ($context->seatsExplicit) {
            // The ceiling SyncSeats applies, applied where every caller passes through:
            // `MollieBilling::subscriptions()->update(['seats' => …])` skipped it.
            $maximum = max(1, (int) config('mollie-billing.max_seats', 1000));

            if ($context->newSeats > $maximum && $context->newSeats !== $context->currentSeats) {
                throw new SeatCountExceedsMaximumException($billable, $context->newSeats, $maximum);
            }

            if (
                $seatPriceNet === null
                && $context->newSeats > $newIncludedSeats
                && ! $billable->isLocalBillingSubscription()
            ) {
                throw new SeatDowngradeRequiredException($billable, $context->newSeats, $newIncludedSeats);
            }

            // The floor the auto-derive branch below has always enforced, applied here too.
            //
            // Only this branch is reachable from the portal's seats screen, and it had no
            // such check: an org with ten members assigned could set the counter to one and
            // the change went through — a prorata refund for nine seats paid out while every
            // one of those members kept working, because the package never revokes seats, it
            // only counts what the host app reports through `getUsedBillingSeats()`. The
            // customer has to release the members first; that is exactly what this exception
            // says, and the same rule the non-explicit branch applies.
            $usedSeats = $billable->getUsedBillingSeats();

            if ($context->newSeats < $usedSeats) {
                throw new SeatDowngradeRequiredException($billable, $usedSeats, $context->newSeats);
            }

            return;
        }

        $usedSeats = $billable->getUsedBillingSeats();
        $currentSeatCount = $billable->getBillingSeatCount();

        if ($seatPriceNet === null) {
            // More members than the plan can seat: nothing to derive, they have to go
            // first.
            if ($usedSeats > $newIncludedSeats) {
                throw new SeatDowngradeRequiredException($billable, $usedSeats, $newIncludedSeats);
            }

            // Extra seats on a plan that sells none can only be an inconsistency when the
            // plan stays (an interval the plan sells no seats in). On a plan CHANGE the
            // count falls to what the new plan includes — see below — so there is nothing
            // to refuse.
            if (! $context->planChanged && $currentSeatCount > $newIncludedSeats) {
                throw new SeatDowngradeRequiredException($billable, $currentSeatCount, $newIncludedSeats);
            }
        }

        // Derived, not carried over: a plan change lands on the seats in use or included
        // by the new plan; anything else keeps the count. A caller who wants to keep more
        // across a plan change passes `seats` and takes the explicit branch above.
        // See BillingPolicy::derivedSeats().
        $context->newSeats = BillingPolicy::derivedSeats(
            $currentSeatCount,
            $usedSeats,
            $newIncludedSeats,
            $context->planChanged,
        );
    }

    /**
     * Block paid add-ons / paid extra seats and pseudo-upgrades on local subscriptions.
     *
     * A free / Local subscription has no payment flow attached. Paid extras must be
     * acquired via the UpgradeLocalToMollie path (which obtains a mandate and creates
     * a real Mollie subscription). Two cases:
     *
     * 1. **Pseudo-upgrade** — the caller switches the plan from free to paid.
     *    Without a Mollie mandate this would silently set the new plan code with no
     *    money flow. Block with `LocalSubscriptionUpgradeRequiresMolliePathException`.
     * 2. **Paid extras on free plan** — adding paid add-ons or buying extra seats.
     *    Block with `LocalSubscriptionDoesNotSupportPaidExtrasException`.
     *
     * Free add-ons (price 0) and the included-seat count remain available.
     */
    protected function validateLocalSubscriptionExtras(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $billable->isLocalBillingSubscription()) {
            return;
        }

        if ($context->planChanged && ! $this->catalog->isFreePlan($context->newPlan, $context->newInterval)) {
            throw new LocalSubscriptionUpgradeRequiresMolliePathException($billable, $context->newPlan);
        }

        $addonsAdded = array_values(array_diff($context->newAddons, $context->currentAddons));
        $paidAddons = array_values(array_filter(
            $addonsAdded,
            // Base AND per-seat price: an addon with base 0 and a seat price bills for
            // every seat, and nothing can collect that without a mandate.
            fn (string $code) => ! $this->catalog->addonIsFree($code, $context->newInterval),
        ));

        // Seats above the included quota are blocked on Local subscriptions even when
        // seat_price_net is null/0: without a mandate we cannot bill for the extra capacity,
        // and silently allowing it would let a free user expand seats indefinitely.
        $includedSeats = $this->catalog->includedSeats($context->newPlan);
        $extraSeats = max(0, $context->newSeats - $includedSeats);

        if ($paidAddons !== [] || $extraSeats > 0) {
            throw new LocalSubscriptionDoesNotSupportPaidExtrasException(
                $billable,
                $paidAddons,
                $extraSeats,
            );
        }
    }

    /**
     * Strip addons that are incompatible with the new plan.
     *
     * When the plan changes, any addon that the new plan does not allow
     * (as defined by the catalog's planAllowsAddon()) is automatically removed.
     * This prevents billing for addons that make no sense on the target plan.
     *
     * The removed addons are reflected in the change diff and trigger
     * AddonDisabled events. No exception is thrown — removal is automatic.
     *
     * Override: Bind your own ValidateSubscriptionChange to keep all addons
     * or to apply custom compatibility rules.
     */
    protected function validateAddons(Billable $billable, SubscriptionChangeContext $context): void
    {
        if ($context->planChanged) {
            $context->newAddons = array_values(array_filter(
                $context->newAddons,
                fn (string $code) => $this->catalog->planAllowsAddon($context->newPlan, $code),
            ));
        }

        // Same zero-price trap as the plan itself (see validateTargetIsSold): an addon
        // the target interval does not sell prices at 0, so switching to yearly used to
        // hand out a monthly-only addon for free, forever. Filtering it out silently
        // would be the other kind of wrong — the customer asked for it and is willing to
        // pay — so this refuses the change instead.
        //
        // When plan and interval stay put, only the *newly added* codes are judged — the
        // ones the billable already holds were sold under the rules of their time. They
        // used to be skipped too, which made an addon-only change the one path with no
        // eligibility check at all: `enableBillingAddon()` wrote a higher tier's addon
        // (or an unknown code) into active_addon_codes and FeatureAccess granted it.
        if (! $context->planChanged && ! $context->intervalChanged) {
            foreach (array_diff($context->newAddons, $context->currentAddons) as $code) {
                if (! $this->catalog->planAllowsAddon($context->newPlan, $code)) {
                    throw new AddonNotAllowedException($context->newPlan, $code);
                }

                if (! $this->catalog->addonOffersInterval($code, $context->newInterval)) {
                    throw new IntervalNotOfferedException($code, $context->newInterval, 'addon');
                }
            }

            return;
        }

        foreach ($context->newAddons as $code) {
            if (! $this->catalog->addonOffersInterval($code, $context->newInterval)) {
                throw new IntervalNotOfferedException($code, $context->newInterval, 'addon');
            }
        }
    }

    /**
     * Check that wallet usage levels allow the plan change.
     *
     * Uses prorated excess logic: computes how much of the old plan's quota
     * was consumed beyond the prorated entitlement, then checks whether
     * the new plan's quota (+ rollover credits) can absorb the excess.
     *
     * If unresolvable overage remains and the billable has no Mollie mandate,
     * throws DowngradeRequiresMandateException. The actual charging happens
     * later in adjustWalletsForPlanChange().
     *
     * @throws DowngradeRequiresMandateException When unresolvable overage exists but no mandate is available
     */
    protected function validateWalletUsage(Billable $billable, SubscriptionChangeContext $context): void
    {
        $this->assertUnresolvedOverageIsChargeable(
            $billable,
            $context->currentPlan,
            $context->currentInterval,
            $context->newPlan,
            $context->newInterval,
        );
    }

    /**
     * The check behind {@see validateWalletUsage()}, on plain arguments so the plan-change
     * preview can ask it too: without a mandate the change is refused, and the screen used
     * to quote the excess as "charged with the change" right up to a generic error toast.
     *
     * @throws DowngradeRequiresMandateException
     */
    public function assertUnresolvedOverageIsChargeable(
        Billable $billable,
        string $currentPlan,
        string $currentInterval,
        string $newPlan,
        string $newInterval,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $rollover = $this->catalog->usageRollover($slug);
            $oldIncluded = $this->catalog->includedUsage($currentPlan, $currentInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);
            $balance = (int) $wallet->balanceInt;

            // Separate purchased credits from plan credits.
            $purchasedBalance = WalletUsageService::getPurchasedBalance($wallet);
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
            $planOnlyBalance = $balance - $purchasedRemaining;

            $excess = 0;
            if ($periodStart !== null && $periodEnd !== null && $oldIncluded > 0) {
                $result = BillingPolicy::computeUsageOverageForPlanChange(
                    $oldIncluded,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $result['excess'];
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $oldIncluded) : 0;
            $targetBalance = $newIncluded + $rolloverCredits + $purchasedRemaining - $excess;

            if ($targetBalance >= 0) {
                continue;
            }

            // Unresolvable overage: mandate is required to charge it.
            if (! $billable->hasMollieMandate()) {
                throw new DowngradeRequiresMandateException($billable, $newPlan);
            }
        }
    }

    /**
     * Ensure the billable is ready for a Mollie-based plan change.
     *
     * For deferred upgrades (prorata charge > 0 on a Mollie subscription),
     * the following must be true before creating a payment:
     *
     * 1. The billable has a valid Mollie mandate (hasMollieMandate() === true).
     *    Without a mandate, recurring payments cannot be created.
     *
     * 2. The subscription_meta contains a mollie_subscription_id.
     *    Without this, cancelAndRecreateMollieSubscription() cannot cancel
     *    the old subscription when the change is applied.
     *
     * 3. No pending_plan_change already exists in subscription_meta.
     *    Only one deferred upgrade can be in flight at a time.
     *
     * These checks only apply to Mollie subscriptions with a prorata charge.
     * Local subscriptions and downgrades skip this validation entirely.
     *
     * @throws InvalidSubscriptionStateException When requirements are not met
     */
    protected function validateMollieReadiness(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $context->isMollie) {
            return;
        }

        if (! ($billable instanceof Model)) {
            return;
        }

        // The one-pending-change rule holds for a credit-only change too, and that is
        // where it used to fall through: the whole method returned early at
        // `prorataChargeNet <= 0`, so a downgrade (charge 0, credit > 0) issued while
        // another change's charge was still in flight passed unchallenged. The executor
        // then reported `in_flight` without composing the refund, and `update()` — which
        // reads "is a charge pending?" straight off the meta — attached the downgrade to
        // the OTHER change's payment id. When that payment settled, Phase 2 applied the
        // other change's intent, cleared the marker, and the downgrade evaporated: no
        // plan switch, no credit refund, no failure event, and any coupon stacked on it
        // redeemed against the wrong invoice.
        //
        // Exempt only the Phase 2 re-validation, which is told so explicitly. It used to be
        // recognised by both prorata amounts being 0 — but so is a switch between two plans
        // priced the same, and that switch then walked past this guard into precisely the
        // failure described above.
        $meta = $billable->getBillingSubscriptionMeta();

        if (! $context->isPendingReapply && (! empty($meta['pending_plan_change']) || ! empty($meta['pending_prorata_change']))) {
            throw (new InvalidSubscriptionStateException(
                'A plan change is already pending payment confirmation. Cancel it first or wait for the payment to resolve.'
            ))->withTranslation('billing::exceptions.state.plan_change_pending_payment');
        }

        // Everything below is about being able to CREATE a charge, so it stays scoped
        // to changes that actually cost money.
        if ($context->prorataChargeNet <= 0) {
            return;
        }

        if (! $billable->hasMollieMandate()) {
            throw (new InvalidSubscriptionStateException(
                'Cannot create a deferred upgrade payment — no Mollie mandate found.'
            ))->withTranslation('billing::exceptions.state.upgrade_no_mandate');
        }

        if (empty($meta['mollie_subscription_id'])) {
            throw (new InvalidSubscriptionStateException(
                'Cannot create a deferred upgrade — no mollie_subscription_id in subscription meta.'
            ))->withTranslation('billing::exceptions.state.upgrade_no_mollie_subscription');
        }
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * The `subscription_meta` keys a transition has to drop, in one place.
 *
 * Every activation and every downgrade had its own hand-maintained unset list, and
 * they had drifted: `ActivateLocalSubscription` cleared ten keys with a comment
 * explaining each, `UpdateSubscription`'s free downgrade cleared three, and the two
 * webhook activation paths cleared none. What survives such a gap is not inert —
 * it is a clock, a schedule or a discount that the next pass acts on:
 *
 * - `past_due_since` is the auto-cancel clock. Kept across a cancellation, one
 *   failed renewal months later was measured against the OLD date and the nightly
 *   pass cancelled the subscription the same night, with a zero-day dunning window
 *   and no Mollie retry.
 * - `scheduled_change` / `scheduled_change_at` are read by the period-end
 *   dispatcher, which keys off the column alone. Surviving a downgrade to free,
 *   the daily job re-applied a change the validator refuses and failed forever.
 * - `next_charge_date_override` outranks the period anchor in `nextBillingDate()`,
 *   so it defers the free plan's quota recharge.
 * - `active_recurring_coupon` is read back by `CreateSubscription` on a later
 *   upgrade, applying a discount campaign nobody granted.
 *
 * Identity keys (`mollie_subscription_id`, checkout handles) are deliberately NOT
 * in these lists: dropping a subscription id orphans a subscription at Mollie that
 * nothing can cancel afterwards. `pending_subscription_cancel` is
 * excluded for the same reason — it is the record that a live subscription still needs
 * cancelling, and the retry job is its only reader.
 */
final class SubscriptionMetaHygiene
{
    /**
     * Keys that describe a dunning episode. A fresh subscription starts a fresh
     * dunning clock — the invariant that replaces "somebody remembers to clean up".
     */
    public const DUNNING_KEYS = [
        'payment_failure',
        'past_due_since',
    ];

    /**
     * Keys that describe work scheduled against the subscription being replaced.
     */
    public const SCHEDULING_KEYS = [
        'scheduled_change',
        'pending_plan_change',
        'pending_prorata_change',
        'prorata_pending_payment_id',
        'pending_subscription_patch',
        'pending_amount_net',
        'pending_amount_recorded_at',
        'next_charge_date_override',
    ];

    /**
     * Keys that describe pricing granted to the subscription being replaced.
     */
    public const PRICING_KEYS = [
        'active_recurring_coupon',
    ];

    /**
     * Everything a transition into a *fresh* subscription must drop.
     *
     * `cancelled_from` goes too: it records which plan a cancellation came from, and
     * a new subscription is not a continuation of that one.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function forFreshSubscription(array $meta): array
    {
        foreach ([...self::DUNNING_KEYS, ...self::SCHEDULING_KEYS, ...self::PRICING_KEYS, 'cancelled_from'] as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }

    /**
     * Drop only the dunning episode, leaving scheduling and pricing intact.
     *
     * Two callers need exactly this and not more. A payment that clears while the
     * billable is PastDue ends the episode but continues the same subscription. And
     * `CreateSubscription` must not touch pricing or scheduling: the 100 %-coverage
     * coupon path deliberately writes `active_recurring_coupon` *before* calling it, so
     * that `fullCoverageStartDate()` can read the marker and defer Mollie's first
     * charge past the free periods.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function withoutDunningEpisode(array $meta): array
    {
        foreach (self::DUNNING_KEYS as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }
}

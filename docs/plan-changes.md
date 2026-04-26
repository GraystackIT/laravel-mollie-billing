# Plan Changes

This document describes how plan changes work in `laravel-mollie-billing`, including the deferred upgrade flow, validation rules, events, and extension points.

## Overview

Plan changes are handled by `UpdateSubscription::update()` and come in three flavors:

| Type | Trigger | Timing |
|------|---------|--------|
| **Upgrade** (higher price, Mollie) | User selects a more expensive plan on a Mollie subscription | Deferred until prorata payment confirmed |
| **Local → Mollie upgrade** | Free-plan user selects a paid plan | Routed to `UpgradeLocalToMollie`, applied via webhook |
| **Downgrade** (lower price) | User selects a cheaper plan | Immediate or scheduled to end of period |
| **Mollie → Free downgrade** | Paid user selects a free plan | Cancels Mollie subscription, switches source to Local |
| **Zero-cost change** | Same price, different plan/interval | Immediate |
| **Free → Free change** | Both source and target are free plans | Immediate, source stays Local |

## Deferred Upgrade Flow

Upgrades on Mollie subscriptions with a prorata charge > 0 are split into two phases to ensure the plan is only changed after payment confirmation.

```
Phase 1 — User initiates upgrade (synchronous)
  1. Build SubscriptionChangeContext (compute seats, addons, prorata, etc.)
  2. ValidateSubscriptionChange::validate()
     - Seats: auto-derive or throw SeatDowngradeRequiredException
     - Addons: strip incompatible
     - Wallet usage: charge overage if needed
     - Mollie readiness: mandate, subscription ID, no pending change
  3. Store pending_plan_change in subscription_meta
  4. Create Mollie recurring payment (prorata charge)
  5. Dispatch PlanChangePending event
  6. Return { pendingPaymentConfirmation: true }
  -> Plan is NOT changed, subscription is NOT updated

Phase 2a — Webhook: payment succeeded
  1. MollieWebhookController::handleSingleChargePaid() creates BillingInvoice
  2. UpdateSubscription::applyPendingPlanChange() is called:
     a. Re-validate via ValidateSubscriptionChange (state may have changed)
     b. Cancel old + create new Mollie subscription
     c. Clear pending state via clearPendingPlanChange()
     d. Update plan, interval, addons, seats in database
     e. Adjust wallets
     f. Redeem coupon (if stored)
     g. Dispatch PlanChanged, SeatsChanged, AddonEnabled/Disabled events

Phase 2b — Webhook: payment failed
  1. UpdateSubscription::clearPendingPlanChange() removes pending state
  2. plan_change_failed_at and plan_change_failed_reason stored in meta
  3. PlanChangeFailed event dispatched
  4. PlanChangeFailedNotification sent to billing admins
  -> Plan remains unchanged
```

## Validation Rules

All validation is centralized in `ValidateSubscriptionChange`. It runs in both Phase 1 (pre-flight) and Phase 2 (before applying). Apps can override it via `app->bind`.

### Seats

- If `usedSeats > newIncludedSeats` and the new plan supports extra seats (`seat_price_net !== null`): seats are automatically increased to `max(usedSeats, newIncludedSeats)`.
- If the new plan does NOT support extra seats: `SeatDowngradeRequiredException` is thrown.
- When seats are explicitly provided by the caller, auto-derivation is skipped.

### Addons

- On plan change, addons not allowed by the new plan (`catalog->planAllowsAddon()`) are automatically stripped.
- Stripped addons appear in the `addonsRemoved` diff and trigger `AddonDisabled` events.

### Wallet Usage

- For each wallet: if used quota exceeds the new plan's included quota, overage is charged via `ChargeUsageOverageDirectly`.
- Requires a Mollie mandate; throws `DowngradeRequiresMandateException` if missing.

### Mollie Readiness (upgrades only)

- `hasMollieMandate()` must be `true`
- `mollie_subscription_id` must exist in `subscription_meta`
- No `pending_plan_change` may already exist (only one deferred upgrade at a time)

### Local Subscription Guards

When the current source is `local`, `validateLocalSubscriptionExtras()` enforces:

- **Pseudo-upgrade block:** switching to a paid plan via `UpdateSubscription` throws `LocalSubscriptionUpgradeRequiresMolliePathException`. The correct path is `UpgradeLocalToMollie` (the bundled plan-change UI does this automatically).
- **Paid-extras block:** adding paid add-ons (`addonPriceNet > 0`) or paid extra seats (`seat_price_net > 0`) throws `LocalSubscriptionDoesNotSupportPaidExtrasException`. Free add-ons (price 0) and the included seat count remain available.

These guards make the legacy bug where a free plan could be silently flipped to a paid one (without any payment) impossible to reproduce — including from admin tooling.

## Prorata Calculation

All prorata calculations are centralized in `BillingPolicy::computeProrata()`. Both `UpdateSubscription` and `PreviewService` delegate to this single method.

### Day Granularity

Billing uses **whole calendar days** (`startOfDay()`). A change made on the same day as the period start yields a factor of exactly `1.0` — the current day counts as a full remaining day.

### Same-Interval Changes

When the billing interval stays the same (e.g. monthly → monthly):

- **Upgrade** (`newNet > currentNet`): `charge = (newNet - currentNet) * factor`
- **Downgrade** (`newNet < currentNet`): `credit = (currentNet - newNet) * factor`

### Interval Changes

When the interval changes (e.g. monthly → yearly or yearly → monthly), the old and new amounts cover different period lengths and cannot be compared directly. The unused portion of the current period is credited in full. The new plan's first payment is collected by Mollie via the new subscription.

- `credit = currentNet * factor`
- No prorata charge — Mollie collects the full new amount via the recreated subscription.

## Invoice Types

Each subscription change creates an invoice with a specific `invoice_kind` depending on what changed. This ensures invoices are clearly labeled in the billing portal.

| Change | `type` (Mollie metadata) | `invoice_kind` | Description / Label |
|--------|--------------------------|----------------|---------------------|
| Plan upgrade | `prorata` | `prorata` | "Pro-rata plan upgrade" |
| Addon added | `addon` | `addon` | "Addon: Print Gateway (pro-rata)" |
| Seats increased | `seats` | `seats` | "Extra seats (pro-rata)" |
| Usage overage | `overage` | `overage` | "Usage overage" |
| Subscription renewal | — | `subscription` | Regular recurring payment |
| Refund / downgrade credit | — | `credit_note` | Credit note referencing original invoice |

### How Invoice Types Are Resolved

`UpdateSubscription::resolveChargeInfo()` inspects the `SubscriptionChangeContext` to determine what changed:

1. **Only addons changed** (no plan, interval, or seat change): `type = 'addon'`, label includes addon name
2. **Only seats changed**: `type = 'seats'`, label shows seat count
3. **Everything else** (plan change, interval change, mixed): `type = 'prorata'`

The webhook controller routes all these types (`prorata`, `addon`, `seats`, `overage`) through `handleSingleChargePaid()` / `handleSingleChargeFailed()`.

## Local → Mollie Upgrade

A free-plan user selecting a paid plan in the bundled UI is diverted to `UpgradeLocalToMollie` instead of going through the regular `UpdateSubscription` deferred flow:

```
User selects paid plan in plan-change UI
  -> applyChange() detects (isLocal && ! isFreePlan(target))
  -> Show confirmation step (read-only billing data + previewed gross)
  -> UpgradeLocalToMollie::handle()
     - Reuse / create Mollie customer (MollieCustomerResolver)
     - Create Mollie first payment with metadata.upgrade_from_local=true
  -> Redirect to Mollie checkout
  -> Webhook -> handleLocalToMollieUpgrade()
     - persistFirstPaymentArtifacts() (mandate, customer, invoice)
     - CreateSubscription::handle() (Mollie subscription + state)
     - WalletPlanChangeAdjuster::adjust() (rebalance, preserve purchased_balance)
     - SubscriptionUpgradedFromLocal event
```

The wallet is **not** seeded fresh — `WalletPlanChangeAdjuster` rebalances plan credits while preserving any purchased balance the user already had.

## Mollie → Free Downgrade

When the target plan is free (`catalog->isFreePlan()`), `UpdateSubscription::update()`:

1. Cancels the Mollie subscription via `CancelSubscriptionRequest` (tolerant of API failures).
2. Removes `mollie_subscription_id` from `subscription_meta`.
3. Sets `subscription_source = local`, `subscription_status = active`, `subscription_ends_at = null`, `subscription_period_starts_at = now()`.
4. Skips `syncMollieSubscription()`.
5. Runs `WalletPlanChangeAdjuster` to reduce plan credits to the free-plan quota; `purchased_balance` is preserved.
6. Refunds any prorata credit via `refundProrataCredit()` if `apply_at = immediate` and a mandate is still available.

`config('mollie-billing.plan_change_mode')` controls whether this happens immediately or is scheduled (`ScheduleSubscriptionChange::apply()` re-enters `UpdateSubscription::update()` with `internal=true` to bypass the user-input mode check).

## Events

| Event | When |
|-------|------|
| `PlanChangePending` | Phase 1: prorata payment created, plan not yet changed |
| `PlanChanged` | Phase 2a or immediate: plan actually changed |
| `PlanChangeFailed` | Phase 2b: prorata payment failed, or Phase 2a validation failed |
| `SubscriptionUpgradedFromLocal` | Local → Mollie upgrade completed via webhook |
| `SeatsChanged` | Seat count changed |
| `AddonEnabled` | Addon added |
| `AddonDisabled` | Addon removed (or stripped as incompatible) |
| `SubscriptionUpdated` | Always dispatched after a change is applied, with full diff |
| `SubscriptionChangeScheduled` | Downgrade scheduled for end of period |

## Extension Points

### Custom Validation

```php
// In AppServiceProvider::register()
$this->app->bind(ValidateSubscriptionChange::class, MyCustomValidator::class);
```

Your custom validator can extend `ValidateSubscriptionChange` and override individual methods (`validateSeats`, `validateAddons`, `validateWalletUsage`, `validateMollieReadiness`).

### Event Listeners

React to plan changes via Laravel event listeners:

```php
Event::listen(PlanChanged::class, function (PlanChanged $event) {
    // $event->billable, $event->oldPlan, $event->newPlan, $event->interval
});

Event::listen(PlanChangeFailed::class, function (PlanChangeFailed $event) {
    // $event->billable, $event->pendingChange, $event->paymentId, $event->reason
});
```

### Catalog Interface

Plan definitions, pricing, quotas, and addon compatibility all come from `SubscriptionCatalogInterface`. The default implementation reads from config (`config/mollie-billing-plans.php`). Bind your own implementation for database-driven catalogs.

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| User cancels pending change, then payment succeeds | Invoice is created (money was collected), but no `pending_plan_change` exists, so plan stays unchanged. Admin must refund manually. |
| Webhook arrives before Phase 1 transaction commits | Row lock blocks the webhook until commit. |
| Phase 2 validation fails after payment | Logged, pending stays in meta for admin review. `PlanChangeFailed` event + notification sent. |
| Local → Local plan switch | Never enters the deferred path. Applies immediately, source stays Local. |
| Local → Mollie via `UpdateSubscription` (no UI) | Throws `LocalSubscriptionUpgradeRequiresMolliePathException`. Use `UpgradeLocalToMollie` instead. |
| Paid extras on a Local subscription | Throws `LocalSubscriptionDoesNotSupportPaidExtrasException`. UI surfaces this as a callout that links to the plan-change page. |
| Second plan change while one is pending | `InvalidSubscriptionStateException` thrown. |

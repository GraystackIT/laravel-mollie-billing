# Plan Changes

This document describes how plan changes work in `laravel-mollie-billing`, including the deferred upgrade flow, validation rules, events, and extension points.

## Overview

Plan changes are handled by `UpdateSubscription::update()` and come in three flavors:

| Type | Trigger | Timing |
|------|---------|--------|
| **Upgrade** (higher price) | User selects a more expensive plan | Deferred until payment confirmed |
| **Downgrade** (lower price) | User selects a cheaper plan | Immediate or scheduled to end of period |
| **Zero-cost change** | Same price, different plan/interval | Immediate |

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

## Events

| Event | When |
|-------|------|
| `PlanChangePending` | Phase 1: prorata payment created, plan not yet changed |
| `PlanChanged` | Phase 2a or immediate: plan actually changed |
| `PlanChangeFailed` | Phase 2b: prorata payment failed, or Phase 2a validation failed |
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
| Local subscriptions | Never enter the deferred path. Changes apply immediately. |
| Second plan change while one is pending | `InvalidSubscriptionStateException` thrown. |

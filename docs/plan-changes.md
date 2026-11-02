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
| **Past-Due reset** | User on `past_due` selects any paid plan (same or different) | Charges the full first period of the new plan, resets the cycle |
| **Change during a trial** | Seats, addons or quantities while `subscription_status = trial` | Immediate and **free** — no charge, no credit. The trial keeps running; Mollie is PATCHed so the first real charge collects the new amount. A plan or interval change is *not* this case: it ends the trial and is charged. |
| **Zero-cost change** | Same price, different plan/interval | Immediate, booked as a saldo-zero switch invoice settled against the paying one |
| **Free → Free change** | Both source and target are free plans | Immediate, source stays Local |

### Two changes inside one period

A mid-period change is *priced* against the whole period (`newNet × remaining / total`) but
*buys* only the days that are left, and the line it writes is stamped with the window it
actually bought — `[now, period_end]`, not `[period_start, period_end]`. That stamp is what a
later change in the same period reads: `ProrataComposer` derives each credit from the line's
own window, so an amount that bought 21 days is divided by 21 days, not by 31.

A change whose two sides cancel out is still booked. `BillingPolicy::computeProrata()` nets
charge against credit, so an equal-price plan switch reports 0/0 — but the composer produces a
full charge for the new plan and a matching credit for the old one, and those have to be
recorded: the old plan line's remaining value now belongs to the new plan code, so it is no
longer refundable. `InvoiceService::createPlanSwitchInvoice()` writes them as one invoice whose
header nets to zero and increments `refunded_net` on the invoices it credits.

That switch invoice has no `mollie_payment_id` of its own — the new plan's days were paid for
out of the *previous* invoice, and `mollie_payment_id` is unique because that uniqueness is the
webhook's idempotency guard. The settlement is therefore recorded as a relationship:
`billing_invoices.settled_by_invoice_id` points at the invoice whose payment covers it, set only
when there is exactly one such invoice and it covers the switch's charges in full.

Everything that has to answer "can this be refunded, and against what" follows that pointer:

- `BillingInvoice::currentPeriodLines()` offers an invoice as a refund candidate if it collected
  a payment **or** is settled by one that did.
- `refundableBaseNet()` / `refundableBaseGross()` read a settled invoice line by line (its own
  charges, i.e. lines without a `parent_invoice_id`) rather than from its header, which nets to
  zero by construction. Every other invoice is still measured by its header — that is the money
  Mollie actually took, and lines can legitimately state something else.
- `InvoiceService::createRefund()` posts to `refundablePaymentId()`, so the refund lands on the
  payment that physically holds the money.

The books stay balanced across the pair: a switch only gets the pointer when its charge is fully
covered by credits booked against the source invoice, so the source's remaining refundable base
drops by exactly what the switch's base gains. A 2900 renewal switched on day 11 leaves
`935 + 1965 = 2900`, and a downgrade on day 20 credits `1965 × 12/21 = 1123` out of the switch.

If the pointer cannot be set (several source invoices, or a charge larger than its credits) the
switch stays unrefundable, exactly as before.

## Deferred Upgrade Flow

Upgrades on Mollie subscriptions with a prorata charge > 0 are split into two phases to ensure the plan is only changed after payment confirmation.

```
Phase 1 — User initiates upgrade (synchronous)
  1. Build SubscriptionChangeContext (compute seats, addons, prorata, etc.)
  2. ValidateSubscriptionChange::validate()
     - Target plan: throw HiddenPlanNotSelectableException for hidden plans
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
  Note: if the local pending state is already gone when the failed/expired
  webhook arrives (user clicked "cancel pending change" first), the webhook
  is a silent no-op — no failed marker, no event, no notification.
```

## Validation Rules

All validation is centralized in `ValidateSubscriptionChange`. It runs in both Phase 1 (pre-flight) and Phase 2 (before applying). Apps can override it via `app->bind`.

Two rules are worth calling out because they are easy to trip over:

- **One pending change at a time.** A change that moves money (a prorata charge *or* a credit) is refused while `pending_plan_change` / `pending_prorata_change` is set. This covers credit-only downgrades, not just charges — a second change keyed to the first one's payment id was applied as the first one's intent and lost without a trace.
- **A settled charge is never applied to a subscription that no longer runs.** If the billable is Cancelled or Expired by the time Phase 2 arrives, the plan is not applied: the money is invoiced, `PlanChangeFailed` is dispatched and admins are notified for a manual refund. Applying it would resurrect the plan-scoped fields the expiry wipe removes, handing feature access back to an ended subscription.

### Established Subscription

- A plan change requires an existing subscription. If `subscription_source` is `none` (or unset) — i.e. checkout was never completed, e.g. an abandoned/cancelled first payment — `InvalidSubscriptionStateException` is thrown. Such billables must go through the checkout flow, which obtains a mandate and creates the subscription. The portal's plan screen (`BillingPortalController::plan()`) redirects these billables to checkout for the same reason.

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

### Nothing is prorated during a trial

A trial is free, and everything the current plan allows is part of what is being tried. Seats and addons added during one are **not charged**, and taking them away again credits nothing: no money was paid for the window they are added to, so there is no fraction of anything to prorate. Mollie is still PATCHed to the new amount, so the first real charge — at the trial end — collects it in full.

A **plan or interval change is the exception**: it ends the trial and is charged. That is a product decision, not an arithmetic one — testing one plan does not entitle anyone to test a different one for the rest of the same trial.

`BillingPolicy::isFreeTrialAdjustment()` is the single predicate, for the same reason `isPastDueReset()` is: `ProrataComposer`, `UpdateSubscription::buildContext()` and `PreviewService` all have to give the same answer. Before it existed the composer and the context disagreed with the preview — the preview quoted €0 for extra seats during a trial and the change then collected the prorated amount anyway, converting the trial with it.

### Day Granularity

Billing uses **whole calendar days** (`startOfDay()`). A change made on the same day as the period start yields a factor of exactly `1.0` — the current day counts as a full remaining day.

### Same-Interval Changes

When the billing interval stays the same (e.g. monthly → monthly):

- **Upgrade** (`newNet > currentNet`): `charge = (newNet - currentNet) * factor`
- **Downgrade** (`newNet < currentNet`): `credit = (currentNet - newNet) * factor`

**The period boundary does not move.** The factor covers only the remainder of the *current* window, and Mollie's charge date is left untouched (the PATCH changes the amount, not the cadence). So `subscription_period_starts_at` stays where it was, and the change's invoice is stamped with the existing period. Phase 2 re-anchoring the period here made `nextBillingDate()` report a date Mollie does not charge on, and a seat added afterwards was prorated against that phantom window while the real renewal billed the same days again.

Two exceptions do open a new window, and both also move Mollie's schedule: an interval change, and the [past-due reset](#past-due-reset). A plan change that converts an in-flight trial is a third case where the *schedule* moves — Mollie's `startDate` is the trial end, and the charge has just paid for the rest of the local period, so the first recurring charge is pushed one full period out.

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
6. Refunds any prorata credit through `ProrataExecutor` → `InvoiceService::createRefund()` if `apply_at = immediate` and a mandate is still available.

`config('mollie-billing.plan_change_mode')` controls whether this happens immediately or is scheduled (`ScheduleSubscriptionChange::apply()` re-enters `UpdateSubscription::update()` as an internal re-entry to bypass the user-input mode check). The marker is set only through the typed `SubscriptionUpdateRequest::asInternalReentry()`; `SubscriptionUpdateRequest::from()` ignores an `internal` key in array data, like `allow_hidden_plan`, because it also waives the lapsed-period guard.

### When "end of period" falls

`ScheduleSubscriptionChange::schedule()` anchors on `nextBillingDate()`, except **on a trial**, where it takes the earlier of `nextBillingDate()` and `trial_ends_at`. A trial's first charge lands on `trial_ends_at` (day 14), not on the period anniversary (day 30) — anchoring on the latter applied the change in the middle of the first paid period, and since `apply()` re-enters as an `internal` immediate update the lapsed-period guard is waived, so it raised the very prorata charge the customer scheduled the change to avoid.

`schedule()` refuses (`InvalidSubscriptionStateException`) for any status `apply()` would not act on — i.e. anything other than `active`, `trial`, `past_due`. A cancelled-in-grace customer used to get a "scheduled for &lt;date&gt;" confirmation for a change the daily dispatcher then silently discarded; the path for them is to resubscribe.

## Past-Due Reset

When a recurring charge fails the subscription enters `subscription_status = past_due`. From this state the user can recover in two ways: pay the failed invoice (regular dunning), or switch to any paid plan. The plan-switch path is the **Past-Due reset** — a special branch through the standard `UpdateSubscription` flow.

The intuition: in `past_due`, the current period was never paid. There is nothing to pro-rate against — no money was collected that could be credited and no fractional period can be left over. So a plan change in this state is conceptually a **fresh start**, not a mid-period adjustment.

### Detection

Past-Due reset triggers when **all** of the following hold:

- `billable->isBillingPastDue()` is `true` (subscription status is `past_due`).
- The new plan is **not** a free plan (`! catalog->isFreePlan(newPlan, newInterval)`).
- Either the plan or the interval changed (`planChanged || intervalChanged`).

Pure seat or addon changes on a `past_due` subscription do **not** reset — they go through the normal prorata path. The reset is intentionally limited to plan/interval switches because that is the recovery action exposed to users in the UI.

A `past_due` user downgrading to a free plan does **not** reset either — the existing `Mollie → Free downgrade` path runs and the subscription switches to `local`, no charge.

### What changes vs. a normal upgrade

The reset uses the same Phase 1 / Phase 2 pipeline as a regular deferred upgrade. The only differences:

| Aspect | Normal upgrade | Past-Due reset |
|--------|----------------|----------------|
| Prorata charge | `(newNet - currentNet) × factor` | `newNet` (full first period at list price) |
| Prorata credit | `(currentNet - newNet) × factor`, or `currentNet × factor` on interval change | `0` — nothing was paid that could be credited |
| Mollie subscription start date | `null` (cadence preserved) on amount-only changes; `now + 1 interval` on interval change | `now + 1 interval` always (`forceResetStartDate=true`) |
| `subscription_period_starts_at` | Untouched on amount-only changes | Set to `now` (Phase 2, derived from `startsNewPeriod`) |
| `payment_failure` marker | Untouched | Cleared in Phase 2 |
| `subscription_status` | Stays `active` | Flipped from `past_due` back to `active` in Phase 2 |
| `subscription_ends_at` | Untouched | Cleared in Phase 2 |

`forceResetStartDate=true` is critical: without it, Mollie's PATCH would keep the failed-charge cadence and immediately retry charging the (new) amount right after the PATCH lands, producing a duplicate of the prorata charge that just succeeded.

### Code paths

The reset is implemented at three points that must stay in sync. Each builds the same line items, just from a different angle:

1. **`PreviewService::computePreview()`** — sets `prorataChargeNet = newNet`, `prorataCreditNet = 0`, `prorataFactor = 1.0`. Surfaces `isPastDueReset` in the preview array so the UI can show the explanatory callout (`portal.past_due_reset_notice`).
2. **`UpdateSubscription::buildContext()`** — computes the same charge values for the live mutation. The resulting `SubscriptionChangeContext` flows through Phase 1's payment creation unchanged.
3. **`ProrataComposer::composePastDueReset()`** — produces the actual `ProrataLine[]` (plan + extra seats + addons) using a synthetic `now → now + 1 interval` window so the standard charge-line helpers compute `factor = 1.0` and emit list-price amounts. No refund lines.

`UpdateSubscription::buildIntent()` then sets `forceResetStartDate = isBillingPastDue() && (planChanged || intervalChanged)` on the `PlanChangeIntent`. `MollieSubscriptionPatcher::updateRecurringAmount()` reads that flag and forces `startDate = now + 1 interval` on the PATCH.

### Phase 2 cleanup

When the prorata payment succeeds, `MollieWebhookController::handleSingleChargePaid()` runs the standard Phase 2 apply on top of additional past-due-specific writes:

- Capture `wasPastDue = (status === PastDue)` **before** rewriting the meta.
- Drop `payment_failure` from `subscription_meta` — the failed-charge marker no longer reflects reality.
- Set `subscription_status = active`, `subscription_ends_at = null`, and the new `subscription_period_starts_at = now`.

If Phase 2 fails (re-validation throws, Mollie PATCH errors, etc.), the user stays in `past_due` and the pending state is rolled back via the standard `clearPendingPlanChange()` path. The user can retry the plan switch — there is no half-applied state.

### UI surface

In the bundled plan-change page (`livewire/billing/⚡plan-change.blade.php`), the preview shows `portal.past_due_reset_notice` as an amber callout whenever `preview.isPastDueReset` is true:

> Your previous charge failed, so no part of the current period was paid. Switching plans here charges the full first period of the new plan immediately and starts a fresh billing cycle from today.

This makes the difference from a regular upgrade explicit — the user sees the full price (not a prorated delta) and understands why.

### Edge cases

| Scenario | Behavior |
|----------|----------|
| Past-due user picks the **same** plan they already have | `planChanged=false`, `intervalChanged=false` → no reset, no plan-change path runs. The user must use the "pay failed invoice" recovery instead. |
| Past-due user picks the same plan with a different interval | `intervalChanged=true` → reset applies. Charges full first period of the new interval. |
| Past-due user downgrades to a **free** plan | Reset does **not** apply (`isFreePlan` check). The `Mollie → Free downgrade` path runs: Mollie subscription cancelled, source flipped to `local`, no charge. `payment_failure` is cleared by the standard downgrade flow. |
| Reset's prorata payment fails | Standard Phase 2b: `clearPendingPlanChange()` removes the pending state. User remains `past_due`, `payment_failure` marker untouched, `PlanChangeFailed` event + notification sent. |
| Webhook arrives twice for the same charge | Idempotent via `BillingProcessedWebhook` (standard webhook-dedup) — the `wasPastDue` capture happens once. |
| Past-due on a Local subscription | Cannot occur. Local subscriptions never enter `past_due` (no recurring charges). |

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
| User cancels pending change via "Ausstehende Änderung abbrechen" | `UpdateSubscription::cancelPendingPlanChange()` issues a `CancelPaymentRequest` against Mollie if the payment is still `isCancelable=true` (typical for cards, SEPA) and then clears local state. Some methods (e.g. paypal) report `isCancelable=false` — those expire on their own at Mollie; local state is still cleared. |
| User cancels pending change, then payment succeeds anyway | Invoice is created (money was collected), but no `pending_plan_change` exists, so plan stays unchanged. Admin must refund manually. This window is small because `cancelPendingPlanChange()` actively cancels the Mollie payment when possible. |
| User cancels pending change, Mollie payment then expires/fails | The failed/expired webhook becomes a no-op: no `plan_change_failed_at` marker, no `PlanChangeFailed` event, no admin notification. The user already decided this charge should not apply — surfacing a failure toast would be misleading. |
| Webhook arrives before Phase 1 transaction commits | Row lock blocks the webhook until commit. |
| Phase 2 validation fails after payment | Logged, pending stays in meta for admin review. `PlanChangeFailed` event + notification sent. |
| Local → Local plan switch | Never enters the deferred path. Applies immediately, source stays Local. |
| Local → Mollie via `UpdateSubscription` (no UI) | Throws `LocalSubscriptionUpgradeRequiresMolliePathException`. Use `UpgradeLocalToMollie` instead. |
| Paid extras on a Local subscription | Throws `LocalSubscriptionDoesNotSupportPaidExtrasException`. UI surfaces this as a callout that links to the plan-change page. |
| Change onto a plan marked `hidden` | Throws `HiddenPlanNotSelectableException` — for immediate *and* scheduled (`apply_at => end_of_period`) changes, the latter before anything is stored. Staying on a hidden plan and changing interval within it are allowed. Deliberate programmatic moves pass a typed `SubscriptionUpdateRequest` with `allowHiddenPlan: true` — array payloads cannot enable the override. See [Hidden plans](configuration.md#hidden-plans). |
| Second plan change while one is pending | `InvalidSubscriptionStateException` thrown. |

## Transaction boundaries

`UpdateSubscription::update()` reaches Mollie three times — `applyProrata()` creates payments
and posts refunds, and the patcher cancels or PATCHes the subscription. None of those may sit
inside a database transaction: a transaction can be rolled back, an HTTP call to a payment
provider cannot, and a rollback after the money moved is exactly how a refund came to be paid
out twice.

So the method runs in three phases, with a **cache lock** (`billing:subscription-change:{id}`)
providing the serialisation a long transaction used to:

1. **Decide** — row lock in a short transaction: build the context, validate, resolve coupons.
2. **Talk to Mollie** — nothing held.
3. **Write** — a second short transaction that re-takes the row lock and re-reads
   `subscription_meta`, because a job (a retry, the overage sweep) may have committed a key in
   between and the write below replaces the whole column.

A concurrent change to the same billable is refused with `SubscriptionChangeInProgressException`
rather than queued: it would otherwise sit on a worker for the length of a payment plus a
PATCH, and its seats, addons and prorata amounts are stale by the time it gets its turn.
Events are dispatched through `DB::afterCommit()` for the same underlying reason — a throwing
listener must not be able to undo settled money.

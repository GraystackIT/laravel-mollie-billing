# Subscription Lifecycle

This document provides an overview of the subscription states and transitions in `laravel-mollie-billing`.

## States

| Status | Description |
|--------|-------------|
| `new` | Billable created, no subscription yet |
| `trial` | Free trial period, optionally with Mollie mandate |
| `active` | Paid subscription, recurring payments active |
| `past_due` | Payment failed, grace period before cancellation. Recovers either by paying the failed invoice or by switching plans — see [Past-Due reset](plan-changes.md#past-due-reset). |
| `cancelled` | User-initiated cancellation, active until end of period |
| `expired` | Subscription ended (trial expired or cancellation period over) |

### What a transition must clear

`subscription_meta` carries clocks, schedules and pricing that belong to *one* subscription. A transition that leaves them behind does not leave something inert — the next unattended pass acts on them:

| Key | What reads it, if it survives |
|---|---|
| `past_due_since`, `payment_failure` | The auto-cancel clock. A stale date meant one later failed renewal was cancelled the same night, with a zero-day dunning window. |
| `scheduled_change`, `scheduled_change_at` | The period-end dispatcher, which keys off the column alone. |
| `next_charge_date_override` | `nextBillingDate()`, outranking the period anchor — it defers a free plan's quota recharge. |
| `active_recurring_coupon` | `CreateSubscription` on a later upgrade, granting a discount campaign nobody applied. |

`Support\SubscriptionMetaHygiene` holds these lists so a new transition cannot forget one:

- `forFreshSubscription()` — everything above. Used by `ActivateLocalSubscription` and the downgrade to a free plan, i.e. wherever a *new* subscription begins.
- `withoutDunningEpisode()` — the dunning keys only. Used by `CreateSubscription` (pricing and scheduling belong to its caller there: the 100 %-coverage coupon path writes `active_recurring_coupon` *before* the call so the Mollie start date can be deferred past the free periods) and by a payment that clears while the billable is PastDue.

Identity keys are never cleared. `mollie_subscription_id` and `pending_subscription_cancel` are the only handles on a subscription that may still be live at Mollie — dropping either orphans it, and it keeps charging with nothing able to target it.

## Subscription Sources

| Source | Description |
|--------|-------------|
| `none` | No subscription |
| `local` | Free/zero-price plan managed locally, no Mollie subscription |
| `mollie` | Paid plan with Mollie recurring subscription |

## Flows

### Checkout (New Paid Subscription)

```
User -> StartSubscriptionCheckout -> Mollie first payment
  -> Webhook: handleFirstPaymentPaid()
    -> persistFirstPaymentArtifacts() — mandate + customer + invoice
    -> CreateSubscription::handle()
      -> Create Mollie subscription (stores mollie_subscription_id)
      -> Set source=mollie, status=active
    -> Credit wallet with included usages (handled by webhook, not CreateSubscription)
    -> SubscriptionCreated event
```

`CreateSubscription` does **not** touch the wallet. The caller (webhook handler / resubscribe / local upgrade) decides the right hydration strategy in each context.

**Service:** `StartSubscriptionCheckout`, `MollieCustomerResolver`, `CreateSubscription`

### Trial Flow

A trial starts when the chosen `(planCode, interval)` carries a positive `trial_days` value in the catalog, the order costs something, and the billable does **not** yet have a Mollie mandate. The trial is decided *before* the zero-amount branch, so it wins over a coupon that covers the whole first payment — otherwise the confirm step announced a trial and a first-charge date, and the customer got an Active subscription with no trial and the coupon spent on the period that was meant to be free. The checkout does not accept a `single_payment` coupon on a trial signup at all (there is no first payment for it to discount; see [coupons.md](coupons.md)); a `recurring` one is applied by the trial path as before. Trials only fire on a fresh checkout — `ChangePlan`, `EnableAddon`, and other plan-change services never start or extend trials.

```
User -> StartSubscriptionCheckout (catalog->trialDays() > 0, no mandate yet)
  -> StartMandateCheckout (0 EUR, sequenceType=first, metadata.pending_subscription_*)
    -> Mollie hosted page captures mandate
      -> Webhook: handleMandateOnlyPaid -> activateSubscriptionAfterMandate
        -> activateTrialSubscriptionAfterMandate
          -> CreateSubscription::handle() with trial_days
            -> Mollie subscription with startDate = now + trial_days, full plan amount
            -> Local: status=trial, trial_ends_at=now+trial_days, source=mollie
          -> Stash coupon (if any) for the first paid charge:
              Recurring -> apply marker via CouponService::applyRecurringMarker()
              SinglePayment -> one-period marker via CouponService::applyFirstChargeMarkerFrom()
                               (discounts the trial-end charge only)
          -> Hydrate wallet aliquot: ceil(included * trial_days / intervalDays)
          -> TrialStarted event
```

Key properties:

- **No invoice is generated**, no `PaymentSucceeded`, no money flows during the trial. The customer's wallet is still hydrated so they can use the product immediately.
- **Wallet hydration is aliquot** to the trial length (`Support\TrialQuota`). For a 14-day trial on a monthly plan with 10 included Tokens: `ceil(10 * 14 / 30) = 5` tokens. For a 60-day trial: `ceil(10 * 60 / 30) = 20` tokens (2× full quota). Always rounded up so a positive included quota credits at least 1. What was granted is recorded under `subscription_meta.trial_quota` and served by `grantedBillingQuota($type)`, because everything that measures the wallet has to measure against the grant and not against the plan's period quota — otherwise the units that were never credited read as consumption on a subscription minutes old.
- **Seats and addons are free during the trial.** Everything the current plan allows is part of what is being tried: no prorata charge, no credit when it is removed again, and the trial keeps running. Mollie is PATCHed to the new amount, so the first real charge collects it. See [plan-changes.md](plan-changes.md#nothing-is-prorated-during-a-trial).
- **A plan or interval change mid-trial ends the trial**, and the wallets then get the full period quota that charge paid for — the same refill the first recurring payment does. See [usage-billing.md](usage-billing.md#a-trial-is-credited-a-prorated-quota); a plan change that leaves the trial running rebalances against the recorded grant instead.
- **The date the checkout names is the date Mollie charges on.** The confirm step prints `firstChargeDate()` of the checkout component: the trial's end, or — when a `recurring` coupon covers the whole order — the first regular charge that coupon no longer covers, counted in billing periods from the trial end where there is one (`CouponService::recurringChargeResumesAt()`, the same value `CreateSubscription` sends as the start date). It used to print the trial's end regardless.
- **Mollie's first real charge** fires at `startDate = now + trial_days`. When that webhook arrives, `handleSubscriptionPaymentPaid` flips status from `trial` to `active`, consumes any parked SinglePayment coupon (discounts the line items + creates a redemption), and lets the recurring marker apply for the first time.
- **Trials never repeat for a returning customer**. Once `mollie_mandate_id` is set, the trial branch is skipped — even on a fresh checkout — and the customer pays the full first period.

If the trial expires without a successful first charge (mandate failure, mandate revoked, customer never paid), `ProcessTrialLifecycleJob` flips the billable to `past_due` **and stamps `past_due_since`**, dispatches `TrialExpired`, and notifies via `TrialExpiredNotification`. `RequireActiveSubscription` then routes the user back to checkout.

The stamp is what keeps the row moving: `PrepareUsageOverageJob`'s auto-cancel pass keys off `past_due_since` alone, so without it the billable stayed in `past_due` indefinitely — never reaching `cancelled → expired` and therefore never reaching the plan-field wipe that closes `hasPlanFeature()`. For the same reason the job's local-quota reset (Case C) skips `past_due`: a past-due local subscription is not renewing, but `nextBillingDate()` keeps cycling for it, so it used to collect a fresh month of consumable quota on every anniversary.

`TrialExpiredNotification` says which of the two happened: "no payment method was added in time" for a trial without a mandate, "the first payment has not reached us" (`trial_expired.body_with_mandate`) for one that had a payment method.

A trial that *does* hold a mandate is left alone by that job at first: Mollie's first charge is scheduled for `trial_ends_at`, and the webhook owns both outcomes (`paid` → `active`, `failed` → `past_due`). That only holds if a webhook arrives. Mollie calls it for payments and nothing else, so a subscription cancelled or suspended at Mollie, a revoked mandate, or a `failed` delivery lost past the retry window leaves the row on `trial` — which grants access unconditionally. After `ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS` (14, the same figure as the stuck country-correction and overage sweeps) without either outcome, the job applies the same transition as for a mandate-less trial: `past_due`, `past_due_since` stamped, `TrialExpired` dispatched. A payment that still arrives afterwards is honoured — the renewal handler activates from `past_due`.

If a mandate exists but Mollie's first charge has simply not fallen due yet (`startDate` in the future) at the moment the local trial expires, the billable can sit in `past_due` while Mollie still shows the subscription as `active`. The `MollieSubscriptionGate` (called from `BillingPortalController::checkout`) detects this and redirects to the dashboard instead of opening the checkout — re-entering `CreateSubscriptionRequest` would 422 with "same description already exists". The dashboard surfaces the scheduled charge date and offers a "Charge now" button that PATCHes Mollie's subscription `startDate` to today — only offered while that start date lies ahead, i.e. on a subscription Mollie has not charged yet; a charged one refuses any new start date, and the button never replaces a subscription (a replacement never starts today). Stale `mollie_subscription_id` entries (Mollie reports `canceled`/`completed`/`suspended` or 404) are removed from `subscription_meta` so the next checkout cleanly creates a new subscription. Lookups are cached for 60 seconds.

Ahead of expiry, the same daily job sends a single `TrialEndingSoonNotification` when `trial_ends_at` falls on the calendar day exactly `trial_ending_soon_notice_days` ahead (default: `1`, i.e. tomorrow). The notification branches on `hasMollieMandate()` to pick the wording — "first charge will be made automatically" with a mandate, "please add a payment method" without. Configurable via [`trial_ending_soon_notice_days`](configuration.md#core) / `BILLING_TRIAL_ENDING_SOON_NOTICE_DAYS`. Missed windows are not backfilled when the value is increased.

`TrialConvertedNotification` and the `TrialConverted` event are dispatched separately by `SubscriptionPaymentHandler::paid()` once Mollie's first recurring charge actually lands (an invoice exists at that point) — not by this job, because the conversion is only real once the payment has settled.

**Services:** `StartSubscriptionCheckout`, `StartMandateCheckout`, `CreateSubscription`, `MollieWebhookController::activateTrialSubscriptionAfterMandate`, `CouponService::applyRecurringMarker`, `ProcessTrialLifecycleJob`

**Configuration:** `intervals.{monthly|yearly}.trial_days` per plan — see [Configuration](configuration.md#plans). Plan-level `trial_days` is not supported. Notice window: `trial_ending_soon_notice_days` (default `1`).

### Local Subscription Activation (Free Plan / Coupon)

```
Trigger -> ActivateLocalSubscription::handle()   (checkout: order net is 0 — a coupon in the request or session is ignored, there is nothing to discount)
  -> Set source=local, status=active
  -> Credit wallet with included usages
  -> SubscriptionCreated event
```

Local subscriptions never have a Mollie mandate. Paid add-ons and paid extra seats are blocked via `LocalSubscriptionDoesNotSupportPaidExtrasException` — see `ValidateSubscriptionChange::validateLocalSubscriptionExtras()`.

**Service:** `ActivateLocalSubscription`, `CouponService::applyAccessGrant` (for coupon-driven activations)

### Plan Change

```
User -> UpdateSubscription::update()
  -> ValidateSubscriptionChange, ProrataComposer (charge and refund lines)
  -> Charge due: ProrataExecutor creates one Mollie payment (type = prorata_charge),
     pending_prorata_change stored, PlanChangePending event — plan NOT switched yet
    -> Webhook: ProrataChargeHandler::paid()
       -> BillingInvoice, plan/interval/seats/addons switched, wallets rebalanced
       -> In-place PATCH of the Mollie subscription (MollieSubscriptionPatcher)
       -> PaymentSucceeded + PlanChanged events
    -> Webhook: ProrataChargeHandler::failed()
       -> pending state cleared, PlanChangeFailed event, plan unchanged
  -> No charge (sidegrade, downgrade): applied at once, Mollie PATCHed, credit refunded
```

See [Plan Changes](plan-changes.md) for the full deferred upgrade flow, the Local→Mollie upgrade path, and the Mollie→Free downgrade, and [flows.md](flows.md#8-plan-and-interval-change) for the sequence diagram.

**Service:** `UpdateSubscription`, `ValidateSubscriptionChange`, `ProrataComposer`, `ProrataExecutor`, `ProrataChargeHandler`, `MollieSubscriptionPatcher`, `ScheduleSubscriptionChange`, `WalletPlanChangeAdjuster`, `UpgradeLocalToMollie`

### Recurring Payment

```
Mollie fires subscription webhook
  -> handleSubscriptionPaymentPaid()
    -> Validate expected vs actual amount
    -> Create BillingInvoice
    -> Recharge wallet quotas (rollover-aware, purchased balance preserved)
    -> Update subscription_period_starts_at
```

**Service:** `MollieWebhookController`

### Cancellation

```
User -> CancelSubscription::handle()
  -> If Mollie: cancel Mollie subscription
  -> Set status=cancelled, subscription_ends_at=end of period
  -> SubscriptionCancelled event
```

For Local subscriptions the Mollie cancel call is skipped — only the local state is updated and wallets are kept until `subscription_ends_at` (which may be `null` for indefinite free plans).

**Service:** `CancelSubscription`

### Resubscribe

```
User -> ResubscribeSubscription::handle()
  -> If Local: just flip status back to active and clear subscription_ends_at
  -> If Mollie: create a new Mollie subscription via CreateSubscription
  -> No wallet credit — the current period's credits are still in the wallet
  -> SubscriptionReactivated event
```

**Service:** `ResubscribeSubscription`

### Local → Mollie Upgrade

```
User on free plan -> selects paid plan in plan-change UI
  -> Component shows confirmation step (existing billing data, computed gross)
  -> UpgradeLocalToMollie::handle()
    -> Reuse Mollie customer (MollieCustomerResolver)
    -> Create Mollie payment with sequenceType=first, metadata.upgrade_from_local=true
    -> Return checkout_url for redirect
  -> User pays
  -> Webhook: handleLocalToMollieUpgrade()
    -> persistFirstPaymentArtifacts() — mandate + customer + invoice
    -> CreateSubscription::handle() — switch source to Mollie
    -> WalletPlanChangeAdjuster::adjust() — rebalance plan credits, preserve purchased_balance
    -> SubscriptionUpgradedFromLocal + PaymentSucceeded events
```

The handler treats a payment as a duplicate only when a Mollie subscription is already running. A Local subscription that has simply ended in the meantime — a gift that ran out or was revoked while a SEPA payment was travelling — is not that. An ordinary upgrade is booked and the subscription created. A purchase of extras that was priced on a gift (`keep_grant`) is *not* carried out once the gift is gone: the payment is booked and refunded in full, and nothing about the billable changes — see [coupons.md](coupons.md#a-gifted-plan-with-paid-extras).

**Service:** `UpgradeLocalToMollie`, `MollieWebhookController::handleLocalToMollieUpgrade`, `WalletPlanChangeAdjuster`

### Mollie → Free Downgrade

```
User on paid plan -> selects free plan
  -> UpdateSubscription::update() detects target is a free plan
  -> Cancel Mollie subscription (CancelSubscriptionRequest)
  -> Switch source=local, clear mollie_subscription_id
  -> WalletPlanChangeAdjuster — reduce plan credits to free-plan quota, keep purchased_balance
  -> Refund prorata credit (if immediate downgrade with mandate available)
  -> PlanChanged event
```

`config('mollie-billing.plan_change_mode')` controls whether this happens immediately or is scheduled to the period end.

**Service:** `UpdateSubscription`

### Usage & Overage

```
App -> WalletUsageService::debit()
  -> Wallet balance decremented
  -> If balance < 0: overage
    -> PrepareUsageOverageJob -> ChargeUsageOverageDirectly
      -> Create Mollie payment
      -> Webhook (type = overage): SingleChargeHandler::paid() -> BillingInvoice + OverageCharged event
```

**Service:** `WalletUsageService`, `ChargeUsageOverageDirectly`

## Key Services

| Service | Responsibility |
|---------|---------------|
| `StartSubscriptionCheckout` | Initiate Mollie first payment |
| `ActivateLocalSubscription` | Activate a free / coupon-granted plan locally |
| `CreateSubscription` | Create Mollie subscription after first payment (no wallet hydration) |
| `UpgradeLocalToMollie` | Convert a local subscription to a Mollie subscription |
| `UpdateSubscription` | Plan changes (immediate + deferred), incl. Mollie→Free downgrade |
| `ValidateSubscriptionChange` | Centralized validation, incl. Local-subscription guards |
| `ScheduleSubscriptionChange` | Schedule downgrades to end of period |
| `WalletPlanChangeAdjuster` | Rebalance wallets on plan/interval changes |
| `CancelSubscription` | Cancel subscription |
| `ResubscribeSubscription` | Reactivate cancelled subscription |
| `InvoiceService` | Create invoices and credit notes |
| `RefundInvoiceService` | Process refunds |
| `WalletUsageService` | Metered billing (debit/credit wallets) |
| `VatCalculationService` | VAT calculation based on country |
| `MollieCustomerResolver` | Resolve / create Mollie customer for a billable |
| `Support\SubscriptionAmount` | Single source of truth for subscription net + line items |
| `MollieWebhookController` | Handle all Mollie payment webhooks |

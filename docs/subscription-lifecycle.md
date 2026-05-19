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

A trial starts when the chosen `(planCode, interval)` carries a positive `trial_days` value in the catalog and the billable does **not** yet have a Mollie mandate. Trials only fire on a fresh checkout — `ChangePlan`, `EnableAddon`, and other plan-change services never start or extend trials.

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
              SinglePayment -> park in subscription_meta.pending_first_charge_coupon
          -> Hydrate wallet aliquot: ceil(included * trial_days / intervalDays)
          -> TrialStarted event
```

Key properties:

- **No invoice is generated**, no `PaymentSucceeded`, no money flows during the trial. The customer's wallet is still hydrated so they can use the product immediately.
- **Wallet hydration is aliquot** to the trial length. For a 14-day trial on a monthly plan with 10 included Tokens: `ceil(10 * 14 / 30) = 5` tokens. For a 60-day trial: `ceil(10 * 60 / 30) = 20` tokens (2× full quota). Always rounded up so a positive included quota credits at least 1.
- **Mollie's first real charge** fires at `startDate = now + trial_days`. When that webhook arrives, `handleSubscriptionPaymentPaid` flips status from `trial` to `active`, consumes any parked SinglePayment coupon (discounts the line items + creates a redemption), and lets the recurring marker apply for the first time.
- **Trials never repeat for a returning customer**. Once `mollie_mandate_id` is set, the trial branch is skipped — even on a fresh checkout — and the customer pays the full first period.

If the trial expires without a successful first charge (mandate failure, mandate revoked, customer never paid), `ProcessTrialLifecycleJob` flips the billable to `past_due`, dispatches `TrialExpired`, and notifies via `TrialExpiredNotification`. `RequireActiveSubscription` then routes the user back to checkout.

If a mandate exists but Mollie's first charge has simply not fallen due yet (`startDate` in the future) at the moment the local trial expires, the billable can sit in `past_due` while Mollie still shows the subscription as `active`. The `MollieSubscriptionGate` (called from `BillingPortalController::checkout`) detects this and redirects to the dashboard instead of opening the checkout — re-entering `CreateSubscriptionRequest` would 422 with "same description already exists". The dashboard surfaces the scheduled charge date and offers a "Charge now" button that PATCHes Mollie's subscription `startDate` to today. Stale `mollie_subscription_id` entries (Mollie reports `canceled`/`completed`/`suspended` or 404) are removed from `subscription_meta` so the next checkout cleanly creates a new subscription. Lookups are cached for 60 seconds.

Ahead of expiry, the same daily job sends a single advance-notice email — `TrialEndingSoonNotification` (no mandate yet) or `TrialConvertedNotification` (mandate captured, charge upcoming) — when `trial_ends_at` falls on the calendar day exactly `trial_ending_soon_notice_days` ahead (default: `1`, i.e. tomorrow). Configurable via [`trial_ending_soon_notice_days`](configuration.md#core) / `BILLING_TRIAL_ENDING_SOON_NOTICE_DAYS`. Missed windows are not backfilled when the value is increased.

**Services:** `StartSubscriptionCheckout`, `StartMandateCheckout`, `CreateSubscription`, `MollieWebhookController::activateTrialSubscriptionAfterMandate`, `CouponService::applyRecurringMarker`, `ProcessTrialLifecycleJob`

**Configuration:** `intervals.{monthly|yearly}.trial_days` per plan — see [Configuration](configuration.md#plans). Plan-level `trial_days` is not supported. Notice window: `trial_ending_soon_notice_days` (default `1`).

### Local Subscription Activation (Free Plan / Coupon)

```
Trigger -> ActivateLocalSubscription::handle()
  -> Set source=local, status=active
  -> Credit wallet with included usages
  -> SubscriptionCreated event
```

Local subscriptions never have a Mollie mandate. Paid add-ons and paid extra seats are blocked via `LocalSubscriptionDoesNotSupportPaidExtrasException` — see `ValidateSubscriptionChange::validateLocalSubscriptionExtras()`.

**Service:** `ActivateLocalSubscription`, `CouponService::applyAccessGrant` (for coupon-driven activations)

### Plan Change

See [Plan Changes](plan-changes.md) for the full deferred upgrade flow, the Local→Mollie upgrade path, and the Mollie→Free downgrade.

**Service:** `UpdateSubscription`, `ValidateSubscriptionChange`, `ScheduleSubscriptionChange`, `WalletPlanChangeAdjuster`, `UpgradeLocalToMollie`

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
      -> Webhook: handleSingleChargePaid() -> BillingInvoice + OverageCharged event
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

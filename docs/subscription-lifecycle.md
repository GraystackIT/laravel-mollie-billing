# Subscription Lifecycle

This document provides an overview of the subscription states and transitions in `laravel-mollie-billing`.

## States

| Status | Description |
|--------|-------------|
| `new` | Billable created, no subscription yet |
| `trial` | Free trial period, optionally with Mollie mandate |
| `active` | Paid subscription, recurring payments active |
| `past_due` | Payment failed, grace period before cancellation |
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

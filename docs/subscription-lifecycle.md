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

### Checkout (New Subscription)

```
User -> StartSubscriptionCheckout -> Mollie first payment
  -> Webhook: handleFirstPaymentPaid()
    -> CreateSubscription::handle()
      -> Create Mollie subscription (stores mollie_subscription_id)
      -> Set source=mollie, status=active
      -> Credit wallet quotas
    -> Create BillingInvoice
    -> SubscriptionCreated event
```

**Service:** `StartSubscriptionCheckout`, `CreateSubscription`

### Plan Change

See [Plan Changes](plan-changes.md) for the full deferred upgrade flow.

**Service:** `UpdateSubscription`, `ValidateSubscriptionChange`, `ScheduleSubscriptionChange`

### Recurring Payment

```
Mollie fires subscription webhook
  -> handleSubscriptionPaymentPaid()
    -> Validate expected vs actual amount
    -> Create BillingInvoice
    -> Recharge wallet quotas
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

**Service:** `CancelSubscription`

### Resubscribe

```
User -> ResubscribeSubscription::handle()
  -> Recreate Mollie subscription
  -> Set status=active, clear subscription_ends_at
  -> SubscriptionReactivated event
```

**Service:** `ResubscribeSubscription`

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
| `CreateSubscription` | Create Mollie subscription after first payment |
| `UpdateSubscription` | Plan changes (immediate + deferred) |
| `ValidateSubscriptionChange` | Centralized validation for plan changes |
| `ScheduleSubscriptionChange` | Schedule downgrades to end of period |
| `CancelSubscription` | Cancel subscription |
| `ResubscribeSubscription` | Reactivate cancelled subscription |
| `InvoiceService` | Create invoices and credit notes |
| `RefundInvoiceService` | Process refunds |
| `WalletUsageService` | Metered billing (debit/credit wallets) |
| `VatCalculationService` | VAT calculation based on country |
| `MollieWebhookController` | Handle all Mollie payment webhooks |

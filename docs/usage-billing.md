# Usage Billing

This document describes how metered usage (e.g. tokens, SMS) is tracked, renewed, and settled during plan changes.

## Architecture

Each usage type is backed by a [bavix/laravel-wallet](https://github.com/bavix/laravel-wallet) wallet on the billable model. Credits represent available units; debits represent consumption. A negative balance means overage.

| Component | Responsibility |
|-----------|----------------|
| `WalletUsageService` | Credit, debit, reset wallets, and purchased-balance tracking |
| `BillingPolicy` | Prorated usage excess calculations |
| `SubscriptionCatalogInterface` | Plan quotas, overage prices, rollover config, usage type names |
| `HasBilling` trait | `usedBillingQuota()`, `remainingBillingQuota()`, `purchasedBillingCredits()`, etc. |

## Configuration

### `usage_rollover`

Controls whether unused credits carry over to the next billing period.

```php
// config/mollie-billing.php
'usage_rollover' => env('BILLING_USAGE_ROLLOVER', false),
```

Can be overridden per plan:

```php
// config/mollie-billing-plans.php
'pro' => [
    'usage_rollover' => true, // overrides global default
    'intervals' => [
        'monthly' => [
            'included_usages' => ['Tokens' => 100, 'SMS' => 50],
            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
        ],
    ],
],
```

Resolution order: plan-level `usage_rollover` > global config > default `false`.

### Included usages & overage prices

Defined per plan per interval inside `intervals.{monthly|yearly}`:

- `included_usages` -- units credited to the wallet each period
- `usage_overage_prices` -- cost per unit when balance goes negative (cents)

Note: usage quotas are plan-scoped only. Addons do not contribute `included_usages`. See `SubscriptionCatalogInterface::includedUsage()` for details.

### Usage type names

Usage types can be given translated display names via `resources/lang/{locale}/usages.php`:

```php
// resources/lang/de/usages.php
return [
    'Tokens' => 'KI-Tokens',
    'SMS' => 'SMS-Nachrichten',
];
```

Resolved by `SubscriptionCatalogInterface::usageTypeName()` with `ucfirst($type)` as fallback.

## Credit Sources

A wallet balance can come from three sources:

| Source | Reason tag | Tracked by |
|--------|-----------|------------|
| **Plan quota** | `credit`, `subscription_renewal`, `plan_change_credit` | Wallet balance |
| **One-time orders** | `one_time_order:{productCode}` | `wallet.meta.purchased_balance` |
| **Coupon credits** | `coupon_credit` | `wallet.meta.purchased_balance` |

**Plan credits are consumed first.** Purchased credits (one-time orders + coupon credits) are consumed only after the plan quota is exhausted. This is tracked via a `purchased_balance` field in the wallet's `meta` JSON -- no schema migration required.

### Consumption priority

```
totalBalance = planCredits + purchasedCredits

When consuming:
  1. Deduct from plan credits first
  2. Only when plan credits reach 0, deduct from purchased credits

purchasedRemaining = max(0, min(purchasedBalance, currentWalletBalance))
```

## Renewal Behavior

On each billing period renewal (Mollie webhook payment or local subscription reset), the wallet is refilled based on the rollover setting:

| Rollover | Behavior | Method |
|----------|----------|--------|
| `false` (default) | Wallet is reset to zero, then credited with plan quota + remaining purchased credits | `WalletUsageService::resetAndCredit()` |
| `true` | Plan quota is added on top of current balance; `purchased_balance` is updated to reflect any consumption | `WalletUsageService::credit()` |

In both cases, purchased credits that were not consumed survive the renewal.

### Examples

**Without rollover, no purchased credits** (`usage_rollover = false`):

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 start | Credit 100 | 100 |
| Month 1 | Use 30 | 70 |
| Month 2 start | Reset + credit 100 | 100 |
| Month 2 | Use 0 | 100 |
| Month 3 start | Reset + credit 100 | 100 |

**Without rollover, with purchased credits:**

| Period | Action | Balance | Purchased |
|--------|--------|---------|-----------|
| Month 1 start | Credit 100 (plan) | 100 | 0 |
| Month 1 | Buy 500 tokens | 600 | 500 |
| Month 1 | Use 200 (100 plan + 100 purchased) | 400 | 400 |
| Month 2 start | Reset + credit 100 + 400 purchased | 500 | 400 |
| Month 2 | Use 50 (plan only) | 450 | 400 |
| Month 3 start | Reset + credit 100 + 400 purchased | 500 | 400 |

**With rollover** (`usage_rollover = true`):

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 start | Credit 100 | 100 |
| Month 1 | Use 30 | 70 |
| Month 2 start | Credit 100 (additive) | 170 |
| Month 2 | Use 0 | 170 |
| Month 3 start | Credit 100 (additive) | 270 |

## Plan Change: Usage Settlement

When a user changes plans mid-period, the old plan's usage is settled proportionally before the new plan's quota is applied. This prevents abuse (e.g. consuming an entire yearly quota in month 1, then downgrading).

### Formula

Purchased credits are separated from plan credits before any prorata calculation:

```
purchasedRemaining = max(0, min(purchasedBalance, currentBalance))
planOnlyBalance    = currentBalance - purchasedRemaining

elapsedFraction    = 1 - prorataFactor(periodStart, periodEnd)
proratedOldQuota   = round(oldIncluded * elapsedFraction)
excess             = max(0, proratedOldQuota - planOnlyBalance)
rolloverCredits    = rollover ? max(0, planOnlyBalance - oldIncluded) : 0
targetBalance      = newIncluded + rolloverCredits + purchasedRemaining - excess
```

If `targetBalance < 0`, the remainder is charged as overage via `ChargeUsageOverageDirectly`.

**Key:** `BillingPolicy::computeUsageOverageForPlanChange()` is the single source of truth for excess calculations. It receives `planOnlyBalance` (excluding purchased credits) so purchased credits are never prorated.

### Step-by-step

1. **Separate purchased credits** -- compute `purchasedRemaining` from wallet meta, derive `planOnlyBalance`
2. **Compute prorated old quota** -- how many plan units the user was entitled to use up to now
3. **Compute excess** -- if `planOnlyBalance` is below the prorated quota, the user consumed more plan credits than their fair share
4. **Compute rollover credits** -- (only when `usage_rollover = true`) credits carried from previous periods (plan-only, not purchased)
5. **Set wallet to new plan quota** -- the new plan starts fresh with its full `included_usages`
6. **Add rollover credits** -- carried plan credits are preserved
7. **Add purchased credits** -- purchased credits survive the plan change unchanged
8. **Subtract excess** -- excess from the old plan is offset against the combined balance
9. **Charge remainder** -- if the excess exceeds everything, the rest is charged as overage
10. **Update purchased_balance** -- capped at `min(purchasedRemaining, targetBalance)` to stay consistent

### Examples Without Rollover (no purchased credits)

| Scenario | Old Incl. | Time | Used | Balance | Prorated | Excess | New Incl. | Wallet | Overage |
|----------|-----------|------|------|---------|----------|--------|-----------|--------|---------|
| Normal usage | 1000/yr | 6mo (50%) | 300 | 700 | 500 | 0 | 600 | **600** | 0 |
| All consumed | 1000/yr | 6mo (50%) | 1000 | 0 | 500 | 500 | 600 | **100** | 0 |
| Heavy overage | 1000/yr | 6mo (50%) | 1100 | -100 | 500 | 600 | 600 | **0** | 0 |
| Excess > new quota | 1000/yr | 3mo (25%) | 1000 | 0 | 250 | 250 | 200 | **0** | 50 |
| Nothing used | 1000/yr | 6mo (50%) | 0 | 1000 | 500 | 0 | 600 | **600** | 0 |

### Examples With Purchased Credits

| Scenario | Old Incl. | Purchased | Time | Balance | Plan Balance | Excess | New Incl. | Wallet | Purchased After |
|----------|-----------|-----------|------|---------|-------------|--------|-----------|--------|----------------|
| Plan covers all | 1000 | 500 | 50% | 700 | 200 | 300 | 500 | **700** | 500 |
| Plan exhausted | 1000 | 500 | 50% | 300 | 0 | 500 | 500 | **300** | 300 |
| Downgrade to free | 1000 | 500 | 50% | 800 | 300 | 200 | 0 | **300** | 300 |

### Examples With Rollover

| Scenario | Old Incl. | Time | Balance | Rollover | Prorated | Excess | New Incl. | Wallet | Overage |
|----------|-----------|------|---------|----------|----------|--------|-----------|--------|---------|
| Credits carried | 100/mo | 15d (50%) | 250 | 150 | 50 | 0 | 80 | **230** | 0 |
| Rollover exhausted | 100/mo | 15d (50%) | 10 | 0 | 50 | 40 | 80 | **40** | 0 |
| Large rollover, downgrade | 100/mo | 15d (50%) | 400 | 300 | 50 | 0 | 50 | **350** | 0 |

### Preview

The `PreviewService` includes detailed usage settlement data in the `usageChanges` array:

```php
$preview = app(PreviewService::class)->previewPlanChange($billable, 'basic', 'monthly');

$preview['usageChanges']['Tokens'] = [
    'current' => 1000,              // old plan quota
    'new' => 600,                   // new plan quota
    'diff' => -400,
    'actually_used' => 500,         // plan units consumed this period
    'prorated_old_quota' => 500,    // entitled plan usage for elapsed time
    'excess' => 0,                  // excess over prorated plan entitlement
    'rollover_credits' => 0,
    'purchased_remaining' => 200,   // purchased credits that survive
    'offset_by_new_plan' => 0,      // excess absorbed by new quota + rollover + purchased
    'effective_new_quota' => 800,   // what the user will actually have
    'unresolved_overage' => 0,      // charged as overage if > 0
    'overage_unit_price_net' => 10,
    'overage_total_net' => 0,
];

$preview['usageOverageChargeNet'];   // total usage overage charge (cents)
$preview['usageOverageChargeGross']; // including VAT
```

## Display Methods

The `HasBilling` trait provides display-ready methods:

| Method | Description |
|--------|-------------|
| `includedBillingQuota($type)` | Plan's included quota from catalog |
| `usedBillingQuota($type)` | Plan units consumed this period (excludes purchased credits) |
| `remainingBillingQuota($type)` | Total units still available (plan + purchased) |
| `purchasedBillingCredits($type)` | Remaining purchased credits (one-time orders + coupons) |
| `billingOverageCount($type)` | Overage units (negative balance magnitude) |
| `billingOveragePrice($type)` | Configured overage unit price from catalog |
| `hasBillingQuotaLeft($type)` | `true` if balance > 0 or mandate exists (allows overage) |

### Usage Meter UI

The `<livewire:mollie-billing::components.usage-meter>` component renders a stacked progress bar with four segments:

```
[plan consumed | remaining included | purchased consumed | remaining purchased]
     green/amber/red     (empty)          dark sky              light sky
```

Embedded in the dashboard (pre-computed props):

```blade
<livewire:mollie-billing::components.usage-meter
    :type="'Tokens'"
    :label="'KI-Tokens'"
    :included="1000"
    :balance="490"
    :purchased-balance="500"
/>
```

Standalone (resolves billable automatically):

```blade
<livewire:mollie-billing::components.usage-meter type="Tokens" />
```

## Events

| Event | When |
|-------|------|
| `WalletCredited` | Credits added (renewal with rollover, initial seeding, coupon bonus, one-time order) |
| `WalletReset` | Wallet reset to zero then refilled (renewal without rollover, plan change) |
| `UsageLimitReached` | Wallet crosses zero (first overage unit) |
| `OverageCharged` | Overage payment created via Mollie |

## Artisan Commands

```bash
php artisan billing:sync-purchased-balance
```

Backfills `purchased_balance` on all wallets from transaction history (one-time orders + coupon credits). Run once after upgrading to a version that tracks purchased credits separately. Safe to re-run.

## Extension Points

- **Custom catalog:** Bind your own `SubscriptionCatalogInterface` to control quotas, overage prices, and rollover behavior from a database
- **Custom validation:** Bind your own `ValidateSubscriptionChange` to customize overage handling during plan changes
- **Event listeners:** React to `WalletReset`, `WalletCredited`, `OverageCharged` for logging, notifications, etc.
- **Usage type names:** Add translations to `resources/lang/{locale}/usages.php` or override `usageTypeName()` in a custom catalog

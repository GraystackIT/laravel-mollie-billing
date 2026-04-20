# Usage Billing

This document describes how metered usage (e.g. tokens, SMS) is tracked, renewed, and settled during plan changes.

## Architecture

Each usage type is backed by a [bavix/laravel-wallet](https://github.com/bavix/laravel-wallet) wallet on the billable model. Credits represent available units; debits represent consumption. A negative balance means overage.

| Component | Responsibility |
|-----------|----------------|
| `WalletUsageService` | Credit, debit, and reset wallets |
| `BillingPolicy` | Prorated usage excess calculations |
| `SubscriptionCatalogInterface` | Plan quotas, overage prices, rollover config |
| `HasBilling` trait | `usedBillingQuota()`, `remainingBillingQuota()`, etc. |

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

## Renewal Behavior

On each billing period renewal (Mollie webhook payment or local subscription reset), the wallet is refilled based on the rollover setting:

| Rollover | Behavior | Method |
|----------|----------|--------|
| `false` (default) | Wallet is reset to zero, then credited with the full plan quota | `WalletUsageService::resetAndCredit()` |
| `true` | Plan quota is added on top of the current balance | `WalletUsageService::credit()` |

### Examples

**Without rollover** (`usage_rollover = false`):

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 start | Credit 100 | 100 |
| Month 1 | Use 30 | 70 |
| Month 2 start | Reset + credit 100 | 100 |
| Month 2 | Use 0 | 100 |
| Month 3 start | Reset + credit 100 | 100 |

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

```
elapsedFraction    = 1 - prorataFactor(periodStart, periodEnd)
proratedOldQuota   = round(oldIncluded * elapsedFraction)
excess             = max(0, proratedOldQuota - currentBalance)
rolloverCredits    = rollover ? max(0, currentBalance - oldIncluded) : 0
targetBalance      = newIncluded + rolloverCredits - excess
```

If `targetBalance < 0`, the remainder is charged as overage via `ChargeUsageOverageDirectly`.

**Key:** `BillingPolicy::computeUsageOverageForPlanChange()` is the single source of truth for excess calculations.

### Step-by-step

1. **Compute prorated old quota** -- how many units the user was entitled to use up to now
2. **Compute excess** -- if current balance is below the prorated quota, the user consumed more than their fair share
3. **Compute rollover credits** -- (only when `usage_rollover = true`) credits carried from previous periods
4. **Set wallet to new plan quota** -- the new plan starts fresh with its full `included_usages`
5. **Add rollover credits** -- carried credits are preserved
6. **Subtract excess** -- excess from the old plan is offset against the new quota
7. **Charge remainder** -- if the excess exceeds the new quota + rollover, the rest is charged as overage

### Examples Without Rollover

| Scenario | Old Incl. | Time | Used | Balance | Prorated | Excess | New Incl. | Wallet | Overage |
|----------|-----------|------|------|---------|----------|--------|-----------|--------|---------|
| Normal usage | 1000/yr | 6mo (50%) | 300 | 700 | 500 | 0 | 600 | **600** | 0 |
| All consumed | 1000/yr | 6mo (50%) | 1000 | 0 | 500 | 500 | 600 | **100** | 0 |
| Heavy overage | 1000/yr | 6mo (50%) | 1100 | -100 | 500 | 600 | 600 | **0** | 0 |
| Excess > new quota | 1000/yr | 3mo (25%) | 1000 | 0 | 250 | 250 | 200 | **0** | 50 |
| Nothing used | 1000/yr | 6mo (50%) | 0 | 1000 | 500 | 0 | 600 | **600** | 0 |

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
    'actually_used' => 500,         // units consumed this period
    'prorated_old_quota' => 500,    // entitled usage for elapsed time
    'excess' => 0,                  // excess over prorated entitlement
    'rollover_credits' => 0,
    'offset_by_new_plan' => 0,      // excess absorbed by new quota
    'effective_new_quota' => 600,   // what the user will actually have
    'unresolved_overage' => 0,      // charged as overage if > 0
    'overage_unit_price_net' => 10,
    'overage_total_net' => 0,
];

$preview['usageOverageChargeNet'];   // total usage overage charge (cents)
$preview['usageOverageChargeGross']; // including VAT
```

## Display Methods

The `HasBilling` trait provides display-ready methods:

| Method | Description | Rollover behavior |
|--------|-------------|-------------------|
| `includedBillingQuota($type)` | Plan's included quota from catalog | Same |
| `usedBillingQuota($type)` | Units consumed this period | Capped at `included` when rollover is off |
| `remainingBillingQuota($type)` | Units still available | Capped at `included` when rollover is off; uncapped when on |
| `billingOverageCount($type)` | Overage units (negative balance) | Same |

When `usage_rollover = false`, `used + remaining = included` always holds. When `usage_rollover = true`, `remaining` may exceed `included` (carried credits).

## Events

| Event | When |
|-------|------|
| `WalletCredited` | Credits added (renewal with rollover, initial seeding, coupon bonus) |
| `WalletReset` | Wallet reset to zero then refilled (renewal without rollover, plan change) |
| `UsageLimitReached` | Wallet crosses zero (first overage unit) |
| `OverageCharged` | Overage payment created via Mollie |

## Extension Points

- **Custom catalog:** Bind your own `SubscriptionCatalogInterface` to control quotas, overage prices, and rollover behavior from a database
- **Custom validation:** Bind your own `ValidateSubscriptionChange` to customize overage handling during plan changes
- **Event listeners:** React to `WalletReset`, `WalletCredited`, `OverageCharged` for logging, notifications, etc.

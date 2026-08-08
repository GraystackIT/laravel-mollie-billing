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

### Rollover (per usage type)

Controls whether unused credits carry over to the next billing period. Configured **per usage type**, not per plan:

```php
// config/mollie-billing-plans.php
'usage_types' => [
    'Tokens' => ['rollover' => true],
    'SMS'    => ['rollover' => false],
],
```

Usage types without an explicit entry fall back to the global default:

```php
// config/mollie-billing.php
'usage_rollover_fallback' => env('BILLING_USAGE_ROLLOVER_FALLBACK', false),
```

Resolution order: `usage_types.<type>.rollover` (when boolean) > `usage_rollover_fallback` > `false`.

### Included usages & overage prices

Defined per plan per interval inside `intervals.{monthly|yearly}`:

- `included_usages` -- units credited to the wallet each period
- `usage_overage_prices` -- cost per unit when balance goes negative (cents)

Note: usage quotas are plan-scoped only. Addons do not contribute `included_usages`. See `SubscriptionCatalogInterface::includedUsage()` for details.

The `included_usages` and `usage_overage_prices` keys must line up — a quota without a price is hard-capped at the quota (nothing could be collected past it), and a price without a quota means the wallet starts at 0. Run `php artisan billing:check-config` to detect such mismatches (it also verifies that any `products.*.usage_type` exists in some plan's usage types).

When a quota is *meant* to have no overage (typical for internal and supplier plans), declare it as `'Tokens' => false` in `usage_overage_prices` rather than leaving the key out. Runtime behaviour is identical — `usageOveragePrice()` returns `null` either way, and the quota is hard-capped — but the explicit `false` tells `check-config` that the missing price is deliberate, so it stops warning. See [Declaring a quota with no overage](configuration.md#declaring-a-quota-with-no-overage).

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

These names are what the plan cards show — both in the checkout plan step and in the portal plan-change view. Each entitlement is one line there (`3 seats incl.`, `100 Tokens incl.`, …) with the per-extra-unit price right-aligned on the same line (`+€0.14/Tokens`); the full wording (`€0.14 per extra Tokens`) is kept as its hover title. The name doubles as the billed unit behind the slash, so prefer a short, singular-reading label (`AI-Token` over `AI-Tokens consumed`).

### Casing of usage-type identifiers

Usage-type strings (`tokens`, `Tokens`, `TOKENS`, `sms`, `SMS`, …) are resolved **case-insensitively** throughout the package. You can pick whatever casing reads best in your config and stay consistent on the call site -- the package will not create duplicate wallets or miss quota lookups when callers use a different casing.

Where the case-insensitive layer lives:

| Layer | Method | Behaviour |
|-------|--------|-----------|
| `HasBilling` trait | `getWallet($slug)`, `hasWallet($slug)`, `createWallet($data)` | Wraps the bavix defaults. First tries an exact slug match, then falls back to `strcasecmp` against existing wallet rows. `createWallet` reuses an existing case-insensitive match instead of inserting a duplicate row. |
| `ConfigSubscriptionCatalog` | `includedUsage($plan, $interval, $type)`, `usageOveragePrice($plan, $interval, $type)` | Looks up `included_usages` / `usage_overage_prices` first by exact key, then case-insensitively. |

Why a wrapper, not a bavix setting: `bavix/laravel-wallet` does not ship a case-insensitive option -- its `HasWallets` trait does a strict `where('slug', $slug)` lookup. The trait override in `HasBilling` is the single point that normalises the behaviour for the whole package.

Recommendation: **pick one casing per project and keep it consistent in `config/mollie-billing-plans.php`**. The case-insensitive layer is a safety net for mixed call sites (e.g. UI passing `tokens` while config has `Tokens`); it is not an excuse for inconsistent naming.

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

### An unpaid overage debt survives the renewal

A negative balance is unpaid debt, and the reset does **not** forgive it while the charge for exactly those units is still settling. It used to: the reset zeroed the debt, then the overage payment's webhook credited the charged quantity on top, so every overage cycle handed the customer that quantity as free units. With SEPA the renewal usually settles first, because Mollie creates the renewal about two days ahead while the overage charge is created the day before it.

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 | Quota 100, used 137 | −37 |
| Month 1, day 29 | Overage charge created for 37 units (unsettled) | −37 |
| Month 2 start | Reset **keeps the debt**, credits 100 | 63 |
| Month 2, day 2 | Overage payment settles, credits 37 | 100 |

Decided per usage type, from the pending-charge snapshot: a type the charge did not cover gets its ordinary reset, and a cycle that already gave up (status `failed`) is not "in flight" — that debt was written off with the past-due transition, so preserving it would strand the wallet negative forever.

### Examples

**Without rollover, no purchased credits** (`usage_types.<type>.rollover = false`):

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

**With rollover** (`usage_types.<type>.rollover = true`):

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 start | Credit 100 | 100 |
| Month 1 | Use 30 | 70 |
| Month 2 start | Credit 100 (additive) | 170 |
| Month 2 | Use 0 | 170 |
| Month 3 start | Credit 100 (additive) | 270 |

## Local subscriptions (free plans, access grants)

Local subscriptions (`subscription_source = local`) have no Mollie mandate, so **no money can flow**. This is by design — see [README.md](../README.md#local-subscriptions) for the full matrix of what is and isn't allowed.

For metered usage specifically:

| Behavior | Local subscription |
|---|---|
| Plan-quota credit on activation | yes — `included_usages` from the plan/interval are credited like any other source |
| Plan-quota refill on renewal | yes — `PrepareUsageOverageJob` resets the wallet at each period boundary (`resetLocalQuota`) |
| Consumption past zero (negative balance) | **no** — since no overage could ever be collected, `WalletUsageService::debit()` hard-caps at the remaining balance and throws `UsageLimitExceededException` (plus a `UsageLimitReached` event). A booking that fits into the remaining balance always goes through — including one that exceeds `included_usages` because purchased credits raised the balance. |
| **Charging an overage** | **never** — no mandate, so `PrepareUsageOverageJob` never routes Local billables to `ChargeUsageOverageDirectly`. Negative balances can only arise from a plan change or an admin adjustment, and are reset at the next period boundary. |
| Buying purchased credits via one-time orders | **opt-in** via `config('mollie-billing.local_subscription.allow_one_time_orders')`. Default `false` — `StartOneTimeOrderCheckout` throws `LocalSubscriptionCannotPurchaseProductsException` and the products page hides the buy buttons. Set to `true` if your free plan is intended as a default tier monetised through token packs etc. |

In short: a Local user is capped at whatever the wallet actually holds, not at the plan quota. Purchased credits count towards that balance, so with `local_subscription.allow_one_time_orders` enabled a Local user can consume well past `included_usages` — what they can never do is go negative. The app has to handle `UsageLimitExceededException`: `hasBillingQuotaLeft($type)` only answers "is there any balance left (or a chargeable overage)" and reserves nothing, so a booking larger than the remaining balance still throws even right after the gate returned `true`. If your business model requires charging these users for overages, do not make that plan free — price it at the lowest non-zero amount so Mollie collects a mandate during checkout. If you want free users to top up credits manually, enable `local_subscription.allow_one_time_orders`.

The same applies to `access_grant` coupon redemptions and `Mollie → Free` downgrades, which both produce a Local subscription with the same constraints.

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
4. **Compute rollover credits** -- (only when rollover is enabled for the usage type) credits carried from previous periods (plan-only, not purchased)
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
| `hasBillingQuotaLeft($type)` | `true` if the balance is positive, or if an overage on this type is chargeable |
| `canChargeBillingOverage($type)` | Whether an overage could actually be billed: non-Local, mandate present, `allowsBillingOverage()`, and an overage price > 0 for this type. Also drives the hard cap in `WalletUsageService::debit()`, so the gate and the enforcement always agree. |

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

### Usage History Statistics

The portal's usage history page (`livewire/billing/usage-history`) reports **real consumption only**. Every wallet transaction carries a `reason` in its meta, and bookkeeping reasons — plan quota top-ups, period resets, plan-change adjustments and purchased credits — are excluded from the total usage, daily average, peak day, trend sparkline and top usage type. The transaction table below the statistics still lists the full wallet ledger, including bookkeeping rows.

The classification lives on `WalletUsageService`:

```php
WalletUsageService::isUsageReason('usage');                     // true
WalletUsageService::isUsageReason('api_call');                  // true (custom reasons count as usage)
WalletUsageService::isUsageReason(null);                        // true
WalletUsageService::isUsageReason('period_reset');              // false
WalletUsageService::isUsageReason('subscription_renewal');      // false
WalletUsageService::isUsageReason('one_time_order:pack-500');   // false

// Constrain a transaction query to real consumption:
WalletUsageService::scopeRealUsage(
    Transaction::query()->whereIn('wallet_id', $walletIds)
)->where('type', 'withdraw');
```

Non-usage reasons are listed in `WalletUsageService::NON_USAGE_REASONS`; dynamic ones are matched by prefix via `NON_USAGE_REASON_PREFIXES` (e.g. `one_time_order:`). Any reason not on those lists — including custom reasons passed to `recordBillingUsage($type, $qty, $reason)` — counts as consumption.

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

Backfills `purchased_balance` on all wallets from transaction history (one-time orders + coupon credits). Run once after upgrading to a version that tracks purchased credits separately. Safe to re-run — `--dry-run` shows what would change, `--force` skips the prompt.

Only purchases made **since the last renewal** count; anything older was already accounted for by that renewal's own carry-over computation. The period boundary is the latest `period_reset` *or* `period_credit` transaction: `period_reset` is only written when the balance was non-zero, so a wallet that renewed at exactly 0 had no anchor at all and the window reached back into earlier periods, resurrecting purchased credits the customer had long consumed — which every later reset then re-credited on top of the new quota.

## Extension Points

- **Custom catalog:** Bind your own `SubscriptionCatalogInterface` to control quotas, overage prices, and rollover behavior from a database
- **Custom validation:** Bind your own `ValidateSubscriptionChange` to customize overage handling during plan changes
- **Event listeners:** React to `WalletReset`, `WalletCredited`, `OverageCharged` for logging, notifications, etc.
- **Usage type names:** Add translations to `resources/lang/{locale}/usages.php` or override `usageTypeName()` in a custom catalog

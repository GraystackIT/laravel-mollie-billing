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

The two maps combine, and every combination means something:

| `included_usages` | `usage_overage_prices` | Behaviour |
|---|---|---|
| `100` | `5` | Allowance of 100, then 5 cents per unit. The wallet goes negative and the period-end pass charges it. |
| `100` | absent or `false` | **Hard cap.** `debit()` refuses to go past the allowance and raises `UsageLimitReached` / throws — nothing could collect a negative balance, so none is created. |
| absent or `0` | `5` | **Pay-per-use.** No allowance; every unit is billed. The wallet only ever goes negative, and the period-end charge, the dunning ladder and the plan-change rebalance all handle it — they iterate wallets, not quotas. |
| absent | absent | The type is not sold on this plan. Debits are capped at whatever balance exists (purchased credits only). |

Run `php artisan billing:check-config` to check them: it reports a quota without a price, a price without a quota (naming both readings), and a type declared on one interval of a plan but not the other. It also verifies that any `products.*.usage_type` exists in some plan's usage types.

**Quotas are per (plan, interval) with no fallback**, deliberately — a yearly allowance is not twelve monthly ones. The cost is a config shape with no symptom until somebody switches interval: a type declared under `monthly` and forgotten under `yearly` resolves to quota 0 there, and with no overage price on that side the hard cap turns the customer's next metered call into an exception. `check-config` warns about exactly this shape.

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

These names are what the plan cards show — both in the checkout plan step and in the portal plan-change view. A type declared with `usage_types.<type>.visible => false` is left off those cards and nowhere else: it is credited, metered, capped and charged like any other, and the usage meters, the plan-change preview and invoices still name it. See [Hidden usage types](configuration.md#hidden-usage-types). Each entitlement is one line there (`3 seats incl.`, `100 Tokens incl.`, …) with the per-extra-unit price right-aligned on the same line (`+€0.14/Tokens`); the full wording (`€0.14 per extra Tokens`) is kept as its hover title. The name doubles as the billed unit behind the slash, so prefer a short, singular-reading label (`AI-Token` over `AI-Tokens consumed`).

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

### A trial is credited a prorated quota

A trial collects no money, so `MandateOnlyPaymentHandler` credits only the share of the
period quota the trial's own length represents:

```
grant = ceil(included × trialDays ÷ intervalDays)      // 30 days per month, 365 per year
```

A 14-day trial on a monthly plan with 100 included units is credited 47, not 100. What was
granted is recorded under `subscription_meta.trial_quota` and read back by
`grantedBillingQuota($type)` — which is what every meter, percentage and threshold
measures against for the duration of the trial. Do not compare a trial wallet against
`includedBillingQuota()`: the part that was never credited would read as consumption.

**When the trial ends**, a full period has been paid for and the wallets get that
period's quota — the ordinary renewal refill (`WebhookSupport::creditPeriodQuota()`),
reason `subscription_trial_conversion`, and the grant record is dropped. Both routes out
of a trial go through it: the first recurring charge (`SubscriptionPaymentHandler`) and a
change that is charged mid-trial (`ProrataChargeHandler` — a plan switch, an addon, or
just a seat). The plan-change adjuster deliberately does **not** run for the second: its
baseline is a period that was paid for and partly consumed, which a trial's is neither.

**A plan change that leaves the trial running** rebalances against the grant instead:
`WalletPlanChangeAdjuster` reads the old side from the recorded grant and prorates the new
plan's quota to the same trial length, then rewrites the record. Without that, the units
the trial was never credited counted as consumption — deducted from the new plan's quota
and, past that, billed as overage nobody used.

### An unpaid overage debt survives the renewal

A negative balance is unpaid debt, and the reset does **not** forgive it while the charge for exactly those units is still settling. It used to: the reset zeroed the debt, then the overage payment's webhook credited the charged quantity on top, so every overage cycle handed the customer that quantity as free units. With SEPA the renewal usually settles first, because Mollie creates the renewal about two days ahead while the overage charge is created the day before it.

| Period | Action | Balance |
|--------|--------|---------|
| Month 1 | Quota 100, used 137 | −37 |
| Month 1, day 29 | Overage charge created for 37 units (unsettled) | −37 |
| Month 2 start | Reset **keeps the debt**, credits 100 | 63 |
| Month 2, day 2 | Overage payment settles, credits 37 | 100 |

A cycle that already gave up (status `failed`) is not "in flight", and its debt is no longer on the wallet at all — see [A debt nobody could collect stands, and the renewals try again](#a-debt-nobody-could-collect-stands-and-the-renewals-try-again).

### A renewal never zeroes a debt that is still owed

The period-end sweep has one chance to charge a period's overage: the night before `nextBillingDate()`. Whenever that night was missed, the renewal used to find a negative balance with nothing in flight and reset it to the new quota — the overage was given away. Three ordinary ways to miss it:

- **A trial.** Its `nextBillingDate()` is mandate capture plus one interval; a 14-day trial converts long before that, and `debit()` allows overage throughout because the billable holds a mandate.
- **A renewal that settles ahead of the sweep night.** Routine under SEPA Direct Debit for as long as the period anchor was the previous renewal's `paidAt`, which trails Mollie's fixed collection date by a lag that varies with weekends and bank holidays — a shorter lag this period put the webhook before the sweep. The anchor is now the charge's `createdAt`, the cycle date itself, so the sweep night precedes the charge again; a charge Mollie creates early, or a moved cadence, can still get ahead of it.
- **A night the sweep did not run.**

So the renewal collects for itself, in two layers:

1. `SubscriptionPaymentHandler::paid()` calls `WebhookSupport::collectOutstandingOverage()` **before** the refill. It goes through `ChargeUsageOverageDirectly::handle()`, whose in-flight guard makes it a no-op whenever the sweep already charged. Once the charge exists, its snapshot is what the reset reads, and everything after that is the table above.
2. `WebhookSupport::shouldPreserveDebt()` decides what the reset may zero: a debt a charge is collecting, **or** a negative balance that can still be collected (a mandate, and either an overage price on the current plan or a recorded price — see [Restored excess keeps its price](#restored-excess-keeps-its-price)). The second half covers a charge attempt that failed before it could write a snapshot. (The paid trial conversion in `ProrataChargeHandler` runs step 1 as well, while the billable is still on the plan the trial ran on — so trial overage is billed at that plan's price, not carried into the new plan's quota.) A debt carried this way is paid out of the new period's quota; if it exceeds the quota, the wallet stays negative and the next sweep charges the remainder.

| Period | Action | Balance |
|--------|--------|---------|
| Trial (grant 10) | Used 60 | −50 |
| Trial end | First recurring charge settles → overage charge created for 50 units | −50 |
| Trial end | Reset keeps the debt, credits the full quota of 20 | −30 |
| A few days later | Overage payment settles, credits 50 | 20 |

A balance nothing could ever bill — no mandate, or no overage price and no recorded one — still gets the ordinary reset.

### A period Mollie does not charge for, and the hand-over back

While a discount covers the whole recurring amount the charge is deferred and no renewal webhook arrives, so `PrepareUsageOverageJob` (case A0) turns the period over itself: collect the ending period's overage, move the anchor to the boundary, refill. When the deferral ends, the webhook takes over again — and the two must not both credit the same period.

A deferral does not have to end on a period boundary. Since 0.5.0 a recurring coupon's window counts billing periods, so its deferral ends on a boundary (see [coupons.md](coupons.md)). But a marker written before that keeps its old date — `N × 30 + 2` days after it was applied: three monthly periods from 10 January defer the charge to 12 April, two days after the boundary of 10 April — and a period extension adds days. Refilling on the 10th and again when the charge settles on the 12th credited one period twice, and a rollover type kept the second quota for good.

So a stretch between a boundary and the returning charge that is **shorter than half an interval** is not a period of its own. The anchor still moves (the lapsed-period guard stays quiet), the overage is still collected, but the quota comes with the charge's own renewal. A longer stretch is a period and is refilled. A plan waiver's deferral ends on a boundary, where the question does not arise.

| Date | Event | Quota credited |
|------|-------|----------------|
| 10 Feb, 10 Mar | Boundary under the coupon (nightly pass) | yes |
| 10 Apr | Boundary, charge returns on 12 Apr (nightly pass) | **no** — anchor moves, overage collected |
| 12 Apr | First real charge (renewal webhook) | yes |

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
| Plan-quota refill on renewal | yes — `PrepareUsageOverageJob` resets the wallet at each period boundary (`resetLocalQuota`). Not at a boundary the subscription ends on: an access grant of exactly two months is two periods, and is not refilled the night before it expires |
| Consumption past zero (negative balance) | **no** — since no overage could ever be collected, `WalletUsageService::debit()` hard-caps at the remaining balance and throws `UsageLimitExceededException` (plus a `UsageLimitReached` event). A booking that fits into the remaining balance always goes through — including one that exceeds `included_usages` because purchased credits raised the balance. |
| **Charging an overage** | **never for usage on the Local plan** — it is hard-capped. Negative balances can only arise from a plan change or an admin adjustment, and are reset at the next period boundary. The one exception is plan-change excess that carries a recorded price (see [Restored excess keeps its price](#restored-excess-keeps-its-price)): if the mandate survived the downgrade, the period-boundary pass charges it before the reset and the reset keeps the debt until that payment settles. |
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
actuallyUsed       = max(0, oldIncluded - planOnlyBalance)
excess             = max(0, actuallyUsed - proratedOldQuota)
rolloverCredits    = rollover ? max(0, planOnlyBalance - oldIncluded) : 0
targetBalance      = newIncluded + rolloverCredits + purchasedRemaining - excess
```

If `targetBalance < 0`, the remainder is charged as overage via `ChargeUsageOverageDirectly`.

**Key:** `BillingPolicy::computeUsageOverageForPlanChange()` is the single source of truth for excess calculations. It receives `planOnlyBalance` (excluding purchased credits) so purchased credits are never prorated.

### Restored excess keeps its price

The unresolved remainder is consumption on the **old** plan, so it is priced with the old plan's overage price — that is the number the preview shows. When the charge for it cannot be created at once (the overage slot is occupied by a period-end charge that is still settling, or the call fails before anything was posted), the units go back on the wallet as a negative balance for the next sweep.

A negative balance is normally priced when it is charged, from the plan the billable is on *then* — the new one. So the restored units carry their price with them, as `deferred_overage` entries in the wallet's `meta`:

```php
$wallet->meta['deferred_overage'] = [
    ['quantity' => 97, 'unit_price_net' => 2],   // old plan's price per unit
];
```

`ChargeUsageOverageDirectly` bills these first and at that price, as their own line; whatever the wallet owes beyond them is ordinary overage at the current plan's price. Without the record, 97 units quoted at 2 ct were billed at the smaller plan's 9 ct, and not at all when the new plan sells no overage.

The record never outlives the debt it describes:

- every reader sees it clamped to the wallet's current negative balance, oldest entry first — a credit that pays the debt off ends it;
- a settled charge removes exactly the units it billed (`SingleChargeHandler` → `WalletUsageService::consumeDeferredOverage()`), so usage that accrued while the payment was settling is not re-labelled with the old price;
- a reset that forgives the debt drops it, a reset that preserves the debt keeps what is still owed;
- a second plan change carries it like an in-flight charge's units rather than reading it as consumption on the plan being left.

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
| Excess > new quota | 1000/yr | 3mo (25%) | 1000 | 0 | 249 | 751 | 200 | **0** | 551 |
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
| `grantedBillingQuota($type)` | What was actually credited for the current period — the same number on a paying subscription, the prorated share on a trial. Measure meters and percentages against this, not against the line above |
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
    :trial="false"
/>
```

Pass `grantedBillingQuota($type)` as `:included`, not the plan's period quota — see
[A trial is credited a prorated quota](#a-trial-is-credited-a-prorated-quota). `:trial`
only labels the footer ("Trial: 7 of 10 remaining" instead of "Plan: …"); the standalone
form below resolves both from the billable itself.

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

### Reason labels

A reason is an internal identifier, and the transaction table must not print it as one.
`Support\UsageReason::label()` is the single resolver:

```php
UsageReason::label('subscription_trial_start');   // "Trial quota"       — billing::portal.usage_reasons.*
UsageReason::label('one_time_order:pack-500');    // "Purchase: 500 Pack" — dynamic, rendered with the product name
UsageReason::label('some_new_reason');            // "Some New Reason"    — title-cased fallback
UsageReason::label('Brave Image Search');         // unchanged            — free text an app or admin wrote
UsageReason::label(null);                         // "Usage"
```

Add a key under `usage_reasons` in `resources/lang/{locale}/portal.php` for any reason your
app writes often enough to deserve wording of its own; anything without one still renders
readably rather than as `snake_case`.

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

It replays the wallet's whole transaction history rather than summing a window: purchases add, and every renewal **and every plan change** clamps the running total against the balance it found — exactly what `resetAndCredit()` and `WalletPlanChangeAdjuster` do. A plan change was not a clamp point at first, so a 500-unit pack of which 490 had been used before an upgrade came back as 500 purchased units out of the new plan's quota. A window cannot answer this, because `resetAndCredit()` deliberately *carries* an unconsumed purchase forward by re-persisting `purchased_balance`, so a pack bought last period and still unused has no transaction in this one. Summing the window produced 0 and this command wrote it, after which the next renewal withdrew the whole balance and credited only the plan quota — credits the customer had paid for, gone. Rollover wallets failed the other way: their renewal writes `subscription_renewal_rollover`, which matched no anchor at all, so the reconciliation ran against the wallet's lifetime and relabelled plan units as purchased.

## Extension Points

- **Custom catalog:** Bind your own `SubscriptionCatalogInterface` to control quotas, overage prices, and rollover behavior from a database
- **Custom validation:** Bind your own `ValidateSubscriptionChange` to customize overage handling during plan changes
- **Event listeners:** React to `WalletReset`, `WalletCredited`, `OverageCharged` for logging, notifications, etc.
- **Usage type names:** Add translations to `resources/lang/{locale}/usages.php` or override `usageTypeName()` in a custom catalog

### Only one overage charge at a time

`subscription_meta.usage_overage` is a **single slot**, and the wallets deliberately stay
negative until the charge covering them settles — with SEPA that is days, not seconds. So
every entry point that rebuilds line items from the wallets has to ask first whether a
payment is already in flight, or the same debt goes to Mollie twice: each settles into its
own invoice (the webhook's idempotency is per payment id) and each credits the units again,
while the second charge's snapshot *overwrites* the first — inheriting its `created_at`, its
dunning cycle and its counted-payments list, and leaving the overwritten payment invisible to
`CleanupStalePendingOverageChargeJob`, which only ever polls the id currently in the slot.

`ChargeUsageOverageDirectly::handle()`, `handleExplicit()` (the plan-change path) and
`PrepareUsageOverageJob::safeCharge()` all carry that guard. The one exception is
`retry()`: it is driven by the very snapshot the guard keys off, so applying it there would
make every retry a permanent no-op. The guard releases on settlement, not on a timer — an
invoice for the pending payment id means the money arrived and whatever debt is left is new.

The path this used to be missing on is the ordinary one: the nightly period-end sweep creates
the charge, the customer cancels immediately the next morning
(`CancelSubscription::handle(immediately: true)`, reached from the portal *and* from the
past-due auto-cancel in `PrepareUsageOverageJob`), and Mollie collected both.

### A retry cannot buy a second payment

The in-flight guard above keys off the persisted `payment_id`, and there is one window where
that id does not exist yet: `charge()` writes the snapshot, calls Mollie, and only then stores
the id. A call that throws *after* Mollie created the payment — a read timeout on the response
is the ordinary case, not an exotic one — leaves a `pending` snapshot with `payment_id: null`,
which `anotherChargeIsInFlight()` deliberately does not treat as in flight. So
`RetryUsageOverageChargeJob` came back 60 seconds later and charged the same debt again.

The charge therefore carries an **idempotency key** that is stable across the retries of one
dunning cycle:

```
usage-overage:{billable_type}:{billable_id}:{cycle_started_at}:{gross_in_cents}
```

Mollie replays the original response for a repeated key instead of acting twice, so the retry
is answered with the payment that already exists and learns the id it lost. Each part earns
its place:

| Part | Why |
|---|---|
| `cycle_started_at` | The snapshot's `created_at`, which `persistPendingCharge()` inherits while a cycle continues and only re-mints after a cycle has failed. So p1 → p2 → p3 of one cycle share a key, and the next cycle's legitimate charge does not. |
| `gross_in_cents` | Mollie answers **400** to a reused key whose parameters differ. If more usage accrued between two attempts the amount moves, and this makes that a new key rather than a rejected request. |
| the billable | Two customers can never collide. |

Mollie caches a key for **one hour**. That covers this ladder — `backoff()` is
`[60, 300, 900]`, so all three retries land inside ~21 minutes — and deliberately not the
nightly sweep, which is a day later and a genuinely new decision about a debt that is still
owed. Set through `MollieCall::send($request, $key)`, which also clears it afterwards: the
SDK clears the key from a *response* middleware, and a throw never reaches that, so a failed
call would otherwise leave its key on the shared client for the next mutating request to
carry.

### …and beyond that hour, Mollie is asked

The key protects the retry ladder. It cannot protect the sweep that runs a day later, and that
is the same debt: a `CreatePaymentRequest` that left the process without an answer leaves a
`pending` snapshot with `payment_id: null`, which is indistinguishable from "we never asked".
Both readings cost money — silence the debt, or charge it twice — so the state is marked
instead of guessed:

```
subscription_meta.usage_overage.payment_unknown_at   # ISO-8601, set when the answer was lost
```

While it is set, nobody charges. `anotherChargeIsInFlight()` counts the snapshot as in flight
even though there is no id to name it by; `WalletPlanChangeAdjuster::chargeDeferredOverage()`
does **not** hand the excess back to the wallet (doing so is what the next sweep then charged
a second time, and a plan-change line is never credited back by the webhook because it carries
`settles_wallet: false`); and `PrepareUsageOverageJob`'s retry pass skips it rather than
sending it through a ladder that is only safe inside the idempotency window.

`CleanupStalePendingOverageChargeJob` resolves it, by asking the only party that knows —
Mollie's payment list for that customer:

| Mollie's answer | What happens |
|---|---|
| A payment matching the snapshot (metadata `type: overage` + billable, the same gross, created no earlier than the snapshot) | Its id is adopted into the snapshot and the ordinary flow takes over: poll the status, replay a lost `paid` delivery, write off a `failed` one. |
| No such payment | The request never arrived. The marker is dropped and the debt is an ordinary un-charged one again, chargeable by the next cycle. |
| 404 / 410 for the customer | The customer was deleted at Mollie, so no payment of theirs survives either. Marker dropped, same as above. |
| Mollie unreachable | Nothing is decided. Tomorrow's run asks again — an unanswered question is not evidence of absence. |

This branch runs on its own, much shorter clock: `RECONCILE_UNKNOWN_AFTER_HOURS = 1`, against the
72 hours the other branches wait out. Those 72 hours buy patience for a delivery that is still
coming (Mollie retries its webhook for hours, a SEPA debit takes days). Here nothing is coming,
the question is answerable as soon as Mollie can be reached — and until it is answered this
billable's overage cannot be charged at all, so waiting three days would freeze the money for
nothing. One hour, so the two cheaper resolutions get to run first: Mollie's idempotency window
(a key is cached for an hour) and `RetryUsageOverageChargeJob`'s ladder (`[60, 300, 900]`),
either of which may turn the unknown into a known payment id on its own.

### A charge whose webhook never arrived

`usage_overage_status = pending` with a `payment_id` means "a charge exists and its outcome
belongs to the webhook". Both of `PrepareUsageOverageJob`'s own passes skip such a snapshot,
which is right while the payment is in flight — and was permanent when the delivery was lost
(a Mollie outage, or app downtime past Mollie's retry window). The charge was never
collected, `hasUnsettledOverageFor()` stayed true so every renewal carried the negative
balance into the new period, and every future overage for that billable was silenced.

`CleanupStalePendingOverageChargeJob` (daily, 04:15 UTC) closes that. After
`STALE_AFTER_HOURS` (72) it asks Mollie:

- **`paid`** — replays the delivery through the webhook controller, so the invoice, the
  wallet settlement and the events are exactly what the webhook would have produced. The
  invoice guard makes a duplicate a no-op.
- **`failed` / `canceled` / `expired`** — the cycle is over. The debt is left standing for
  the next renewals (see below); nobody is notified.
- **still `open`/`pending` past `HARD_LIMIT_DAYS` (14)** — the payment is **cancelled at
  Mollie first**, and only then treated like a failed one. A payment Mollie will not cancel
  (a direct debit already on its way to the bank) keeps its slot and is looked at again on
  the next pass: freeing the slot for a payment that can still settle is how one debt gets
  collected twice. The 14 days are the **payment's own** age (Mollie's `createdAt`), not
  the snapshot's — the snapshot's `created_at` is the dunning cycle's start and is
  inherited from payment to payment, so the third SEPA attempt of a cycle is routinely
  created when the cycle is already twelve or thirteen days old.
- **an invoice already exists** — the webhook did run and only the snapshot was left behind:
  the snapshot and the dunning budget are cleared.

Mollie being unreachable is not a verdict; the next pass asks again.

### A debt nobody could collect stands, and the renewals try again

A dunning cycle is three payments. When all three fail, that used to be the end of the
debt: the operators were mailed, the subscription went past due, and the next renewal's
reset wrote the units off. Most failed overage payments are a card limit or an expired
card, though, and the customer fixes those within a period or two — so this is now settled
without anybody in operations:

1. **The debt leaves the wallet and stands as a receivable.**
   `ChargeUsageOverageDirectly::deferFailedCycle()` credits the unpaid units back (reason
   `overage_debt_deferred`), keeps the lines in `subscription_meta.usage_overage` with
   `settles_wallet: false`, and sets `usage_overage_status = failed` plus `failed_at`. Off
   the wallet, because a negative balance is netted against the next period's quota by the
   reset that preserves it — the customer would pay in units for something that is then
   collected in money as well.
2. **Each of the next renewals starts one fresh cycle for it.** The sweep the night before
   a renewal, the renewal itself (`WebhookSupport::collectOutstandingOverage()`) and the
   last sweep before a subscription ends all go through
   `ChargeUsageOverageDirectly::handle()`, which charges the standing lines together with
   whatever the wallets owe now. Not before the debt has rested `RECOLLECT_AFTER_DAYS` (3):
   the sweep's cycle fails hours before its own renewal, and trying again the next morning
   is a fourth attempt on a payment method that has just refused three.
3. **After `MAX_RENEWAL_RECOLLECTIONS` (3) such cycles it is written off.** The snapshot is
   removed, `OverageWrittenOff` is dispatched (audit entry `overage_written_off`, with the
   amount and the number of attempts), and *now* the operators are told.

| When | What happens | Customer | Operator |
|---|---|---|---|
| Cycle fails (3 payments) | Units credited back, debt stands | Mail: "we will try again with your next renewal" | — |
| Each of the next 3 renewals | One new cycle for the standing debt | Mail again if it fails | — |
| One of them is paid | Overage invoice, snapshot cleared, no units credited a second time | Invoice | — |
| All of them fail | Written off, `OverageWrittenOff` | Mail: "we will not try again" | Mail |

**While the debt stands the subscription is past due.** A customer who owes money that
most likely cannot be collected any more books nothing new: more seats, a paid addon, a
plan or interval change, a one-time order (with or without a code), extras on top of a
grant and a new checkout are refused with `PastDueExpansionNotAllowedException`, now and
as a scheduled change. A code entered in the portal still works where it is a gift
(credits, an extension, a waiver, a discount on the renewals) — none of them charges
anything and none of them lifts the lock. Shrinking stays
possible. The customer reads the open amount, and why nothing can be booked, on the
dashboard and on the usage page.

The next-charge box (`NextChargeSummary`, `<x-mollie-billing::next-charge>`) keeps
announcing the renewal for such a customer — date, lines, amount — and lists the open
debt as a line of its own when that renewal will collect it; `total_gross` is everything
that leaves the account that day, `gross` remains the subscription's own charge.

**Access stays.** A customer who has paid the plan and owes only overage keeps the
application in the scope of that plan: `hasAccessibleBillingSubscription()` answers true,
`RequireActiveSubscription` (`billing.active`) lets them through, and the feature gates
(`billing.feature`, `@planFeature`, `MollieBilling::features()`) answer as for an active
subscription. What is locked is booking and further overage. With a renewal unpaid as
well, nothing changes: no access, and the application's routes lead to the portal.

### Past due: no further overage

While a subscription is past due — for an overage debt *or* for an unpaid renewal — only
what the wallets hold is usable. A booking that would take a wallet below zero, or
further below, is refused exactly as it is for a billable that does not allow overage:
`UsageLimitExceededException` from `recordBillingUsage()` / `WalletUsageService::debit()`
and the `UsageLimitReached` event, all-or-nothing (40 units left, 50 asked: nothing is
booked). It is one rule in one place, `canChargeBillingOverage()`, so
`hasBillingQuotaLeft()` — the gate an app asks before doing work — gives the same answer
as the debit. A type that still has units stays usable; a wallet that is already negative
takes nothing more. Back on `active`, the billable's ordinary overage setting applies.

> **For app developers.** `recordBillingUsage()` can now throw for a billable that allows
> overage and has a mandate — when it is past due. If your code calls it without asking
> `hasBillingQuotaLeft()` first and without catching `UsageLimitExceededException`,
> because "this customer may always go over", that assumption no longer holds. Handle it
> the way you handle a customer without overage: ask the gate, or catch the exception.

The standing debt is not touched by any of this, and a renewal paid while it stands hands
out the **whole** quota of the new period: the debt was taken off the wallet when its
cycle gave up and is kept as money, so the new units are not netted against it. (An
ordinary negative balance — overage not yet charged — is different: it stays on the
wallet through a reset and is charged, as described above.)

This is a different past due from the one a failed *renewal* causes, and it is recorded
under its own key, `subscription_meta.overage_past_due_since`:

| | Renewal unpaid (`past_due_since`) | Overage debt only (`overage_past_due_since`) |
|---|---|---|
| Renewals | Mollie retries the failed one | keep running and are booked as usual; each one is an attempt at the debt |
| `past_due_max_days` | cancels the subscription | does **not** run — every renewal is paid |
| Plan or interval change | the past-due reset: a fresh period, charged in full | refused — the period is paid, a reset would charge it twice |
| "Charge now" on the dashboard | offered | not offered — it would collect the *next* period today |
| Ends when | a renewal is paid | the debt is paid (→ active), or written off after the last attempt (→ active) |

Every path that ends a dunning episode — a paid renewal, a paid plan change, a reversed
chargeback, an operator's trial extension — asks
`ChargeUsageOverageDirectly::statusAfterRecovery()` which status to write instead of
writing `Active` itself. The lock ends in one place only, `releasePastDueForDebt()`.

**The operator's "Retry"** in the past-due list starts a new collection cycle for a
standing debt at once (`collectStandingDebtNow()`), without the three-day rest. It counts
as one of the three further attempts — each cycle is up to three payments against the
mandate, and an attempt that did not count would let the button dun without end. A second
click while that payment is on its way starts nothing.

A new checkout is refused for a past-due customer who owes — an overage debt, or an
unpaid renewal on a subscription Mollie is still running. It stays open for the one
past-due state with nothing to pay for: a trial that expired without a subscription.

Both can hold at once. A paid renewal then ends only the renewal's episode and the
subscription stays past due for the debt; a paid or written-off debt ends only the debt's
and the subscription stays past due for the renewal, on the ordinary clock.

A plan change while a debt stands carries it along: the standing lines travel with the
plan change's own overage charge rather than being overwritten in the single slot.


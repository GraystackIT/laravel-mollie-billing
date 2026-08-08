# Coupons

This document describes the full coupon system in `laravel-mollie-billing` — every coupon type, where it can be redeemed, what it does, when the redemption fires, and how each type interacts with renewals.

## Overview

Coupons are first-class entities in the system. They are stored in `coupons`, redeemed into `coupon_redemptions`, and orchestrated by `CouponService`. Each entry point in the UI declares which coupon types it accepts via the `allowed_types` validation context — anything else is rejected with `type_not_allowed_in_context`.

| Entry point | Component | Coupon types accepted |
|---|---|---|
| Checkout | `⚡checkout.blade.php` | `single_payment`, `recurring`, `trial_extension`, `access_grant` |
| Plan change | `⚡plan-change.blade.php` | `recurring` |
| Seat sync | `⚡seats.blade.php` | `recurring` |
| Addon enable | `⚡addons.blade.php` | `recurring` |
| One-time-order purchase | `⚡products.blade.php` | `single_payment` (via `applicable_products`) |
| Dashboard "redeem coupon" | `⚡dashboard.blade.php` | `credits`, `trial_extension`, `period_extension` (a `single_payment`/`recurring` code shows a hint to use the action flow) |

**Note on the entry-point restrictions:**
- `single_payment` is *only* accepted on Checkout and One-Time-Order purchases. Plan-change / seat-sync / addon-enable changes the price of an existing Mollie subscription — a one-shot discount that fully covers the prorata charge would leave the local plan switched while Mollie keeps charging the old amount. Use `recurring` for those flows.
- `recurring` is *not* accepted on One-Time-Order purchases — there are no follow-up charges to attach the recurring marker to, so it would have no effect.

The admin creates coupons via `admin/coupons/⚡create.blade.php` — a single form with conditional fields per type. For `credits` coupons, the form renders one numeric input per usage type declared in `SubscriptionCatalogInterface::allUsageTypes()` and writes the entered amounts into `credits_payload`; empty or zero fields are dropped on save. If no usage types are declared in the plans config, the form surfaces a warning instead — the coupon cannot be saved until at least one usage type exists, because `credits_payload` would be empty and the domain validator (`Credits coupons require a credits payload.`) would reject it.

## Coupon types

The system supports six coupon types. Each row in the table below answers: what does the coupon do, where can a user redeem it, when does the redemption side-effect actually fire, and does the discount/effect repeat on renewal?

| Type | What it does | Where redeemed | When the side-effect fires | Renewal behaviour |
|---|---|---|---|---|
| **`single_payment`** | Reduces the first checkout charge OR a one-time-order purchase by a percentage or fixed amount | Checkout, One-time-order | Immediately on the charge it was applied to (first checkout payment, product purchase). Redemption record is written with `invoice_id` of that charge. With 100% coverage no money flows: Subscription Checkout uses the Mandate-Only path, One-Time-Order writes a local 0-EUR audit invoice with no Mollie roundtrip. | Never. The discount applies once. The Mollie subscription is configured with the **full** recurring amount so the next renewal charges the regular price. |
| **`recurring`** | Reduces every recurring charge by a percentage or fixed amount, until the discount lifetime ends | Checkout, Plan change, Seat sync, Addon enable | First charge: same as `single_payment`. From then on, an `active_recurring_coupon` marker on `subscription_meta` is honoured by every Mollie renewal webhook. | **Yes — automatically** until `marker.valid_until`. Once that date is past, the marker is cleared and Mollie is PATCHed back to the full price. |
| **`credits`** | Tops up the wallet(s) listed in `credits_payload` | Dashboard | Immediately on redemption — the wallet is credited and `purchasedBalance` is incremented so the credits survive period resets. | Never. The redemption is one-shot. |
| **`trial_extension`** | Extends `trial_ends_at` by `trial_extension_days`. For Mollie-source subscriptions also PATCHes the Mollie `startDate` to the new trial end so the next charge follows the extended trial. | Checkout (with trial-gate), Dashboard (with trial-gate) | On the dashboard: immediately on redemption. At checkout: when the trial is activated, i.e. inside the mandate-only webhook — the Mollie subscription must exist before its charge date can be PATCHed. Either way `extendBillingTrialUntil()` shifts the trial end and, for Mollie subscriptions, calls `MollieSubscriptionPatcher::setNextChargeDate()`. The same is true for any direct admin-side call to `extendBillingTrialUntil()`, which only accepts statuses where a trial can legitimately run (`new`, `trial`, `active`, `past_due`) and throws `InvalidSubscriptionStateException` otherwise — the status flip it performs grants unconditional access, so on a `cancelled` or `expired` row it resurrected an ended subscription with no Mollie subscription behind it. Promoting out of `past_due` clears the dunning episode, so the granted extension is not undone by the auto-cancel clock still running against the old failure. | Never. |
| **`access_grant`** (full or addon-only) | **Full**: activates a Local subscription with the listed plan and addons for `grant_duration_days`. **Addon-only**: merges the listed addons into the active subscription's `active_addon_codes`, also valid for Mollie subscriptions. | Checkout | Immediately. `applyAccessGrant()` either activates a Local subscription or extends `subscription_ends_at`/merges addon codes. The redemption snapshot is stored in `grant_applied_snapshot` for revoke. | Never. The grant is finite; once `subscription_ends_at` is reached the subscription expires (full grants only). Addon-only grants merge the addons permanently — admin can revoke via `revokeGrant()` to remove them. |
| **`period_extension`** | Pushes the next billing date by `grant_duration_days`. For Mollie subscriptions: PATCHes `startDate`. For Local subscriptions: extends `subscription_ends_at`. | Dashboard | Immediately. The plan and all active addons keep running unchanged — only the next charge date is shifted. | Never. The next regular charge after the extension runs at full price. |

## Stackability

All types support a `stackable` flag (default: `true`). Stackability gates coupon **combination**, not coupon **type**:

- **Stackable coupons** can be applied next to other stackable coupons — discounts cumulate against the remaining net (each subsequent coupon sees a smaller `orderAmountNet`).
- A **non-stackable** coupon (the new one OR any already-applied one) blocks any further coupon entry. The UI hides the input field once a non-stackable coupon is active.
- **At most one `recurring` coupon per apply-set**, regardless of stackability. `active_recurring_coupon` is a single slot, so a second recurring code overwrote the first: the preview and the prorata charge honoured both discounts, then every renewal honoured only the last while both per-billable slots had been consumed. Rejected with `recurring_conflict`. Stacking several `single_payment` codes on a one-time order is unaffected and threads the remaining net correctly.

Implementation: `CouponService::resolveAndValidateShared()` checks the new coupon's `stackable` against the `existingCouponIds` context; both the new coupon being non-stackable AND any existing coupon being non-stackable triggers `CouponNotStackableException`.

## Scope filters

Every coupon can be restricted to a subset of plans, intervals, addons, or products:

| Filter | Type | Effect |
|---|---|---|
| `applicable_plans` | `array<string>` | Coupon only applies when `planCode` is in the list. |
| `applicable_intervals` | `array<string>` | Coupon only applies for `monthly` and/or `yearly`. |
| `applicable_addons` | `array<string>` | Coupon only applies when every addon being acquired is in the list — and at least one is. |
| `applicable_products` | `array<string>` | Coupon only applies for the listed one-time-order products. |
| `minimum_order_amount_net` | `int` (cents) | Coupon requires the order to be at least this big. |

**A set filter is a requirement, not a conditional.** If a filter is non-empty and the order
carries nothing of that dimension, the coupon is rejected — it is not silently skipped.
This matters because the entry points do not overlap: subscription checkout passes no
`productCodes`, a one-time-order purchase passes no `planCode`. A coupon restricted to
`applicable_products` is therefore unusable at subscription checkout, and a coupon
restricted to `applicable_plans` is unusable on a product purchase. The rejection reason
names the dimension (`product_not_applicable`, `plan_not_applicable`, …), so an operator
can tell "wrong plan" from "not usable here at all".

## Validity & redemption limits

| Field | Effect |
|---|---|
| `valid_from` | Coupon cannot be redeemed before this timestamp. |
| `valid_until` | Coupon cannot be redeemed after this timestamp. **Required for recurring coupons** if `max_redemptions_per_billable` is empty. For recurring coupons, also caps the per-redemption marker lifetime (see below). |
| `active` | Soft-disable. Inactive coupons are rejected and existing recurring markers are cleared on the next renewal. |
| `max_redemptions` | Global cap across all billables (null = unlimited). |
| `max_redemptions_per_billable` | Per-billable cap (default `1`). For `recurring`, this defines the discount lifetime in periods: `marker.valid_until = now + max_redemptions × intervalDays + 1d`. **Required for recurring coupons** if `valid_until` is empty. |

### What counts against which cap

The two caps read two different sources, deliberately:

- **`max_redemptions`** is measured by the denormalised `coupons.redemptions_count` column. That counter counts *grants*, not applications: `redeem()` increments it only for a first grant, never for a `renewal` re-application of an already-granted recurring coupon (a 50-redemption campaign would otherwise be exhausted by 20 monthly subscribers within months), and `revokeGrant()` decrements it so the slot is freed. The redemption rows cannot answer this question — nothing in the schema marks a row as grant vs. renewal.
- **`max_redemptions_per_billable`** is measured by the `coupon_redemptions` rows for that billable, **filtered to `revoked_at IS NULL`**. A revoked grant frees its per-billable slot, matching the counter decrement above. `validate()` and `redeem()` share one implementation of this check (`assertPerBillableLimitLeft()`) so the UI and the write path can never disagree — while they differed, revoking a grant left the coupon permanently unusable for that billable, because `validate()` runs first on every UI path.

"Has this coupon ever been redeemed?" — the question behind the guards that refuse to rename or delete a coupon — is answered by the rows **unfiltered**: a revoked redemption still happened, and the invoices and PDFs issued under that code still exist.

## Full coverage handling — both `recurring` and `single_payment` allow 100 %

A discount coupon that reduces the charge to zero would normally crash at the payment provider — Mollie rejects `amount = 0` on subscription charges. Each coupon type has its own zero-charge path so 100 % discounts are supported across the board:

- **`recurring` 100 %** — *allowed.* The Mollie subscription is created/PATCHed with the **full** recurring price, and `startDate` is deferred to the day after the marker's `valid_until`. Mollie does not charge anything during the discount lifetime, and the first real charge after that is naturally at full price (the marker is already expired by then). This is what powers "first 3 months free, then €29/month"-style campaigns.

  See `MollieSubscriptionPatcher::fullCoverageStartDate()` and `CreateSubscription::fullCoverageStartDate()` for the integration. `ResubscribeSubscription` re-uses `CreateSubscription`, so resubscribe-during-discount also defers correctly.

- **`single_payment` 100 % on Subscription Checkout** — *allowed via the Mandate-Only path.* When the checkout total drops to 0 €, `StartSubscriptionCheckout` routes to `StartMandateCheckout`, which creates a 0-EUR mandate-collection payment with the subscription spec embedded in metadata (`pending_subscription_*` keys). Once the mandate is captured, the webhook (`MollieWebhookController::handleMandateOnlyPaid`) activates the Mollie subscription with the default `startDate = now + 1 interval`, writes a local 0-EUR audit invoice, redeems the coupon, and hydrates wallets. The customer pays nothing on the first period and is billed at full price from period 2 onwards. This is the right shape for "first month free, then keep paying" promotions.

- **`single_payment` 100 % on One-Time-Order** — *allowed via the inline 0-EUR path.* When all coupons together cover the product price, `StartOneTimeOrderCheckout` skips Mollie entirely: it writes a local 0-EUR invoice (`mollie_payment_id = null`), redeems the coupon, credits the wallet, and dispatches `OneTimeOrderCompleted`. There is no webhook for this flow because there is no Mollie payment. Invoice and redemptions share one transaction, and a refused redemption aborts the order — on the inline path the coupon *is* the consideration, so fulfilling without recording the redemption would hand out the goods for free. The Mollie-paid path keeps logging a failed redemption instead: there the money genuinely cleared, and withholding delivery would take the payment and give nothing back.

- **`single_payment` 100 % on Plan change / Seat sync / Addon enable** — *not allowed.* These updates change the price of an existing Mollie subscription. A one-shot discount that fully covered the prorata charge would leave the local plan switched while Mollie kept charging the old amount on the next renewal. Use `recurring` for these flows — its deferred-startDate trick keeps Mollie's recurring amount in sync with what the customer actually pays.

- **Discount > 100 %** — always rejected, semantically nonsensical. A fixed-amount `single_payment` coupon whose value exceeds the order is also rejected at validate-time (defense in depth — `computeRecurringDiscount` already caps Fixed at `min(value, netAmount)`).

For "30 days for free without a subscription" use cases, use `access_grant`. For "extend the current period by 14 days" use `period_extension`.

## Coupons at signup, and what the first renewal then charges

A signup coupon is priced by three different pieces of code — the checkout charge, the amount the Mollie subscription is created with, and every renewal invoice. They are only allowed to differ in the way the type intends:

| Coupon at checkout | First charge | Mollie's recurring amount | First renewal |
|---|---|---|---|
| `single_payment` | discounted (0 € → mandate-only) | **full** price | full price |
| `recurring` | discounted (0 € → mandate-only) | **discounted**, and the marker exists before the subscription is created | discounted, until `valid_until` |
| `trial_extension` | nothing is charged — a trial starts | full price, first charge at the extended trial end | full price |
| `access_grant` | nothing at all: no Mollie customer, no mandate, no payment | — (a Local subscription has no Mollie subscription) | never — the grant expires |

Two consequences worth knowing:

- **On a trial signup the coupon is applied at the charge that ends the trial, not at signup.** A `single_payment` coupon is parked in `subscription_meta.pending_first_charge_coupon` and discounts that one charge; a `recurring` coupon's marker is written straight away, so Mollie's subscription is created at the discounted amount and every charge from the trial's end on is discounted. Redemption records follow the same timing — during a trial nothing is charged, so nothing is redeemed yet.

- **A trial and a fully covering `recurring` coupon can collide** (a promotion link auto-applies the code while the checkout form still submits the full gross). The Mollie start date is then the **later** of the trial end and the coupon's `valid_until + 1 day`: charging at trial end would collect money during months the coupon promised free.

A `trial_extension` coupon is gated twice: the selected `(plan, interval)` must offer a trial **and** the trial must actually fire for this customer — a returning subscriber with a mandate on file checks out at full price, and an extension applied to a paid period would move the next charge date forward and bill them again within days. `FirstPaymentHandler` therefore refuses to redeem one on a paid first payment even if a request carries the code.

## Recurring marker — how renewals re-apply the discount

When a `recurring` coupon is redeemed, `CouponService::setActiveRecurringCouponMarker()` writes a marker to `subscription_meta.active_recurring_coupon`:

```php
[
    'coupon_id' => 1,
    'code' => 'REC50',
    'discount_type' => 'percentage',
    'discount_value' => 50,
    'valid_until' => '2026-07-15T10:00:00+00:00', // see below
    'base_amount_net' => 1000,                     // recurring net at apply-time
    'first_applied_at' => '2026-01-15T10:00:00+00:00',
]
```

### `valid_until` — the discount lifetime is computed once at apply time

The marker's lifetime is locked at apply time as the earliest of two gates, measured in the interval the discount will actually run at (the *target* interval — `UpdateSubscription` redeems before it persists a changed one, so reading it off the billable computed a yearly→monthly switch in years):

```
marker.valid_until = min(
    coupon.valid_until,                                    // global hard end (if set)
    now + max_redemptions_per_billable × intervalDays + 1d // duration-based, if set
)
```

The `+ 1d` buffer ensures a charge that lands a few hours after the mathematical period end is still inside the discount window. `intervalDays` is `30` for monthly and `365` for yearly — calendar-month variation is irrelevant because the buffer absorbs it.

The validator (`validateRequiredFieldsForType` for `Recurring`) requires that **at least one** of `valid_until` or `max_redemptions_per_billable` is set — so the marker is guaranteed to have a `valid_until`.

### The count is enforced too, not only the date

`valid_until` stays deliberately generous — `CreateSubscription` derives the 100 %-coverage deferral from it, so shortening it would make Mollie start charging while the coupon is still meant to cover the period. But `max × 30 (+1)` days is *shorter* than the calendar wherever February falls: a 3-period monthly coupon redeemed on 1 December ran to day 91 while Mollie's fourth charge landed 1 March — day 90, inside the window — so the customer received four discounted periods and a fourth redemption row that made the audit trail look deliberate.

So the marker also stops once `max_redemptions_per_billable` **applications** have been recorded. Only redemptions carrying a positive `discount_amount_net` count: a zero-discount row exists to occupy the campaign slot (the trial path writes one, because its discount arrives with the later charges), and counting it would give trial signups one discounted period fewer than paying ones.

### `base_amount_net` — the discount basis is locked

The marker stores the recurring net the coupon was applied against. On every renewal, `computeMarkerDiscount()` calculates:

```
discount = discount_rate × min(base_amount_net, current_charge_net)
```

This means:

- **Seats/addons added after coupon-apply do NOT get discounted.** A `REC50` applied at €10/mo plan → user adds 5 seats × €5 + addon → recurring rises to €43/mo → discount stays at 50 % × €10 = €5. The user pays €38, not €21.50.
- **Seats/addons removed below the original base** are honoured fairly. New net €6 → discount = 50 % × min(€10, €6) = €3 → user pays €3.
- **Fixed discounts** behave identically — they cap at `min(value, base, current)` so the charge can never go negative.

### Marker lifecycle

```
1. Apply        → marker is written with valid_until + base_amount_net
2. Each renewal → renewal webhook applies discount and writes a new
                  CouponRedemption with invoice_id of the renewal charge.
                  The marker itself is not mutated — its lifetime is the date,
                  not a counter.
3. Expired      → valid_until <= now OR coupon deactivated
                  → Mollie subscription is PATCHed back to full price, marker is cleared
```

For 100 % coupons, step 2 doesn't happen during the discount lifetime: the Mollie subscription's `startDate` is deferred past `marker.valid_until`, so Mollie does not charge anything in that window. The first real charge runs after step 3 — the marker is already expired and the full price is billed naturally.

Edge cases:

- **Free downgrade** (Mollie → Free): the marker is cleared automatically when the Mollie subscription is cancelled — no Mollie charges happen anymore.
- **Plan change with active marker**: `MollieSubscriptionPatcher` reads the marker and PATCHes Mollie with the discount-bearing amount so the discount survives plan/seat/addon changes. For 100 % markers, the deferred `startDate` is preserved so the discount lifetime is honoured even across plan switches.
- **Re-entering the same coupon while active**: rejected with `recurring_already_active`. The marker is the source of truth; the user sees the current state in the dashboard.
- **Different recurring coupon while active**: rejected with `recurring_conflict`. Only one recurring marker per subscription. Owner-resolution: deactivate the current coupon (set `active = false`) — the marker is cleared on the next renewal.

## Redemption record (`coupon_redemptions`) — when and what is written

The `discount_amount_net` on each `CouponRedemption` reflects the **actual** amount billed less:

| Trigger | When the row is written | `discount_amount_net` value | `invoice_id` |
|---|---|---|---|
| First-payment-webhook (Mollie checkout payment cleared) | After the first Mollie payment is paid and a sales invoice was created | The discount applied to that first invoice | First-payment invoice |
| Mandate-Only-webhook (100 % `single_payment` checkout) | After the 0-EUR mandate-collection payment is captured and the local audit invoice was written | Full `orderAmountNet` (= the full plan price the coupon waived) | Local 0-EUR audit invoice |
| One-time-order webhook (Mollie payment cleared) | After the product payment is paid and a sales invoice was created | The discount applied to that product invoice | Product invoice |
| One-time-order inline (100 % `single_payment`) | Immediately in `StartOneTimeOrderCheckout::handle` — no Mollie payment, no webhook | Full coupon discount per redeemed coupon | Local 0-EUR product invoice (`mollie_payment_id = null`) |
| Plan-change deferred charge (Phase 2) | When the prorata-charge payment is paid | The actual prorata discount that was billed (read from the persisted `pending_prorata_change.charge_lines` of `kind='coupon'`) | Prorata-charge invoice |
| Sidegrade (Saldo-0 plan switch) | Immediately on `update()` | The prorata discount on the plan-switch invoice | Plan-switch invoice |
| Renewal webhook (recurring marker) | On every renewal as long as the marker is redeemable | Discount on the renewal charge, capped to `base_amount_net` (see above) | Renewal invoice |
| PATCH-only (no charge: addon/seat update on existing subscription) | Immediately on `update()` | `0` — no charge attached. The recurring marker (if applicable) takes effect on the next renewal. | — |
| Local-sub redemption (no Mollie charge) | Immediately on `update()` | `0` for `recurring`. Other types apply their side effect. | — |
| Free downgrade | Immediately on `update()` | `0` | — |

**There is exactly one redemption per actual billing event** — the system avoids double-redeems even across the deferred-charge two-phase flow.

## Lifecycle hooks

Coupons emit events for every meaningful change:

| Event | When | Payload |
|---|---|---|
| `CouponRedeemed` | After `CouponService::redeem()` commits | `Billable`, `Coupon`, `CouponRedemption` |
| `GrantRevoked` | After `CouponService::revokeGrant()` commits | `Billable`, `Coupon`, `CouponRedemption`, reason |
| `SubscriptionExtended` | When `applyPeriodExtension` or full `access_grant` extends `subscription_ends_at` | `Billable`, old/new end, `Coupon` |

Standard events from the wider lifecycle (`PaymentSucceeded`, `PaymentAmountMismatch`, etc.) also fire for invoices that include coupon line-items.

## Programmatic API

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;

$service = MollieBilling::coupons();

// Create a recurring 20% coupon valid for 6 charges
$service->create([
    'code' => 'REC20',
    'name' => 'Spring 20%',
    'type' => CouponType::Recurring,
    'discount_type' => DiscountType::Percentage,
    'discount_value' => 20,
    'max_redemptions_per_billable' => 6,
]);

// Validate (without billable) for a checkout preview
$coupon = $service->validateWithoutBillable('REC20', [
    'planCode' => 'pro',
    'interval' => 'monthly',
    'orderAmountNet' => 5000,
]);

// Validate (with billable) for an in-session apply.
// `allowed_types` restricts which coupon types the entry point accepts —
// e.g. an action flow accepts only single_payment + recurring, the dashboard
// only credits + trial_extension + period_extension. Anything else throws
// InvalidCouponException with reason 'type_not_allowed_in_context'.
$coupon = $service->validate('REC20', $billable, [
    'planCode' => 'pro',
    'interval' => 'monthly',
    'addonCodes' => $billable->getActiveBillingAddonCodes(),
    'orderAmountNet' => 5000,
    'existingCouponIds' => [], // for stackability checks
    'allowed_types' => [
        \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
        \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
    ],
]);

// Compute the discount for display
$discount = $service->computeRecurringDiscount($coupon, 5000);

// Redeem (writes CouponRedemption + applies side-effect)
$service->redeem($coupon, $billable, [
    'planCode' => 'pro',
    'interval' => 'monthly',
    'orderAmountNet' => 5000,
    'discount_amount_net' => $discount,
    'invoice_id' => $invoice->id,
]);
```

For one-time-order purchases:

```php
$billable->purchaseOneTimeOrder('token-pack', metadata: [], couponCode: 'PROD20');
// Or with multiple stackable codes:
$billable->purchaseOneTimeOrder('token-pack', metadata: [], couponCodes: ['STACK10', 'STACK5']);
```

For seat sync / addon enable:

```php
$billable->syncBillingSeats(seats: 5, couponCodes: ['STACK10']);
$billable->enableBillingAddon('sso', couponCodes: ['STACK5']);
```

## Auto-apply tokens

A coupon can carry an `auto_apply_token` (a short slug). `PromotionController` resolves it via `CouponService::resolveByAutoApplyToken()` (case-insensitive), parks the **code** in `session('billing.auto_apply_coupon')` and redirects to checkout, where `mount()` validates it and pre-fills the applied-coupon box. This lets you build campaign URLs without exposing the actual code.

The pre-fill is what makes the price reflect the coupon, and that matters: `StartSubscriptionCheckout` refuses a session coupon whose discount the submitted `amount_gross` does not account for, on any path that actually collects money. Without it, the code reached the payment metadata while the form submitted the full price — the customer paid in full, the webhook priced the coupon, `reconcileWithPaidAmount` cancelled the discount line back out, and the redemption consumed their per-billable slot plus the campaign counter for a discount they never received. Trial and other €0 checkouts keep the coupon regardless, because nothing is collected there for the amount to disagree with.

## Validation reasons (for UI translation)

`InvalidCouponException::reason()` returns one of these codes — each has a translation key under `billing::checkout.coupon_<reason>`:

| Reason | Meaning |
|---|---|
| `not_found` | Code does not exist. |
| `inactive` | `active = false`. |
| `not_yet_valid` | Before `valid_from`. |
| `expired` | After `valid_until`. |
| `globally_exhausted` | `max_redemptions` reached. |
| `per_billable_limit_reached` | `max_redemptions_per_billable` reached for this billable. |
| `plan_not_applicable` | Plan not in `applicable_plans`. |
| `interval_not_applicable` | Interval not in `applicable_intervals`. |
| `addon_not_applicable` | Addon set not covered by `applicable_addons`. |
| `product_not_applicable` | Product not in `applicable_products`. |
| `min_order_not_met` | Below `minimum_order_amount_net`. |
| `requires_billable` | Trial-extension validated without a billable AND without a plan in the context — neither half of the trial gate can be answered. |
| `requires_trial` | Trial-extension on a `(plan, interval)` that offers no trial, or on a customer for whom no trial would start (mandate already on file). |
| `requires_active_subscription` | Period-extension/addon-only-grant without an active subscription. |
| `recurring_conflict` | Another recurring coupon already active. |
| `recurring_already_active` | The same recurring coupon is already active on the subscription. |
| `too_close_to_charge` | Period-extension would race a Mollie charge scheduled within 24 h. |
| `full_coverage_use_access_grant` | A discount would *exceed* the order amount (e.g. a fixed-amount `single_payment` coupon worth more than the product). 100 %-coverage on its own is allowed and does not raise this — it's handled by the Mandate-Only path (Subscription Checkout) or the inline 0-EUR path (One-Time-Order). |
| `type_not_allowed_in_context` | The coupon's type is not accepted at this entry point (e.g. a `credits` coupon entered on a plan-change form). The acceptance list per entry point is in the overview table at the top of this document. |

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status

This repository is a **greenfield Laravel package** (`graystackit/laravel-mollie-billing` (namespace `GraystackIT\MollieBilling\…`, GitHub: `GraystackIT/laravel-mollie-billing`)). At the time of writing only `LICENSE` and `README.md` exist — no `composer.json`, no `src/`, no tests. All implementation work is driven from the plan document, not from existing code.

## Source of truth

The authoritative specification lives at:

```
/Users/bharb/.claude/plans/mollie-billing-package.md
```

Before making architectural decisions, read the relevant section of that plan. It defines:

- The full package structure under `src/` (Services, Contracts, Concerns, Jobs, Http, Events, Models, Enums, …)
- The `Billable` contract + `HasBilling` trait (consumer apps `use HasBilling` on their billable model — typically an `Organization`, not a `User`)
- The `SubscriptionCatalogInterface` and its default `ConfigSubscriptionCatalog` implementation backed by `config/mollie-billing-plans.php`
- A 19-phase implementation roadmap — follow it in order unless the user says otherwise

When the plan and the code disagree, update the plan in the same change that updates the code.

## Architectural shape (big picture)

The package wraps `mollie/laravel-cashier-mollie` and adds, on top of it:

1. **VAT / OSS compliance** (`Services/Vat/*`) — `mpociot/vat-calculator` + VIES, country-match reconciliation across user-declared / IP / payment country, OSS export
2. **Wallet-based metered billing** (`Services/Wallet/*`) — `bavix/laravel-wallet` tracks included quota; overage is charged directly via `ChargeUsageOverageDirectly` with retry + past-due state
3. **Subscription lifecycle services** (`Services/Billing/*`) — one class per action (Start/Create/Activate/Change/Cancel/Resubscribe, EnableAddon/DisableAddon, SyncSeats). The `HasBilling` trait delegates to these via the container so apps override behavior by rebinding, not by subclassing models.
4. **Local vs Mollie subscriptions** — free/zero-price plans run as `SubscriptionSource::Local` without a Mollie subscription; paid plans are `SubscriptionSource::Mollie`. Services branch on this; the trial flow (Pfad A/B/C in the plan) converts Local→Mollie when a mandate arrives.
5. **Feature + plan gating** — `FeatureAccess` resolves features from the active plan **plus** active addons. Exposed as `MollieBilling::features()`, `@planFeature` Blade directive, and `billing.feature:<key>[,<key>]` middleware (OR semantics).
6. **Livewire 4 + Flux UI** — `livewire/flux-pro` is a soft dependency. `Support/FluxPro.php` does a `class_exists` check; `<x-billing::table>` / `<x-billing::modal>` wrappers fall back to HTML+Alpine when Pro is absent. Never call `<flux:table>` / `<flux:modal>` directly from views.
7. **Extension points via Facade callbacks** — `MollieBilling::authUsing()`, `resolveBillableUsing()`, `notifyBillingAdminsUsing()`, `notifyAdminUsing()`, `ipGeolocation()`. Apps wire these in `AppServiceProvider`.

## Conventions specific to this package

- **Billable, not User** — all contracts and services take `Billable` (typically the tenant/org). Do not assume `auth()->user()`.
- **Enums over strings** — `SubscriptionSource`, `SubscriptionInterval`, `SubscriptionStatus`, `MollieSubscriptionStatus`, `DiscountType`, `InvoiceStatus`, `CountryMismatchStatus` are defined in `src/Enums/`. Cast columns via `HasBilling::initializeHasBilling()` which calls `mergeCasts` — don't duplicate casts in the consuming model.
- **Migration stub is table-agnostic** — `add_billing_columns_to_billable_table.php` reads the table name from `config('mollie-billing.billable_model')` and is idempotent (checks `Schema::getColumnListing`). Preserve that pattern for any future billable-table migrations.
- **Events are the extension seam** — every state change dispatches a Laravel event (see the events table in the plan). Apps react via listeners; do not add hooks or callbacks for things an event already covers.
- **Idempotent webhook handler** — `MollieWebhookController` must stay idempotent and must branch on the `mandate_only` metadata for zero-amount first payments.
- **Coupon redemption uses `lockForUpdate`** — `CouponService::redeem()` must preserve pessimistic locking (`DB::transaction` + `Coupon::lockForUpdate` + `increment('redemptions_count')`); don't "simplify" it to a plain update. The package does **not** implement Cashier-Mollie's `CouponHandler` contract — `CouponService` is the sole entry point and discounts flow directly into our pricing services.

## Commands

No build/test tooling is in place yet. Once `composer.json` exists, the intended commands (per the plan's Verifikation section) will include:

```bash
composer install
php artisan test --compact --filter=Billing
php artisan billing:prepare-overage
php artisan billing:oss-export {year}
```

Until Phase 1 (scaffold) is done, there is nothing to run.

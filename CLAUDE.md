# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package

`graystackit/laravel-mollie-billing` — namespace `GraystackIT\MollieBilling\…`, GitHub `GraystackIT/laravel-mollie-billing`. PHP 8.3+, Laravel 11/12/13. Auto-discovered service provider: `MollieBillingServiceProvider`. Facade: `MollieBilling`.

## Source of truth

**The code is the source of truth.** The original 19-phase implementation plan at `/Users/bharb/.claude/plans/mollie-billing-package.md` was the kickoff spec; it has been implemented and is no longer maintained. Read it only as historical context if needed — do not update it when changing code, and prefer reading the actual `src/` files over consulting the plan.

## Commands

```bash
composer install
./vendor/bin/pest --compact                             # full suite
./vendor/bin/pest --compact --filter=WalletUsage        # one test file / pattern
./vendor/bin/pest tests/Feature/Subscription/UpdateSubscriptionTest.php
```

Tests run on Orchestra Testbench against an in-memory SQLite DB. `tests/TestCase.php` boots the package providers (VAT calculator, bavix/laravel-wallet, MollieBilling) and registers `TestBillable` as the billable model. `RefreshDatabase` plus `tests/database/migrations` provide the schema. There is no separate lint/build step.

Artisan commands shipped by the package (registered via `MollieBillingServiceProvider`):

```bash
php artisan billing:prepare-overage    # PrepareOverageCommand → enqueues PrepareUsageOverageJob
php artisan billing:oss-export {year}  # OssExportCommand     → CSV via OssProtocolService
```

## Big-picture architecture

The package wraps `mollie/laravel-mollie` ^4 (Mollie's official Laravel SDK, which itself wraps `mollie/mollie-api-php` v3 with typed request objects) and adds, on top of it, six layers:

1. **VAT / OSS compliance** — `Services/Vat/{VatCalculationService,CountryMatchService,OssProtocolService}` use `mpociot/vat-calculator` + VIES, reconcile user-declared / IP / payment country, and emit OSS export rows.
2. **Wallet-based metered billing** — `Services/Wallet/WalletUsageService` debits/credits `bavix/laravel-wallet` wallets per `usage_type` (e.g. `tokens`, `sms`). Negative balance = overage. `ChargeUsageOverageDirectly` builds line items per wallet and creates a Mollie payment; `RetryUsageOverageChargeJob` handles failures and toggles `past_due`.
3. **Subscription lifecycle services** (`Services/Billing/`) — one class per action: `Start*`, `Create`, `Activate{,Local}`, `ChangePlan`, `Cancel`, `Resubscribe`, `EnableAddon`, `DisableAddon`, `SyncSeats`, plus the `UpdateSubscription` orchestrator and `ScheduleSubscriptionChange` for end-of-period changes. **`HasBilling` delegates to these via the container** — apps override behavior by `app->bind`ing the service, not by subclassing models.
4. **Local vs Mollie subscriptions** — free/zero-price plans run as `SubscriptionSource::Local` without a Mollie subscription; paid plans are `SubscriptionSource::Mollie`. Services branch on this. The trial flow converts Local→Mollie when a mandate arrives.
5. **Feature + plan gating** — `Features/FeatureAccess` resolves features from the active plan **plus** all active addons. Surfaced via `MollieBilling::features()`, the `@planFeature` Blade directive, and `billing.feature:<key>[,<key>]` middleware (OR semantics).
6. **Livewire 4 + Flux UI** — `livewire/flux-pro` is a soft dependency. `Support/FluxPro::available()` does a `class_exists` check; `<x-billing::table>` / `<x-billing::modal>` wrappers fall back to HTML+Alpine when Pro is absent. **Never call `<flux:table>` / `<flux:modal>` directly from views.**

### Catalog: where plan/interval data comes from

`SubscriptionCatalogInterface` is the single seam for plan/addon pricing, included quotas, overage prices, and feature lists. The default `Support/ConfigSubscriptionCatalog` reads `config/mollie-billing-plans.php`. All quota/price lookups are keyed by **(planCode, interval, …)** — `included_usages` and `usage_overage_prices` live inside `intervals.{monthly|yearly}` per plan, not at plan level. Apps wanting DB-driven catalogs rebind the interface in `AppServiceProvider`.

### Webhook handler

`Http/Controllers/MollieWebhookController` handles Mollie's **legacy** webhooks (per-payment `webhookUrl`, ID-only payload). Mollie's v4 "next-gen" typed webhook events (`PaymentLinkPaid`, `SalesInvoicePaid`, etc.) don't cover subscription/recurring-payment flows yet, so we stay on legacy and fetch the payment via `GetPaymentRequest` inside `fetchPayment()`.

The controller must stay **idempotent** (deduped via `BillingProcessedWebhook`) and must branch on the `mandate_only` metadata for zero-amount first payments. After a successful recurring payment, wallets are recharged via `$this->catalog->includedUsages($planCode, $interval)` (additive — preserve negative balances from overage).

Note on signature validation: Mollie does **not** sign legacy webhooks — `X-Mollie-Signature` only exists on next-gen event webhooks (payment links, sales invoices). `ValidatesWebhookSignatures` would therefore be a no-op pass-through for our route and is intentionally **not** applied. The `BillingProcessedWebhook` dedup table and the fact that every incoming webhook triggers a `GetPaymentRequest` against Mollie (which fails for spoofed IDs) are the real integrity guarantees.

### Events as the extension seam

Every state change dispatches an event in `src/Events/` (`SubscriptionCreated`, `PlanChanged`, `OverageCharged`, `PaymentAmountMismatch`, `WalletCredited`, …). Apps react via listeners. Do not add hooks/callbacks for things an event already covers.

### Extension points via Facade callbacks

Apps wire these in `AppServiceProvider`:

```php
MollieBilling::resolveBillableUsing(fn () => auth()->user()?->currentOrganization);
MollieBilling::authUsing(fn () => auth()->check());
MollieBilling::notifyBillingAdminsUsing(...);
MollieBilling::notifyAdminUsing(...);
MollieBilling::ipGeolocation(...);
```

## Conventions specific to this package

- **Billable, not User** — all contracts and services take the `Billable` (typically the tenant/org). Do not assume `auth()->user()`. Methods on `HasBilling` follow the `…Billing…` naming convention (`recordBillingUsage`, `includedBillingQuota`, `getBillingSubscriptionInterval`, …) to avoid collisions with Jetstream/Sanctum, `spatie/laravel-permission`, etc.
- **Enums over strings** — see `src/Enums/`. Casts are merged via `HasBilling::initializeHasBilling()` (`mergeCasts`); don't redeclare them in the consuming model.
- **Migration stub is table-agnostic** — `add_billing_columns_to_billable_table.php` reads the table name from `config('mollie-billing.billable_model')` and is idempotent (`Schema::getColumnListing` check). Preserve that pattern for any new billable-table migration.
- **Coupon redemption uses `lockForUpdate`** — `CouponService::redeem()` must keep `DB::transaction` + `Coupon::lockForUpdate` + `increment('redemptions_count')`. Don't "simplify" it. `CouponService` is the sole entry point and discounts flow directly into our pricing services.
- **Mollie API calls use typed request objects** — `Mollie::send(new CreatePaymentRequest(...))` with `Mollie\Api\Http\Data\Money` for amounts, never `Mollie::api()->payments->create([...])` with raw arrays. Exception: `MollieSalesInvoiceService` stays on property-access (`Mollie::api()->salesInvoices->create([...])`) because `CreateSalesInvoiceRequest`'s shape diverges significantly from our current payload (`vatScheme`, `vatMode`, `recipientIdentifier`, typed `Recipient`/`DataCollection`) — a separate refactor.
- **VAT rate from country lookup, never reverse-engineered from gross** — `MollieWebhookController` computes expected gross from net + country and compares against Mollie's actual amount; mismatch → `PaymentAmountMismatch` event, invoice persisted with the actually-paid amount as source of truth.
- **Test fixture shape** — when writing fixtures, follow the (planCode, interval) shape used in `tests/Feature/Wallet/WalletUsageServiceTest.php`: `included_usages` / `usage_overage_prices` go **inside each `intervals.{monthly|yearly}` block**, not at plan top level.

## Laravel 13 install note

`mpociot/vat-calculator` does not yet declare Laravel 13 compatibility upstream. Apps install via the `GraystackIT/laravel-vat-calculator` fork as a VCS repository (see README) — Composer then transparently resolves the upstream package through the fork.

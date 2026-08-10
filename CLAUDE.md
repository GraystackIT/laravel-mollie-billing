# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package

`graystackit/laravel-mollie-billing` — namespace `GraystackIT\MollieBilling\…`, GitHub `GraystackIT/laravel-mollie-billing`. PHP 8.3+, Laravel 12/13. Auto-discovered service provider: `MollieBillingServiceProvider`. Facade: `MollieBilling`.

## Source of truth

**The code is the source of truth.** The original 19-phase implementation plan at `/Users/bharb/.claude/plans/mollie-billing-package.md` was the kickoff spec; it has been implemented and is no longer maintained. Read it only as historical context if needed — do not update it when changing code, and prefer reading the actual `src/` files over consulting the plan.

**Before reporting anything as a defect, read `docs/decisions.md`.** It records the behaviour that looks wrong and is not — the enforced invariants, the deliberate choices, and the findings that were investigated and refuted — so a review spends its attention on something new. Add to it whenever a judgement call is made. And treat a finding as a *claim* until it is reproduced: the fix is only believed once its test has also been run against the unfixed file and seen to fail there.

## Commands

```bash
composer install
./vendor/bin/pest --compact                             # full suite
./vendor/bin/pest --compact --filter=WalletUsage        # one test file / pattern
./vendor/bin/pest tests/Feature/Subscription/UpdateSubscriptionTest.php

composer test:browser                                   # Chrome end-to-end suite
composer browser:assets                                 # rebuild Vite assets first
composer browser:gallery                                # screenshots → index.html

composer test:sandbox                                   # real Mollie API, needs MOLLIE_KEY=test_…
```

`composer test:browser` runs past Composer's 300-second process timeout; it dies mid-suite and
leaves its server on port 8001, which makes every later run fail with `UnableToStartServer`.
Run it as `./vendor/bin/pest --compact --configuration=phpunit.dusk.xml` (or set
`COMPOSER_PROCESS_TIMEOUT=0`), and if a run was interrupted, free the port before the next one.

The browser suite (`tests/Browser/`) is separate on purpose: it needs Chrome and a
Flux Pro licence, and `phpunit.xml.dist` deliberately does not list it. It is the only
suite that renders a portal screen — Flux ships under `suggest`, so the feature suite
runs without it. Browser tests are plain PHPUnit classes, never Pest closures: the Dusk
server boots its app by instantiating the originating test class by name, and Pest's
evaluated `P\Tests\…` classes do not exist in that second process. Mollie is faked at
the SDK's own `HttpAdapterContract` seam against a shared SQLite file, so both processes
see one world. See `docs/browser-testing.md`.

Tests run on Orchestra Testbench against an in-memory SQLite DB. `tests/TestCase.php` boots the package providers (VAT calculator, bavix/laravel-wallet, MollieBilling) and registers `TestBillable` as the billable model. `RefreshDatabase` plus `tests/database/migrations` provide the schema. There is no separate lint/build step.

Artisan commands shipped by the package (registered via `MollieBillingServiceProvider`):

```bash
php artisan billing:prepare-overage    # PrepareOverageCommand → enqueues PrepareUsageOverageJob
php artisan billing:oss-export {year}  # OssExportCommand     → CSV via OssProtocolService
php artisan billing:check-config       # CheckConfigCommand   → validates mollie-billing.php + plans config
```

## Big-picture architecture

The package wraps `mollie/laravel-mollie` ^4 (Mollie's official Laravel SDK, which itself wraps `mollie/mollie-api-php` v3 with typed request objects) and adds, on top of it, six layers:

1. **VAT / OSS compliance** — `Services/Vat/{VatCalculationService,CountryMatchService,OssProtocolService}` use `mpociot/vat-calculator` + VIES, reconcile user-declared / IP / payment country, and emit OSS export rows.
2. **Wallet-based metered billing** — `Services/Wallet/WalletUsageService` debits/credits `bavix/laravel-wallet` wallets per `usage_type` (e.g. `tokens`, `sms`). Negative balance = overage. `ChargeUsageOverageDirectly` builds line items per wallet and creates a Mollie payment; `RetryUsageOverageChargeJob` handles failures and toggles `past_due`.
3. **Subscription lifecycle services** (`Services/Billing/`) — one class per action: `Start*`, `Create`, `Activate{,Local}`, `ChangePlan`, `Cancel`, `Resubscribe`, `EnableAddon`, `DisableAddon`, `SyncSeats`, plus the `UpdateSubscription` orchestrator and `ScheduleSubscriptionChange` for end-of-period changes. **`HasBilling` delegates to these via the container** — apps override behavior by `app->bind`ing the service, not by subclassing models.
4. **Local vs Mollie subscriptions** — free/zero-price plans run as `SubscriptionSource::Local` without a Mollie subscription; paid plans are `SubscriptionSource::Mollie`. Services branch on this. The trial flow converts Local→Mollie when a mandate arrives.
5. **Feature + plan gating** — `Features/FeatureAccess` resolves features from the active plan **plus** all active addons. Surfaced via `MollieBilling::features()`, the `@planFeature` Blade directive, and `billing.feature:<key>[,<key>]` middleware (OR semantics).
6. **Livewire 4 + Flux Pro UI** — `livewire/flux-pro` is a **hard runtime dependency** but listed under `suggest` (not `require`) in composer.json because it is commercial and the consuming app must install it with its own license. All portal/admin views use `<flux:…>` components directly — no fallback wrappers, no `FluxPro::isInstalled()` checks. The package provides both the checkout flow and the post-signup portal (dashboard, plan change, invoices, return). `PromotionController`, `RequireActiveSubscription` middleware, and `TrialExpiredNotification` all redirect to `BillingRoute::checkout()`, which dynamically resolves the correct route name including any prefix (e.g. `tenant.billing.checkout`).

### Catalog: where plan/interval data comes from

`SubscriptionCatalogInterface` is the single seam for plan/addon pricing, included quotas, overage prices, and feature lists. The default `Support/ConfigSubscriptionCatalog` reads `config/mollie-billing-plans.php`. All quota/price lookups are keyed by **(planCode, interval, …)** — `included_usages` and `usage_overage_prices` live inside `intervals.{monthly|yearly}` per plan, not at plan level. Apps wanting DB-driven catalogs rebind the interface in `AppServiceProvider`.

### Webhook handler

`Http/Controllers/MollieWebhookController` handles Mollie's **legacy** webhooks (per-payment `webhookUrl`, ID-only payload). Mollie's v4 "next-gen" typed webhook events (`PaymentLinkPaid`, `SalesInvoicePaid`, etc.) don't cover subscription/recurring-payment flows yet, so we stay on legacy and fetch the payment via `GetPaymentRequest` inside `fetchPayment()`.

The controller must stay **idempotent** (deduped via `BillingProcessedWebhook`) and must branch on the `mandate_only` metadata for zero-amount first payments. After a successful recurring payment, wallets are recharged via `$this->catalog->includedUsages($planCode, $interval)` (additive — preserve negative balances from overage).

A reservation is not a lease held forever: `reserve()` takes over a still-pending row older than `MollieWebhookController::RESERVATION_TTL_MINUTES`, because nothing unwinds it when the process dies outright and a leaked row otherwise answered every later delivery 200 — telling Mollie a payment it never processed was handled. A delivery skipped because another is genuinely in flight answers **503**, not 200, so the information it carries (typically a refund the holder cannot see) is re-presented.

Idempotency has two layers: (1) `reserve()` serializes deliveries that are still in flight, while duplicates are recognised by an **outcome signature** (terminal status + refunded total) — deduping on the payment id alone made refund re-deliveries unreachable, since Mollie re-calls the same payment webhook when a refund is created and the status stays `paid`. A delivery seen while the status is still non-terminal (`open`/`pending`/`authorized`) releases its reservation instead of consuming it, so the later genuine `paid` delivery still books the money; (2) `invoiceAlreadyExistsForPayment($payment)` short-circuits every handler that creates an invoice — Mollie re-delivers if a previous run threw *after* invoice persistence (reservation gets deleted in `__invoke()`'s catch), and the `billing_invoices.mollie_payment_id` unique index would otherwise trap the webhook in a 500-retry loop. Any new handler that calls `createForPayment` / `createInvoice` MUST add the guard before the non-idempotent side-effects (coupon redemptions, wallet credits, Mollie API calls).

**Activation is two-phase.** `FirstPaymentHandler` and `MandateOnlyPaymentHandler` each split into a short `openGate()` transaction (row lock → invoice guard → duplicate guard → `WebhookSupport::claimActivation()`) and an `activate*()` body that runs **outside any transaction**, with `releaseActivationClaim()` in a `finally`. The Mollie `CreateSubscription` call must never sit inside a transaction: anything throwing after it rolled back `mollie_subscription_id` and the invoice while the subscription existed at Mollie, and the re-delivery then created a second one and charged the customer twice every period. The gate's guard order matters — the invoice guard runs first, because after a crash mid-activation the billable is both Active *and* holds this payment's invoice, which is a re-delivery to swallow, not a duplicate payment to report. The claim (`subscription_meta.activation_claim`, TTL `WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES`) is what a *different* payment sees while our activation is in flight but its writes are not yet visible; it throws `ActivationClaimHeldException` → the controller answers 503 so Mollie re-delivers. It deliberately outlives a crash so the same payment can re-claim at once while a sibling waits out the TTL. Any new activation path must follow the same shape.

**No Mollie call inside a DB transaction — anywhere.** The activation path is the documented case, but the rule is general: a transaction can be rolled back, an HTTP call to a payment provider cannot. `UpdateSubscription::update()` and `RefundInvoiceService::refund()` therefore follow the same three-phase shape — decide (row lock, short transaction) → call Mollie (nothing held) → write (row lock, short transaction) — with a **cache lock** (`billing:subscription-change:{id}`, `billing:invoice-refund:{id}`) providing the serialisation a long transaction used to. The loser of that lock is refused (`SubscriptionChangeInProgressException` / `RefundInProgressException`) rather than queued: it would otherwise sit on a worker through a payment plus a PATCH, and everything it validated is stale by the time it gets its turn. `InvoiceService::createCreditNote($…, deferDocument: true)` exists so the DOMPDF render and the disk write happen after the booking commits, instead of holding the invoice row lock and the serial range across them. `WalletPlanChangeAdjuster::adjust($…, deferOverageCharge: true)` exists for the same reason: the rebalance is local and belongs in the transaction, the charge for what it cannot resolve is a Mollie call and is handed back out to `chargeDeferredOverage()`.

**Every Mollie request goes through `Support\MollieCall::send()`, never `Mollie::send()` directly.** That is the single door where the rule above is *enforced*: it refuses when a transaction is open — throwing in testing/local, logging a warning in production, because dropping a payment the customer is waiting for is worse than the risk being warned about. Reviewing every call site against every caller that might open a transaction is exactly the check that rots, and it did — the addon services and the plan-change phase-3 body both drifted back after the rule was written down. The test harness pins the ambient `RefreshDatabase` depth as the baseline in `tests/TestCase.php::setUp()`, so the guard is live for the whole suite rather than only where a test asserts it. `tests/Feature/Billing/NoMollieCallInsideTransactionTest.php` additionally pins `DB::transactionLevel()` at the moment of the call for the money paths, and self-tests the guard.

`MollieCall::send()` also owns the **idempotency key** for the calls that have a meaningful one: `send($request, $key)` sets it on the shared client and clears it in a `finally`, because the SDK clears the key from a *response* middleware that a throw never reaches — and a leaked key means the next mutating request gets this operation's cached response or a 400. Mollie caches a key for **one hour** and rejects a reused key whose parameters differ, so a key must be stable across *retries of one operation* and must move when the amount does: see `RefundInvoiceService` (`invoice-refund:…`) and `ChargeUsageOverageDirectly` (`usage-overage:…`, keyed on the dunning cycle). Note the SDK puts a *random* key on every POST by default, which protects nothing across retries.

`MollieCall::send()` enforces a second invariant at the same door: **every amount is written with its currency's own number of decimals.** Mollie reads the number as written, so a hand-assembled `"10.5"` is a wrong charge rather than a rejected one, and `"500.00"` for a zero-decimal currency like JPY is off by a factor of a hundred in a request the API accepts. Build amounts with `BillingMoney::mollieAmount()` / `InvoiceService::molliePrice()`, never `number_format($cents / 100, 2)` or a `(string)` cast. Pinned by `tests/Feature/Billing/MollieAmountScaleGuardTest.php`.

A relative mutation (enable *one* addon, move seats by a delta) must not be serialised with a transaction around `update()`. Pass `UpdateSubscription::update()` a **closure** instead: it is resolved on the billable refreshed under the change lock, so the read and the write share one critical section without holding anything across the Mollie calls.

Note on signature validation: Mollie does **not** sign legacy webhooks — `X-Mollie-Signature` only exists on next-gen event webhooks (payment links, sales invoices). `ValidatesWebhookSignatures` would therefore be a no-op pass-through for our route and is intentionally **not** applied. The `BillingProcessedWebhook` dedup table and the fact that every incoming webhook triggers a `GetPaymentRequest` against Mollie (which fails for spoofed IDs) are the real integrity guarantees.

### Events as the extension seam

Every state change dispatches an event in `src/Events/` (`SubscriptionCreated`, `PlanChanged`, `OverageCharged`, `PaymentAmountMismatch`, `WalletCredited`, …). Apps react via listeners. Do not add hooks/callbacks for things an event already covers.

### Extension points via Facade callbacks

Apps wire these in `AppServiceProvider`:

```php
MollieBilling::resolveBillableUsing(fn () => auth()->user()?->currentOrganization);
MollieBilling::authUsing(fn (Request $r, Billable $b) => $r->user()?->belongsToBillable($b) ?? false);
MollieBilling::notifyBillingAdminsUsing(...);
MollieBilling::notifyAdminUsing(...);
MollieBilling::ipGeolocation(...);
MollieBilling::urlParametersUsing(fn (?Billable $b) => $b ? ['organization' => $b->slug] : []);
```

**Multi-tenant URL resolution** has two layers: `PropagateRouteDefaults` middleware copies route params into `URL::defaults` for the active HTTP request (portal, Livewire, form POSTs). `MollieBilling::urlParametersUsing()` provides parameters for contexts **without** a request — Mollie redirect/webhook URLs built in `StartSubscriptionCheckout`, `CreateSubscription`, queued `TrialExpiredNotification`, etc. Both are active simultaneously without conflict. Services always pass explicit params from the resolver to `route()` defensively, so URLs are correct even outside a request.

## Conventions specific to this package

- **Billable, not User** — all contracts and services take the `Billable` (typically the tenant/org). Do not assume `auth()->user()`. Methods on `HasBilling` follow the `…Billing…` naming convention (`recordBillingUsage`, `includedBillingQuota`, `getBillingSubscriptionInterval`, …) to avoid collisions with Jetstream/Sanctum, `spatie/laravel-permission`, etc.
- **Enums over strings** — see `src/Enums/`. Casts are merged via `HasBilling::initializeHasBilling()` (`mergeCasts`); don't redeclare them in the consuming model.
- **Migration stub is table-agnostic** — `add_billing_columns_to_billable_table.php` reads the table name from `config('mollie-billing.billable_model')` and is idempotent (`Schema::getColumnListing` check). Preserve that pattern for any new billable-table migration.
- **Coupon redemption uses `lockForUpdate`** — `CouponService::redeem()` must keep `DB::transaction` + `Coupon::lockForUpdate` + `increment('redemptions_count')`. Don't "simplify" it. `CouponService` is the sole entry point and discounts flow directly into our pricing services.
- **Mollie API calls use typed request objects** — `Mollie::send(new CreatePaymentRequest(...))` with `Mollie\Api\Http\Data\Money` for amounts, never `Mollie::api()->payments->create([...])` with raw arrays.
- **Local invoice PDF generation** — `InvoiceService` (formerly `MollieSalesInvoiceService`) generates invoice PDFs locally via `elegantly/laravel-invoices` and stores them on the configured Laravel filesystem disk (`config('mollie-billing.invoices.disk')`). Serial numbers are generated atomically by `InvoiceNumberGenerator`. The old `MollieSalesInvoiceService` class extends `InvoiceService` as a deprecated alias.
- **VAT rate from country lookup, never reverse-engineered from gross** — `MollieWebhookController` computes expected gross from net + country and compares against Mollie's actual amount; mismatch → `PaymentAmountMismatch` event, invoice persisted with the actually-paid amount as source of truth.
- **Test fixture shape** — when writing fixtures, follow the (planCode, interval) shape used in `tests/Feature/Wallet/WalletUsageServiceTest.php`: `included_usages` / `usage_overage_prices` go **inside each `intervals.{monthly|yearly}` block**, not at plan top level.


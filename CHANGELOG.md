# Changelog

All notable changes to `graystackit/laravel-mollie-billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Admin panel is fully translated via the new `billing::admin` lang file (English and German).
- `mollie-billing.admin_locale` (`BILLING_ADMIN_LOCALE`): pins the admin panel to a fixed locale. `null` (default) follows the app locale.

### Changed

- `mollie-billing.header_components` now also render in the admin header, so a language switcher works there too.
- **Breaking-ish:** the admin panel no longer forces English. Set `BILLING_ADMIN_LOCALE=en` to restore the previous behavior.
- Docs: `notifications.md` now spells out that `notifyBillingAdminsUsing()` / `notifyAdminUsing()` recipients must be notifiable, including the `routeNotificationForMail()` recipe for returning the billable itself.

### Fixed

- Aborted `mandate_only` payments (portal payment-method change, trial / 100%-coupon checkout) no longer flip an active or trialing subscription to `past_due`: the webhook failure path now short-circuits them instead of falling through to the subscription-payment-failure handler. Without an accessible subscription the abort still runs the after-checkout callback with `success = false`.

## [0.3.9] - 2026-08-04

### Added

- Hidden plans: `hidden => true` per plan keeps it out of the checkout plan step and the portal's plan-change screen while every by-code lookup keeps resolving, so a subscriber on one still sees their plan. Enforced server-side via `HiddenPlanNotSelectableException` (plan change, scheduled change, checkout, Local→Mollie upgrade); staying on a hidden plan and changing interval within it stay allowed, and `ActivateLocalSubscription` still assigns them programmatically. New `SubscriptionCatalogInterface::visiblePlans()` / `planIsHidden()` — **breaking for apps that implement the interface themselves** rather than extending `ConfigSubscriptionCatalog`. Opt out per call through the typed request only: `new SubscriptionUpdateRequest(planCode: 'supplier', allowHiddenPlan: true)` or `$request->withAllowHiddenPlan()`.
- `usage_overage_prices` accepts `false` per usage type to declare a quota that deliberately has no overage; `billing:check-config` no longer warns about it, and `usageOveragePrice()` returns `null` (not `0`) so the UI does not render a "€0.00 per unit" price.
- `mollie-billing.header_components`: Livewire component aliases rendered in the portal header (left of the theme switcher) and the checkout header (left of the "Back" link), so an app can add e.g. a language switcher without publishing the layouts. The admin layout is intentionally excluded.
- `billing:check-config` errors when every plan is hidden, validates that `hidden` is a boolean, and no longer reports an ambiguous `tier` when the collision only involves a hidden plan.

### Changed

- The hidden-plan override is typed-only: `SubscriptionUpdateRequest::from()` no longer reads `allow_hidden_plan` from array payloads. Use the constructor argument (`new SubscriptionUpdateRequest(planCode: 'supplier', allowHiddenPlan: true)`) or the new `withAllowHiddenPlan()`. It overrides an authorization check, so an app forwarding `request()->all()` into `update()` must not be able to enable it; scheduled changes replay it through the typed API.
- The checkout plan step and its addon prices are resolved through `SubscriptionCatalogInterface` (`visiblePlans()`, `basePriceNet()`, `addonPriceNet()`, …) instead of reading `mollie-billing-plans.plans` / `.addons` directly, so a database-backed catalog offers the codes it actually sells and stale config entries can no longer surface in checkout.
- The checkout shell is `max-w-5xl`; the plan step uses the full width for its side-by-side cards while the form-shaped steps stay at `max-w-3xl`.
- Plan cards (checkout plan step and the portal plan-change view) put each entitlement on one line with the per-extra-unit price right-aligned instead of on a second indented line, name the unit the extra price is charged per (`+€0.12/Tokens`, `+€11.88/seat`), resolve usage labels through `usageTypeName()`, and pluralize the seat count. The three-up comparison cards show feature names only, with the description as the row's hover title — stacked multi-line descriptions made the cards impossible to compare. Add-on lists stay complete (no cap, no truncation). New keys: `checkout.quota_extra_price`, `checkout.seat_unit`, `portal.quota_extra_price`, `portal.seat_unit`, `portal.seat_overage_price`.
- The VAT-number field reserves one line for its feedback and aligns the country select to the top of the row, so validation messages swap in place instead of shifting the form (checkout billing step and the portal's edit-billing modal). New key: `checkout.vat_checking`.
- `WalletUsageService::debit()` locks and refreshes the billable row before evaluating `canChargeBillingOverage()` and before locking the wallet — same acquisition order as the subscription-change path — so a debit can no longer decide the overage policy from a caller model that predates a plan change.
- The usage-quota gate and the usage-quota enforcement now resolve through one rule, `Billable::canChargeBillingOverage($type)`: non-Local subscription, Mollie mandate present, `allowsBillingOverage()`, and an overage price > 0 for the current (plan, interval, usage type). `hasBillingQuotaLeft()` and the hard cap in `WalletUsageService::debit()` both read it, so the gate can no longer report quota left where the debit throws. `UsageThresholdNotification` uses it too and stops promising a charge that would not happen.
  - **Behaviour change:** a quota whose overage price is missing (or `false`) is now hard-capped instead of running the wallet negative — `recordBillingUsage()` throws `UsageLimitExceededException` and fires `UsageLimitReached` once the balance is exhausted. Previously such usage was accepted and the negative balance was never collectable, because `ChargeUsageOverageDirectly` skips wallets without a price. Configure an overage price for every quota you want to be exceedable.
  - **Breaking for apps implementing `Billable` without `HasBilling`**: the new `canChargeBillingOverage(string $type): bool` is part of the contract. Implementations using the trait inherit it.

### Fixed

- `checkout.vies_validation_failed` now says the number is not registered as valid in VIES *and* asks the user to check their input — the old wording named VIES without giving an action, which read like the lookup itself had failed (that case is `vies_unavailable`).
- German `portal.return.to_dashboard` said "Zum Dashboard" while `portal.nav.dashboard` calls the same target "Übersicht" — the DE portal reserves "Dashboard" for the app's own dashboard (`nav.back_to_dashboard`). Now "Zur Übersicht", with `portal.return.body` matching.
- Cancelling a one-time order at Mollie landed on the return page with "Payment received": the page assumed success for every `origin=products` return without ever looking at the payment. `StartOneTimeOrderCheckout` now stashes the created payment in the session under an unguessable per-checkout token (`PENDING_ORDER_SESSION_KEY` holds a `token => payment_id` map, the token travels in the `redirectUrl` as `order_token`) and the return page resolves the real status from Mollie — paid → success, canceled/failed/expired → the failure card pointing back to the products page, everything else → keeps polling, unknown token → a new "we could not check this payment" state instead of a false success. Terminal payments are dropped from the map. A map rather than a single id because two checkouts started in parallel (two tabs, a double-click) would otherwise overwrite each other and both return pages would judge the last payment. New keys: `portal.return.body_products`, `processing_title_products`, `timeout_body_products`, `unresolved_title`, `unresolved_body`.
- The one-time-order return page treated Mollie's `authorized` as a completed purchase. An authorisation is not a capture and the webhook creates the invoice and credits the wallet on `paid` alone, so the page announced goods that nothing had booked. `authorized` now counts as pending and keeps polling.
- The VAT feedback line in the portal's edit-billing modal collapsed while a VIES check was in flight — the placeholder carried `wire:loading.remove` but nothing took its place, so the modal jumped on every keystroke. The wrapper now reserves the line itself. A blocking error raised while the VIES verdict is still unknown (VIES unreachable, "correct or clear the number") also renders in the error colour with the warning icon instead of the amber pending style.
- German overage hints were partly untranslated (`€6.00 per extra Platz`) in `de/checkout.php` and `de/portal.php`.
- `hasBillingQuotaLeft()` consulted the existence of a Mollie mandate alone, so it reported quota left where `WalletUsageService::debit()` would in fact refuse the booking. Most visible after a downgrade to a free plan or a cancellation: `mollie_mandate_id` is never cleared, so a Local subscription still counted as mandate-backed and the app was told to proceed straight into a `UsageLimitExceededException`.
- Deferred prorata upgrades dropped `allow_hidden_plan` from the stored `pending_plan_change`, so an approved move onto a hidden plan was rejected in Phase 2 after the payment settled. The payload is now serialized via `SubscriptionChangeContext::toPendingArray()`.
- `ScheduleSubscriptionChange::schedule()` re-asserts the hidden-plan rule against the locked and refreshed row. The check in `UpdateSubscription::update()` runs on the caller's model, so a request built before a concurrent plan change could store a scheduled move onto a hidden plan that only failed at period end.
- `UpdateSubscription::update()` validated the plan target only on the immediate path — a change with `apply_at => end_of_period` was stored unvalidated and failed at period end instead. Plan-target validation now runs before the scheduling branch.

## [0.3.8] - 2026-07-20

### Added

- `spatie/laravel-activitylog` v5 support (`^4.12|^5.0`). v5 stores model attribute diffs in a dedicated `attribute_changes` column instead of nesting them in `properties`; the create migration now ships that column and a new `add_attribute_changes_to_activity_log_table` migration adds it to tables created earlier, owned by the app, or created by spatie's own published migration. Without it every audit insert fails — and since `RecordBillingAudit` swallows write errors to keep billing flows alive, the trail would silently stay empty. Both majors work against the same table (the column is nullable and v4 never writes it).

## [0.3.7] - 2026-07-20

### Added

- Audit trail: every billing event is recorded against the billable via `spatie/laravel-activitylog` (new hard dependency) and shown as a timeline in a new "Audit" tab on the admin billable page. Rows store a translation key plus raw placeholder values instead of rendered text, so the history renders in any locale and resolves plan codes to current catalog names. New `BillingAuditMap` (single source of truth for what is audited), `BillingAuditEntry` (rendering), `RecordBillingAudit` listener, `AuditCategory` enum, `HasBilling::billingAuditTrail()`, `PruneBillingAuditJob`, `audit` config block and `resources/lang/{en,de}/audit.php`. The package ships its own `activity_log` migration with string morph ids — spatie's `nullableMorphs` stub cannot hold the default uuid keys; do not publish spatie's migrations alongside it.

### Fixed

- Audit hardening: the `activity_log` migration now uses string morph ids (integer- and uuid/ulid-keyed subjects can coexist) and only drops the table on rollback when it created it; `check-config` validates `causer_id` as well as `audit.categories` / `audit.retention_days`; `RecordBillingAudit` requires a `Billable` model; `BillingAuditEntry::occurredAt()` accepts immutable dates; `billingAuditTrail()` sorts by id as tie-breaker.
- Invoice download: missing-PDF regeneration is serialised per invoice via a cache lock, so concurrent downloads no longer delete each other's freshly written file.
- Portal usage statistics now count real consumption only. Bookkeeping transactions (credit purchases, plan quota top-ups, period and plan-change resets) no longer inflate the usage total, daily average, peak day, trend and top usage type. New `WalletUsageService::isUsageReason()` / `scopeRealUsage()` expose the classification; the transaction table still lists the full wallet ledger.

## [0.3.6] - 2026-06-15

### Added

- `UpdateSubscription` now guards against user-initiated changes while the current billing period has lapsed without renewing. When `nextBillingDate()` lies in the past the subscription is stuck between periods and the prorata factor collapses to 0, so a seat/addon upgrade would prorate to a free change; `guardPeriodNotLapsed()` throws `InvalidSubscriptionStateException` until the renewal (or past-due flow) catches up. Internal end-of-period re-entries (`internal=true`) and past-due subscriptions are exempt.

### Security

- Portal mutation actions now authorize at the service level via `abort_unless(MollieBilling::authorizes(request(), $billable), 403)`. Livewire component actions bypass the portal route middleware, so plan changes, scheduled/pending-change cancellation, addon enable/disable, seat changes and one-time product payments each re-check authorization before mutating state or triggering a charge.

## [0.3.5] - 2026-06-09

### Fixed

- Plan changes are now blocked for billables that never completed checkout (`subscription_source = none`): after an abandoned/cancelled first payment the portal could silently rewrite the plan code with no payment. `ValidateSubscriptionChange` now throws `InvalidSubscriptionStateException`, and `BillingPortalController::plan()` redirects such billables to checkout.

## [0.3.4] - 2026-06-02

### Added

- `Billable::scopeBillableSearch()`, `scopeBillableOrderByName()` and `scopeBillableOrderByEmail()` — query scopes that drive admin-panel search and sorting. `HasBilling` ships defaults targeting the `name` / `email` columns (User-as-billable shape); apps whose display name or contact email lives elsewhere (or behind a relation) override them. See README "Admin search & sort on custom columns".

### Changed

- The admin panel no longer reads `name` / `email` columns directly. Billable labels in the billables list & detail, scheduled-changes, past-due, refunds and grant views now render through `getBillingName()` / `getBillingEmail()`, and search/sort route through the new scopes — so a billable whose name/email lives on non-standard columns no longer yields empty lists or query errors.

### Breaking

- The three new scope methods are part of the `Billable` contract. Apps that implement `Billable` **without** the `HasBilling` trait must add them (next tag should be **0.4.0**). Implementations using `HasBilling` inherit working defaults and need no change.

## [0.3.3] - 2026-05-30

### Added

- `HasBilling::getAvailableBillingSeats()` and `isBillingSeatAvailable(int $count = 1)` — derive free seat capacity from the configured seat count minus `getUsedBillingSeats()`. Both are part of the `Billable` contract.

## [0.3.2] - 2026-05-22

### Added

- New `Billable::applyBillingScope(Builder $query)` hook, applied automatically as a global Eloquent scope by `HasBilling`. Apps whose billable model also stores non-billable rows (e.g. a `User` table that mixes staff with paying customers) can override this method to restrict the row set the package operates on — admin listings, KPIs and lifecycle jobs all see the filtered set. Default is a no-op, so existing implementations are unaffected. Bypass per-query with `->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)` where every row must remain reachable (webhook resolution, retry jobs, admin impersonation).

## [0.3.1] - 2026-05-22

### Changed

- `MollieBilling::cleanupOrphanedBillableUsing()` closures may now return `false` to veto cleanup for billables that legitimately exist without a subscription (admins, employees, internal accounts). The job then skips the `CheckoutAbandoned` event, mandate revocation and log entry. Returning `true` or `void` keeps the legacy behaviour. The cleanup closure is also now invoked **before** the side-effects (event/mandate revoke), so a vetoing app no longer triggers ghost notifications for every matching admin row. Mollie customer/mandate IDs are snapshotted before the closure runs so revocation still works after the row is deleted.

### Fixed

- `ProcessTrialLifecycleJob` no longer fires the `TrialConverted` event or sends `TrialConvertedNotification` when a mandate is present and the trial ends tomorrow — both spoke in past tense ("trial was converted", "invoice was issued") but at that point no charge had happened and no invoice existed. The job now always sends `TrialEndingSoonNotification`, which already branches on `hasMollieMandate()` for the right wording. The actual conversion event + notification are dispatched only by `SubscriptionPaymentHandler::paid()` when Mollie's first recurring charge lands.
- `CleanupOrphanedBillablesJob` now sets `$timeout = 60` so a hung Mollie HTTP call (the SDK uses a very generous default) can't outlast the queue's visibility window. Without it, a stuck `GetPaymentRequest` could keep the job running past `retry_after`, the queue would re-deliver it, and the second pickup would fail immediately with `MaxAttemptsExceededException` because `attempts() >= tries`.
- `CleanupOrphanedBillablesJob` now handles `ModelNotFoundException` from `$billable->refresh()` — when an earlier billable in the same chunk cascade-deletes a later one via the app's cleanup closure, the job skips the disappeared row instead of bubbling the exception into the outer try/catch (which would have logged a misleading warning).

## [0.3.0] - 2026-05-20

### Fixed

- Mail notifications no longer render a duplicate closing line. The custom `signature_line` ("Thanks, the :app team.") was appended on top of Laravel's default `Regards, :app` salutation; the custom line has been removed from all notifications and the `billing::emails.signature_line` translation key dropped.
- Trial extensions now always patch the Mollie subscription's `startDate` to the new trial end — both via `TrialExtension` coupon and via direct `Billable::extendBillingTrialUntil()` calls (e.g. from admin UIs). Previously only the local `trial_ends_at` was updated, so Mollie still charged at the originally scheduled date. The Mollie sync is centralized in `HasBilling::extendBillingTrialUntil()` and skipped when the effective end does not move (no redundant PATCH). New `MollieSubscriptionPatcher::setNextChargeDate()`.

### Changed

- `billing:simulate` no longer asks for multiple flows at once. The interactive picker now offers a single flow at a time and loops back to the menu after each run, so multiple simulations can be chained without restarting the command. The dispatch step prints an "Expected:" block (status / events / notifications) before running and a "Result:" + "Verification:" block afterwards with ✓/✗ for each expectation — events and notifications are captured via runtime spies, not faked, so the simulated side-effects still happen.

### Documentation

- New "Choosing the locale per recipient" section in [docs/notifications.md](docs/notifications.md) and a cross-reference in [docs/translations.md](docs/translations.md). Explains how to deliver per-customer email languages via Laravel's `HasLocalePreference` contract — no package config required.

## [0.2.9] - 2026-05-19

### Added

- New `billing:simulate` and `billing:webhook-replay` Artisan commands plus `Testing\LifecycleSimulator` service for reproducing subscription-lifecycle transitions (trial end, renewal, scheduled change, overage charge, past-due auto-cancel, cancelled→expired) and replaying Mollie payments through the webhook handler on non-production systems. See [docs/testing-flows.md](docs/testing-flows.md).

### Fixed

- Country mismatch detected during a `mandate_only` webhook no longer leaves the billable permanently un-activatable: the trial/coupon activation now runs before the country-match check, so `subscription_plan_code`/`interval`/`source` are persisted before the mismatch path triggers cancel-at-period-end. `ResubscribeSubscription` can recover the billable after the user resolves the mismatch in the portal. Same ordering fix applied to the first-payment and local→Mollie upgrade paths (`FirstPaymentArtifacts::persist()` no longer runs the country-match check internally; callers invoke it after the subscription is fully active).
- Checkout gate: a billable in local `PastDue` while Mollie still has an `active`/`pending` subscription (e.g. trial expired before Mollie's first charge fell due) is now redirected from the checkout to the dashboard instead of triggering a 422 "same description already exists" on `CreateSubscriptionRequest`. Stale `mollie_subscription_id` entries (Mollie reports `canceled`/`completed`/`suspended` or 404) are removed from `subscription_meta` so the next checkout attempt creates a fresh subscription. The dashboard surfaces the upcoming-charge date and offers a "Charge now" button that PATCHes the Mollie subscription's `startDate` to today. Lookups are cached for 60 seconds. New `Services\Billing\MollieSubscriptionGate`.
- `SubscriptionPaymentHandler::paid()` now flips a `PastDue` billable back to `Active` on a successful recurring charge (previously only `Trial → Active` was handled), and clears the `payment_failure` / `past_due_since` markers from `subscription_meta`. Without this, a recovered subscription would keep showing the red "overdue" banner and badge in the portal even though the invoice was paid and persisted. Mirrors the cleanup that `ProrataChargeHandler` already does for the Past-Due-Reset plan-change path.

## [0.2.8] - 2026-05-19

### Changed

- **BREAKING**: `SubscriptionCatalogInterface::usageRollover()` now takes a usage type instead of a plan code. Configure rollover per usage type via the new `usage_types.<type>.rollover` block in `config/mollie-billing-plans.php`.
- **BREAKING**: Removed `BILLING_USAGE_ROLLOVER` / `config('mollie-billing.usage_rollover')` and the per-plan `plans.<code>.usage_rollover` override. Replaced by `BILLING_USAGE_ROLLOVER_FALLBACK` / `config('mollie-billing.usage_rollover_fallback')`. `php artisan billing:check-config` fails hard on the legacy keys with a migration hint.

### Fixed

- `MandateOnlyPaymentHandler` is now idempotent against re-entry: a second `mandate_only` webhook for an already-activated billable no longer resets `subscription_status` to `Trial` or extends `trial_ends_at`. The dispatcher reloads the billable from the DB before the `hasAccessibleBillingSubscription()` guard, and both internal activation paths (`activateTrialSubscriptionAfterMandate`, `activateCouponSubscriptionAfterMandate`) bail out early when the status is no longer `New`.

## [0.2.7] - 2026-05-19

### Added

- `BILLING_MOLLIE_KEY` env alias for `MOLLIE_KEY` from `mollie/laravel-mollie` (via the new `mollie_api_key` config key). When set, the service provider propagates the value into `mollie.key` at boot, so all package settings can stay on the `BILLING_*` prefix. The existing `MOLLIE_KEY` continues to work unchanged.
- `MollieBilling::useNotification($original, $replacement)` lets apps replace any built-in notification class (trial reminders, payment failures, invoices, admin alerts, …) with their own. All package call sites now resolve notifications through `MollieBilling::resolveNotification()`, so a single registration swaps the dispatched mail/channel/template globally without touching package code. See [docs/notifications.md](docs/notifications.md).

### Fixed

- Country-block middleware (`BlockRestrictedCountries`) now uses the same cache as the checkout default-country resolver. Previously `IpGeolocationManager::getCountry()` reached straight through to the driver on every call, so every request to a protected route triggered a fresh ipinfo.io / db-ip.com lookup. Caching has been pulled down into `getCountry()` (24h on success, 1h on negative) so both the UX resolver and the middleware share one cache key per IP.

## [0.2.6] - 2026-05-18

### Fixed

- Trial state is now cleared whenever a non-trial subscription is activated. `CreateSubscription` resets `trial_ends_at` to `null` when no `trial_days` is passed, and the recurring-payment webhook handler also clears it on the Trial→Active flip. Previously the trial banner and "Testphase" badge stayed visible after a billable upgraded from a local trial to a paid Mollie subscription, because `trial_ends_at` was preserved.
- Plan changes paid via a prorata charge now end an in-flight trial. `ProrataChargeHandler::paid()` flips `subscription_status` to `Active` and clears `trial_ends_at` when the billable was on `Trial`, the same way it has always done for `PastDue`. Previously a trial user clicking "Plan wechseln" in the portal would be charged the prorata amount but keep the trial banner and "Testphase" badge.

## [0.2.5] - 2026-05-18

### Added

- Admin coupon-create form now exposes a wallet-credit editor for `credits` coupons. Renders one numeric input per declared usage type (from `allUsageTypes()`) and writes the entered amounts into `credits_payload`. Previously the type was selectable but had no UI to specify which wallet to top up or by how much.
- Admin invoice list now has a "Regenerate PDF" action per row. Uses the new `InvoiceService::regeneratePdf()`, which deletes the previous PDF file before re-rendering and dispatches a new `InvoicePdfRegenerated` event. Useful when the initial PDF render failed or stored a corrupted file — invoice data, serial number and amounts stay unchanged.
- `Billable::setBillingName(string $name)` companion to `getBillingName()`, plus an overridable `billingNameAttribute(): string` hook on `HasBilling`. The checkout now routes the company-name input through these instead of force-filling `name` directly, so apps that use a `User` as the billable can persist the company name into a dedicated column (e.g. `practice_name`) without overwriting the user's personal name. Default behavior is unchanged (both read/write `name`).

## [0.2.4] - 2026-05-13

### Fixed

- Portal dashboard now shows the trial end date as "Next billing" while the billable is still on trial — previously it displayed `period_starts_at + 1 interval`, which was incorrect because the first real charge happens at trial end. The change is display-only; `nextBillingDate()` semantics are unchanged.
- Redeemed-codes table rendered the raw ICU plural string (`{1} :days day|[2,*] :days days`) for trial- and grant-extension coupons. Switched to `trans_choice()` so the correct plural form is shown.

## [0.2.3] - 2026-05-13

### Fixed

- `/billing/admin/*` was loaded outside the `web` middleware group, so `$request->user()` returned `null` and every request to the admin panel returned 403. The admin route group now includes the `web` middleware so the session-driven user is resolved before `AuthorizeBillingAdmin` runs. Consuming apps no longer need to wrap the package's auto-loaded admin routes themselves.

## [0.2.2] - 2026-05-13

### Fixed

- Removed ⚡ (U+26A1) prefix from all Volt SFC filenames. The character did not survive GitHub's zipball distribution on some hosts (e.g. Laravel Cloud), making `mollie-billing::checkout` and other Volt components unresolvable in production. Livewire's Finder resolves these files without the prefix as well, so behavior is unchanged on environments where the prefix did work.

## [0.2.1] - 2026-05-13

### Fixed

- Usage-history Livewire view crashed on `bavix/laravel-wallet ^12.0` because `Transaction::TYPE_WITHDRAW` / `TYPE_DEPOSIT` constants were removed in favor of the `TransactionType` enum. Switched to raw string comparisons so the view works on both v11 and v12.
- `mollie-billing::checkout` (and other Volt SFCs in the package) could not be resolved on environments that run `view:cache` during deploy (e.g. Laravel Cloud). The package now additionally mounts its Volt view directory via `Volt::mount(...)` when `livewire/volt` is installed, so Volt's `ComponentResolver` can locate the package's single-file Volt components.

## [0.2.0] - 2026-05-13

### Added

- Laravel 13 support. `bavix/laravel-wallet` constraint widened to `^11.5|^12.0`; Pest constraint widened to `^3.0|^4.0`; `mpociot/vat-calculator` constraint bumped to `^3.26` (Laravel-13-compatible release available directly on Packagist).
- CI matrix expanded to test PHP 8.3/8.4 × Laravel 12/13.

### Changed

- CI: allow manual workflow runs via `workflow_dispatch`.
- Drop Laravel 11 support — `elegantly/laravel-invoices ^4.8` requires Laravel 12+. Composer constraint narrowed to `^12.0|^13.0`.
- `livewire/flux-pro` moved from `require` to `suggest`. The consuming application must install it separately with its own commercial license; this package no longer attempts to pull it from the private Flux repository.

## [0.1.0] - 2026-05-13

Initial public release.

# Changelog

All notable changes to `graystackit/laravel-mollie-billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

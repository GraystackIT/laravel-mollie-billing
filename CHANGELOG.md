# Changelog

All notable changes to `graystackit/laravel-mollie-billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Admin panel is fully translated via the new `billing::admin` lang file (English and German).
- `mollie-billing.admin_locale` (`BILLING_ADMIN_LOCALE`): pins the admin panel to a fixed locale. `null` (default) follows the app locale.
- `mollie-billing.vat.seller_country` (`BILLING_SELLER_COUNTRY`) and `vat.require_seller_country`: your own country, which is what separates a domestic business customer (owes domestic VAT) from a cross-border one (reverse charge). Falls back to `invoices.seller.address.country`.
- `mollie-billing.checkout_country_fallback` (`BILLING_CHECKOUT_COUNTRY_FALLBACK`, default `DE`): the VAT country used when a billable has none persisted. Replaces five scattered `?? 'DE'` literals that checkout and webhook could drift apart on.
- `mollie-billing.invoices.locale` (`BILLING_INVOICE_LOCALE`) plus a nullable `billing_invoices.locale` column: pins the language a PDF renders in, so a regenerated document matches the archived one.
- `MollieBilling::routes()` — the mounting call README documented all along but that did not exist.
- `MollieBilling::flushCallbacks()` for use in `tearDown()`.
- `brick/money` is a declared dependency. It was already installed transitively and used directly by the invoice and money code, which would have broken on any install that resolved it away.
- Cancelling a subscription from the admin panel requires an operator reason, recorded in the audit trail. A forced cancel is irreversible — a Mollie subscription can only be recreated, never resumed — and it used to be a one-click confirm whose trail said an admin did it but never why. `CancelSubscription::handle()`, `Billable::cancelBillingSubscription()` and the `SubscriptionCancelled` event each take a trailing optional `?string $reason`, so existing callers and listeners are unaffected.
- Bulk wallet credits require an operator reason, recorded on every credited wallet and in the audit trail instead of the hardcoded `bulk credit`.
- Revoking an access grant requires a reason. It is appended to the stable `admin_revoke` marker (`admin_revoke: <reason>`), so the marker stays greppable in every locale while carrying the why, and the audit entry records it.
- **Chrome end-to-end test suite** (`composer test:browser`). Drives the real checkout, portal and admin UI against a fake Mollie, with Flux Pro installed as a dev dependency — the default feature suite cannot render a single portal screen, because Flux ships under `suggest` and is absent there. Mollie is faked at the SDK's own `HttpAdapterContract` seam, so every typed request and response hydration is the real code path; the hosted payment page is a page the browser genuinely navigates to, with pay/fail/cancel/expire buttons and a card-country selector feeding the three-way VAT check. 34 scenarios cover checkout (including trials and coupons), plan changes, addons, seats, one-time products, renewals, dunning, overage, expiry, VAT numbers, the country mismatch and the admin panel. Every step is screenshotted; `composer browser:gallery` turns them into a browsable HTML gallery. See [docs/browser-testing.md](docs/browser-testing.md).
- **Dev-workflow note:** `livewire/flux-pro` is now a `require-dev` dependency (with a `composer.fluxui.dev` repository entry), because the browser suite cannot render a screen without it. `composer install` therefore needs Flux Pro credentials in an `auth.json`; see [docs/browser-testing.md](docs/browser-testing.md). Runtime is unaffected — Flux stays under `suggest` for consumers.
- `resources/css/app.css` — the reference stylesheet consuming apps need (Tailwind + Flux, scanning this package's views). It doubles as the browser suite's build input.
- New `subscription_meta` key `activation_claim`: an in-flight subscription activation's durable claim, so a concurrent payment backs off instead of creating a second Mollie subscription. Removed on both the success and the failure path; expires after `WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES`.
- New `subscription_meta` keys: `cleanup_vetoed_at` (orphan cleanup vetoed by the app), `usage_overage_counted_payments` (dunning budget, per payment id), `trial_ending_notified_for` (the `trial_ends_at` date already warned about), `orphaned_prorata_payment` (a prorata charge Mollie collected but no webhook completed).

### Changed

- `SubscriptionCatalogInterface` requires two new methods: `planOffersInterval(string $planCode, string $interval): bool` and `addonOffersInterval(string $addonCode, string $interval): bool`. Apps with their own catalog implementation must add them — they answer whether the interval is *sold*, which the price getters cannot express (`0` means both "free" and "not offered"). Apps extending `ConfigSubscriptionCatalog` need no change.
- `mollie-billing.header_components` now also render in the admin header, so a language switcher works there too.
- **Breaking-ish:** the admin panel no longer forces English. Set `BILLING_ADMIN_LOCALE=en` to restore the previous behavior.
- **Breaking (tax):** a B2B customer in your own country is now invoiced with domestic VAT instead of 0% reverse charge. Set `BILLING_SELLER_COUNTRY` so the package can tell domestic from cross-border; while it is unset the previous behaviour is kept and a warning is logged.
- **Breaking (API):** `RefundInvoiceService::callMollieRefund()` takes a `string $currency` and returns the Mollie refund id. Test doubles and subclasses overriding the old 3-argument version fail with a PHP fatal.
- `AdminRefundFailedNotification` takes an optional third constructor argument: the refunded `BillingInvoice`. Existing replacements registered via `useNotification()` keep working — the argument is trailing and optional — but add it to get the correct invoice instead of the narrowed guess.
- `CountryMismatchResolved` carries an optional `?string $justification` as its fourth constructor argument, so the audit listener can record why an operator resolved a mismatch the way they did.
- New `VatCalculationService::reverseChargePolicyWithoutSellerCountry()` is the single decision point for reverse charge when no usable seller country is configured; the checkout reads it instead of reimplementing the condition.
- `mollie-billing.mollie_locale` is passed to every customer-facing Mollie payment, so hosted pages follow the configured language. It was read by no code path before.
- **Removed** `mollie-billing.require_payment_method_for_zero_amount`. It was read nowhere, and its shipped default (`true`) described the opposite of the deliberate behaviour — free plans run as `SubscriptionSource::Local` without a mandate — so honouring it would have changed behaviour for every existing install.
- Notifications are queued onto the `queue` config block. Replacement classes registered via `resolveNotificationUsing()` must therefore be serializable; see [docs/notifications.md](docs/notifications.md#queueing).
- Docs: `notifications.md` now spells out that `notifyBillingAdminsUsing()` / `notifyAdminUsing()` recipients must be notifiable, including the `routeNotificationForMail()` recipe for returning the billable itself.

### Security

- Plan change validates its selection. An unknown plan code or interval priced as free — the catalog resolves an absent (plan, interval) pair through `basePriceNet() ?? 0` and `seatPriceNet() ?? null`, and `ValidateSubscriptionChange` only rejects *hidden* plans — so a client-substituted value turned a paid subscription into a downgrade-to-free: a real Mollie refund of the unused period, the subscription cancelled, and access left open forever. `#[Locked]` cannot substitute for this: the plan selector is exactly where the customer is *meant* to write, so locking the property would break the feature — and locking answers "did the client change this" rather than "is this a plan they may have", which is the actual question.
- `enableAddon()` checks the plan allowance. It accepted any code from the client, and `ValidateSubscriptionChange` skips addon validation unless the plan changed — so an addon reserved for a higher tier was billed and `FeatureAccess` granted its features.
- Checkout: `billableId` / `billableClass` / `billing_locked` are `#[Locked]` and `submit()` authorizes the billable via `MollieBilling::authorizes()` (anonymous signup exempted), so a crafted Livewire payload can no longer repoint the checkout at another tenant and overwrite its billing address, VAT number and Mollie payment.
- Checkout: `vatNumberValid` is `#[Locked]` and reverse charge additionally requires a non-empty VAT number, so the client can no longer strip VAT off the Mollie amount. A persisted VAT number only pre-confirms when its VIES audit row says `valid`.
- Plan change: `preview` is `#[Locked]` and `confirmAndPay()` re-prices the local→Mollie upgrade through `PreviewService` (including seats and addons), so the Mollie amount no longer comes from a tamperable snapshot.
- Portal: every mutating action on the dashboard (`cancelSubscription`, `endTrial`, `resubscribe`, `saveCountryFix`, `redeemCoupon`, …) and on the billing-data page (`save`, `changePaymentMethod`) now calls `MollieBilling::authorizes()`. Livewire actions bypass the portal route middleware, so a member denied billing management could previously cancel the tenant's subscription or rewrite its VAT number.
- Expiring a subscription now clears the plan-scoped fields (`subscription_plan_code`, `subscription_interval`, `active_addon_codes` and the plan-scoped `subscription_meta` keys), the same wipe `revokeFullGrant()` already performed. The plan code used to outlive the subscription, so `@planFeature` and the `billing.feature` middleware kept resolving a lapsed access grant's features. `FeatureAccess` itself stays a pure catalog lookup — consuming apps gate navigation and whole modules on `hasPlanFeature()`, so answering "may they use it" there would black out every billable that carries a plan code without a live subscription.
- `AuthorizeBillingAdmin` is registered as Livewire persistent middleware, so admin *update* requests are authorized and not just the initial page load. An operator whose `canAccessBillingAdmin()` became false could otherwise keep calling `forceCancel`, `refund` and the bulk actions from an already-loaded tab.
- **The portal routes now carry `billing.portal`.** The alias and the Livewire persistent-middleware registration both existed, but no route ever applied it — and persistent middleware is only re-applied if the original route carried it, so both were dead and the initial portal page load was unauthorized. Note this **fails closed**: an app that never registers `MollieBilling::authUsing()` now gets 403 on the portal rather than being served. `return` (the landing page for an anonymous signup, whose billable has no owner yet) and `invoice.download` (which authorizes itself, so a billing admin who is not a member of the billable can still fetch the PDF) stay outside it deliberately.
- Portal read paths — dashboard, invoices, billing data, addons, seats, usage history, plan change, products — verify `MollieBilling::authorizes()` themselves as well. The mutating actions were hardened in an earlier pass but the reads were not, and a Livewire update request re-renders the component.
- Checkout prices a `billing_locked` order from the billable's **persisted** country and VAT number. It honoured the lock for the database write but still priced from the client-controlled properties, and `totals()['gross']` goes to Mollie verbatim — a live VAT-stripping path.
- The VAT number is frozen alongside the billing country while a country mismatch is open. It decides the same tax treatment, so freezing only the country left the decision changeable.
- `products::purchase()` re-checks `availableBillingProducts()`, so a product marked purchasable once per billable cannot be bought twice through a crafted Livewire call. Nothing downstream caught it — `StartOneTimeOrderCheckout` never reads the flag.

### Fixed

- **Security:** a paid plan could be activated for **0 €** by selecting an interval the plan does not offer. A plan may legitimately be sold in one interval only, but `basePriceNet()` returns `0` for a missing interval — indistinguishable from a free plan — so the zero-amount checkout branch activated a Local subscription on the paid plan, with every feature, indefinitely, without a payment or a Mollie call. No tampering was needed beyond clicking the interval toggle. `SubscriptionCatalogInterface` gained `planOffersInterval()` / `addonOffersInterval()`; checkout, plan change, addon enable and `StartSubscriptionCheckout` all consult them, the interval toggle only lists intervals the plan is sold in, and the service throws `IntervalNotOfferedException` rather than trusting a zero.
- A trial-extension coupon entered at checkout now actually extends the trial. It was accepted by the form, then dropped twice over: the trial gate read a plan-level `trial_days` the package no longer supports (so only a billable *already* on trial could pass it), and the activation stashed only `single_payment` and `recurring` codes — a customer saw the coupon applied and the trial ended on the plan's own day, with no redemption on record.
- A recurring coupon applied to a **trial** signup reaches Mollie. The trial branch created the subscription at the full price while writing a marker that promised the discount, so Mollie collected full price from the trial's end onward and the renewal invoice reconciled down to it — the discount existed only on paper. The marker is now written before the Mollie call, as the non-trial path already did.
- A trial and a fully covering recurring coupon on the same signup no longer charge during the free months: the Mollie start date is the later of the trial end and the coupon's lifetime, instead of always the trial end.
- A trial-extension coupon is refused on a paid first payment, and on a plan or customer for whom no trial starts. Applied there it would set `trial_ends_at` days ahead on a paying subscriber and PATCH Mollie's next charge to that date — billing them again within the week.
- The checkout summary states what a trial-extension coupon buys ("+7 days of trial", and the trial callout counts plan + coupon days) instead of pricing it as "−0.00".
- A converted trial reports the right next billing date. `next_charge_date_override` — set to delay ONE charge, by a trial extension or a period extension — survived the charge it delayed and kept outranking the paid period's anchor, so a converted trial's next-billing date read a month early. Everything downstream inherited it: the overage pass fires the day before that date, and a prorata plan change measures its factor against it.
- Country-mismatch resolution works again. The resolvability guard accepted only the payment and IP signals, but a mismatch exists *because* the signals disagree — so the user-declared country, one of the two options the portal modal and the admin screen offer, always threw, leaving the mismatch Pending with no way to resolve it while the subscription ran toward cancellation.
- `CleanupOrphanedBillablesJob` asks Mollie unconditionally before deleting. Its "did we collect anything?" guard sat behind an `elseif` and never ran on the path that deletes, and the pending-payment marker only tracks the latest attempt — so a first payment that settled but whose invoice write failed got the billable deleted and a paying customer's mandate revoked.
- `vat_calculator.forward_soap_faults` is set in the provider's `register()`. Flipped from `VatCalculationService`'s constructor it arrived after the injected calculator had snapshotted its config, so VIES SoapFaults still collapsed to a bare `false` and an `INVALID_INPUT` answer was reported to the customer as "VIES unavailable" indefinitely.
- Queued jobs and notifications are deferred until the surrounding transaction commits. Nearly all of them are dispatched inside one, so a worker could dequeue first: an invoice mail whose row did not exist yet failed `firstOrFail()` into `failed_jobs` with no retry, and a subscription PATCH could reach Mollie for a change the transaction then rolled back.
- A permanently failed overage charge no longer silences every later one, and its dunning counter is reset when a new charge cycle starts — it stayed at the maximum after a give-up, so the next period's first failure went straight to `past_due` plus customer and admin mail.
- `billable_type` is persisted as the class name, not `getMorphClass()`, wherever a retry job resolves it. Under `Relation::enforceMorphMap()` the alias failed `class_exists()`, so the PATCH never landed *and* the marker was never cleared — an hourly retry and admin alert, forever, while Mollie charged the stale amount.
- `PlanChangeIntent::fromArray()` resolves the billable scope-free, like every other retry-path lookup; with an app-defined `applyBillingScope()` the worker found nothing and gave up without ever patching Mollie.
- The stale-prorata cleanup asks Mollie before acting on an entry's age. It deleted on age alone, so a charge that had been collected was discarded with an alert saying only "deleted after 7 days without webhook"; the paid branch was an abandoned stub that logged at info level and notified nobody.
- The orphaned-prorata marker is consumed rather than only written: the admin alert now carries the intended change and its charge lines, and the marker is cleared.

- Reverse charge is decided in one place. `usesReverseCharge()`, `PreviewService` and the checkout each answered "is there a valid VIES row" on their own while the charge also compared the seller country, so a domestic B2B customer was shown net prices with a "Reverse charge" badge and then billed gross. All three delegate to `VatCalculationService::reverseChargeApplies()`.
- The webhook no longer clears `pending_first_payment_id` on `paid` before the paid handlers have run. An activation that threw afterwards left a billable at New/None holding a paid invoice, collected money and a live mandate — which looked abandoned to `CleanupOrphanedBillablesJob`, and it deleted the customer and revoked their mandate. The job additionally refuses to delete anything that has an invoice or a paid Mollie payment.
- `PrepareUsageOverageJob` skips a billable whose overage charge is still in flight. The wallets stay negative until the payment settles — days, with SEPA — so the daily pass created a second charge for the same overage.
- The wallet adjuster receives the old period boundaries explicitly at all four call sites. The ordering was load-bearing and undocumented: with the new interval already saved, `nextBillingDate()` returned old-start-plus-*new*-interval, which collapsed the elapsed fraction and billed a customer's in-quota usage as excess.
- Tests: `MollieBilling`'s callbacks and fake are flushed in `tearDown()`. They live in statics and outlived the application instance that registered them, so a closure querying a rolled-back billable decided authorization for unrelated later test files — which is why the failing set shifted as file order changed.
- Portal and checkout money rendering goes through `BillingMoney`: the currency symbol honours `mollie-billing.currency_symbol` and the built-in ISO map (`GBP12.34` → `£12.34`, `CHF12.34` → `CHF 12.34`), decimal separators follow the locale, and invoice rows use each invoice's own `currency` instead of one global symbol.
- The local→Mollie upgrade confirmation showed a zero net line (`$preview['newNet']` never existed) next to a correct total.
- `mollie-billing.show_yearly_savings` is honoured by the plan selector's savings badges; it was read by no code path before.
- Outbound Mollie amounts and the portal's rendered prices derive their decimal count from the currency (`BillingMoney::mollieAmount()` / `minorUnitFactor()`). Seven call sites hardcoded two decimals, so with `BILLING_CURRENCY=JPY` a ¥2900 plan was charged as ¥29 while the invoice booked 2900.
- The amount charged for a set of line items is the sum of the per-line VAT, matching how every invoice header is booked. Rounding VAT on the summed net instead made the collected and the invoiced amount differ (plan 550 + addon 550 at 19%: 1309 collected vs 1310 invoiced) — affects the recurring subscription amount, the plan-change PATCH, usage-overage charges and discounted one-time orders.
- `ProrataComposer::planRefundLine()` caps the refund at the line's own net, like its sibling `buildRefundLine()`. A period whose start lies in the future yields a pro-rata factor above 1, and only the invoice-level pool bounded it — which on a multi-line invoice is larger than the line.
- `mollie-billing.header_components` are keyed with `key(...)` in the portal and checkout layouts — the `@livewire` compiler silently dropped the previous third argument.
- Cancelling during a trial no longer grants a full unpaid billing period as grace: the boundary is capped at `trial_ends_at` instead of `subscription_period_starts_at + 1 interval` (a yearly trial cancelled on day 2 handed out roughly a free year that no automatic pass reclaimed).
- Resubscribing inside the grace period now restores the previous state instead of starting a new one: a cancelled trial comes back as a `Trial` on its original end date rather than `Active` with nothing paid, Mollie's first charge is anchored to the end of the already-paid period rather than one interval from now (days 30–59 of a monthly subscription used to go unbilled), and a duration-limited local access grant keeps its expiry instead of becoming perpetual.
- `hasAccessibleBillingSubscription()` consults `subscription_ends_at` for `Active` subscriptions, so AccessGrant / `ActivateLocalSubscription($durationDays)` durations are actually enforced. A 30-day grant no longer grants access forever, keeps recharging wallet quotas and blocks re-checkout. Mollie-source subscriptions keep `ends_at = null` and are unaffected.
- Mollie cancellation errors are no longer all swallowed alike: "already gone" (404/410) stays silent, while an unreachable Mollie is recorded as `subscription_meta['pending_subscription_cancel']` and reported to the admins instead of leaving Mollie billing a customer whose access ended.
- `ActivateLocalSubscription` resets plan-scoped `subscription_meta` (`seat_count`, `active_recurring_coupon`, pending markers, `next_charge_date_override`) and `scheduled_change_at`, so a billable coming from a larger tier no longer keeps e.g. 10 seats on a plan that includes 1. Mollie identity keys are preserved.
- Scheduling a change no longer drops paid extra seats: the auto-derived count includes `getBillingSeatCount()`, and a derived count is re-derived at apply time instead of being replayed as an explicit one (which bypassed the validator's clamp and threw `SeatDowngradeRequiredException` at period end for a change accepted at schedule time).
- Cancelling a subscription clears any scheduled change, and `ScheduleSubscriptionChange::apply()` refuses to run for a subscription that is no longer active. Previously a scheduled upgrade still rewrote plan/addon codes, rebalanced wallets and could charge overage against a cancelled customer — and an expired billable re-failed the daily job forever.
- A pro-rata executor no-op is no longer mistaken for "Mollie handled it": the branch is derived from the path the executor actually took. A free downgrade now cancels the Mollie subscription instead of only dropping `mollie_subscription_id` (orphaning a live subscription), and `mollieSubscriptionPatched` reflects reality.
- `CouponService::redeem()` enforces the per-billable redemption cap inside its `lockForUpdate` transaction, so a double-clicked Credits coupon can no longer credit the wallet twice.
- Renewals of an already-granted recurring coupon no longer consume the global `max_redemptions` cap: it sizes a campaign in customers, so 20 monthly subscribers used to exhaust a 50-redemption coupon and lock out new ones while the discount kept applying with no redemption row written. Renewals still write their audit row.
- `revokeFullGrant()` no longer wipes the local state of a billable whose grant has been superseded by a paid Mollie subscription (it would strip plan code and source while Mollie kept charging). The redemption is still revoked and the coupon slot freed.
- `CouponService::update()` applies the same type and discount validation as `create()`, so a Percentage coupon can no longer be raised above 100% after creation and flow uncapped into the recurring marker.
- `EnableAddon` / `DisableAddon` compute the addon list under the row lock, so two near-simultaneous changes no longer silently disable each other's just-paid-for addon.
- AccessGrant `minimum_order_amount_net` is checked against the net order amount instead of the VAT-inflated gross.
- A one-time order fully covered by coupons books exactly 0 instead of ±1 cent: coupon VAT is allocated from the cumulative discount so per-line roundings sum to the product's own VAT.
- Pro-rata refund lines are computed from the line's actual net rather than a rounded per-unit price, so a full-quantity refund can no longer exceed what was paid (1001 over 2 seats used to refund 1002).
- A failed Mollie subscription PATCH now actually dispatches `RetrySubscriptionPatchJob` at all three marker sites instead of only writing a marker nothing read, and `billable_type` is normalised to the configured billable FQCN so the job can resolve morph-mapped models.
- Cancelling a pending plan change keeps a still-live Mollie payment reconcilable: an already-settled payment refuses the local clear, and an open-but-not-cancelable one is recorded as `subscription_meta['orphaned_prorata_payment']` with an admin notification.
- Interval changes are priced by one rule again — `BillingPolicy`'s "full new price − unused credit". The composer charged the new interval's price scaled by the *old* window's remaining fraction while granting a full new period, so monthly → yearly on day 28 of a month was a ~93% undercharge.
- Negative amounts render as `-€5.00` instead of `€-5.00` — `BillingMoney::format()` resolves the sign itself and formats the magnitude, so the minus precedes the currency symbol.
- `mollie-billing.currency_symbol` no longer defaults to `€`, which made the built-in symbol map unreachable for the configured currency: `BILLING_CURRENCY=GBP` alone rendered `€` everywhere. Unset it and the symbol is derived from `currency` (`GBP` → `£`, `CHF` → `CHF `); set it only to override.
- Admin money output honors `mollie-billing.currency_symbol` and the active locale's digit grouping. New `Support\BillingMoney::format()` backs the `<x-mollie-billing::admin.money>` component, the refund detail's net/VAT line and the billable invoices tab (refund modal plus the "exceeds remaining" errors, which previously rendered a bare number with no currency symbol at all). Amounts follow the invoice's own currency where one is stored. Falls back to `number_format()` when ext-intl is absent.
- Admin layout title now falls back to `config('app.name')` when `mollie-billing.company_name` is null or empty (the config key always exists, so `config()`'s default argument never applied).
- Aborted `mandate_only` payments (portal payment-method change, trial / 100%-coupon checkout) no longer flip an active or trialing subscription to `past_due`: the webhook failure path now short-circuits them instead of falling through to the subscription-payment-failure handler. Without an accessible subscription the abort still runs the after-checkout callback with `success = false`.
- Every amount leaving for Mollie is scaled by its own currency, via `BillingMoney::mollieAmount()`. A hardcoded ×100 charged a JPY plan 1/100th of its price and read a JPY refund as a hundred times its value; `BillingMoney` is now the single home for the scale in both directions and `InvoiceService::molliePrice()` / `mollieAmountToMinor()` / `normalizeCurrency()` delegate to it.
- The amount collected equals the amount booked. Every invoice derives its header VAT by summing per-line VAT, but the charge sites rounded VAT once over the total — with six lines the two differ by enough to trip the mismatch guard on every renewal. New `BillingMoney::grossWithLineVat()` is used by both sides.
- A prorata plan refund is capped at the cash actually collected for that period, so a mid-period change after a discounted or partially-refunded charge can no longer pay out more than came in.
- Dashboard-initiated Mollie refunds derive the credited net from the invoice's own line composition instead of line[0]'s VAT rate. On a mixed-rate invoice the old reading lost money and could never reach fully-refunded; new `InvoiceService::creditNetForGross()` resolves it, and `createCreditNote()` rejects a gross above the invoice total with `RefundExceedsInvoiceAmountException`.
- Repeated partial refunds can no longer credit more VAT than was charged. Each credit note now carries a per-line VAT budget read from prior credit notes, and the chunk that exhausts a line absorbs the rounding residual.
- A discount line whose VAT rate matches no other line keeps its VAT when credited; the fold that merged negative parts dropped it.
- A caller-supplied per-line `vat_rate` can no longer put VAT on a reverse-charged invoice.
- Saldo-zero plan-switch invoices inherit the refunded invoices' currency instead of the configured one.
- The admin alert for a failed Mollie refund is sent from outside the refund transaction, so it survives the rollback that prompted it.
- Invoice serial allocation locks one index record instead of the year's rows. The `LIKE 'IN-25______'` scan cannot use a btree index on Postgres under a non-C collation, so `FOR UPDATE` locked every row it touched — which is what let an admin refund and a renewal webhook block each other. The bounded range also makes the `IN-` and `CR-` sequences disjoint. A counter that outgrows its slot width now throws instead of silently restarting at 1 into a unique-index violation.
- Patch-retry chains are keyed per intent, not per billable, so two pending changes no longer collapse into one; the marker is cleared only when its stored signature matches the intent that ran, and `uniqueFor` covers the full ~52 h backoff ladder.
- Every `subscription_meta` write on the retry paths runs against a row re-read under `lockForUpdate` (new `Support\Concerns\MutatesBillingMeta`), and sibling refund-line jobs are serialised per billable. Two jobs reading `[L1, L2]` and writing back `[L2]` / `[L1]` resurrected each other's line — and a resurrected refund line is a second payout. `InvoiceService::createRefund()`'s own append uses the same lock, since a plan change reaches it outside that serialisation.
- Overage dunning counts unsettled payments, not queue attempts, so Mollie API errors no longer consume the customer's retry budget and push them to `past_due` early.
- `CleanupStalePendingProrataChangeJob` and `CleanupStalePendingCountryCorrectionJob` paginate with `chunkById`; both mutate the column they filter on, so `OFFSET` pagination skipped a page of records for every page it cleaned.
- The table-walking jobs carry explicit `timeout` / `tries` / `backoff`. Without them a slow run outlasted the queue's visibility window and was re-delivered into an immediate `MaxAttemptsExceededException`, dropping the rest of that day's work.
- A vetoed orphan is remembered (`cleanup_vetoed_at`) instead of being re-examined every 15 minutes forever; delete the key to re-admit the row.
- `RevokeMollieMandateJob` treats a 404/410 from Mollie as success — the mandate is gone, which is the goal — and uses the typed `RevokeMandateRequest`.
- A permanently failed overage charge notifies admins from `failed()` and stays `pending` for the next daily pass rather than disappearing silently.
- `ProcessTrialLifecycleJob` warns about an ending trial exactly once (`trial_ending_notified_for`). Anything that re-entered the same window — a queue re-delivery, a second scheduler, a manual `queue:work --once`, or widening `trial_ending_soon_notice_days` — mailed the customer again. The job also carries an explicit `timeout`.
- `PropagateRouteDefaults` and `AuthorizeBillingPortal` are registered as Livewire persistent middleware, so portal *update* requests keep both their tenant route defaults and their authorization check.
- `billing:check-config` validates `mollie-billing.queue.connection` against the app's configured connections; a typo silently queued every billing job onto a connection with no worker.
- A `vat.seller_country` that is not an ISO-3166-1 alpha-2 code is rejected instead of flowing into the comparison and never matching. A typo (`AUT`, `Austria`, `de-AT`) silently reverse-charged every domestic B2B supply at 0%, with the seller owing that VAT out of pocket — which cannot be unwound, while an over-charged invoice can. Reverse charge is refused while the value stays malformed, regardless of `vat.require_seller_country`, and `billing:check-config` reports it as an error.
- Country-mismatch corrections gate on the effective refunded net rather than the raw `refunded_net` column, so an invoice credited without that cache no longer aborts the whole resolve with "Refund amount must be positive" and leaves the mismatch Pending forever.
- A country correction keeps each line's own VAT rate instead of stamping the destination's standard rate over all of them. An AT invoice of 2000 at 20% plus 1000 at 10% corrected to DE collected 35.70 instead of 34.80, and OSS mis-bucketed the reduced line — `OssProtocolService` buckets by the line's `vat_rate`. The correction charge is now summed from the line items the reissue invoice is built from, so collected and booked cannot differ.
- `CouponService::validate()` no longer counts revoked redemptions against `max_redemptions_per_billable`, which made a coupon permanently unusable for a billable after a grant was revoked. Renaming or deleting a coupon is blocked by its redemption rows rather than the revoke-decremented `redemptions_count`, so revoked audit rows can no longer be orphaned.
- `TrialEndingSoonNotification` captures the mandate state, trial end date and day count at construction. It is queued and `SerializesModels` re-fetches the billable, so a mandate arriving before delivery made the mail tell the customer the exact opposite of the truth.
- `AdminRefundFailedNotification` names the invoice the refund was posted against. It reported `latestBillingInvoice()`, which for any active customer is a later renewal or the credit note the failed refund itself produced.
- `TrialExpiredNotification`, `UsageThresholdNotification` and `TrialConvertedNotification` freeze their point-in-time values too: subscribing from the expiry mail's own CTA rewrote the date it reported, a plan change inverted "will be billed" into "will be blocked", and one payload could name a different plan than its own mail body.
- The admin audit tab locks `billableId`, so a scoped operator can no longer read another billable's activity trail by rewriting the property. The billable and coupon detail screens lock their routed target too, rejecting a retarget instead of silently ignoring it.
- The checkout grand total is assembled with per-line VAT (`BillingMoney::grossWithLineVat()`), so what the customer is shown, what Mollie collects and what the webhook books are the same number.
- Plan-change "Due now" reports the amount Mollie actually collects instead of the charge-minus-refund balance, and names the pro-rata credit as the separate refund it is. `prorataCreditGross` is grossed up from the credit's own net — the VAT probe resolves against whichever side is non-zero with the charge winning, so on an interval-change upgrade the panel showed the charge amount as the credit (33562 instead of 2438).
- Portal invoice summary totals are scoped to the configured currency instead of summed across currencies under one symbol; the dashboard's country-mismatch refund total is grouped per currency, since that figure names money that is about to move.
- Portal and checkout quota prices read the minor-unit scale off the currency, so a currency without two decimals no longer renders at the wrong order of magnitude.
- The checkout resolves the no-seller-country reverse-charge policy through `VatCalculationService::reverseChargePolicyWithoutSellerCountry()` rather than a local copy of the condition, which disagreed with the service for a malformed `vat.seller_country`.
- Every `\Flux::toast()` / `\Flux::modal()` call goes through the new `Concerns\InteractsWithFluxUi` guard. Flux Pro is absent from this package's own test environment, so each of these fataled with `Class "Flux" not found` and made the whole action leading up to it unreachable under test.
- Invoices book the currency of the payment they record, not today's `BILLING_CURRENCY`. A renewal whose webhook arrived after a currency switch was labelled with a currency the customer was never charged in — and a refund of it would be rejected by Mollie for a currency mismatch. `createInvoice()` gained an optional trailing `?string $currency`; only a purely local document (the zero-amount audit invoice for a fully coupon-covered order) still falls back to config, which is correct because it was priced just now.
- Repeated partial refunds can no longer over-credit VAT when an earlier credit note was created with custom `line_items`. Those lines carry no `parent_line_item_index`, so they were invisible to the invoice-wide "how much VAT is left" cap, which was computed by summing the per-original-line map. New `BillingInvoice::creditedVatTotal()` counts every credit line pointing at the invoice, indexed or not.
- **Pinned the renewal invariants that nothing asserted.** The billing period advances on every recurring payment, anchored on the payment's own `paidAt` rather than `now()` — a late webhook must not shorten or lengthen the period the customer paid for — and `nextBillingDate()` is what the overage pass, the dashboard and every prorata calculation key off. The behaviour was already correct; no test held it in place. Eight cases now do: the anchor, consecutive renewals walking forward without drift, yearly advancing by a year, rollover quota accumulating while non-rollover resets, a negative overage balance surviving the recharge, trial → active starting the paid period at the first charge, past-due recovery re-anchoring on the retry, and a redelivered renewal not advancing twice. A browser scenario shows the date moving on the dashboard.
- The checkout's "included seats" line rendered the raw plural source string (`3 seat incl.|3 seats incl.`) — a `trans_choice` string passed through `__()`. Found by the browser suite on its first run.
- After a failed first payment the customer stared at the "activating your subscription…" spinner until the poll timeout instead of seeing the failure. The return page's failure probe finds the payment through `subscription_meta.pending_first_payment_id`, and the webhook — which nearly always beats the customer's redirect — deleted that marker on `failed`/`canceled`/`expired`. The webhook now leaves it alone (`CleanupOrphanedBillablesJob` treats a billable carrying it more carefully, not less: it polls Mollie rather than judging by age), and the return page clears it once the failure has been shown.
- **A failed activation can no longer produce a second Mollie subscription.** The Mollie `CreateSubscription` call ran inside the same transaction as the invoice, the coupon redemption, the app listeners and the after-checkout hook — so anything throwing after it rolled back `mollie_subscription_id` and the invoice while the subscription existed at Mollie, and the re-delivery found a clean slate and created another one, charging the customer twice every period. Activation is now two-phase: a short gate transaction (row lock, guards, durable claim) and the activation itself outside any transaction. The row lock is also no longer held across arbitrary app code.
- A concurrent checkout tab's payment backs off with a 503 while another payment's activation claim is fresh, instead of racing it into a second subscription. The claim survives a crash, so the same payment re-claims immediately while a sibling waits out `WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES`; a dead delivery cannot block activation forever.
- Renewal invoices are reconciled to the amount that actually cleared, not just reported. A plan price raised while the Mollie PATCH never landed made the invoice book the catalog amount, over-declaring output VAT on every renewal and leaving `refunded_net` headroom Mollie cannot honour — so a full refund would fail.
- Every webhook amount is read at the scale of the payment's own currency. A hardcoded ×100 entered the invoice, the mismatch guard, the wallet settlement and the refund reconciliation at a hundred times the value for a zero-decimal currency.
- A single charge whose payment carries no `line_items` metadata — a payment in flight across a package upgrade — derives its net from the collected gross instead of booking the gross as net, which added VAT on top of VAT already collected (11.90 became an invoice of net 1190 / VAT 226 / gross 1416).
- The mismatch guards compare against the per-line gross, like the invoice header and the charge sites. Three places computed "the expected gross" and produced two different numbers, so a multi-line renewal or first payment reported a spurious `PaymentAmountMismatch` every time.
- The past-due retry action refuses any billable the list itself would not show, so a target that is not `past_due` can no longer be pushed into a dunning evaluation through a crafted call.
- The refunds screen no longer renders a regular invoice inside its credit-note detail modal; the id is checked on write *and* on read, so a row that stops being a credit note drops out of an already-open modal.
- Bulk wallet credits and trial extensions reject out-of-range amounts and a `usage_type` that is not in the plan catalog. An unvalidated type created a wallet nothing will ever charge or reset, and a mistyped order of magnitude was applied verbatim; a negative trial extension reported success while `extendBillingTrialUntil()` silently kept the previous date.
- Resolving a country mismatch requires an operator justification (max 500 characters). It is stored at `subscription_meta.tax_country_verified.justification` and recorded in the `country_mismatch_resolved` audit entry, which also gains `chosen_country` — the entry previously recorded that a resolution happened but neither which country won nor why.

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

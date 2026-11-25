# Changelog

All notable changes to `graystackit/laravel-mollie-billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **"Cancel pending change" no longer confirms a cancel that did not happen.** Once the
  prorata payment has been paid or authorized, `cancelPendingPlanChange()` deliberately keeps
  the change for its webhook to apply, but the plan-change screen toasted "cancelled" anyway
  and the notice stayed. It now says the payment has already gone through and the change will
  be applied. A cancel that did go through no longer adds a "plan changed" toast either.
  The new `UpdateSubscription::tryCancelPendingPlanChange()` returns a
  `PendingChangeCancelOutcome` (`Cancelled`, `Cleared`, `AlreadyPaid`); the bool of
  `cancelPendingPlanChange()` is unchanged.
- **A paid plan change survives a webhook run that dies part-way.** `ProrataChargeHandler`
  writes the charge invoice first and applies the change after it, and a re-delivery that
  found the invoice skipped the payment as done: a run that died in between (a fatal, PHP's
  time limit) left the customer charged, the change unapplied and Mollie on the old amount.
  Each completed step is now recorded under `pending_prorata_change.progress`, and the next
  delivery resumes after the last one. Wallet credits commit together with their record and
  each refund line keeps its Mollie idempotency token, so neither is repeated; the PATCH sets
  absolute values derived from the change, so a repeated one sets the same target. If a renewal was booked before the re-delivery, the subscription has ended,
  or the run predates the recorded steps, it is not resumed and the operators get an
  `AdminPlanChangeFailedNotification` instead, once. `CleanupStalePendingProrataChangeJob`
  leaves a run that can still be resumed alone for 48 hours from its start.
- **`CleanupStalePendingProrataChangeJob` really unblocks the customer.** For a paid charge
  whose change never arrived (and when Mollie stays unreachable) it dropped only
  `pending_prorata_change`; `pending_plan_change` and `prorata_pending_payment_id` stayed,
  so every further plan change was refused and the portal kept announcing a change waiting
  for its payment. It also says "booked" rather than "no invoice exists" when the payment's
  invoice is there, and does not alert again when the webhook already did.
- **The e-invoice no longer scans every class the app has.** `horstoeko/invoicesuite`
  discovers its document providers and validators by loading every class in Composer's
  class map and asking `is_subclass_of()`; with an optimised autoloader that is all of
  `vendor/`. On an app's first invoice that ran past PHP's 30-second limit (or died on a
  class that cannot be loaded), and in the prorata webhook the fatal came after the invoice
  had been written and before the paid plan change was applied. `EInvoiceService` now
  confines discovery to `horstoeko\invoicesuite` for the duration of a document, unless the
  app has set discovery namespaces of its own.

## [1.0.1] - 2026-11-25

### Added

- **Admin invoice list** (`billing/admin/invoices`): every invoice across all billables, with date range and quick filters, kind/status/search filters, per-currency sums, a detail modal, inline PDF view and download, and a ZIP export of exactly the filtered set, capped by `invoices.export_max` (`BILLING_INVOICE_EXPORT_MAX`, default 1000). The export needs the PHP `zip` extension; run `php artisan migrate` for the new `billing_invoices.created_at` index.
- The admin panel works in UTC throughout and labels every time it shows as such.

### Changed

- **A first-payment (`single_payment`) coupon is accepted on a checkout that starts a trial**,
  partial discounts as well as 100 %. It discounts the charge at the trial's end: the trial
  activation carries it over as a one-period `active_recurring_coupon` marker
  (`first_charge_only`), so Mollie's subscription is created at the discounted amount and the
  trial-end renewal PATCHes it back to the full price; at 100 % Mollie keeps the full price and
  starts one period after the trial, while the confirm step and "Next charge" show the charge
  at the trial's end, at 0.00. An extended trial moves the discount to its new end, and the
  nightly pass no longer ends the free charge of a code switched off after signup.
  The code's own `valid_until` and deactivating it after
  signup do not take the discount back. The checkout used to refuse such a code
  (`coupon_not_with_trial`, translation removed), because it was parked while Mollie collected
  the full price at the trial's end.

### Fixed

- **A trial extended while a `recurring` coupon waits for the charges after it moves the
  coupon as well.** Its window stayed on the original trial end, so the extension ate
  discounted periods: a two-period code discounted one charge, and at 100 % the first paid
  charge kept its old date. Every promised discounted charge now follows the new trial end
  (`CouponService::moveTrialCouponMarkerTo()`), and at 100 % so does the first paid charge —
  with any period-extension days given on top of it. A past-due trial (first charge failed)
  moves its coupon as well.
- **A trial extended past a partial recurring coupon's own end date returns Mollie to the
  full price.** Mollie went on collecting the discounted amount for a charge the coupon no
  longer covered, and the renewal reported a payment mismatch.
- A trial activation that crossed midnight measured the coupon window and `trial_ends_at`
  from different days.

## [1.0.0] - 2026-11-22

### Fixed

- **An overage invoice states the period the usage was in.** The overage of a period is charged
  the day before the renewal; a SEPA payment settles days later, after the renewal has moved the
  period on, and the invoice read the period from the billable at that moment — October's usage
  was invoiced as November's. The same happened to a retry created in the next period, and to a
  standing debt collected at a later renewal. Every line of the pending snapshot now records the
  period its usage was in and `SingleChargeHandler` books each line for its own period (the
  invoice for their span); a payment in flight across the upgrade is dated by the period Mollie
  created it in.

### Added

- **Every invoice PDF is an e-invoice.** The structured invoice (EN 16931, UN/CEFACT CII) is
  embedded as `factur-x.xml` in a PDF/A-3: ZUGFeRD / Factur-X profile EN 16931 by default,
  XRechnung 3 with `invoices.e_invoice.profile = xrechnung`. That is what § 14 UStG calls an
  e-invoice (Germany: mandatory for B2B from 2027/2028); Austrian businesses accept the
  hybrid. Built from the booked invoice by `EInvoiceService` (`horstoeko/invoicesuite`):
  credit notes as type 381 with the invoice they correct, reverse charge as category AE with
  both VAT IDs, customers outside the EU as O, other zero rates as E. If it cannot be built
  the plain PDF is stored and the error logged. See `docs/e-invoicing.md`.
- `invoices.seller.vat_id` (`BILLING_SELLER_VAT_ID`) for the seller's VAT ID; a VAT ID kept in
  `tax_number` keeps working. `billing:check-config` reports the seller data the e-invoice
  needs. A billable model can provide BT-10 (e.g. a Leitweg-ID) with `getBillingBuyerReference()`.

### Changed

- **A zero-rated invoice says why on its face.** Reverse-charge invoices print the statement
  in the wording of the seller's country — Austria "Übergang der Steuerschuld auf den
  Leistungsempfänger" (§ 11 (1a) UStG 1994), Germany and the rest of the EU
  "Steuerschuldnerschaft des Leistungsempfängers" (§ 14a (5) UStG), English "Reverse
  charge" — overridable with `BILLING_REVERSE_CHARGE_TEXT`; invoices to customers outside
  the EU "not taxable in the seller's country". Before, the PDF showed 0 % VAT without a
  reason.
- **The invoice PDF states the service period** on every line ("Leistungszeitraum:
  01.11.2026 – 30.11.2026", § 14 (4) Nr. 6 UStG / § 11 (1) Z 3 lit. d UStG 1994) — for a
  product (one-time order) and its credit note the delivery date instead ("Leistungsdatum"),
  also in the e-invoice (BT-72) — prints dates in
  the invoice locale's format (`d.m.Y` in German; `BILLING_INVOICE_DATE_FORMAT` overrides),
  names the payment method in words ("Kreditkarte" instead of "Creditcard"), and labels the
  VAT IDs of both parties and the seller's tax number ("UID-Nr." for an Austrian seller,
  "USt-IdNr." for a German one). The buyer's VAT ID is the one the invoice relied on.
- While e-invoicing is on the PDF renders in DejaVu Sans, because PDF/A-3 needs embedded fonts
  and DOMPDF never embeds Helvetica. `invoices.pdf.template_data.font` still wins.

## [0.8.0] - 2026-11-20

### Fixed

- **Validation messages name the field by its label.** The checkout, the billing-data page,
  the plan change and the dashboard's country fix said "company name muss ausgefüllt werden"
  or "billing street …" — the property name. They now use the label the form shows
  (`BillingFieldNames`), so an app's override of a label (e.g. "Name auf der Rechnung") is
  the word in the message too. The VAT number gets its own name without "(optional)".

## [0.7.6] - 2026-11-20

### Fixed

- **"Previous page" at Mollie no longer traps the customer.** Mollie sends that link to the
  redirect URL and leaves the payment `open`, without a checkout link once a method was
  picked. The return page polled that payment as "activating…" until it expired, and the
  checkout sent every new attempt back to it. The return page now reports a first payment
  still `open` after ~10 s as "Payment not completed" with "Try again", and the checkout
  starts a new payment when the open one has lost its checkout link (`pending` still goes to
  the return page, an `open` payment with a link is still resumed).

## [0.7.5] - 2026-11-20

### Added

- **The checkout resumes an unfinished order.** Address, plan, interval, addons, seats, the
  coupon code and the step reached are kept as a draft in the session and restored after a
  reload or a later visit, at the furthest step that still validates. Filed per signed-in
  user, billable and checkout URL; input only — prices, VAT, the VIES result, the coupon and
  permissions are worked out again on every resume, and nothing is written to the billable.
  Dropped once the subscription exists, after `checkout_draft_ttl_minutes` (default 1440,
  `0` = off), and when the billable is deleted or may no longer be acted for. App step data
  is kept only for the `customData` keys listed in `checkout_draft_custom_data`.
- **The return page knows when there is no checkout.** Opened without a payment or an
  activation under way and without a subscription, it says "No subscription has been taken
  out yet" and offers "Continue subscription checkout" — no activation claim, no polling.
  Pending payments, late webhooks, activations waiting for their retry, upgrades from a free
  plan and failed / cancelled / expired payments keep their own states.

### Changed

- **The checkout's confirm button names what submitting does** (`checkout::bookingPath()`,
  mirroring `StartSubscriptionCheckout::handle()`): "Confirm and pay" only when the first
  period is paid now; "Start trial and add payment method" for a trial that really starts;
  "Confirm and add payment method" when a code covers the first charge; "Activate free plan"
  and "Activate access" for the local paths, each with its own notice instead of "you will be
  redirected to Mollie". New keys in `en` and `de`.

### Fixed

- An access grant on a plan with trial days, and a free plan with trial days, announced a
  trial on the confirm step that the booking never started.

### Tests

- The checkout browser matrix expected the reverse-charge-style single price for a domestic
  business on the plan card; since 0.7.0 that card shows the net price and the VAT-inclusive
  price it pays. The assertion now checks both.

## [0.7.0] - 2026-11-18

### Added

- Domestic business customers with a VAT number see both net and VAT-inclusive prices
  when choosing plans, addons, seats and usage allowances. The net price is emphasized;
  each amount carries its own VAT label. Applies to the portal and checkout without
  changing tax calculations or charges.

### Changed

- Standalone billing intervals on the seats page and checkout addon selection use
  "per month" / "per year" ("pro Monat" / "pro Jahr" in German).

### Fixed

- The domestic B2B seat summary applies the displayed coupon's VAT to its VAT-inclusive
  total and rounds VAT on the seat line total, keeping the net and inclusive prices consistent.

## [0.6.1] - 2026-11-17

### Tests

- Browser, feature and unit coverage for `show_unit_price`: gross for a consumer, net under
  reverse charge, German number format, no unit price without the flag, for a quantity of 1
  or without a usage type; `billing:check-config` error and warning; `BillingMoney::formatUnitPrice()`
  (including a zero-decimal currency) and `BillingMoney::count()`.
- The products-page fix of 0.6.0 (pack size in the active locale, usage type by its display
  name) is pinned by a browser test that fails against the 0.5.1 page.
- The admin billables list's sort check waits for the new order instead of a fixed pause; on
  a loaded machine the second click's round trip outlasted it once.

## [0.6.0] - 2026-11-17

### Added

- **Unit price on product cards.** `'show_unit_price' => true` on a product with a
  `usage_type` and a `quantity` above 1 shows the price of one unit under the price
  (e.g. "€0.0833 per SMS"), gross or net exactly as the price beside it.
  `billing:check-config` rejects a non-boolean and warns where nothing would be shown.
- **Hidden usage types.** `usage_types.{type}.visible => false` keeps a usage type off the plan
  cards — the checkout's plan step and the plan-change screen — while it is still credited,
  metered, capped and charged for overage like any other. The usage meters (dashboard, usage
  page), the plan-change preview, invoices and the admin panel keep showing it. Looked up
  case-insensitively; only a literal `false` hides. `billing:check-config` rejects a
  non-boolean `visible` and warns when a hidden type has an overage price above 0 in any plan
  or interval.

### Changed

- **`SubscriptionCatalogInterface` gained `usageTypeIsVisible()` and
  `visibleIncludedUsages()`.** An application that implements the interface itself (instead
  of extending `ConfigSubscriptionCatalog`) has to add both — see
  [docs/upgrading.md](docs/upgrading.md) § 1.5.

### Fixed

- The products page grouped a pack's quantity in English ("1,000 SMS") in every locale and
  showed the usage type's code instead of its translated name (`billing::usages.<type>`).

## [0.5.1] - 2026-11-17

### Documentation

- `docs/usage-billing.md`: the hand-over after a charge deferral still described a recurring
  coupon's window as `N × 30 + 2` days. Since 0.5.0 it counts billing periods; the old date
  applies only to markers written before that release.

## [0.5.0] - 2026-11-17

### Behaviour changes an application must know

The full list, with what to do about each, is in [docs/upgrading.md](docs/upgrading.md).

- **A queue worker is required.** Unfinished activations, refunds, Mollie patches and seat
  changes are resolved by queued jobs with delays. On `QUEUE_CONNECTION=sync` a failing retry
  job has failed for good at its first error; only the activation retry guards against that
  (it is left to the hourly `SweepPendingRetriesJob`).
- **A new scheduled job**, `RefundUnassignedPaymentsJob` (daily, 04:15 UTC), is registered by
  the package. Nothing to add when `schedule:run` is already running.
- **Past due blocks every new booking.** `PastDueExpansionNotAllowedException` is now also
  thrown for a plan or interval change (while only an overage debt is open), a one-time order
  (`StartOneTimeOrderCheckout`), a new checkout (`StartSubscriptionCheckout`) and extras on a
  grant (`StartGrantExtrasPurchase`).
- **`recordBillingUsage()` refuses overage while past due.** Usage beyond what the wallets
  hold throws `UsageLimitExceededException` (and dispatches `UsageLimitReached`), and
  `canChargeBillingOverage()` / `hasBillingQuotaLeft()` answer false — also for a billable that
  allows overage. Normal again once the subscription is `active`.
- **A failed overage charge is a debt that stands.** The subscription becomes `past_due`
  under `subscription_meta.overage_past_due_since`; `past_due_max_days` does not apply to it,
  the next three renewals each try again, then it is written off (`OverageWrittenOff`).
- **A customer who owes only overage keeps access**: `hasAccessibleBillingSubscription()`
  answers true and `billing.active` (`RequireActiveSubscription`) lets them through. With an
  unpaid renewal nothing changes. Code that reads `isBillingPastDue()` as "locked out" needs a
  second look.
- **An undercollected period is charged automatically** (`UndercollectionCharge`), instead of
  being reported. `UndercollectionReporter` is gone.
- **Payments the package cannot deliver for are refunded automatically** — a renewal for an
  ended subscription, a prorata payment whose change was cancelled, a first payment whose
  activation failed for good, a paid payment without a billable after 14 days. The admin
  notifications for these cases are sent only when the automatic way is exhausted.
- **A scheduled change that cannot be applied is dropped** after three nightly attempts — at
  once when the rules refuse it, including a change blocked by seat usage, which used to be
  kept indefinitely. `SubscriptionChangeApplyFailed` is dispatched once, when the change is
  given up; `ScheduleSubscriptionChange::apply()` no longer throws unless called with
  `onRequest: true`.
- **A change that costs money is refused on a cancelled or expired subscription**
  (`SubscriptionEndedChangeNotAllowedException`, preview error `subscription_ended`).
- **`cancelBillingSubscription()` throws `InvalidSubscriptionStateException`** for a billable
  without a subscription (`new`, `expired`).
- **Events an app may listen for now fire where they did not:** `CheckoutStarted` is
  dispatched; `PlanChanged`, `SeatsChanged`, `AddonEnabled` / `AddonDisabled` and
  `SubscriptionUpdated` are dispatched for a change applied after its prorata payment settles.
  `PaymentSucceeded` and the invoice mail are held back while an activation is being retried.
- **A recurring code's free periods are billing periods, not days.** `discount_periods` counts
  calendar months or years on the subscription's billing day (`Support\BillingAnchor`:
  31 Jan → 28 Feb → 31 Mar) instead of `N × 30` / `N × 365` days. A fully covering code is
  first charged on the regular charge N periods after its first discounted one — three free
  periods from 31 January: 30 April, then the 31st again, where it used to be 3 May and the 3rd
  for good. Counted from the trial end on a trial signup, from the next charge on the
  dashboard, from the running period for a change whose own charge the code discounts. A code
  with a fixed end date is first charged on the first regular charge on or after it, no longer
  on the day after. Mollie's start date, `charge_deferred_until`, the portal's next charge and
  the new marker key `charge_resumes_at` name one date; the marker's `valid_until` is the day
  before. Markers written before this release keep their date. Texts of your own that computed
  "`valid_until` + 1 day" or "N × 30 days" — see [docs/upgrading.md](docs/upgrading.md) § 2.9.
- **A trial whose first charge a fully covering code defers becomes `active` at the trial
  end** (`ProcessTrialLifecycleJob`): period from the trial end, full quota per free period,
  `TrialConverted` dispatched then; no `TrialConvertedNotification` for it. It used to stay
  `trial` with the prorated trial quota until the first charge.
- **Free plan periods are counted on the billing day** (`PlanWaiver`), an **interval change
  that charges nothing starts the new cycle** on its day, the **portal names the day of a
  deferred charge** until it has arrived, and the **checkout's confirm step names the first
  charge** under a fully covering code (`checkout.full_coverage_redirect_notice`).
- **The Mollie subscription id can change while a subscription runs.** Mollie refuses a new
  start date once it has charged a subscription (measured 17 November 2026). Where the package
  has to move the next charge of such a subscription — anchor day, trial or period extension,
  a fully covering code or plan waiver mid-subscription, an interval change, the past-due
  reset — it cancels it and creates it again from the new date (`MollieSubscriptionReplacer`),
  and `subscription_meta.mollie_subscription_id` names the new one. New event
  `MollieSubscriptionReplaced`. See [docs/upgrading.md](docs/upgrading.md) § 2.10a.
- **Published translations are out of date.** New keys in `admin`, `audit`, `checkout`,
  `notifications` and `portal`; a published `components/next-charge.blade.php` needs merging.
  Changed: `admin.coupons.type_info.recurring` (the help text of a 100 % code) and
  `admin.coupons.create.info.discount_periods`; new: `checkout.full_coverage_redirect_notice`;
  a published `components/checkout/step-confirm.blade.php` needs merging.

### Added

- `MollieSubscriptionReplacer`, `RetrySubscriptionReplacementJob`, the event
  `MollieSubscriptionReplaced` (audited as `mollie_subscription_replaced`) and the marker
  `subscription_meta.pending_subscription_replacement`: moving the next charge of a subscription
  Mollie has already charged, cancel first, then create; finished by the retry and the hourly
  sweep after any interruption, never with a start date of today or earlier.
- `Support\MollieSubscriptionAnswer`: reads Mollie's 422 refusals of the subscriptions API
  (charged start date, already cancelled, same description) — measured, not documented by Mollie.
- `tests/Sandbox/MollieChargedSubscriptionContractTest`; the canary's `pinned` subscription now
  records Mollie's answer to a start date after a charge instead of failing on it.

- **Upgrade from a free plan under a code that covers the whole price.** The plan screen
  no longer refuses it: nothing is due today, so the customer is sent to Mollie for a €0
  authorisation (`mandate_only`, as in the checkout), and the subscription is created at
  the full price with its first charge after the code. The screen says so — "Nothing is
  due today. First charge on …: …" (`portal.upgrade_first_charge`). Abandoning the
  authorisation changes nothing.

- **Next charge, date and amount.** The customer dashboard and the admin subscription tab state when the next charge is and what it is made of (plan, seats, addons, discount or "plan free until", net, VAT, total), from the same calculation the charge uses (`Services\Billing\NextChargeSummary`).
- `coupons.discount_periods`: the number of discounted charges of a recurring coupon, with
  its own field on the admin coupon form and on the coupon page. Migration
  `2026_11_16_000001_add_discount_periods_to_coupons_table` (idempotent; moves the number
  for existing recurring coupons).
- `Services\Billing\RedeemCouponFromPortal` and `PortalCouponOutcome`: one entry point for a
  code typed in by the customer. A recurring discount can now be applied to a running
  subscription from the dashboard; a gifted plan can be activated there.
- Admin coupon form: choose the intent (discount / free time / credits) first, then the
  kind. Addon-only grants moved under "Advanced".
- `CouponService::redeem()` context key `lifetime_from` for a recurring coupon whose first
  discounted charge is not now.
- `BillingCountryRequiredException`; the addons and seats screens tell a gifted tenant
  without billing details to complete them before buying extras.
- Translations: `notifications.grant_extras_purchase_refunded.*`,
  `audit.event.grant_extras_purchase_refunded`, `portal.flash.billing_details_required`
  (en, de).
- `RenewalUndercollected` event (billable, renewal invoice, expected / collected / shortfall net,
  reason `renewal_created_before_change` | `plan_change_spanned_renewal`), audited as
  `renewal_undercollected`. The difference is charged automatically, once per case, as its own
  recurring payment with its own invoice (`Services\Billing\UndercollectionCharge`); the event
  carries `chargePaymentId` (null when nothing could be charged — past due, ended, no mandate,
  Mollie refused — and only then are the admins told). The customer is told by
  `UndercollectionChargedNotification`.
- `PastDueExpansionNotAllowedException` and the preview error `past_due_expansion`.
- `BillingPolicy::derivedSeats()`.
- Plan-change screen: "after the change: N seats" and an explicit "keep N extra seats" choice.
- A downgrade whose prorata payment spanned a renewal now refunds the difference for the period
  it lands in (`SupersededRenewalRefund::refundOverpaid()`).
- **Per-seat addon pricing.** An addon interval can carry `seat_price_net` next to `price_net`, and an addon `included_seats`. Billed addon seats are `max(0, seats − included_seats)` and are invoiced on their own `addon_seats` line. Prorated on addon enable/disable, seat changes, plan and interval changes.
- **A gifted plan stays free when the tenant buys paid extras.** A tenant on an access grant can buy paid addons and extra seats from the portal; only those are charged, the plan stays free until the grant ends (`PlanWaiver`, `UpgradeLocalToMollie::handleKeepingGrant()`, `StartGrantExtrasPurchase`).
- Access grants carry `grant_on_expiry` (`end` — default — or `continue_paid`): what happens when the free time ends for a tenant who bought paid extras.
- New coupon type `plan_waiver`: the plan's base price is free for N billing periods, extras stay paid.
- Admin: extend a subscription by days, give free plan periods and extend a running grant directly from the billable's subscription tab (`AdminExtensionService`) — no coupon code to create and send. The grant list shows all three kinds.
- Admin coupon form: every option has an (i) explaining what it does, and the selected coupon type is explained in full.
- `SubscriptionCatalogInterface::addonSeatPriceNet()`, `addonIncludedSeats()`, `addonIsFree()`.
- **Hidden features.** `features.{key}.visible => false` keeps a feature off the plan cards (checkout, plan change) and addon cards while it still unlocks like any other — for technical feature codes. `SubscriptionCatalogInterface::featureIsVisible()`, `visiblePlanFeatures()`, `visibleAddonFeatures()`; `billing:check-config` rejects a non-boolean `visible`.
- Migration `2026_11_15_000001_add_plan_waiver_fields_to_coupons_table` (`grant_on_expiry`, `waiver_periods`).
- **Automatic resolution of payments the package cannot deliver for.**
  `Services\Webhook\UnwantedPaymentRefund` books such a payment and returns it in full
  (invoice + credit note, net zero), idempotently, with retry through `pending_refund_retries`
  and a customer mail (`UnwantedPaymentRefundedNotification`). Used for a renewal collected for
  an ended subscription (the Mollie subscription is cancelled first), a prorata payment that
  settles after its change was cancelled or its subscription ended, and a first payment whose
  activation failed for good. Event `UnwantedPaymentRefunded`, audited as
  `unwanted_payment_refunded`.
- **Automatic retry of an unfinished activation** (`RetrySubscriptionActivationJob`,
  `Support\ActivationRetry`): one minute, five, fifteen, an hour, six hours; a Mollie
  subscription the first attempt already created is adopted instead of created a second time
  (`CreateSubscription` spec key `adopt_subscription`); when the retries are spent the payment
  is refunded. `SweepPendingRetriesJob` re-dispatches a retry whose job was lost.
- `RefundUnassignedPaymentsJob` (daily, 04:15 UTC): a paid payment no billable can be found for
  is booked if its billable exists by then, and refunded after 14 days.
- **An overage debt that stands.** Event `OverageWrittenOff` (audited as
  `overage_written_off`); `ChargeUsageOverageDirectly::statusAfterRecovery()`,
  `collectStandingDebtNow()`, `isPastDueForOverageDebtOnly()`; `MAX_RENEWAL_RECOLLECTIONS`,
  `RECOLLECT_AFTER_DAYS`. The dashboard shows the open debt and says that nothing new can be
  booked; the usage page shows the open charge and says that overage is blocked while a payment
  is open.
- `NextChargeSummary::for()` keys `overage_debt` (`net` / `gross`, or null) and `total_gross`;
  the next-charge component lists an open overage debt that the next renewal collects.
- `PastDueExpansionNotAllowedException::booking()` and the text
  `portal.error_past_due_booking`; `SubscriptionEndedChangeNotAllowedException` and the preview
  error `subscription_ended`.
- Notifications `UnwantedPaymentRefundedNotification`, `UndercollectionChargedNotification`,
  `ScheduledChangeNotAppliedNotification` (de + en);
  `OverageBillingFailedNotification::$willRetry`.
- `Services\Billing\UndercollectionCharge`; `ScheduleSubscriptionChange::MAX_APPLY_ATTEMPTS`;
  `ScheduleSubscriptionChange::apply($billable, onRequest: true)` for a person asking.
- Event `CouponRedemptionRecordMissing`, audited as `coupon_redemption_record_missing`.
- Admin coupon form: restrict a discount to plans and billing intervals. Coupon page: free plan
  periods, what happens at a gift's end, plan / interval / usage restrictions.
- Seat and addon screens show the amount collected (or credited) now before the change is
  confirmed. Customer and operator see what the package resolved on its own: the retry state
  and the dropped notice of a scheduled change, balance charges, refunded payments.
- Checkout: the plan step shows a plan's `description`; the extras step states the seats a
  per-seat addon includes. Products: the purchase dialog states the amount that will be charged
  when a coupon applies. Usage meter: units a rollover usage type carried over.
- Tests: an inventory of every option per area (`tests/Coverage/*.php`, guarded by
  `tests/Feature/Coverage/OptionCoverageTest.php`, `composer test:coverage-claims`), the
  browser matrix (`tests/Browser/Matrix/`, `composer test:browser:quick`), `tests/SandboxBrowser`
  (a real Mollie checkout in Chrome, `composer test:sandbox:browser`) and the subscription
  canary (`composer test:sandbox:canary`). See `docs/sandbox-testing.md`.
- Tests: a shared clock for the browser suite (`travelSharedClockTo()`; the test process and
  the Dusk server read one "now" from the shared database, reset after every test — see
  `docs/browser-testing.md`), and `GiftLongRunMatrixTest`, which walks a gifted plan with a
  paid addon and a paid seat month by month to the end of the gift for both
  `grant_on_expiry` values. `ChargebackSdkContractTest` pins `ChargebackHandler` to the
  Mollie SDK's own chargeback and payment samples (full, partial, reversed), since
  chargebacks cannot be produced in test mode.
- Tests: `RenewalMonthEndCadenceTest` runs both cadences Mollie may follow after a month end
  ("drift" and "clamp") through the renewal handler; the canary's March 2027 answer is
  documentation only.
- Tests: `tests/Concurrency` (`composer test:concurrency`, `:pgsql`, `:redis`;
  `phpunit.concurrency.xml`) runs the money-path guards in two PHP processes at the same
  time — plan-change and refund locks, `refundLine()`, the webhook reservation and its
  takeover, the activation claim, the seat lock, the usage quota and the last coupon slot —
  against SQLite or PostgreSQL and a file or Redis cache; see `docs/concurrency-testing.md`.
- Tests: `RemoteDiskLiveS3Test` (`composer test:s3`, optional) fetches the signed invoice and OSS
  links from a real S3-compatible server (Herd's RustFS/MinIO): 200 inside the window, 403 after.
- Tests: invoice and credit-note PDFs are read back with `smalot/pdfparser` (require-dev) in
  the feature and the browser suite, on every machine — the browser check used to need
  `pdftotext` and was skipped without it. `invoices.temporary_url_expiry` /
  `oss.temporary_url_expiry` are checked against a real S3 adapter (`league/flysystem-aws-s3-v3`,
  require-dev): `X-Amz-Expires` is the configured minutes.
- Tests: the screens' own message for a booking refused while past due
  (`error_past_due_booking`) on the addons, seats, products and checkout screens — feature
  and browser (`PastDueBookingRefusalTest`, workbench switch `past_due_booking_race`).
- `billing:check-config` warns when the package's jobs run on a connection with the `sync`
  driver, and says what that costs.
- Refunds by line: `RefundInvoiceService::refundLine()` (or `refund()` with `line_index`)
  books a refund against one invoice line — its rate, within what is left of it
  (`RefundExceedsLineAmountException` otherwise). The admin refund modal offers the lines and
  fills in what is left of the chosen one. `InvoiceService::refundableLines()` /
  `creditLineFor()`.

### Changed

- `max_redemptions_per_billable` means "how often one customer may redeem the coupon" for
  every type. For recurring coupons the lifetime is `discount_periods` and/or `valid_until`.
  **Compatibility:** `CouponService::create()` still reads a per-customer limit passed
  without `discount_periods` as the period count; `update()` does not.
- A recurring coupon limited only by `valid_until` now discounts until that date without a
  count (it used to stop after one charge, the column's default).
- Discounted renewals of a recurring coupon no longer count against the per-customer limit;
  their redemption rows carry `grant_applied_snapshot.mode = recurring_renewal`.
- The dashboard no longer answers a recurring or single-payment code with "enter this code
  when changing your plan". `billing::portal.coupon_redeem_use_in_action` is unused.
- Texts: `coupons.type_info.recurring`, `create.info.valid_until_recurring`,
  `create.info.max_per_billable(_recurring)`, `create.validity_hint_recurring`,
  `portal.coupon_redeem_title/subtitle` (de + en).
- A purchase of paid extras on a gifted plan is no longer carried out when the gift has
  ended, been revoked or expired before the payment settles. The payment is booked and
  refunded in full, no subscription is created, and plan, addons and seats stay as they
  were. Previously the tenant ended up on a subscription at the full price. New:
  `GrantExtrasPurchaseRefunded` event (audited), `GrantExtrasPurchaseRefundedNotification`
  for the tenant. The admins are told only when the refund has used up its retries.
- **Breaking behaviour:** a plan change without an explicit `seats` lands on
  `max(used seats, seats included in the new plan)` instead of carrying the old seat count over.
  Pass `seats` to keep more. Interval, seat and addon changes are unaffected.
- **Breaking behaviour:** while a subscription is past due, adding seats or enabling a paid addon
  is refused (immediate, scheduled, preview). Reducing seats, disabling an addon and a free addon
  remain possible. A plan/interval change remains possible with an unpaid renewal (the past-due
  reset) and is refused while only an overage debt is open — see "Behaviour changes an
  application must know".
- The preview no longer raises `paid_seats_lost` for a plan change onto a plan that sells no extra
  seats; the seat count falls to the included seats and is shown as `newSeats`. The error remains
  for an interval-only change.
- `plan-change` Livewire component: `dropExtraSeats` / `toggleDropExtraSeats()` are replaced by
  `keepExtraSeats` / `toggleKeepExtraSeats()` (default off).
- **Breaking for apps with their own catalog:** `SubscriptionCatalogInterface` has six new methods (see above).
- `CouponService::computeMarkerDiscount()` takes an optional third argument describing the configuration being priced.
- `CouponService::revokeGrant()` also revokes plan-waiver redemptions; on a Mollie subscription whose grant lives on as a plan waiver it removes the waiver instead of only recording the revocation.
- The preview reads addon quantities from the billable, like the charge does; a quantity map in the preview payload is no longer multiplied in.
- `billing:check-config` rejects a negative or non-integer addon `seat_price_net` and a negative addon `included_seats`.

- **Switzerland, Monaco and the Isle of Man are no longer invoiceable by default.** "EU" is now the package's own list of the 27 member states instead of the VAT library's rule table, which also carries CH (8.1 %), MC and IM. An app selling there adds the country under `additional_countries`.
- **Overage incurred during a trial is charged** when the trial converts. It used to be reset away with the first paid period.
- A `single_payment` coupon is refused at checkout when a trial will start; the trial wins over a 100 % coupon instead of being dropped silently.
- **The billing period starts on the day Mollie created the charge**, not on the day it settled. Invoices, `nextBillingDate()` and prorata windows follow the charge cycle.
- An interval change is refused while a plan waiver runs.
- New events `ChargebackReversed` and audit entries for it.
- A trial whose first charge never reports back moves to `past_due` 14 days after the trial end (`ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS`), also when a mandate is on file.
- `billing:check-config` rejects VAT rates that are not a percentage between 0 and 100 (including `0.19`-style decimals), checks `vat_rate_overrides`, and rejects a `checkout_countries.include` entry that cannot be priced.
- A scheduled end-of-period change is announced to Mollie when it is scheduled, so the renewal is created at the new amount.
- New event `RefundReversed` (and an audit entry) when a booked refund turns out not to have paid out.
- **Overage that could not be collected is no longer written off after one dunning cycle.** It
  stands as an open charge and each of the next three renewals tries again automatically.
  Operators are mailed only when it is finally written off. While it stands the subscription is
  `past_due` (recorded as `subscription_meta.overage_past_due_since`): renewals keep running,
  `past_due_max_days` does not apply to it, and it returns to `active` when the debt is paid or
  written off.
- **While a subscription is `past_due`, usage beyond what the wallets hold is refused** —
  `recordBillingUsage()` throws `UsageLimitExceededException` and `hasBillingQuotaLeft()`
  answers false, as for a billable without overage.
- A customer who is past due only for an overage debt keeps access
  (`hasAccessibleBillingSubscription()`, `billing.active`); with an unpaid renewal nothing
  changes.
- While an overage debt is open, a plan or interval change and a one-time order are refused
  with `PastDueExpansionNotAllowedException`, like seats and paid addons. A one-time order and
  extras on a grant are refused in any past-due state.
- The admin past-due list's "Retry" starts a new collection cycle for a standing overage debt
  (it counts as one of its three further attempts).
- A prorata payment that settles after its change was cancelled, or after the subscription
  ended, is refunded automatically instead of being reported for a manual refund. Cancelling a
  pending change whose payment can no longer be cancelled no longer alerts the admins.
- A scheduled change that fails to apply is retried on the following nights (3 attempts), then
  dropped with a customer mail and an admin mail; a change the rules refuse is dropped at once
  with a customer mail and no admin mail. A change blocked by seat usage used to be kept
  indefinitely; it is now dropped and the customer told. `apply()` no longer re-throws, and
  `SubscriptionChangeApplyFailed` is dispatched once, when a change is given up.
- An undercollected period is charged the difference automatically. `UndercollectionReporter`
  is gone.
- No invoice mail and no `PaymentSucceeded` for a first payment whose activation is still being
  retried; both follow when the activation completes.
- A failed automatic refund no longer mails the admins at its first attempt; one mail is sent
  when its retries are used up (`RetryRefundLineJob`). `GrantExtrasPurchaseRefund` no longer
  mails the admins on success either.
- A missing redemption row after a paid checkout is logged and audited
  (`CouponRedemptionRecordMissing`) instead of mailed to the admins; the mail remains when the
  recurring marker cannot be written either.
- A recurring coupon whose discount has run out no longer blocks redeeming another one.
- `CouponService::create()` refuses an existing code (case-insensitively) with
  `InvalidArgumentException` instead of letting the unique index throw.
- `OverageBillingFailedNotification` and `AdminOverageBillingFailedNotification` wording
  (de + en): the customer subject no longer says "Service paused", the admin mail says
  "written off".
- The refund idempotency key contains the Mollie payment id
  (`invoice-refund:{payment}:{invoice}:{gross}:{already refunded}:{reason}`).

### Fixed

- **Portal and admin screens (CodeRabbit review of `resources/`, 17 November 2026).**
  `admin.scheduled_changes.applied_pending_payment` sat in the wrong group of both catalogs,
  so "Apply now" on a change that waits for its payment showed the raw key; a new guard
  (`tests/Feature/Coverage/TranslationKeysExistTest`) checks every literal `billing::` key in
  `de` and `en`. The admin coupon form no longer saves plan / interval / addon / product
  restrictions onto a kind that does not offer them (ticked under a discount kind, then the
  kind switched). The addons screen quotes a toggle with the billed quantity of the addons
  that stay (`getBillingAddonQuantity()`), as the change is priced. The billing-data page
  stops waiting for a new mandate after its tick budget also when no billable resolves. The
  checkout drops a dated recurring code that ends before a trial's first charge also when the
  trial plan is picked after the code, or the trial outlasts it by submit time
  (`coupon_ends_before_first_charge`).
- **A start date sent to a charged Mollie subscription was refused, every time.** The anchor
  day after a short month, a trial or period extension, a fully covering code or plan waiver
  given mid-subscription, an interval change and the past-due reset all PATCHed `startDate`,
  which Mollie answers with 422 once it has charged the subscription. The date stayed where
  Mollie had it, and the PATCH retries could never succeed. Such a subscription is now
  replaced (see Added). `MollieSubscriptionPatcher::pinNextChargeDate()` first asks Mollie for
  its own next date and moves nothing where that already is the anchor day.
- **Cancelling a subscription that was already cancelled** at Mollie (422 "The subscription has
  been cancelled.") is success, not a failure retried for 24 hours and then mailed.

- A gift no longer gives the renewal on its last day away. A waiver's end was compared with
  the hour the gift was given while the renewal is compared as a day, so a gift given at
  10:00 "covered" Mollie's early-morning charge on its end day: with `continue_paid` the
  period after the gift was not charged for the plan, with `end` the subscription ran (and
  charged the extras) a period longer. A waiver now ends on a day (`PlanWaiver::endDay()`).
- A subscription charged on the 29th–31st keeps its day through shorter months (31 Jan →
  28 Feb → 31 Mar → 30 Apr; 29 February in leap years for a yearly one): the billing period,
  invoice period, prorata window and the portal's next charge end on that day, and the renewal
  sends Mollie the date wherever Mollie's own month-end rule could differ — previously the
  period could end up to three days before Mollie's charge (portal showed a charge that did not
  come, seat/addon changes were refused, overage ran early). New `subscription_meta.billing_anchor`
  (`Support\BillingAnchor`).
- A free plan or a gift without extras started on the 29th–31st no longer moves to the 28th
  after February: the nightly period turn-over keeps the anchor day (Local subscriptions,
  `PrepareUsageOverageJob`).
- A renewal that ended a fully covering one-period code no longer sends today as Mollie's next
  charge date (which charged the customer a second time that day): the renewal resets the amount
  only (`MollieSubscriptionPatcher::updateRecurringAmountAfterCharge()`), and a date a charge
  returns to is never today (`ChargeDeferral`).
- Refunds and chargebacks of a payment are read through `MollieCall` (the transaction guard
  applies) and across every page; previously the webhook booked only the first page Mollie
  returned (`Support\MolliePaymentCredits`).
- The portal shows billing days — charge dates, period boundaries, the end of a subscription,
  trial or gift — as the calendar day they are computed on, in every timezone
  (`BillingTime::billingDay()`). Converted into a timezone west of UTC, Mollie's charge at
  00:28 UTC moved a day back, and a customer in New York read "next charge 9 Mar" for a charge
  on 10 March and a full-price charge on 9 April next to "plan free until 10 Apr".
- On the `sync` queue driver the retry jobs no longer give up at their first Mollie error.
  A sync run that throws has failed for good, so the first error dead-lettered a refund line,
  dropped a PATCH or cancel marker, or mailed the operator about an overage charge.
  `RetryRefundLineJob`, `RetrySubscriptionPatchJob` and `RetrySubscriptionCancelJob` now keep
  their marker on a sync run and are run again by the hourly `SweepPendingRetriesJob` until
  their window ends; `RetryUsageOverageChargeJob` leaves the charge pending for the nightly
  pass and mails after three failed nights (`usage_overage_inline_failures`). The activation
  retry is run by the hourly sweep from the start on `sync` and refunds only once its ladder's
  time is over, no longer at the sweep's first attempt. A real queue behaves as before.
- An amount refund after a refund bound to one line was spread by each line's original
  share, so part of it landed on the spent line at 0 % VAT and too little VAT was credited.
  It is spread by what is left of each line.
- A saldo-zero plan-switch invoice could not be refunded from the admin panel although the
  panel offered it (`RefundInvoiceService` answered "Invoice has no Mollie payment id."). It is
  refunded against the payment that settled it, by amount or by line; such an invoice now also
  reads as fully refunded once it is.
- A second `SyncSeatsJob` inside the confirmation window of the first (its prorata payment
  not yet paid) was refused ("already pending payment confirmation") and, with `tries = 1`,
  the member's seat was never billed. The job now waits: put back every ten minutes for up to
  seven days (`PENDING_WAIT_SECONDS`, `MAX_PENDING_WAITS`). A put-back copy of an absolute
  seat job was also dropped by its own running original's unique lock; copies are unique per
  dispatch now.
- A refund line Mollie kept rejecting was re-stamped with a fresh `first_attempt_at` on every
  attempt; a line the sweep kept picking up therefore never reached its seven days, was never
  dead-lettered and no operator was told. The first failure's time is kept.
- A scheduled change whose attempt failed after its prorata payment had been created no
  longer comes back to life. When the change was given up (attempts used up, refused,
  cancelled), the change waiting for that payment stayed behind, and a payment that settled
  days later carried out the change the customer had been mailed was "not carried out". The
  attempt now notes its payment on the stored change, and dropping the change withdraws what
  waits for it (`UpdateSubscription::withdrawPendingChangeForPayment()`): the payment is
  cancelled where Mollie still allows it, otherwise returned in full by
  `UnwantedPaymentRefund` when it arrives; seats, addons and the Mollie amount of a seat or
  addon change are put back. Further attempts still create no second payment. The customer
  mail (`ScheduledChangeNotAppliedNotification::$paymentOnItsWay`), the dropped-change notice
  in the portal and the operator's scheduled-changes list say that a payment is on its way
  and comes back. See docs/plan-changes.md, "A failure after the payment exists".
- A prorata payment Mollie accepted while the answer was lost (a timeout, or a failed write)
  is no longer followed by a second one. `InvoiceService::createCharge()` notes the payment
  before asking Mollie (`subscription_meta.prorata_charge_in_creation`, `charge_token` in the
  payment metadata); the next attempt for the same change, or the payment's own webhook,
  takes it over. A different change gets its own payment and the lost one is cancelled or
  returned. `CleanupStalePendingProrataChangeJob` drops a note nobody took over within 48
  hours and retries the cancel of a withdrawn change's payment that Mollie could not be asked
  about.
- The products page renders no purchase dialog for a customer who may not buy.
- The checkout's extras step prints an addon's `description`.
- A fully covered order with several lines no longer previews 0.01 on the plan screen
  (one discount line rounded its VAT against several catalog lines).
- The return page no longer answers "Payment received" when a customer on a free plan
  abandons an upgrade at Mollie: while that payment is pending it asks Mollie about it.
- The activation retry now finishes an upgrade away from a free plan (an accessible local
  plan counted as "completed"), and a retry can price a recurring code despite the marker
  its own first attempt left behind.
- A recurring code limited by date that is over before the trial ends is refused at the
  checkout's field instead of being shown as a discount on a first charge it cannot reach.
- The billing-data page stops waiting for a payment-method change that is never confirmed
  (90 s), says so and offers "Check again".

- An access grant typed into the dashboard was answered with "this coupon type cannot be
  redeemed here" whatever the customer's situation.
- A re-delivered webhook for such a refused purchase no longer raises the
  "incomplete activation" alarm.
- A seat or addon added while past due was free for the unpaid period and PATCHed into Mollie.
- The old plan's included seats became paid extra seats on a plan that includes fewer.
- **Second audit round** (details in `docs/decisions.md`): the billing period follows Mollie's charge cycle instead of the day a payment settled (SEPA periods overlapped or left gaps); a coupon is judged as of the charge, not as of settlement; a deactivated 100 % coupon ends its deferral; "N free periods" count from the next charge, not from today; a period extension during a trial extends the trial; an unlimited free plan no longer gets an end date from a period extension; the running discount is applied to the prorata charge of a plan change; credits are booked when a coupon fully covers the charge; a failed seat/addon prorata is rolled back locally and at Mollie; a renewal no longer writes back a stale seat count; a chargeback that is reversed is taken out of the books (`ChargebackReversed`); a 100 % coupon no longer leaves 0.01; a payment for extras on a gift that ended meanwhile is booked instead of dropped; a revoked one-off code cannot be redeemed again; a redeemed coupon cannot change type; quota is not credited twice at the end of a deferral; the third SEPA attempt of a dunning cycle is not written off after two days; trial overage is charged on a conversion through a plan change; "extend trial" is refused on a free or gifted subscription.
- 100 % recurring coupon: a seat or addon added while the coupon runs is charged from the next regular billing date instead of staying free until the coupon ends. The deferred Mollie charge date is now stored (`subscription_meta.charge_deferred_until`, `Support\ChargeDeferral`).
- A period extension or trial extension on a fully covered subscription no longer moves the Mollie charge *earlier*, which collected the full plan price while the coupon was running.
- A subscription Mollie is not charging (fully covered) now turns its billing period over each interval: included quota is credited every period and seat/addon changes are no longer refused from the second month on.
- The first invoice of a subscription states the period its payment bought (yearly plans, Local→Mollie upgrades, re-checkout after expiry); a downgrade in the first period refunded nothing.
- Failed and cancelled Mollie refunds are no longer booked as credit notes; a booked refund that later fails is reversed.
- A plan-change refund and its own refund webhook can no longer book the same Mollie refund twice.
- A prorata credit is the unused share of what was *paid* for a line (after coupon and adjustments), not of its list price.
- Free plan with paid addons on a Mollie subscription: a kept addon is charged again on a downgrade to the free plan, and an addon added or removed mid-period is prorated.
- A renewal Mollie created for a configuration the customer has since left is refunded for the difference instead of only raising `PaymentAmountMismatch`.
- A seat or addon increase PATCHes Mollie in the same call that changes the local state, not only once its prorata payment clears.
- A renewal that settles before the nightly overage pass no longer forgives that period's overage.
- Plan-change usage excess that had to be deferred keeps the unit price it was quoted at.
- `billing:sync-purchased-balance` no longer revives purchased balance that was used up before a plan change.
- Reverse charge: display and charge agree for `additional_countries` (reverse charge applies inside the EU only).
- A `checkout_countries.include` country without a VAT rate is no longer offered; it used to collect a payment the webhook could never book.
- A free plan chosen with a promotion coupon in the session is activated locally instead of being sent through mandate collection and never activated.
- The OSS export classifies a line by the VAT actually charged; credit notes inherit the VAT validation of the invoice they correct.
- **Third audit round** (details in `docs/decisions.md`):
  - Coupons: a recurring coupon limited by date no longer discounts the first renewal when it
    has ended before that renewal — the Mollie subscription is created at the full price, also
    with a trial in front; the checkout refuses such a code at the field.
  - A recurring code entered with a seat or addon change left Mollie on the full price (for
    good during a trial). The PATCH is now priced from the marker the change is about to write
    (`CouponService::recurringMarkerFor()`), so the first amount Mollie receives is the
    discounted one; a change that fails after the PATCH leaves no redemption and puts Mollie
    back to the committed amount. The legacy `applyPendingPlanChange()` path does the same —
    pricing only codes it can still redeem (`CouponService::hasRedemptionLeft()`) — and
    redeems its codes again: its write phase read them from an undefined variable.
  - A redeemed recurring coupon whose `discount_periods` was changed through
    `CouponService::update()` changed the number of discounted charges of every customer
    already holding it. The count is kept in the marker, like the value and the end date.
  - A gift given to continue as paid that ends before the first renewal no longer gives a plan
    period away.
  - A repeated identical admin extension within five seconds is refused instead of applied
    twice — across processes and on every cache store: the guard is kept in
    `subscription_meta.admin_extension_guard` under the row lock, not in a cache lock (which
    guarded nothing on the `array` and `null` stores).
  - A past-due customer who owes could start a second subscription through
    `StartSubscriptionCheckout`.
  - A plan, interval, seat or paid-addon change on a cancelled subscription was charged and
    refunded — and left the seat or addon active for free.
  - A plan change kept addons the target plan does not allow (a downgrade to the free plan
    stayed on Mollie and went on billing the addon).
  - `CheckoutStarted` is dispatched.
  - A plan change applied after its prorata charge settles dispatches `PlanChanged` (and
    `SeatsChanged`, `AddonEnabled` / `AddonDisabled`, `SubscriptionUpdated`) and is in the audit
    trail.
  - `cancelBillingSubscription()` on a billable without a subscription (`new`, `expired`)
    throws `InvalidSubscriptionStateException` instead of writing `cancelled` with thirty days
    of access.
  - `UsageLimitReached` keeps its audit row when the booking is refused.
  - The activation retry no longer runs inside the webhook on a `sync` queue.
  - A stale overage payment is cancelled at Mollie before its slot is freed.
  - `SyncSeatsJob` re-queues itself when the seat lock is busy instead of failing.
  - The preview of a seat or addon change with a coupon code quoted the undiscounted amount due
    now.
  - "Apply now" on a scheduled change (portal and admin) reported success when the change had
    not been applied, or had failed and been dropped; it reports the failure and keeps the
    change, and says "started" while the change's charge is still settling.
  - Plan change: an upgrade from a free plan that a discount code covers in full is refused
    with its reason instead of a generic error; a pure credit is no longer described as
    "refunded once this payment has been captured".
  - Billing data: returning from a payment-method change no longer waits forever — neither
    when Mollie's webhook replaced the mandate before the customer came back, nor when the
    change is never confirmed.
  - `AdminRefundFailedNotification` names the invoice number, not the row id.
  - Admin: refund reason "Other" without a text is refused in the operator's language; the
    refund detail shows the reason text; a refusal from a prompt of the subscription tab is
    shown inside the prompt; a duplicate coupon code is answered with a sentence.
  - A typed usage reason is shown as written instead of being title-cased.
  - The portal says a used-up single-payment code is used up instead of where to enter it.

### Plan, seat and addon changes (16 November 2026)

- **Fixed:** a second plan change in a period whose first plan change settled after the
  renewal credited nothing (or only the difference) for the plan held in between. The
  period is now settled as if it had been paid on that plan from its first day.
  A balance that is collected after its plan was left is returned except for the days the
  plan ran.
- **Fixed:** a plan change kept addons the target plan does not allow — a downgrade to a
  free plan with an addon stayed on Mollie with a subscription for the addon alone.
- **Fixed:** a change on a cancelled subscription was charged and refunded at once; an added
  seat or addon stayed active after the refund. Such changes are refused before anything is
  charged (`SubscriptionEndedChangeNotAllowedException`). Giving a seat or addon back still
  refunds the unused paid share; the cancelled Mollie subscription is no longer PATCHed.
- **Changed:** while past due, scheduling an expansion for the period end is refused
  (`PastDueExpansionNotAllowedException`), and so is any plan change under a pure overage
  debt; a scheduled expansion that meets a past-due subscription is dropped instead of being
  applied as the past-due reset. Scheduling a reduction says the open payment remains due.
- **Fixed:** the preview of a seat or addon change with a coupon code quoted the
  undiscounted amount due now.
- **Added:** the seat and addon screens show what is collected or credited now; the
  dashboard, plan screen, invoice list and admin show balance charges and the state of a
  scheduled change that is being retried or was dropped.
- **Changed:** an admin is mailed about an automatic refund only when its retries are used
  up — no longer on the first failure, and not when it went through.
- **Changed:** a plan or interval change from past due is the past-due reset only when the
  running period is unpaid. When the renewal was collected and only a balance
  (`UndercollectionCharge`) was declined, it is an ordinary change: the unused paid share is
  credited, the new plan is charged for the days left, the period and Mollie's start date
  stay, the declined balance is closed and the dunning ends
  (`BillingPolicy::runningPeriodIsPaid()`). Before, the customer paid a second full period
  on top of the paid one — 40.00 instead of 20.00 for the month in the pinned example. A
  failed renewal is the reset as before; a pure overage debt still refuses a plan change.
  A renewal with a standing chargeback (also a partial one) counts as unpaid, so the reset
  still applies there, whichever came first.
- **Changed:** the balance invoice for seats and addons added after Mollie created the
  renewal carries one line per component (`seats`, `addon`, `addon_seats`, marked
  `undercollection`) instead of one `undercollection` line; total and VAT are unchanged. An
  application that reads `line_items` by `kind` sees the new kinds. The renewal's
  `adjustment` line gains `reduces` (line key => net).
- **Fixed:** a seat or addon given back in such a period was credited from the renewal alone,
  at its share of the adjustment (3.08 instead of 3.39 for a 5.00 seat with 19 of 28 days
  left). It is credited out of the renewal line and the balance line together; given back
  while the balance is still out, the balance's share is returned when it settles.
- **Fixed:** under a fixed-amount coupon, after a downgrade that settled across a renewal,
  seat and addon lines were credited at the share the dearer plan's renewal was paid at (up
  to 1.04 too much in the pinned case). They are capped at the share the discount leaves of
  the current configuration, like the plan line.
- **Changed:** `UndercollectionCharge::planLeft()` is `configurationLeft()`; it also runs on
  an interval change and covers seat and addon balances. `UndercollectionCharge::cases()`
  knows the status `closed` (declined, and closed by a plan or interval change).
- **Fixed:** the invoice and credit note of an automatically refunded payment
  (`UnwantedPaymentRefund`) for a billable without a billing country carried the fallback
  country's VAT rate instead of the rate the payment was charged at (19 % on a payment
  priced at 20 %; the amounts were exact). The rate is read off the last document the
  billable was charged with (`rateThePaymentWasChargedAt()`).

## [0.4.1] - 2026-11-03

### Fixed

- `CancelSubscription` no longer writes back the meta snapshot it read before the Mollie cancel call; a concurrent overage charge's in-flight marker survived and could be charged twice otherwise.
- `CouponService::redeem()` grants the renewal exemption only to a billable holding that coupon's marker, and a renewal never rewrites the marker.
- `BlockRestrictedCountries` is Livewire persistent middleware, so the IP gate also applies to component actions of an already-open tab.
- Addon-only changes check newly added addons against the plan's `allowed_addons` and interval (`AddonNotAllowedException`, `IntervalNotOfferedException`); `StartSubscriptionCheckout` checks the allowlist too.
- `max_seats` is enforced for every explicit seat count, not only through `SyncSeats`.
- `RequireResolvedCountryMismatch` ignores an unauthorized query-parameter guess instead of redirecting on a stranger's mismatch state.
- `SubscriptionUpdateRequest::from()` ignores `internal`; the scheduler uses the new typed `asInternalReentry()`.
- A retried coupon-expiry reset is priced from the live subscription and leaves a newer recurring coupon alone.
- Refund reasons are escaped before they reach the PDF template, which prints the description unescaped.
- Paid checkouts validate SinglePayment/Recurring coupons against the billable (per-billable limit), in the service and the component.
- The package-route check behind the query-parameter billable fallback also requires a package controller; an app route named `*.billing.*` no longer qualifies.
- Prorata payment webhooks only act on the pending change whose `charge_payment_id` they carry.
- `CleanupOrphanedBillablesJob` keeps `applyBillingScope()`, so excluded rows are never deleted.
- Overage unit refunds reject non-positive counts and more units than the invoice billed.
- The refund webhook defers while a programmatic refund of the same invoice is in flight, so one Mollie refund cannot be booked twice.
- `ResubscribeSubscription` refuses while a country mismatch is open.
- Editing billing details on the plan-change screen records `tax_country_user`, like the other two forms.
- The Local→Mollie upgrade refuses extra seats the target plan does not sell, writes the clamped seat count into the payment metadata, and `confirmAndPay()` honours blocking preview errors.
- A locked checkout grants reverse charge only when the VIES-confirmed number is the persisted one.

## [0.4.0] - 2026-08-11

### Added

- **Sandbox test suite** (`composer test:sandbox`, `tests/Sandbox/`). Sends every request type the package builds to the real Mollie API with a test key, through the package's own services, and checks that the responses carry the fields the code then reads. It is what the other two suites structurally cannot be: the feature tests fake the facade and the browser tests fake the SDK's HTTP adapter, so both answer with whatever they were told. Opt-in and excluded from `phpunit.xml.dist` — it needs a key and a network. Refuses any key that is not `test_`, and cancels every subscription it creates by sweeping the customers it created, not by remembering ids a failing test never reached.
- `docs/decisions.md` — behaviour that looks like a defect and is not: the enforced invariants, the deliberate choices behind them, and the findings that were investigated and refuted. Written so a review spends its attention on something new.
- `MollieCall::send()` now also enforces that every amount is written with its currency's own number of decimals. Mollie reads the number as written, so `"10.5"` is a wrong charge rather than a rejected one, and `"500.00"` for a zero-decimal currency is off by a factor of a hundred in a request the API accepts.
- `billing:check-config` warns when a usage type is declared on one interval of a plan and not the other. Quotas and overage prices resolve per (plan, interval) with no fallback, so that shape has no symptom until a customer switches interval — and then the quota is 0 and, with no overage price on that side, the hard cap refuses their next metered call.
- `billing:check-config` now checks the two settings outside `mollie-billing.php` that decide whether this package's guards guard anything: the default **cache store** (plan changes, refunds and seat deltas all serialise on `Cache::lock()`, which the `array` store keeps in one process's memory and the `null` driver grants to everyone) and **bavix's `wallet.lock.driver`** (per-process by default, so a request recording usage and the worker charging the overage can interleave on the same wallet). It also warns when `routes.auto_load_admin` is off and no admin route was mounted.
- `mollie-billing.routes.auto_load_admin` (`BILLING_AUTO_LOAD_ADMIN_ROUTES`, default `true`): switches off the service provider's own registration of the admin routes, so `MollieBilling::adminRoutes()` inside your route group actually takes effect.
- `InvoiceService::createInvoice()` takes a `deferDocument` flag, with `issueInvoiceDocument()` rendering and announcing afterwards — the same shape credit notes already had.
- Admin panel is fully translated via the new `billing::admin` lang file (English and German).
- `mollie-billing.admin_locale` (`BILLING_ADMIN_LOCALE`): pins the admin panel to a fixed locale. `null` (default) follows the app locale.
- `mollie-billing.vat.seller_country` (`BILLING_SELLER_COUNTRY`) and `vat.require_seller_country`: your own country, which is what separates a domestic business customer (owes domestic VAT) from a cross-border one (reverse charge). Falls back to `invoices.seller.address.country`.
- `mollie-billing.checkout_country_fallback` (`BILLING_CHECKOUT_COUNTRY_FALLBACK`, default `DE`): the VAT country used when a billable has none persisted. Replaces five scattered `?? 'DE'` literals that checkout and webhook could drift apart on.
- `mollie-billing.invoices.locale` (`BILLING_INVOICE_LOCALE`) plus a nullable `billing_invoices.locale` column: pins the language a PDF renders in, so a regenerated document matches the archived one.
- `MollieSubscriptionReferenceCleared` event, dispatched when the gate drops a `mollie_subscription_id` Mollie no longer knows. Audited like every other event.
- `MollieBilling::routes()` — the mounting call README documented all along but that did not exist.
- `MollieBilling::flushCallbacks()` for use in `tearDown()`.
- `brick/money` is a declared dependency. It was already installed transitively and used directly by the invoice and money code, which would have broken on any install that resolved it away.
- Cancelling a subscription from the admin panel requires an operator reason, recorded in the audit trail. A forced cancel is irreversible — a Mollie subscription can only be recreated, never resumed — and it used to be a one-click confirm whose trail said an admin did it but never why. `CancelSubscription::handle()`, `Billable::cancelBillingSubscription()` and the `SubscriptionCancelled` event each take a trailing optional `?string $reason`, so existing callers and listeners are unaffected.
- Bulk wallet credits require an operator reason, recorded on every credited wallet and in the audit trail instead of the hardcoded `bulk credit`.
- Revoking an access grant requires a reason. It is appended to the stable `admin_revoke` marker (`admin_revoke: <reason>`), so the marker stays greppable in every locale while carrying the why, and the audit entry records it.
- **Chrome end-to-end test suite** (`composer test:browser`). Drives the real checkout, portal and admin UI against a fake Mollie, with Flux Pro installed as a dev dependency — the default feature suite cannot render a single portal screen, because Flux ships under `suggest` and is absent there. Mollie is faked at the SDK's own `HttpAdapterContract` seam, so every typed request and response hydration is the real code path; the hosted payment page is a page the browser genuinely navigates to, with pay/fail/cancel/expire buttons and a card-country selector feeding the three-way VAT check. 34 scenarios cover checkout (including trials and coupons), plan changes, addons, seats, one-time products, renewals, dunning, overage, expiry, VAT numbers, the country mismatch and the admin panel. Every step is screenshotted; `composer browser:gallery` turns them into a browsable HTML gallery. See [docs/browser-testing.md](docs/browser-testing.md). The shared workbench SQLite runs with a busy timeout — two processes on one file made runs fail at random points with `database is locked`. Explicitly *not* WAL: both processes issue the pragma on connect, it needs an exclusive lock, and it took the suite from two failures to forty-five and then to `database disk image is malformed`.
- **Dev-workflow note:** `livewire/flux-pro` is now a `require-dev` dependency (with a `composer.fluxui.dev` repository entry), because the browser suite cannot render a screen without it. `composer install` therefore needs Flux Pro credentials in an `auth.json`; see [docs/browser-testing.md](docs/browser-testing.md). Runtime is unaffected — Flux stays under `suggest` for consumers.
- `resources/css/app.css` — the reference stylesheet consuming apps need (Tailwind + Flux, scanning this package's views). It doubles as the browser suite's build input.
- `InvoiceService::createCreditNote()` takes a `deferDocument` flag, and `issueCreditNoteDocument()` renders and announces afterwards — so a caller inside a transaction can hand both back out.
- `mollie-billing.max_seats` (`BILLING_MAX_SEATS`, default `1000`): largest seat count a single change may request. `SeatCountExceedsMaximumException` is thrown above it.
- `RenewalCollectedForEndedSubscription` event: Mollie collected a recurring charge for a subscription that no longer runs (a cancellation that never landed at Mollie). Carries the payment id, the invoice that was still written for it, the gross amount and the local status. Listen for it to automate the refund and a second cancellation attempt.
- New `subscription_meta` key `activation_claim`: an in-flight subscription activation's durable claim, so a concurrent payment backs off instead of creating a second Mollie subscription. Removed on both the success and the failure path; expires after `WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES`.
- New `subscription_meta` keys: `cleanup_vetoed_at` (orphan cleanup vetoed by the app), `usage_overage_counted_payments` (dunning budget, per payment id), `trial_ending_notified_for` (the `trial_ends_at` date already warned about), `orphaned_prorata_payment` (a prorata charge Mollie collected but no webhook completed).
- `Billable::grantedBillingQuota($type)` — the quota actually credited for the current period, which on a trial is the prorated share and not the plan's period quota. Backed by the new `subscription_meta.trial_quota` marker and `Support\TrialQuota`, which now owns the proration arithmetic the trial activation used to inline.
- `Support\UsageReason::label()` — one place that turns a wallet transaction's stored reason into readable text, with `subscription_trial_start`, `subscription_trial_conversion`, `overage_settlement` and `one_time_order:{product}` translated (en + de) and an unknown identifier title-cased rather than shown raw.
- `WebhookSupport::creditPeriodQuota()` and `::hasUnsettledOverageFor()` — the renewal's wallet refill, shared with the paid trial conversion. `SubscriptionPaymentHandler::hasUnsettledOverageFor()` delegates to it; `WalletUsageService`'s constructor no longer takes the catalog, whose only use was the threshold lookup that is now `grantedBillingQuota()`.

### Changed

- `docs/usage-billing.md` stated the plan-change excess formula the wrong way round (`proratedOldQuota - planOnlyBalance` instead of `actuallyUsed - proratedOldQuota`). The two agree only at exactly 50% elapsed, which every table row but one used — the 25% row was off by 11×, documenting €1.00 of overage where the code correctly charges €11.02.

- `InvoiceService::generateAndStorePdf()` returns `bool` instead of `void`, so `regeneratePdf()` can tell a failed render from an invoice that simply still has its previous document. Only relevant to apps that override the `protected` method.
- The OSS export CSV has a trailing `scope` column. Existing column positions are unchanged; anything reading the file by index keeps working, but only `scope = oss` rows belong in the OSS return — see [docs/vat-handling.md](docs/vat-handling.md#what-scope-means--read-this-before-filing).
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

### Removed

- `InconsistentStateException` — never thrown, never referenced, undocumented.
- `BillingInvoice::lineItemDaysActive()` / `lineItemDaysRemaining()` and their private period resolver — no callers anywhere, and the pair carried an orphaned docblock in German.
- `ResubscribeSubscription`'s private `computeMarkerDiscount()` — replaced by the `CouponService` call it was a partial copy of.

### Fixed

- **Nothing is charged for seats or addons added during a trial.** A trial is free, and everything the current plan allows is part of what is being tried — but a seat added mid-trial was prorated and collected, which also converted the trial into a paid subscription on the spot. Nothing was paid for the window those seats are added to, so there is no fraction to charge and nothing to credit when they are removed again; Mollie is still PATCHed, so the first real charge collects the new amount in full. A plan or interval change remains charged and still ends the trial. `BillingPolicy::isFreeTrialAdjustment()` is the single predicate the composer, the context builder and the preview share — the preview already quoted €0 for such a change while the change itself collected, and the two now agree. Pinned by `tests/Feature/Subscription/TrialAdjustmentIsFreeTest.php`.
- **A trial that is charged mid-trial now gets the period it paid for.** Changing a plan, an addon or just the seat count during a trial converts it: the status goes `active` and a new billing period starts, charged in full. The wallets were left holding the trial's prorated fraction for that whole period — for a seat change nothing ran at all, because the plan-change adjuster only fires when plan or interval move. Both routes out of a trial now share the renewal's own refill (`WebhookSupport::creditPeriodQuota()`, reason `subscription_trial_conversion`). Pinned by `tests/Feature/Webhook/TrialConversionWalletTest.php`, which reports 10 of 20 units without the fix.
- **A plan change during a running trial no longer bills quota that was never granted.** `WalletPlanChangeAdjuster` measured the old side against the plan's full period quota, so the units a trial was never credited read as consumption — and with a trial barely elapsed the prorated entitlement is near zero, making all of them excess: deducted from the new plan's quota and, past that, charged as overage. An untouched 10-of-20 trial wallet came out of a downgrade holding 1 unit instead of 5. The old side now comes from the recorded grant and the new side is prorated to the same trial length, with the grant record following the plan. Pinned by `tests/Feature/Wallet/TrialPlanChangeRebalanceTest.php`.
- A **trial no longer reports usage it never had**. A trial is credited only its prorated share of the plan quota (14 days of a monthly plan with 20 included units = 10), while every meter, percentage and threshold measured against the plan's full 20 — so the 10 that were never granted rendered as consumption, and a subscription minutes old showed half its quota gone. All of them now measure against `grantedBillingQuota()`, and the meter labels the trial's total as such. The usage threshold warning was wrong the same way and in the customer's disfavour: at 80 % of 20 a trial wallet is already 6 units into overage, so the warning that its quota was running out never arrived in time. Pinned by `tests/Feature/Wallet/TrialGrantedQuotaTest.php`.
- The usage history no longer prints raw reason identifiers. `subscription_trial_start`, `overage_settlement` and every `one_time_order:{code}` had no translation and were rendered verbatim in the customer's transaction table; resolution now goes through `UsageReason::label()`, which also title-cases anything it does not know instead of leaking snake_case.
- `php artisan migrate` no longer aborts on **PostgreSQL** while retyping the wallet morphs. `wallets` carries `unique(['holder_type', 'holder_id', 'slug'])`, which Laravel's Postgres grammar creates as a *constraint*, and Postgres refuses to drop the index behind it: `SQLSTATE[2BP01]: cannot drop index wallets_holder_type_holder_id_slug_unique because constraint … requires it`. The migration dropped every index it found with `dropIndex()`; a unique one now goes through `dropUnique()` (`alter table … drop constraint …`), falling back to the index form for a unique index that has no constraint behind it. Affected every install whose `billable_key_type` is not `int`; SQLite and MySQL cannot see it, because there both compile to the same statement. Pinned by `tests/Feature/Migrations/WalletMorphPostgresTest.php`, which runs against a real Postgres and skips without one.
- A usage-overage charge whose create-payment call never came back is now **reconciled against Mollie instead of guessed at**, closing the double charge the idempotency key below cannot reach (Mollie caches a key for one hour; the nightly sweep is a day later). `charge()` marks the snapshot `payment_unknown_at` when the request left the process without an answer, and from then on: `anotherChargeIsInFlight()` counts it as in flight, `WalletPlanChangeAdjuster::chargeDeferredOverage()` no longer hands the debt back to the wallet (which is what the next sweep then charged a second time — and for a plan-change line the webhook credits nothing back, `settles_wallet: false`, so nothing cancelled out), and `PrepareUsageOverageJob` pass 3 leaves it alone rather than sending it through a retry ladder that is only safe inside Mollie's one-hour window. `CleanupStalePendingOverageChargeJob` then asks Mollie's own payment list for the customer and decides: adopt the payment it finds (matched on metadata, amount and creation time), drop the marker when Mollie holds no such payment so the debt stays chargeable, drop it too when the customer is gone at Mollie (404/410), and decide nothing at all while Mollie cannot be reached. That reconciliation runs on its own short clock (`RECONCILE_UNKNOWN_AFTER_HOURS = 1`) rather than the job's 72-hour staleness gate: those 72 hours buy patience for a delivery that is still coming, while here nothing is coming and the debt plus every later overage for that billable is frozen until the question is settled. Pinned by `tests/Feature/Wallet/LostOveragePaymentReconciliationTest.php`, whose counting test reports 2 sends without the fix and 1 with it.
- Browser suite: `WorkbenchSetting`'s cross-process writes retry on `database is locked`, and the reverse-charge invoice test waits for the VIES validation to be *persisted* instead of pausing 1200 ms. `busy_timeout` cannot cover a transaction that reads before it writes — SQLite refuses that read-to-write upgrade immediately rather than consulting the busy handler, because waiting would risk a deadlock — and `nextId()` must read its counter to increment it. Two runs failed on this: once as the raw lock, once as an invoice carrying 19 % VAT because the same lock had rolled back the billing-data save, which read like a VAT defect and was not one.
- **Measured against the real Mollie API**, not just against fakes: three new cases in `tests/Sandbox/MollieRequestContractTest.php` pin the contracts the two fixes above depend on — a repeated idempotency key is answered with the *first* payment (so a retry cannot double-charge), a reused key with a *different* amount is rejected (which is why the overage key carries the gross), and a listed customer payment really does expose the `metadata.type` / `metadata.billable_id` / `amount.value` / `createdAt` that the reconciliation matches on. The idempotency case deliberately sends a key of the exact production shape, backslashes from the class name and colons from the ISO timestamp included.
- The usage-overage charge carries a **stable idempotency key**, so a retry of one dunning cycle cannot buy a second payment for the same debt. `charge()` persists the snapshot, calls Mollie, then stores the payment id — when the call throws *after* Mollie created the payment (a read timeout on the response is the ordinary case) no id is ever stored, and `RetryUsageOverageChargeJob` charged the same debt again 60 seconds later. Both settled, each into its own invoice under the webhook's per-payment idempotency. Mollie now replays the original response instead and the retry learns the payment id it lost. The key is `usage-overage:{billable}:{cycle}:{gross}`: the snapshot's `created_at` is the cycle, so p1 → p2 → p3 share a key while the next cycle's legitimate charge does not, and the gross is in it because Mollie answers 400 to a reused key whose parameters differ. Mollie caches a key for one hour, which covers this ladder (`backoff()` is `[60, 300, 900]`) and deliberately not the nightly sweep.
- `MollieCall::send()` takes an optional idempotency key and owns its lifetime — it clears the key in a `finally`, because the SDK clears it from a *response* middleware that a throw never reaches, so a failed call used to leave its key on the shared client for the next mutating request to carry. `InvoiceService::forgetIdempotencyKey()` now delegates here instead of keeping a second copy of that cleanup.
- `CountryMatchService` writes `subscription_meta` under the row lock (`MutatesBillingMeta`) when recording an issued correction and a verified country. `issueCorrectionCharge()` writes `country_corrections` to the same column and the webhook clears it from another process, so whoever saved second discarded the other's key — losing `country_corrections` makes `needsCorrection()` re-issue a charge already in flight, and the customer pays twice for one supply period.
- `ApplyScheduledChangesJob::$uniqueFor` is 3600, not 900. Laravel takes the unique lock at *dispatch* time, so a lock equal to `timeout` was already spent by the time a job that ran to its limit finished — and the dispatching pass, which finds the row until `scheduled_change_at` is cleared, could queue a second `apply()` that charges the customer again. `JobQueueBoundsTest` now asserts `uniqueFor > timeout` for every `ShouldBeUnique` job in `src/Jobs`, enumerated from the directory.
- `PrepareUsageOverageJob` pass 3 guards each row, like passes 1, 2 and 4. `chunkById()` walks ascending ids, so one unreadable snapshot — or one `dispatch()` against an unreachable queue — ended the pass *and* the passes after it, silently skipping every higher id for a day.
- `OssProtocolService::scopeFor()` decides `domestic` before `non_eu`. `sellerCountry()` accepts a seller outside the EU on purpose, and for such a seller its own home turnover was labelled `non_eu` — the OSS total was unaffected, but the national-return rows lost their label. The seller country is now resolved once per export instead of once per invoice.
- `CleanupStalePendingOverageChargeJob` replays a lost delivery through the new public `MollieWebhookController::replayDelivery()` instead of `ReflectionMethod::setAccessible()` on a protected method, which a rename would have broken silently — and the replay path, the one that recovers money Mollie already collected, had no test at all. It has one now.
- A chargeback that puts a subscription past due now dispatches `PaymentFailed`, like the failed renewal producing the same transition. An app hanging its dunning off that event missed every chargeback-driven episode.
- `CouponService::redeem()` grants the `renewal` context exemption only to Recurring coupons. `redeem()` is public API taking a caller-supplied array, so the flag bought any coupon type a second redemption past an exhausted campaign.
- `MollieSubscriptionGate` announces a cleared `mollie_subscription_id` via the new `MollieSubscriptionReferenceCleared` event (reason `gone` for 410, `not_found` for 404). Afterwards `hasLiveMollieSubscription()` answers false, which is indistinguishable from a billable that never had one, so a log line was the only trace.
- `WalletPlanChangeAdjuster::restoreUnchargedExcess()` logs every line it cannot put back. The existing warning fires only when at least one line *was* restored, so a wholly unrestorable set left no trace at all — and those units are gone, the wallet having already been clamped to 0.
- Four docblocks that had drifted onto the wrong method: `SingleChargeHandler::pinVatRate()` carried `settleOverageWallets()`'s, `MandateOnlyPaymentHandler::countRecurringGrant()` carried `stashTrialCoupon()`'s, `CleanupOrphanedBillablesJob::refuseCleanupAfterMollieFailure()` carried `mollieMayHavePaid()`'s (whose text also still claimed it was consulted only without a pending-payment marker), and `CountryMatchService::assertCountryIsInvoiceable()` carried `assertCountryIsResolvable()`'s.
- The "overage price without a quota" warning names what the shape actually does. Pay-per-use — no allowance, every unit billed — is supported end to end, and the message read as if it were a defect.
- A subscription whose Mollie customer was deleted is forgotten. Mollie answers **410 Gone** for a resource that existed and was removed, not 404, so the gate — which cleared the stored id on 404 only — left it in place forever: unreachable, un-cancellable, and blocking a replacement. Measured against the sandbox.
- An orphaned billable whose Mollie customer no longer exists can be cleaned up. The sweep answered "might have paid" to anything it could not ask, which is right for a timeout and wrong for a 404/410 — those say there is no customer, so there are no payments to protect. The orphan could never be removed and every nightly sweep logged the same warning.
- `StartSubscriptionCheckout::handle()` refuses a request without `amount_gross` instead of reading it as 0. Its signature always said the key was required; omitting it produced `(int) null`, and 0 on a paid plan fell through to the €0 mandate-only branch — mandate captured, subscription activated, first period never charged.
- A trial longer than one billing interval keeps its whole trial as grace when cancelled. The boundary took the earlier of `trial_ends_at` and the interval-derived date, which is the same answer for a short trial and the wrong one for a long one — a 45-day trial on a monthly plan gave the customer 30 of the 45 free days they were promised.
- An invoice PDF totals to the gross the invoice was booked with. Given only a VAT rate the renderer recomputes `net × rate`, and the stored figure is not always that number — a partial credit note allocates its VAT across the lines it reverses — so the document was a cent or two away from the amount it is the document of.
- `update()` reports the date a scheduled change is actually written for. During a trial the scheduler anchors on the trial end (Mollie's first charge) while the portal was told `nextBillingDate()`, so a trialing customer read a confirmation naming a date two weeks later than what happened.
- A trialing customer can change their plan for the whole trial. The lapsed-period guard assumes a renewal webhook keeps `nextBillingDate()` in the future, which does not hold during a trial — so a trial configured longer than one interval locked the customer out with "wait for the renewal to complete" for a renewal that was neither due nor late.
- `billing:simulate` estimates the renewal gross the way the invoice books it, per line. Rounding once over the sum put the printed figure a cent or two off the charge that then arrived.
- A country correction stuck at a non-terminal Mollie status is escalated after 14 days instead of 24 hours. `pending` is where a SEPA Direct Debit lives for several business days, and escalating cancels the customer's subscription and mails the admins that the reissue failed — for a payment that is simply still travelling.
- Cancelling a free-plan subscription notifies the billing admins, like a paid one always did. The Local branch returned before the notification, so churn was reported only half the time.
- Resuming a free plan starts a fresh dunning clock. A stale `past_due_since` from an earlier episode meant the next failed overage charge was measured against a months-old date and auto-cancelled the same night.
- The overage dunning ladder no longer forces `PastDue` onto a Cancelled or Expired billable — that undid the cancellation, blocked the resume flow and later handed back access the customer had given up. The debt is still recorded and still reported.
- A usage type priced without an included quota is billed on a plan change instead of forgiven. The excess calculation was gated on the old plan including some of it, so for that shape a plan change silently wiped the whole unbilled debt.
- Seat deltas are serialised across their whole read-modify-write. The row lock they used to rely on is released before the write (no Mollie call may sit in a transaction), so two "member invited" events both read the same count and one seat was dropped.
- The usage-threshold warning is sent once per period again. The nightly pass sets the period anchor to tomorrow, and the dedupe compared against it — so for the rest of the day every debit above the threshold re-sent the same mail.
- A mandate-only signup whose coupon stopped covering the full price is reported instead of logged. The mandate is captured at that point, so it is a customer who completed a €0 checkout and got nothing.
- A plan switch between two equally priced plans can no longer start while another change's payment is in flight. The one-pending-change rule inferred "internal re-validation" from both prorata amounts being 0, which is also true of such a switch.
- A refused coupon redemption on an inline one-time order no longer leaves an orphan PDF on disk carrying a serial number the books have no record of.
- Admin refunds carry an idempotency key. A call that timed out on the wire but arrived at Mollie could be repeated by a retry or a second click, and paid the customer twice.
- Operator actions in the admin panel are recorded as `admin` in the audit trail. The actor was derived from the route name, and a Livewire panel only loads its route once — so every mutation after the first page load was filed as if the customer had made it.
- An abandoned OSS export run no longer blocks every future export. A killed worker or a Ctrl-C left the row claiming to be in flight forever, in the one part of the package whose output goes to a tax authority on a deadline.
- The billing-data form pushes a changed company name to the Mollie customer record, like the identical form on the plan-change screen always did.
- The admin invoices tab offers no refund button for an invoice that cannot be refunded (saldo-zero, or booked without a Mollie payment) — it could only ever produce an error.
- `billing:check-config` no longer fails a working config over a missing `plans.*.tier`. Nothing in the package reads it; it warns instead, and still errors on a non-integer value.
- `docs/refund-management.md` no longer advertises `InvoiceService::createStandaloneCreditNote()`, which does not exist — prorata credit notes are written by `createRefund()`.
- `docs/vat-handling.md` describes `calculate()` as it is: it takes a `Billable`, never calls VIES, and refuses reverse-charge on a domestic supply.
- The README no longer promises that publishing the views overrides the Livewire components. Livewire 4 resolves them from a single registered path and has no notion of a vendor override.

- **The package could not be installed at all with `BILLING_BILLABLE_KEY_TYPE` at its shipped default.** `2026_01_01_000006_alter_wallet_morphs_for_billable_key_type` dropped `wallets.holder_type`/`holder_id` while `wallets_holder_type_holder_id_slug_unique` still covered them, which aborts `php artisan migrate` halfway on SQLite; its raw `DROP INDEX IF EXISTS \`name\`` is not valid MySQL syntax at all, so the same migration failed immediately there. It now enumerates the live schema (`Schema::getIndexes()`) and drops every index covering the morph columns through the Blueprint, which is portable. Only `billable_key_type = 'int'` — the one value the test suite pinned — ever survived, which is why nothing caught it.
- **The wallet morph migration destroyed bavix's `transfers` table.** `transfers.from_id`/`to_id` address a *wallet*, never the billable, and since bavix 9 they are plain bigint columns with no `_type` sibling. Retyping them to uuid dropped the host app's columns and re-added a NOT NULL `from_type` that `Bavix\Wallet\Models\Transfer` never fills, so every `transfer()`, `pay()` and `exchange()` in the consuming app failed on insert. Only `wallets.holder` and `transactions.payable` are touched now, and a half-present morph is skipped rather than guessed at.
- **An overage in flight is no longer charged a second time.** `ChargeUsageOverageDirectly::handle()` was the one entry point without the in-flight guard its two siblings have, and it is what an immediate cancellation goes through (`CancelSubscription::handle(immediately: true)`, reached from the portal and from `PrepareUsageOverageJob`'s past-due auto-cancel). The wallets stay negative until the charge settles — with SEPA that is days — so the nightly period-end payment and the next morning's cancellation both billed the same debt, each settling into its own invoice and crediting the units again. The retry path keeps its own unguarded route, since it is driven by the very snapshot the guard keys off.
- **A documented `BILLING_PLAN_CHANGE_MODE` value bricked the application.** The config file cast the env value with `PlanChangeMode::from()`, which runs inside the service provider's `mergeConfigFrom()` — so `EndOfPeriod` (one of the three spellings `docs/configuration.md` printed; the backing values are `immediate` / `end_of_period` / `user_choice`) threw a `ValueError` during `register()`, killing every request and every artisan call, `billing:check-config` included. It now resolves case-insensitively via `tryFrom()` and degrades to `user_choice`; `billing:check-config` reports the offending raw value and names the one that was meant. The docs print the backing values.
- **A prorata coupon line carries the VAT rate of what it discounts.** `applyCouponDiscountsToProrataLines()` read the rate only from a line with `kind === 'plan'`, and a seat-only or add-on-only change produces no such line — so the discount was booked at 0 % VAT while the lines it offsets carried the country's rate. The customer paid VAT on the undiscounted net and the invoice's own totals no longer added up. The rate now comes from the first charge-direction line that has one, falling back to a refund-direction line.
- **The Local→Mollie upgrade forwards the coupon it priced with.** The plan screen priced the upgrade through `PreviewService` *with* every applied code — so the customer was charged the discounted amount — but sent no `coupon_code` to `UpgradeLocalToMollie`. The webhook therefore had nothing to redeem: no redemption row, no recurring marker (the Mollie subscription was created at full price, losing the ongoing discount), and the reconciliation saw an amount it could not explain and fired `PaymentAmountMismatch`. The first-payment path carries exactly one code, so a stacked selection is refused with a message (`billing::portal.flash.upgrade_single_coupon_only`) rather than charged at one price and recorded at another.
- **Checkout validates add-ons and seats even when their step is not rendered.** `submit()` gated `validateStep3()` on `hasAddonsOrSeatsStep()`, so for a plan with no allowed add-ons and no seat price — where that step does not exist — `addon_codes` and `extra_seats` reached the order completely unchecked. A crafted Livewire payload could buy free capacity (the catalog prices seats at 0 where no `seat_price_net` is sold) and switch on an add-on reserved for another tier, whose `feature_keys` `FeatureAccess` then granted. The validation is unconditional now, a non-zero seat count on a plan that sells none is refused with a field message (`billing::checkout.seats_not_available`), and switching to such a plan resets the count the way it already dropped incompatible add-ons.
- **A throwing `PaymentSucceeded` listener no longer cancels a paid plan change.** In `ProrataChargeHandler::paid()` the event and the invoice notification sat inside the try/catch guarding invoice persistence, so an app listener that threw logged "Failed to persist prorata_charge invoice" — which had in fact succeeded — and returned before the plan switch was applied. Mollie's re-delivery could not repair it either: `paid()` opens with the invoice guard and no-ops. The customer had paid a prorata charge for a switch that then simply never happened. Both side effects now run outside the try, each isolated through the newly public `WebhookSupport::safely()`.
- **A `suspended` Mollie subscription keeps its id.** `MollieSubscriptionGate::snapshot()` treated `suspended` as terminal and dropped `mollie_subscription_id` — but Mollie hands the subscription straight back from `GetSubscriptionRequest` and resumes it once a usable mandate exists. Forgetting the id made it unreachable: nothing could cancel or PATCH it, the documented past-due recovery had no target, and `hasLiveMollieSubscription()` answering false let the customer back into checkout, where `CreateSubscriptionRequest` 422s with "same description already exists". Only `canceled` and `completed` drop the id now, and `suspended` counts as live for the checkout gate.
- **A seat count cannot be set below the seats actually in use.** `ValidateSubscriptionChange` enforced that floor only on the auto-derive branch; the explicit branch — the only one the portal's seats screen reaches — did not. An org with ten members could set the counter to one, collect a prorata refund for nine seats, and keep all ten working, because the package never revokes seats, it counts what the host app reports through `getUsedBillingSeats()`. The screen's own minimum is clamped to that number too, so it no longer proposes a change the validator refuses.
- **A failed free downgrade no longer orphans a live Mollie subscription.** `MollieSubscriptionPatcher::updateForIntent()` discarded `cancelForFreeDowngrade()`'s result, so on the pro-rata executor's path `UpdateSubscription` assumed the cancel had landed and dropped `mollie_subscription_id` — after which nothing could target the subscription and Mollie kept charging the old price every period. **Breaking (API):** `updateForIntent()` returns `bool` instead of `void`; subclasses overriding it must widen their signature.
- **"Apply the scheduled change now" no longer destroys the change it is applying.** The portal reassembled the payload by hand and cancelled the stored change *before* attempting it, never restoring it. It could not succeed at all under `plan_change_mode = end_of_period` (no `internal` flag), it replayed an auto-derived seat count as an explicit one, and it forwarded only the legacy single `coupon_code`, dropping stacked codes and the discount with them. It delegates to `ScheduleSubscriptionChange::apply()` now — the same code the daily dispatcher runs, which clears the change only on success.
- **A failed plan change no longer wipes another change's in-flight markers.** The plan screen's catch block called `clearPendingPlanChange()`, which also unsets `pending_prorata_change` and `prorata_pending_payment_id` — keys an addon or seat change writes without any `pending_plan_change` of its own. A customer whose addon charge was still open and who then hit any error on the plan screen had the marker deleted underneath a live Mollie payment; when it settled, `ProrataChargeHandler` had nothing to match and reported an orphaned charge.
- **A country mismatch opened by a renewal no longer swallows that renewal.** `SubscriptionPaymentHandler::paid()` ran the three-way country check before booking anything — and the check is not a read: on a mismatch it flags, which cancels the subscription and writes status `Cancelled` immediately. The very next guard (`isBillableState()`) then saw a dead subscription and returned through `reportRenewalForEndedSubscription()`: an admin alert claiming Mollie had collected for a subscription that no longer runs, no wallet recharge, no period advance, and a customer who had just paid locked out for the period they bought. It needs no exotic state — a customer who declared AT and pays with a DE card from a DE IP is exactly the drift the mismatch flow exists for. The check now runs last, the same ordering `FirstPaymentArtifacts` and `MandateOnlyPaymentHandler` already use.
- **`plan_change_mode = end_of_period` no longer breaks the seat and addon controls.** The mode governed *every* call into `UpdateSubscription::update()`, and `SyncSeats`/`EnableAddon`/`DisableAddon` send no `apply_at` at all — so every click on "Update seats", "Add add-on" and "Remove add-on" threw `Immediate plan changes are not allowed.` It now applies only to a request that actually moves plan or interval. `changeBillingPlan()` is scheduled instead of refused: the DTO tracks whether the caller *named* an `apply_at` (`SubscriptionUpdateRequest::$applyAtExplicit`), so "asked for immediate" stays refused while "said nothing" gets what the mode means.
- **`billing:check-config` now errors when the Mollie webhook route is not mounted.** Every payment carries `webhookUrl: route(BillingRoute::webhook())`, so an app that mounted the portal and the checkout but not the webhook threw `RouteNotFoundException [billing.webhook]` on its very first payment attempt — a 500 in checkout with nothing naming the cause. The README's route block was missing `MollieBilling::webhookRoutes()` entirely; it is there now, with a note that it must sit outside the `web` group.
- **Resolving a country mismatch to a country the package cannot invoice is refused before the first refund goes out.** `resolve()` refunds every linked invoice and only then issues the correction charge, and that failure was swallowed — so choosing a non-EU signal (a UK-issued card, a Swiss IP, both of which the portal modal and the admin screen offered) left the customer fully refunded, never re-charged, and holding a `billing_country` that makes every later checkout, resubscribe and renewal throw. Both selectors now filter on the new `VatCalculationService::isInvoiceable()`, and `NonEuCountryException` carries a message instead of an empty string.

### Security

- **No Mollie call can run inside a database transaction any more — enforced, not documented.** Every Mollie request in the package goes through the new `GraystackIT\MollieBilling\Support\MollieCall::send()`, which refuses when a transaction is open (throws in testing/local, logs a warning in production so a payment the customer is waiting for is never dropped). Reviewing 30 call sites against every caller that might open a transaction is exactly the kind of check that rots, and it had: `EnableAddon`/`DisableAddon` wrapped the whole plan-change engine — payment, refund and PATCH — in `DB::transaction` to serialise their read-modify-write of `active_addon_codes`, and `UpdateSubscription`'s phase-3 body held the free-downgrade cancel, the subscription PATCH and the plan-change overage charge inside its own transaction. A throw after any of those undid the local half while Mollie kept its half: a refund the customer had received with `refunded_net` back at 0 (so the retry refunded again), a cancelled subscription the local row still called active, a collected payment whose `usage_overage` snapshot — the only thing able to reconcile it — no longer existed.
  - `UpdateSubscription::update()` accepts a **closure** payload, resolved on the billable refreshed under the existing `billing:subscription-change:{id}` lock. That is how a relative mutation (enable *one* addon) gets its read and write into one critical section without a transaction around the Mollie pipeline.
  - `WalletPlanChangeAdjuster::adjust()` takes a `deferOverageCharge` flag and returns the unresolved overage line items; `chargeDeferredOverage()` posts them after the commit. Same shape as `InvoiceService::createCreditNote(deferDocument: true)`.

- The plan-change preview now quotes the usage-overage gross per line, the way `ChargeUsageOverageDirectly` builds the amount it actually collects. Grossing the summed net instead put the confirm screen a cent or more away from what leaves the account once more than one metered type is involved — two 3-cent lines at 19 % gross to 2 cents of VAT per line and 1 on the total.
- The admin panel's sidebar has a "Back to app" link, configurable via `BILLING_ADMIN_BACK_URL` exactly like the portal's own back link. Unset it falls back to `BILLING_DASHBOARD_URL`, so a single-app install configures one value. The `route:<name>` expansion both share now lives in `BillingRoute::configuredUrl()` rather than being spelled out in each layout, and an unregistered route name hides the link instead of raising `UrlGenerationException` from a layout — `billing:check-config` reports it as an error, which is where a typo should stop you.
- A custom checkout step's error message no longer outlives the problem it described. `validate()` clears only the keys it is about to check, so a step reporting a condition no rule can express — "please confirm your email address" — kept that message on screen after the customer had confirmed, under a field that already read *confirmed*. `next()` and `back()` now reset the error bag before they navigate.
- Custom checkout steps gained an optional `enter` callback, run whenever the step is arrived at in either direction. A step whose state cannot be trusted after the customer has been elsewhere — an email confirmation is the plain case — had no way to notice it was current again: returning via Back left it verified-no-longer but unable to re-issue a code, and only a page reload got the customer moving. See [README](README.md#custom-checkout-steps).
- A verified VAT number from the seller's own country no longer claims reverse charge. The verification message was unconditional, so an Austrian business buying from an Austrian seller read "VAT number verified — reverse-charge applies" directly above a total that correctly included 20 % — a statement about the customer's tax position contradicted by the very invoice that followed. Reverse charge is for a cross-border B2B supply inside the EU; a domestic one carries domestic VAT however valid the number is. New `vat_verified_domestic` message, and the country test now lives in `VatCalculationService::reverseChargeCountriesQualify()` so the form feedback and the price display cannot disagree — the checkout's own copy of that policy now delegates to it.
- The nightly overage pass no longer charges the same debt twice. `safeCharge()` decided whether a charge was already in flight from the attributes loaded when its 200-row chunk was hydrated — up to 199 synchronous Mollie calls earlier — so anything that claimed the slot in that window (a retry job, a plan change, a second pass) was invisible. Two recurring payments against the same mandate followed: two invoices, the wallet credited twice, and the first payment orphaned in the snapshot so a lost webhook for it was never reconciled. The row is re-read before the decision.
- `billing:prepare-overage` now takes the scheduled pass's own unique lock instead of a private one, and runs through `Bus::dispatchNow()`. It guarded command-against-command only — not the dangerous pair — and `dispatchSync()` routes through `CallQueuedHandler`, which `forceRelease()`s the unique lock on the way out: a manual run at 02:10 destroyed the lock the in-flight 02:00 pass was relying on.
- `SyncSeatsJob` no longer wraps the seat prorata payment and the Mollie PATCH in a transaction. A throw after the payment rolled back the seat count and the invoice while the money stood at Mollie — collected and unbooked, with `tries = 1` and no retry marker — and the row lock was held across two HTTP round trips. It now decides the target under the lock and calls the service outside it; `UpdateSubscription` already serialises on its own cache lock.
- An overage charge whose `CreatePaymentRequest` keeps failing (a dead mandate) now reaches a terminal state. Pending with no payment id had no path out: the dunning budget is only charged when a previous payment id exists, so `giveUp()` never ran; the stale-charge cleanup returned immediately; and `safeCharge()` skipped every later period because the status was still `pending`. The debt was carried forward, shrinking the customer's usable quota each period while they were billed nothing, and the retry ladder re-dispatched daily forever. Past the hard limit it is now written off, which alerts an operator and frees the next cycle.
- `SweepPendingRetriesJob` guards each row. It is the only reader for `pending_subscription_cancel`, the coupon-expiry PATCH and every refund-retry line, and `chunkById` walks ascending ids — so one marker holding an array where a string belongs aborted the sweep for every billable above it, hourly, forever: Mollie kept charging cancelled subscriptions and rejected refunds never reached their customers.
- `BillingTime::subInterval()` clamps the backwards step the way `addInterval()` clamps forwards. An anchored (re)subscribe back-dated the period with plain `subMonth()`, which overflows, so a 29–31 March anchor read forward as 1–3 April while Mollie charged on the 29th–31st: the dashboard promised a date that was not the one collected, and the overage pass's "renews tomorrow" window matched neither, so that period's overage was never collected. Same fix in the lifecycle simulator, which reported the overage pipeline as broken when run on a month end.
- Three cleanup jobs and `RetryUsageOverageChargeJob::giveUp()` now write `subscription_meta` key-scoped through `MutatesBillingMeta` instead of persisting the blob they read before a Mollie round trip. A `pending_refund_retries` line committed in that window was silently reverted — and the hourly sweep is its only reader, so the money never reached the customer and no dead letter was raised; reverting a removal was a second payout.
- The six scheduled entries that are not `ShouldBeUnique` now carry `->onOneServer()`. On a multi-host deployment each host dispatched its own copy: N× the Mollie polling, N copies of every dead-letter mail, and two cleanups racing each other's meta writes. Two of them invoke webhook handlers directly, bypassing the controller's reservation, so nothing else serialised two hosts replaying the same payment.
- `PrepareUsageOverageJob` reports a pass that exhausts its retries (new `AdminOverageSweepFailedNotification`), `ApplyScheduledChangesJob` gained the `$timeout` every other Mollie-calling job has, and `RetryUsageOverageChargeJob`'s overlapping middleware uses `dontRelease()` — each release consumed one of its three attempts, so a job blocked for 90 seconds died in `failed()` without ever attempting the charge.
- `billing:sync-purchased-balance` chunks instead of loading every wallet at once.
- The country-mismatch gate and the plan-feature gate are now re-applied on every Livewire update, not only on the initial page load. The mismatch is opened by a *webhook* while the customer's tab already sits on the plan or seats page, so booking through an unresolved mismatch was the ordinary timeline — every invoice issued in that window carries a VAT treatment the correction flow then has to refund and reissue. The feature gate had the same hole: a downgrade or revoked grant left the paid screen driveable for as long as the tab stayed open.
- `BillingPortalController::checkout()` authorizes a billable that came from the query-parameter fallback before answering from it. The status code was an oracle — 302 meant "that tenant has a live subscription", 200 meant it does not, for any route key a stranger tried — and the branch fired an outbound Mollie call per probe on someone else's subscription.
- `Sanitize::backUrl()` rejects backslashes and control characters. Browsers follow the WHATWG URL parser, where `/\evil.example` enters the authority state exactly as `//evil.example` does, so the checkout header rendered an attacker-controlled "Back" link on the page immediately before the payment step. `parse_url()` reports no host for it, which is why the existing checks passed it.
- The query-parameter billable scan skips values that cannot be the key. With the default integer key, `?utm_source=newsletter` ran `where('id', 'newsletter')` — a hard error on PostgreSQL (22P02), i.e. a 500 on `/billing/checkout` for ordinary marketing traffic — and one wasted query per parameter everywhere else.
- The usage page parses its two date filters defensively; an unreadable value used to throw from the render path and leave the customer's own page 500ing. The table and the chart now share the parsed range.
- The feature-gate JSON refusal is translated instead of a hard-coded English string.
- The README's and CLAUDE.md's `authUsing()` example now compares the requester against the billable. The old one-liner ignored its `$billable` argument, and every cross-tenant defence in the package delegates to that callback — so an app copying it let any authenticated user read any tenant's billing screens by naming its route key in a query parameter.
- The dashboard's and usage page's wallet meters now refresh when a balance changes. They are child components, which keep their own state across a parent re-render, and their key was fixed — so redeeming a credits coupon reported success while the meter kept showing the balance it first mounted with. The credits were only visible after a full page load, which reads as "the code did nothing". The balances are now part of the key.
- The browser suite gained 18 scenarios covering what the customer actually reads (`CouponCatalogCheckoutTest`, `CouponMidCycleTest`, `InvoiceAndPreviewDisplayTest`): every coupon type at checkout and mid-cycle, the eight refusal reasons at checkout and four in the portal, invoice-archive amounts and summary tiles against the persisted values, reverse charge showing a zero VAT column, and the pro-rata and seat previews against the figures the composer produces. Amounts are asserted through `BillingMoney::format()` on the stored value rather than against literals, so a display that disagrees with the ledger fails — and `dusk` anchors were added to the confirm step, the invoice rows, the summary tiles and both preview screens to make that possible.
- A saldo-zero plan-switch invoice can now be refunded, so a **third** change inside one period credits what the switch consumed instead of 0. The switch holds no `mollie_payment_id` — the new plan's remaining days are paid for out of the invoice that collected the period, and that column is unique because its uniqueness is the webhook's idempotency guard — so its charge line was invisible to every refund path: not a candidate, a header pool of zero, and `createRefund()` rejecting it as "missing payment_id". On a 2900 plan switched on day 11 and downgraded on day 20 that was 1123 net of paid, unused service the customer never got back, plus the new plan's charge on top. New nullable column `billing_invoices.settled_by_invoice_id` records the settlement; `refundableBaseNet()`/`refundableBaseGross()` read a settled invoice's own charge lines instead of its header, and refunds are posted to `refundablePaymentId()`. The pair stays balanced — the source's remaining base drops by exactly what the switch's gains — and when the settlement is not unambiguous the switch stays unrefundable as before. **Requires `php artisan migrate`.**
- A mid-period pro-rata charge line is now stamped with the window it actually bought (`[now, period_end]`) instead of the whole period. The line is priced as `newNet × remaining/total` but recorded as if it had bought the full period, and a *later* change in the same period derives its credit from that stamped window — dividing a 21-day amount by 31 days. Upgrade on day 11 of 31 charged 3997; downgrading back on day 20 credited 1547 where 2284 was owed. 737 net kept from the customer on every second downgrade, and the same 737 overcharged when the second change runs the other way. Seat and addon changes had the identical defect (131 instead of 194 on a 500-cent seat).
- An equal-price plan switch is now booked instead of dropped. `computeProrata()` nets the two sides, so a same-price change reported 0/0 and the executor never ran — while the composer had produced a full charge for the new plan and a matching credit for the old one. Nothing recorded that the old line's remaining value now belonged to the new plan code, so it read as fully refundable for the rest of the period (a later refund pays out value already consumed) and the next change in that period found no line for the plan it is now on. The gate now asks the composer when the aggregate says zero. See [docs/plan-changes.md](docs/plan-changes.md) for the one case this still cannot resolve.
- `createPlanSwitchInvoice()` now books every refund line against the invoice it credits. The composer loads plan, seat and addon candidates through three separate queries, so three lines crediting the same renewal arrive holding three independent clones of that row — and writing them in a loop meant each `save()` overwrote the previous increment. A switch crediting 1965 + 339 + 610 recorded 610, so `remainingRefundableNet()` reported 3690 where 1386 was left: 2304 net of consumed value stayed payable in real money. Same clobber `createRefund()` already guards with a tracker.
- A credit line is no longer offered as something further to credit. `currentPeriodLines()` returned the plan-switch invoice's *refund* lines as refund candidates (same `plan`/`seats`/`addon` kinds, `Paid`/`Subscription` header) and, sorted newest-first, they won over the invoice holding the money — whose pool was then `max(0, 0 − 0)`, dropping every selected line. On the seat and addon paths the loop had already spent its quantity on the discarded candidate, so the real line was never asked for. Candidates are now positive lines on invoices a refund can actually be posted against, and a reduction consumes quantity only once a line exists.
- A partial-quantity refund is capped at the share being refunded, not at the whole line. `prorataFactor()` clamps remaining days at ≥ 0 but never at ≤ the total, so a line whose period starts in the future yields a factor above 1 — and one seat out of three at 1500 was credited 556 for a seat that cost 500. The per-invoice pool bounds the invoice total, never a single inflated unit.
- A reconciled webhook invoice now lands on the amount Mollie actually collected instead of a cent or three away from it. The adjustment line was derived as `round(gross / (1 + rate)) − expectedNet`, which is not the sum the header is built from: `InvoiceService` totals the *per-line* VAT, and six lines whose VAT each rounds up put that three cents above `round(Σ net × rate)`. The document that exists to record what was charged recorded a gross nobody paid, output VAT was declared against it, and `refunded_net` carried headroom Mollie rejects — a full refund of such an invoice fails. The delta is now measured against the header's own summation. Exactly is not always reachable (`net + round(net × rate)` skips values — 51,03 has no representation at 20 %), so it minimises the gap within the cent of slack the caller already tolerates.
- `applicable_usages` on a coupon is now enforced like every other `applicable_*` set. It was read nowhere, so setting it did the opposite of what it says: a coupon restricted to `tokens` applied to everything at full value. No charge in the package carries a usage dimension (overage is priced from wallet balances and never consults a coupon), so a coupon with the field set is now rejected with `usage_not_applicable` until a path passes `usageTypes` — conservative and visible rather than silently inverted.
- A recurring coupon that expires by *date* no longer buys one extra discounted period. The reset PATCH is what actually stops the discount — Mollie charges the amount it holds — and it was decided by asking "is the marker expired *now*", one period too early: a coupon whose `valid_until` fell mid-period was still live at the renewal webhook, so nothing was PATCHed, and the next charge left Mollie discounted. It arrived as an ordinary renewal with no redemption row (the marker had expired by then) and the reset happened afterwards. `markerExpired()` now takes the next charge date, so the reset is posted while it still matters.
- The two coupon types that move a charge date — `PeriodExtension` and `TrialExtension` — no longer PATCH Mollie from inside `CouponService::redeem()`'s transaction. A throw afterwards (the redemption insert, a `CouponRedeemed` listener) rolled back the redemption row, the incremented `redemptions_count` and the local dates while Mollie had already moved the charge: free period, campaign slot still open, and re-applying the code extended a second time. Both now go through `MollieSubscriptionPatcher::deferNextChargeDate()`, which writes the local half inside the caller's transaction and posts an **absolute** target after it commits — absolute because a relative `+N days` cannot be retried without extending twice. A failed PATCH leaves a `pending_subscription_patch` marker (`reason: next_charge_date`) for the hourly sweep.
- A seat or addon change with a recurring coupon no longer redeems it twice and zero out its discount basis. `UpdateSubscription` defers its redemption only for a plan or interval change, so a seat sync redeemed in its own phase *and* raised a deferred Mollie charge — and Phase 2 then redeemed again from the codes it found in the charge lines. That fallback carries no recurring net, so `redeem()` rewrote `active_recurring_coupon` with `base_amount_net = 0`: `computeMarkerDiscount()` returned 0 from then on and the PATCH that follows set Mollie back to full price. A 50%/12-month campaign stopped discounting after one seat change — €159.50 per subscriber at €29/month — and the customer had burned two campaign slots for it. Phase 2 now redeems only what Phase 1 deferred.
- A parked `single_payment` coupon is no longer consumed by a trial-end charge Mollie collects in full. A Mollie subscription carries one amount, so there is nowhere for a one-charge discount to live; the handler put the discount line on the invoice anyway, `reconcileWithPaidAmount()` cancelled it back out with an adjustment (net and gross unchanged — the customer saved nothing), `PaymentAmountMismatch` fired, and the coupon was redeemed regardless. It is now left unapplied and spendable, with a warning, and [docs/coupons.md](docs/coupons.md) says so instead of promising the discount.
- A renewal invoice is stamped with the period it paid for, not the one that just ended. `createForPayment()` read the billable's anchor, which the renewal handler only advances *after* the invoice exists — so the document carried the expired window, and `currentPeriodLines()` (which drops any line whose period end is more than a day from the billable's own) could not find the very invoice that paid for the current period. Every mid-period downgrade after a renewal therefore composed its refund against nothing and credited **0**: on a €43.00 net configuration halved on day 14 of 28, the customer was €21.50 net out of pocket. `createForPayment()` now takes the window explicitly, as `ProrataChargeHandler` already did.
- A settled plan-change overage no longer credits its units back into a wallet that was already cleared. `WalletPlanChangeAdjuster` clamps the balance to 0 as it charges — those units are consumption, not capacity — while `settleOverageWallets()` credits `quantity` back, which is right for a period-end charge whose balance is still negative. For a 390-unit plan-change charge the customer ended up with 390 units on a plan that includes 10, and a rollover type kept them for good. The adjuster's lines are marked `settles_wallet: false`.
- A plan-change overage is refused while a period-end charge is still settling. `subscription_meta.usage_overage` is a single slot and only `safeCharge()` guarded it, so a plan-change charge overwrote an in-flight one and inherited its `created_at` — hence its dunning cycle and counted-payments list. `RetryUsageOverageChargeJob` resolves the snapshot rather than a payment id, so a failure webhook for the overwritten payment charged the *new* line items a second time (54 net collected twice) while the overwritten charge's 74 net was never recovered and was invisible even to the stale-snapshot sweep. The excess stays on the wallet for the next sweep instead.
- A Local rollover renewal clamps `purchased_balance` before crediting, like the webhook renewal always did. A rollover type never zeroes its wallet, so nothing else brought the value down: a wallet whose 500 bought units were fully consumed still reported 500 purchased, the next 100-unit renewal read as 100 purchased (and 100/100 of the plan quota consumed although nothing was), and a later upgrade computed its target balance 500 units too high. Both paths now go through `WalletUsageService::creditRollover()`.
- The paid-overage handler settles the wallets before clearing the markers. A hard kill between the two is not catchable, and clearing first left the debt on the wallet with no snapshot and no marker — nothing could match that payment again and the next sweep charged the same 37 units a second time. Crediting first leaves a `pending` snapshot whose invoice exists, which two existing recovery paths already recognise.
- An out-of-order renewal delivery is invoiced but not booked as a new period. Idempotency here is per payment, so a late delivery for an older renewal ran the whole body: the anchor went back a month — after which the overage pass's "renews tomorrow" never matched again until the next renewal, so that period's overage was never collected — and a rollover wallet was credited a second full period on top.
- A monthly period ends on the last day of a shorter month instead of three days into the next one. `addMonth()` overflows — 31 January plus one month is 3 March — and Mollie charges a monthly subscription on the last day of a month that has no 31st, so for every customer billed on the 29th, 30th or 31st the two disagreed. Three consequences, two of which cost money: the dashboard promised a charge date after the money had already been taken; `PrepareUsageOverageJob` charges a period's overage on the day *before* `nextBillingDate()`, which by then was after Mollie had renewed and moved the anchor, so that period's overage was never charged separately at all; and the prorata window was 31 days long for a 28-day period, pricing a mid-period upgrade of €30.00 net at 17/31 (€16.45) instead of 14/28 (€15.00) — with a downgrade over-crediting by the same arithmetic. Every "one interval later" now goes through `BillingTime::addInterval()`. A yearly period starting on 29 February ends on 28 February, not 1 March.
- No Mollie call runs inside a database transaction any more. `UpdateSubscription::update()` and `RefundInvoiceService::refund()` each held one transaction across their Mollie calls *and* every local write, so anything throwing after the money moved rolled the local half back while Mollie kept its half: a refund the customer had received with no credit note and `refunded_net` at 0 — so the retry refunded again — or a cancelled subscription with the local row still saying Mollie/Active. Both now decide under the row lock, call Mollie with nothing held, and write in a second short transaction; a cache lock per billable/invoice provides the serialisation the long transaction used to. The loser of that lock is refused with `SubscriptionChangeInProgressException` / `RefundInProgressException` instead of queueing behind a payment plus a PDF render. The credit note's PDF and its event moved out too, so the invoice row lock and the serial range are no longer held across a DOMPDF render — which is what made a lock-wait timeout against a concurrent refund realistic.
- A change's events fire after the commit, not inside it. `update()` wraps the whole change — including the Mollie payments and refunds `applyProrata()` posts — in one transaction, and events are the documented extension seam: a throwing listener rolled back the credit note, the `refunded_net` increment and the seat count while Mollie had already paid the customer, and the retry refunded a second time. The failure still surfaces (it is an app bug), just after everything is durable.
- A quantitative addon is priced by its real quantity on both sides of a change. `PlanChangeIntent` was built with `array_fill_keys(…, 1)` while `SubscriptionAmount::net()` reads `getBillingAddonQuantity()` — whose docblock promises the override is honoured "in every place where pricing is calculated" — so the prorata window priced one unit while the recurring window billed three: the preview quoted €27.00, Mollie was asked for €9.00, and two thirds of the old addon were never credited.
- The prorata preview resolves two VAT rates. The credit reverses an invoice that was already issued and carries that invoice's line rate; the charge is a new supply and is priced by the composer through `liveVat()`. Applying the old rate to the charge reported VAT on a supply that carries none for a customer who had since become reverse-charge-eligible. `currentPeriodCredit` also stops claiming a full-period credit on a past-due reset, where nothing is credited at all.
- `applyPendingPlanChange()` refuses a Cancelled or Expired subscription, the guard its live twin `ProrataChargeHandler::paid()` has. Force-filling plan fields onto an ended row resurrects the plan-scoped fields the expiry pass wipes, handing `@planFeature` and the `billing.feature` middleware back to a subscription that ended.
- The abandoned-reservation takeover is conditional on the value it read, so exactly one delivery wins. `firstOrCreate` was already race-safe; the takeover was not, and two deliveries proceeding together could write two credit notes for one refund — a credit note carries `mollie_payment_id = null`, so no unique index stopped it.
- A saldo-zero plan switch increments `refunded_net` on the invoices its refund lines reverse. Both readers filter on `invoice_kind = Refund` and the switch document is a `Subscription`, so the reversed line stayed fully refundable and a later downgrade composed its refund against a document with no `mollie_payment_id` — which `createRefund()` can never pay out.
- Three more `subscription_meta` writes in `InvoiceService` go through the locked mutator. Each read the in-memory attribute, then wrote the whole JSON column back after a Mollie call or a PDF render — long enough for a concurrent writer's committed key to be reverted, including a `pending_refund_retries` line the retry job had just consumed.
- The invoice serial prefix is truncated to its `P` slots, and `billing:check-config` reports a prefix that does not fit. `extractCounter()` reads the counter at an offset computed from the format, so a longer prefix made writer and reader disagree: with the default format and prefix `INV` the serials converged and then repeated, and `persistWithSerial()` rethrew after three identical retries — every invoice from that point on failing for money Mollie had already collected.
- The three credit-note readers on `BillingInvoice` share one lookup, and the admin invoice tab preloads it for the whole page. `creditNetForGross()` reaches them up to eighteen times per refund, each running the same query and hydrating every credit note of the billable — all while holding the invoice row lock and the serial range.
- `billing:cleanup-orphans` reports deletions, vetoes and paid-money holds separately. A veto shrinks the candidate count exactly like a deletion, so rows the app's closure refused to delete were reported as removed — on a destructive command, where that count is the only feedback.
- `PreviewService`'s SinglePayment coupon branch is gone. `validate()` only ever admits Recurring codes there, so it was unreachable — and it was the copy missing the clamp its live twin has.
- A `pending` overage charge whose webhook never arrived is finally somebody's problem. Both of `PrepareUsageOverageJob`'s passes skip a snapshot that carries a `payment_id` — right while the payment is in flight, permanent when the delivery is lost — so the charge was never collected, every renewal carried the negative balance into the new period (costing the customer that much quota for good), and every future overage for that billable was silenced. The new `CleanupStalePendingOverageChargeJob` (daily, 04:15 UTC) asks Mollie after 72 h: it replays a lost `paid` delivery through the webhook controller, writes off a terminal failure with an admin notification, writes off one still sitting at `open` after 14 days, and clears a snapshot whose payment turns out to have been invoiced already. Its two sibling markers have had a poll like this all along.
- An activation's post-invoice side effects are isolated from one another. Once the invoice exists a throw is terminal — the re-delivery answers `AlreadyBooked` and takes the quiet branch — so an app listener on `WalletCredited` that threw on the second usage type left the customer Active and paying with half their quota, the three-way country check never run, `SubscriptionCreated` never dispatched, and nobody told. Each step now logs its own failure at error level and the rest completes. The three sibling handlers have wrapped theirs all along.
- The dashboard's "Charge now" action re-checks that the subscription is still past due. The banner renders for `PastDue` plus a future Mollie start date and the page does not poll, so between render and click Mollie can charge and the webhook activate — and `forceImmediateCharge()` looks at neither status nor start date, so the click PATCHed `startDate` to today on an Active subscription and collected a second full period for one already paid.
- A seat change is clamped at both ends, against the new `mollie-billing.max_seats` (default 1000). The portal input's bounds are client-side only and `SyncSeats` clamped nothing but the lower one, so a mistyped order of magnitude reached `SubscriptionAmount::net()` and raised a prorata charge in the millions.
- Checkout offers an addon only in the intervals it is actually sold in, and drops a ticked one when the interval changes. Priced at 0 for an interval it does not define, a monthly-only addon rendered as "€0.00 / per year" — a free perk of the annual plan — and was refused only when the order reached `StartSubscriptionCheckout`, which `submit()` could report as nothing more useful than "payment could not be created".
- Cancelling a subscription voids an in-flight prorata charge while it still can be. A SEPA upgrade charge takes days to settle and needs no customer interaction, so cancelling minutes later left it armed: the handler correctly refused to apply the change, but the money had been collected from someone who left and an operator had to refund it by hand. `cancelPendingPlanChange()` already knew how to do this — it refuses to touch a settled payment and records an orphan marker plus an alert when a cancel is no longer possible.
- `totalBillingSpentGross()` takes a currency (default: the configured one). Unscoped it summed `amount_gross` across currencies, so a billable whose `BILLING_CURRENCY` changed mid-life reported 20.00 GBP + 10.00 EUR as "€30.00". The portal's own invoice card has scoped this all along.
- `SubscriptionCreated` fires once per activation. `CreateSubscription`/`ActivateLocalSubscription` end with it and three webhook activation bodies plus the coupon grant path fired it again a few lines later, so every paid signup wrote two `subscription_created` audit rows at the same second and ran every app listener twice — provisioning whatever they provision a second time.
- `CreateSubscription` passes the `mandate_id` its callers already hand it. Without it Mollie binds the subscription to "the customer's first valid mandate", which on a recovery checkout is the old one — and `MandateUpdated` has just queued `RevokeMollieMandateJob` for exactly that mandate. The first charge after the trial then failed against a revoked mandate and started dunning for a customer who had just supplied a working payment method.
- The plan-change preview accepts `addons` as a list of codes, not only as a `code => quantity` map. `UpdateSubscription` documents both shapes and the package's own addons screen passes the list — read as a map, its integer keys made every addon vanish from the quoted price, the line items and the intent: the coupon discount was quoted against the plan alone, and with another addon already active the composer emitted refund lines for one the customer was keeping.
- Redirects into the portal and the checkout finally say why. `RequirePlanFeature`, `RequireResolvedCountryMismatch` and `PromotionController` each flashed a message under a key of their own — `billing.status`, `billing_status`, `billing.promotion_status` — and no view read any of them, so a customer bounced off a gated feature, an unresolved country check or an expired promo link landed with no explanation at all. One key, rendered by both layouts, with localised messages.
- The admin "Extend trial" form is offered only for the statuses `extendBillingTrialUntil()` accepts, and catches its refusal. `trial_ends_at !== null` is true for anything that ever trialled, so the form rendered for Cancelled and Expired subscriptions and the exception left the operator with a 500 instead of the "resubscribe instead" guidance.
- `endTrial()` authorizes before it looks at the subscription. A 403 for "on trial" and a silent 200 for "not on trial" told an unauthorized team member the tenant's trial state, which the read gate otherwise withholds.
- The dashboard's usage grid uses an inline `grid-template-columns`. `lg:grid-cols-{{ n }}` is never compiled by Tailwind, so three usage meters silently fell back to two per row.
- `billing:sync-purchased-balance` replays the wallet's whole transaction history instead of summing a window. `resetAndCredit()` deliberately *carries* an unconsumed purchase forward by re-persisting `purchased_balance`, so a pack bought last period and still unused has no transaction in this one: the window found 0, the command wrote 0, and the next renewal withdrew the whole balance and credited only the plan quota — credits the customer had paid for, gone, behind a confirmation prompt that read exactly like the correction the command advertises. Rollover wallets were the mirror failure: their renewal writes `subscription_renewal_rollover`, which matched no anchor at all, leaving a lifetime sum that relabelled plan units as purchased.
- `billing:prepare-overage` asks before running and takes the lock the job declares. `dispatchSync()` bypasses `ShouldBeUnique` (it lives in `PendingDispatch`), so a hand-started run walked the table alongside the scheduled one and could issue two `CreatePaymentRequest`s for the same negative balance — two invoices, the quantity credited twice. The five passes also cancel Mollie subscriptions and expire subscriptions, which is not something a bare invocation should start: `--force` skips the prompt.
- `SyncSeatsJob` allows a single attempt, like `ApplyScheduledChangesJob`. It runs the same non-idempotent path — Mollie prorata payment plus a subscription PATCH inside a transaction — and in `byDelta()` mode a retry is worse than a repeat: the delta is re-resolved from the rolled-back row, so the second attempt adds *another* seat.
- `PrepareUsageOverageJob` and `ChargeUsageOverageDirectly` mutate `subscription_meta` under the row lock. Both wrote the whole JSON column from a model hydrated when its 200-row chunk was fetched — with up to 199 other billables and a synchronous Mollie call each in between — so a renewal webhook's `seat_count` was reverted, a cleared dunning episode resurrected, and a `pending_refund_retries` line brought back to be paid a second time. The `usage_overage_attempts` line in the catch block assigned the key to its own value and counted nothing; it is gone.
- `CleanupStalePendingProrataChangeJob` acts on a marker with no `created_at`. Defaulting it to `now()` meant it was never older than the threshold, so the recovery branch written for exactly that corruption could not be reached — while `ValidateSubscriptionChange` refused every money-moving plan change for as long as the marker was set.
- The Mollie subscription is cancelled only when the target configuration bills nothing at all. `isFreePlan()` looks at base and seat prices, so it says yes for a free plan that still carries a paid addon: removing a *different* addon cancelled the subscription at Mollie while `UpdateSubscription` — which asked `planChanged && isFreePlan()` — kept source Mollie, status Active and the subscription id. The customer kept their addon and was never billed again, and the next change patched a subscription that no longer existed. Both sides now ask the same question.
- `ResubscribeSubscription` recomputes a recurring coupon's discount through `CouponService`. Its private mirror read `discount_type`, `discount_value` and `base_amount_net` and skipped every gate: the coupon's `active` flag, the marker's `valid_until`, and the per-billable application count. The marker survives a cancellation and is only cleared on a renewal, which never happens during a grace period — so resubscribing re-applied campaigns that had closed or been switched off, Mollie collected the discounted amount, and the renewal handler then fired `PaymentAmountMismatch` on a payment nobody had asked to discount. `MollieSubscriptionPatcher` lost the same mirror earlier for the same reason.
- Four more `lockForUpdate()` reads bypass `BillingScope` (`UpdateSubscription` twice, `EnableAddon`, `DisableAddon`, `ScheduleSubscriptionChange`). Under an app-defined `applyBillingScope()` the scoped query can match nothing, and then it looks like it worked: `first()` returns null, no row is locked, `refresh()` still loads fresh data, and the read-modify-write proceeds unserialised.
- A recurring charge for a subscription that no longer runs is no longer booked as an ordinary renewal. Mollie keeps charging when a local cancellation never landed — `CancelSubscription` writes the Cancelled status even if `cancelAtMollie()` fails, and the retry job gives up after 24 hours — so every period advanced the anchor, reset and recharged every wallet, and mailed "your invoice is ready" to somebody who cancelled. The invoice is still written (the money arrived and needs a document to refund against), everything else is skipped, and the new `RenewalCollectedForEndedSubscription` event plus an admin notification say so. `failed()` had this state rule all along.
- A failed `refunds()` / `chargebacks()` lookup rethrows instead of answering 200. Both are live HTTP calls, and returning let the controller record the outcome signature as processed — so Mollie never called again: no credit note, `refunded_net` at 0, the refund's VAT declared in the OSS export, and for a chargeback a customer still subscribed on money the bank pulled back. Both handlers dedup on the refund id, so a re-delivery is safe.
- A mandate-only activation refused because of the subscription's state now reports instead of logging at info. `Active` with a past `subscription_ends_at` — what a lapsed AccessGrant leaves behind — passed the gate but was missing from `ACTIVATABLE_STATES`, so the €0 mandate payment settled, the mandate was saved, and nothing else happened: no subscription, no invoice, a return page spinning to a timeout. The customer's second attempt then had a mandate, skipped the trial branch, and charged full price for the trial they were offered.
- Checkout adopts the billable it resolved even when that billable has no saved address yet. Identity and prefill were gated on one predicate, so an authorized org without a billing address (a fresh signup, or one whose access grant just lapsed) fell through to `createBillable()`: the customer paid, a *second* organisation was created to hold the subscription, and their own one stayed unsubscribed — bounced back to checkout and eligible for orphan cleanup.
- The portal's billing-address and plan-change forms write the company name through `setBillingName()`. Writing the `name` column directly clobbered the personal name of a `User`-as-billable while leaving the billing name untouched, so the save read as a no-op and corrupted an unrelated column at once.
- The return page recognises an anonymous signup's own checkout. `authorizes()` fails closed when no owner exists yet — which is why the route is deliberately outside `billing.portal` — but the component applied the same gate, so a paying customer polled for 90 seconds and was then offered a Logout button. It now honours a session stamp written at submit time, and only when the request does not name a different billable.
- The billing-data page asks `VatCalculationService` whether a supply is reverse-charged. Its local copy of the rule omitted the seller-country test, so a domestic business customer was shown a "Reverse charge" badge on the page that explains their VAT treatment while every invoice carried full domestic VAT.
- Dashboard addon labels come from the catalog. Reading `mollie-billing-plans.addons` directly skipped both the `billing::addons.*` translations and any rebound catalog, making the dashboard the one screen that showed raw codes.
- A refund whose Mollie response was lost is replayed on retry instead of paid out again. The idempotency key ended in fresh randomness on every attempt — which stopped Mollie rejecting two legitimately identical refunds as duplicates, and made the retry of a created-but-unacknowledged refund a second payout. The token is now minted once per refund line and carried through `pending_refund_retries`.
- `createRefund()`'s `try` covers the Mollie call and nothing else. It used to wrap the bookkeeping too, so a failing `refunded_net` write — a lock-wait timeout against a concurrent admin refund is enough — filed the line as both persisted *and* failed: the credit note was written and a retry queued for money Mollie had already returned.
- The refundable base is `effectiveRefundedNet()`, not the raw column. A credit note whose cache write was lost is still a refund that happened; reading the column alone offered the whole invoice as refundable again.
- `InvoiceRefunded` and the refund notification fire after the commit. The money has left Mollie by then, so a throwing app listener rolled back the credit note and the `refunded_net` increment while the customer kept the refund — and the admin's retry click paid it a second time.
- `regeneratePdf()` renders before it deletes. It nulled the pointer and removed the blob first, and `generateAndStorePdf()` swallows every failure, so an unknown currency code or an S3 hiccup left the invoice with no document and no copy anywhere. The path is derived from serial number and date, so the new render simply overwrites the old file.
- The invoice download route regenerates a PDF whose pointer is null, not just one whose blob went missing. A failed render 404ed the customer's own paid invoice forever.
- The past-due reset is one predicate in `BillingPolicy`, shared by the composer, `buildContext()` and the preview. The composer's copy was missing the plan/interval condition, so a past-due customer adding one seat was charged the full list price of plan plus seats plus addons — €34.00 for a €3.55 change, quoted at €3.55 — and Mollie's schedule was left untouched, so the same period was collected again a fortnight later.
- The plan-change preview quotes the usage overage an interval switch will charge. Included quotas are keyed by (plan, interval), so an interval move changes the quota as much as a plan move; the validator and the wallet adjuster both knew that and the preview did not, promising €0.00 for usage the change then billed.
- The OSS export calls a domestic business customer `domestic`, not `reverse_charge`. A validated VAT number alone was enough for the reverse-charge label, but `reverseChargeApplies()` — the rule that priced the invoice — also requires the countries to differ. Domestic turnover and its collected VAT were routed into the EC Sales List and out of the national return, on a line the list says is zero-rated.
- A plan change onto a (plan, interval) the catalog does not sell is refused instead of priced at zero. `basePriceNet()` answers `0` for an unknown pair, which made `isFreePlan()` true and took the change into the free-downgrade branch: the Mollie subscription was cancelled and a real refund issued before the enum cast rejected the interval and rolled the local transaction back. Checkout has guarded this all along; `update()` and `schedule()` now do too, for addons as well as the plan.
- A scheduled change that the current seat usage blocks is kept and announced instead of re-thrown. `ApplyScheduledChangesJob` allows a single attempt, so it failed every night for as long as the customer kept the members, while they carried on paying the old price. The admin branch that was supposed to tell the operators was an empty `if` with an "out of scope for this phase" comment; it now sends `AdminPlanChangeFailedNotification`.
- A scheduled change stores the addon payload the target plan can actually host. `apply()` re-filtered it, so the money was always right — but the portal and admin panel render the stored payload, and it promised an addon that silently disappeared at period end.
- `ScheduleSubscriptionChange` takes its row lock scope-free, like every other lock read in the package. With an app-defined `applyBillingScope()` the scoped query can match nothing, and then it looks like it worked: no row is locked, `refresh()` still loads fresh data, and the write runs unserialised.
- Admin dashboard MRR/ARR normalise a yearly invoice to a twelfth. `estimatedMonthlyNormalised()` divided by `max(1, X ? 1 : 1)` — a tautology that always meant "divide by one" — so one yearly customer at €1,200 reported €1,200 MRR and €14,400 ARR. Prorata charges are never scaled up.
- Trial conversion counts one cohort instead of two overlapping sets. "Has a mandate" against "is Expired" put a customer who converted and cancelled later in both, reading as 50% for a single converted trial, while an unpaid trial parked in `PastDue` appeared in neither and lifted the rate.
- `AdminKpiService::mrr()` answers 0 rather than raising when there is no invoice table. The `safely()` fallback was omitted on a closure typed `int`, so the safety net itself became a `TypeError` in exactly the boot-time state it exists for.
- The open-overage KPI sweeps with `chunkById`. `chunk()` paginates an unordered query with LIMIT/OFFSET, so a row could be summed twice or skipped.
- A coupon's `discount_value` must be greater than zero, for both discount types. Only Percentage > 100 was rejected, so a Fixed `-500` was accepted and the pricing services subtract it unexamined — the "discount" *increased* the charge by €5.
- The products page offers `single_payment` coupons only. It listed `recurring` in its `allowed_types` while `StartOneTimeOrderCheckout` accepts neither, so a customer's code was accepted at the field and the purchase it was entered for then failed.
- `checkout.blade.php::submit()` serialises per billable and returns the customer to a first payment that is still open at Mollie instead of minting a second. `$processing` is component state, so two `/livewire/update` POSTs replaying their own snapshots each created a `sequenceType: first` payment; `recordPendingFirstPayment()` keeps only the last id, so the return page polled the payment the customer did *not* pay, and the other settling later was caught only by the activation gate — as a duplicate, after the money had moved.
- `SyncPurchasedBalanceCommand` anchors its "purchases this period" window on the latest `period_reset` **or** `period_credit` transaction. `resetAndCredit()` only writes a `period_reset` for a non-zero balance, so a wallet that renewed at exactly 0 had no anchor: the window reached into earlier periods and resurrected purchased credits the customer had long consumed, which every later reset re-credited on top of the new quota. The renewal's own quota deposit now carries its own reason (`period_credit`) instead of the generic `credit`, which `credit()` also writes.
- The OSS export marks each row with the filing it belongs to (`oss`, `domestic`, `reverse_charge`, `non_eu`) in a trailing `scope` column, and keeps them in separate buckets. Reverse-charged B2B supplies (EC Sales List) and seller-country domestic sales (national return) were aggregated into the OSS buckets unmarked, so a CSV filed verbatim over-declared OSS turnover. Nothing is dropped — turnover that vanishes from a report is harder to notice than turnover in a column that says what it is.
- Invoice serial numbers take their year from UTC rather than `date('y')`. A serial minted in the hours around New Year on a non-UTC server could carry the neighbouring year's digits while `created_at` — which the OSS export and the PDF both read — said otherwise. Sequence integrity was never affected.
- The admin refund field, the OSS export and `LifecycleSimulator` scale amounts by the currency instead of a hardcoded 100. On a JPY install an operator typing `500` to refund ¥500 posted a **¥50 000** refund — a 100× over-refund of real money — and every honest entry below ¥50 000 was then rejected as "exceeds remaining"; KWD was wrong by 10× in the other direction. The OSS export, which is a tax filing, wrote ¥3000 as `30.00` in a row whose own column declares the currency. The parser also composes minor units from the digits rather than multiplying a float, so `0,07` is 7 and not 7.000000000000001.

- A trial that expires without a payment method is stamped with `past_due_since`, so it keeps walking `past_due → cancelled → expired`. `PrepareUsageOverageJob`'s auto-cancel pass keys off that stamp alone, so these rows were parked in `past_due` forever: `hasPlanFeature()`, `@planFeature` and the `billing.feature` middleware kept answering from the retained plan code for a customer who never paid, and since the row stays Local with a cycling `nextBillingDate()`, the job's local-quota pass handed it a fresh month of consumable quota on every anniversary. That pass now also skips `past_due` — a past-due local subscription is not renewing.
- A change scheduled on a **trial** anchors on the earlier of `nextBillingDate()` and `trial_ends_at`. The first charge lands on the trial end (day 14), not the period anniversary (day 30), so the change was applied in the middle of the first paid period as an `internal` immediate update — which waives the lapsed-period guard and raised the very prorata charge the customer scheduled the change to avoid.
- `ScheduleSubscriptionChange::schedule()` refuses states `apply()` will not act on. A cancelled-in-grace customer was shown "scheduled for &lt;date&gt;" for a change the daily dispatcher then silently discarded.
- `extendBillingTrialUntil()` only promotes to `trial` from states where a trial can run (`new`, `trial`, `active`, `past_due`). The flip grants unconditional access, so an admin trial extension on a `cancelled` or `expired` row resurrected an ended subscription with no Mollie subscription behind it (`expired` even has its plan code wiped, so the customer landed in a portal gated open on a plan that no longer exists). Promoting out of `past_due` now clears the dunning episode, so the extension is not undone the moment the new trial lapses.
- A coupon's scope filters (`applicable_plans`, `applicable_intervals`, `applicable_addons`, `applicable_products`) are positive requirements rather than conditionals that only run when the caller happens to name that dimension. The entry points do not overlap — subscription checkout passes no `productCodes`, a product purchase passes no `planCode` — so a coupon restricted to a €10 token pack took 50 % off a €500/yr signup, and a plan-restricted coupon discounted any product. Both from customer-facing coupon fields.
- A fully coupon-covered one-time order writes its invoice and its redemptions in one transaction, and a refused redemption aborts the order instead of being logged. On the inline path the coupon *is* the consideration, so two tabs on a `max_redemptions_per_billable = 1` coupon both passed the lock-free `validate()`, both wrote a 0-EUR invoice, one won the redemption lock and the other's exception was swallowed — two token packs delivered against one redemption on record. The Mollie-paid path deliberately still logs: there the money cleared.
- The Local→Mollie upgrade charged a gross rounded over the summed net while the webhook books the same lines with per-line VAT, so collected and booked disagreed by a cent or two on any multi-line upgrade (12.9 % of two-line price combinations, ~0.5 % of four-line ones exceeded the 1-cent slack and fired `PaymentAmountMismatch` on a legitimate payment). A later `refundFully()` then asked Mollie for more than the payment held and was rejected, leaving the invoice impossible to close out. `PreviewService` now derives `grossTotal`/`vatAmount` from `MollieSubscriptionPatcher::recurringLineNets()`, the declared authority for that breakdown.
- The Local→Mollie upgrade is an activation path and now carries the same two-phase activation gate as the other two (row lock → invoice guard → duplicate guard → durable claim). It had only the per-payment invoice guard, which cannot see a *second* upgrade payment: two tabs produced two paid payments, the second created a second Mollie subscription whose id overwrote the first in `subscription_meta` — leaving a live subscription charging the customer every period with nothing locally pointing at it. No concurrency was required.
- The activation gates take their row lock scope-free. `newQuery()` applies the app's `BillingScope`, but the webhook deliberately resolves billables without it — so for a scoped-out billable the locking `first()` matched nothing and **no lock was taken at all**, silently reopening the double-subscription race the gate exists to prevent. A missing row is now an error rather than a no-op.
- An overage invoice is booked at the VAT rate the charge was computed with, and reconciled against the amount that cleared. It was the one paid path that skipped reconciliation: the metadata carries nets only and `createForPayment()` recomputes VAT from the billable's country *now*, so a customer whose classification changed while the SEPA payment settled (days) got a 10.00 + 19 % = 11.90 charge invoiced as 12.00 (AT 20 %) or 10.00 (zero-rated) — money either over- or under-declared on the document, with `refunded_net` headroom that Mollie cannot honour, and nothing fired. The rate `ChargeUsageOverageDirectly` already snapshots is now pinned onto the lines, and where the country lookup forces zero-rating the difference lands as an adjustment line.
- Every webhook meta mutation goes through `MutatesBillingMeta` (re-read under a lock, merge only the keys the handler owns) instead of writing the handler's whole snapshot back. The overage handler and the renewal handler can run in the same second — the overage charge settles the day before the renewal *by design* — and a blob write reverted whatever committed beside it: the `usage_overage*` keys came back from the dead and `PrepareUsageOverageJob` then skipped that billable's overage collection indefinitely. (The renewal-side variant was not reproducible in-process, because `WalletUsageService` happens to re-read the billable mid-handler; the change removes the dependency on that accident.)
- `TrialConverted` fires after the conversion is saved. Firing it first meant a throwing app listener — events are the documented extension seam — left the billable on status `Trial` with a past `trial_ends_at`, which `hasAccessibleBillingSubscription()` refuses: a customer who had just paid was locked out for a whole period and the period anchor was never advanced, with the invoice guard preventing the re-delivery from repairing it.
- The one-time-order wallet credit and the Local→Mollie upgrade's wallet adjust are wrapped like their siblings. Both sit after the invoice exists, so an uncaught throw was unrecoverable — the re-delivery exits at the invoice guard — and the customer had paid for credits that were never granted. True durability here would need an idempotent completion marker rather than the invoice-existence short-circuit; that is a follow-up.
- A refund that has to be reduced now books the reduced amount. The clamp against "what Mollie can still return" reached the API call only: the credit note — a tax document — stated the uncapped figure while less money moved, `refunded_net` could exceed `amount_net`, and every later refund decision read an inflated base. The line is rebuilt at the capped amount with a `refund_cap_note`, the way `ProrataComposer` already marks a line it had to cap.
- A credit note's dedup key is stamped before the row is committed. It was written in a second save, after the row existed and after the synchronous `CreditNoteIssued` event had run — a throwing app listener or a DB blip in that window meant the re-delivery found no stamp and wrote a **second** credit note for the same refund, double-booking `refunded_net`, under-declaring VAT and causing later legitimate refunds to be refused as "exceeds remaining".
- **Chargebacks are booked.** They were entirely invisible: Mollie reports a chargeback on the same payment and in the v2 API the status stays `paid`, so the dedup signature was byte-identical to the paid delivery already on record and the delivery was answered 200 and forgotten — the money was gone while the invoice stayed fully paid (output VAT and the OSS return over-declared by that amount), no credit note existed, and the customer kept access indefinitely. For SEPA this is routine: the payer has eight weeks and needs no reason. The charged-back total is now its own component of the outcome signature, and `ChargebackHandler` issues a credit note per chargeback id (deduplicated on it, like refunds on the refund id), puts the subscription into `past_due` so the existing dunning ladder takes over, and notifies admins. The Mollie subscription is deliberately not cancelled here — see [docs/refund-management.md](docs/refund-management.md#chargebacks).
- A fresh subscription starts a fresh dunning clock. `past_due_since` survived a cancellation, so months later **one** failed renewal was measured against the old date and the nightly pass auto-cancelled the same night — a zero-day dunning window, before Mollie had even retried the payment. The `subscription_meta` keys each transition must drop now live in one place (`SubscriptionMetaHygiene`); four transitions had been maintaining their own lists and they had drifted.
- A downgrade to a free plan performs the same hygiene as `ActivateLocalSubscription`. It cleared three keys where its sibling cleared ten, and wrote no status: a **past-due account became unrecoverable** (source flipped to Local, status stayed PastDue, access refused, and no renewal will ever arrive to clear it), a surviving `scheduled_change` made the daily dispatcher fail every night forever, and a surviving `next_charge_date_override` deferred the free plan's quota recharge.
- A scheduled change whose own validation refuses it permanently is dropped instead of retried nightly. `apply()` re-threw without clearing `scheduled_change_at`, so `ApplyScheduledChangesJob` failed on that billable every day indefinitely. Transient failures still propagate to the queue's retry.
- A failed Mollie cancel during a free downgrade no longer orphans a live subscription. `cancelForFreeDowngrade()` swallowed every failure while the caller dropped `mollie_subscription_id` regardless — so with Mollie unreachable for that one call the subscription stayed active, charged the old plan's price every period against a now-free billable, and nothing could target it any more. It now reports its outcome, treats 404/410 as done, and on any other failure keeps the id, writes `pending_subscription_cancel`, dispatches `RetrySubscriptionCancelJob` and notifies admins.
- A renewal no longer forgives an overage debt whose charge is still settling. For non-rollover quotas the period reset zeroed the negative balance, and the later settlement credited the charged quantity on top — so every overage cycle handed the customer that quantity as free units. With SEPA the renewal regularly settles first, because Mollie creates it about two days ahead while the overage charge is created the day before it. `resetAndCredit()` takes a `preserveDebt` flag and the renewal passes it per usage type, read off the pending-charge snapshot.
- A backing-off subscription-PATCH retry no longer overwrites a newer plan. Nothing cleared its marker when a later change PATCHed Mollie successfully, and `updateForIntent()` prices from the stored intent — so the retry put Mollie back on the superseded plan and then cleared its own marker: local plan C, Mollie collecting B's price every period, nothing left for the hourly sweep. Since the renewal handler reconciles each invoice down to whatever cleared, it never self-healed. The retry now compares the intent against live state and drops a superseded one (seats only when the row states them explicitly, so a legitimate retry is never skipped).
- A spent overage dunning cycle no longer bills the next one's first failure. `giveUp()` keeps the snapshot for auditability and the next cycle inherited its `created_at`, so the cycle key still matched and `usage_overage_counted_payments` still listed the three payments that had already failed: the new period's first failure exceeded the budget and went straight to past_due with customer and admin mail, no retry ladder. A snapshot whose status is `failed` now starts a fresh cycle; within one cycle the inheritance is unchanged.
- The past-due auto-cancel actually cancels at Mollie. It force-filled the status and dispatched the event — the comment claimed parity with `CancelSubscription`, but the Mollie cancel, the `pending_subscription_cancel` retry marker and the `scheduled_change` wipe were all missing. A yearly subscriber whose overage dunning gave up was cancelled locally, expired by the next pass, and then charged the full yearly price at the anniversary, booking an invoice for a billable with no access. It now delegates to `CancelSubscription`.
- `RetryUsageOverageChargeJob` has a timeout and a per-billable overlap guard. It is the one job that creates auto-collected payments, and `ShouldBeUnique` guards dispatches rather than re-deliveries: a hung `CreatePaymentRequest` outlived the queue's visibility window, a second worker found the charge still unsettled, and both collected — two invoices and the quantity credited twice.
- The Mollie subscription PATCH asks `CouponService` whether a recurring marker is still redeemable, instead of only whether it exists. A coupon an admin had deactivated (the documented way to resolve a `recurring_conflict`) or one whose window had closed but whose renewal had not run yet was re-applied to Mollie by any seat, addon or plan PATCH, so Mollie collected the discount for another period and `PaymentAmountMismatch` fired on a payment nobody meant to discount.
- A promotion-link coupon is no longer consumed for a full-price charge. `PromotionController` parks the code in the session, and it was spliced into the payment metadata regardless — while `amount_gross` came from a form that knew nothing about it. The customer paid in full, the webhook priced the coupon, `reconcileWithPaidAmount` cancelled the discount line back out (firing `PaymentAmountMismatch` on a good payment), and `redeem()` burned their per-billable slot and the campaign counter for a discount never received. The checkout now pre-fills the session coupon (the docs claimed this existed; it did not), and the service drops a session coupon the submitted amount does not account for on any path that collects money — trial and other €0 checkouts keep it, since nothing is collected there.
- At most one `recurring` coupon per apply-set. `active_recurring_coupon` is a single slot, so a second recurring code overwrote the first: the preview and the prorata charge honoured both discounts, then every renewal honoured only the last one while both per-billable slots had been consumed. Rejected with `recurring_conflict`. Stacking several `single_payment` codes on a one-time order is unaffected.
- The recurring marker's lifetime is computed in the interval the discount will actually run at. `UpdateSubscription` redeems before it persists a changed interval, so a yearly→monthly switch measured a "2 periods" coupon in years: 24 discounted monthly renewals instead of 2 (and the reverse direction truncated it to 61 days, so the first yearly renewal got nothing).
- The marker also stops after the promised number of **applications**, not just at its date. `max × 30 (+1)` days is shorter than the calendar wherever February falls, so a 3-period coupon's fourth charge could land inside the window — four discounted periods and a fourth redemption row that looked deliberate. The date stays generous because `CreateSubscription` derives the 100 %-coverage deferral from it; only redemptions carrying a discount count, so a zero-discount grant row does not eat an application.
- A recurring coupon on a **trial** signup now increments `redemptions_count`. The trial path wrote only the marker, and every later charge takes the renewal pipeline, which skips the counter by design — so no code path ever counted the grant: `redemptions_count` stayed 0 forever, `globally_exhausted` never tripped, and a 50-redemption campaign could be claimed by any number of trial signups. (Per-billable limits still worked; those count rows.)
- A webhook reservation abandoned by a dying process no longer swallows every later delivery of that payment. Nothing unwinds the reservation on an OOM or `max_execution_time` fatal, a SIGKILL on deploy, or a failure of the `delete()` in the controller's own catch — and because a non-created row meant "skip", the retry was answered 200 and Mollie stopped calling: a first payment was never activated (money collected, no subscription, no invoice) and a renewal never booked, for the 180 days until the prune job removed the row. `reserve()` now takes over a pending row older than `RESERVATION_TTL_MINUTES` (15), mirroring the activation claim's TTL.
- A delivery skipped because another is in flight answers **503** instead of 200. Mollie re-calls the same payment when a refund is created — including the country-mismatch flow, which refunds the very payment being processed — so the holder, which fetched the payment before that refund existed, recorded `…:paid` and the 200 told Mollie the refund delivery had been handled. `refunded_net` stayed 0 and the books over-stated revenue. Safe now that a leaked reservation cannot hold forever.
- A re-delivery that finds this payment's invoice but no finished activation is reported instead of logged away. "Invoice exists" only means "nothing left to do" when the crash happened *after* `CreateSubscription`; between the invoice commit and that call — where `InvoiceCreated` runs synchronous app listeners — it left a paid invoice, a saved mandate, status `New`, no Mollie subscription and nobody informed, and `CleanupOrphanedBillablesJob` refuses to touch a billable holding an invoice. All three activation gates now check `activationCompleted()` and fire `SubscriptionActivationFailed` plus an admin notification.
- A failed mandate-only invoice creation is rethrown rather than returned. Returning let the controller record the outcome as processed and answer 200, so Mollie never re-delivered and a transient failure permanently ended a signup that had already captured a mandate — with no cleanup job to catch it. The trial branch's `CreateSubscription` failure now notifies admins too: it can throw after the Mollie call but before the local save, leaving a live subscription with no local record.
- A same-interval plan change no longer restarts the billing period when its deferred charge settles. The composer prices such a change with the remaining-window factor (covering only up to the existing period end) and the patcher leaves Mollie's cadence alone, so re-anchoring the local period invented a window nobody paid for: `nextBillingDate()` named a date Mollie does not charge on, the overage pre-pass missed the real renewal, a graceful cancel granted grace that was not paid for, and a seat added afterwards was prorated against the phantom window while the real renewal billed the same days again. Interval changes and the past-due reset still start a new period; the wallet adjuster still runs for a plain plan change.
- A mid-trial plan change within the same interval no longer charges the trial-end days twice. Mollie's `startDate` is the trial end, and the deferred charge paid for the rest of the local period — so an amount-only PATCH left the first recurring charge on the old trial-end date and collected a full period for days just paid for, roughly 1.8× the monthly price in the customer's first ten days. Phase 2 now forces the schedule reset when it converts a trial.
- A settled prorata charge is no longer applied to a cancelled or expired subscription. `CancelSubscription` clears `scheduled_change` but not the deferred-prorata markers, so a SEPA charge started before a cancellation settled days later and force-filled plan, interval and addons back onto an expired row — resurrecting the plan-scoped fields the expiry wipe removes, so `@planFeature` and the `billing.feature` middleware granted access again. The charge is now reported for a manual refund instead, and the pending state is consumed.
- A failed Mollie PATCH in prorata Phase 2 writes `pending_subscription_patch` and dispatches `RetrySubscriptionPatchJob` — the fourth marker site the earlier "a failed PATCH now actually dispatches the retry" fix missed. A bare log meant nothing retried and the pending state was cleared right after, so Mollie kept charging the old amount for the rest of the subscription's life while the customer had the new plan; the renewal handler reconciles each invoice down to the amount that cleared, so it never self-healed.
- The "one pending change at a time" rule now covers credit-only changes. It sat behind an early return at `prorataChargeNet <= 0`, so a downgrade issued while another change's charge was in flight passed unchallenged, was keyed to that other change's payment id, and evaporated when the payment settled — no plan switch, no credit refund, no failure event, and any coupon stacked on it redeemed against the wrong invoice.
- An unusable stored plan-change intent in Phase 2 is logged and the pending state cleared, instead of escaping as a 500 that makes Mollie re-deliver a bug forever.
- **Security:** the checkout rendered a foreign account's billing address and VAT number. `resolveBillable()` falls back, on the package's own routes, to matching any unreserved query parameter against a billable's route key — so `?org=<key>` resolved a stranger, and `mount()` copied their company name, street, postal code, city, country and VAT number onto the component and showed them read-only on the confirm step. Reads were the one half of that component which never asked `authorizes()`. Identity and data are now separated: the `($billableId, $billableClass)` pair is kept so `submit()` still answers 403 (rather than silently creating a second billable for a team member who is not the billing manager), while none of the fields are adopted; a query-parameter guess is dropped entirely. The same gate was added to the return page and the usage-meter component.
- `submit()` authorizes before it validates. With the pre-fill gated, an unauthorized requester's form is empty, so validation would have refused first and answered with field errors about a stranger's checkout.
- `MollieBilling::resolveBillableFromCallback()` exposes just the app's own resolver result, without the query-parameter fallback — the two answers mean different things when authorization refuses.
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

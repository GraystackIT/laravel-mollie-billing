# Browser testing

The package ships a Chrome end-to-end suite that drives the real UI — checkout,
customer portal and admin panel — against a fake Mollie. It tests the package
**functionally and visually**: every step drops a screenshot, and those screenshots
become a browsable gallery.

It is deliberately **not** part of the default test run. `./vendor/bin/pest` stays
fast and Chrome-free; the browser suite has its own config.

```bash
composer browser:driver     # once: install the ChromeDriver matching your Chrome
composer browser:assets     # once (and after view changes): npm install && vite build
composer test:browser       # run the suite
composer browser:gallery    # build screenshots/index.html
open tests/Browser/screenshots/index.html
```

> **`composer install` now needs Flux Pro credentials.** `livewire/flux-pro` is a
> `require-dev` dependency (from the `composer.fluxui.dev` repository declared in
> `composer.json`), because there is no way to render a portal screen without it.
> Put your licence in an `auth.json` next to `composer.json` — it is gitignored:
>
> ```json
> { "http-basic": { "composer.fluxui.dev": { "username": "you@example.com", "password": "YOUR-LICENCE-KEY" } } }
> ```
>
> Without it, `composer install` fails on that package. `composer install --no-dev`
> works but leaves you unable to run any test suite.
>
> **Without a licence at all — CI, or a contributor who only wants the feature suite —
> remove both halves and install normally:**
>
> ```bash
> composer remove --dev livewire/flux-pro --no-update
> composer config --unset repositories.flux-pro
> composer update
> ```
>
> Both, not just the dependency: Composer loads every configured repository's package
> list *before* it resolves anything, so leaving `composer.fluxui.dev` behind fails the
> install with a 401 before a single package is considered. That is what `.github/workflows/tests.yml`
> does, and the feature suite then passes in full (1780 cases at the time of writing) with
> Flux absent — measured, not assumed. Only `tests/Browser` needs it, and that suite has
> its own `phpunit.dusk.xml`.

## Why this exists alongside the feature suite

The feature suite runs on Orchestra Testbench with an in-memory SQLite database and
**no Flux Pro** — Flux is a hard runtime dependency that ships under `suggest`
because it is commercial, so it cannot be installed in a package's default test
environment. That means the feature suite can never render a portal screen, and
every action that ends in a `\Flux::toast()` was historically unreachable by it.

The browser suite installs Flux Pro as a dev dependency and serves a real
application. It is the only place where "does the plan-change screen actually
price this correctly" is a question with an answer.

## How it is wired

```
testbench.yaml                     providers + workbench config for BOTH processes
workbench/app/Providers/           WorkbenchServiceProvider — the app under test
workbench/app/FakeMollie/          Mollie, answered from SQLite
workbench/routes/web.php           /_wb/* levers + /fake-mollie/* hosted page
workbench/database/migrations/     fake-Mollie state + cross-process settings
resources/css/app.css              the reference stylesheet (also the build input)
tests/Browser/BrowserTestCase.php  base class: driver, migrations, screenshots
tests/Browser/InteractsWithWorkbench.php  the suite's levers and helpers
tests/Browser/gallery.php          manifest.jsonl → index.html
```

**Two processes, one world.** Dusk starts an HTTP server in a separate PHP process.
Both it and the test process load `WorkbenchServiceProvider`, which points them at
one **file-backed** SQLite database, one storage root and one cache directory. That
is what lets a test create a billable, flip a setting or deliver a webhook and have
the browser see it. In-memory SQLite would give the two processes two different
databases.

That connection sets a **10s `busy_timeout`**. PDO's default is 0, so the moment the two
processes collided — a webhook writing an invoice while the browser's own request read the
dashboard — the run failed with `database is locked` rather than waiting. The timeout makes
them queue instead.

Do **not** add `journal_mode = wal` here, however appealing WAL's reader/writer overlap
sounds. Laravel's SQLite connector issues that pragma on every connect and switching the
mode needs an exclusive lock, so the two processes then collide on the pragma itself: a
full run went from 2 failures to 45. (And if you try it anyway, delete
`workbench/database/workbench.sqlite` afterwards — SQLite reports `database disk image is
malformed` when the mode changes under an existing file.)

**Mollie is faked at the SDK's own seam.** `FakeMollieHttpAdapter` implements
`Mollie\Api\Contracts\HttpAdapterContract` — the same interface the SDK's own
`MockMollieHttpAdapter` uses. Every typed request object the package builds, every
response hydration, every `$payment->getCheckoutUrl()` runs the real SDK code path;
only the wire is fake. State lives in SQLite so both processes act on one Mollie.

**The hosted payment page is real.** `/fake-mollie/checkout/{payment}` is a page the
browser genuinely navigates to, with pay / fail / cancel / expire buttons and a card
country selector that feeds the three-way VAT check. Settling it delivers the legacy
webhook **before** redirecting, which is production's ordering: Mollie calls the
webhook independently of the customer coming back.

**What the fake Mollie does beyond create-and-settle**, because a scenario depends on it:
an open payment can be cancelled (`CancelPaymentRequest`, what `PendingFirstPayment` sends
when a customer comes back from the hosted page and confirms another order) exactly when it
reports `isCancelable: true` — the `payments_cancelable` lever below — and is refused with 422
"The payment cannot be cancelled" otherwise, as Mollie refuses every open first payment
(measured 26 November 2026; `refuse_cancel_payment` refuses even a cancelable one); a
subscription PATCH takes `mandateId` (the payment-method change's rebinding); and revoking a
mandate cancels every subscription still bound to it and makes the mandate read 410 Gone, as
measured on 26 November 2026 — which makes a subscription left on a revoked mandate visible
instead of renewing quietly. `fail_create_subscription` makes every
`CreateSubscriptionRequest` throw, for the activation-retry scenarios; `fail_cancel_subscription`
answers every `CancelSubscriptionRequest` with a 503 and cancels nothing (counted in
`failed_cancel_subscriptions`), which is what leaves a `pending_subscription_cancel` behind. `lose_create_payment_answer`
(a metadata key, e.g. `undercollection`) makes a `CreatePaymentRequest` whose metadata carries that
key create the payment and then throw — the answer lost on its way back, which a caller cannot
tell from a refusal; `lost_create_payment_answers` counts how often it fired.

**The fake honours idempotency keys by default**, the way the sandbox measured the real API
(`tests/Sandbox/MollieIdempotencyContractTest.php`, `MollieRequestContractTest`,
`MollieChargedSubscriptionContractTest`, `MollieSettledPaymentContractTest`; re-measured on
26 November 2026): a POST or DELETE whose key was seen within the hour (on the shared clock) is
answered with the first request's response as it was then (`idempotency_replays` counts them);
the same key on another URL or with other parameters is refused with a 400 ("…used before on a
different URL…" / "…used before with different parameters…", both counted in
`idempotency_conflicts`). A PATCH is not keyed — Mollie applies a repeat again. A refusal (4xx)
binds its key like an answer, so a retry after a refusal lever (`refuse_refund`, …) is refused
again even with the lever off — use a fresh key; an outage (5xx) binds nothing, and a lost
create-payment answer (`lose_create_payment_answer`) binds it, because Mollie acted. Each key it remembers is a
`workbench_settings` row `idem.<sha1>` naming the key, the request, the resource it created
(`payment_id` for a payment) and the response, so a test can read which key created what. Every
mutating request carries a key — the caller's (`MollieCall::send($request, $key)`) or the SDK's
random one — so `idempotency_replays` stays 0 unless a key really was sent twice.
`honour_idempotency_keys` = `0` switches it off. It used to be opt-in and for create-payment only,
switched on by `UsageDeepTest` alone — a dunning retry that reused its predecessor's key looked
like a new payment to a fake that ignored keys, and the real API replays the old one.
`FakeMollieIdempotencyTest` pins the rule on its own. The feature suite's stateful facade doubles
(`ChangeMatrixWorld`, `BookingMatrix`, `RenewalLongRun`) apply the same rule through
`tests/Support/MollieIdempotencyMemory.php` (`->honour = false` switches it off there);
`UsageLongRunHarness` models it for create-payment on its own.

`payments_cancelable` makes the fake report `isCancelable: true` for an open payment, and only
then does it cancel one (by default it reports false — what Mollie reports for every open first
payment, measured; a pending recurring direct debit is cancelable at Mollie, which the fake's
card mandates do not model), so a cancel of a change in flight can actually cancel its charge at
Mollie. `hold_mollie_request` (a request's base class name, e.g.
`CreatePaymentRequest`) makes the first such request **in the Dusk server** announce itself in
`mollie_request_held` and wait — at most 30 seconds — until the test forgets the setting:
whatever the server holds across that call, such as a portal change's
`billing:subscription-change` lock, is held while the test does something else.

`refuse_refund` makes every `CreatePaymentRefundRequest` answer 422 "The specified amount cannot
be refunded" (field `amount.value`), as Mollie does for an amount above what is left (measured
26 November 2026; `refused_refunds` counts them). `FakeMollie::chargeback($paymentId)` puts a
chargeback on a paid payment — the status stays `paid`, `amountChargedBack` and the payment's
chargeback list change, as in the API reference — and `FakeMollie::reverseChargeback($id)` sets
its `reversedAt`. After a reversal the fake keeps `amountChargedBack` as it was: what Mollie does
there is not measured, and an unchanged amount is the harder case for the webhook's dedup.

`count_get_payments` = 1 makes the fake count every `GetPaymentRequest` per payment, in the
setting `get_payment_count.<payment id>` — for a scenario whose point is that a page did *not*
ask Mollie about a payment (the return page opened for another organization).

**A mailbox.** Mail goes to the log, and a notification the Dusk server sends (an operator's
refund in the admin panel) is out of reach of `Notification::fake()` in the test process. With
the setting `mailbox` = 1 every notification either process sends is written to
`workbench_mailbox` (class, channel, recipient). `admin_mailbox` (an address) gives the server an
operator for `MollieBilling::notifyAdmin()`, which otherwise answers nobody there;
`billing_admins_mailbox` = 1 makes the billable's own address its billing admin
(`notifyBillingAdmins()`), so the customer's mails from a portal action can be counted.

`GET /_wb/feature/softdrinks` is a page of "the application" behind `billing.feature:softdrinks`
(Softdrinks is an addon's only feature), for asserting what the feature gate lets through.
Laravel's `jobs` and `failed_jobs` tables exist in the shared file; both processes still run the
`sync` queue, and a scenario about a job that puts itself back on the queue switches the test
process to the `database` driver and works the queue with `queue:work --once`
(`ChangesInFlightTest::useTheDatabaseQueueHere()`).

**Who may act, and where the portal lives.** By default the portal is mounted at `/billing` and
the session's `wb_billable` is both the tenant and the one billable the user may act for. Four
levers separate them, all read by the server on every request, so an open tab meets them on its
next click:

- `tenant_mount` = 1 mounts the portal and the checkout the way a multi-tenant app does — under
  `{organization:slug}`, named `tenant.`, with `urlParametersUsing()` for URLs built without a
  request (`WorkbenchServiceProvider::registerTenantMount()`). The slug is the billable's name
  (`Str::slug`), the tenant is found by `Workbench\App\Http\IdentifyWorkbenchTenant`, which is
  Livewire persistent middleware like an app's own, and `/_wb/act-as/{id}` lands on
  `/{slug}/billing` as a member of that organization only. Dusk server only; off by default.
- `/_wb/member-of/{ids}` gives the user memberships apart from the session's tenant, and
  `/_wb/point-tenant/{id}` points the session's tenant resolver somewhere else without
  authorizing anything — fetched from the open page, its next action carries it.
- `deny_billing_access` = 1 makes `MollieBilling::authUsing()` answer no (billing rights revoked
  mid-session); `deny_billing_admin` = 1 makes `AdminUser::canAccessBillingAdmin()` answer no
  (an operator demoted mid-session).

**VIES and IP geolocation** are faked too — the package's own `FakeVatCalculator`
for VAT numbers (`DE129273398`, `ATU69934418`, `NL863726392B01` are valid; anything
else answers `valid: false`, exactly as VIES reports an unknown number), and a
settings-table driver for the IP country.

## Writing a scenario

Browser tests are **plain PHPUnit classes**, not Pest closures:

```php
class MyFlowTest extends BrowserTestCase
{
    public function test_something(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Business', 10);
            $this->shot($browser, 'Plan overview');
        });
    }
}
```

The Dusk server boots its application by instantiating the **originating test class
by name** (see testbench-dusk's `server.php`), and Pest's evaluated closure classes
(`P\Tests\…`) do not exist in that second process. A Pest-style test therefore
fails with `Class "P\Tests\…" not found` rendered into the page.

### The levers

| Helper | What it does |
|---|---|
| `makeBillable()` | A bare billable. |
| `makeActiveSubscriber($plan, $interval)` | Mid-life subscriber: mandate, fake-Mollie customer/subscription rows with the metadata `CreateSubscription` writes, wallets filled from the catalog. |
| `actAs($browser, $billable, $path)` | Point the session at a billable (wipes cookies first) and go. |
| `loginAdmin($browser)` | Authenticate an `AdminUser` for the admin panel. |
| `renewSubscription($billable)` | What Mollie's scheduler does: charge the subscription, deliver the webhook. |
| `deliverWebhook($paymentId)` | Deliver the legacy webhook for any payment. |
| `settleAtFakeMollie($browser, $outcome, $country)` | Settle the hosted page the browser is on. |
| `setRecurringOutcome('failed')` | Make every following recurring charge fail — drives dunning. |
| `setIpCountry('NL')` | What the IP resolves to, for the three-way check. |
| `simulator()` | The package's `LifecycleSimulator` (trial expiry, overage, past-due auto-cancel, cancelled→expired). |
| `coupons()` | `CouponService`, for creating coupons. |
| `shot($browser, 'caption')` | Full-height screenshot + a gallery manifest line. |
| `travelSharedClockTo('2027-03-10 09:05')` | Move "now" for the test process **and** the server — see below. Reset after every test. |

### A shared clock

The two processes share a database, not a clock. `Carbon::setTestNow()` in a test moves
the test's own time only; the page the browser loads next is rendered at the machine's real
time. A scenario that needs renewals months apart — a gift running out, a trial ending —
could move the books forward but never look at them.

`travelSharedClockTo($at)` moves both. The clock is a row in the shared settings table
(`workbench_settings`, key `clock.now`): the instant travelled to and the real instant the
travel happened. `Workbench\App\Support\SharedClock` turns that into `Carbon::setTestNow()`
— in the test process when it travels, and in the Dusk server on **every request**, from
`WorkbenchServiceProvider::register()`. Both read "now" as the travelled instant plus the
real time elapsed since, so the clock keeps running as in production: a cache lock with a
lifetime still runs out, and two requests are not stamped with the same microsecond.

Rules that keep it from leaking:

- **The server applies it, the test process never reads it at boot.** Only the `cli-server`
  SAPI (`php -S`) reads the row. The test process boots its application *before*
  `migrate:fresh` clears the table, and must not pick up a row an earlier test left.
- **Every test starts on the machine's time.** `BrowserTestCase::tearDown()` deletes the row
  and clears `Carbon::setTestNow()`, also when the test failed half-way, and `setUp()`'s
  `migrate:fresh` would drop the row anyway.
- **A travelled test leaves no cache behind.** The shared file cache expires entries by the
  clock that wrote them, so one written in a travelled future would still be fresh for the
  next test — which starts its ids over (`sub_wb_1`, billable 1) and would read it as its
  own. `SharedClock::reset()` flushes the cache after a test that travelled.
- **The session follows the clock.** While a clock is set the server runs with a session
  lifetime of ten years: the file session handler compares the file's *real* modification
  time with `now() - lifetime`, so a clock months ahead read every session as expired; and a
  cookie stamped with an expiry in the travelled past would be dropped by Chrome, which keeps
  the machine's time.
- **Travel, then `actAs()` again.** The page the browser holds was rendered at the old time.
- **Travel first, then build the world.** A billable or coupon created before the first
  travel carries the machine's dates, and the journey then starts somewhere else than the
  test says.

`GET /_wb/clock` answers what the server thinks the time is (`now`, `travelled`) — the first
stop when a date on screen looks wrong. `tests/Browser/Matrix/GiftLongRunMatrixTest.php`
uses the clock for a gift with paid extras walked over five months, and pins the reset.

### Interacting with Flux

Three things the portal's own patterns force, all learned the hard way:

- **Anything that costs money sits behind a confirm modal.** The button carrying the
  `wire:click` is in the DOM from the start but hidden. Use
  `confirmInModal($browser, 'modal-name', $actionSelector)`, which opens it through
  Flux's own `window.Flux.modal(name).show()`.
- **`waitFor()` requires visibility**, so it can never see those buttons. Use
  `waitForPresence()` / `waitForAbsence()` / `domCount()` when the question is "does
  this action exist?" rather than "is it on screen?".
- **Flux hides real inputs behind its own controls.** `jsClick()` reaches checkboxes
  and radios that WebDriver refuses to click. Flux's own elements — `ui-radio` (cards
  too), `ui-checkbox`, the heading of a folded `ui-disclosure` — take a native WebDriver
  click; `AdminCouponFormMatrixTest::choose()` finds a control by the property it is bound
  to and clicks what is on screen, so the form is never set through `$wire.set`.

And one about time: **never wait on a stopwatch for something the server decides.**
The VAT field is debounced and then asks VIES; a `pause(1500)` passed alone and
failed under load. Wait for the verdict — in that case the validity icon. And when an
assertion depends on something being *persisted*, wait for that, not for the screen:
`test_a_reverse_charged_invoice_shows_no_vat` waited out a `pause(1200)` after the
billing-data save and then asserted the invoice carried no VAT — which reads like a VAT
bug the two times the save had in fact been rolled back underneath it.

### `database is locked`, and why `busy_timeout` does not fix it

The two processes share one SQLite file, and `busy_timeout = 10000` covers the ordinary
case: a writer waits for the other writer to finish. It cannot cover a transaction that
**reads before it writes**. That one starts as a deferred read transaction and then asks
to upgrade to a write; if the sibling already holds the write lock, SQLite refuses the
upgrade *immediately* rather than waiting, because two transactions each holding a read
lock and each waiting to write is a deadlock. The busy handler is never consulted, so the
timeout is irrelevant.

`WorkbenchSetting::nextId()` is exactly that shape — it must read the counter to increment
it — so its writes are wrapped in a bounded retry (`retryingOnLock()`). Two runs of the
browser suite failed on it, once as the raw lock and once as an invoice that carried VAT
because the same lock had rolled back the billing-data save. Any new cross-process write
belongs behind the same retry.

## Screenshots and the gallery

`shot()` writes `screenshots/<scenario>/<test>/<nn>-<caption>.png` plus a line in
`screenshots/manifest.jsonl`. `composer browser:gallery` turns the manifest into
`screenshots/index.html`: grouped by scenario, in visit order, captions intact,
click for full size. All of it is gitignored — the images are build output.

A failing scenario also drops `screenshots/failure-<test>-0.png`, which is usually
the fastest way to see what the page was actually showing.

## Coverage

34 scenarios across 10 files:

| File | Covers |
|---|---|
| `CheckoutTest` | Anonymous signup through all four steps; existing customer with the locked billing step; failed payment. |
| `TrialTest` | Trial checkout via the €0 mandate-only flow; trial expiry without a payment method. |
| `CouponCheckoutTest` | Percentage coupon on the first payment; 100% coupon riding the mandate-only path; trial-extension coupon lengthening the trial it is applied to, and refused on a plan without one. |
| `PortalDashboardTest` | Cancel into grace + resubscribe; credits coupon; discount coupons sent to the action that prices them. |
| `PlanChangeTest` | Immediate upgrade with pro-rata; downgrade scheduled for the period end (and cancelled), including the extra-seats guard; interval switch. |
| `AddonsSeatsProductsTest` | Addon enable/disable; extra seats; one-time product crediting a wallet; `onetimeonly` disappearing once paid. |
| `LifecycleTest` | Renewal booking an invoice and recharging wallets; the next-billing date walking forward across two renewals; failed renewal → past_due → recovery; overage charge; cancelled → expired. |
| `BillingDataTest` | Saving the address; valid EU VAT number → reverse charge on the next invoice; unknown number rejected; invoice archive with real PDFs. |
| `CountryMismatchTest` | Foreign-card renewal flagging a mismatch; the customer's correction and the refund-and-reissue. |
| `CheckoutDeepTest` | Second checkout while the first payment awaits its activation retry or its webhook (return page, one payment); Back from Mollie with another plan, interval and addon (old payment withdrawn, new amount charged; Mollie refusing the withdrawal); Back from a trial's €0 authorisation to a plan without a trial; promotion link with a plan-restricted code; payment-method change moving the running subscription before a renewal; a trial whose activation failed for good still offered as a trial. |
| `CouponDeepTest` | A code or gift whose circumstances changed after it was given: a gift that ran out revoked after the tenant took the free plan (record only); finished free plan periods revoked while a later waiver runs (B and Mollie's waived amount untouched); a campaign's last slot taken by a second customer while the first one's payment travelled (two browsers — honoured, discounted subscription, a third customer refused); a promotion code switched off in the admin list before the webhook (payment honoured, Mollie on the full price). |
| `RenewalDeepTest` | Renewals off the straight way, portal and admin side by side: a trial whose first charge failed, converted by the next cycle's paid charge (no trial left on the dashboard, conversion mail once, Trial → Paid tile, a later upgrade rebalanced against the full quota); a free downgrade while the 28 February anchor replacement waits (no paid subscription comes back, Free with no next charge, the upgrade reaches Mollie and leaves one subscription); a balance payment whose create answer was lost (taken over, shown pending then paid, invoiced once); a failed renewal recovered in the admin past-due list, and the debt-only past due an overage debt keeps. |
| `ChangesInFlightTest` | Changes still on their way when the next thing happens: a scheduled upgrade applied now from the menu, then withdrawn (charge cancelled at Mollie, Pro's amount back, the next renewal unadjusted) or declined ("failed", Pro at Mollie); a graceful cancel on the dashboard while a seat charge is open (cancelled at Mollie, or — not cancelable — refunded in full with a credit note when it settles; the seat goes either way) or while an addon charge is open (off on the addons page, at the feature gate and on the dashboard); `SyncSeatsJob` meeting a held addon change's lock (queued again, seat billed afterwards, no failed job). |
| `UsageDeepTest` | Metered usage off the straight way, against a fake that honours idempotency keys: the overage dunning ladder (three distinct payments, one key each, the customer told once, the debt shown open only then); a product purchase cancelled, declined or expired on the hosted page (no success, no invoice, no units, no redemption, still offered, the code still usable); a token pack bought while the sweep's SEPA charge is open (500 bought units intact through the settlement and the renewal); a downgrade with excess while that charge is open (the preview quotes the excess at the old price and only once, the next sweep bills it as a `deferred_price` line); a trial ended early with overage, the sweep's charge before, during or without it (one payment, its invoice on the invoices page). |
| `FakeMollieIdempotencyTest` | The fake's idempotency, no browser: a repeated key answered with the first payment, refund or subscription without any lever; another amount refused (400), another URL refused (400); a refused request binds its key, an outage does not; a PATCH is not keyed, a DELETE is; an open first payment cannot be cancelled unless `payments_cancelable`; a revoked mandate reads 410; the key free again after the hour; the lever switches it off — each as measured on 26 November 2026. |
| `RefundDeepTest` | Refunds off the straight way: a refund by line from the admin modal, the reduced remainder on reopening and more than the line has left refused; Mollie refusing a refund (nothing booked, operator mailed, a retry pays once); a full refund of an overage invoice returning its units, and a stale second "refund all" from another window; a renewal collected after the customer cancelled (returned in full, customer mailed, still cancelled); a chargeback on a renewal (past due, credit note) and its reversal (active again, reversal document, archive tiles). |
| `CountryCorrectionDeepTest` | Country corrections off the straight way: an invoice partly refunded by line before the mismatch (the modal names, and the correction refunds and re-charges, only what remained paid); an overage invoice in the linked set resolved by an operator (no units move, the dashboard's meters unchanged, the reissue listed in portal and admin); a paid reissue refunded in full from the admin tab (credit note VAT and gross equal the reissue's); two corrections whose "re-charge pending" callout goes once both are booked and stays gone after reload, Back and a duplicate webhook; the invoice list's ZIP export holding the invoice, its correction credit note and the reissue under their serial names, no `MISSING.txt`. |
| `LifecycleDeepTest` | The end of a subscription and the way back: an operator's cancel at period end of a yearly trial whose first charge failed (ends now, no Resubscribe, both gates closed); a free plan cancelled on the 20th and resumed after its boundary (renewed, refilled, a change accepted); an unlimited free plan cancelled in two tabs (one mail, resumed without an end date); a cancel Mollie answered with a 503 (operator mailed, resume refused until the sweep cancelled the old subscription, then exactly one); the admin tab of a cancelled row, Resubscribe double-clicked and clicked by two operators at once. |
| `PortalAuthAndRetryTest` | Who may drive the portal, and where it lives: the portal under `/{organization:slug}` clicked through with an action on every page (every link, the checkout redirect, the invoice download and the return URL handed to Mollie keep the tenant; no `UrlGenerationException`); another organization's portal (403); plan, seats and addons actions replayed while the session's tenant points elsewhere, billing rights revoked before "Update seats", an operator demoted before a refund (403, nothing booked, nothing at Mollie). |
| `PortalRetryAndReuseTest` | A second attempt: an upgrade or addon paid after a declined charge (announced as changed/enabled, no failure left on the page); a free-plan upgrade and a gifted tenant's paid addon confirmed again after Back from Mollie (the same payment, one at Mollie, one invoice); the unfinished-change warning in English and German, gone with the next completed change. |
| `PortalAdminEdgeTest` | Portal and admin at their edges: "Update seats" after a renewal webhook opened a country mismatch under the open tab (sent to the dashboard by the persistent gate, nothing booked); an operator's cancel on a row that expired meanwhile (refused in the prompt, no audit entry); `admin_locale` = en with the app in German (refusals and amounts stay English across round-trips); a ¥500 refund on a JPY invoice (whole-number wording, ¥500 net, ¥595 at Mollie); products on a free plan and an access grant with one-time orders off (no buy action, crafted purchase refused, also with a 100 % code). |
| `PortalAdminLocaleTenantTest` | Every portal page and admin screen in German (no raw `billing::` key, `lang="de"`, German money); the invoice PDF from the dashboard card and the archive under `/{organization}/billing`, another organization's invoice id 403; the return page opened for another organization (no state, no polling, no `GetPayment` for its pending payment). |
| `AdminPanelTest` | Every admin screen; operator cancel with a recorded reason; refund and its credit note in the customer's portal; mismatch queue. |

## Troubleshooting

**`Class "P\Tests\…" not found` in a screenshot** — the test is written Pest-style.
Make it a class extending `BrowserTestCase`.

**`Workbench assets missing`** — run `composer browser:assets`.

**Chrome/ChromeDriver version mismatch** — `composer browser:driver`.

**Watch it happen** — `DUSK_HEADED=1 composer test:browser` drops the headless flag.

**A balance looks stale** — bavix/laravel-wallet caches balances in the `array`
store by default, i.e. per process. The workbench points `wallet.cache.driver` at
the shared file store; if you add another cache-backed dependency, do the same.

**A run stops making progress and never ends** — the PHP dev server blocked on its own
access log. `php -S` logs every request to stderr, into a pipe only the test process
reads, and Symfony's `Process` reads a pipe only when it is asked for output.
testbench-dusk's per-test `clearOutput()` truncates the buffer but never reads the pipe,
so after roughly 64 KB of log (a few hundred requests) the server sits in `write()`
forever and the test waits on ChromeDriver for a page that will not come. A class with a
handful of tests never gets there, because every class starts a fresh server; a
data-driven matrix class did within minutes. `BrowserTestCase::drainServerOutput()` reads
and discards both pipes in `setUp()` and `tearDown()`. A single test that makes several
hundred requests on its own would still fill the pipe — call `drainServerOutput()` inside
it. To tell this hang from a slow test: `sample <pid of php -S> 1` shows
`php_cli_server_logf → write`.

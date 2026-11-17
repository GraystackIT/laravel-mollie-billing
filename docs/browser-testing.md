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

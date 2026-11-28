# Decisions

Behaviour in this package that looks like a defect at first reading, and is not.

Every entry here has been questioned by at least one review, in several cases repeatedly.
The point of writing them down is that the next reviewer spends their attention on
something else — and that anyone who *does* want to change one of these starts from the
reasoning rather than from scratch.

Two rules keep this list honest:

- **A finding is a claim until it is reproduced.** Nothing in this package is changed on
  the strength of an audit note alone. The reproduction is a test that fails against the
  current code; the fix is what makes it pass; and the fix is only believed once the same
  test has been run against the *unfixed* file and seen to fail there. Where that
  counter-check could not be produced, the change was reverted — see
  [Refuted findings](#refuted-findings).
- **An enforced invariant beats a review round.** Where a rule has been broken more than
  once, the answer is a choke point that fails loudly, not a note asking people to be
  careful. `src/Support/MollieCall.php` is the model.

---

## Enforced invariants

### No Mollie call inside a database transaction

A transaction can be rolled back; an HTTP call to a payment provider cannot. Every
service that moves money is written in the same three phases — **decide** (row lock, short
transaction) → **call Mollie** (nothing held) → **write** (row lock, short transaction) —
with a cache lock (`billing:subscription-change:{id}`, `billing:invoice-refund:{id}`,
`billing:seat-sync:{class}:{id}`) providing the serialisation a long transaction used to.

This is enforced, not documented: `MollieCall::send()` is the single door every request
goes through and it inspects `DB::transactionLevel()`. It throws in tests and local
development and warns in production, because refusing a payment the customer is waiting
for is worse than the risk being warned about.

The rule was stated first and enforced later, and the gap is why: two services drifted
back into the old shape between one review and the next. `tests/Feature/Billing/NoMollieCallInsideTransactionTest.php`
pins it, including a self-test of the guard itself.

### Every amount carries its currency's number of decimals

Mollie reads the number as written. `"10.5"` is ten euros fifty, and `"500.00"` for a
zero-decimal currency such as JPY is off by a factor of a hundred — in a request the API
accepts without complaint, so the error lands in a booked invoice rather than in an error
log. `BillingMoney::mollieAmount()` takes the scale from the currency; anything
hand-assembled does not. `MollieCall::send()` checks the shape of every `Money` on the
request. See `tests/Feature/Billing/MollieAmountScaleGuardTest.php`.

### Webhook idempotency has two layers

`reserve()` serialises deliveries still in flight; duplicates are recognised by an
**outcome signature** (terminal status + refunded total), not by payment id — deduping on
the id alone made refund re-deliveries unreachable, because Mollie re-calls the same
payment webhook when a refund is created and the status stays `paid`. On top of that,
`invoiceAlreadyExistsForPayment()` short-circuits every handler that creates an invoice.

A reservation is not a lease held forever (`RESERVATION_TTL_MINUTES`): nothing unwinds it
when the process dies outright, and a leaked row otherwise answered every later delivery
200 — telling Mollie a payment it never processed was handled. A delivery skipped because
another is genuinely in flight answers **503**, not 200.

### A prorata charge whose run died is resumed, not skipped

`ProrataChargeHandler::paid()` writes the charge invoice first and applies the change
after it, so the invoice guard alone read a run that died in between (a fatal, PHP's time
limit — hit in production rendering the e-invoice) as finished: paid for, never applied,
Mollie left on the old amount. While `pending_prorata_change` still names the payment, a
re-delivery resumes after the last step recorded in its `progress` (`STEP_*`). The steps
are recorded because several move money: `createRefund()` has no per-line idempotency,
the wallet credit and the undercollection settlement none either. The switch writes its
own step in the same save, together with what the billable looked like before it, so a
resumed run still announces the change correctly. What decides the run and is gone once
the change is in force (whether it converts a trial, the stacked coupon codes) is noted
*before* the invoice, with the period it was decided in. A run that dies *inside* a step
repeats that step.

Not resumed, on purpose: a run whose period has ended since it died — a renewal of the
old cadence, or the charge that ends the trial, booked before Mollie re-delivered. The
finished steps (wallets, refund lines, the trial treated as running) were right for the
period that ended, and carrying on would book the switch on top of a renewal nobody
credits. The change stays waiting and the operators get `AdminPlanChangeFailedNotification`
with the completed steps; reconstructing the money for that case automatically was judged
not worth the risk for a window of minutes. The same report, at once, for a run whose
subscription has ended since (refund lines may be out already, so a full refund on top
would return too much) and for a run that wrote its invoice without recording steps (a
version before this one — resumed from nothing it would move the money twice). The report
is noted in the progress (`interrupted_reported_at`): a later delivery and
`CleanupStalePendingProrataChangeJob` do not alert again, and the job says "booked" rather
than "no invoice exists" for a payment whose invoice is there.

The clean-up leaves a run that recorded its steps alone for
`CleanupStalePendingProrataChangeJob::RESUME_WINDOW_HOURS` (48) from the run's start
(`progress.started_at`), not from the change's `created_at`: with SEPA the change is days
old when its webhook arrives, and dropping it made Mollie's re-delivery skip the payment.
The PDF and its `InvoiceCreated` are separate steps, so a run that dies between them still
announces the invoice; the PDF is rendered with the billable the run holds
(`InvoiceService::storeInvoiceDocument()`), never re-read through the invoice's relation,
which applies `BillingScope` and finds nothing without a tenant context. The
`interrupted_reported_at` note is written only once the report was handed over — submitted
to the notification channel (queued, where the notification queues), not read; a resolver or
send that throws leaves it unwritten and the next delivery tries again, and with nobody to
tell yet nothing is noted. A mail worker that fails later is the queue's to retry.

Where a step moves money, its record is not trusted to follow it: the wallet credits (or the
rebalance) commit in one transaction with their step, the overage that leaves to charge is
noted there and charged as the next step, outside it; each refund line gets its token —
part of its Mollie idempotency key — stored before Mollie is asked, and a line whose token
is on a credit note or in `pending_refund_retries` is not paid again. The period anchor is
compared once more right before the switch, so a renewal booked while the run paid out is
not overwritten (narrowed, not closed: the renewal handler takes no lock to share), and
before the first step that moves money; the switch also requires the subscription to still
run. A rebalance that throws is not caught: the transaction takes it back with its record.
A shortfall `UndercollectionCharge` claimed in this run without an outcome is handed to the
operators, not asked of Mollie again — its idempotency key is kept for an hour only. Refunds an
earlier attempt sent without booking an answer are not sent again either: those lines go to
the operators (`refunds_unknown`), nor the overage an attempt asked Mollie for (`overage_unknown`), nor
the period a change landed in once an attempt started settling it — a balance claimed or
the superseded renewal refunded (`shortfall_unsettled`). A step whose record is refused
because the waiting change is gone stops the run. Each report carries what the run recorded
(refund lines and tokens, deferred overage, unsettled claims), and the clean-up keeps it in
`orphaned_prorata_payment.progress`, because it is the operator's only copy.

Once a run has booked its payment, the customer can no longer cancel the change and a
scheduled change can no longer withdraw it — both cleared the waiting change from under a
run that was under way, which then never patched Mollie, or kept the money in silence on
the next delivery. The cancel answers "already paid"; the withdrawal tells the operators.
A cancel when nothing waits any more answers `NothingPending`, and the plan page then says
what did happen. A change the clean-up gives up with its payment collected (or unknown) is
`plan_change_unfinished` to the plan page, not `plan_change_failed_at`: that one says "the
payment failed, try again", and a second try charged twice.

A paid prorata charge whose webhook never arrived (no invoice) is not given up by the
clean-up at all: it delivers it through `MollieWebhookController::replayDelivery()`, as
`CleanupStalePendingOverageChargeJob` does for overage. Dropped as an orphan, a plan change
paid for never happened, and a seat or addon change — in force since Phase 1 and PATCHed at
Mollie — had its payment returned in full by the late webhook while the addon stayed on.
Only a payment whose metadata names `prorata_charge` is replayed. A cancel refused past the
switch suppresses the render's "plan changed" toast: the portal shows one toast, the last. The
PATCH is not made exactly-once; it sets absolute values derived from the change, so a
repeat sets the same target. The change events are built from what the switch wrote,
stored with it.
`tests/Feature/Webhook/ProrataChargeResumeTest.php` crashes after every step.

### Activation is two-phase

`FirstPaymentHandler` and `MandateOnlyPaymentHandler` split into a short `openGate()`
transaction (row lock → invoice guard → duplicate guard → `claimActivation()`) and an
`activate*()` body that runs outside any transaction, with `releaseActivationClaim()` in a
`finally`. The guard order matters: the invoice guard runs first, because after a crash
mid-activation the billable is both Active *and* holds this payment's invoice — that is a
re-delivery to swallow, not a duplicate payment to report.

---

## Deliberate behaviour

### Legacy webhooks, and no signature validation

Mollie does not sign legacy webhooks; `X-Mollie-Signature` exists only on next-gen event
webhooks (payment links, sales invoices), which do not yet cover subscriptions or
recurring payments. `ValidatesWebhookSignatures` would be a pass-through on our route and
is deliberately not applied. The integrity guarantees are the `BillingProcessedWebhook`
dedup table and the fact that every delivery triggers a `GetPaymentRequest` against
Mollie, which fails for a spoofed id.

### `mrr()` measures invoiced revenue, not contract value

The window is the last 30 days of invoiced revenue, normalised per period. A yearly
customer therefore contributes only in the 30 days around their own renewal, so the tile
reads near zero for eleven months of the year. This is a definition, not a rounding bug.

The alternative — pricing every Active billable from the catalog — is a different metric
with different behaviour for prorata and mid-cycle charges, and the two-way split in
`AdminKpiService` is the deliberate counterpart of this choice. Apps that want
contract-value MRR bind their own `AdminKpiService`.

### Flux Pro is a hard runtime dependency listed under `suggest`

It is commercial: the consuming app must install it with its own licence, so it cannot be
in `require`. Every portal and admin view uses `<flux:…>` directly — there are no fallback
wrappers and no `FluxPro::isInstalled()` checks, because a half-rendered billing screen is
worse than a clear failure. The feature suite runs without Flux precisely because it never
renders a portal screen; the browser suite, which does, needs the licence.

### Free plans run without a Mollie subscription

A zero-price plan is `SubscriptionSource::Local`: no Mollie subscription, no mandate
required. Paid plans are `SubscriptionSource::Mollie`. Services branch on this rather than
creating €0 subscriptions at Mollie, and the trial flow converts Local → Mollie when a
mandate arrives. A free plan can still owe money — metered overage is real revenue — which
is why the dunning and rebalancing paths handle Local billables too.

### Every combination of quota and overage price is a supported shape

`included_usages` and `usage_overage_prices` are independent maps, and all four
combinations mean something: allowance-then-overage, hard cap (a quota with no price, or
the explicit `false`), pay-per-use (a price with no quota), and not-sold. None of them is a
misconfiguration to be normalised away — the hard cap exists because nothing could collect
a negative balance without a price, and pay-per-use is handled end to end because the
charge path, the dunning ladder and the plan-change rebalance all iterate *wallets* rather
than quotas. `tests/Feature/Config/UsageConfigMatrixTest.php` walks the matrix.

### A trial's quota is prorated, and is the yardstick for the whole trial

A trial collects no money, so it is credited only the share of the plan's period quota
that its own length represents: `ceil(included × trialDays ÷ intervalDays)`, 30 nominal
days to a month and 365 to a year. A 14-day trial on a monthly plan with 100 included
units gets 47. That is deliberate, and `Support\TrialQuota` owns the arithmetic.

What was *not* deliberate is that only the crediting knew about it. Every reader — the
usage meters, `usedBillingQuota()`, the threshold warning — measured the wallet against
the plan's full period quota, so the part that was never granted read as consumption: a
subscription minutes old reported "10 of 20 remaining" on a wallet nothing had touched.
The threshold warning failed in the customer's disfavour for the same reason, firing at
80 % of a quota the trial wallet only reaches once it is well into overage.

`Billable::grantedBillingQuota()` is the single answer to "how much was this billable
actually given this period", and everything that measures a wallet goes through it. It
reads the `subscription_meta.trial_quota` marker written at grant time rather than
re-deriving from `trial_days` — the configured trial length can change under an existing
trial, and `extendBillingTrialUntil()` moves the end date while crediting nothing, so the
recorded grant is the only thing that stays true. A trial from before the marker existed,
or one whose marker no longer describes the plan the billable is on, falls back to
re-deriving. `tests/Feature/Wallet/TrialGrantedQuotaTest.php` pins both.

### A trial is free, including what is added during it

Seats, addons and quantities added while the trial runs are not charged, and removing
them again credits nothing. Nothing was paid for the window they are added to and nothing
will be until the trial ends, so there is no fraction of anything to prorate — the
customer is charged the full new amount at conversion, because Mollie is PATCHed to it
either way. A **plan or interval change is the exception**: it ends the trial and is
charged. That is a product decision, not an arithmetic one.

`BillingPolicy::isFreeTrialAdjustment()` is the predicate, next to `isPastDueReset()` and
for the same reason: the composer, the context builder and the preview all have to give
one answer. They did not — the preview quoted €0 for extra seats during a trial while the
change collected the prorated amount and converted the trial with it.
`tests/Feature/Subscription/TrialAdjustmentIsFreeTest.php` pins both directions.

The same premise decides what happens to the wallet when the trial ends or moves:

| | | |
|---|---|---|
| **Trial ends, paid** — first recurring charge, or a plan change mid-trial | Full period quota, `WebhookSupport::creditPeriodQuota()` | A full period has been paid for. Both routes share one refill, so a trial that ends by changing plan lands where one that ends by being charged lands. |
| **Trial continues, plan changes** | Rebalance against the *grant*, re-granted prorated to the same trial length | Nothing has been paid; the trial's entitlement simply moves plan. |
| **Paying subscription, plan changes** | Unchanged — prorated settlement of the period being left | The period was paid for and partly consumed. |

The plan-change adjuster is deliberately *not* the mechanism for the first row. Its
baseline is a period that was paid for and partly consumed, which a trial's is neither —
and it only ran when plan or interval moved, so a seat change mid-trial converted the
trial, started a new period, charged for it, and left the wallet holding the trial's
fraction for the whole month that followed. Read against the old plan's full quota, the
un-granted remainder also counted as consumption: an untouched 10-of-20 trial wallet came
out of a downgrade holding 1 unit instead of 5, and past the new quota the difference is
billed as overage the customer never used. `tests/Feature/Webhook/TrialConversionWalletTest.php`
and `tests/Feature/Wallet/TrialPlanChangeRebalanceTest.php` pin the three rows.

### Rollover carries differently at a renewal than at a plan change

At a **renewal** the new quota is added on top of whatever is in the wallet: 100 unused
plus 100 credited is 200. At a **plan change** only what sits *above* the old plan's
allowance is carried: the same 100 unused plus a new quota of 100 is 100, not 200.

That reads like an inconsistency and is the point. A renewal ends no period early — there
is nothing to settle. A plan change ends the current period early and settles it
proportionally, which is what stops a customer consuming a yearly allowance in month one
and then downgrading. Carrying the current allowance *and* granting the new one would pay
them twice for the same half-period. `docs/usage-billing.md` states the formula and its
worked examples; `tests/Feature/Config/UsageConfigMatrixTest.php` pins both sides against
each other so neither can be "corrected" into the other.

### The catalog is keyed by (planCode, interval)

`included_usages` and `usage_overage_prices` live inside `intervals.{monthly|yearly}`, not
at plan level, because a yearly plan's included quota is not twelve times the monthly one
in any pricing model anyone actually ships. Test fixtures must follow the same shape;
`tests/Feature/Wallet/WalletUsageServiceTest.php` is the reference.

### Coupon redemption keeps its explicit lock

`CouponService::redeem()` is `DB::transaction` + `Coupon::lockForUpdate` +
`increment('redemptions_count')`. It looks like something an ORM feature could replace and
it cannot: `validate()` is deliberately lock-free (it runs on every keystroke in the
checkout), so two tabs both reach `redeem()` believing the slot is free.

### `settles_wallet: false` on plan-change overage lines

`WalletPlanChangeAdjuster` clamps the wallet to 0 as it composes the charge, so those
units are already accounted for — the customer is paying for consumption that happened,
not buying capacity. `settleOverageWallets()` credits `quantity` back for a *period-end*
charge, where the balance is still negative and needs clearing; doing it here would land
the credit on 0 and hand out free units, permanently for a rollover type.

### The browser suite sets `busy_timeout`, never `journal_mode = wal`

Two processes share one SQLite file. WAL looks like the obvious answer and makes it
strictly worse: both processes issue `pragma journal_mode = wal` on connect, that pragma
needs an exclusive lock, and the suite went from two failures to forty-five and then to
`database disk image is malformed`. `busy_timeout` alone is what works. See
`docs/browser-testing.md`.

### The test Mollies honour idempotency keys by default (26 November 2026)

Mollie replays the first response for a key it has seen within the hour and refuses the key
with other parameters (measured: `MollieRequestContractTest`) or on another URL (400 "used
before on a different URL", `MollieSettledPaymentContractTest`); a repeated subscription create
comes back as the same subscription (`MollieChargedSubscriptionContractTest`). The fakes now do
the same unless told otherwise, because a fake that takes a reused key as a new operation hides
exactly the bug a stable key exists to prevent — a retry that pays, refunds or subscribes twice —
and lets a key reused for a *different* operation pass, which the real API refuses.

- `FakeMollieHttpAdapter` (browser and concurrency suites): every POST, PATCH and DELETE, keyed
  on the header the SDK sends; `honour_idempotency_keys` = `0` switches it off. It used to be
  opt-in, for create-payment only, and switched on by `UsageDeepTest` alone.
- The feature suite's stateful facade doubles — `ChangeMatrixWorld`, `BookingMatrix`,
  `RenewalLongRun` — through `tests/Support/MollieIdempotencyMemory`; `->honour = false`
  switches it off. `UsageLongRunHarness` already modelled it for create-payment. The per-test
  `Mollie::shouldReceive('send')` stubs and the SDK's `MockMollieClient` (`MollieBilling::fake()`)
  answer canned responses per request class and are left alone: there is no state a key could
  protect.

Judgement calls: only a 2xx answer binds a key (an outage or refusal lever must not stick to the
key its retry sends again; what Mollie does with a key whose first request it refused is not
measured), except a lost answer, which binds it because Mollie acted. A replay is the response as
it was then, not the resource as it is now. The status for "other parameters" is not measured —
the contract test only asserts the refusal; the browser fake answers 422, as before, and so does
the feature memory (the code comments and `UsageLongRunHarness` say 400; no caller branches on it).
**Superseded on 26 November 2026** — all three measured, see the next entry: other parameters
are a 400, a refused request binds its key, and a PATCH is not keyed at all.

No package code was found reusing a key for a different operation: every feature directory that
uses the doubles (Subscription, Webhook, Checkout, Portal, Wallet, Billing, Coupons, Lifecycle,
OneTimeOrder, Catalog, Testing) and five browser classes stayed green with the default on.
Red before: `tests/Browser/FakeMollieIdempotencyTest.php` (4 of 7 red against the opt-in fake),
`tests/Feature/Testing/MollieIdempotencyInWorldsTest.php` (9 of 9 red against the doubles without
the memory).

### What Mollie does with a key, a cancel and a revoke — measured (26 November 2026)

Every assumption the fakes and the code comments made about Mollie that no run had measured,
measured against the real test API: `tests/Sandbox/MollieIdempotencyContractTest` (8 cases) and
`tests/SandboxBrowser/MollieSettledPaymentContractTest::test_a_refund_sent_twice_with_one_key_is_one_refund`
(refunds need a settled payment). Each asserts the answer of that day.

| Question | Answer |
|---|---|
| Same key, other parameters — `POST /payments`, `POST /customers/{id}/subscriptions`, `POST /payments/{id}/refunds` | **400** Bad Request, "You are using an idempotency key that you used before with different parameters. Please send a unique idempotency key with each request.", no `field`. Not 422, as both fakes answered |
| Same key on another URL (payment → subscriptions; customer A → customer B) | **400**, "…used before on a different URL…" — the 16 November measurement holds |
| A request Mollie refused (422) — sent again, identical, with the same key, after its reason had gone | **the refusal again** (422, same detail) — the same request with a fresh key is taken. A 4xx binds its key like a 2xx. Another request with that key: 400 "other parameters" |
| A replay after the resource changed (subscription PATCHed to 12.00 and cancelled) | the first answer as it was: 10.00, `active` — not the resource as it is now |
| A PATCH with a reused key | **not keyed**: the same key with another body is taken, and a repeat of the first PATCH is applied again (13.00 → 15.00 → 12.00) |
| A DELETE (cancel subscription) with a reused key | replayed: 200 with the cancelled subscription, where a fresh key gets the 422 of a second cancel |
| Same refund key twice | one refund, same id; one listed |
| Refund above what is left | 422 "The specified amount cannot be refunded", field `amount.value` |
| Cancel an open first payment — no method picked; created with `creditcard`; with `eps`; at €0.00 | **refused**, 422 "The payment cannot be cancelled"; `isCancelable: false` up front, status stays `open`. (`banktransfer` cannot be a first payment at all.) |
| Cancel a pending recurring direct debit | taken: `isCancelable: true`, status `canceled` |
| Revoke a mandate a subscription charges | the subscription is `canceled` at once, with `canceledAt`; the mandate reads back **410 Gone** |

Not measurable here: whether a 5xx binds a key (no request provokes one), and the one-hour
lifetime (a run would wait an hour; the fakes keep 60 minutes as documented).

**What follows for the fakes** (the test-side change, nothing in `src/`):
`FakeMollieHttpAdapter`, `MollieIdempotencyMemory` and `UsageLongRunHarness` answer other
parameters with the measured 400 and text, remember a 4xx answer for its key (a 5xx still binds
nothing — an outage lever must not stick to its retry), and key POST and DELETE only.
`FakeMollieHttpAdapter::cancelPayment()` cancels only what the payment reports `isCancelable`
(the `payments_cancelable` lever) — it used to cancel any open payment while reporting
`isCancelable: false`; a revoked mandate reads 410. `CheckoutDeepTest`'s two withdraw scenarios
switch `payments_cancelable` on, to keep pinning that branch.
Red before: `tests/Browser/FakeMollieIdempotencyTest.php` 5 of 11 red against the unfixed fake
(other-parameters status, refusal binding, PATCH keyed, open first payment cancelled, revoked
mandate 404); `tests/Feature/Testing/MollieIdempotencyInWorldsTest.php` 4 of 10 red against the
unfixed memory (422 ×3, refusal not bound). Both green after.

**What follows for the package** — measured facts the code relies on, for the maintainer:

- *The code comments were right, the fakes wrong:* `MollieCall::send()`, `RefundInvoiceService`,
  `UsageLongRunHarness` and the CHANGELOG said 400; no caller branches on the status.
- **`PendingFirstPayment::withdraw()` never succeeds against real Mollie.** No open first payment
  can be cancelled through the API, so "Back from Mollie with a changed order" always ends in
  `PendingPaymentForAnotherOrderException`: the customer is told to finish the earlier payment
  or wait for its expiry (15 minutes). That is the designed fallback and it is safe — no second
  payment next to a payable one — but the withdraw-and-continue path the browser suite showed as
  the normal case is unreachable. Whether to offer something else (e.g. create the new payment
  and rely on the automatic refund of a duplicate) is a product decision; nothing was changed.
- *A refused request binds its key for an hour.* A keyed request retried within the hour after a
  4xx gets the same refusal even when its reason is gone. Where this can bite: a refund line
  (`InvoiceService::createCreditNote`, stable `refundToken`) or an admin refund
  (`RefundInvoiceService`, key on gross + already refunded + reason) retried within the hour after
  Mollie refused it — the retry is refused again, without a second refund. It errs on the safe
  side; nothing was changed. **Seen in the suite:** `RefundDeepTest`'s "a refund Mollie refuses …
  can be retried once" went red against the corrected fake — the operator's retry from the same
  panel, right after the refusal, got the 422 again and a second failure mail. Real Mollie does
  exactly that. The test now retries an hour later (shared clock +61 min) and says why; whether a
  retry after a refusal should carry a new key (e.g. the refused attempt's number) is the
  maintainer's call. **Decided the same day:** it does — see "A refusal moves the idempotency
  key on; nothing else does"; `RefundDeepTest` retries at once again.
- *Revoking a mandate cancels its subscriptions at once* — the reason `RevokePreviousMandate`
  first moves the subscription with `rebindMandate()` is now a measured fact, not documentation.
- *The clean-ups* cancel what Mollie lets them: a pending recurring direct debit is taken. The
  prorata paths (`UpdateSubscription`, `CleanupStalePendingProrataChangeJob`, `InvoiceService`)
  ask `isCancelable` first, which agrees with the answer; `CleanupStalePendingOverageChargeJob`
  sends the cancel and keeps the slot on a refusal. Both hold as measured.

### A changed order waits for the earlier payment, and says until when (26 November 2026)

Measured the same day (entry above): Mollie never cancels an open first payment, so "back from
Mollie with a changed order" always ends in `PendingPaymentForAnotherOrderException`. The
maintainer kept the safe wait — no second payment beside a payable first one — and decided it
has to be *shown*:

- **The checkout says until when.** `PendingFirstPayment` hands the exception the payment's
  own `expiresAt` from the `GetPaymentRequest` it already made (Mollie sends it on an open
  payment; the hosted-checkout sandbox test asserts it is there). Only when an answer has no
  `expiresAt` does it fall back to `createdAt` + `PendingFirstPayment::FIRST_PAYMENT_EXPIRY_MINUTES`
  (15, measured); with neither, the notice says "a few more minutes". The time is shown in
  the billable's display timezone (`BillingTime::display()`), formatted for the app locale
  (`checkout.pending_other_order_time_format`).
- **It offers the earlier payment's checkout link** ("Zur früheren Zahlung") — paying it books
  the *earlier* order, and the text says so. No link, no button: the exception carries null
  when Mollie gave none. (Today that cannot happen: an open payment without a link is not
  payable any more and gets a new payment instead — see `redirectFor()`.) The notice state on
  the component is `#[Locked]`, because the link is rendered as an `href`.
- **The withdraw stays** for a payment that reports `isCancelable: true` (the fake's
  `payments_cancelable` lever; `CheckoutDeepTest`'s two withdraw scenarios). One that reports
  `isCancelable: false` is no longer sent a cancel that is known to be refused; one that says
  nothing is still asked, as before.
- **Only the checkout shows the notice.** The plan page's upgrade, the addons and seats
  screens and the extras purchase still show the exception's message alone; the data is on
  the exception for them when wanted. *Superseded on 27 November 2026 — see the next entry.*

Pinned by `tests/Feature/Checkout/PendingPaymentWaitNoticeTest.php` and
`tests/Browser/CheckoutPendingPaymentWaitTest.php`.

### The wait notice is the same on every screen that starts a first payment (27 November 2026)

Maintainer's decision. The plan page's upgrade from a free plan and the purchase of extras on
a gift (addons, seats — `StartGrantExtrasPurchase`) meet `PendingPaymentForAnotherOrderException`
exactly as the checkout does, and showed only a toast or the red flash with the exception's
message. They now show the checkout's notice — expiry time and the link to the earlier payment.

- **One component, one state.** The markup is `<x-mollie-billing::pending-other-order>`
  (`resources/views/components/pending-other-order.blade.php`), the state the
  `Concerns\ShowsPendingOtherOrderNotice` trait: `pendingOtherOrder`, `pendingOtherOrderUntil`
  (ISO, UTC), `pendingOtherOrderTimezone` (the billable's display timezone, taken from the
  exception's billable when caught) and `pendingOtherOrderUrl`, all `#[Locked]`. A screen calls
  `showPendingOtherOrder($e)` in its catch and `clearPendingOtherOrder()` at the start of the
  action, so a retry that goes through leaves no stale notice. The checkout's dusk selectors
  and lang keys (`checkout.pending_other_order_*`) are unchanged and reused — not moved — so an
  app that overrode them keeps its wording. Its former `pendingOtherOrderUntilLabel()` action
  is gone; the component formats the time.
- **Only an https link is rendered** — or one on the application's own origin, which is where
  the browser suite's fake Mollie checkout lives (plain http). Anything else (another scheme, a
  relative path, a foreign plain-http host) becomes no link, and the notice says "wait"
  instead of "finish it". The URL comes from Mollie's API, so this is defence in depth, not a
  known hole.
- **Not on the products page.** `products.blade.php` buys one-time orders
  (`StartOneTimeOrderCheckout`), which never ask `PendingFirstPayment` and cannot throw this
  exception — and a Local (gifted) subscription is refused products altogether. The "extras
  purchase on a gift" is the addons and seats screens; a catch on the products page would be
  dead code.

Pinned by `tests/Feature/Portal/PendingPaymentWaitNoticeScreensTest.php` (red against the
unchanged screens: 10 of 10) and `tests/Browser/PortalPendingPaymentWaitTest.php`.

### Grace on cancellation during a trial is the trial end

`CancelSubscription::graceBoundary()` returns `trial_ends_at` whenever it lies in the
future. During a trial nothing has been paid and `subscription_period_starts_at` is the
mandate-capture time, so the period-derived default describes an interval nobody paid for
— handing it out would be roughly a free year on a yearly plan cancelled on day two, which
no automatic pass ever reclaims.

It used to take the *earlier* of the two, which is the same answer whenever the trial is
shorter than the interval and the wrong one when it is not: `trial_days` is per-interval
config with no upper bound, and a 45-day trial on a monthly plan then gave the customer 30
of the 45 free days they were promised. `TrialCancelGraceBoundaryTest` pins all three
shapes — short trial, long trial, and a trial that has already converted (where the
paid period wins).

### A cancel at period end keeps only the access there is (25 November 2026)

`graceBoundary()` special-cased only a trial that is still running. A trial whose first
charge failed is past due with `trial_ends_at` behind it and `subscription_period_starts_at`
still at the mandate capture, so a cancel at period end — the admin's button (offered for
past due), or the dashboard action called directly — took the default, `nextBillingDate()`:
a year on a yearly plan, as `cancelled` with an end ahead, which is access. A resume on top
of it passed `paid_period_ends_at` = that date to `CreateSubscription`, which wrote Active,
cleared the dunning keys and set Mollie's first charge a year out — for a customer who had
paid nothing. A chargeback (`markPastDue` moves only the status) handed back the rest of the
charged-back period the same way.

**Rule:** a graceful cancel of a billable with no access (`! hasAccessibleBillingSubscription()`)
is an immediate one — ends now, overage pass included, which is what the past-due auto-cancel
does a few weeks later anyway. Past due for an overage debt *only* keeps its access (the
plan is paid), so it keeps its grace to the period end. A failed ordinary renewal was never
affected — its period was not advanced, so the boundary is already behind — and is covered
by the same rule. `PastDueCancelGraceTest`: the failed first charge and the chargeback red
before (end 10 February / 15 March, resume succeeded), the debt-only control green.

The admin tab reports what was written, not what was ticked (26 November 2026):
`confirmCancel()` flashed "grace period applies" for a cancel at period end the service had
turned into an immediate one, and the operator told the customer about access that was gone.
It now reads the end date back. `SubscriptionTabCancelAndRevokeTest` › "says the cancel took
effect now …", red before.

### A second cancel at period end changes nothing (25 November 2026)

`CancelSubscription::handle()` refused only `new` and `expired`. A second graceful cancel
of a cancelled row (a stale tab, another billing member) ran the whole method again and
recorded the grace date as `cancelled_from.ends_at` — the "end before the cancel" a resume
restores. A free plan without an end date therefore came back from a resume with the
30-day expiry, and `PrepareUsageOverageJob` pass 1 expired it on that date, plan and addons
wiped. `SubscriptionCancelled` and the customer mail went out a second time.

A cancel at period end of a `cancelled` row now returns without writing or announcing
anything — a no-op rather than a refusal, so the transition matrix cell stays `cancelled`
and an internal caller (the country-mismatch cancel, a plan waiver ending) meeting a row
that is already running out is not logged as a failure. An immediate cancel of a cancelled
row still goes through: it ends the grace. `ResubscribeSubscriptionTest` › "keeps the
unlimited free plan through a second cancel …", red before (end date restored, two events).

### A resume leaves one subscription at Mollie (26 November 2026)

`ResubscribeSubscription` creates a **new** Mollie subscription. Two paths made that a
second one beside a subscription that still collects:

- **The cancel it undoes never reached Mollie.** `CancelSubscription` ends the row locally
  whatever Mollie answers and parks a failed cancel under `pending_subscription_cancel` for
  the hourly sweep (24 hours, then an operator mail). A resume in that window created the
  new subscription with its first charge at the paid period's end — which is the old one's
  next charge too. Once the sweep gave up, the customer paid twice every period.
- **Two resumes at once** (a second tab, a second operator, the admin's Resubscribe and the
  customer's): the method had no serialisation, both read `cancelled` before either wrote,
  and each created a subscription. Livewire sends one tab's double click one after the
  other, so this needs two requests from two places — exactly what an operator and a
  customer are.

**Rule:** a resume takes the `billing:subscription-change:{id}` cache lock every change to
the subscription takes, and decides on the row re-read under it; a loser is refused with
`SubscriptionChangeInProgressException`, as an overlapping change is, and one arriving after
the first has finished finds the row no longer cancelled. On the Mollie branch the pending
cancel is sent once more first, outside any transaction: done (or the subscription already
gone) clears the marker; anything else throws `PreviousSubscriptionStillRunningException`
with nothing written, so the same click works once Mollie answers. Refusing rather than
adopting the old subscription is deliberate: after a 503 nobody knows whether Mollie
cancelled it, and a cancelled subscription cannot be revived.

The dashboard and the admin tab name the three refusals (still being cancelled at Mollie,
another change running, no longer cancelled) instead of "an error occurred" / "Error (Code:
0)". `ResubscribeOneSubscriptionTest`: all four cases red before (a second create each time,
no cancel retried); `LifecycleDeepTest` (browser) › the 503 cancel, the double click and two
operators at once.

### A cancel forgets what the gate remembered of the subscription (26 November 2026)

`MollieSubscriptionGate` keeps a 60-second snapshot of the Mollie subscription, and the
checkout sends a customer whose subscription Mollie still runs back to the dashboard. A
past-due dashboard asks the gate for its "charge now" banner, so after an immediate cancel —
the operator's, or a cancel at period end with no access to keep — `billing.active` sent the
customer to the checkout and the checkout sent them straight back, for a minute, with the
subscription already cancelled at Mollie. `MollieSubscriptionReplacer` already forgot the
snapshots it changed; the cancel did not. `CancelSubscription`, `RetrySubscriptionCancelJob`
and the resume's own retry of a pending cancel now drop it once Mollie has taken the cancel
(or answered that it was gone). `CancelForgetsSubscriptionSnapshotTest`, both cases red before;
`LifecycleDeepTest` › the operator's cancel of a failed trial reaches the checkout.

### A recurring code that ends before the first renewal leaves no marker (26 November 2026)

A paid first payment made with a recurring code is honoured even if the code was switched
off while the payment travelled (`FirstPaymentCouponPricing::afterPayment()`): the
redemption is counted and the invoice books the discount. The Mollie subscription is
created at the full price, because `price()` sets `recurring_discount_net` to 0 for an
inactive code. But the redemption still wrote the `active_recurring_coupon` marker, and only
`isMarkerStillRedeemable()` reading the coupon's **live** `active` flag kept it quiet. An
operator who switched the campaign back on for new customers revived it: every renewal of
this customer booked a coupon line for a discount Mollie never collected, took it back off
with an `adjustment` and fired `PaymentAmountMismatch`.

**Rule (maintainer decision 6):** where `price()` zeroes the recurring discount because of
the code's own state, the payment is redeemed and **no marker is written** — `price()`
returns `recurring_marker => false`, and `redeem()` passes `skip_marker`. The marker exists
only where Mollie was priced with the discount.

**The code's own end date is treated the same.** The hazard is a different one there — the
marker snapshots `valid_until`, so editing the code later revives nothing — but the marker
is not dead either: `price()` measures `valid_until` against the next charge *at the current
hour*, while the renewal asks at the *start of the charge's day*
(`SubscriptionPaymentHandler::paid()`, `$chargeDay`). A code ending at noon on the day of the
first renewal priced Mollie at the full amount, and its marker, capped at that noon, was
still valid at midnight: the same coupon line, adjustment and mismatch. The trial path
already wrote its marker only for a recurring discount above 0, so both paths now agree.

Not changed: a code whose `discount_periods` is spent by the first payment (one period) also
prices Mollie at the full amount and still gets a marker — that marker's own count
(`discount_periods` kept in it, the redemption rows) refuses it at once and nothing revives
it. An app's own `price()` that returns no `recurring_marker` key keeps the marker, as before.

`FirstPaymentCodeChangedInFlightTest` › "writes no recurring marker for a code switched off in
flight …" and "… whose end date falls before the first renewal": both red before — the marker
was present, and with that assertion taken out the renewal invoice carried a `coupon` line
(and its `adjustment`) for 59.50 collected. `BookingMatrixCouponsTest` › "books the paid
amount when a coupon dies between payment and webhook" still counts one redemption.

### A refusal is shown in the reader's language, its message stays English (26 November 2026)

The admin screens showed a service's refusal as `$e->getMessage()`: a German operator read
"There is no subscription to cancel.", "Only paid invoices can be refunded.", "A coupon with
the code WINTER26 already exists." on an otherwise German page. A handful of customer-facing
exceptions had gone the other way and put the *translated* sentence into the message, so the
log read German whenever the request did.

**Rule (maintainer decision 12):** the exception message is English and is for the log. What
a screen shows comes from `Support\ExceptionMessage`, which reads the translation key the
exception carries (`Exceptions\TranslatableException`, implemented by
`Exceptions\Concerns\CarriesTranslation`):

- an exception with one meaning names its sentence itself (`SubscriptionChangeInProgressException`,
  `RefundInProgressException`, `SeatCountExceedsMaximumException`, …);
- the two thrown for many reasons — `InvalidSubscriptionStateException` and
  `InvalidRefundTargetException` — get theirs at the throw site with `->withTranslation()`;
- an input refusal of the services the admin forms call (coupons, refunds, extensions, the
  country fix) is an `InvalidBillingInputException`, which is an `\InvalidArgumentException`
  so every existing `catch` and test keeps working;
- `PastDueExpansionNotAllowedException`, `SubscriptionEndedChangeNotAllowedException`,
  `ActivationInFlightException` and `PendingPaymentForAnotherOrderException` keep their
  portal/checkout keys, but their message is now that sentence's `en` text.

The sentences live in `resources/lang/{en,de}/exceptions.php`. They reach operators and
customers alike, so the German ones address nobody directly (no "du", no "Sie"). The `en`
sentence is the old message wherever that was readable, so English screens did not change.

Anything without a key falls back: `ExceptionMessage::for()` says the generic "The action
could not be completed." — for customers, who must not read an internal message;
`ExceptionMessage::forOperator()` says "The action was refused: <message>" — for the admin,
where an operator acting on an app's own refusal needs what it said, even in English. An
operator's refusal that the service *can* say is no longer reported to the error tracker:
the invoices tab now catches `RefundInProgressException` (it went to `report()` and the
generic "refund failed"), and the resume, the revoke and the country fix show a translatable
refusal instead of reporting it.

Deliberately not changed: `InvalidCouponException` carries a reason *code*, which the portal
screens already map to their own sentences; the grant screen still shows the code. The PDF
re-render error keeps its technical detail inside the translated `pdf_error` sentence. A bare
`\InvalidArgumentException` in `InvoiceService` (unknown metadata type, unknown currency) is a
programming error, not a refusal.

`ServiceExceptionTranslationTest` (admin cancel of an ended subscription, refund of an unpaid
invoice, refund while another runs, taken coupon code, trial extension on a cancelled
subscription — each asserting the German sentence under `de`; the customer exception's English
message): all seven red before. `ExceptionTranslationGuardTest` keeps every throw of the two
many-reasons exceptions keyed and the four admin-facing services free of bare
`\InvalidArgumentException` (run against the unfixed sources it found 23 unkeyed throws and 27
bare ones). `TranslationKeysExistTest` checks every literal key in both locales and the
`exceptions.php` key and placeholder parity. Browser: `AdminCouponFormMatrixTest` › the form says
why in the operator's language — red with the old `getMessage()` line, green with the fix.

### The German customer texts say "du" (26 November 2026)

The German portal and checkout mixed both registers, sometimes on one screen: the dashboard
tiles said "deine Zahlungsmethode", the coupon card under them "Geben Sie einen beliebigen
Code ein"; the checkout opened on "Wählen Sie den passenden Plan"; half the mails said
"Deine Testphase", the refund and undercollection mails "Ihre Zahlung".

**Rule (maintainer decision 11):** every German text a customer reads addresses them as
"du" — lower-case `du`/`dein`/`dir`/`dich` inside a sentence, capitalised only at its start.
That covers `portal.php`, `checkout.php`, `errors.php` and the customer notifications in
`notifications.php`. Not changed: `admin.php` and `audit.php` address the operator and keep
their "Sie"; the `[Admin]` notifications address nobody; `exceptions.php` reaches both and
deliberately addresses nobody (see the entry above). The country-correction reasons in
`portal.php` are printed on the customer's credit note and corrected invoice, so they say
"du" too. Keys and placeholders are unchanged; only the wording moved, so no app override
breaks — but an app that published and edited the German files keeps its own copy.

"Sie" is also the third person, which a pattern cannot tell from the polite form: three
portal sentences keep it ("Diese Änderung … Sie wird in der kommenden Nacht erneut
versucht", the dropped-payment note, the not-yet-applied flash). The guard lists them by
exact wording, so a polite "Sie" slipped into the same sentence still fails.

`tests/Feature/Coverage/GermanInformalAddressTest.php` walks every customer-facing German
file and refuses "Sie", "Ihnen" and every form of "Ihr": red before in four of them
(`checkout` 33 keys, `portal` 32, `notifications` 19, `errors` 3), green after. Browser:
`PortalAdminLocaleTenantTest` › the German checkout and portal address the customer as du —
walks the checkout's plan, add-on and confirm steps and every portal page in German and
asserts the du-wording literally and no polite pronoun on screen; red with the old
`checkout.php` ("Did not see expected text [Wähle den passenden Plan]"), green after.

### An automatic refund whose run throws is queued, not left to a re-delivery (26 November 2026)

`UnwantedPaymentRefund::refund()` and `GrantExtrasPurchaseRefund::refund()` wrapped
`InvoiceService::createRefund()` in a catch that logged and returned null. A Mollie error
never reaches that catch — `createRefund()` queues the line itself — but everything *around*
the Mollie call does: the refund lock's cache store, the token lookup's bookkeeping, and the
credit note's write after Mollie has paid out. Such a throw ended in nothing queued while
`announceOnce()` logged "queued for retry"; the refund happened only if Mollie called the
webhook again and `resume()` ran. Covers every caller: duplicate first payment, activation
failed for good, prorata of a cancelled change / ended subscription, renewal after end
(`UnwantedPaymentRefund`), and the gift-gone purchase (`GrantExtrasPurchaseRefund`).

**Rule:** a throw from `createRefund()` parks the line in `pending_refund_retries`
(`Services\Webhook\Concerns\QueuesThrownRefund`) — the existing queue: hourly
`SweepPendingRetriesJob`, `RetryRefundLineJob` with its backoff and 7-day window, and its one
admin mail (`AdminPlanChangeFailedNotification`) at the dead letter. No second ladder.

- **Marked `refund_outcome_unknown`.** The throw does not say whether Mollie holds the refund,
  so the retry lists the payment's refunds and adopts the one carrying the token instead of
  posting again — the lost-answer path `createRefund()` already had.
- **One token per payment.** `fullRefundToken()` derives the token from the payment id and the
  invoice, instead of `createRefund()`'s random one: every attempt at the one full refund — the
  first, the queued retry, a re-delivery that finds nothing queued — has the same idempotency
  key and metadata. A re-delivery that finds an invoice this service booked, no credit note and
  nothing queued also asks Mollie first (an earlier run may have died after the call).
- **The log says what is true:** "queued for retry" only when an entry is there; otherwise
  "could not be queued — posted on the next webhook delivery" (the queue write itself failed).
- **`refunded_net` is written after the credit note, not before.** Found by the lost-answer
  test: `createRefund()` raised the counter, then the credit note's write failed, and the retry
  that adopted the refund added it again — `refunded_net` at twice the payment (5800 for 2900).
  The counter is now written once the credit note is persisted; `effectiveRefundedNet()`
  already derives from credit notes, so a lost counter write stays harmless.

Red before: `tests/Feature/Webhook/UnwantedPaymentRefundThrowRetryTest.php` — 4 of 4 red against
the unfixed service ("actual size 0 matches expected size 1": nothing queued), the lost-answer
case red again on `refunded_net` 5800 vs 2900 until the counter moved behind the credit note.
`GrantExtrasGiftGoneRefundTest` › "queues the refund when createRefund() throws…" and "does not
refund a gift-gone purchase twice…" — red against the unfixed `GrantExtrasPurchaseRefund`.
Real API: `tests/SandboxBrowser/UnwantedPaymentRefundRetryContractTest` pays on Mollie's hosted
test page, fails the credit note after the real refund, and checks that Mollie lists the refund
with its token metadata and that the retry adopts it (one refund at Mollie) — passed on
26 November 2026 (14 assertions): Mollie lists the refund's `metadata.refund_token`, and the
retry booked the credit note on the existing refund without a second `CreatePaymentRefund`.

Judgement call: the "earlier run" flag reaches `refund()` as a property set by `settle()`, not
as a new parameter — `refund()` is protected and overridden (a test subclass already did), so
a changed signature would be a fatal error for an app's subclass. Not covered: a first payment
whose activation failed (invoice booked by the handler, not by this service) does not ask
Mollie on a second `settle()` with nothing queued; the stable token still replays within
Mollie's one-hour key window.

### `plans.*.tier` is the app's field, not the package's

Nothing in the package reads it; up- and downgrade are derived from the prorata amounts.
`billing:check-config` therefore *warns* when it is missing and errors only when a value is
present but not an integer — failing a working config over a field that changes nothing
blocked deploys for no reason.

### Publishing the views does not override Livewire components

Livewire 4 resolves a namespaced component from the single view path registered with
`Livewire::addNamespace()`; its finder has no notion of a vendor override, and the API
takes one path. So a published `⚡…blade.php` is inert. This is a Livewire constraint, not
a choice — the supported seams are the services (rebind them), the events, and the facade
callbacks, none of which requires touching a view. Documented in the README so nobody
spends an afternoon editing a file that is never read.

### The admin routes auto-load, and that is switchable

They are tenant-agnostic, so registering them from the service provider is safe and saves
every app a route file. But `MollieBilling::adminRoutes()` exists for apps that want their
own middleware stack, and calling it does not *replace* the auto-loaded routes — same
URIs, and the ones registered first match first. `mollie-billing.routes.auto_load_admin`
is what makes the second option real.

---

## Judgement calls from the most recent review round

These were reported as defects, judged, and decided. Each is a choice with a plausible
alternative; they are listed so the alternative is argued rather than rediscovered.

| Decision | Reasoning |
|---|---|
| Seat minimum is `max(included_seats, used_seats)` | A seat picker that lets an operator go below the seats already occupied produces an invoice for fewer seats than the tenant is using, and nothing reconciles it afterwards. `ValidateSubscriptionChange` throws `SeatDowngradeRequiredException` rather than silently clamping, so the caller decides what to tell the user. |
| `plan_change_mode` governs only requests that move plan or interval | Applied to every request it also deferred seat and addon changes, which have their own flows and their own prorata. The mode is about *when a plan change takes effect*, and `SubscriptionUpdateRequest::$applyAtExplicit` keeps an explicit caller's choice from being overridden. |
| `recurring_discount_net` is 0 when no application remains | A trial start consumes no coupon application, so the naive count left the Mollie subscription carrying a discount the renewal handler would not honour — and `PaymentAmountMismatch` fired on a payment nobody had asked to discount. |
| `trial_converted_at` is stamped as its own marker | Trial conversion cannot be derived after the fact from status transitions alone, and the trial-conversion KPI needs a window. A marker is cheap; reconstructing it from the audit trail is not. |
| `getDownloadUrl()` is not gated on `hasPdf()` | The controller regenerates a missing PDF on demand. Gating the link hid the only path that repairs it, so an invoice whose render failed once became permanently undownloadable. |
| The one-pending-change rule keys on an explicit re-entry flag | It used to infer "internal re-validation" from both prorata amounts being 0 — which is also true of a switch between two equally priced plans, so exactly that switch walked past the guard while another change's payment was in flight. `SubscriptionChangeContext::$isPendingReapply` says it outright. |
| A stuck country correction escalates after 14 days, not 24 hours | `pending` is where a SEPA Direct Debit lives for several business days. Escalating cancels the customer's subscription and mails the admins that the reissue failed — for a payment that is simply still travelling. The figure now matches the overage sweeper's for the structurally identical case. |
| A PDF line uses the booked VAT, falling back to the rate | The renderer recomputes `net × rate` when given only a percentage, and the stored figure is not always that number — a partial credit note allocates its VAT across the lines it reverses. `unit_tax` is multiplied by the quantity, so it is used only where the stored amount divides evenly; everything else renders exactly as before. |
| `RetryRefundLineJob` keeps `releaseAfter(30)` | Reported as a hazard: every release counts against `tries`, so a blocked sibling could dead-letter a refund line without ever calling Mollie. The numbers do not support it — `expireAfter(600)` caps one holder at 20 releases against a `tries` budget of 50, and the sibling that *did* choose `dontRelease()` (`RetryUsageOverageChargeJob`) has 3 tries, not 50. Lengthening the interval would also delay every legitimate retry behind a fast sibling, and no test can distinguish the two without real queue timing. Revisit if a dead-lettered line is ever observed with no Mollie attempt recorded. |
| A coupon is redeemed after the money moved, not reserved before it | Reported twice (first-payment/mandate-only activation, one-time orders): two checkouts can both pass the lock-free `validate()`, both get paid, and the second `redeem()` then finds the campaign full — the customer keeps what they paid for. That is the trade. Redeeming *before* the payment consumed coupons against subscriptions that never came into existence, and refusing activation *after* it takes a customer's money and gives them nothing. The overshoot is bounded by checkouts genuinely in flight at once, and every failed redemption alerts the admins (`FirstPaymentCouponPricing::compensateFailedRedemption()`); `OneTimeOrderHandler` books the paid amount ("the payment wins"). A reservation ledger would close it and is a feature, not a fix. Per-billable limits are a different matter and *are* enforced before payment — see `StartSubscriptionCheckout::couponEligibleForPaidCheckout()`. |
| A `onetimeonly` product paid twice in parallel is delivered twice | Two checkouts opened before either settles each produce a paid payment. The flag is checked when a checkout is started, against paid invoices; making fulfilment refuse the second would keep money for goods not delivered, and an automatic refund is a product decision. Not changed; an app that needs a hard limit listens for the paid order and refunds. |
| "Apply now" on a scheduled change works under `plan_change_mode = end_of_period` | Reported as a customer bypass of the mode, including an early prorata refund on a downgrade. The plan-change component's docblock records the opposite as the bug it fixed: the button used to throw under that mode and the catch then deleted the customer's change. The mode governs how a change is *booked*; whether the customer may pull their own booked change forward is a product question this table does not settle — **open for the maintainer**, unchanged until decided. |
| The cleanup job keeps `BillingScope`; the lifecycle sweeps do not | The scope is documented as the static "this row is not a billable" filter (staff in a shared `users` table), and those rows look exactly like orphans — so the one job that deletes must not remove it. The sweeps only act on rows that carry a subscription, and stay scope-free so a request-dependent scope cannot hide a lapsed subscription from them. |
| Trials are exempt from the lapsed-period guard | The guard's premise is that a renewal webhook keeps `nextBillingDate()` in the future. A trial's anchor is the mandate-capture time and Mollie charges at the trial end instead, so a trial longer than one interval walks past it with nothing wrong. Nothing is given away: nothing was paid for the period, so a prorata of 0 is the right answer. |

---

## Configuration the package's correctness depends on

Two settings that live outside `mollie-billing.php` and decide whether the guards in here
actually guard anything. Both are checked by `billing:check-config`, because neither breaks
at boot and so nothing else would say a word.

### The cache store has to hold a lock across processes

`UpdateSubscription`, `RefundInvoiceService` and `SyncSeatsJob` each serialise on a
`Cache::lock()`. That is what replaced the long transaction they used to hold — see the
invariant above — so those locks are now the only thing between two concurrent plan
changes, two refund clicks, or two seat deltas. On the `array` store the lock lives in one
process's memory and a second process takes it too; on `null` every caller gets it. Point
`CACHE_STORE` at redis, memcached, database or file.

### bavix's wallet lock is separate, and defaults to `array`

`wallet.lock.driver` protects a balance update against a concurrent one. Its default is the
per-process `array` store, so a request recording usage and the worker charging the overage
can interleave on the same wallet — and that balance is what this package bills from. Set
`WALLET_LOCK_DRIVER` to a shared store.

`wallet.cache.driver` is *not* a correctness concern: the package never writes a balance
directly, only through bavix's own `deposit`/`withdraw`/`forceWithdraw`, so bavix's
invalidation applies whichever driver is configured. See the refuted finding below.

### Mollie says 410 Gone, not 404, for something that was removed

Measured against the sandbox, not inferred: an unknown id answers **404**, while a revoked
mandate, a deleted customer, that customer's payment list and a subscription whose customer
was deleted all answer **410**. Revoking an already-revoked mandate throws rather than
being idempotent.

They are different questions — "never existed" versus "existed and is gone" — and both are
definitive. Any code that treats only one of them as final leaves a stale id or a permanent
"maybe" behind, which is exactly what `MollieSubscriptionGate` and
`CleanupOrphanedBillablesJob` did. `tests/Feature/Billing/MollieGoneResourceTest.php` pins
both, and pins the other half too: a 503 must *not* be read as "gone", or one bad night
orphans a live subscription.

### A lost create-payment answer is marked, never guessed

`ChargeUsageOverageDirectly::charge()` writes the snapshot, posts the payment and only then
stores the id, so a throw in between leaves `payment_id: null` for two very different reasons:
the request never arrived, or it arrived and the answer was lost. Locally they are
indistinguishable and both readings cost money — reading it as *charged* silences the debt
forever, reading it as *not charged* charges the customer twice, because the payment settles
into its own invoice under the webhook's per-payment idempotency and a plan-change line is
never credited back (`settles_wallet: false`).

So the third state is explicit: `usage_overage.payment_unknown_at`. Every path that would
otherwise re-derive the debt asks `paymentMayExist()` first, and only
`CleanupStalePendingOverageChargeJob` may resolve it — against Mollie's payment list for the
customer, never on a guess. An idempotency key is *not* sufficient here: Mollie caches one for
one hour, which covers the retry ladder (`[60, 300, 900]`) and not the nightly sweep. The same
applies to every payment of a dunning cycle, not only its first — see
[A dunning retry is a new payment](#a-dunning-retry-is-a-new-payment-and-the-snapshot-says-which-one-is-live-25-november-2026). See
`docs/usage-billing.md` for the four outcomes and
`tests/Feature/Wallet/LostOveragePaymentReconciliationTest.php` for the pins.

Two figures in that flow are deliberate. The reconciliation waits **one hour**
(`RECONCILE_UNKNOWN_AFTER_HOURS`), not the job's 72, because the 72 buy patience for a delivery
that is still coming and here nothing is — while the marker stands, this billable's overage
cannot be charged at all, so a three-day wait freezes money for nothing. And the key format was
measured rather than reasoned about: `tests/Sandbox/MollieRequestContractTest.php` sends a key of
the exact production shape (class-name backslashes, ISO-timestamp colons) to the real API and
asserts the replay, plus the 400 for a reused key with a different amount.

## Refuted findings

Reported, investigated, and **not** changed. Listed with what the investigation showed, so
the same note does not come back.

### A coupon-expiry retry does not fail when the Mollie ids are missing

Reported as a lost retry: `updateRecurringAmount()` logs and returns when the billable has
no Mollie customer or subscription id, so `RetrySubscriptionPatchJob` clears its marker as
if the PATCH had landed. The package never clears `mollie_customer_id`, and an empty
`mollie_subscription_id` means there is no Mollie subscription left to carry the discounted
amount. Neither state is one a retry can repair — throwing would only spend a day of
backoff and then page the admins about a subscription that does not exist. The same skip
applies on the plan-change branch, deliberately.

### The wallet balance is not read from a stale cache

The probe that "proved" it — raw-updating `wallets.balance` and then debiting — was
measuring bavix's *deferred* deposit inside the `RefreshDatabase` transaction, not a cache
miss. The `forget()` that followed from the misreading broke 27 tests. Reverted.

### The country-mismatch block on checkout does not trap anyone

Reported as a possible redirect loop: checkout carries `billing.country-resolved`, which
redirects a billable with an open mismatch to the portal dashboard. Driven end to end, it
does not loop — `index`, `invoices` and `billing-data` are deliberately outside that
middleware group so the correction modal stays reachable. It cannot loop even if an app
applies `billing.active` to those routes: `RequireActiveSubscription` passes through for
any route under the billing name prefix before it looks at the subscription at all.
Blocking a *new* booking while a mismatch is open is the stated intent — booking with
unreviewed VAT data only creates more invoices the correction flow has to refund and
reissue.

### `SingleChargeHandler` cannot write a zero-rated invoice for a VAT-bearing charge

Reported as a money defect: `chargedVatRate()` returns `0.0` from its `catch`, and
`pinVatRate()` stamps that onto every line through `??=` (0.0 is not null), so the comment's
promise to "leave the lines unpinned" is not what the code does. True as written — and
unreachable. `VatCalculationService::calculate()` has exactly one throw,
`NonEuCountryException`, and it is decided by the country alone; the reverse-charge branch
reads persisted state and never VIES, so there is no transient failure to catch. A country
that makes the probe throw makes `InvoiceService::createForPayment()` throw at the same
`calculate()` call, which has no `catch` — so no invoice is written at 0 %, the webhook 500s
instead, and Mollie re-delivers. And that country cannot be reached either: the only two
package paths that persist `billing_country` are `WebhookSupport::ensureBillingCountry()`
(writes `checkout_country_fallback`) and `CountryMatchService::resolve()`, which is guarded by
`assertCountryIsInvoiceable()`. The same reasoning refutes the companion finding against
`OneTimeOrderHandler::ensureBillingCountry()` for "a non-EU country with no
`additional_countries` entry".

### The reservation takeover's null branch is unreachable, not broken

Reported as a permanent 503 loop: `reservationIsAbandoned()` treats a null `received_at` as
abandoned, and the takeover then builds `where('received_at', null)`, which SQL compiles to
`received_at = null` and never matches — so `$won` stays 0 and every delivery is refused. The
SQL reasoning is right; the state is impossible. `received_at` is `$table->timestamp(...)`,
i.e. NOT NULL, `firstOrCreate()` always writes it, and nothing else ever sets it to null. The
null branch is a defensive read of a column that cannot hold null, and a `whereNull()` fix
could not be given a test that fails without it.

### `prorationIsUsable()` does not need an ordering check on explicit boundaries

Reported as billing the whole balance as excess when a caller passes an old period whose end
is not after its start. `BillingPolicy::prorataFactor()` returns 0.0 for that pair
(`prorataPeriodDays()` answers `total => 0`) and clamps `remaining` at 0, so the factor stays
inside [0, 1] and the degenerate pair reads as "period fully elapsed" — entitlement is the
*full* old quota, which bills less excess, never more. The one case where everything becomes
excess is `oldIncluded === 0`, and for a plan with no included quota that is the right answer.
The derived path's guard exists for a different reason: there, a zero elapsed fraction means
the derivation itself was corrupted by an overwritten period start.

### `applyRecurringMarker()` takes the interval argument its callers pass

Reported as a signature violation — four arguments passed to a three-parameter method. It
has four: `?string $interval = null`. An artifact of reviewing `src/Services/Webhook` without
`src/Services/Billing` in scope.

### `sellerCountry()` cannot return an untrimmed country

Reported as a domestic B2B supply invoiced at 0 %: `isIsoAlpha2()` trims before validating, so
`' AT '` passes, and the untrimmed candidate is then handed to
`CountryResolver::isoFromVatPrefix()`. The premise is that the whitespace survives — it does
not. `isoFromVatPrefix()` opens with `strtoupper(trim(...))`, so what `sellerCountry()`
returns is already normalised and `reverseChargeApplies()`'s `===` comparison holds.

### `PlanChangeIntent` stores a class name, and resolves it scope-free

Two findings, same file, both already closed in this PR. `toArray()` writes
`$this->billable::class`, not `getMorphClass()` — with a comment saying why, since an alias
fails `class_exists()` in the retry job. And `fromArray()` resolves with
`withoutGlobalScope(BillingScope::class)`, so an app-defined `applyBillingScope()` cannot hide
the row from a queue worker. The `??=` guard in `SweepPendingRetriesJob` therefore keeps an
FQCN, never an alias.

### `CancelSubscription::handle()` accepts a reason

Reported as a `Error: Unknown named parameter $reason` swallowed by a local catch, so no
past-due billable would ever be auto-cancelled. The signature is
`handle(Billable $billable, bool $immediately = false, ?string $reason = null)` — the reason
argument was added in this PR together with the audit requirement.

### The trial-ending day count is already aligned to UTC

Reported as an off-by-one from `$trialEndsAt->copy()->startOfDay()` using the instance's own
timezone. `trial_ends_at` is cast to `UtcDatetime`, so `getBillingTrialEndsAt()` cannot return
a non-UTC instance and both day boundaries are taken in the same zone.

### `amount_unknown` is defined in both shipped locales

Reported as a raw translation key reaching customer emails. It is `resources/lang/en/notifications.php`
line 6 and its German sibling.

### The stale `@phpstan-ignore-next-line` markers are not stale

Reported as failing the analysis under `reportUnmatchedIgnoredErrors`. There is no analysis:
PHPStan is neither a dependency nor configured in this repository. The markers are inert, and
`$payment->refunds()` on an SDK object is exactly the kind of call that would need one again
if PHPStan were added.

### `RefundHandler` does not over-credit across refunds in one delivery

`refundChildren()` is memoised, and `creditNetForGross()` does read it, so the reasoning
looks sound. It is still wrong: the handler carries `refunded_net` forward inside the loop
and `effectiveRefundedNet()` takes the maximum of that column and the credit-note sum, so
the stale snapshot is compensated. A test that sends two refunds in one delivery whose sum
exceeds the invoice passes with and without the "fix" — which is what refuted it. The test
stayed, as a guard and as a record: see
`tests/Feature/Webhook/WebhookRobustnessTest.php`.

### `BillingTime::billingDay()` exists, and its contract fits every caller

Reported as "critical" by a review that saw `resources/` only: the dashboard calls a method
that does not exist. It does — `Support\BillingTime::billingDay(?CarbonInterface): ?CarbonInterface`,
a UTC copy of the day, `null` in, `null` out. Every caller passes a `?CarbonInterface`
(`getBillingTrialEndsAt()`, `nextBillingDate()`, `getBillingPeriodStartsAt()`,
`getBillingSubscriptionEndsAt()`, `MollieSubscriptionSnapshot::$startDate`) or a parsed
`Carbon`, and chains `?->translatedFormat()`. The dashboard's `with()` runs in every
`Livewire::test('mollie-billing::dashboard')` of the feature suite and in the browser suite.

### A plan-screen upgrade that costs 0 € gross is not sent to Mollie as a payment

Reported: `confirmAndPay()` lets a fully coupon-covered upgrade through to
`UpgradeLocalToMollie::handle()`, which would create a payment of 0. It does not: the guard
refuses 0 € without a code, and `handle()` sends a 0 € gross under a code through the
`mandate_only` authorisation, exactly as the checkout does — see
[Open decisions for the maintainer](#open-decisions-for-the-maintainer), point 4, and
`FullCoverageUpgradeFromFreeTest`.

### `canChargeBillingOverage()` already answers false while past due

Reported against the usage page: the method should check past due. It does, as its first
condition (`! $this->isBillingPastDue()`), since
[Further overage is blocked in past due](#further-overage-is-blocked-in-past-due); pinned in
`PastDueUsageAndAccessTest`.

---

## The November 2026 audit

A full pass over the package found 24 defects, each reproduced by a test that failed on the
unfixed code. What was decided while fixing them is recorded here, by area. The "known
limits" at the end of each area are findings that are real and were **not** closed — they
are open work, not accepted behaviour.

### Period end and next charge are two dates, and the second one is stored

For an ordinary subscription Mollie charges when the period ends, which is why
`nextBillingDate()` served as both for so long. A discount that covers the whole recurring
amount separates them: Mollie cannot charge 0, so the subscription keeps its full price and
its start date is pushed past the discount instead. The *period* still turns over every
interval; the *charge* sits months away — and that deferred date existed at Mollie only.
Everything that later touched the charge date worked from the period end and was wrong in
both directions:

- a seat or addon bought under a 100 % coupon PATCHed the new amount without a start date.
  Mollie keeps a start date it is not sent (measured:
  `tests/Sandbox/MollieChargeDateContractTest.php`), so the purchase was free until the
  coupon ended;
- a period extension computed `period end + N days`, which lies *before* the deferred date,
  and Mollie — holding the full price there — collected it while the coupon was running. A
  trial extension had the same defect.

`subscription_meta.charge_deferred_until` is the one stored answer, owned by
`Support\ChargeDeferral`: written wherever the charge is deferred (`CreateSubscription`,
`MollieSubscriptionPatcher`), read by everything that sets or reasons about a charge date.
An amount that is no longer fully covered returns the charge to
`ChargeDeferral::regularNextBillingDate()`; an extension under a deferral is appended to it
and leaves the period alone (`deferNextChargeDate(…, periodFollows: false)`), so the days
are added after the coupon rather than taken out of it. Subscriptions deferred before the
value was stored are recognised from their marker.
`tests/Feature/Coupons/FullCoverageChargeDateTest.php` pins all of it.

One consequence is deliberate: when a customer under a deferral that carries extension days
then buys something paid, the charge returns to the regular cycle and the extension days on
the *plan* are gone with the deferral. Keeping them would need a second, per-line charge
date, which Mollie's single-amount subscription cannot express.

### A period Mollie does not charge for is still renewed

No charge means no renewal webhook, and the webhook is what moves the anchor and refills the
wallets of a Mollie subscription. Under a fully covering coupon the first period therefore
never ended: the included quota was credited once for the whole coupon, and
`nextBillingDate()` slid into the past, where `guardPeriodNotLapsed()` refused every seat and
addon change with "wait for the renewal" — a renewal that was months away.

`PrepareUsageOverageJob` case A0 is that renewal: for an Active Mollie subscription whose
charge is deferred past the period boundary it collects the ending period's overage, moves
the anchor to the boundary itself (not to midnight, so the cycle does not drift) and refills
through `WebhookSupport::creditPeriodQuota()`. It matches on "due", not on "tomorrow", so a
period that slid before the case existed is caught up on the first night — credited once,
because quota is not owed in arrears. `tests/Feature/Coupons/FullCoveragePeriodRollsTest.php`.

No redemption row is written for such a period, as before: a redemption records a discount
on a charge, and there is no charge.

### The first invoice states the period its payment bought

`FirstPaymentHandler` and `LocalToMollieUpgradeHandler` write the invoice *before*
`CreateSubscription` puts plan, interval and anchor on the billable, and
`FirstPaymentArtifacts::persist()` passed no period — so `createForPayment()` read whatever
the billable held before: nothing (falling back to "now + 1 month", also for a yearly
plan), the Local period being upgraded out of, or the anchor of a subscription that expired
months ago (the expiry pass wipes plan and interval and keeps `subscription_period_starts_at`).

`BillingInvoice::currentPeriodLines()` matches a line by its period end with a day of
tolerance, and `ProrataComposer` prorates over the line's own window. So a yearly signup or
an upgrade from a Local plan produced an invoice the refund machinery could not find — a
downgrade in the first period charged the new plan in full and credited nothing — and a
re-checkout after expiry was prorated over a window several periods long.

`persist()` now passes `now → now + 1 interval` explicitly, which is the window
`CreateSubscription` anchors a checkout at. Same shape as the renewal handler and
`MandateOnlyPaymentHandler`, which already stated their period.
`tests/Feature/Webhook/FirstPaymentInvoicePeriodTest.php` pins the three cases.

### A refund is booked only if it can pay out, and un-booked if it does not

`RefundHandler` booked every refund Mollie lists on a payment, whatever its status: 5.00
refunded plus 5.95 `failed` booked 10.95 of credit. And a refund booked while `pending`
stayed booked when the bank bounced it.

Now: `failed` and `canceled` are never booked. A refund that *was* booked — by this handler,
by `RefundInvoiceService` or by a plan change, all of which book at creation — and is later
reported in one of those states is reversed by `InvoiceService::reverseBookedCredit()`.

- **A credit note is reversed, not deleted.** It is a tax document with a serial number.
  The reversal is a `Refund`-kind row with its own `CR-` serial, positive amounts and
  status `paid`, whose lines mirror the credited ones and carry `reverses_credit: true`.
  `BillingInvoice::creditSign()` is the single reader of that flag; `derivedRefundedNet()`,
  `creditedPerOriginalLine()`, `creditedVatTotal()` and `currentPeriodLines()` subtract
  where they used to add. The flag, not the amount's sign, decides: those readers have
  always summed magnitudes.
- **`queued` is booked.** It has not flowed yet and can still be cancelled — but a refund is
  booked at creation everywhere else in the package, and Mollie is not known to call again
  for every intermediate step. What makes booking early safe is the reversal. A refund with
  no `status` at all is booked too (older stubs, and nothing to decide on).
- **Absence from Mollie's list reverses nothing.** Only an explicit `failed`/`canceled`
  does: the list is paginated, and "not on this page" must not undo a credit note.
- **Wallet units are not clawed back.** An overage refund that credited units and then
  failed leaves them in the wallet — they may have been used. `RefundReversed` is
  dispatched and the admins get `AdminRefundFailedNotification`; the operator decides.
- **Two changes in `MollieWebhookController` were unavoidable.** A failed refund returns
  `amountRefunded` to what it was, so (a) `syncMollieCredits()` also runs the handler when
  a refund is still on the books although Mollie reports no refunded amount
  (`RefundHandler::hasStandingRefundBookings()`), and (b) `outcomeAlreadyProcessed()`
  compares against the *latest* processed outcome instead of any earlier one — the
  delivery that has to undo the booking is otherwise byte-identical to the original
  `…:paid` and was deduped away. Every delivery fetches the payment live, so "equal to the
  latest processed state" is the precise meaning of "duplicate". When a state recurs, the
  older row with that signature is replaced (the pair is unique).

`tests/Feature/Webhook/RefundStatusSyncTest.php`.

**Not measured against the sandbox:** that Mollie keeps listing a failed/canceled refund
and calls the payment webhook when a refund fails. The fix is correct if it does and inert
if it does not; a `tests/Sandbox` probe would settle it.

### The invoice archive's tiles net a reversal against the credits (26 November 2026)

The portal's invoice archive sums three tiles: *total paid* (every `paid` row with a positive
gross), *credits* (every `refunded` row with a negative gross) and *spent* (the balance). A
reversal document — a `Refund`-kind row, status `paid`, positive amounts — matched the first
rule: after a chargeback that was disputed and won, a renewal paid once read "total paid
23.56" for 11.78, and "credits 11.78" for a credit that no longer stood. *Spent* came out
right only because the two errors cancelled.

**Rule:** *total paid* counts charges only (no `Refund`-kind row); a reversal is netted
against *credits* (never below zero), and *spent* is the balance of the two. The rows
themselves are unchanged — the reversal is listed with its own `CR-` serial, positive, as
the document it is. Found by `RefundDeepTest` (browser, chargeback and reversal);
`tests/Feature/Portal/InvoiceArchiveTilesAfterReversalTest.php`, red before (both cases),
green after.

### Plan-change refunds share the per-invoice refund lock

`InvoiceService::createRefund()` posts its refunds line by line and writes the credit note
carrying their `mollie_refund_id` only after the last call. Mollie calls the payment
webhook as soon as the first refund exists; `RefundHandler` found no id on record, booked
it as "synced from Mollie dashboard", and `createRefund()` then booked it again —
`effectiveRefundedNet()` read 50.00 for 30.00 returned. The webhook reservation cannot
help: that delivery is for the *original* payment, not the one being processed.

`createRefund()` now takes `billing:invoice-refund:{id}` — the lock `RefundInvoiceService`
and `RefundHandler` already share — per line, keyed on the invoice that holds the payment
(the settlement root for a plan-switch document), and holds it until the credit note is
committed; the PDF render happens after release. No transaction is involved, so the Mollie
calls stay outside one.

- **It waits three seconds, then queues.** `RefundInvoiceService` refuses the loser
  outright because an operator can click again. This path runs inside a plan change whose
  charge has already settled, so a line whose invoice stays locked goes to
  `pending_refund_retries` and `RetryRefundLineJob` posts it later, re-reading the
  remaining balance.
- The webhook that loses gets `RefundInProgressException` → 500 → Mollie re-delivers, and
  the refund id is on record by then — when the Mollie call answered. When its answer was
  lost, see the next entry.

`tests/Feature/Refund/PlanChangeRefundWebhookRaceTest.php` forces the interleaving
deterministically (the webhook arrives inside the second line's Mollie call).

### A refund line whose answer was lost is recognised, not paid again

A refund line whose Mollie call threw (a read timeout after Mollie had created the refund)
went to `pending_refund_retries` with its token and no refund id. Mollie's payment webhook
for that refund then booked it as "synced from Mollie dashboard", and `RetryRefundLineJob`
posted the line again: under the same key Mollie replayed the refund and a second credit
note was written for it (80.00 credited for 40.00 returned); once the headroom clamp changed
the amount, or the retry came after Mollie's one-hour key cache, the key was new and the
customer was paid a second time; and with the invoice used up by the sync, the line failed
"Nothing left to refund" for seven days and dead-lettered as "permanently failed" — for a
refund that had gone out. The token alone protected nothing past the first hour.

Now, in `InvoiceService::createRefund()`, under the per-invoice refund lock:

- **The token goes to Mollie as refund metadata** (`refund_token`), and `RefundHandler`
  stamps it onto the credit note it books for that refund (the document is deferred, so it
  is on the note before `CreditNoteIssued` runs).
- **A line whose token is already on a credit note is settled** — not posted, not failed,
  not re-queued — whoever booked it. Checked before the headroom, so a used-up invoice
  does not turn a paid refund into a dead letter.
- **A line whose earlier call threw is looked up at Mollie first.** It carries
  `refund_outcome_unknown` (`ProrataLine::$refundOutcomeUnknown`, serialised only when
  set); the payment's refund list is read through `MolliePaymentCredits` and a refund
  carrying the token (not `failed`/`canceled`) is adopted and booked at the amount Mollie
  paid, instead of posting again. The same rule as "A lost create-payment answer is
  marked, never guessed": a key is not enough once a retry ladder runs past the hour.
- **A refund id Mollie returns that is already on a credit note is not booked again** — a
  replay of a refund the webhook synced from a note without a token (one created before the
  metadata was sent).

Only a line that is *known* to have been sent is looked up, so a first attempt costs no
extra call. **Not covered:** `ProrataChargeHandler`'s resume of a run that died between the
Mollie call and the credit note still relies on the key (within the hour) — that line is not
in `pending_refund_retries` and carries no flag.

`tests/Feature/Refund/RefundRetryAfterLostAnswerTest.php`.

### A listener cannot fail a refund that has been paid

`CreditNoteIssued`, `InvoiceRefunded` and `RefundProcessedNotification` are sent after the
money has left Mollie and the credit note is committed. A synchronous app listener that threw
there reached the caller: `RetryRefundLineJob` failed and the queue retried the same line —
clamped to what was left, so under a new idempotency key: a second payout with nobody
involved — and the admin modal ("the payment provider rejected the request") and
`SupersededRenewalRefund` ("could not be refunded automatically") reported a refund as failed
that had gone out, while the customer's notification was skipped. An operator retry was then
a new Mollie refund, because `already_refunded_net` in its key had moved.

Now every such announcement goes through `InvoiceService::announceBooked()` (credit-note
documents, `createRefund()`, `RefundInvoiceService` phase 4), which reports a listener's
failure instead of propagating it — the rule `WalletCredited` already follows. The customer
notification in phase 4 is guarded on its own, so a failing event listener does not skip it.
`InvoiceRefunded` in the webhook handlers is left as it is: a throw there is a 500 and a
re-delivery that the dedup absorbs, not a misreported refund.

`tests/Feature/Refund/RefundListenerFailureTest.php`.

### A chargeback on a refunded payment is reported, once

A SEPA payer can reverse a debit the merchant has already refunded. `creditNetForGross()`
then finds nothing (or only the rest) left to credit, and `ChargebackHandler` logged a
warning and moved on: no credit note — correct, the invoice is already credited — but also
no operator mail, no event and no record, so every re-delivery logged again and nobody
disputed the chargeback with the proof of the refund, which is the one way to get the
second payout back.

Now the part of a chargeback beyond what was still creditable (`uncreditedGross()`, measured
only when something had been credited before, with the cent of reconciliation slack
tolerated) is reported: `ChargebackOnRefundedPayment` (audited) and an operator mail naming
the payment, the chargeback id, the amount and the part already refunded. Once per
chargeback — its id goes into `subscription_meta.chargebacks_on_refunded_payment` through
`MutatesBillingMeta`, since a chargeback with nothing left to credit leaves no credit note to
dedup on. **Judgement calls:** no zero-amount credit note (a tax document for nothing), and
no `past_due` for a chargeback with nothing left to credit — the payment had been given back,
so there is nothing to dun for. A partial one (rest credited) goes past due as before.

**The report cannot stop the past-due transition (26 November 2026).** It runs inside the
booking loop, before `markPastDue()`. A throwing `ChargebackOnRefundedPayment` listener or
mail transport made the delivery a 500; the re-delivery found the chargeback booked (its
credit note carries the id), booked nothing, and returned before `markPastDue()` — Active
access on money the bank had pulled back, no `PaymentFailed`. The event now goes through
`InvoiceService::announceBooked()` and the mail is guarded the same way: a failure is
reported, never propagated. *Judgement call:* the id is noted before the send, so a failed
mail is not retried (at most once, like every other operator mail here); the warning logged
just before it carries the same facts. *Not changed:* `InvoiceRefunded` in this handler stays
unguarded, as the section "A listener cannot fail a refund that has been paid" decided for
the webhook handlers — note that for a chargeback its re-delivery does not reach
`markPastDue()` either. "puts the billable past due although the report … fails"
(listener, mail), red before (status stayed Active).

`tests/Feature/Webhook/ChargebackAfterRefundTest.php`.

### OSS scope follows the VAT charged; credit notes inherit their original's validation

`OssProtocolService::scopeFor()` labelled a row `reverse_charge` whenever the invoice
pointed at a valid VIES validation. `vat_validation_id` only says which validation was on
file when the document was written — also when the calculation refused reverse charge, and
on a plan-change credit note it was the billable's validation *on the day of the refund*.
A consumer sale at 19 % whose buyer later registered a VAT number and downgraded had its
credit note filed under `reverse_charge`.

Both sides:

- The scope is `reverse_charge` only when the validation is valid **and** the rate group
  carries no VAT (rate 0, VAT 0). Decided per rate group, like the buckets, because a
  plan-switch document can hold credits at the original's rate next to charges priced under
  today's classification.
- `createRefund()`'s credit note takes `vat_validation_id` from the invoice it credits (as
  it already did for country and currency), like `createCreditNote()`.

**Judgement call:** `createPlanSwitchInvoice()` and `createForPayment()` still stamp the
*current* validation. Both are charge documents priced under today's classification, so
today's validation is the one they rely on; the plan-switch document's credit lines keep
the original's rate on the line, and the per-rate-group scope files them correctly.

`tests/Feature/Vat/OssScopeFollowsChargedVatTest.php`,
`tests/Feature/Refund/PlanChangeRefundWebhookRaceTest.php` (validation inheritance).

### Known limits, not changed — webhook, invoices, refunds

| Finding | Why it stands |
|---|---|
| `ProrataChargeHandler` derives `seat_count` from the charge invoice and falls back to the included seats when the seats line is missing | Only reachable with an unpriced or sub-cent seat line. |
| The renewal writes back the `seat_count` it read at the start | A pure interleaving with `SyncSeats`; no deterministic reproduction. |
| An invoice may book one cent more than Mollie collected | That is the documented reconciliation slack; no real trigger found. |

### A country correction re-issues what remained paid, and moves no units (25 November 2026)

`resolve()` refunds what is left of each linked invoice at the old country's rate and charges
it again at the new one. Three things went wrong around that:

- **The charge cloned the original at full value.** A refund made before the resolve — a
  goodwill credit, a refund by line, a plan change's credit — leaves less to refund, but
  `issueCorrectionCharge()` re-charged the whole invoice: 2000 net with 500 given back was
  refunded 1500 + AT VAT and charged 2000 + DE VAT, so the goodwill was collected again over
  the mandate. An invoice refunded *in full* before the resolve (a cancellation, an unwanted
  payment) was skipped by the refund step and still charged in full.
  `CountryCorrectionEffectiveRefundTest` asserted that ("being already refunded is no reason
  to skip the reissue") against a spy that never looked at the amount; it now asserts the
  opposite.
- **The refund credited an overage invoice's units back.** `refundFully()` returns the
  units of an overage invoice, which is right for a refund of the service and wrong for a
  tax re-issue: the usage was consumed and is charged again right after, so the wallet
  gained the units for free.
- **The reissue's lines carried `parent_invoice_id`.** That key marks a credit against
  another invoice, and every reader of a document's *own* charges skips such lines
  (`originalLineParts()`, `ProrataComposer::paidLineNet()`). A reissue therefore had no
  charge lines to refund by: a full refund credited 0 % VAT and paid back only the net, a
  dashboard refund booked the VAT on no document, and it offered nothing to refund by line.

**Rules now:**

- The reissue charges what remained paid. Its lines are the original's at the new rate plus
  one negative `refunded` line per original line that was credited before the correction,
  at that line's new rate and naming it in `applies_to` — so it reads, folds and prorates
  like any invoice with a reduction. An invoice with nothing left is not re-issued at all.
  `InvoiceService::creditedOutsideCorrection()` computes it.
- *Judgement call:* the correction's own refund is recognised by its credit note's reason
  (`RefundReasonCode::BillingError` with `CountryMatchService::CORRECTION_REFUND_REASON`),
  not by new state. A resolve that refunded an invoice and stopped before charging it (another
  invoice's refund threw) still owes the whole reissue on the retry, and every correction
  credit note ever written carries that reason, so the rule holds for history too. An
  operator who books a manual refund with exactly that reason and text is read as the
  correction — that is the one input that defeats it.
- *The correction's own refund keeps its reason when the webhook books it (26 November
  2026).* When the refund call lost its answer (or phase 3 failed), `resolve()` threw, the
  mismatch stayed Pending, and Mollie's payment webhook booked the refund as "synced from
  Mollie dashboard", reason Other. The retry then read it as a refund made before the
  correction: nothing remained paid, nothing was charged again — the customer kept the
  whole refund. `RefundInvoiceService` now sends `reason_code` (and `reason_text`, up to
  500 bytes — Mollie takes 1 KB of metadata) as refund metadata, and `RefundHandler` books a
  refund carrying it under that reason; a dashboard refund has none and is booked as
  before. The metadata is set through a property for the length of `callMollieRefund()`,
  whose signature is an override seam (tests and apps replace it) and stays unchanged; an
  override that ignores it books as before. `CountryCorrectionLostRefundAnswerTest`, red
  before (no charge sent).
- The correction refunds money only (`refund()` with no `wallet_credits`), never units.
- Reissue lines reference the corrected invoice through `corrects_invoice_id`, like the
  undercollection's `completes_invoice_id`. *Not migrated:* reissues written before this
  change keep `parent_invoice_id` on their lines and keep refunding net only; refund one of
  those with explicit `line_items` carrying `vat_amount`.

`CountryCorrectionRechargeBasisTest` (partial refund before → 17.85 not 23.80; full refund
before → no charge; the correction's own earlier refund → full charge; overage → wallet
unchanged) and `CountryCorrectionReissueRefundTest` (full refund pays 119.00, dashboard
refund maps to 19.00 VAT, lines offered), red before except the retry control.

### The portal's confirmation names what the correction refunds (26 November 2026)

The country-fix modal says "*n* invoice(s) totalling *x* will be refunded and reissued". It
counted every invoice linked to the mismatch and summed their original gross, while
`resolve()` refunds only what is left of each and skips an invoice with nothing left. Found
by the browser suite: Pro + Softdrinks (40.34) with the Softdrinks line refunded (5.83)
before a second renewal (40.34) flagged the mismatch — the modal promised 80.68, the
correction refunded 34.51 + 40.34 = 74.85. An invoice refunded in full before was counted
and summed although nothing happens to it.

**Rule:** the figure is `CountryMatchService::refundsOnResolve()` — the same targets
`resolve()` corrects (reissues excluded), only those with a remaining refundable net, each
with that net plus `InvoiceService::creditNoteVat()` on it, which is what
`RefundInvoiceService` pays back for a refund without an amount. Still grouped per currency.
*Judgement call:* the count follows the same set, so it counts invoices that will actually be
refunded and reissued, not every invoice the mismatch was flagged against. The operator's
resolve modal ("*n* invoices will be refunded and reissued") counts the same set, for the same
reason; each linked invoice stays visible on the invoices tab.

`Feature/Vat/CountryFixModalNamesRefundsTest` (partly refunded → 74.85 not 80.68; refunded in
full → "1 invoice(s) … 40.34", and 1 in the operator modal), red before;
`Browser/CountryCorrectionDeepTest::test_a_partly_refunded_invoice_is_reissued_at_what_remained_paid_not_at_its_original_value`.

### Every writer of `country_corrections` re-reads it under the lock (25 November 2026)

`CountryCorrectionHandler::failed()` saved the whole `subscription_meta` of the instance it
was handed. The hourly sweeper hands over a billable loaded a chunk and several Mollie round
trips earlier, so the save deleted every key written since — a correction a re-resolve had
just issued among them. That payment, once paid, found no pending state:
`createCorrectionInvoice()` threw, `paid()` logged and returned, and the webhook answered 200
for money on no document; the next resolve then charged the same supply period again.
`issueCorrectionCharge()` had the mirror image: it wrote its snapshot of the map (taken
before its Mollie call) over the one read under the lock, and brought back an entry the
previous charge's paid webhook had just cleared — the dashboard kept showing a pending
re-charge and the sweeper polled a paid payment every hour, because `paid()` returned on the
existing invoice without clearing the entry.

**Rules:** both go through `mutateBillingMeta()` and touch only their own key; `paid()`
clears a stale entry when the reissue already exists; and `paid()` *rethrows* a failed
booking, so the webhook answers 5xx and Mollie re-delivers (the reissue's payment id keeps
that idempotent). The sweeper catches it per payment and tries again next hour, so one
payment cannot stop the sweep. `CountryCorrectionMetaRaceTest`, red before.

### A serial's year is the year's last digits, one per `Y` (25 November 2026)

`Y` is documented as one digit slot per character, so `YYYY` is how an operator asks for the
full year. `InvoiceNumberGenerator` read the two-digit year and padded it to the slot: every
invoice and credit note said year `0026`. A single `Y` received two digits, which shifted
the counter one character off the offset `extractCounter()` reads it from, so the next serial
was computed from the wrong characters. The slot now takes the last *n* digits of the
four-digit year (`YY` → 26, unchanged; `YYYY` → 2026; `Y` → 6).

*Behaviour change:* an install that already issued `0026` serials continues in a new range
(`IN-2026-00001`); the old serials stay unique and nothing collides. `InvoiceSerialRangeTest`,
red before.

### A prorata credit is the unused share of what was paid, not of the list price

An invoice line states the list price of its component. Whatever reduced the payment sits on a
negative line of its own: a recurring coupon, or the `adjustment` that books a renewal down to
what Mollie collected. The composer prorated the credit from the line, so a period bought with a
50 % coupon (plan 2900, coupon −1450) and left on day 16 of 31 credited 2900 × 16/31 = 1497 —
capped by the per-invoice pool at 1450, the whole payment, for a period half used. The pool
bounds the invoice total, never the share.

`ProrataComposer::paidLineNet()` spreads the reductions over the charge lines in proportion
(`list × (charges − reductions) / charges`) and both refund builders start from it. Lines with a
`parent_invoice_id` are credits against another invoice (the saldo-zero switch document) and
reduce nothing. `tests/Feature/Subscription/ProrataCreditPaidShareTest.php`.

Judgement calls:

- **Proportional, not per-line.** A coupon is not attributed to "the plan" even where it was
  priced against the plan alone; the invoice does not record that, and a proportional spread is
  the only reading that needs no guess.
- **A positive adjustment never raises a component above its list price.** A surplus is money
  collected for something else (see `SupersededRenewalRefund`); it is refunded as such, not
  through the prorata of a line.
- **A line whose paid share is 0 is `isCouponCovered`**, exactly as a line with `amount_net = 0`
  already was: it has quantity but no cash to give back.

### A free plan with paid addons is prorated like any paid plan

`isFreePlan()` asks about base and seat price. The composer used it as "nothing bills here" in two
places: a free **target** skipped every charge line while the refund side credited all current
addons (a kept, paid addon was refunded and then used for free until the renewal — 610 net in the
test), and a free **origin** returned `[]` (an addon added mid-period was never charged, one
removed never credited). The origin shortcut is now scoped to `isLocalBillingSubscription()` — the
one case it was written for, Free → paid through `UpgradeLocalToMollie` — and the target gate is
gone: the charge helpers already return null for a component the target does not price.
`tests/Feature/Subscription/FreePlanPaidAddonProrataTest.php`, on the composer and through
`UpdateSubscription::update()`.

Judgement call: a downgrade onto a free plan that keeps a paid addon now has a charge line, so it
takes the deferred path (the switch waits for that charge) like every other plan change with one.
Netting the kept addon out instead ("neither credit nor charge it") was considered and rejected —
it is a second pricing rule for one combination, and `PlanChangeFullRefundTest` pins "credit the
whole old configuration, charge the whole new one" for everything else.

### A scheduled change is announced to Mollie when it is scheduled

Mollie creates a recurring payment with the amount the subscription holds at that moment (a SEPA
debit about two days ahead), and a PATCH reaches only the next payment to be created. The
dispatcher applies a scheduled change once `scheduled_change_at <= now` — at or after the charge
date — so the PATCH it made reached the renewal *after next*. The one in flight settled at the old
amount, the period had lapsed (factor 0, no credit), and Pro → Starter booked for the period end
cost Pro for the first Starter period, with `PaymentAmountMismatch` as the only trace.

`ScheduleSubscriptionChange::schedule()` now PATCHes Mollie to the target amount after its
transaction commits and records `scheduled_change.mollie_prepatched`; `cancel()` PATCHes back.
`SubscriptionPaymentHandler::paid()` calls `applyAtRenewal()` before pricing, so the renewal is
invoiced as the new configuration, with no prorata (`SubscriptionUpdateRequest::asPeriodBoundary()`).
`tests/Feature/Subscription/RenewalChargesTheChangedConfigurationTest.php`.

Judgement calls:

- **Applied with the renewal, not only by the dispatcher.** With the pre-patch alone, a card
  renewal arrives at the new amount while the billable is still on the old plan: it was invoiced
  as the old plan plus an adjustment, and the dispatcher then applied the change mid-period with a
  prorata on top — a scheduled *upgrade* was charged its difference twice. The hook only acts on a
  change that was pre-announced (or bills nothing), is due within two days, keeps the interval, and
  only for an Active or Trial billable; it never throws.
- **No wallet rebalance at the boundary** (onto a paying configuration). The renewal's own period
  refill is the right semantic there; rebalancing first settles a period that has simply ended and
  then grants the new quota twice for a rollover type.
- **A free target is not announced.** There is no amount to patch to, and cancelling ahead of time
  could not be undone if the customer withdraws the change. It is applied with the renewal and that
  renewal refunded in full.
- **An interval change is not announced.** Moving the cadence is the patcher's business and cannot
  be done ahead without moving the charge date. It stays with the dispatcher.
- **A failed pre-patch is not retried.** `RetrySubscriptionPatchJob` would discard it as superseded
  (the billable is not on the target yet). The change is still applied; a renewal at the old, higher
  amount is refunded down by `SupersededRenewalRefund`.
- **`realignScheduled()`** re-announces a stored change after an immediate seat/addon change PATCHed
  the current configuration's amount over it.

### A renewal created for a configuration the customer has left is refunded, not just reported

`SupersededRenewalRefund::settle()`, called from `SubscriptionPaymentHandler::paid()` after the
invoice exists. The payment's own metadata names the plan/interval/seats/addons it was created for.
Different from the local configuration and more collected than it costs → the `adjustment` surplus
is refunded through `RefundInvoiceService` (credit note, `refunded_net`, Mollie refund) and the
payment is booked as the renewal it is. Bought nothing — Local billable, or another interval — →
refunded in full and **not** booked as a new period.

Judgement calls:

- **One-sided.** A renewal that collected *less* than the current configuration costs is not topped
  up: charging from inside a webhook is a different decision from giving money back. This is the
  remaining gap for a seat added after Mollie already created the next debit.
  **Superseded on 16 November 2026** — the shortfall is charged automatically; see
  [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
- **Metadata must be present and must disagree.** Without it nothing is refunded; a mismatch whose
  metadata agrees with local state (failed PATCH, coupon) stays with `PaymentAmountMismatch`.
- **A refund that cannot be posted is logged and mailed to the admins, never thrown** — the renewal
  is invoiced either way, and a 500 would only make Mollie re-deliver a payment already booked.

### An interval change that settles after a renewal credits that renewal

Phase 2 of an interval change used the refund lines stored in Phase 1. If a renewal of the old
interval was booked in between (SEPA: the new interval's charge takes days, and Mollie's cadence
only moves in Phase 2), nothing credited it: a month and a year paid for the same days.
`ProrataChargeHandler::paid()` composes the refund lines again when the period anchor moved after
the pending change was created. They *replace* the stored ones — the period Phase 1 saw has since
run to its end on the old configuration.

### A seat or addon change moves Mollie in the same call as the local state

`UpdateSubscription` wrote `seat_count`/`active_addon_codes` at once but left the PATCH to Phase 2,
i.e. until the prorata charge cleared. With SEPA a renewal in between was collected at the old
amount while the seat was active for the whole new period (2900 instead of 3400 in the test). The
PATCH now runs in phase 2c of the same `update()`, and `ProrataChargeHandler::failed()` PATCHes
back together with the local restore. The comment that claimed these changes "reach Mollie
immediately anyway" was describing this, not the code. Plan and interval changes are unchanged:
they switch locally only when their charge clears and keep their PATCH there.

Judgement call: Phase 2 still PATCHes for a seat/addon change. It is the same amount and therefore
idempotent, and it repairs a first PATCH that failed without waiting for the retry job.

### An accepted change supersedes the last one's outcome (26 November 2026)

`plan_change_failed_at` (+ `_reason`) and `plan_change_unfinished` were only ever written.
The plan page reads them as "how did the change I am watching end" and looks only at their
age — 24 hours for a declined charge, 7 days for one the clean-up gave up — and the addon
page reads the stamp for 30 minutes. So the customer whose upgrade charge was declined, who
did as told and upgraded again ten minutes later and paid, was told on the poll that saw the
change land that the payment had failed and to try again, with the danger callout beside the
new plan for the rest of the day; a later change after an unfinished one was announced as
"could not be completed — do not start it again". A successful addon after a declined one
was a "payment failed" toast.

**Rule:** the markers describe the latest attempt. `UpdateSubscription::runUpdate()` drops
them (`SubscriptionMetaHygiene::PLAN_CHANGE_OUTCOME_KEYS`) once a new change has passed
validation and its coupons — in its own short locked write (`MutatesBillingMeta`), and
*before* any Mollie call, so a new charge declined at once writes its own stamp after the
drop, never under it. A refused change leaves them. The record of the earlier attempt is the
`PlanChangeFailed` event, the audit log and the operators' mail, which this does not touch;
for an unfinished change the operators also keep `orphaned_prorata_payment`. Clearing the
unfinished notice when the customer goes ahead anyway is deliberate: the page reports the
change the customer is watching, and the earlier one is the operators' to settle.
`tests/Feature/Portal/StalePlanChangeOutcomeTest.php`: the paid retry, the unfinished
predecessor and the addon red before; the declined retry (still reported as declined) green.

### Known limits, not changed — plan changes and proration

- **Plan change (same interval) whose SEPA charge spans a renewal.** Local switch and PATCH both
  wait for the charge, so they do agree with each other — but the renewal in between is collected
  and booked for the old plan, and Phase 2 then switches plans mid-period without settling that
  period. Closing it needs a second prorata in Phase 2 (a further charge for an upgrade), which is
  a new money flow rather than a fix.
  **Superseded on 16 November 2026** — that further charge exists now; see
  [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
- **`CleanupStalePendingProrataChangeJob`** clears a pending seat/addon change whose charge failed
  without restoring the local state. Local state and Mollie now at least agree in that case (both
  on the new configuration); the unpaid prorata remains.
- **Seat auto-derive on a plan change** — `max(currentSeats, used, included)` turns the old plan's
  included seats into paid extra seats on a plan that includes fewer. The preview shows the same
  number, so it is a product question, not a preview/charge disagreement. `docs/plan-changes.md`
  describes `max(used, included)`.
- **`ScheduleSubscriptionChange::schedule()` filters `addons` with `(int) $q > 0`**, which empties a
  *list* of codes (`(int) 'storage'` is 0). Pre-existing; the pre-patch reads both shapes.

### A period reset never zeroes a debt that is still owed

The period-end sweep has exactly one chance to charge a period's overage — the night
before `nextBillingDate()` — and every way of missing that night used to end in the same
place: the renewal found a negative balance with no charge in flight and reset it to the
new quota. Two of those ways are ordinary operation, not accidents:

- **A trial.** `nextBillingDate()` is mandate capture plus one interval, a 14-day trial
  converts long before it, and `debit()` allows overage for the whole trial because the
  billable holds a mandate (`canChargeBillingOverage()` is true). Overage during a trial
  was free. Worse, it was inconsistent: the same customer cancelling the trial gracefully
  *was* charged, because `subscription_ends_at` becomes the trial end and Case B fires.
- **A renewal that settles ahead of the sweep night.** The anchor is the previous
  renewal's `paidAt`. With SEPA that trails Mollie's fixed collection date by a lag that
  varies; when this period's lag is more than a day shorter than the last one's, the
  webhook arrives first, the anchor jumps, and `renewsTomorrow` never matches the period
  that just ended.

A night on which the sweep did not run is the third, and loses the overage of everybody
renewing the next day.

What applies now, in two layers:

1. `SubscriptionPaymentHandler::paid()` calls `WebhookSupport::collectOutstandingOverage()`
   before the refill. It is `ChargeUsageOverageDirectly::handle()` — in-flight guard
   included, so it is a no-op when the sweep did its job — and from the moment the charge
   exists the rest is the mechanism that was already there: the snapshot makes the reset
   preserve the debt, the settlement credits it back, a failure runs the dunning ladder.
2. `WebhookSupport::shouldPreserveDebt()` replaces the bare `hasUnsettledOverageFor()` as
   the reset's question: a debt is kept when a charge is collecting it **or** when it is
   uncharged but collectable (mandate, and an overage price on the current plan or a
   recorded one). `PrepareUsageOverageJob::resetLocalQuota()` asks the same question.

Pinned by `tests/Feature/Webhook/TrialConversionCollectsOverageTest.php` and
`tests/Feature/Webhook/RenewalCollectsUnchargedOverageTest.php`.

Judgement calls in it:

- **Charge at the renewal, rather than only carry the debt.** Carrying alone pays the
  debt out of the next period's quota, which is not the same as collecting it, and leaves
  nothing for the dunning ladder to act on. Charging first re-uses the snapshot, the
  retry job and the reconciliation job unchanged. The carry is the fallback, not the fix.
- **Metered overage in a trial is revenue.** "A trial is free, including what is added
  during it" covers seats, addons and quantities — things with a recurring price that
  the conversion charges in full anyway. A metered unit consumed past the grant has no
  later charge that would cover it.
- **A cycle that gave up stays written off.** Status `failed` is neither re-charged by
  the renewal nor carried through the reset. That is the documented behaviour and it is
  unchanged; re-charging would be a fourth attempt, a day after three failures, on the
  back of the very renewal that recovers the billable from past due.
  **Superseded on 16 November 2026** — the debt stands and the next renewals try again,
  three times at most, after three days of rest; see
  [An overage nobody could collect stands, and the renewals try again](#an-overage-nobody-could-collect-stands-and-the-renewals-try-again-case-10).
- **The fallback carry is paid in units.** A debt carried without a charge (the paid
  trial conversion in `ProrataChargeHandler`, which does not run the charge step; a
  charge attempt that threw before writing a snapshot) lands under the new quota:
  −37 + 50 = 13. Only what exceeds the quota stays negative for the next sweep. Giving the
  units away was the alternative.
- **The sweep's trigger was not widened.** With the renewal collecting for itself the
  one-night window is no longer what stands between a debt and a reset, and a wider
  window would only add nights on which the in-flight guard has to say no.
- **An existing fixture was corrected.** `RenewalCountryMismatchTest` debited 40 units
  from a wallet it had never credited and called that "spent most of the period's quota".
  It was 40 units of overage, and the test expected the renewal to reset it away. The
  fixture now credits the quota first.

### Plan-change excess keeps the price it was quoted at

`WalletPlanChangeAdjuster` prices unresolved excess with the **old** plan's overage price
— it is consumption on the old plan, and it is what the preview shows. When the charge
could not be created (`restoreUnchargedExcess()`: the single overage slot was occupied, or
the call failed before posting), only the *units* went back on the wallet, and the next
sweep multiplied a bare negative balance by the current plan's price. 97 units quoted at
2 ct were billed at 9 ct — 8.73 EUR instead of 1.94 — and where the new plan sells no
overage the line was skipped and the next reset forgave the debt.

The units now carry their price: `wallet.meta.deferred_overage`, a list of
`[quantity, unit_price_net]`. `ChargeUsageOverageDirectly::buildLineItemsFromWallets()`
bills those first, as their own line marked `deferred_price`, and prices only the
remainder from the current plan. Pinned by
`tests/Feature/Wallet/PlanChangeDeferredExcessPriceTest.php`, both directions.

Judgement calls in it:

- **On the wallet, not on `subscription_meta`.** The debt is a property of one wallet's
  balance and has to move with it. Next to `purchased_balance`, which is the same kind of
  fact, it needs no migration and no second lock.
- **Clamped on read, not trusted.** Every reader sees the record cut down to the wallet's
  current negative balance, and every credit, debit and reset rewrites it. A record that
  could outlive its debt would re-price *new* overage at the old plan's rate the next
  time the wallet went negative.
- **Consumed at settlement, by price.** The clamp alone is not enough: usage accruing
  while the charge settles keeps the wallet negative afterwards, and those new units
  would inherit the old price. So `SingleChargeHandler::settleOverageWallets()` removes
  exactly the units a `deferred_price` line billed. Consuming at charge *creation* was
  the alternative without touching that handler, and loses the price when a cycle fails.
- **A second plan change carries it.** In `adjust()` the recorded units are added to the
  in-flight units: taken out of the excess and kept as debt. Read as consumption on the
  plan being left they were prorated against the wrong quota and re-priced a third time.
  Netting against the new quota still applies, as it does for in-flight units: a wallet
  at 10 − 97 = −87 is billed 87 at the old price and the customer has paid the other 10
  in quota.
- **A free plan with a surviving mandate is charged.** `PrepareUsageOverageJob`'s Local
  case now charges *recorded* debt before its reset — and only that; a Local wallet's
  other negative balances are still nobody's quoted price and are reset as documented.
  `chargeDeferredOverage()` already charged a billable with a mandate regardless of
  source, so this is the same rule one period later rather than a new one.

### The plan-change preview quotes the excess the change bills (26 November 2026)

`WalletPlanChangeAdjuster::adjust()` keeps two kinds of debt out of the excess it prices:
units an in-flight period-end overage charge is collecting, and excess an earlier plan change
left on the wallet with its price (`deferred_overage`). Both are part of the negative balance
and neither is consumption on the plan being left. `PreviewService` read the bare balance and
quoted them again. Pro → Starter the morning after the sweep's SEPA charge for 30 SMS: the
screen said 50 units at 15 ct would be charged, the change carried the 30 and billed 20 —
the customer confirmed a figure 4.50 net too high, and "used" read 70 for a period in which
40 were used.

Both now ask one method, `WalletPlanChangeAdjuster::carriedDebtUnits()`, and the preview
subtracts it from the excess and from the "used" figure. Pinned by
`tests/Feature/Wallet/PlanChangePreviewCarriedDebtTest.php` (both kinds of debt, red before:
50 quoted against 20 billed, 40 against 15) and `tests/Browser/UsageDeepTest.php` (the
downgrade on the plan screen while the sweep's payment is open, then that payment and the
next sweep: the excess is billed once, at the old plan's 15 ct, as a `deferred_price` line).

Judgement call: the preview still lists the excess under what is due now although, with the
overage slot taken, it is collected by the next sweep instead (`restoreUnchargedExcess()`). The
amount and the price are right, and so is the line: the customer owes it. *When* it is
collected is now said (decision 8 of 26 November 2026): `PreviewService` sets
`usageOverageAwaitsChargeInFlight` from `ChargeUsageOverageDirectly::chargeIsInFlight()` — the
same test `handleExplicit()` refuses on, read-only and without its warning log — and the plan
screen adds, under the overage lines of both "due now" blocks, "The overage will be charged
with the next debit, once the current one has completed." (de: "Der Mehrverbrauch wird mit der
nächsten Abbuchung berechnet, sobald die laufende abgeschlossen ist."). A pending snapshot
whose payment already has its invoice is not in flight — `handleExplicit()` charges then — and
the sentence stays away. The figures are deliberately left in the total: taking them out
would quote a "due now" that is lower than what the customer owes for the change. Pinned by
`tests/Feature/Wallet/PlanChangePreviewOverageInFlightTest.php` (red before: the flag was
absent and neither sentence rendered) and by the dusk element
`overage-after-charge-in-flight` in `tests/Browser/UsageDeepTest.php`.

### Without a mandate the plan-change preview says the change is refused, not "charged later" (26 November 2026)

Reported as: the preview without a mandate does not say the overage is charged later.
Measured, it is not charged later at all. `ValidateSubscriptionChange::validateWalletUsage()`
refuses an immediate change whose excess the new plan cannot absorb when the billable has no
mandate (`DowngradeRequiresMandateException`); `WalletPlanChangeAdjuster::chargeDeferredOverage()`'s
`no_mandate` branch (`restoreUnchargedExcess()`) sits behind that check and is reached only
if the mandate disappears between validation and charge. A change scheduled for the period
end is accepted, but `ScheduleSubscriptionChange` applies it through `update()` as
`immediate`, so it is refused there for the same reason and dropped as `not_available`.
The preview quoted the excess under "due now" and offered both buttons; the confirm then
answered with the generic error toast.

So the truthful text is a refusal, not a later date. `PreviewService` now runs the
validator's own check — split out as the public
`ValidateSubscriptionChange::assertUnresolvedOverageIsChargeable()`, which
`validateWalletUsage()` delegates to, so the two cannot disagree — for a plan or interval
change, sets `usageOverageRequiresMandate` and adds the blocking error
`usage_overage_requires_mandate`. The plan screen renders
`portal.error_usage_overage_requires_mandate` (dusk `change-refused-overage-needs-mandate`)
and disables the button; `applyChange()` answers a refusal that gets past the preview with
the same sentence. Judgement call: both options are blocked, scheduling included, because a
scheduled change would be refused when applied unless a mandate arrives first — and with a
mandate the customer can change at once. Not covered: a seat-only change on the same plan
runs the same validator check; its screen is not the plan screen and is left as it was.
Pinned by `tests/Feature/Wallet/PlanChangePreviewOverageNoMandateTest.php` (red before,
with the `PreviewService` hunks removed: 5 of 6 failed — the flag was absent and neither
sentence rendered; the with-mandate control passed both ways) and by
`tests/Browser/UsageDeepTest.php::test_a_downgrade_with_excess_and_no_mandate_is_refused_on_the_plan_screen`
(red before: waited 15 s for `change-refused-overage-needs-mandate`; green after, no payment
created, plan unchanged).

### The admin billable header asks Mollie for the mandate once per page load (26 November 2026)

The header refresh (`billing-admin-billable-changed`, see "Admin billable header follows the
tab's actions") re-renders the page after every tab action, and `mandate()` called
`MollieMandateInspector::inspect()` each time — one extra `GetMandateRequest` per admin
action, for a status or plan refresh that cannot change the mandate. The page now keeps the
summary as a locked `MandateSummary::snapshot()` together with the mandate id it was fetched
for, and asks Mollie again only when `mollie_mandate_id` differs (including none → some). A
full page load starts empty and always fetches, so a mandate revoked at Mollie shows on the
next load. A failed lookup (null) is kept too: the header says "unavailable" until a reload,
as it did for that render before. Pinned by
`tests/Feature/Admin/BillableHeaderMandateFetchTest.php` (red before: 2 GetMandate
requests after one tab action instead of 1; the second test — a changed id fetches, no id
does not, every full load does — passed before and after, guarding against over-caching).
That a real mandate survives the kept snapshot unchanged is measured against the Mollie test
API in `tests/Sandbox/MollieRequestContractTest.php::test_it_reads_a_mandate_the_way_the_inspector_expects`.

### A change waiting for its payment is called a change, not an upgrade (26 November 2026)

Any plan or interval change whose pro-rata charge exceeds its credit waits for that charge —
not only an upgrade. Pro → Starter with eight extra seats is a downgrade by base price
(`BillingPolicy::isUpgrade()` is false) and still collects more than the Pro month it
replaces. The plan screen announced every one of them as "An upgrade to the :plan plan is
awaiting payment confirmation", and the toast/callout for a declined charge — shared with the
addons screen, where no plan is involved at all — as "Your plan upgrade could not be
completed". Decision 7: neutral wording. `portal.pending_plan_change_notice` is now "The
change to :plan is awaiting your payment." / "Der Wechsel zu :plan wartet auf deine Zahlung.",
and `portal.flash.plan_change_failed` "Your change could not be completed because the payment
failed. …" / "Deine Änderung konnte nicht durchgeführt werden, da die Zahlung fehlgeschlagen
ist. …". Keys and placeholders are unchanged, so nothing that references them moved; the
dashboard shows neither text. Pinned by
`tests/Feature/Subscription/PendingChangeNeutralWordingTest.php` (red before in both locales:
the old upgrade sentences rendered), and rendered in the browser by
`PendingChangeCancelTest` / `ChangesInFlightTest`, which assert the keys.

### `billing:sync-purchased-balance` clamps at a plan change too

The replay clamps the running purchased total wherever the live bookkeeping does. It knew
about renewals and not about plan changes, where `WalletPlanChangeAdjuster` writes
`min(purchasedRemaining, finalBalance)`. A 500-unit pack with 490 used before an upgrade
to a 1 000-unit plan was therefore measured only at the very end, against the new plan's
balance: 500 "purchased", 490 of them plan quota, re-credited on top of every later reset.
`plan_change_reset`, `plan_change_credit` and `plan_change_pending_overage` are clamp
points now, and leaving a clamp point also caps the total at the balance it left behind —
a plan change that nets an excess against the new quota can leave less than it found.
Pinned by `tests/Feature/Commands/SyncPurchasedBalanceAfterPlanChangeTest.php`.

### A dunning retry is a new payment, and the snapshot says which one is live (25 November 2026)

Three findings about the second and third payment of an overage dunning cycle, all with
one root: after a payment failed, `usage_overage.payment_id` went on naming it while
`RetryUsageOverageChargeJob` asked Mollie for the next one, and the idempotency key was the
cycle's alone.

- **The retry was answered with the failed payment.** A card refuses within minutes, inside
  the hour Mollie keeps a key, and the next payment's request had the same key and the same
  parameters as the failed one's — so Mollie replayed p1. The job stored p1 again, reported
  *Charged* and stopped; p1's webhook had already come. One attempt per cycle instead of
  three, the snapshot `pending` until `CleanupStalePendingOverageChargeJob` wrote it off 72 h
  later, and `OverageBillingFailedNotification` never sent, because only `giveUp()` sends it.
  (SEPA was spared only because its failure arrives after the hour.) Every test fake ignored
  the key, which is why the suite expected three payments seconds apart.
- **A retry that could not create its payment was lost.** With p1 in the slot,
  `markPaymentUnknown()` saw "an id is there" and set nothing, pass 3 skipped the row for the
  same reason, and the stale sweep polled p1, found it failed and deferred the cycle. If the
  create had in fact reached Mollie (answer lost), that payment p2 settled later on top of the
  units `deferFailedCycle()` had credited back: the customer got them for free.
- **The stale sweep's own cancellation started a new cycle.** It cancels a payment stuck for a
  fortnight and defers the cycle; Mollie's `canceled` webhook for that payment then ran the
  retry job, which never looked at the status and charged the deferred lines at once —
  without `RECOLLECT_AFTER_DAYS`, and counted as one of `MAX_RENEWAL_RECOLLECTIONS` although no
  renewal had come.

What applies now:

1. `payment_id` names the payment **in flight**, nothing else. `persistPendingCharge()` moves
   an id still in the slot to `usage_overage.previous_payment_ids`
   (`ChargeUsageOverageDirectly::PREVIOUS_PAYMENTS_KEY`) when the next payment is asked for.
   So a create that throws is marked `payment_unknown_at` like a cycle's first one, and the
   whole "a lost create-payment answer is marked, never guessed" rule holds for every payment
   of a cycle. The dunning budget is unaffected: it counts ids in
   `usage_overage_counted_payments`, which the job writes before it retries.
2. The key carries the payment before it: `usage-overage:{class}:{id}:{cycle}:{gross}:after:{id}`
   (no suffix for a cycle's first payment, which keeps its measured shape). It moves exactly
   when a new payment is meant, and stays put across the queue retries of asking for that one.
3. `CleanupStalePendingOverageChargeJob` skips the cycle's earlier payments when it looks a
   lost one up (same metadata, same amount, same window — p1 matched), and for a later payment
   waits `RECONCILE_UNKNOWN_AFTER_HOURS` from the marker, not from the cycle's start. Measured
   from the cycle it reconciled mid-ladder, and a queued re-entry of the retry job then found
   the adopted payment in the slot, counted it as failed and created the next one.
4. `RetryUsageOverageChargeJob` returns at once for `usage_overage_status = failed`. A cycle
   that is over is the renewals' to collect again; nothing else in the job's reach may start
   one.

Follow-up (26 November 2026, review finding): moving p1 out of the slot also removed the
only terminal path of a retry Mollie refuses *for good* — a SEPA payment that failed with
MD01 and an invalidated mandate behind it. Before, the sweep polled p1 after 72 h, found it
failed and deferred the cycle. Now the slot was empty, `reconcileUnknownPayment()` found
nothing (p1 skipped as a previous payment), dropped the marker and **returned**, so the
`HARD_LIMIT_DAYS` write-off for "no payment could be created" — whose own comment says it
exists to end exactly this daily retry — was unreachable whenever a marker stood, and pass 3
re-marked it every night: three refused creates and an admin mail a day, `pending` for ever,
never past due. *Rule:* "Mollie holds no such payment" (also: no customer, 404/410) is an
answer, and the pass goes on to the no-id branch's hard limit after it. That also ends the
same loop for a cycle's *first* payment, which had it since the marker was introduced.
*Judgement:* the limit stays the cycle's 14 days, not the 72 h the p1 poll used to give —
a transient create failure after p1 must still get its nightly retries ("marks a retry whose
payment could not be created, and the nightly pass tries again"), and telling a refused
mandate from a 5xx by the error code is not settled anywhere. Pinned by
`OverageDunningRetryPaymentTest` › ends a cycle whose retry Mollie refuses for good (red
before: `pending` after three weeks of nights). *Not changed:* the sweep does not take the
retry job's `WithoutOverlapping` lock, so a retry whose create is in flight at 04:15 can
race the write-off — the same window the existing hard-limit branch always had.

Not changed: if the cancelled payment's webhook were processed *before* the sweep's
`writeOff()` committed, the retry would still see a pending cycle and create the next payment
while the sweep then defers it. The cancel call and the write-off run back to back in one
process, against a webhook that has to cross Mollie and the queue — not worth a cross-process
lock. Pinned by `tests/Feature/Wallet/OverageDunningRetryPaymentTest.php`; the long-run harness
(`tests/Support/UsageLongRunHarness.php`) now answers keys as the sandbox measured, so every
long-run dunning test exercises the key.

### Known limits, not changed — wallet and overage

- A trial that converts through a *paid plan change* (`ProrataChargeHandler`) carries its
  overage instead of charging it, and what stays negative is then priced by the new plan.
  Charging there needs a Mollie call in a handler this change does not own.
- The renewal-time charge does not run when the renewal handler returns early: a renewal
  for an ended subscription, or one delivered out of order. Neither books a new period,
  so neither resets a wallet either — the debt simply stays where it is.

### "EU" is the package's own list, not the VAT library's rule table

`VatCalculationService::calculate()` and `isInvoiceable()` decided EU membership with
mpociot's `shouldCollectVAT()`. That answers "does the library ship a rate", and the library
ships rates for Switzerland (8.1 %), the Isle of Man and Monaco as well. So Switzerland was
invoiceable with nothing configured, the reverse-charge branch was open for it, and the
country-mismatch modal — which filters on `isInvoiceable()` — offered a Swiss IP signal:
resolving to it refunded an Austrian customer's history and re-invoiced it at 8.1 %.

`CountryResolver::isEuMember()` (the EU-27, `EL` accepted for Greece) is now the only source.
The library is still where an EU country's *rate* comes from. A non-EU country is invoiceable
only through `additional_countries`.

The documented behaviour had a test all along, and it passed: it ran against a
`FakeVatCalculator` that knows four countries. `tests/Feature/Vat/NonEuCountryNeedsAdditionalCountryTest.php`
runs against the real calculator, for CH, IM and MC, and walks all 27 members.

*Behaviour change:* an install that relied on CH/MC/IM being priced implicitly, or that added
countries through `vat_calculator.rules`, must declare them under `additional_countries`.
Monaco is inside the French VAT territory in law; it is treated like any other non-member
here because the package has no notion of "taxed as another country" — declare
`'MC' => ['vat_rate' => 20.0]` if you sell there.

### Reverse charge has one decision, and "intra-EU" is inside it

`reverseChargeApplies()` called itself the single decision point, while `calculate()` kept a
condition of its own in front of it (`$isEu &&`). For a country priced through
`additional_countries` the two disagreed: `usesReverseCharge()` and the checkout said
"reverse charge, net" for a validated UK business, the charge added the configured 20 %. The
first payment collected net, the invoice was reconciled with a `PaymentAmountMismatch`, and
the Mollie subscription was created 20 % above what the checkout had shown.

The EU-member test is now the first condition inside `reverseChargeApplies()` and
`reverseChargeCountriesQualify()`, and `calculate()` adds nothing in front.
`HasBilling::usesReverseCharge()` already delegated and was not touched.
`tests/Feature/Vat/ReverseChargeAdditionalCountryTest.php`.

*Judgement call:* a billable with no billing country now gets `false` from
`reverseChargeApplies()` when no invoice country is passed (it used to fall through to the
seller-country comparison and could answer `true`). Nothing can be reverse-charged to a
country that is not known.



### A country is offered only if it can be invoiced

`checkout_countries.include` fed the selector; the VAT rate came from a different list, and
nothing tied them together. With `include => ['GB']` — the example the config file gave —
the form accepted the country, `totals()` swallowed the `NonEuCountryException` and priced
at 0 %, the payment was created, and the webhook then threw on every delivery: money
collected, no invoice, no subscription.

Three layers now, because each has a caller the others do not see:

- `CountryResolver::resolve()` leaves out an `include` code that is neither an EU member nor
  declared under `additional_countries`. Every form reads this list (checkout, billing data,
  plan change), so the `in:` rule refuses it too.
- `StartSubscriptionCheckout::handle()` probes the billable's country through
  `calculate()` before any branch that creates a Mollie payment (trial, full-coverage,
  paid) and throws `NonEuCountryException`. That covers a locked checkout, an imported
  billable, and an app calling the service directly.
- `billing:check-config` reports the `include` entry as an error.

`totals()` keeps its `catch` on purpose: it is on the render path, and a 500 while the
customer is still choosing a country helps nobody. It can no longer lead to a payment.

`tests/Feature/Checkout/UnpriceableCheckoutCountryTest.php`,
`tests/Feature/Commands/CheckConfigVatRatesTest.php`. `CountryResolverTest` asserted the old
behaviour ("include GB → GB is offered") and was rewritten.

This also retires the premise of the refuted finding *"`SingleChargeHandler` cannot write a
zero-rated invoice…"*: it argued the unbillable country "cannot be reached" because only two
package paths persist `billing_country`. The checkout submit was a third. The conclusion
stands — now because the country is refused before it is persisted.

### A VAT rate is a percentage, and check-config says so

`vat_rate_overrides` outranks every other source and is cast with `(float)`; the command
did not look at it. `additional_countries.*.vat_rate` only had to be `is_numeric()`. The
library underneath speaks decimals, so `0.19` is the mistake waiting to happen: numeric,
accepted, and every invoice for that country carried 0.19 % VAT. A string read as 0 %, a
negative rate produced a gross below the net.

Both keys now have to be numeric, within 0–100, and not strictly between 0 and 1. `0` stays
valid (a country sold to without VAT), and so does `1`. An override key that is not an
uppercase ISO code is an error too — the lookup upper-cases the country, so a lower-case key
is never read. `tests/Feature/Commands/CheckConfigVatRatesTest.php`.

*Judgement call:* the open interval (0, 1) is refused although a rate of 0.5 % is
arithmetically a percentage. No VAT regime has one, and the cost of the false negative is a
whole country invoiced at a hundredth of its rate.

### A free order is activated locally, whatever coupon is around

The zero-amount branch read "free plan AND no coupon → activate locally". A promotion link
leaves its code in the session, so a customer who arrived through one and picked the free
plan was sent to Mollie to authorise a payment method for a plan that costs nothing; the
webhook then refused the activation, because a coupon cannot cover 100 % of an order of 0.
Mandate captured, no subscription — and the mandate cost them the trial on any later paid
checkout.

When the order's net is 0 the coupon is ignored and the plan is activated. A free base plan
with priced extras and a coupon is unchanged (there the coupon is what makes the total zero).

The session key (`StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY`) is now removed
when the code reaches a definitive end: redeemed as an access grant, ignored on a free order,
dropped because the amount was not priced with it, dropped because this billable may not
redeem it — and by `PromotionController` when a later link turns out to be expired.

*Judgement call:* it is **not** removed when a payment has merely been created with it. A
customer who cancels on Mollie's page and returns must still find the promotion applied, and
the redemption happens in the webhook, which has no session. The cost is that the code can
outlive a successful signup for the rest of the session; the only readers are the checkout
component and this service, both of which are closed to a billable with a subscription.

`tests/Feature/Checkout/FreePlanIgnoresSessionCouponTest.php`.

### The trial wins over a coupon (a first-payment coupon then discounts the trial-end charge)

`handle()` tested `amount_gross === 0` before the trial branch, so a coupon covering the
whole first payment dropped `trial_days`: the confirm step had announced "14 days free, first
charge on <date>", and the customer got an Active subscription at once with the coupon spent
on the period that was meant to be free. The trial is now decided first, for any order that
costs something.

What the coupon then does is the trial path's existing rule (docs/coupons.md): a `recurring`
one writes its marker and, at full coverage, defers Mollie's start date to the later of trial
end and coupon end. A `single_payment` one is parked and not consumed.

That last rule is why the checkout form now **refuses** a `single_payment` coupon whenever a
trial will start (`coupon_not_with_trial`). A trial collects no first payment, and Mollie's
charge at the trial's end is the subscription's one recurring amount — so the form showed a
reduced total next to a first-charge date on which the full price was collected. Refusing it
is the same outcome for the coupon (it stays unspent either way) with a total that is true.
The service still carries such a code into the trial metadata if a caller passes one; the
webhook parks it as before.

*Known remainder:* with a 100 % `recurring` coupon on a trial the confirm step names the
trial end as the first-charge date, while the charge is deferred to the later of trial end
and coupon end. The customer is charged later than announced, never earlier, and the amount
shown (0) is what is collected until then.

`tests/Feature/Checkout/TrialWinsOverFullCoverageCouponTest.php`.

**Superseded on 24 November 2026: a first-payment coupon is accepted on a trial signup** —
partial discounts as well as 100 %. Refusing it left no way to give "a trial, then x % off
the first charge". The trial still wins; the coupon then discounts the first charge there
is, the one at the trial's end.

That charge is collected from the Mollie subscription's recurring amount, so the discount
goes there for exactly one period: `CouponService::applyFirstChargeMarkerFrom()` writes an
`active_recurring_coupon` marker from the coupon's `firstChargeView()` (`discount_periods`
1, no end date) with `first_charge_only`, before `CreateSubscription` — the trial path of a
`recurring` code, unchanged. Everything downstream already handled a one-period recurring
marker on a trial: Mollie created at the discounted amount, or at 100 % at the full amount
with its start date one period after the trial end; the trial-end renewal books the coupon
line and the redemption, finds the marker expired for the next charge and PATCHes Mollie
back. The rejected alternative — collect the trial-end charge as a payment of our own and
start the subscription a period later — needs a scheduled charge, its own dunning and its
own webhook branch for what the marker does with code that exists.

*Judgement calls:*

1. **The code's `valid_until` does not end the discount**, unlike a `recurring` code's.
   For a first-payment code it is the last day to *redeem* it, and the customer redeemed it
   at signup; a code valid for three more days on a 14-day trial would otherwise discount
   nothing. `firstChargeView()` drops it from the arithmetic.
2. **Deactivating the code does not withdraw it** (`isMarkerStillRedeemable()` exempts
   `first_charge_only`, as it does a plan waiver). Deactivation stops new redemptions; a
   first payment collected at once is not taken back either.
3. **`redeem(renewal: true)` accepts a `single_payment` coupon only with its own
   `first_charge_only` marker.** The renewal exemption from the caps was restricted to
   `recurring` so the flag could not buy a second redemption; the marker is the proof the
   grant exists, as it is for a recurring one. The row is marked `recurring_renewal`, so it
   is not counted as a second redemption by the per-billable limit.
4. **At 100 % there is no redemption row carrying the discount** — no charge is made for
   the free period, exactly as with a fully covering `recurring` code. The grant row (discount
   0) is the record.
5. **`pending_first_charge_coupon` is no longer written**, but the renewal handler still reads
   it: a trial started by an earlier release keeps the behaviour it was started with.
6. **At 100 % the customer is shown the charge at the trial's end, at 0.00** — on the confirm
   step (`firstChargeDate()`, notice `trial_covered_redirect_notice`) and in "Next charge"
   (`NextChargeSummary` ignores the deferral during the trial when the marker is
   `first_charge_only`). The deferred Mollie start one period later is how a free charge is
   carried out, not a later first charge: naming that date read as "first charge postponed,
   at full price", the opposite of what the code gives. Once the trial is over the deferred
   full-price charge is the next one shown. No invoice is written for the free trial-end
   charge, as for any charge Mollie never makes.
7. **The nightly pass leaves a switched-off first-payment code alone.**
   `MollieSubscriptionPatcher::endDeferralOfDeactivatedCoupon()` ends the deferral of a fully
   covering coupon that has been deactivated — right for a `recurring` campaign, wrong for
   this: it pulled the full price (23.80) onto the trial's end, the charge promised free. It
   skips a `first_charge_only` marker, as `isMarkerStillRedeemable()` does on the renewal path.
8. **A trial extension moves the code with it — a `recurring` one too.** The marker's window
   was measured from the trial end it was written for. Extended past it, a first-payment
   code expired before its charge, a two-period recurring code discounted one charge, a
   100 % recurring code started charging the old date (fewer free periods), and an amount
   PATCH in between (a seat during the trial) sent Mollie the full price.
   `extendBillingTrialUntil()` calls `CouponService::moveTrialCouponMarkerTo()` while the
   trial is still running: the window starts at the new trial end with the number of
   charges the grant was given, a recurring code's own `valid_until` still capping it, and
   at 100 % the stored deferral moves with it while it is still the window's own end. Not
   after the trial: charges the code was for have then been made (or, at 100 %, skipped).
   A plan waiver is not moved; it ends on a day. Nor is a code whose window was not measured
   from the trial end being replaced (its discount was already running when the trial was
   set): the move recomputes the window from the old trial end first and acts only on a match.
   Refinements from review (each with a test that failed before it):
   - **The deferral moves by the window's shift, not only when it sat on the window's end.**
     Days a period extension appended to it during the trial stay on top; left behind, the
     deferral fell inside the moved window and Mollie charged full price for a free charge.
   - **A past-due trial is moved too.** Its first charge failed, so no discounted charge was
     booked; "another week to add a card" must not cost the discount. The guard is "no
     redemption with a discount for this grant yet", not the status alone.
   - **A partial code the moved charge has left behind returns Mollie to the full price**
     (`MollieSubscriptionPatcher::resetCouponMissingCharge()`, after commit) — a recurring
     code whose own `valid_until` falls before the new trial end. Mollie went on collecting
     the discounted amount, and the invoice reconciled down with a payment mismatch. This was
     so before the move existed; a fully covering code is left to the charge date.
   - **The trial end is read once at activation.** The window, Mollie's start date and
     `trial_ends_at` each read the clock; an activation crossing midnight measured them from
     different days and the move no longer recognised the window as the trial's.
   - **The code's end date is kept with the grant** (`coupon_valid_until` in the marker), as
     `discount_periods` is. A date the operator gives the offer later no longer stops the
     move (by no longer matching) or caps it.
   - **"Next charge" names the free trial-end charge only up to that day.** After it, and
     before the nightly job converts the trial, the deferred paid charge is next.
   From the CodeRabbit pass before merging:
   - **A failed reset keeps the moved charge date.** The retry slot holds one obligation;
     when the date PATCH and the reset both fail, the reset carries the date's `target` and
     `RetrySubscriptionPatchJob` applies both. Overwritten, Mollie kept the earlier date.
   - **A retried trial activation reuses the trial end of its first attempt**
     (`subscription_meta.trial_activation_ends_at`, written before the Mollie call). A retry
     that adopted the subscription the first attempt created read a fresh clock, hours or a
     day after the start date Mollie held.
   - **A retried trial activation whose coupon can no longer be priced keeps the grant of
     the first attempt.** The marker it wrote stays, and Mollie is created at the amount the
     marker promises (`computeMarkerDiscount()` as of the trial end) — at full price beside
     it, the trial-end charge collected the full price while the books promised the discount.
     The kept grant is counted on that retry (`countRecurringGrant()`), as a priced one is.
     The recorded trial end is reused while it is still ahead to the moment, not the day.
   - **The window's start is kept in the marker (`window_from`)** and is what the move
     checks. Comparing ends was ambiguous: capped by the code's end date, a window started on
     a paid period and one measured from the trial end share their end. A marker written
     before the start was kept is moved only when its end identifies the start (the window
     recomputed without the code's end date ends on the stored day). After a move the new
     trial end is the start, so a second extension moves it again.

`tests/Feature/Coupons/CouponSignupMatrixTest.php` (partial, 100 %, expired and deactivated
code, each through the trial-end charge and the renewal after it),
`tests/Feature/Checkout/TrialWinsOverFullCoverageCouponTest.php`,
`tests/Feature/Webhook/TrialCouponHandoverTest.php`.

### A mandate-holding trial is left alone for 14 days, not forever

`ProcessTrialLifecycleJob` did not expire a lapsed trial that holds a mandate, because the
first charge is scheduled for `trial_ends_at` and "the webhook owns both outcomes". It owns
them only if it arrives. Mollie calls the webhook for payments and nothing else: a
subscription cancelled or suspended at Mollie, a revoked mandate, or a `failed` delivery lost
past the retry window leaves the row on `Trial` — which `hasAccessibleBillingSubscription()`
grants unconditionally, and which no other pass ever looks at again.

After `FIRST_CHARGE_GRACE_DAYS` (14 — the figure the stuck country correction and the stuck
overage charge use, for the same SEPA reason) with neither outcome recorded, the job applies
the transition it already applies to a mandate-less trial: `PastDue`, `past_due_since`
stamped, `TrialExpired`, `TrialExpiredNotification`. From there the existing auto-cancel
clock takes over. A payment that still arrives is honoured — the renewal handler accepts
`PastDue`.

*Judgement calls:* (1) `PastDue`, not a new state — it is what the lifecycle already means by
"the payment did not come", and it is the only state the auto-cancel pass picks up. (2) The
notification's wording ("no payment method was added in time") is not accurate for a
mandate holder; a dedicated mail is a feature, not part of this fix. (3) The Mollie
subscription is not cancelled here — `MollieSubscriptionGate` already handles a live or
stale one when the customer returns to checkout.

`tests/Feature/Jobs/TrialFirstChargeGraceTest.php`.

---

## Per-seat addons, plan waiver, admin extensions (DEV-380, DEV-384, DEV-382)

### A gift is a plan waiver, and a plan waiver is the recurring-discount slot

A tenant who is *given* the product (an access grant) can buy paid addons and seats on top.
The extras are charged, the plan stays free until the gift ends, and the gift can be
extended afterwards. None of that was possible: a gift was a Local subscription, Local
subscriptions cannot be charged, and the only way to a Mollie subscription threw the gift
away and charged the full plan.

`Services\Billing\PlanWaiver` is deliberately **not** a second pricing mechanism. A waiver
is a marker in the one slot a recurring coupon already lives in
(`subscription_meta.active_recurring_coupon`), shaped as a fixed discount with
`kind = plan_waiver`. Every place that prices a recurring charge already asks
`CouponService::computeMarkerDiscount()` — renewal invoice, Mollie amount, preview,
full-coverage deferral, expiry reset — so a waiver is honoured by all of them without any
of them learning a new rule. The earlier design (a separate `plan_free_periods` marker,
consumed "after" a recurring coupon) would have put a second discount with its own lifetime
next to the first in every one of those places.

What a waiver adds, and where:

- **Its ceiling follows the plan line** (`PlanWaiver::ceiling()`): the amount waived when it
  was given, capped at what the plan and the still-active granted addons cost in the
  configuration being priced. An upgrade does not enlarge the gift; a cheaper plan does not
  spill it over onto addons. Callers pricing a configuration the billable is moving *to*
  pass it as `$target`.
- **Its count is its own** (`max_applications`): the coupon behind a gift is a one-off grant
  whose `max_redemptions_per_billable` means "issued once", not "one free period".
- **A waived renewal is a plain redemption row**, never `redeem()` — that would re-run the
  coupon's own effect, which for an access grant is the grant.
- **Its invoice line says what it takes off** (`applies_to`), and
  `ProrataComposer::paidLineNet()` places such a reduction on those lines first. Spread like
  an ordinary coupon, a fully paid addon read as two-thirds unpaid and the free plan as
  partly paid: removing the addon under-credited, changing the plan credited money nobody
  had paid.

**The cost of the single slot is accepted on purpose:** a waiver and an ordinary recurring
coupon cannot run at the same time. Free periods given while a waiver runs are appended to
it; next to a recurring coupon they are refused (`recurring_conflict`). DEV-382 had asked
for "coupon first, free periods afterwards". Two discounts repricing the same charge with
separate lifetimes is exactly the interaction this package kept getting wrong, and a
refusal with a reason is something an operator can act on.

`tests/Feature/Coupons/PlanWaiverMarkerTest.php` and
`tests/Feature/Coupons/GrantKeepsRunningWithPaidExtrasTest.php` pin it end to end.

### What happens when a gift ends is decided when it is given

`grant_on_expiry` on the grant coupon, overridable per tenant
(`subscription_meta.grant_on_expiry`, set from the admin panel): `end` (default) or
`continue_paid`.

With `end`, the renewal that buys the last covered period cancels the subscription
gracefully instead of patching Mollie to the full price — the tenant was given this plan
and never agreed to pay for it. The paid addons end with it. The default is `end` because
the alternative turns a gift into a charge on its own. With `continue_paid` the ordinary
marker-expiry path runs: Mollie is patched to the full amount for the next charge.

The date rule is "a renewal is plan-free when it falls before the gift's end", so a gift
rounds up to the end of its last period. There are no partial days.

A waiver that came from the `plan_waiver` coupon type (free periods for a paying customer)
is always `continue_paid`.

### Buying extras on a gift prices itself

`UpgradeLocalToMollie::handleKeepingGrant()` computes the amount it charges. Every other
first payment takes `amount_gross` from the form that priced it; here the price depends on
what the gift waives, and that must not be something a request can submit. If the gift has
ended by the time the payment settles (SEPA), the upgrade is booked as an ordinary paid one
and nothing is waived.

The addons and seats screens start this purchase directly
(`StartGrantExtrasPurchase`). They used to answer a gifted tenant with a notice and a link
to the plan page, whose only offer was to give the gift up.

### Admin extensions leave a coupon behind, but nobody types a code

`AdminExtensionService` — extend everything by days, plan free for N periods, extend a
gift — creates a one-off coupon and redeems it in the same call. The coupon and its
redemption are the audit trail and what makes the grant list and `revoke` work. A refused
attempt deletes the coupon again, so no redeemable code is left for somebody who learns it.

A period extension cannot be revoked: it has moved a charge that may since have happened.
Revoking free plan periods removes the whole waiver, also when later periods were appended
to it — they share one marker, and a partial take-back is not something an invoice can
state.

### The feature suite does not render Flux

`<flux:…>` tags pass through uncompiled in the feature suite (Flux is under `suggest`). A
Livewire test there proves the component's logic and the text it emits, not that a tooltip
opens. The `(i)` explanations on the coupon form are therefore asserted by their text; how
they render is the browser suite's job.

### A per-seat addon counts every billed seat — and "billed" is never below the plan's included seats

The addon's seat price applies to all seats of the subscription, the plan's included ones too; the
addon's own `included_seats` are independent of the plan's. The seat count it uses is
`SubscriptionAmount::billedSeats()` = `max(seat_count, plan.included_seats)`.

That floor is not a pricing opinion. Every billing path carries seats as "extra seats beyond the
plan's included ones" — the Mollie subscription metadata, `CreateSubscription`'s spec,
`getExtraBillingSeats()` in the renewal handler — so by the time an amount is built a stored count
of 4 on a plan that includes 10 is indistinguishable from 10. `SubscriptionAmount::lineItems()`
keeps its `int $extraSeats` parameter for the same reason and derives the total as
`included + extra`; `net()` applies the same floor, so `net() === Σ lineItems()` always holds.
Changing `lineItems()` to take the total would have let a caller pass a number the rest of the
pipeline cannot reproduce at the next renewal. Pinned in `AddonSeatPricingAmountTest`.

### `addon_seats` is its own line, on its own axis

Not folded into the `addon` line or the plan's `seats` line: its quantity moves with the seat count
while the base does not, and a seat change has to credit it separately. On a seat-only change the
composer charges/credits the difference in *charged* addon seats, so the addon's free seats absorb
a change below them. `BillingInvoice::deriveSeatCount()` reads `seats`/`seat` only and therefore
ignores it; the refund-quantity whitelist in `currentPeriodLines()` includes it.

### `getBillingAddonQuantity()` multiplies the base price only

An app that returns a quantity > 1 for an addon gets `quantity × price_net` as before. The
per-seat part is `charged seats × seat_price_net`, once — "three CRM licences for twelve seats" has
no obvious meaning and nobody asked for it.

### "Is this addon free?" asks about both prices

`addonIsFree()` replaces `addonPriceNet() > 0` where that decided whether a payment instrument is
needed: `ValidateSubscriptionChange::validateLocalSubscriptionExtras()` and the addons screen's
Local block. An addon with base 0 and a seat price is refused on a Local subscription.
`EnableAddon` and `UpgradeLocalToMollie` make no such decision themselves — they go through the
validator and `SubscriptionAmount::net()`.

### Payment `type` whitelists were left alone

`MollieWebhookController`, `SingleChargeHandler` and `InvoiceService::mapTypeToInvoiceKind()` list
`'prorata' | 'addon' | 'seats'` — those are Mollie payment *metadata types*, not line kinds. Nothing
emits a payment of type `addon_seats`; the line travels inside `prorata_charge` payments.

### A scheduled change stores `addons` as a code => quantity map

Both input shapes are accepted; the list is normalised to `code => 1`. The stored payload is what
the portal and the admin panel render, and `apply()` replays it through the same normaliser as an
immediate change.

### Not covered by a test — per-seat addon pricing

- `plan-change.blade.php`: `addon_seats` lines are grouped under "Add-ons" in the pro-rata
  breakdown. The data (`prorataLines`) is tested; the rendering of that block is not.
- `addons.blade.php`: the Local block now keys on `isFree`. The server-side refusal is tested; the
  disabled button is not rendered in a test.
- `step-plan.blade.php`: the "+ price / seat" suffix on the plan cards is not asserted (the addon
  step's is).
- `MandateOnlyPaymentHandler` and `LifecycleSimulator` build their lines through the same
  `SubscriptionAmount::lineItems()` call and are not exercised with a per-seat addon.
- Access grants / `PlanWaiver`: a granted addon's waiver is derived from `SubscriptionAmount::net()`
  and so now includes that addon's seats. Not tested here (DEV-384 territory).
- A recurring coupon is tested for the discount base and for the refund share, not end to end
  through a renewal webhook with a per-seat addon.
- The browser suite was not run.

---

## Second audit round (November 2026)

A second full pass, after the plan waiver, per-seat addons and the admin extensions had
landed. Its rule: a finding counts when a test shows it red — **or when no test covers the
behaviour at all**. Every money path looked at ends with a test; what could not be tested
is listed as open. Long runs (12–24 renewals), a change matrix and a booking matrix were
built for it and stay in the suite.

### Area: renewals over many periods

#### The long-run harness

`tests/Feature/Webhook/Support/RenewalLongRun.php` is a stand-in for Mollie's subscription
engine: it keeps the amount, start date, interval and metadata last sent by
`CreateSubscription` / `UpdateSubscription`, and produces recurring payments at the amount
held *when the charge is created*, with the subscription's metadata, `createdAt` on the
cycle date and `paidAt` whenever the test says the money settled. Everything runs through
the real webhook route under `Carbon::setTestNow()`.

Every renewal is checked for: Mollie's amount == the figure the test states for that
period == invoice gross == Σ lines; no `adjustment` line; `PaymentAmountMismatch` never
dispatched; the invoice period one interval long, starting on the cycle, equal to the
billable's anchor, and within a day of the previous period's end; consecutive serials
across every charge document (restarting with the year).

Three things in it are a model and not a measurement, and are stated in the file:

- **the cadence** — run as `start + n × interval` ("clamp") and as "previous + interval"
  ("drift"); the month-end test runs both;
- **the hour a charge is created** — 06:00 UTC;
- **the wallet cache** — `wallet.cache.ttl` is set to forever, because inside
  `RefreshDatabase`'s never-committing transaction bavix's balance lives only in its
  cache, and travelling a month forward silently zeroed every wallet. Not a package
  behaviour; in production the balance is in the database. Any test that moves time by
  more than a day and then reads a wallet needs the same line.

What the sandbox confirmed (`tests/Sandbox/MollieRenewalContractTest.php`): Mollie holds
exactly the amount and start date `CreateSubscription` sends; the patcher's amount-only
PATCH arrives and leaves `nextPaymentDate` alone; amount and start date sent in one PATCH
are both in force; the subscription metadata the patcher writes is readable back; a
recurring payment carries `createdAt`, and a direct debit has no `paidAt` while it
settles; `refunds()` / `chargebacks()` exist and are empty on a fresh payment.

#### Fixed

##### A renewal's period is the cycle the charge was created for, not the day it settled

`SubscriptionPaymentHandler::paid()` anchored the new period on `paidAt`. For a card that
is the cycle date. For SEPA Direct Debit Mollie creates the charge on the cycle date and
it settles one to five business days later, a different number every month. Twelve months
with lags of 3, 5, 1, 0, 4 … days produced periods that overlapped (a month that settled
faster than the one before) or left holes, invoices stating a window nobody was charged
for, and a `nextBillingDate()` up to a week after the day Mollie actually charges on —
the date the overage sweep, every prorata window, `guardPeriodNotLapsed()` and the
coupon-expiry check are computed from.

The period now starts at the payment's `createdAt` (`cycleStart()`), with `paidAt` as the
fallback for a payment object without one and `now` for one with neither; a `createdAt`
later than `paidAt` is not trusted. `tests/Feature/Webhook/RenewalLongRunSepaTest.php`.

Consequence, deliberate: the out-of-order guard compares cycle dates. A SEPA renewal of
the old cadence that settles *after* a plan change opened a new period is invoiced and not
booked as a further period — it used to move the anchor to its settlement day and refill
the wallets a second time.

##### The discount on a renewal is the one valid when the charge was created

Mollie collects what the subscription held when it created the charge; whether that was
the discounted amount was decided at the previous renewal, by asking if the coupon would
still be valid at the next charge. The invoice asked a different question — "is it valid
*now*" — and with SEPA, now is days later. A coupon ending between a charge and its
settlement (20 % until 12 March, charge created on the 10th, settled on the 15th) was
collected at 27.61 and invoiced at 34.51 with an `adjustment` of −5.80 net,
`PaymentAmountMismatch` fired, and no redemption was written for a discount the customer
had received.

Both sides now judge the same instant at day granularity: this payment's discount as of
the start of its cycle day, the reset as of the start of the next cycle day. Day
granularity because Mollie creates the charge at an hour of the cycle date nobody
controls — a coupon ending at noon on a cycle day covers that day's charge whether it was
created at 03:00 or 14:00.

`CouponService::computeMarkerDiscount()` and `redeemRecurringCouponForRenewal()` gained
an optional `$asOf` for this (they already had the mechanism internally; `markerExpired()`
used it). `tests/Feature/Webhook/RenewalLongRunCouponTest.php`, data set "ends between a
charge and its settlement".

##### The charge that ends a full-coverage coupon sets the cadence — at any hour

`charge_deferred_until` is stored with the time of day it was written; Mollie's start date
is a date. The first charge after three free periods (signed up 10 January 10:00 →
deferred to 12 April) is created at some hour of 12 April. Created before 10:00, the
deferral still read as "in the future" inside that payment's own webhook, so the
coupon-expiry PATCH believed the charge was still deferred and sent a start date "back on
the regular cycle" — 10 May. The customer paid a month on the 12th and was charged the
next one on the 10th. Created after 10:00, the cadence stayed on the 12th. Which one a
customer got depended on when Mollie's scheduler ran.

The renewal handler now drops the stored deferral once the charge it deferred has arrived
(`forgetSpentChargeDeferral()`, compared by day) before it asks the patcher anything.
`RenewalLongRunCouponTest`, "charges nothing for three fully covered periods…".

##### A cent of rounding is booked, not tolerated

`WebhookSupport::reconcileWithPaidAmount()` returned the lines untouched when they were
within a cent of the payment. A subscription whose amount predates per-line VAT rounding
(or was set by hand) is one cent off on every renewal, forever: at 34.50 collected the
invoice stated 34.51 — output VAT on money that never arrived, and a full refund that asks
Mollie for more than the payment holds, which Mollie refuses.

Within the slack the difference is now booked as a `Rounding` line (kind `adjustment`)
when — and only when — a document at exactly the paid amount exists; `net + round(net ×
rate)` skips values, and a rounding line that still misses would be noise. No
`PaymentAmountMismatch` and no warning: a cent of rounding is not something an operator
has to look at. `tests/Feature/Webhook/RenewalBookkeepingEdgesTest.php` (both directions,
plus the refund amount).

##### A renewal does not write back a seat count somebody else changed

The handler read `seat_count`, built its lines, and at the end wrote the count derived
from those lines back — under the row lock, which does not help when the value written is
stale. A seat sync committing in between was reverted: Mollie billed seven seats, the row
said five. The write is now compare-and-set against the value read at the top. Reproduced
deterministically with a listener on `InvoiceCreated`, which is dispatched synchronously
inside that window. `RenewalBookkeepingEdgesTest`.

##### A plan change keeps the seats it was approved with

`ProrataChargeHandler` took the new seat count from the charge invoice (included seats +
the quantity of its `seats` line) and used the intent only when the invoice had no plan
line. The seats line is left off whenever it rounds to nothing or the new plan does not
price its seats, and the count collapsed to the plan's included seats — 5 became 2 —
while Mollie, patched from the intent, went on charging for all five. The intent is read
first now; the invoice is the fallback for a stored intent without `new_seats`.
`tests/Feature/Webhook/ProrataChargeSeatCountTest.php`.

##### A reversed chargeback comes back out of the books

A chargeback that is disputed and won carries `reversedAt`. The credit note stayed, the
invoice kept reading as refunded, and the subscription stayed past due on its way to the
auto-cancel for a payment that stands. `ChargebackHandler` now skips a chargeback that is
already reversed when first seen, reverses one it had booked
(`reverseBookedCredit(…, 'mollie_chargeback_id', …)`), dispatches `ChargebackReversed` and
notifies the admins.

Judgement calls:

- **Status is restored only when this chargeback owns the dunning episode** —
  `payment_failure` still names it (`reason = chargeback`, this invoice). A renewal that
  failed in the meantime owns it by then; the books are corrected and the status is left.
- **The controller re-reads a payment that still has a refund or chargeback on the books
  even when its outcome signature has not moved.** Whether one has failed or been reversed
  is stated on the refund/chargeback, and nothing guarantees `amountRefunded` /
  `amountChargedBack` change with it. Every handler behind `route()` is idempotent; the
  cost is one extra read at Mollie per re-delivery of such a payment.

`tests/Feature/Webhook/ChargebackReversalTest.php`.

#### Deliberate — pinned by tests that were green from the start

| Behaviour | Test |
|---|---|
| 24 monthly and 4 yearly renewals: amount, period, anchor, quota and serials never drift; no PATCH is sent | `RenewalLongRunTest` |
| A signup on the 29th/30th/31st: the start date sent is the clamped end of the first period, so Mollie's cycle is the 28th/30th. Under a clamping cadence February leaves up to two days between "one month after the last charge" and the next charge, once; the period then follows the charge and nothing accumulates — *superseded 16 November 2026: the renewal after a clamped charge now pins Mollie's next date, see "Mollie's cadence after a month end is measured for the record" in the third audit round* | `RenewalLongRunTest`, month-end data sets (both cadence models) |
| A yearly subscription started on 29 February runs on 28 February from then on, 2032 included — that is the date Mollie was given | `RenewalLongRunTest` |
| A three-period coupon discounts exactly three periods (checkout + 2 renewals), through February, card and SEPA; the reset reaches Mollie in the webhook of the third | `RenewalLongRunCouponTest` |
| A date-limited coupon's last discounted period is the last charge created before its end | `RenewalLongRunCouponTest` (three of four data sets) |
| Under a fully covering coupon no charge is created, the nightly pass turns the periods over and refills; the two days between the third period's end and the returning charge are not a period of their own | `RenewalLongRunCouponTest` |
| A gift with `end`: extras charged while it runs, every charge created before its end, then cancelled at the end of the last paid period | `RenewalLongRunWaiverTest` |
| A gift with `continue_paid`, and N free plan periods for a paying customer: plan-free while covered, full price from the first charge after | `RenewalLongRunWaiverTest` |
| A per-seat addon with its own free seats books the same four lines for a year | `RenewalLongRunChangesTest` |
| A seat added, an addon dropped and seats given up between renewals: Mollie holds the new amount in the same call, every later renewal books it, the cadence never moves | `RenewalLongRunChangesTest` |
| A renewal delivered twice, a day later again, or three days late books one invoice, one period, one refill | `RenewalLongRunDeliveryTest` |
| Two renewals delivered in the wrong order: both invoiced for their own month, the period does not go back, no second refill, the run continues | `RenewalLongRunDeliveryTest` |
| A failed renewal followed by a successful one: no invoice and no period for the unpaid month, one dunning episode, Active again, markers cleared (card and SEPA) | `RenewalLongRunDeliveryTest` |

One number in there is a product fact worth knowing rather than a defect: a "three
periods free" coupon signed up on 10 January is first charged on 12 April — 92 days, two
more than three calendar months — and the subscription then runs on the 12th. That is the
`N × 30 + 1 (+1)` window `setActiveRecurringCouponMarker()` documents.
*Changed on 17 November 2026:* the window counts billing periods; the same signup is first
charged on 10 April and stays on the 10th — see
[A recurring code's window counts billing periods](#a-recurring-codes-window-counts-billing-periods-not-days-decided-and-changed-17-november-2026).

#### Open — not testable here, or not decided

- **Mollie's cadence after a month end.** Whether a subscription started on the 30th
  charges on 30 March or 28 March after February cannot be observed: the sandbox scheduler
  runs daily and no run sees a period roll over. The package is correct under both models
  (the anchor follows the charge), but under the clamping one the local period ends up to
  two days before the charge once a year, and during those days `nextBillingDate()` is in
  the past — `guardPeriodNotLapsed()` refuses seat and addon changes until the webhook
  arrives.
  *Closed 16 November 2026 — the gap was up to three days, up to five times a year for the
  31st, and is gone: see "Mollie's cadence after a month end is measured for the record"
  (third audit round, lifecycle).*
- **The hour Mollie creates a charge**, and whether `createdAt` of a *subscription*
  payment is the cycle date. Measured for a one-off recurring payment only (it is the
  creation moment); a subscription's own charge cannot be produced in a test run.
- **Refund statuses.** `failed` / `canceled`, whether such a refund stays in `refunds()`,
  and whether the payment webhook is called when a refund fails: a refund needs a settled
  payment, and a direct debit stays `pending` for the length of any run.
  `MollieRenewalContractTest::test_a_refund_cannot_be_created_before_the_payment_settled`
  pins that limit and will fail — with a message saying so — the day it stops being one.
- **Chargebacks.** `reversedAt`, `amountChargedBack` after a reversal, and the webhook
  call are taken from Mollie's API reference; the sandbox cannot produce a chargeback.
- **A renewal that collected less than the current configuration costs** is still not
  topped up (see "A renewal created for a configuration the customer has left"): the
  remaining gap for a change made after Mollie created the next SEPA charge.
  **Superseded on 16 November 2026** — see
  [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
- **`reverseBookedCredit()` leaves wallet units** handed back by a refund that later
  failed. Unchanged from round 1; an operator is alerted.
- **Not covered by the long run:** an interval change (monthly ↔ yearly) mid-run, a
  scheduled change applied at a renewal, a trial converting into the run, a non-EUR /
  zero-decimal currency, reverse charge, and usage overage collected across renewals.
  The harness supports all of them; none was written in this round.

### Area: quota, overage and the nightly passes

#### Fixed (each reproduced by a test that failed first)

##### The hand-over from a deferred charge back to the renewal webhook credits once

Case A0 of `PrepareUsageOverageJob` renews a period Mollie is not charging for. When the
deferral ends the webhook renews again — and a deferral need not end on a boundary. A
recurring coupon's ended `N × 30 + 2` days after it was applied; three monthly periods from
10 January put the first charge on 12 April. (Since 17 November 2026 a coupon's deferral
ends on a boundary; markers written before keep their date, and a period extension still
adds days — the hand-over below stays needed.) A0 refilled on 10 April, the webhook refilled
on the 12th: two quotas for one period, kept for good by a rollover type.

A stretch between a boundary and the returning charge that is shorter than half an interval
is now not a period of its own: the anchor moves and the overage is collected, the quota
comes with the charge. `tests/Feature/Wallet/UsageLongRunDeferredChargeTest.php`.

Judgement calls:

- **Half an interval, not "any charge inside the coming period".** A deferral can end
  almost a whole period after a boundary (`N × 30` days against calendar months: six
  periods from 15 March end on 13 September, 29 days after the boundary of 15 August).
  Withholding the quota there would leave the customer a month without an allowance.
  Above the half, the stretch is refilled and the charge's renewal refills again — for a
  rollover type that is up to one quota too many, once per deferral, in the customer's
  favour. Prorating the stretch would be exact and is not done.
- **The anchor moves even when the quota does not.** An unmoved anchor is what made
  `guardPeriodNotLapsed()` refuse every change "until the renewal".
- **A gift that ends is exempt.** With `on_expiry = end` the period beginning at that
  boundary is the last covered one and the subscription is cancelled to its end; no charge
  is coming, so it gets its quota.

##### A subscription is not refilled at the boundary it ends on

Case C refilled a Local subscription whenever its period boundary was tomorrow, whether or
not `subscription_ends_at` was tomorrow too. An access grant of exactly two months got a
third quota the night before it expired — useless to a non-rollover type, permanent for a
rollover one, whose balance outlives the expiry and is still there at the next
subscription. `UsageLongRunLocalTest` ("ends on a period boundary").

A grant ending the day *after* a boundary still starts that one-day period and is refilled:
the rule is "ends on or before", and nothing is prorated.

##### A retry payment is written off on its own age, not on its cycle's

`CleanupStalePendingOverageChargeJob` measured the 14-day hard limit against the snapshot's
`created_at`, which is the dunning *cycle's* start and is inherited from payment to payment.
On SEPA each failure takes days to arrive, so the third payment of a cycle is created when
the cycle is 12–13 days old; two days later it was written off as "pending for over 14
days". The write-off frees the slot, the next sweep (or the renewal, which now collects for
itself) charges the same units again, and the written-off payment then settles: two
invoices for one debt. The limit is now taken from Mollie's `createdAt` of the payment
being polled, falling back to the snapshot only when the answer carries none.
`tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php`.

> **Superseded in part on 16 November 2026** — the age rule stands, but a stale payment is
> now cancelled at Mollie before its slot is freed, and the cycle's debt is deferred rather
> than written off; see
> [An overage nobody could collect stands, and the renewals try again](#an-overage-nobody-could-collect-stands-and-the-renewals-try-again-case-10).

##### Trial overage is billed at the trial plan's price when a plan change converts the trial

Left open in round 1. `ProrataChargeHandler` now calls
`WebhookSupport::collectOutstandingOverage()` before the conversion's refill, at a point
where the billable is still on the old plan — so the existing charge path prices it there.
60 Tokens against a grant of 10 on a 12 ct plan, converted to a 10 ct plan with a quota of
100: 50 units at 12 ct are charged, instead of 50 units silently coming out of the new
quota. `tests/Feature/Webhook/TrialConversionWalletTest.php`.

##### "Local + Trial" was reachable, and cost a free customer their plan

Round 1 called this state unreachable. The signup path cannot produce it — but
`extendBillingTrialUntil()` flipped any `Active` billable to `Trial`, and the admin panel
offers that action on whatever billable is open (the bulk action matches a free-plan row
that still carries an old, future `trial_ends_at`). On a Local subscription there is no
charge for a trial to defer; the flip only scheduled a lapse.
`ProcessTrialLifecycleJob` expires a trial without a mandate into PastDue: "no payment
method was added in time", locked out, auto-cancelled and expired a month later — for a
free plan, or for an access grant with weeks left to run.

- `extendBillingTrialUntil()` refuses a Local subscription that is `Active`
  (`InvalidSubscriptionStateException`, which the admin panel already catches). Local
  `New`, `Trial` and `PastDue` are unchanged.
- `ResubscribeSubscription` resumes a Local subscription that was cancelled mid-trial as a
  *trial* on its original end date, as the Mollie branch always has. It came back `Active`
  with no end date — a paid plan, unpaid, that no pass ever reclaims. This closes it for
  rows already in the state.

`tests/Feature/Subscription/LocalTrialStateTest.php`. An existing test
(`TrialAndScheduleEdgeStatesTest`, "still extends a trial for the states where one can
run") pinned the flip for Local `Active` and was narrowed to `New` and `Trial`.


#### Deliberate — pinned by a test, not changed

| Behaviour | Why it stays | Test |
|---|---|---|
| Usage on top of a debt whose charge is still settling is paid out of the next period's quota, not billed | There is one overage slot. The sweep's charge for 37 units is in flight (SEPA), 20 more are used before the renewal, the reset keeps the whole debt: −57 + 100 = 43, +37 at settlement = 80. Nothing is lost and nothing is billed twice; a card, which settles at once, is billed in money for the same 20. Billing them would need a second concurrent charge. | `UsageLongRunSepaTest` |
| The anchor of a paid subscription is the renewal's `paidAt` | Already decided; the long run confirms the consequence is contained: with lags of 1–7 days over twelve periods every period is credited once and billed once, whichever of sweep and renewal comes first. | `UsageLongRunSepaTest` |
| A cycle the dunning gave up on is written off by the renewal that recovers the billable — **superseded on 16 November 2026**, see [An overage nobody could collect stands, and the renewals try again](#an-overage-nobody-could-collect-stands-and-the-renewals-try-again-case-10) | Unchanged from round 1. Three failed payments, PastDue, renewal paid the next day: Active, wallet reset to the quota, no fourth attempt. The following period's overage is an ordinary new cycle. | `UsageLongRunLifecycleTest` (recovery) |
| An immediate cancellation makes one more attempt at a written-off debt — since 16 November 2026 the debt is *standing*, not written off, and this attempt is one of its collection cycles | `CancelSubscription(immediately)` charges what the wallets owe. After a dunning episode that ended in auto-cancel this is a fourth payment on a method that failed three times, with its own two retries: six requests in all, none after expiry. It is the last moment the debt can be collected at all. | `UsageLongRunLifecycleTest` (stays past due) |
| Resuming a cancelled subscription credits nothing — *except a Local one whose period turned over during the grace, 25 November 2026* | It restores the paid period; the next quota comes with the next renewal. A Local grace is 30 days, not the rest of a period, and the nightly pass does not renew a cancelled row: resumed on the old anchor, `nextBillingDate()` stayed in the past for good — case C matches only "renews tomorrow", so no refill ever came, and `guardPeriodNotLapsed()` refused every change with "wait for the renewal". `ResubscribeSubscription` now does the turn-over the pass skipped: anchor on the period running now (on the anchor day, several boundaries give one period), that period's quota through `WebhookSupport::creditPeriodQuota()` — not for a grant that ends by that boundary. Red before (anchor stayed 1 March). | `UsageLongRunLifecycleTest` (resubscribe); `UsageLongRunLocalTest` › "keeps renewing a free plan that was resumed after its period turned over during the grace" |
| A Local period that starts on the 31st keeps the 31st — *revised 16 November 2026* | The nightly pass turns a free plan or a gift without extras over on its anchor day, like a Mollie renewal: 31 Jan → 28 Feb (29 Feb in a leap year) → 31 Mar → 30 Apr, one refill per period on the day it begins. `PrepareUsageOverageJob::resetLocalQuota()` derives the day with `BillingAnchor::forCycle()` and records it beside the new period start; `nextBillingDate()` — and with it the boundary night, the refill and every prorata window — ends on it. Earlier the clamp to the 28th stuck for good; seen red (3 cases) against the unchanged job. | `UsageLongRunLocalTest` › "keeps a Local period on its anchor day through the short months" (free plan from 31 Dec 2027 through the leap February to March 2029, free plan from the 30th, gift without extras ending 15 May) |

Green from the start, and kept as a guard: card subscription over twelve periods (every
night once, eve missed, three nights missed around every boundary, every night twice);
free plan over twelve periods incl. hard cap and purchased units; access grant to expiry;
plan waiver over three free periods and back to paid; gift with `on_expiry = end`
(cancelled by the nightly pass, expired after, no further quota); operator extension
mid-period; trial into conversion and three paid periods; yearly over three years;
graceful cancellation with overage; per-seat addon through trial activation and through
the simulator's renewal; the uncovered branches of `RetryUsageOverageChargeJob`,
`SyncSeatsJob` and `PruneProcessedWebhooksJob`.


#### Open — real, not closed

- **`tests/Feature/Coupons/ChargeDeferralLifecycleTest.php` is not from this work and was
  red while it was written.** Four cases that drive `PrepareUsageOverageJob` failed in one
  run and passed in the next without a change to the job; one other case ("returns the
  charge to the regular date on an upgrade and defers it again on the way back") failed in
  the last run. It exercises `ChargeDeferral` / `MollieSubscriptionPatcher`. To be confirmed
  green by its owner — and re-run against case A0's hand-over rule, which it does not know.
- **A deferral that ends more than half an interval after a boundary still credits that
  stretch in full** (see the judgement call above). Exact would be a prorated quota.
- **A0 under a deferral is not tested together with a period extension appended to it**,
  nor a yearly interval under a deferral. The rule is interval-agnostic; no run walks it.
- **`SweepPendingRetriesJob` with the `next_charge_date` shape of
  `pending_subscription_patch`** is dispatched like the other flat shape; what
  `RetrySubscriptionPatchJob` does with it was not walked here (not this area's job).
- **`SyncSeatsJob` when the seat lock cannot be taken within 30 s** throws
  `LockTimeoutException` and, with `tries = 1`, the seat change is dropped into
  `failed_jobs`. Nothing notifies an operator; a member was invited and the seat is not
  billed. Not reproduced under real contention — a test would need two processes.
  **Superseded on 16 November 2026** — the job re-queues itself up to five times before it
  fails; see "A seat change that could not get its lock" under
  [Area: operations, overage and past due](#area-operations-overage-and-past-due).
- **Concurrency in general.** Every run here is one process. The cache locks, bavix's
  wallet lock and the row locks are exercised for ordering, never for contention.
- **Mollie's own behaviour is assumed, not measured, in these runs**: that a `1 month`
  subscription collects on a fixed day, and that `paidAt` of a SEPA debit trails it.

### Area: changes to a running subscription

#### Fixed

##### The running discount is carried onto the prorata charge
`ProrataComposer::withMarkerDiscount()`. The prorata charge priced the new configuration at list
while the credit for the old one is the unused share of what was paid, so across a change the
customer paid the discount back for the rest of the period. Under a plan waiver, Pro (free) →
Enterprise on day 11 of 31 charged 200.00 × 21/31 = 135.48 where the recurring difference is
150.00 × 21/31 = 101.61 — 33.87 for a plan held as a gift; a granted addon switched off and on
was charged pro-rata although the next renewal waives it again. Whole-configuration changes
(plan, interval, past-due reset) now take the discount of the new configuration, seat/addon
changes what the discount grows by. The line is `kind: 'coupon'`; a waiver's carries
`applies_to` (new `ProrataLine::$appliesTo`). *Not seen red — written before the matrix ran.*
Pinned by every plan-change cell and by `ChangeMatrixScheduledAndWaiverTest` (upgrade/downgrade
under a waiver, granted addon off/on, 100 % coupon upgrade).

##### A charge the discount covers in full no longer swallows the credits
`ProrataComposer` returns no charge lines when the discount covers them; `ProrataExecutor`
treats charge lines that sum to ≤ 0 as no charge. A charge of 0.00 creates no payment, so there
is no webhook, and the refund lines parked as "pending until the charge clears" were never paid
out. **Seen red**: fixed coupon (10.00) on Pro + storage → Free keeping storage — preview showed
credits of 28.13 + 5.06, nothing was booked.

##### A coupon that ends before the next charge is not PATCHed into a new period
`ProrataChargeHandler::paid()` and `PreviewService`. A change that opens a period (interval
change, past-due reset, trial conversion) puts the next recurring charge a full interval out;
the PATCH used the discount as of today. **Seen red**: 50 % for twelve months, monthly → yearly
on 11 Jan 2026 — Mollie held 1071.60 for the charge of 11 Jan 2027 although the coupon ends in
November 2026 (invoice 1128.00 − adjustment). Now judged as of the next charge, like the renewal
handler does; the charge made today keeps its discount. Plan waivers are left to the renewal
handler (`on_expiry`).

##### The stale-pending sweep restores what the failed charge was for
`CleanupStalePendingProrataChangeJob` cleared only the marker for a `failed`/`canceled`/`expired`
charge; seats, addons and Mollie's amount stayed on the unpaid configuration. It now calls
`ProrataChargeHandler::failed()`. **Seen red** (`ChangeMatrixKnownLimitsTest`: seat count 4
instead of 2). Side effect, intended: the billing admins are notified and `PlanChangeFailed`
fires on this path too. The recorded reason text (`Charge failed`) is kept.

##### A due scheduled change Mollie was not told about waits for its renewal
`ScheduleSubscriptionChange::apply()` / `WAIT_FOR_RENEWAL_DAYS = 7`. Applied by the dispatcher
while the period had lapsed (SEPA renewal pending) it had factor 0: an upgrade whose pre-patch
failed was free for a period (150.00 in the test's numbers); an interval change charged the new
interval on top of the old interval's renewal. It now waits until the renewal is booked and is
then an ordinary change in a paid period. *Not seen red — written before its tests ran.* Not for
PastDue (the reset is its path), not for "apply now" mid-period, and bounded at 7 days.

Two round-1 tests in `RenewalChargesTheChangedConfigurationTest` were rewritten from "scheduled,
applied by the dispatcher on the renewal day" to "changed immediately on the renewal day": the
nets they pin (`SupersededRenewalRefund`, Phase-2 recomposition) still exist for that case.

#### Deliberate

##### An interval change is refused while a plan waiver runs
`ValidateSubscriptionChange::assertIntervalChangeAllowed()`; also in `schedule()` and as a
preview error. A waiver's amount and lifetime are denominated in the interval it was given in.
Monthly → yearly charged a year minus one month's price for a gifted plan (`ceiling()` caps at
the granted amount); counted in periods, a gift of four months became four years. There is no
reading of "the same gift on the other interval" that neither charges for the gift nor enlarges
it, so it is refused with a reason. **Product decision — the alternative is a conversion rule
(e.g. waive `plan price × remaining gift days / 365` of the year), which is a feature.**

##### A coupon entered with a change is refused while a waiver runs
Nothing new: `CouponService::validate()` refuses (one slot). Pinned for the seat, addon and plan
forms — nothing charged, patched or written.

##### Under a waiver, a cheaper plan is free and nothing is credited for the plan
The plan line was paid with 0 (`paidLineNet()` places the waiver on the lines it names), the
ceiling follows the new plan's price, and paid extras are credited and charged again. The
`applies_to` extension of `paidLineNet()` was reviewed against the matrix (every waiver cell) and
left as it is.

##### A credit under a fixed-amount coupon is the proportional paid share
Removing one of several components that were paid under a FIXED coupon credits
`list × paid/charges`, not the list price. Example: Pro + 2 seats = 60.00, 10.00 off, one seat
removed with 21/31 left: credited 2.82; pricing the period again without the seat would give
3.39. The invoice does not record what a fixed discount was "for", so the spread is the one
reading that needs no guess and can never credit more than was paid. Percentage coupons and
waivers are exact. **Product question if exactness is wanted: store the discount's terms on the
coupon line.**

##### A fully covered plan switch books no document
Waiver, Pro → Starter with nothing paid on top: no charge, no credit, no invoice. Plan, Mollie
(full price, deferred) and wallets move; there is nothing of monetary value to state.

#### Open (pinned by a test, closing needs a new money flow)

- **Plan change, same interval, whose SEPA charge spans a renewal.** 30 Jan Pro → Enterprise
  (12.90 charged, pending); 1 Feb renewal is collected and booked as Pro (50.00) — local state
  and Mollie agree; 3 Feb the charge clears and the plan switches. Enterprise for 26 of 28 days
  on a Pro payment: 150.00 × 26/28 = **139.29 net not collected**, once. Needs a second prorata
  charge in Phase 2. `ChangeMatrixKnownLimitsTest` ("PINS: …").
  **Superseded on 16 November 2026** — charged automatically (139.28 with per-line rounding);
  see [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
- **Seat (or addon) added after Mollie created the next debit.** 31 Jan +1 seat; the February
  debit was created on 30 Jan at 60.00. Three seats for February, two paid: **5.00 net short,
  once**; booked with an `adjustment`, not topped up. Needs a charge from inside the renewal
  webhook. Pinned.
  **Superseded on 16 November 2026** — charged automatically; see
  [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
- **Seat or addon added while PastDue is free for the unpaid period.** Factor 0 on a lapsed
  period; Mollie is PATCHed. Pinned as today's behaviour. Product question: refuse seat/addon
  changes while PastDue?
- **Seat auto-derive on a plan change** — `max(currentSeats, used, included)`. Enterprise with
  5 included → Pro with 2 included carries 5 seats, 3 of them paid extras (15.00/month). The
  preview shows it. Pinned unchanged. Product question.
- **The prorata charge of an interval change is discounted but writes no redemption row**, so it
  does not count as one of the coupon's applications.
- **`applyAtRenewal()` while a prorata charge is pending**: the validator refuses a second
  change, the hook logs and leaves it to the dispatcher. Not tested.
- Yearly subscriptions are covered for the interval change only; seat/addon/plan cells run on
  monthly.

### Area: bookings with and without coupons

#### Fixed

##### Nothing owed is nothing charged — a 100 % coupon no longer leaves a cent

VAT is rounded per invoice line, and per-line rounding does not cancel across lines. Pro
29.50 + an addon 15.50 at 19 % carry 5.61 + 2.95 = 8.56 of VAT; one coupon line of −45.00
carries −8.55. With a coupon covering the whole order the checkout therefore showed a
total of **0.01**, sent that cent to Mollie as a first payment, and by doing so took the
order off the zero-amount path it belongs on (no mandate-only flow, coupon priced by the
paid handler). At 20 % the roundings happen to cancel, which is why nothing had noticed.

- `checkout.blade.php::totals()`: a net of 0 is a gross of 0.
- `MandateOnlyPaymentHandler`: the 0.00 invoice of a fully covered signup now carries one
  coupon line per charged line, each taking exactly that line off. Mirrored line by line
  the roundings are symmetric, so net, VAT and gross are 0 for any rate and any number of
  lines. One line for the whole discount stated a gross of 0.01 for a payment of 0.
- `StartOneTimeOrderCheckout::quote()`: the same clamp for a product order.

`BookingMatrixCouponsTest` ("single_payment 100 %", "recurring 100 %", both at 19 %). The
checkout half was seen red; the invoice half follows from the same arithmetic and was not
run against the unfixed handler separately.

*Judgement call:* partial coupons keep their single line. There the charged gross and the
invoice are built by the same per-line formula and agree with each other to the cent; the
VAT can differ from `rate × net` by a cent, which is the documented reconciliation slack
and not a disagreement between what is shown and what is collected.

##### An addon-only access grant is refused at the checkout form

It adds addons to a subscription that already runs, and the checkout only ever serves a
billable without one — `CouponService::validate()` refuses it for that reason. The form
validates without a billable, so it accepted the code, showed the addon as free next to a
payable total, and the submit then died inside the service with "the payment could not be
created". Refused at the field now (`coupon_requires_active_subscription`), on apply, on
the promotion pre-fill and on every re-validation. `BookingMatrixCouponPathsTest`.

##### A payment for a gift that is gone is booked, not reported as a duplicate

`LocalToMollieUpgradeHandler::openGate()` read "no longer Local" as "already upgraded".
A Local subscription can stop being Local without any upgrade: a gift an operator revoked,
or one that ran out and was expired by the nightly pass, leaves the source at None. With
SEPA the payment for the extras settles days after the purchase was started — it found
that state and was answered with `DuplicatePaymentReceived`: 18.60 collected, no invoice,
no subscription. The duplicate condition is now "a Mollie subscription is running"; every
other state proceeds, and `activate()` books a payment with no gift left to carry as a
regular upgrade (the rule already documented under "Buying extras on a gift prices
itself"). `BookingMatrixGrantExtrasTest`, which also pins that a genuine second payment is
still a duplicate — since 26 November 2026 booked and refunded as one, see
[A duplicate first payment is refunded automatically](#a-duplicate-first-payment-is-refunded-automatically-26-november-2026).

##### One price for a one-time order: page, payment and invoice

`StartOneTimeOrderCheckout::quote()` is the single place a product order is priced, and
`taxCountry()` the single answer to where it is taxed. Three things came apart before:

- The products page hardcoded `AT` for a billable without a billing country while the
  payment used `checkout_country_fallback`: 59.40 shown, 58.91 charged.
- The inline (fully coupon-covered) path used neither and threw `NonEuCountryException`
  for that billable. It now adopts the fallback and persists it, as the paid path's
  webhook does.
- The page grossed the *summed* discount; the payment rounds each coupon line. Two stacked
  coupons of 1.50 at 19 % are 0.29 + 0.29 of VAT against 0.57 on the sum — a cent between
  page and payment. The page now shows `price_gross − gross` from the quote. (Pinned in
  `BookingMatrixCouponEdgesTest`; derived from the arithmetic, not seen red.)

`BookingMatrixOneTimeOrderTest`.

##### `boughtBillingProducts()` lists products, not coupon codes

It collected every line's `code`, and a coupon line carries the coupon's. An order paid
with `FULL` reported `['pack', 'FULL']`. Only product lines count now (`HasBilling`,
foreign file, one condition).

##### The checkout names the date money is really first collected

Closes the "known remainder" of round 1. `firstChargeDate()` on the checkout component:
the trial's end, or the day after a fully covering recurring coupon ends when that is
later — computed with `CouponService::recurringMarkerValidUntil()`, which was extracted
from the private marker writer so that `CreateSubscription`'s deferral and the form read
one value. `step-confirm.blade.php` prints it. `BookingMatrixCouponPathsTest` asserts the
printed date equals the start date of the Mollie subscription the webhook creates.

##### `TrialExpiredNotification` says what happened

A mandate holder whose first charge never arrived is no longer told that "no payment
method was added in time" (`trial_expired.body_with_mandate`, en/de). Frozen at
construction like the date. `TrialFirstChargeGraceTest`.

##### check-config: a product priced 0, and per-seat addons that cannot bill as written

`products.*.price_net = 0` is an error — the purchase is refused, so the card's button can
only fail. Three warnings for per-seat addons: a seat price on one interval only;
`included_seats` without any seat price; a plan that allows a per-seat addon but sells no
extra seats and includes no more seats than the addon gives away (the seat price is dead
there). `CheckConfigNewFieldsTest`.


#### Deliberate, now pinned

- **A subscription checkout takes one coupon.** A second code is refused whether or not
  both are stackable; stacking is a one-time-order feature. `BookingMatrixCouponEdgesTest`.
- **A recurring coupon always has a count.** `max_redemptions_per_billable` is NOT NULL
  with default 1 and the admin form's minimum is 1, so "valid until" alone does not exist:
  the earlier of date and count ends the discount. See "Open" for what that does to an
  operator who fills in only the date.
- **A gift that ended before the payment settled is booked as a regular upgrade.** The
  first invoice states the amount collected (the extras) with an `adjustment`, and the
  Mollie subscription is created at the full price. Unchanged from DEV-384; the matrix
  states the numbers (18.60 collected, 54.00 from the next renewal).
- **Buying extras without a billing country is refused** (`NonEuCountryException`, before
  any Mollie call). A guessed VAT country on an invoice is worse than a refusal.
- **Seats already in use cannot be undercut** when buying extras: the service raises the
  count to `used_seats` and charges for it.
- **Hidden features**: named on no customer surface, unlocking in the model, `@planFeature`
  and `billing.feature`. `HiddenFeaturesEverywhereTest` includes a structural check that
  no customer view, mail, controller or the invoice service reads a feature name outside
  the three screens that list (visible) features.


#### Open

- **"Valid until" on a recurring coupon reads as the limit and is not.** The create form
  says "set either Max per billable or a Valid until date", the column defaults to 1, and
  the form's field defaults to 1 — so an operator who sets only the date gets a coupon
  that discounts exactly one charge. Not a pricing disagreement (shown, charged and
  invoiced agree), but very likely not what was meant. `CouponService` / the admin form
  are another owner's; needs a product decision (nullable count, or wording).
- **A revoked or expired gift turns a purchase of extras into a full-price subscription.**
  Documented behaviour for "expired"; for "revoked by an operator between purchase and
  settlement" it is the same code path and equally defensible, but the tenant agreed to
  15.50 a month and is charged 45.00 from the next renewal. Refunding and not activating
  would be the alternative. Product decision.
- **The portal's message when buying extras is refused for a missing billing country** is
  whatever `addons.blade.php` / `seats.blade.php` make of the exception (foreign views);
  not asserted.
- **`UpgradeLocalToMollie::handle()` trusts `amount_gross`** from the plan-change screen
  (foreign). The matrix drives it with the amount the checkout formula yields; that the
  screen computes the same number is the plan-change owner's test.
- **Rendering.** Everything asserted on a Livewire screen is logic and emitted text. How
  the confirm step, the products page or the coupon errors *look* is the browser suite's,
  which was not run.
- **Not seen red:** the trial + 100 % recurring date (the method did not exist before),
  the notification wording (new key), the stacked-coupon cent on the products page and
  the 0.01 on the mandate-only invoice (both derived from the arithmetic).

### Area: coupons, free time and the charge date

#### Coupons, free time and the charge date — second review round

Everything below was reproduced by a test that failed on the unfixed code (noted where a
test was green from the start and stays as a pin). Three parts: what was fixed, what was
decided while fixing it, and what is still open.

##### Fixed

#### Free plan periods are counted in charges, from the next one

`PlanWaiver::grant()` gave a period-based waiver `valid_until = now + N × 30 + 1` days — the
formula of a recurring coupon, which is redeemed at checkout and therefore at a period
boundary. A waiver is given in the middle of a period. On a subscription that bills nothing
but the plan the charge is deferred to that date, so "one free period" given on day 10 of a
monthly period moved the charge from day 30 to day 42, and on day 2 from day 30 to day 33:
the full price was collected three days late and the redemption said a month had been free.
Yearly: one free year given 100 days into the period deferred the charge to 367 days after
it was given — 102 days after the regular renewal instead of a year after it. With a paid addon on the same subscription the very same action waived a whole
renewal. The non-covering case had its own edge: given on the first day of a 31-day month,
the window closed on the day of the renewal it was meant to cover, and a SEPA settlement a
day later was booked without it.

The marker now carries `charge_resumes_at` — the next charge (`ChargeDeferral::nextChargeDate()`,
the trial end during a trial) plus N whole intervals — and `valid_until` is one day before it,
because that is the date both full-coverage deferrals add a day to. `extendByPeriods()` steps
the same date by whole intervals. `tests/Feature/Coupons/WaiverPeriodsAlignToBillingCycleTest.php`,
and against the real API in `tests/Sandbox/MolliePlanWaiverContractTest.php`.

#### A fully covering gift resumes on a renewal, not the day after it ends

"A renewal is plan-free when it falls before the gift's end … there are no partial days"
held as long as extras were billed. Once the tenant removed them, the full plan price was
parked behind `gift end + 1 day` — the middle of a period that rule calls free. Gift ending
in 5 days, renewal in 20: 60.00 collected on day 6. `PlanWaiver::chargeResumesAt()` is now
the one answer for both `fullCoverageStartDate()` implementations: the stored date for free
periods, the first renewal on or after the end for a gift. An ordinary 100 % coupon keeps
`valid_until + 1 day`. Pinned against `markerExpired()`, so the deferral and the renewal
handler cannot disagree about which renewal is the first one charged.

#### A waiver whose PATCH failed was deleted by its own retry

`CouponService::repriceAfterCommit()` queued a failed PATCH as `recurring_coupon_expiry_reset`.
That retry PATCHes the full price and clears the marker — right when a discount has ended,
and this runs when a waiver was just *given* or *extended* as well. One Mollie timeout while
giving free periods, and the hourly sweep removed the waiver: the tenant paid in full for
periods the redemption row says were free. Appending periods to a waiver held by another
coupon was skipped as "superseded", leaving the full price behind the old date. The reason
is now `plan_waiver_sync`; the retry carries no payload and re-prices the billable as it
stands (`PlanWaiver::syncMollieOrQueue()`, `RetrySubscriptionPatchJob::syncPlanWaiver()`).

`AdminExtensionService::extendGrant()` had the same hole without a retry at all: a failed
sync was thrown at the operator, whose second click extended the gift a second time, and a
failed resubscribe kept the first extension. The sync is queued, the resubscribe is all or
nothing (`PlanWaiver::restore()`).

#### Deactivating a grant code ended the gifts made with it — but only for tenants who had bought extras

`isMarkerStillRedeemable()` reads `coupon.active`. For a recurring coupon that is the
documented way to end the discount. A plan waiver is what remains of a gift: switching the
code off stops new redemptions and leaves a Local grant untouched, while the same grant on a
tenant who had bought an addon stopped being honoured at the next renewal — and with
`on_expiry = end` that renewal cancelled the subscription. A waiver no longer looks at
`active`; taking a gift back is `revokeGrant()`.

#### A waiver counted renewals that were not its own

Two readings of the redemption rows, both wrong for `max_redemptions_per_billable > 1`:
the per-billable cap counted `plan_waiver_renewal` rows, so the first waived renewal used up
the second slot of a code that may be redeemed twice; and the application count had no
lower bound in time, so a second redemption of the same code was exhausted by the renewals
the first had waived — accepted, slot consumed, nothing waived. The cap ignores renewal rows;
the count starts at the marker's `first_applied_at`.

#### A period extension during a trial moved the wrong date

`nextBillingDate()` for a trial is mandate capture plus one interval; a trial is charged at
its end. "14 more days" on day 9 of a 14-day trial therefore moved Mollie's charge to day
40 and left `trial_ends_at` on day 14 — and a mandate-holding trial with no charge 14 days
after its end is past due. The 24-hour guard looked at the same wrong date. A period
extension on a running trial now *is* a trial extension (`extendBillingTrialUntil()`), and
the guard reads the trial end.

Two refusals were missing in `validate()`: a Local subscription without an end date (the
apply step wrote `now + N days` into it — a perpetual free plan that expires in N days,
reachable from the customer's own dashboard) and a cancelled Mollie subscription (the PATCH
went to a subscription Mollie had cancelled, the grace end did not move, the redemption was
written).

#### A trial under a deferred first charge was declared past due

`ProcessTrialLifecycleJob` gives a mandate-holding trial 14 days after its end for the first
charge to arrive. A trial started with a fully covering recurring coupon has that charge
deferred to the coupon's end on purpose (docs/coupons.md: "can collide"). On day 29 the
customer who was promised three free months lost access and the auto-cancel clock started.
The window now starts at the deferred date. Free plan periods given to a trial take the same
path.

#### A coupon switched off while it deferred the charge stayed in force

"Deactivate the coupon — the marker is cleared on the next renewal." A 100 % coupon has no
renewal. `MollieSubscriptionPatcher::endDeferralOfDeactivatedCoupon()`, called by the nightly
pass, returns such a subscription to the full price from the next regular date; the running
period stays as it is. It recognises a subscription deferred before the date was stored by
the marker's shape. Not while only extension days remain (the coupon's own window has
passed): those days were given.

#### Returning from a deferral forgot what else had moved the charge

`chargeWasDeferred()` recognises a legacy deferral by "the marker covers its own base", which
stays true after the charge has been returned to its regular date. Every later amount PATCH
then re-sent `regularNextBillingDate()` — the bare period boundary. Seat under a 100 %
coupon, then 14 days of period extension, then another seat: the charge was pulled 14 days
forward while the row went on saying `P + 14`. And during a trial the boundary is a month
after signup, not the trial end. `ChargeDeferral::undeferredNextChargeDate()` is where the
charge returns to now: trial end, else `nextBillingDate()` (which carries the extension),
else the rolled boundary. A false positive of the legacy reading thereby re-sends the date
the package believes anyway.

#### A trial extension could move a paying subscription's charge earlier

`extendBillingTrialUntil()` accepts `Active` and sets Mollie's start date to the new trial
end. Paid until day 20, "trial until day 5": charged on day 5. Refused unless the target
lies after the next charge (deferred or regular).

#### One-off codes

- `revokeGrant()` freed the only slot of an admin-made code. Its code is printed on the
  invoice it reduced and the dashboard accepts plan-waiver codes, so the tenant could redeem
  the revoked free periods again. A code with `max_redemptions = 1` is deactivated on revoke.
- The "issue grant" screen created its `GRANT-…` code without a campaign limit: anybody who
  learned it could redeem a free plan at checkout. It is created with `max_redemptions = 1`
  (`accessGrantCoupon(…, maxRedemptions:)`).
- `update()` let the type of a redeemed coupon change. A running recurring marker re-applies
  its coupon through `redeem()` at every renewal, which then ran the new type's effect.

#### Smaller

- `extendGrant()` on a Local subscription with an end date and no grant redemption behind it
  saved the new end and then died on `SubscriptionExtended`'s non-nullable coupon; the retry
  extended again. The event's coupon is nullable.
- `PlanWaiver::syncMollie()` PATCHed a cancelled subscription (the id stays on the row), which
  Mollie rejects and the retry mails the admins about a day later.

##### Decided while fixing

- **`valid_until` of a period-based waiver is `charge_resumes_at − 1 day`, not a generous
  window.** The count (`max_applications`) is what ends it when extras are billed; the date
  is what the deferral is derived from when they are not, so it has to be exact.
- **A gift extended by periods moves by calendar intervals**, no longer by 30-day blocks.
- **"Extend the gift" is refused for free plan periods on a paying subscription.** Moving
  the date gave nothing the count did not decide, and `on_expiry = end` would have cancelled
  a paying customer. Free periods are extended by giving more.
- **After `extendGrant()` the end-of-gift question is asked again.** A subscription running
  out with its gift is brought back only if the gift now reaches the next charge; one
  switched to `end` that does not is cancelled to the end of its period at once, because
  nothing else looks at it before Mollie collects.
- **One-off is `max_redemptions = 1`.** There is no column that says "made for one tenant";
  a single-use campaign code whose only redemption is revoked is deactivated too, and has to
  be switched back on by hand.
- **`redeem()` still does not look at `active`.** A payment made with a code is honoured even
  if the code was switched off while the payment travelled; `validate()` is the gate. Since
  26 November 2026 such a redemption writes no recurring marker — see "A recurring code that
  ends before the first renewal leaves no marker".
- **A deactivated 100 % coupon keeps the running period free.** That is the equivalent of
  "cleared on the next renewal" for a partial discount.
- **Revoking appended free periods removes the whole waiver** — unchanged, documented.
- **The ceiling of a waiver after an interval change stays the monthly amount.** Pinned in
  `PlanWaiverCeilingMatrixTest`; see Open.

##### Open

- **A trial whose first charge is deferred stays `Trial` after its end.** It is no longer
  expired, but nothing converts it either: the nightly period roll (case A0) covers `Active`
  only, so its prorated trial quota is not refilled until the first charge arrives.
- **Gift + interval change.** Monthly gift, tenant switches to yearly: 50.00 off a 500.00
  charge while the plan is "free until" the gift's end. Refusing the interval change while a
  gift runs, or scaling the ceiling, is a product decision.
- **Extension days under a deferral are absorbed by a later, longer gift.**
  `fullCoverageStartDate()` takes the later of the waiver's own date and the stored deferral,
  so 10 days appended and then a gift extended past them yields the gift's date, not that
  date plus 10.
- **An expired recurring marker blocks a new recurring coupon while extension days still
  defer the charge** (`recurring_conflict` on a marker nothing clears before the first
  charge).
- **Free plan periods redeemed on a cancelled Mollie subscription** are written to the marker
  and only take effect on a resubscribe. Not refused.
- **Two operator clicks are two extensions.** `extendByDays()` and `freePlanPeriods()` mint a
  fresh code per call; nothing makes a double submit idempotent.
- **Not testable here:** two concurrent redemptions racing `Coupon::lockForUpdate()` (SQLite,
  one connection), and what Mollie does on the deferred date itself — the sandbox cannot
  observe a charge.

---

## Decisions taken with the maintainer (15 November 2026)

Eight open points from the second audit round were decided one by one. What was built from
them is recorded here.

| Point | Decision |
|---|---|
| A recurring coupon with only "valid until" gave one discounted charge | Own field `discount_periods`; empty with a date means "until the date". `max_redemptions_per_billable` only means "how often redeemable" |
| A gift ends while the purchase of extras is in flight | Refund the payment, create no subscription, tell tenant and admins |
| Seat or addon added while past due | Expansions are refused; reducing and plan changes stay possible. **Extended on 16 November 2026**: past due blocks every new booking, and a plan change is refused when only an overage debt is open — see [Past due blocks every new booking](#past-due-blocks-every-new-booking) |
| A plan change kept the seat count | Seats fall to `max(used, included)` unless the customer chooses to keep more |
| Interval change under a plan waiver | Stays refused |
| Two cases that collect too little once | Not charged automatically; detected, audited, reported to the admins with the amount. **Superseded on 16 November 2026**: charged automatically — see [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12) |
| Simplifications | Next charge (date and amount) on dashboard and admin tab; one "redeem a code" field; coupon form by intent |
| A single "adjust subscription" page | Not wanted. Plan, seats and addons are separate pages on purpose; do not merge them |

### The next charge is stated, date and amount

`Services\Billing\NextChargeSummary` answers "when is the next charge, and how much" from the
sources the charge itself is built from: `SubscriptionAmount` for the lines,
`computeMarkerDiscount()` judged **as of the charge date** for the discount, `ChargeDeferral`
for the date. The dashboard used to show a date (the period end) and leave the amount to be
worked out — tolerable while period end and charge date were the same day and the amount was
the list price, and wrong since a fully covering coupon or a free plan can defer the charge
for months and a plan waiver takes the plan off an amount that still contains paid extras.
`tests/Feature/Subscription/NextChargeSummaryTest.php` compares the figure with what the
package sends Mollie for the same billable. It is a projection: usage overage is charged
separately and is not in it, and a scheduled change is flagged rather than priced in.

### Area: coupons

#### Coupons — third round: period count, form by intent, one field in the portal

Three changes the maintainer decided. Each started from tests that failed (or could not
run) against the code as it was.

##### A recurring coupon says how long it discounts in a field of its own

`max_redemptions_per_billable` meant two things: "how often may one customer redeem this"
for six coupon types, and "how many charges are discounted" for the seventh. The form had
to explain the exception in three tooltips, the API read like a bug, and the two meanings
could not both be expressed — a recurring coupon a customer may redeem twice did not exist.

`coupons.discount_periods` (nullable int) is the number of discounted charges of a
`recurring` coupon. Set: that many charges. Empty with a `valid_until`: until the date,
without a count. Both: whichever ends first. Neither: refused, as before.
`max_redemptions_per_billable` is the per-customer redemption limit for every type.

Readers that moved: `CouponService::recurringMarkerValidUntil()`, the count gate in
`isMarkerStillRedeemable()`, `validateRequiredFieldsForType()`, and
`FirstPaymentCouponPricing::price()` (whether Mollie's recurring amount still carries the
discount after the first payment).

Decided on the way:

- **Existing coupons keep their lifetime.** The migration copies the number for every
  `recurring` coupon and sets its per-customer limit to 1 — which is what it effectively
  was, since the renewal rows counted against that same number and a customer could never
  redeem a recurring coupon twice. A coupon with a `valid_until` and the column default of 1
  meant "one charge, and not after the date"; it becomes `discount_periods = 1`, not
  "until the date". Running markers are not touched: they carry their end date, and the
  count is read from the coupon. Pinned for a 3-period coupon with 0, 1 and 2 charges
  already discounted (`tests/Feature/Migrations/DiscountPeriodsMigrationTest.php`).
- **The copy runs only in the run that adds the column.** Afterwards an empty
  `discount_periods` is a statement, not a gap; a re-run must not turn "until the date"
  into "one charge".
- **`create()` still accepts the old shape.** A recurring coupon created with a per-customer
  limit and WITHOUT the `discount_periods` key is read the way it was written: the number
  becomes the period count, the limit becomes 1. That is the compatibility path for every
  app that creates coupons in code — and for this package's own test suite, most of which
  still creates recurring coupons that way and thereby proves the path. One case is not
  moved: a limit of 1 next to a `valid_until` (1 is the default and carries no intent; the
  date is a complete lifetime). As soon as the key is present, even as null, both fields
  mean what they say — which is how the admin form passes them. `update()` has no such
  path: it is always explicit.
- **A discounted renewal is not a redemption.** Renewal rows are marked
  (`grant_applied_snapshot.mode = recurring_renewal`) and excluded from the per-customer
  count, like a waiver's. Renewal rows written before the mark cannot be told from a grant
  and still count; with a limit above 1 that refuses a second redemption it could have
  allowed. Deliberately not guessed at.
- **A second grant of the same code counts afresh**, as for a plan waiver. The lower bound
  (`first_applied_at`) is applied only where a second grant is possible at all
  (`max_redemptions_per_billable > 1`) and is loosened by a minute, because a coupon's
  first row is written a moment before its marker in the same `redeem()`. Markers written
  by an older release are therefore counted exactly as before.

##### The coupon form asks for the intent first

"Discount", "free time" or "credits" — then the kind of it. The seven `CouponType` values
and the `type` property are unchanged; `intent` is a second property that steers `type`
and is derived from it when only `type` is set (a deep link, a test, a browser script).

- **Giving the product away under "free time" always has a plan.** The addon-only grant —
  an access grant without one — is a checkbox under "Advanced". Choosing it sets
  `type = access_grant` and drops plan, interval and duration on save, whatever an earlier
  choice left in those fields. Setting `type = access_grant` directly and leaving the plan
  empty still creates an addon-only grant, as before.
- **A recurring coupon's form always passes `discount_periods`**, also when empty. The form
  always submits a per-customer limit (default 1); without the key the compatibility path
  above would turn that 1 into "one period" and accept a coupon the operator gave no
  lifetime.
- **The intent cards carry their text as content, not as attributes.** The feature suite
  does not compile Flux tags, so an attribute would be invisible to it — and an extra
  hidden copy for the tests would be read twice by a screen reader.

##### One "redeem a code" field in the portal

`Services\Billing\RedeemCouponFromPortal` recognises the type of the code and does what
that type means for this billable; the dashboard only authorises, calls and shows the
sentence. See "One field in the portal" in docs/coupons.md for the table.

- **A recurring code is applied to the running subscription.** Marker against what is
  billed today, a redemption row with a discount of 0 (nothing is charged now — the
  "PATCH-only" row of the redemption table), then the Mollie PATCH, after the redemption
  has committed. A PATCH that fails is queued (`plan_waiver_sync`, which re-prices the
  billable from whatever marker it holds), not thrown: the discount has been granted.
- **Its lifetime is counted from the next charge** (`lifetime_from`), not from today.
  Counted from today, "3 periods" typed in on the first day of a period closed its window
  on the day of the third charge, and a settlement a day later lost it. The count gate is
  unaffected: the grant row carries no discount and uses up no application.
- **A code that would end before the next charge is refused, not spent.**
- **A pending plan change refuses it.** That change PATCHes Mollie to its own amount when
  its payment settles, computed before the discount existed.
- **A single-payment code is never redeemed there** — there is nothing to redeem it
  against. It is answered with what it is for. No link: the products page takes no
  pre-filled code.
- **An access grant follows `validate()`:** activates for a billable without a
  subscription, extends a gift of the same plan, and is refused with a reason on a paid
  subscription or another plan. ("Refuse on any running subscription" was the other
  reading; a second gift for the same plan is exactly what the checkout path already
  accepts.)
- **`redeem()` still does not look at `active`**, and the service does not either after
  `validate()` has passed — unchanged.

##### Not covered by a test

- The browser suite was not run. Three browser tests were rewritten blind because they
  asserted the behaviour that changed: `AdminPanelTest` (intent before kind; first tooltip
  is `info.intent`), `CouponMidCycleTest` and `PortalDashboardTest` (a recurring code is now
  applied on the dashboard). How the intent cards and the new field render is not asserted
  anywhere else.
- The recurring-from-portal path was not run against the Mollie sandbox. It sends the same
  `UpdateSubscriptionRequest` the plan-waiver sync does, which is.
- The migration's `down()`.
- Renewal rows of a recurring coupon written before the `recurring_renewal` mark, with a
  per-customer limit above 1 (see above): the conservative outcome is described, not pinned.
- A 100 % recurring code applied from the portal defers the charge to `first charge +
  N × 30 days + 2`. That is the coupon formula (30-day months), so it lands within a day or
  two of the charge after the N skipped ones — for long runs across 31-day months possibly a
  day before it. Tested for two periods ("past the second skipped charge"), not to the day.
  (**Superseded on 16 November 2026**: pinned to the day for 1, 2, 3, 6 and 12 periods.
  **Changed on 17 November 2026**: counted in billing periods, the first charge is the
  regular charge N periods after the first covered one.)
  A plan waiver, by contrast, is aligned to the cycle exactly.

### Area: a gift that ends while extras are being bought

Entries for `docs/decisions.md`. Not merged (shared file).

#### A purchase priced on a gift is refunded, not booked, once the gift is gone

`UpgradeLocalToMollie::handleKeepingGrant()` prices "the extras, on top of a plan that stays
free", and the tenant agrees to that number. With SEPA the payment settles days later. If
the gift has run out, been revoked, or been expired by the nightly pass by then, there is
nothing left to keep.

The handler has answered this three ways. First it reported the payment as a duplicate
(money collected, no invoice, no subscription). Round 2 made it book a regular upgrade —
which fixed the lost money and created a subscription at the full price: plan and addon
from the next period on, for somebody who had agreed to pay for an addon. Now
(`Services\Webhook\GrantExtrasPurchaseRefund`, called from
`LocalToMollieUpgradeHandler::activate()`, i.e. outside any transaction and under the
activation claim):

- the payment is returned in full;
- no Mollie subscription is created, no waiver written, plan / addons / seats untouched;
- the mandate the first payment captured is **not** adopted and is revoked
  (`RevokeMollieMandateJob`) — a mandate on file is what denies a later checkout its trial,
  so keeping it would not leave the tenant "as they would be without the purchase";
- the tenant is mailed (`GrantExtrasPurchaseRefundedNotification`), the admins are alerted
  (`MollieBilling::notifyAdmin()`), `GrantExtrasPurchaseRefunded` is dispatched and audited.

> **Superseded on 16 November 2026** — the admins are no longer alerted, neither when the
> refund went through nor when it is queued; one mail comes from `RetryRefundLineJob` when
> the retries are used up. See [The twelve cases that used to be a mail](#the-twelve-cases-that-used-to-be-a-mail) and point 5 of
> [Open decisions for the maintainer](#open-decisions-for-the-maintainer).

"Gone" is one predicate, `grantStillRuns()`: an active Local subscription that ends in the
future **and** a grant redemption that was not revoked. It is deliberately the same set of
conditions `carryGrantOver()` needs to write a waiver, so there is no state in which
neither a waiver is written nor a refund issued.

Not affected: a second payment while a Mollie subscription already runs is still a
duplicate (`DuplicatePaymentReceived`; booked and refunded since 26 November 2026, see
[A duplicate first payment is refunded automatically](#a-duplicate-first-payment-is-refunded-automatically-26-november-2026)); an upgrade without `keep_grant` is booked as before; a purchase
whose gift still runs is booked as before.

##### Judgement call — booked and credited, not refunded without a document

An invoice is written for the payment and the refund is its credit note.

- **Every payment the package receives becomes an invoice.** That is what
  `CleanupOrphanedBillablesJob` ("a billable with an invoice is never touched"), the admin
  panel and the tenant's own invoice list read as "money came in". A debit and a credit on
  a bank statement with no document for either is the worse outcome for the tenant and for
  the operator's bookkeeper.
- **The later refund webhook needs something to recognise the refund by.** Mollie calls
  the payment webhook again once the refund exists. `RefundHandler` dedups on the
  `mollie_refund_id` stamped on a credit-note line; without a credit note it would find a
  refunded payment with no booking at all.
- **Net zero for VAT and OSS.** The invoice is one line for exactly the gross Mollie
  collected; the credit note reverses that line, so net and VAT are zero across the pair
  (pinned through `OssProtocolService`). The line's net is derived from the gross — the
  gross is the one fact — and its label says the purchase was not carried out.
- The invoice is `InvoiceKind::Subscription` with a `purchase_not_executed` line flag,
  which is how a re-delivery tells "refused and settled" from "an activation that died
  half-way" (the `AlreadyBooked` branch used to raise the incomplete-activation alarm for
  any invoice without a finished activation).

The alternative — refund with no document — is defensible in tax terms (no supply took
place) and was rejected for the three practical reasons above.

##### Judgement call — the refund goes through `InvoiceService::createRefund()`

Not `RefundInvoiceService::refundFully()`. Both hold the per-invoice lock
(`billing:invoice-refund:{id}`) and stamp the refund id, but only `createRefund()` parks a
line Mollie could not take under `subscription_meta.pending_refund_retries`, where
`SweepPendingRetriesJob` / `RetryRefundLineJob` retry it with backoff for seven days and
dead-letter it with an admin notification. `refundFully()` throws and leaves the retry to
an operator. A failed refund here must not depend on somebody clicking.

Consequences: a re-delivery does not post a second attempt while the line is queued (it
would race the retry job); the admin alert states `refund_queued: true`; the tenant's mail
goes out once either way — it says the amount "is being refunded", which stays true.
(The admin alert was removed on 16 November 2026, see the note above.)

##### Idempotency

The invoice is unique per payment. The refund is skipped when a credit note for the
invoice exists, nothing is left to refund, or the line is queued. Event, both mails, the
mandate revocation and the after-checkout hook run once per payment, guarded by
`subscription_meta.grant_extras_refunds[paymentId]` — written *before* they run, so a
re-delivery during a slow mailer cannot send a second mail; a mail that fails to send is
the lesser harm.

Tests: `tests/Feature/Checkout/GrantExtrasGiftGoneRefundTest.php` (revoked / ran out /
expired by the nightly pass / redemption gone; refund == payment; nothing changed; one
mail each; re-delivery; the refund webhook afterwards, `pending` and `refunded`; Mollie
down → queued → swept → credited; reverse charge; OSS).
`tests/Feature/Coupons/GrantKeepsRunningWithPaidExtrasTest.php` had pinned "charged as a
regular upgrade" and was rewritten.

#### A gifted tenant without a billing country is told what is missing

A plan given by an operator can exist without the tenant ever having been through a
checkout, so without a billing address. `handleKeepingGrant()` handed the empty country to
the pricing probe and threw `NonEuCountryException` — "VAT cannot be calculated for country
[]" — which the addons and seats screens could only show as "something went wrong".

`BillingCountryRequiredException` (a subclass, so existing catches keep working) is thrown
before anything is priced. Both screens show a standing callout to such a tenant with a
button to the billing-details page, and answer an attempted purchase with the same
sentence. `tests/Feature/Portal/GiftedTenantWithoutBillingCountryTest.php`.

*Judgement call:* refused, not priced at `checkout_country_fallback`. The fallback exists
for a checkout that collected an address a moment earlier; here there is none, and an
invoice at a guessed country's VAT rate is worse than asking.

#### Open

- **Not measured against Mollie:** that a refund of a `sequenceType: first` payment leaves
  the mandate usable until the revoke job runs, and that revoking it does not affect the
  refund. Both follow from Mollie's documented model (a refund is against the payment, not
  the mandate) and were not probed in `tests/Sandbox`.
  **Superseded on 16 November 2026** — measured: the refund of a first payment after its
  mandate was revoked is accepted; see "Real Mollie: what the sandbox browser suite
  measured".
- **The tenant's mail is the same whether the refund was posted or queued.** If the queued
  refund dead-letters after seven days the admins are told; the tenant is not told again.
- **Rendering** of the callout and of the toast on the addons screen: the feature suite
  does not compile Flux, so the callout's text is asserted and the button and the toast
  are not. Browser suite not run.
- **A gift that ends *after* the payment was booked** is unchanged: that is the
  `grant_on_expiry` path.

### Area: past due, seats on a plan change, undercollection

#### Behaviour and judgement calls

##### Past due: only what the caller asked for counts as an expansion
`ValidateSubscriptionChange::assertNoPastDueExpansion()`. A seat count is an expansion only when
it was passed explicitly and exceeds the current count. A *derived* count can exceed the stored
one because more members are assigned than seats exist; refusing to switch an addon OFF for that
reason would be absurd (`PastDueExpansionRefusedTest`, "does not mistake a derived seat count…").
A plan or interval change is exempt whatever seats and addons it carries — it is the past-due
reset and charges the new period in full. (**Superseded in part on 16 November 2026**: exempt only
when a renewal is unpaid; refused while nothing but an overage debt is open — see
[Past due blocks every new booking](#past-due-blocks-every-new-booking).) Phase-2 re-validation is exempt (that change was
accepted and paid before). The exception's message is the translated customer text.

##### Past due: a scheduled expansion is refused as well
"At the period end" of a past-due subscription is in the past; a stored expansion would be applied
by the next dispatcher run on the unpaid period. Refused in `schedule()` with the same exception.

##### Seats on a plan change: used or included, both directions
`BillingPolicy::derivedSeats()`, used by `UpdateSubscription::buildContext()`,
`ValidateSubscriptionChange::validateSeats()`, `ScheduleSubscriptionChange` (storing, pre-patch)
and `PreviewService`. Consequences that were decided along the way:

- **Paid extra seats nobody sits on are dropped too**, not only the old plan's included ones — the
  rule is "used or included", and it is the same on an upgrade. What was paid for them is credited
  pro-rata.
- **A plan that sells no extra seats no longer demands an explicit "drop" first** when the
  customer merely *holds* more seats than it includes; the count falls to the included seats.
  More seats *in use* than it includes is still `SeatDowngradeRequiredException`.
- **"Keep extra seats" in the screen passes the current count explicitly** (never below used or
  included) and is only offered when the target plan sells seats and there is something to keep.
- A scheduled change stores the derived count for display (`seats_auto_derived`) and re-derives
  at apply time, so members assigned in the meantime are seated.

##### Undercollection: reported once, never acted on

> **Superseded on 16 November 2026** — see [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12).
> `UndercollectionReporter` is now `UndercollectionCharge`; the recognition described below is
> unchanged, what follows it is a charge instead of a report.

- **(b) renewal created before the change**: recognised in `SupersededRenewalRefund::settle()` —
  the call `SubscriptionPaymentHandler` already makes, no new call there. Condition: a negative
  `adjustment` on the renewal *and* payment metadata naming another configuration. Expected net
  is the sum of the invoice's own lines (coupon/waiver lines included, so a discount is never
  read as a shortfall); collected is expected plus the adjustment.
- **(a) plan change spanned a renewal**: recognised in `ProrataChargeHandler::paid()` when the
  period anchor moved after the pending change was created. The shortfall is what the same
  change would cost if made now (composed with the billable still on the old plan). Per-line
  rounding: the example is 185.71 − 46.43 = **139.28**, not 150.00 × 26/28 = 139.29.
- **Its mirror image is settled, not reported**: a downgrade that spanned a renewal refunds the
  difference against the renewal. Returning money needs nobody's decision; the maintainer's
  "do not charge automatically" is about the other direction.
- **Once per case**: both places sit behind the per-payment invoice guard. No separate marker.
- **Silent** within 1 cent, for discounts/waivers, and when the payment's metadata matches the
  current configuration (`PaymentAmountMismatch` only, as before).
- The admin notification reuses `AdminPlanChangeFailedNotification` (as
  `SupersededRenewalRefund` does) with `shortfall_net`, `expected_net`, `collected_net`,
  `invoice_id`, `reason` in its context. **A dedicated notification class with its own subject
  would read better; not done.**

#### Not covered by a test (open)

- `plan-change.blade.php`: the "after the change: N seats" line, the "not carried over" hint and
  the keep button are not rendered in a test (browser suite only). The component logic
  (`keepExtraSeats`, the seats ordered and the amount charged) is tested through Livewire.
- `tests/Browser/PlanChangeTest.php` was adapted to the new markup but **not run**.
- `plan-change.blade.php`: the `past_due_expansion` callout is not rendered in a test; the seats
  and addons screens' hint is.
- The toast the addons screen shows when `enableAddon()` is refused is not asserted.
- Undercollection (a) with an active coupon or waiver, and with an interval change in flight, is
  not tested; (b) is tested without a discount and for "no report" with discounts.
- After a downgrade that spanned a renewal, the refund is a plain partial credit on the renewal;
  the renewal's plan line keeps its quantity, so a later change in that period finds no line for
  the plan the customer is now on and credits nothing for it. Not tested, not changed.
  (Worked through with numbers on 16 November 2026 — point 3 of
  [Open decisions for the maintainer](#open-decisions-for-the-maintainer).)
- A seat change scheduled for the period end on a non-past-due subscription that *becomes* past
  due before it is applied is applied by the dispatcher as before (factor 0). Not tested.


## Third audit round: every option tested, automatic resolution (16 November 2026)

A third pass, in five areas (checkout, changes to a running subscription, coupons and free
time, lifecycle, operations and admin). Three things were asked of it:

1. **Every option has a test, also in the browser.** Each area keeps an inventory of its
   options — config keys, catalog fields, Livewire actions, events, notifications, commands
   — in `tests/Coverage/{checkout,changes,coupons,lifecycle,operations}.php`, and
   `tests/Feature/Coverage/OptionCoverageTest.php` fails for an option nobody claims. The
   data-driven browser classes live under `tests/Browser/Matrix/`.
2. **What the package can resolve itself, it resolves.** Twelve cases used to end in a mail
   asking an operator to act. The principle now is that admins should have as little work
   as possible: a message goes out only when the automatic way is exhausted.
3. **Past due means "nothing new is booked"**, in every path, and an overage nobody could
   collect is a debt that stands instead of a write-off.

The rule of the second round is unchanged: a finding counts when a test shows it red — or
when no test covers the behaviour at all — and a fix is believed once its test has been
seen red against the unfixed file.

**What this round supersedes.** The older entries are kept and carry a note pointing here:

| Older statement | Now |
|---|---|
| "Undercollection: reported once, never acted on" (15 November) and the one-sided `SupersededRenewalRefund` | The difference is charged automatically — [Undercollection is charged back](#undercollection-is-charged-back-cases-11-and-12) |
| "A cycle that gave up stays written off" (first and second round) | The debt stands and the next renewals try again, three times at most — [An overage nobody could collect stands](#an-overage-nobody-could-collect-stands-and-the-renewals-try-again-case-10) |
| The interim decision of this round "a failed overage charge no longer makes the subscription past due" | Revised by the maintainer: past due, under its own key `overage_past_due_since` — [Past due for an overage debt](#past-due-for-an-overage-debt) |
| Admin notifications for the cases 1–12 | Resolved automatically; a notification only once the automatic way is spent — [The twelve cases](#the-twelve-cases-that-used-to-be-a-mail) |

### Decisions taken with the maintainer (16 November 2026)

| Point | Decision |
|---|---|
| What past due allows | **Past due blocks every new booking**: seats, paid addons, plan and interval changes (for a debt-only past due), one-time orders, a new checkout, extras on a grant. Reducing stays possible; with an unpaid renewal the plan change stays available as the past-due reset |
| Usage while past due | **Overage is blocked.** Only what the wallets hold — the included quota and purchased units — can be used; beyond it `recordBillingUsage()` refuses as for a billable without overage |
| Access while past due | **Access stays for a customer who owes only overage.** The plan is paid; what is locked is booking and further overage, not the application. With an unpaid renewal nothing changes |
| A failed overage charge | Makes the subscription **past due** — revising the first version of this round, which left it active — recorded under its own key `subscription_meta.overage_past_due_since`, so the auto-cancel clock does not run for it |
| An overage the dunning gave up on | The debt stands; each of the next three renewals starts one more collection; then it is written off and only then are the operators told |
| The two cases that collect too little once (cases 11 and 12) | **Charged automatically**, once per case, announced to the customer — revising the decision of 15 November |
| A scheduled change the validator refuses | Dropped and the customer informed ("validator says no → drop + inform"), including a change blocked by seat usage |
| Admin mails | Only when the automatic way is exhausted |
| A single "adjust subscription" page (DEV-386) | Not wanted — unchanged from 15 November. Plan, seats and addons stay separate pages |

### The twelve cases that used to be a mail

| Case | What happened | What the package does now | An operator hears |
|---|---|---|---|
| 1 | A renewal collected for a subscription that no longer runs | Invoiced, Mollie subscription cancelled, payment refunded in full, customer told | when the refund's retries are used up |
| 2, 3, 8 | A prorata payment that settles after its change was cancelled, failed, or after the subscription ended | Booked and refunded in full, customer told | when the refund's retries are used up |
| 4, 5, 6 | An activation that did not finish (mandate captured and nothing activated; invoice without a subscription; `CreateSubscription` failing after the first payment) | Retried five times over seven hours; a Mollie subscription the first attempt created is adopted; then the payment is refunded | when the retries are spent |
| 7 | A paid payment that names no billable | Booked if its billable turns up, refunded after 14 days | once when first seen, and if the refund keeps failing |
| 9 | A scheduled change that cannot be applied | Retried on three nights, then dropped; a refusal is dropped at once; customer told | when a transient failure used up its attempts — not for a refusal |
| 10 | An overage charge that never settled | Debt stands, three further collections with the next renewals | at the final write-off |
| 11, 12 | A period collected at less than it costs | The difference is charged as its own payment with its own invoice | when it cannot be charged (past due, ended, no mandate, Mollie refuses) |

Two building blocks carry most of it. `Services\Webhook\UnwantedPaymentRefund` books a
payment that is not applied and returns it in full — invoice plus credit note, net zero —
idempotently, with retry through `pending_refund_retries`, a customer mail, and an admin
mail only from the dead letter. It was generalised from `GrantExtrasPurchaseRefund`. Usage:
`app(UnwantedPaymentRefund::class)->settle($payment, $billable, $reasonKey, $lineLabel)`,
and `->resume($payment, $billable)` on a re-delivery; a new caller adds a reason key and the
two sentences under `billing::notifications.unwanted_payment_refunded.reasons.*`.
`RetrySubscriptionActivationJob` with `Support\ActivationRetry` is the other.

### Area: checkout

Inventory `tests/Coverage/checkout.php` (110 options). Feature:
`tests/Feature/Coverage/CheckoutConfigKeysTest.php`, `CheckoutCatalogAndActionsTest.php`,
`tests/Feature/Checkout/BookingMatrixCouponCrossTest.php`. Browser:
`tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php` (145 cases),
`CheckoutOtherPathsMatrixTest.php` (60), `CheckoutOptionsMatrixTest.php` (28), shared world
in `CheckoutMatrixWorld.php`.

#### Fixed

##### A dated recurring coupon that ends before the next charge was still collected as a discount

`FirstPaymentCouponPricing::price()` decides the amount the Mollie subscription is created
at (`recurring_discount_net`). It honoured the coupon's count (`discount_periods`) but not
its date: with only `valid_until` set, the subscription was always created at the
discounted amount — also when the coupon is over before the subscription's first charge.

Pro yearly 299.50 + SSO 155.50 = 455.00 net, a 15 % code valid for another 100 days, AT
20 %. First payment 464.10 (right). Mollie subscription: 464.10 instead of 546.00 — the
charge a year later collects 81.90 too little for a year the coupon does not cover, and its
invoice carries a "payment adjustment" line. Monthly, with a code valid ten more days:
45.90 instead of 54.00 on the second charge. One undercollected charge per such customer;
the renewal after it is priced right.

`price()` now zeroes `recurring_discount_net` when `valid_until` is not after the next
charge (one interval ahead, or the trial end for a trial start). Found by the browser
matrix (`yearly × recurring-date × AT`), reproduced and seen red at feature level
(46410 ≠ 54600, 4590 ≠ 5400).

##### … and the same with a trial in front

Team 19.50 + SSO 15.50 = 35.00 net, 14 days of trial, a 15 % code valid for another ten
days. The first charge is at the trial end, after the code: 42.00 (AT), not 35.70.

- The checkout applied the code and showed 35.70 as the amount of the first charge. It now
  answers at the field (`checkout.coupon_ends_before_first_charge`) and applies nothing.
  Seen red (3570 ≠ 4200).
- `price()` — for a code that reaches the activation without the form — puts no discount
  on the subscription unless `valid_until` is after the trial end (10 days → 0, exactly
  14 → 0, 20 → 525). Counter-proof: with the trial branch switched off, the first two cases
  are red.

##### The billing-data page waited for a mandate change that had already happened — or for one that never comes

`billing-data.blade.php::mount()` took "the mandate I see now" as the one to wait to change
when the customer returns from Mollie. Mollie's webhook is regularly there before the
customer is; the page then waited for a change that was already done, and polled
`checkForMandateUpdate` every two seconds for as long as it stayed open.
`changePaymentMethod()` now remembers the mandate the customer **left** with (session,
`billing.mandate_change.{id}`) and `mount()` compares against that.

The other half: after a change abandoned or failed at Mollie the poll had no end either. It
stops after 45 ticks (90 s, the patience the return page has), shows
`portal.payment_method.update_timeout` with a "Check again" button
(`recheckMandateUpdate()`), and resumes with a fresh budget when asked. Both seen red, in
the feature suite and in the browser.

##### What the customer was not told

- **A plan's `description`** is a documented catalog field, `checkout@plans()` hands it to
  the plan step — and `step-plan.blade.php` never printed it, in neither of its layouts. It
  is rendered under the plan name now. Counter-proof done afterwards.
- **The purchase dialog of a product did not say what is charged once a coupon applies.**
  It listed the price and each coupon's discount, but not the amount that goes to Mollie —
  the one number the customer agrees to; with two stacked coupons whose VAT rounds apart it
  could not even be added up. The dialog carries a "You pay:" line
  (`portal.products.confirm.total`), computed from the same per-line figures the order is
  created with. Pinned as shown == amount at Mollie == invoice, 33 cases.
- **A rollover usage type hid the carried-over units.** The meter printed "Plan: 50 of 50
  remaining" for a wallet holding 80 (50 quota + 30 carried over): `remainingIncluded` is
  capped at one period's quota. It adds "+ 30 carried over" (`portal.usage_carried_over`)
  for a type whose catalog entry says `rollover => true`.
- **The checkout did not say that a per-seat addon includes seats.** The customer read
  "+ €4.00 / seat" next to three seats and was charged for two — in their favour, but
  unexplained, and the portal's addons page does say it. The extras step prints
  "(1 seat included)" (`checkout.addon_included_seats`).
- **"Something went wrong. Please try again." for an upgrade that can never work by trying
  again.** A customer on a free plan who applies a recurring code covering the whole price
  on the plan screen sees a total of 0.00, clicks "pay" — and `confirmAndPay()` answered
  with the generic toast, because `grossTotal <= 0`. First given a message of its own;
  since the decision of 16 November 2026 the upgrade goes through a €0 authorisation
  instead (see the open decisions, point 4), and the message is gone again.

##### Test infrastructure

- **The browser suite was asking the real VIES service.** `WorkbenchServiceProvider` bound
  a `FakeVatCalculator` for `VatCalculator::class`, but mpociot's provider is deferred and
  registers its own binding the first time the class is resolved — replacing the fake.
  The workbench registers that provider eagerly before binding the fake. The VAT step went
  from about 40 s (and red) to about 13 s.
- **A browser on 127.0.0.1 could not show anything that depends on the visitor's country.**
  `IpGeolocationManager` answers `null` for a private address before it asks any driver.
  New opt-in workbench setting `client_ip`, applied to `REMOTE_ADDR` in the provider's
  `boot()`.
- **Browser runs of a matrix class hung for good — the dev server blocked on its access
  log.** `php -S` writes its access log to stderr, into a pipe only the pest process reads.
  testbench-dusk calls `clearOutput()` per test, which truncates Symfony's buffer but does
  not read the pipe. After about 64 KB of log the server blocks in `write()`, the test waits
  on ChromeDriver forever, and the shared test lock is never released. Classes with few
  tests never notice; data-driven classes hang after a few minutes. Fixed in
  `tests/Browser/BrowserTestCase.php` (`drainServerOutput()` in `setUp()` and `tearDown()`),
  documented in `docs/browser-testing.md`.

#### Deliberate

- **`plans.*.tier` has no browser test.** The package renders and prices nothing by it;
  the feature test pins exactly that. Waived in the inventory.
- **The plan screen admits recurring coupons only.** A `single_payment` code is answered at
  the field and the upgrade is priced without it. `UpgradeLocalToMollie::handle()` itself
  prices any code it is handed, so an app calling the service can use one.
- **A subscription checkout takes exactly one coupon.** Once one is applied the field is
  gone, so stacking cannot even be attempted in the browser.
- **A code on a free order is ignored, not refused, and not spent.**
- **A first-payment coupon on a trial discounts the trial-end charge** (until 24 November
  2026 it was refused with `coupon_not_with_trial` — see "The trial wins over a coupon").
- **100 % recurring coupon:** Mollie cannot hold a subscription of 0, so it holds the full
  price and its start date is moved past the coupon. The browser matrix asserts amount and
  start date and does not force a "renewal" Mollie would not make; the feature matrix
  travels to that date and books the full-price charge.
- **Catalog entries of the browser matrix are not in the workbench catalog.** They are
  added per test through the cross-process config channel (`WorkbenchSetting
  'config.<key>'`), so the shared catalog other suites count and click through is
  untouched.
- **`BookingMatrix::boot()` sets `wallet.cache.ttl = null`** — the test-harness artefact
  described under [The long-run harness](#the-long-run-harness), not package behaviour.

- **A customer who already has a mandate is asked for the €0 authorisation too** when
  upgrading from a free plan under a code that covers the price in full — identical to
  the checkout, which does the same for a fully covered order. One activation path and
  one basis for the retry: `RetrySubscriptionActivationJob` re-reads the Mollie payment
  it retries from, and a subscription created without a payment would have none. The
  authorisation also confirms that the payment method on file still works before a
  subscription is put on it. The confirmation says what happens
  (`portal.upgrade_authorisation_notice`): a short trip to the payment provider, nothing
  is charged. Pinned by `says on the confirmation that the customer is sent on briefly…`
  and `asks a customer who already has a mandate for the €0 authorisation too`.
- **This path is for a local subscription only.** A Mollie subscription that is cancelled
  is changed under the plan-change rules (`SubscriptionEndedChangeNotAllowedException`
  for what costs money); one that has expired books again through the checkout, where the
  same code goes through the same €0 authorisation. A LOCAL plan that has ended — a gift
  that ran out — upgrades this way like a running one. All three pinned in
  `FullCoverageUpgradeFromFreeTest`.

#### Open

Nothing. What stood here was closed on 16 November 2026:

- *The refusal `coupon_ends_before_first_charge` had a feature test only.* Browser case
  `test_a_dated_code_that_ends_before_the_trial_does_is_refused_at_the_field` — refused at
  the field, the first charge shown in full, and a code that outlives the trial is taken.
- *The products page rendered the purchase dialog for a customer who may not buy.* The
  dialog is rendered only where a purchase is possible. Seen red: `products: a customer
  who may not buy gets no purchase dialog at all`.
- *`addons.*.description` was handed to the checkout's extras step and not printed.* It is
  printed under the addon's name. Seen red: `checkout: the extras step prints an addon's
  description`; browser `test_the_extras_step_prints_an_addons_description`.
- *A free-plan customer with a 100 % recurring code could not upgrade on the plan
  screen.* Implemented — see
  [Open decisions for the maintainer](#open-decisions-for-the-maintainer), point 4.

### Area: changes to a running subscription

Inventory `tests/Coverage/changes.php`. Browser: `ChangeMatrixTest.php` (57 cells + 2
tests), `ChangeScreensMatrixTest.php` (21 tests).

#### Unwanted prorata payments are returned (cases 2, 3, 8)

A prorata payment that settles after its change was cancelled, or after the subscription
ended, is refunded automatically instead of being reported for a manual refund. Cancelling
a pending change whose payment can no longer be cancelled no longer alerts the admins.

- **Booked and credited, never refunded without a trace** — the rule of
  [A purchase priced on a gift is refunded](#a-purchase-priced-on-a-gift-is-refunded-not-booked-once-the-gift-is-gone).
- **A re-delivery resumes.** `ProrataChargeHandler::paid()` asks `resume()` before its
  invoice guard, so a refund that threw (and was not queued) is posted on the next
  delivery; one that is queued is left to `RetryRefundLineJob`.
- **Case 3 keeps `PlanChangeFailed`**: the change did fail; only the manual-refund mail is
  gone.
- **Case 8 keeps its marker** (`orphaned_prorata_payment`): it is what tells the paid path
  what the payment was for.
- **The admin mail comes from the dead letter only.** The first version mailed "queued for
  retry" on the first failed attempt — one service with one behaviour was preferred over a
  second retry ladder, and the question was left to the maintainer. Decided: only when the
  automatic way is exhausted. `UnwantedPaymentRefund::announceOnce()` no longer mails; the
  one mail is `RetryRefundLineJob`'s after seven days. The same was applied to
  `GrantExtrasPurchaseRefund` (see the open decisions, point 5).

#### A scheduled change that cannot be applied is retried, then dropped (case 9)

`ScheduleSubscriptionChange::apply()` no longer re-throws. A failure is retried on the
following nights (`MAX_APPLY_ATTEMPTS` = 3), then the change is dropped with a customer
mail (`ScheduledChangeNotAppliedNotification`) and an admin mail. A change the rules refuse
is dropped at once with a customer mail and no admin mail. `SubscriptionChangeApplyFailed`
is dispatched once, when a change is given up, not per failure.

- **Refusals are a whitelist** of domain exceptions; anything unknown is treated as
  transient, so a stored change is never dropped for a fault that is gone tomorrow.
  `InvalidSubscriptionStateException` is deliberately transient: its usual cause here is
  another change still waiting for its payment.
- **Seat usage is a refusal now.** A change blocked by seat usage used to be kept
  indefinitely; it is dropped and the customer told to release seats and schedule again.
- **The retry is the next night's dispatch**, not the queue's: `scheduled_change_at <= now`
  still selects the billable. No new job, no new schedule.
- **Three attempts**: enough to ride out an outage of a night or two, short enough that the
  customer hears within days.
- **"Apply now" is the exception.** With `apply()` resolving failures on its own, the
  screens' `catch` never fired: the portal said "Plan changed." when nothing had changed,
  and the admin panel said "Scheduled change applied." for a change it had just thrown
  away. That is right when nobody is watching and wrong in front of a person.
  `apply($billable, onRequest: true)` re-throws for the requested path — the change stays
  scheduled, no attempt is counted, the error is shown. Both screens also say "could not be
  applied yet" / "started" for a change that is only waiting for its renewal or its payment
  (`portal.flash.scheduled_change_not_yet`, `scheduled_changes.applied_pending_payment`).

#### Undercollection is charged back (cases 11 and 12)

`Services\Billing\UndercollectionCharge` (formerly `UndercollectionReporter`) collects the
difference as its own recurring payment with its own invoice, exactly once per case,
announced to the customer with amount and reason (`UndercollectionChargedNotification`).
`RenewalUndercollected` carries `$chargePaymentId`; it is null when nothing could be
charged.

- **Routing without touching the webhook controller:** the payment is
  `type: prorata_charge` with `undercollection: <case key>` in its metadata;
  `ProrataChargeHandler` hands such payments to `UndercollectionCharge::paid()` /
  `failed()` before anything else.
- **Failure is the renewal's failure path**, literally:
  `SubscriptionPaymentHandler::failed()`. Recovery from that past-due state is whatever
  recovers a failed renewal (the next paid renewal, a plan change, the dunning's
  auto-cancel). The failed back-charge itself is not retried by the package.
- **Not in PastDue / Cancelled / Expired, not without a mandate** → reported to the admins
  instead (`obstacle` in the context). Likewise when Mollie refuses to create the payment.
- **Lines that say what they pay for**, pointing at the renewal via `completes_invoice_id`
  (not `parent_invoice_id`, which marks a credit) and marked `undercollection`: the plan
  for a plan change, one line per component for seats and addons. (At first it was one
  line of `kind: undercollection` that no later credit read — which is what made a seat
  given back in that period come out at 3.08 instead of 3.39; see "Closed since the first
  review" below.)
- **VAT is the billable's current treatment** (rate, reverse charge), like any new charge.
- **Amounts are per-line rounded**: the spanned-upgrade example is 185.71 − 46.43 = 139.28.
- **Silent within one cent**, for discounts and waivers, and when the payment was priced
  for the current configuration — unchanged conditions, now deciding "charge" instead of
  "report".

#### Fixed

- **A code entered on the seat or addon form was not previewed on the amount due now.**
  `PreviewService::prorataLinesPreview()` took the prorata coupon discount from the legacy
  aggregate `prorataChargeNet`, which is only computed for a plan or interval change. For a
  seat or addon change the preview quoted the undiscounted amount (one seat, 21/31 days,
  AT: 4.07) while `UpdateSubscription::withCouponDiscountLines()` booked and collected the
  discounted one (3.26). The preview computes the coupon lines exactly as the booking does,
  and the coupon line carries its `code`. Seen red.
- **The seat and addon screens never said what is collected now.** They showed the
  recurring price only. Both now show the prorata charge or credit, from
  `PreviewService::previewUpdate()['prorataTotalGross']`.
- **Charged changes on a cancelled subscription were accepted, charged and refunded.** A
  plan or interval change on a cancelled-in-grace subscription collected the prorata (Pro →
  Business 113.88) and returned it in full at once, plan unchanged. For a seat (11.39) or a
  paid addon (5.64) the refund left the seat or addon active — free. Refused up front now
  (`SubscriptionEndedChangeNotAllowedException`, preview error `subscription_ended`), with
  the reason on all three screens. *Judgement:* seat-up and paid-addon-on had no rule of
  their own; they are refused as an application of the existing rule "a settled charge is
  never applied to a subscription that no longer runs".
- **A plan change kept addons the target plan does not allow.** Pro with `softdrinks` →
  free plan: source stayed `mollie`, the Mollie subscription was not cancelled, the addon
  stayed active and billed, while the preview announced its removal.
  `UpdateSubscription::buildContext()` filters now.
- **The plan screen said "refunded separately once this payment has been captured" for a
  pure credit.** A change that charges nothing reads "The credit of … is refunded to your
  payment method. Nothing is charged for this change." (`portal.prorata_refund_only`).
- **Surfaces for the automatic paths** — the retry state and the dropped notice of a
  scheduled change, the balance charges, the refunded unwanted payment — are shown to
  customer and operator; see "What the package resolves on its own is still shown" in
  `docs/plan-changes.md`.

#### Deliberate

- The plan screen shows the charge and the credit as two figures, not one netted total:
  they are two movements at Mollie (a payment, and a refund on the old payment).
- A seat or addon is active as soon as it is ordered, while its payment is still open; a
  plan or interval change waits for the payment. A declined payment takes the seat or addon
  back and re-patches Mollie.
- `plan_change_mode`: an explicit `apply_at` the mode forbids is refused, not converted.
  The screen only offers what the mode allows.
- A percentage coupon discounts the amount it was granted on (`base_amount_net`), so an
  upgrade under a 100 % coupon charges the difference.
- Under a 100 % coupon a seat taken away shows no credit line and refunds nothing — it was
  never paid for.
- "Withdraw the pending change" on the plan screen belongs to a plan change whose prorata
  payment is still open (`pending_plan_change`), not to the free-plan upgrade.
- A money cell of the browser matrix may not pass on 0 == 0: under none / percent / fixed
  the charge or credit shown must be greater than zero.
- The browser harness schedules the renewal that pays the running period for *yesterday*
  at the test Mollie, because the package anchors the period to the day Mollie scheduled
  the charge for. A renewal fired "today" put the period a month into the future (factor
  61/31) and produced an overage charge and a back-charge that no real sequence would.
- The state-matrix expectations were derived from the rules and written down before the
  exploration run was read. One derivation was wrong and was corrected against the
  document, not against the run: a plan change during a trial pays "for the rest of the
  local period", not a full first period.
- The free plan of the workbench catalog (`changes-free`) is listed hidden and switched
  visible by the tests that need it, so no other matrix gains a card.
- **A declined balance is not collected again.** It is one payment and the ordinary
  dunning — the maintainer's decision for cases 11/12: no second retry ladder. It stays
  "Declined" on the billable's subscription tab, where the operator can charge it by hand,
  until the subscription recovers by a paid renewal; a plan or interval change closes it
  ("Declined — closed by a plan change"), because the configuration it was priced for is
  gone. Collected late by Mollie all the same, it is kept for the days that configuration
  ran and returned for the rest.
- **A yearly subscription whose date waiver ends inside the running year**: the last waived
  renewal covers the whole year. A waiver is counted in charges, not in days; see the
  coupon area.
- **A balance's component lines round their VAT per line; the document's total is exact.**
  The balance is charged as one amount (`shortfall + round(shortfall × rate)`) and announced
  so; its lines carry the VAT rounded each on its own, and the difference — at most one
  cent — is settled on the largest line. Two lines of 5.50 at 19 % book 1.04 + 1.05 = 2.09,
  the 13.09 the customer paid, where each line's own rounding would say 1.05. The total
  collected, the total booked and the VAT declared are the same to the cent; only one
  line's split is a cent off its own rounding. Pinned at exactly one cent:
  `ChangeMatrixBalanceComponentLinesTest`, "keeps total and VAT…".
- **A component paid half by the renewal and half by its balance is credited as two
  lines, each rounded like any credit-note line.** One seat in the debit, one added after
  it: the renewal and the balance each carry half of each seat, and a seat given back is
  2.50 × 19/28 = 1.70 twice — 3.40 where the seat as a whole is 3.39. The cent goes to the
  customer, and the per-invoice pool still bounds both documents. Rounding per line is what
  every invoice and credit note of the package does; a single figure split back over two
  payments would need a second rounding rule. Pinned at exactly one cent: same file,
  "credits each seat at 5.00…".
- **A balance written before the component lines keeps its old reading.** Its case holds one
  `undercollection` line and its renewal's adjustment names no lines, so nothing says which
  component it paid: it is booked, shown and listed as before, a seat given back is credited
  from the renewal at the adjustment's spread share (5.00 × 50/55 — the old 3.08), and a seat
  given back while it is still open notes nothing. A plan or interval change still closes it
  and returns its unused days if it arrives late. Guessing the composition after the fact
  was not done: it only concerns balances open across the upgrade, within one period.
  `ChangeMatrixBalanceEdgeCasesTest`, "…from before the component lines…".
- **A charged-back renewal is an unpaid period, also when a declined balance is on top.**
  Whichever came first, and also for a partial chargeback that leaves money on the invoice:
  a plan change from there is the reset, nothing is credited out of the renewal, the dunning
  keeps the clock of the first failure. `runningPeriodIsPaid()` reads the standing chargeback
  off the books (`ChargebackHandler::hasStandingChargebackBookings()`), because the later
  failure overwrites `payment_failure.reason` — reading only the reason made a partial
  chargeback followed by a declined balance an ordinary change (seen red).
  `ChangeMatrixBalanceEdgeCasesTest`, "…charged back…".

#### Counter-checks

Each automatic path was switched off, one at a time, and its tests run:

| Switched off | Red |
|---|---|
| The back-charge (`UndercollectionCharge::settle()` returns at once) | 16 of 26 in `ChangeMatrixKnownLimitsTest` |
| The "once per case" claim | 1 |
| The obstacle check (status / mandate) | 3 — PastDue, Cancelled, Expired |
| The one-cent slack (`TOLERANCE = 0`) | 1 |
| "Only when the payment was priced for another configuration" | 1 at first; sharpened by a new test (pct50, fixed, waiver), now 4 |
| The refund in `UnwantedPaymentRefund::settle()` | 10 of 12 in `AutoRefundUnwantedProrataPaymentTest` |
| `UnwantedPaymentRefund::resume()` | 0 at first — no test told it apart from the invoice guard. New test "finishes the refund on a re-delivery when the first run booked the payment and got no further"; red without `resume()` |
| The retry of a scheduled change | 2 of 5 in `ScheduledChangeAutoRetryTest` |
| The refusal whitelist | 1 |

For the record: the first version of the switching script changed a file before it held
the shared test lock; `UndercollectionCharge.php` stood switched off for a few minutes
while other runs could see it, and was restored from its backup at once.

#### Closed since the first review

Reviewed on 16 November 2026. Nothing in this area is open: every entry below is closed,
and the two limits that are kept on purpose stand under "Deliberate" above.

- *Every state crossed with every change kind, the back-charge and the scheduled-change
  retry in the browser* — `ChangeMatrixTest` (state matrix, 27 cells) and
  `ChangeScreensMatrixTest` (balance charge paid / declined / not possible; attempts 1–3,
  dropped, refused).
- *A second change after a plan change that settled across a renewal* — fixed, see
  [plan-changes.md](plan-changes.md#a-period-whose-plan-change-settled-after-the-renewal);
  also under a fixed-amount coupon, after an interval change, and while the balance of an
  upgrade is still open (only what is paid is credited; a balance that arrives after its
  plan was left is returned except for the days the plan ran —
  `UndercollectionCharge::configurationLeft()`).

- *`UnwantedPaymentRefund` for a billable without a billing country* — tested and fixed:
  the money was exact (booked, credited and refunded in full), but the document carried the
  fallback country's rate (19 % on a payment priced at 20 %). The rate is now read off the
  last document the billable was charged with (`rateThePaymentWasChargedAt()`).
  `AutoRefundUnwantedProrataPaymentTest`, "…has lost its billing country…".

- *A scheduled change whose attempt fails after its prorata payment was created* — tested
  as a sequence, and a defect found and fixed. Reproduced red
  (`ScheduledChangeRetryAfterPaymentTest`, 7 of 11 at first): the following nights created
  no second payment (the validator's pending-change guard), but when the change was dropped
  after the third attempt only `scheduled_change` went — `pending_prorata_change` stayed,
  the payment settled days later and Phase 2 carried out the change the customer had been
  mailed was "not carried out". Now the attempt that created a payment notes its id on the
  stored change (`ScheduleSubscriptionChange::OWN_PAYMENT_KEY`), and `cancel()` — the one
  door for dropping, refusing and withdrawing — hands it to
  `UpdateSubscription::withdrawPendingChangeForPayment()`: payment cancelled where Mollie
  allows, waiting state cleared, seats/addons and the Mollie amount put back, and a payment
  that settles anyway returned by `UnwantedPaymentRefund` (`change_cancelled`). Judgement
  calls:
  - **Dropped means dropped, also for a payment that has already settled at Mollie.** The
    portal's cancel button leaves such a payment to the webhook to apply; here the customer
    has been told the change is off, so the webhook returns the money instead. One rule —
    "after the mail, nothing of this change happens" — rather than an outcome that depends
    on whether the webhook or the nightly run came first.
  - **Attempts keep counting while the change's own payment is in flight.** Clearing the
    stored change on the second night ("it is in flight, Phase 2 will finish it") was
    considered and not done: the attempt failed for a reason nobody has looked at, and a
    change that ends in a refund is safer than one that completes on a half-written state.
    If the payment settles before the next night, Phase 2 does complete it and the next run
    only clears the stored change.
  - **Only its own payment.** A waiting change that names another payment (an immediate
    seat change made the same week) is left alone; tested.
  - **A payment whose creation went unanswered** (Mollie accepted it, the answer was lost or
    the write recording it failed) — closed as well. An idempotency key does not help:
    Mollie keeps it an hour, the retry is a night away. Instead `createCharge()` notes the
    payment before the call (`prorata_charge_in_creation`, token also in the payment's
    metadata), the way the overage path recovers a lost payment from the customer's payment
    list. Red first (`ProrataChargeInCreationTest`); the take-over switched off inside a
    held run turns it red again. Judgement calls: the next attempt adopts the lost payment
    only for the *same* intent — a different change gets its own payment and the lost one
    is cancelled or returned; Mollie unreachable means *no* payment is created (the attempt
    fails), because "unknown" is not "none"; a note nobody takes over is dropped after 48
    hours, so a late settlement is returned rather than carrying out a change from two days
    ago.
  - **Mollie unreachable when the change is given up (`lookup_failed`)** — the change is
    withdrawn regardless; `CleanupStalePendingProrataChangeJob` retries the cancel daily
    until Mollie answers.
- *Per-seat addon rows on a plan-change preview* — `ops-team-plus` (hidden, 6 seats
  included) is a second plan that allows `ops-crm`. `ChangeMatrixTest`, "a plan change
  previews, charges and books the per-seat addon on its own row", up and down: the row and
  its amount are computed in the test from the rule (billed addon seats = max(0, seats −
  the addon's included seats), seats never below what the plan includes) and compared with
  the screen, the `addon_seats` lines of the invoice and the credit note, the amount Mollie
  holds and the next renewal.
- *An interval change under every discount state* — under each waiver state (ends on a
  date, continues paid, counted in periods, with a gifted addon) and in both directions it
  is refused on screen with its reason and nothing moves (8 cells); under each coupon state
  (percentage, fixed, 100 %) it is a money cell in both directions (6 cells, 4 of them
  new). An addon with seats of its own included (`ops-crm` with `included_seats` 2, set per
  test): two further preview cells, one of them landing on no billed addon seat — no row,
  no line, not a row at 0.

- *A plan change from past due on a paid period* — was a limit ("the reset even when the
  renewal was paid and only a balance was declined": 20.00 charged for a February already
  paid at 20.00), now fixed. `BillingPolicy::runningPeriodIsPaid()` separates the two ways
  of being past due; `isPastDueReset()` asks it, so preview, context, composer and the
  Mollie start date follow from one predicate. The change is the ordinary one, it closes
  the declined balance (`UndercollectionCharge::configurationLeft()`) and ends the dunning.
  Seen red: 20 of 23 in `ChangeMatrixPaidPeriodPastDueTest` with the predicate switched
  off. See [plan-changes.md](plan-changes.md#past-due-on-a-paid-period-is-not-a-reset).
  Judgement calls:
  - **"Paid" is read off the period, not off the cause of the dunning.** The period has
    not ended and an invoice that collected money states a line for it. After a failed
    renewal the anchor stays on the period that ran out, so the same test says "unpaid"
    there without knowing anything about balances. A chargeback is excluded by its reason:
    the renewal's invoice is still `paid` then, but the money is gone.
  - **The seven days are not collected.** In the pinned example the customer held Pro for
    seven days whose balance (7.50 of 27.86) was declined; the change closes that balance
    instead of charging those days with the change. That is the rule below ("a declined
    balance is not collected again") applied here, not a new one. If the payment arrives
    after all, the customer has paid exactly plan × days: 13.93 for 1–10 Feb.
  - **Only a plan or interval change ends this dunning** — it is the one change that
    leaves the configuration the balance was for. A seat or addon given back does not.
  - **`planLeft()` became `configurationLeft()`**: it runs on an interval change as well and
    covers seat and addon balances, because a plan or interval change credits and
    re-charges the whole configuration. The balance of a plan change is still left only
    when *its* plan is — it is claimed in the same step that announces the change into
    that plan, and the first version of the generalisation marked it as left at birth
    (caught by the interval test: the late balance came back in full).
- *The balance of seats and addons has component lines* — was a limit (a seat given back
  credited 3.08 instead of 3.39), now fixed. Seen red: 10 of 11 in
  `ChangeMatrixBalanceComponentLinesTest` with the components switched off. Judgement calls:
  - **The renewal's `adjustment` says what it reduces** (`reduces`, line key => net),
    written by `SupersededRenewalRefund` in the delivery that books the renewal. Without
    it the balance line alone made one seat exact and every other credit in the period
    wrong the other way (the plan read as partly unpaid). It is document metadata: no
    amount, total or printed line changes.
  - **One position per component on the balance, credited in the share of the renewal
    line's units.** With one seat in the debit and one added after it, both documents
    carry half of each seat; a seat given back is two credit lines of 2.50 × f, not one of
    5.00 × f. Rounding is per line, so the pair can differ from the single figure by a cent.
  - **Total and VAT of the balance are pinned to the single amount**: VAT per line, the
    difference to `round(shortfall × rate)` settled on the largest line. A line's VAT can
    therefore be a cent off its own rounding (two lines of 5.50 at 19 %: 1.04 + 1.05).
    Chosen over `grossWithLineVat()` because the amount the customer was announced and
    charged must not depend on how many lines describe it.
  - **A component given back while its balance is out** gets nothing for the balance's
    share now and the unused days back when the balance settles
    (`componentsGivenBack()`). Without this the component lines would have made that
    sequence worse than before (the whole seat paid for nine days of use).
- *Fixed-amount coupon, seat and addon lines after a downgrade that settled across the
  renewal* — was a limit (up to 1.04 too much credited), now fixed:
  `ProrataComposer::capAtCurrentDiscountShare()`. Seen red: the two `fixed`/`down` plan
  cells of "settles a second change…" in `ChangeMatrixRemainingCellsTest` (105 and 106
  off) with the cap switched off. Scoped to a fixed-amount marker and to lines of a
  renewal booked for another plan; a percentage is the same share on any configuration and
  a waiver names its lines.

### Area: coupons and free time

Inventory `tests/Coverage/coupons.php`. Browser: `tests/Browser/Matrix/Coupon*MatrixTest.php`
and `Admin{CouponForm,SubscriptionTab,Grant}MatrixTest.php`.

#### Fixed

##### A second, identical admin action within seconds is refused

"Extend by 14 days", "plan free for 2 periods" and "extend the gift by 30 days" each mint a
fresh code, so nothing made a repeated request a no-op: a double click gave twice. Livewire
sends such requests one after the other, so a lock released when the first finishes lets
the second through. `AdminExtensionService::once()` therefore claims action + amount
before the action runs and keeps the claim for `REPEAT_GUARD_SECONDS` (5) after a success;
a refused or failed action removes it at once, so a corrected retry is not told to wait. A
different amount is a different action.

The claim was first a cache lock. On the `array` store that lock lives in one process's
memory and on `null` every lock is granted — and the operator's two clicks are two
requests. It is now an entry in `subscription_meta.admin_extension_guard`, written under
the billable's row lock (`MutatesBillingMeta`) before the action and settled after it:
shared by every process whatever the cache store, so `billing:check-config` has nothing to
report about it. A claim still marked running holds for `RUNNING_GUARD_SECONDS` (120) — a
second tab arriving while the first is still talking to Mollie is refused, and one whose
process died does not lock the operator out for longer. Expired entries are dropped on the
next write.

##### A discount that has run out no longer holds the recurring slot

The marker is cleared by the renewal after its end. Until then — a period away for a
deactivated coupon, weeks away when extension days keep the charge deferred — `validate()`
answered "another recurring coupon is already active" about one that discounts nothing. A
marker that `isMarkerStillRedeemable()` rejects is treated as absent; the new redemption
overwrites it.

##### The redemption that could not be recorded is an audit entry, not a mail

`FirstPaymentCouponPricing::compensateFailedRedemption()` mailed the admins whenever a
coupon's row could not be written after the payment (the campaign filled up while the
payment travelled). The discount is in force — priced into the payment, and a recurring
coupon gets its marker — and nobody can add the row behind the coupon's own limit check. It
is `Log::warning` plus the event `CouponRedemptionRecordMissing` (audit key
`coupon_redemption_record_missing`). The mail remains for the one case that needs a
person: the recurring marker could not be written either, so Mollie collects a discount
the books do not know.

##### A recurring code entered with a seat or addon change left Mollie on the full price

`UpdateSubscription::update()` PATCHes Mollie in phase 2c and redeems the coupon in phase
3 — and the PATCH is priced from the marker the redemption writes. Pro 50.00 + two seats
10.00 with a 20 % code: Mollie was sent 72.00 instead of 57.60. For a paying customer that
lasted until the prorata charge settled and Phase 2 patched again (days, with SEPA — a
renewal in between was collected in full); during a trial, where the change costs nothing
and no Phase 2 follows, it lasted for good: every renewal collected the full price and was
booked with an `adjustment` against the discount the customer had been shown. `update()`
first re-priced once more after everything had committed (`PlanWaiver::syncMollieOrQueue()`).
Four cases, all red against the unchanged file.

That left Mollie on the undiscounted amount for the length of one request, so the PATCH
itself is now priced right. The redemption stays in phase 3 (`CouponService::redeem()`
with its `lockForUpdate`, inside the change's own transaction and never around a Mollie
call); what moves to phase 1 is the *marker* it will write:
`CouponService::recurringMarkerFor()` builds it with the same arguments, and it travels on
`PlanChangeIntent::$couponMarker` to every PATCH of the change — the executor's (sidegrade,
refund) and phase 2c's. `MollieSubscriptionPatcher` prices from it instead of the marker
on the row, and reads its end for a fully covering code's deferral date. It is not
serialised: a queued retry runs after the commit and reads what was actually written.

- **A PATCH that fails** is queued as before; the redemption is written with the change, the
  retry prices from it. The re-price after the commit remains for exactly this case (the
  discount did not reach Mollie) and is skipped otherwise — one PATCH, not two.
- **A phase 3 that rolls back** after the discounted PATCH (the campaign filled up in the
  meantime, a lock wait, a listener) leaves no redemption — and Mollie would collect a
  discount the books do not know. `undoingUnbackedDiscount()` re-prices Mollie from the
  committed state and re-throws. A second attempt then redeems once.
- **The legacy path** `applyPendingPlanChange()` (reached from `SingleChargeHandler` for
  metadata the package no longer emits) PATCHed before it redeemed as well, with no re-price
  at all. It now builds the marker the same way — from the codes `redeem()` would still
  take (`CouponService::hasRedemptionLeft()`: campaign cap and per-customer cap, asked
  before the PATCH without a lock; an expired, switched-off or non-recurring code needs no
  check, its marker discounts nothing). Where the redemption fails all the same (it logs,
  the charge has settled) Mollie is re-priced to the marker that exists. Its test found a
  second fault there: the phase-3 closure did not `use` `$pendingData`, so the codes were
  read from an undefined variable and nothing was ever redeemed on that path — no row, no
  marker, for a charge Mollie had collected with the discount.

##### A gift given to continue as paid, ending before the first renewal, gave a plan period away

`LocalToMollieUpgradeHandler::finishGrantCarryOver()` only knew the `end` case. With
`continue_paid` and a gift shorter than the first period, the subscription stayed at the
extras-only amount: the first renewal — the first charge after the gift — collected 18.00
for a period that costs 78.00. The step the renewal handler takes when a waiver will not
cover the next charge (full price, marker cleared) is now taken at carry-over too.

##### Smaller ones

- **The coupon form could not restrict a discount to a plan or an interval.**
  `applicable_plans` and `applicable_intervals` were enforced by `validate()` and settable
  only in code. The form has them (discount kinds only). `applicable_usages` is
  deliberately not offered: nothing in the package can satisfy it, so a coupon carrying it
  is refused everywhere — that is tested, and shown on the coupon page.
- **The coupon page did not show what a coupon gives**: `waiver_periods`,
  `grant_on_expiry`, and the plan / interval / usage restrictions were invisible.
- **A duplicate code was "something went wrong (23000)".** `CouponService::create()`
  refuses an existing code (case-insensitively) with a sentence, before the unique index
  does.
- **A refusal from a prompt was shown where the operator was not looking** — in the banner
  at the top, while the prompt is opened from the bottom of the page. Both prompts of the
  subscription tab show the refusal inside themselves as well.
- **A used-up single-payment code was answered with where to enter it.** The portal says
  the code is used up.
- **Editing a redeemed recurring coupon's `discount_periods` changed running grants.** The
  value and the end date travelled with the marker, the count was read off the coupon at
  every renewal: cutting six periods to one in `CouponService::update()` ended every
  holder's discount after their first charge. The marker keeps `discount_periods`; a marker
  written before reads the coupon as before.

#### Deliberate

- **A dashboard code field in every state.** The 7 × 7 matrix pins what each type does in
  each state. Notable cells: a plan-waiver code on a cancelled Mollie subscription is
  accepted and waits for the resubscribe; a period extension on a past-due subscription
  whose renewal is overdue is `too_close_to_charge`; a gift code on the free plan is
  "another plan".
- **Free plan periods on a cancelled Mollie subscription are kept for the resubscribe**:
  nothing is PATCHed, the waiver is counted from the charge the resubscribe will make.
- **Unmarked renewal rows of an older release count as redemptions** — pinned now: with a
  per-customer limit above 1 a second redemption is refused until the rows are marked.
- **A 100 % code over N periods first charges N billing periods after the first covered
  charge — decided and changed on 17 November 2026.** Until then it deferred to
  `first charge + N × 30 + 2` days (pinned to the day for 1, 2, 3, 6 and 12 periods, never
  more than three days from the cycle), and the cadence moved to that day for good: a
  customer on the 31st was charged on the 3rd. The maintainer decided that a coupon's window
  counts the subscription's billing periods; see
  [A recurring code's window counts billing periods](#a-recurring-codes-window-counts-billing-periods-not-days-decided-and-changed-17-november-2026).
  `CouponRoundFourGapsTest` › "defers a fully covering portal code to the charge N periods
  after the first, on the billing day" now pins 31 Mar / 30 Apr / 31 May / 31 Aug /
  29 Feb 2028 for a period begun 31 January (red against the previous rule, 5 of 5).
- **The fake Mollie of the browser suite honours a patched `startDate`.** It ignored it, so
  no browser test could see a charge date "at Mollie". Additive, and the measured behaviour
  of the real API (`tests/Sandbox/MollieChargeDateContractTest`).
- **The coupon form is clicked in the browser matrix, not set.** Intent cards, selects,
  checkbox groups, single checkboxes and the list's row boxes are clicked through WebDriver
  (`AdminCouponFormMatrixTest::choose()`, a folded section unfolded by its heading).
- **The two `datetime-local` fields of the coupon form are not typed into.** What keyboard
  entry into such a field means depends on the browser's locale (order of day, month and
  year, 12- or 24-hour clock), so a typed date would test Chrome's locale, not the form.
  The value is put into the input and announced with the `input` event a typed value raises
  (`AdminCouponFormMatrixTest::enterDateTime()`); it travels through the field's own
  `wire:model`, and the test asserts the stored UTC timestamp and the coupon page.
- **Clearing the marker at carry-over also ends "extend the gift"** (a `continue_paid` gift
  shorter than the first period). There is no gift left to extend: the plan became
  chargeable when the extras were bought, exactly as after the last waived renewal of any
  gift. `extendGrant()` refuses with a sentence, and the operator gives free time anew —
  "plan free for N periods" (`freePlanPeriods()`) or "extend by days". Pinned:
  `GrantKeepsRunningWithPaidExtrasTest` › "leaves no gift to extend once the plan became
  chargeable at carry-over…" — refused, then one free plan period, Mollie at the extras-only
  18.00, and the renewal booked at 15.00 net.
- **The legacy path can still send a discount for a moment when two redemptions race for
  the last slot.** Whether the code can be redeemed is asked before the PATCH, without a
  lock (none may be held across a Mollie call); `redeem()` asks again under
  `lockForUpdate`. Only a second redemption of the last slot in between makes the second
  answer differ — then the re-price after phase 3 puts Mollie on the full amount within the
  same request, and the end state matches the books: change in force, nothing redeemed, no
  marker. Pinned: `RecurringCouponWithChangePatchesMollieTest` › "puts Mollie on the full
  price when another customer takes the last slot…" (57.60, then 72.00). It is the one
  place left where Mollie holds an amount it should not, and only for that request.
- **There is no screen to edit a coupon.** An offer is changed by deactivating the code and
  creating a new one; deactivating (page and bulk) and deleting an unused one are tested in
  the feature and the browser suite. Coupons are not immutable in code, though:
  `CouponService::update()` exists for apps, refuses a new code or type once redeemed, and
  otherwise changes the offer for redemptions from then on. A running grant keeps its terms:
  the marker carries type, value and end date — and now the number of discounted charges as
  well, which `isMarkerStillRedeemable()` read off the coupon (see Smaller ones).

#### Counter-checks

| Finding | Test | Without the fix | With it |
|---|---|---|---|
| Identical admin action within seconds applied twice | `CouponRoundFourGapsTest` › "arrives twice" (three actions) | 3 failed | green |
| Run-out / deactivated recurring marker blocks a new coupon | `CouponRoundFourGapsTest` › "accepts a new recurring coupon once the one in the slot has run out" | 2 failed | green |
| Duplicate code is a `QueryException` | `CouponBasicsAuditTest` › "treats a code as case-insensitive…" | 1 failed | green |
| Coupon page does not show `waiver_periods` | `CouponFormIntentTest` › "shows on the coupon page everything…" | 1 failed | green |
| Used-up single-payment code answered with where to enter it | `RedeemCouponFromPortalTest` › "says a single-payment code is used up…" | 1 failed | green |
| First PATCH of a change with a recurring code undiscounted (marker built late) | `RecurringCouponWithChangePatchesMollieTest` › "sends the discounted amount with the one and only PATCH" (5 cases), "puts Mollie back when the change fails…", "…on the legacy path" | 7 failed | green |
| Legacy path redeemed nothing (`$pendingData` not in the closure) | same file › "…on the legacy path" | 1 failed | green |
| Repeat guard in the cache: second process gives again (`array`, `null`; running action) | `CouponRoundFourGapsTest` › "refuses the identical admin action from a second process…" (4 cases), "keeps an identical action out while the first one is still running…" | 5 failed | green |
| Editing `discount_periods` cut running grants | `CouponBasicsAuditTest` › "leaves running discounts at the number of periods…" | 1 failed | green |
| 100 % code with a change: date and single PATCH; addon given back with a code (one PATCH) | `RecurringCouponWithChangePatchesMollieTest` › "defers Mollie's first charge…" (2), "credits an addon given back…" — marker not built in advance | 3 failed | green |
| Rollback after the discounted PATCH not put back (also when that fails and is queued) | same file › "puts Mollie back…", "queues putting Mollie back…" — `undoingUnbackedDiscount()` without its re-price | 2 failed | green |
| Legacy path keeps a discount it could not redeem | same file › the race case — without the re-price after phase 3 | 1 failed | green |
| Legacy path sends a discount first for a code it cannot redeem | same file › "sends the legacy path's one PATCH undiscounted…" — without `hasRedemptionLeft()` | 2 failed (campaign full, used up) | green |
| A "running" claim held only the short window | `CouponRoundFourGapsTest` › "lets an action whose process died run again…" — running entries at `REPEAT_GUARD_SECONDS` | 1 failed | green |
| Real Mollie after a change with a code, re-price off | `MollieRecurringCodeContractTest` › "…with_the_first_patch" — marker not built in advance | 1 failed | green |

Not counter-checked this way: the form's plan / interval fields and the `grant_on_expiry` /
restriction rows of the coupon page (views; their tests were written together with the
change), and the prompt's inline refusal, which was found by a failing browser test before
it was fixed.

#### Open

Nothing. What stood here was closed on 16 November 2026:

- *Between phase 2c and the re-price Mollie held the undiscounted amount.* The PATCH is
  priced from the marker the change is about to write — see "A recurring code entered with
  a seat or addon change…" above. Seen red: seven cases.
- *The repeat guard lived in the cache.* It is kept on the billable's row — see "A second,
  identical admin action within seconds is refused". Seen red on the `array` and `null`
  stores and for an action still running. `billing:check-config` needs no new check: the
  guard no longer depends on the cache.
- *Clearing the marker at carry-over also ended "extend the gift".* Deliberate — see above;
  the way to give free time again is pinned.
- *Not shown in a browser:* the coupon form is now clicked (`AdminCouponFormMatrixTest`);
  editing has no screen (Deliberate, above — deactivation is tested in both suites); and the
  recurring PATCH against real Mollie is `tests/Sandbox/MollieRecurringCodeContractTest`:
  a 20 % code from the portal (Mollie reads back 48.00, date unchanged), a 100 % code from
  the portal (60.00, next payment on the stored deferral) and a code with two seats during
  a trial (57.60, trial end unchanged). The last one switches the re-price after the commit
  off, so it reads what the change's own PATCH sent; against the earlier ordering it is red
  (72.00).

And what the first closing left untested, now pinned:

- *A 100 % code with a change:* one PATCH at the full price whose start date is the day
  after the end of the marker as written, equal to `ChargeDeferral::until()` afterwards —
  for a code limited by `discount_periods` and one limited by `valid_until`.
- *Putting Mollie back after a rollback fails as well:* `plan_waiver_sync` is queued, its
  retry sets the committed, undiscounted amount (60.00), nothing stays redeemed.
- *An addon given back with a code:* the refund path — a credit note for the unused share
  (15.00 × 21 / 31 = 10.16 net, 12.19 paid back at Mollie), a redemption with no discount
  (nothing is charged now), one PATCH at the discounted renewal amount (48.00).
- *The legacy path with a code it cannot use:* campaign full, used up by the customer,
  expired, switched off or not recurring — exactly one PATCH, at the full amount (72.00).
  Seen red for the two caps, which reached Mollie as a discount first and were taken back.
- *A claim left "running" by a process that died* holds for `RUNNING_GUARD_SECONDS` and
  not longer: refused at 6 s and at 119 s, run at 121 s; a different amount runs meanwhile.
- *The other browser files of this area* run green with the guard on the row.

### A recurring code's window counts billing periods, not days (decided and changed 17 November 2026)

**Decision (maintainer, 17 November 2026).** A recurring coupon limited by `discount_periods`
counts the subscription's own billing periods — calendar months or years on its billing day,
"the anchor day, limited to the length of the month" (`Support\BillingAnchor`) — not 30- or
365-day blocks. After N periods Mollie charges on the regular date and the billing day stays.
The invoice, the marker (`valid_until`), `ChargeDeferral`, `NextChargeSummary` / the portal and
Mollie's `startDate` name the same date. A coupon with a fixed end date (`valid_until` set by an
admin) stays bound to that date.

This replaces the deliberate rule "a 100 % code over N periods defers the first charge by
N × 30 + 2 days" (coupons area, Deliberate; and the month-end entry of the lifecycle area). A
customer with a billing day of 31 (or any day) was charged on the 3rd after the free time —
three periods from 31 January: 3 May — and on the 3rd for good.

**How it is built.**

- `CouponService::recurringMarkerValidUntilOnCycle($coupon, $interval, $from, $anchorDay)`:
  the earlier of the coupon's `valid_until` and `BillingAnchor::advance($from, $interval, N,
  $anchorDay) − 1 day`. `recurringMarkerValidUntil()` keeps its three-parameter signature (an
  app may override it) and counts on `$from`'s own day.
- `recurringChargeResumesAt()` and the marker key **`charge_resumes_at`**: the first date of the
  cycle from `$from` whose day begins at or after `valid_until`
  (`BillingAnchor::firstOnOrAfter()`). For a count that is `from + N` periods; for a date it is
  the first regular charge on or after that day — a renewal is discounted while the code is
  valid at the start of its day (`SubscriptionPaymentHandler` asks on the charge's day), so a
  code ending at noon on a renewal day covers that renewal and the next one is the first
  charge. `CreateSubscription` and `MollieSubscriptionPatcher::fullCoverageStartDate()` send it
  as the start date; `ChargeDeferral` stores it; the checkout's and the plan screen's "first
  charge on …" ask the same method.
- `from` is the start of the first discounted period: now at a checkout or an upgrade; the
  **trial end** on a trial signup (`applyRecurringMarkerFrom()` — a method of its own, because
  PHP refuses an override of `applyRecurringMarker()` with the old signature, which
  `FailedRedemptionAfterPaymentTest` does); the next charge on the dashboard;
  `CouponService::recurringLifetimeFrom()` for a change — the running period when the code
  discounts the change's own charge, otherwise the next charge, and for an interval change
  now. `UpdateSubscription` decides it once for the PATCH's marker and the redemption, so the
  two cannot name different days. `redeem()` uses the same rule when a caller passes no
  `lifetime_from` (the prorata webhook).
- `recurringAnchorDay()`: the recorded billing day where `from` is the running period, the day a
  shortened charge stands for otherwise (`BillingAnchor::forCycle()`).
- `MollieSubscriptionPatcher::updateForIntent()` judges a coupon's discount for the day the
  amount is next collected (`CouponService::computeDiscountOfMarkerOn()`), as the renewal does,
  not for today. Needed once the running period can be a code's first: a 50 % code for one
  period entered with a seat change ends before the next charge, and judged for today the
  PATCH sent the discount to Mollie for a renewal the books charge in full (seen in the matrix
  while building it). A plan waiver is judged for today as before — its end is the renewal
  handler's to settle.
- `BillingAnchor::forCycle()` also takes the **trial end's day** for the first charge after a
  trial. Found by the new matrix: a trial ending 31 January under a code for one period was
  first charged on 28 February (correct) and then on the 28th for good — nothing had recorded
  the 31st, because a trial's period starts at the signup and is not turned over while the
  charge waits. Seen red in the matrix before this part was added: 6 cases (31st × N = 1, 3;
  29 February yearly; both cadences).

**Judgement calls.**

- **The running period counts when the code discounts the change's own charge.** A plan
  upgrade with a 100 % code on day 10 pays the prorata less the code (or, yearly, nothing at
  all — the code covers the charge less the refund) and that redemption carries a discount, so
  it is application 1: two periods are the running one and the next, Mollie collects again on
  the second renewal. With N = 1 there is no deferral at all. The count check
  (`isMarkerStillRedeemable()`) has always counted that redemption; the window now agrees with
  it. A seat or addon change whose prorata the code discounts is treated the same way —
  without it a 50 % code over N periods discounted the prorata plus N renewals.
- **The trial does not eat the code.** Counted from the signup, "three periods free" behind a
  14-day trial were first charged 92 days after the signup; now three periods after the trial
  end. A partial code on a trial discounts the N charges from the trial end on (it did before,
  by the count; the window now matches).
- **Markers written before keep their date.** They carry no `charge_resumes_at`; every reader
  (`CreateSubscription`, `MollieSubscriptionPatcher`, `ChargeDeferral::derivedFromCoupon()`) falls
  back to `valid_until + 1 day` for them, and the renewal judges them by their `valid_until` as
  before. Nothing running is moved. Where no deferral is stored, `ChargeDeferral` derives one
  from a marker that carries `charge_resumes_at` only when that date lies past the regular
  next charge.
- **A code that covers only the running period defers nothing.** A 100 % code entered with a
  seat change and ending before the next renewal is sent at the full price with no start date;
  the next charge is the regular one.

**Tests** (run against the previous state of the source in a held run, files restored byte
for byte afterwards):

| Test | Against the previous state | Now |
|---|---|---|
| `CouponWindowInBillingPeriodsTest` › "first collects a fully covering code on the charge N periods on, on the billing day" — checkout, trial, upgrade from free, dashboard, plan change, seat change × N = 1, 2, 3 × the 15th / the 31st × monthly / yearly, plus 29 February yearly (N = 2) × both Mollie cadences: Mollie's start date, the next charge, the portal, `valid_until` = the day before, nights through the free periods, three renewals on the billing day | 154 red | 154 green, 2 skipped (dashboard × 29 February: no yearly cycle reaches it from a year before) |
| same file › "discounts exactly N charges with a partial code, on the billing day" (50 %) | 128 green (the count check already held) — 26 red: a seat change, whose discounted prorata was not counted, got N + 1 discounted charges | 154 green, 2 skipped |
| `CouponWindowDatedAndLegacyTest` › dated codes (ends between renewals, at midnight, at noon on a renewal day, 1 March on the 31st, both limits either way) and the dashboard | 7 red | green |
| same file › a marker of the previous release keeps 2 April (re-price, derived deferral, re-created subscription) | 3 green — Bestandsschutz pin | green |
| Rewritten expectations of the old rule, recomputed by hand: `CouponRoundFourGapsTest` (5), `RecurringCouponWithChangePatchesMollieTest` (3), `RecurringDiscountPeriodsTest` (4), `RecurringHundredPercentTest` (2), `CouponSignupMatrixTest` (1), `CouponMoneyCorrectnessTest` (1), `RenewalMonthEndCadenceTest` (6), `BookingMatrixCouponPathsTest` (1), `FullCoverageUpgradeFromFreeTest` (1), `RenewalLongRunCouponTest` (1), `ChargeDeferralLifecycleTest` (1 — the PATCH of an interval change a year out is now the full 120.00) | 26 red | green |
| `UsageLongRunDeferredChargeTest` (the hand-over from a deferral that ends off a boundary) and `ChangeMatrixWorld`'s `pct50-last` — set up with a marker of the old shape / two periods instead of one, so they keep testing what they were written for | green | green |
| Browser `CouponWindowMatrixTest::test_a_code_for_two_free_periods_on_a_cycle_on_the_31st_returns_on_the_31st` (dashboard, 31st, N = 2, both cadences: confirmation, next charge on screen, Mollie's start date, invoiced periods 31 Mar → 30 Apr → 31 May → 30 Jun) | 2 red | green |
| Browser `CouponWindowMatrixTest::test_an_upgrade_from_the_free_plan_on_31_january_stays_on_the_31st` (paid, both cadences) | green — moves a feature-only pin into the browser | green |

**Closed in the follow-up of 17 November 2026** (what the first pass left untested or saw
and did not change). Each finding seen red against the state before it, in a held run:

| Point | Outcome | Test | Before | After |
|---|---|---|---|---|
| A trial whose first charge a fully covering code defers stayed `trial` through the free periods; its period was not turned over and kept the prorated trial quota | **Fixed.** `ProcessTrialLifecycleJob::convertUnderDeferredCharge()`: at the first run after the trial end the subscription is `active`, its period starts at the trial end on that day (`billing_anchor`), the trial quota is replaced by the period's full quota (overage of the trial collected first), `TrialConverted` is dispatched. The free periods then turn over like any deferral (case A0). Mollie is not touched. The conversion mail waits for the first charge's invoice — there is none before | `CouponWindowInBillingPeriodsTest` › "first collects …" (trial rows: status, period, quota after the nights) | 26 red | green |
| On the day of a deferred charge, after the stored hour and before Mollie's charge arrives, the portal named the past trial end (or the end of a period the deferral was off) | **Fixed.** `ChargeDeferral::pending()` keeps the deferral for its whole day; `NextChargeSummary` reads it. Every decision about Mollie still uses `until()` | `CouponWindowDatedAndLegacyTest` › "names today on the day of a deferred first charge after a trial …" (before any nightly pass), "… off the period boundary …" | 3 red (the variant after the nightly passes was red before the trial conversion and green with it) | green |
| `PlanWaiver` counted free plan periods with `addMonthsNoOverflow()` from the next charge: from 28 February of a cycle on the 31st, two periods ended on 28 April and the cycle stayed on the 28th | **Reproduced and fixed.** Grant, `extendByPeriods()` and `chargeResumesAt()` count with `BillingAnchor::advance()` on the cycle's day (`CouponService::recurringAnchorDay()`) | `CouponWindowDatedAndLegacyTest` › "counts free plan periods on the billing day of a cycle on the 31st" (both cadences: 30 Apr, 31 May, 30 Jun) | 2 red | green |
| `extendByPeriods()` read the cycle's day off the charge the waiver had already deferred. From a cycle on the 29th, one free period moved the charge to 28 February; that date is two periods past the period start, `BillingAnchor::forCycle()` cannot trace it back, and its own day (28) was taken for the cycle's. Two periods appended then ended on 28 April, while three given at once end on 29 April, and Mollie's cycle moved to the 28th for good (on the 31st likewise: 28 April instead of 30 April). Found on 25 November 2026 by CI, through a date sweep of `WaiverPeriodsAlignToBillingCycleTest`, whose expectations were themselves off the cycle (see the note below the table) | **Fixed.** The cycle's day is read off `ChargeDeferral::undeferredNextChargeDate()`, the charge without the waiver, as the grant reads it | `WaiverPeriodsAlignToBillingCycleTest` › "appends free periods on the cycle day …" (29th, 31st, 30th in a leap year, at a fixed date) | 3 red | green |
| A code with an **interval change** | **Found and fixed:** a change whose whole charge the code covers is in force at once, and kept the old period start while Mollie's start date was reset from today — the local cycle ran on the 15th, Mollie on the 25th. Phase 3 now starts the new cycle on the day of an interval change in force. The rules otherwise hold: the code discounts the change's own charge → that period is the first; a change that nets to a credit (yearly → monthly) is not discounted, its periods are the next ones | `CouponWindowChangesTest` › "counts a code entered with an interval change …" (100 % and 50 %, monthly ↔ yearly) | 1 red (100 %, monthly → yearly), 3 green | green |
| A code with an **addon change** | Holds (same rule as a seat change); added as a path of the matrix | `CouponWindowInBillingPeriodsTest` (addon change, 52 rows) | 52 red against the state before the first pass (as the seat change); green against the first pass | green |
| **Resubscribe** in the grace period (`paid_period_ends_at`) with a `discount_periods` code | Holds: a fully covering code keeps its first charge (`charge_resumes_at`), a partial one its count | `CouponWindowChangesTest` › "keeps a code's periods through a cancellation and a resubscribe …" | 2 green (pin) | green |
| The **checkout's confirm step** did not name the first charge of a code that covers the whole order without a trial — only the trial notice carried a date | **Fixed:** `checkout.full_coverage_redirect_notice` (en, de) with the date of `firstChargeDate()`; both notices carry `dusk="confirm-first-charge"` | Browser `CouponWindowMatrixTest::test_the_checkout_names_the_first_charge_of_a_code_over_several_periods` (31 January, three periods: 30 April; after a 7-day trial: 7 May; then Mollie's start date and the dashboard) | 2 red (the trial variant only for want of the anchor — its date was printed) | green |
| The **plan screen**'s first charge under a code over several periods | Holds | Browser `CouponWindowMatrixTest::test_the_plan_screen_names_the_first_charge_of_a_code_over_several_periods` (31 January, two periods: 31 March = Mollie's start date) | green (pin) | green |
| `MollieRecurringCodeContractTest` against **real Mollie** | Run with the test key from `.env`; resources removed by the test case's teardown. The 100 % case now also asserts the date one billing period after the free charge | `tests/Sandbox/MollieRecurringCodeContractTest.php` | — | 3 green |

**The tests in `WaiverPeriodsAlignToBillingCycleTest` ran against the wall clock and took their
expectations from a cycle that drifts.** They added one month at a time to the next charge
(31 October → 30 November → 30 December) where Mollie and the package stay on the cycle's day
(→ 31 December), and two data sets assumed a 30-day month: "29 days into the period" does not
exist in February, and "a gift of 19 days ends just before the renewal" is after it when the
renewal is 18 days away. So the file turned red on about 90 days a year: on 25 November 2026
in CI, with no change to the code. Expectations are now `start + n × interval` clamped
(`waiverCycleCharge()`), the gift cases around the renewal count from it, and the late-period
case is day 27. A sweep over every day from 1 December 2026 to 30 November 2027 and over
25 January to 5 April 2028, the clock pinned per day, found these and is green after them.

A note on the counter-checks: Livewire recompiles a single-file component only when its
source is newer than the compiled class. A counter-check that restores a view with its old
modification time therefore leaves the compiled *previous* version in use for later runs; the
scripts touch every restored file. One feature run of this follow-up saw that artefact
(`BookingMatrixCouponPathsTest` naming 10 June) and was green once the view was touched.

Nothing of this section is open.

### Area: lifecycle, automatic resolution, real Mollie

Inventory `tests/Coverage/lifecycle.php`.

#### Fixed

##### A payment the package cannot deliver for is returned by the package

**A renewal for a subscription that no longer runs (case 1)**
(`WebhookSupport::reportRenewalForEndedSubscription()`). Mollie keeps charging a
subscription whose cancel never reached it. The renewal is invoiced as before, then the
Mollie subscription is cancelled — first, so nothing further is collected while the refund
is under way — and the payment refunded in full through `UnwantedPaymentRefund`, and the
customer is told (reason `renewal_after_end`). A cancel Mollie does not take is parked
under `pending_subscription_cancel`, the marker `SweepPendingRetriesJob` already turns into
`RetrySubscriptionCancelJob`.

**An activation that did not finish (cases 4–6)** (`reportSkippedActivation()`,
`reportIncompleteActivation()`, `reportSubscriptionActivationFailure()`). All three leave a
marker (`subscription_meta.activation_retries[payment id]`) and queue
`RetrySubscriptionActivationJob`: one minute, five, fifteen, an hour, six hours. The job
fetches the payment and replays it through the webhook controller's own route with
`ActivationRetry::resuming()` set, which changes exactly three things in the handlers:

- a payment whose invoice exists but whose subscription does not is **resumed** — it takes
  the activation claim under the row lock (`claimForResume()`) and runs the same activation
  with the invoice it finds, outside any transaction, like the first attempt;
- **before a Mollie subscription is created, Mollie is asked whether one exists**
  (`WebhookSupport::createSubscription()`): an active or pending subscription of this
  customer whose metadata names this billable, plan and interval is adopted
  (`CreateSubscription` gained `adopt_subscription`, which writes the local state and skips
  the call). `CreateSubscription` can fail *after* Mollie created the subscription — the
  answer is lost — and a blind second create is the double charge the two-phase activation
  exists to prevent. A lookup that fails is not read as "none": it throws and the retry
  comes back;
- a failure is not announced again — `SubscriptionActivationFailed` is dispatched once per
  payment, when the ladder starts.

When the retries are spent, a payment that collected money is refunded (reason
`activation_failed`) and the operator is told what happened and what was done; a €0
mandate checkout has nothing to refund, so there the operator mail is the last resort.

**A paid payment that names no billable (case 7)**
(`MollieWebhookController::reportPaidWithoutBillable()`). Reported once, and a trace row is
written: `{payment}:@unassigned` in `billing_processed_webhooks`, `received_at` = first
seen, `processed_at` null until resolved. No new table — there is no billable whose meta
could carry it, and that table is already "what do we know about this payment id".
`RefundUnassignedPaymentsJob` (daily, 04:15 UTC) refunds such a payment at Mollie after
`GRACE_DAYS` = 14, the figure of the package's other "an operator had their chance"
limits, or books it through the ordinary webhook path if its billable exists by then.

Judgement calls:

- **No "invoice is ready" mail and no `PaymentSucceeded` while an activation is being
  retried.** Both used to go out the moment `CreateSubscription` failed. They are sent by
  `completeActivation()` when the retry lands; if it never does, the customer gets the
  refund mail instead of an invoice mail followed by a refund mail.
- **The refund of an unassigned payment has no invoice and no credit note.** Both are
  addressed to a billable and there is none. The trace row and the log are the record.
  This is the one place the "booked and credited, never refunded without a trace" rule
  cannot hold.
- **A failing unassigned refund is retried nightly and reported after three further days,
  once** (a third row, `…:@unassigned-refund-failed`, is the "already reported" flag).
- **The retry of a refused mandate-only activation is usually futile** — a coupon that was
  switched off does not switch itself on — but it costs five cheap attempts and catches the
  case where an operator fixes it in the meantime. The operator mail follows either way.
- **The webhook dedup compares against the latest outcome, and a payment with a refund or
  chargeback on the books is always looked at again** — unchanged from the second round,
  but it is what makes the refund's own webhook harmless here: it finds the refund id on
  the credit note.

##### The activation retry ran inside the delivery on a `sync` queue

Found by the old tests of this path, not by the new ones (which all fake the bus):
`QUEUE_CONNECTION=sync` runs a dispatched job at once and ignores its delay. The retry
therefore ran inside the webhook delivery it is meant to repeat, met that delivery's own
reservation (503), threw "still incomplete" — the delivery answered **500** — and because a
sync job that throws has failed for good, `failed()` refunded the payment seconds after the
hiccup. Now `RetrySubscriptionActivationJob::runsInline()` keeps the webhook from
dispatching on a sync connection, the dispatch itself can no longer fail the delivery, and
`SweepPendingRetriesJob` dispatches the job for any `activation_retries` marker older than
the ladder (`LADDER_SECONDS`, 7 h 22 min). That sweep is also the net under a job that was
lost: before it, such a marker was the only trace of a paying customer without a
subscription, and nothing read it.

##### `CheckoutStarted` was never dispatched

The event, its audit key and its translations existed; nothing fired it.
`StartSubscriptionCheckout` dispatches it once Mollie has created the payment;
`StartMandateCheckout` does when the mandate payment carries a subscription (a trial, a
fully covered first period) and not when it is a change of payment method. Found by the
event catalog.

##### A plan change that waits for its charge was applied in silence

`UpdateSubscription::update()` dispatches `PlanChanged`, `SeatsChanged`, `AddonEnabled` /
`AddonDisabled` and `SubscriptionUpdated` for a change it applies itself. A change with a
prorata charge is applied later, by `ProrataChargeHandler::paid()`, and that handler
announced nothing: pro → team on 20 January, charge settled five minutes later, plan
switched, Mollie PATCHed — no `PlanChanged` for the app's listeners and no `plan_changed`
row in the audit trail. `docs/plan-changes.md` promises the event for exactly this phase.
The handler announces the difference between the billable before and after its switch,
each event isolated (`safely()`), once — the invoice guard keeps a re-delivery away.

##### Cancelling nothing gave thirty days of access

`CancelSubscription::handle()` had no status guard. A billable that never subscribed
(`new`, source `none`) took the Local branch and came out `cancelled` with
`subscription_ends_at` = now + 30 days — and `hasAccessibleBillingSubscription()` answers
true for a cancelled subscription whose end lies ahead. The dashboard's
`cancelSubscription` action is callable by any member with billing rights regardless of
what the page renders. An `expired` billable flipped back to `cancelled` and its customer
was mailed a second cancellation. Both throw `InvalidSubscriptionStateException`; nothing
is written and nothing is sent.

The admin subscription tab's `confirmCancel()` did not catch that refusal (26 November
2026): a tab loaded while the subscription ran still offers the buttons after the expiry
job, a grace end or another operator has ended it, and the submit left the action as a 500
with the modal open. It is caught like every other action there — the modal closes and the
operator reads "There is no subscription to cancel." — and `canCancel` excludes `new` as
well, which the service refuses like `expired`. `CancelReasonTest` › "says there is nothing
to cancel …" and "offers no cancel for a billable that never subscribed", red before.

##### Smaller ones

- **`UsageLimitReached` left no audit row when the booking was refused.** On a hard cap
  `WalletUsageService::debit()` dispatched the event inside its transaction and then threw
  — the audit recorder's insert was rolled back with everything else. The event is
  dispatched from the `catch`, after the rollback, and the exception rethrown.
- **The refund-failed mail named a row id.** `AdminRefundFailedNotification`'s subject read
  "Refund failed for invoice 1" — the primary key. It carries the document number, falling
  back to the id; `toArray()` gained `invoice_number`.
- **The refund idempotency key was only unique inside one database.**
  `invoice-refund:{invoice id}:{gross}:{already refunded}:{reason}`: the invoice id is a
  local sequence, the key space is the Mollie account. Two installations on one account
  (staging beside production, a restored database, every developer's test database) all
  have an invoice 1, and Mollie answered the second one's refund with **400 "idempotency
  key … used before on a different URL"** for an hour. Measured against the real API by
  `MollieSettledPaymentContractTest`. The payment id is part of the key now.

##### A gift gave its last day's renewal away — depending on the hour it was given

Found by the new browser walk (`GiftLongRunMatrixTest`, below). Pro given on 10 January at
10:00 for 90 days, extras bought the same day, so Mollie charges on the 10th: the gift ends
on 10 April, and "a renewal is plan-free when it falls before the gift's end" makes 10 April
the first charged renewal. It was not. The renewal side is a day (the charge day is the
start of the cycle's day, because Mollie creates the charge early that morning — measured
at 00:28 UTC), the gift's `valid_until` kept the hour it was given. 10 April 00:00 lay
before 10 April 10:00, so the waiver still "covered" it: with `continue_paid` Mollie was
never moved to the full price and the period to 10 May was given away; with `end` the
subscription was not ended and charged the extras for another month. The same gift given at
midnight behaved as documented. The feature long run had not seen it because its extras were
bought ten days into the gift, so no renewal ever fell on the gift's end day.

A waiver's end is now compared as a day: `PlanWaiver::endDay()` (start of the `valid_until`
day, UTC), used by `CouponService::isMarkerStillRedeemable()` for waivers and by
`PlanWaiver::chargeResumesAt()`. Ordinary recurring coupons are unchanged. The date the
portal prints ("plan free until 10 Apr 2027") is the stored one and does not move. The boundary
test in `PlanWaiverLifecycleAuditTest` ("ends the subscription exactly when the gift no
longer reaches the next charge") had pinned the instant — `valid_until − 1 s` still free —
and pins the day now: the second before the end day is free, the whole end day is not.

##### The renewal that ended a fully covering one-period code charged the customer twice that day

A free customer upgraded on 10 January under a 100 % code for one period; Mollie charged the
first full period on 10 February at 06:00. That renewal's own reset PATCH — the code had
ended — read the spent marker as a deferral (`chargeWasDeferred()`, the recognition kept for
subscriptions deferred before the date was stored) and returned the charge to
`ChargeDeferral::undeferredNextChargeDate()`, i.e. `nextBillingDate()`: inside the webhook the
anchor had not moved, the period boundary was 10:00 and so "in the future", and 10 February
was sent as Mollie's start date — a second charge that day. The renewal now resets the amount
only (`MollieSubscriptionPatcher::updateRecurringAmountAfterCharge()`): the charge has just
been made, nothing it could return to lies elsewhere. And a date a charge returns to is after
today by day, never today (`ChargeDeferral::undeferredNextChargeDate()`,
`regularNextBillingDate()`). Found while pinning the upgrade from the free plan on the 31st.
Seen red: `RenewalMonthEndCadenceTest` › "does not send the day of a renewal as the next
charge when a one-period code ends with it" and › "never names today as the date a charge
returns to".

##### Refunds and chargebacks were read past MollieCall, and only their first page

`RefundHandler` and `ChargebackHandler` asked the SDK resource (`$payment->refunds()`,
`$payment->chargebacks()`). That sends a `DynamicGetRequest` on `_links.refunds` through the
client the payment was hydrated with — past `MollieCall::send()`, so the transaction guard and
the amount check never saw those reads. It also returned the first page only: a payment with
more refunds or chargebacks than Mollie's default page of 50 had the rest never booked.
`Support\MolliePaymentCredits` reads both as typed requests
(`GetPaginatedPaymentRefundsRequest`, `GetPaginatedPaymentChargebacksRequest`, limit 250)
through `MollieCall::send()` and follows `next` to the end (at most 100 pages, then it
throws). It is resolved from the container. The test suite binds `StubAwarePaymentCredits`,
which answers payment stubs only after `MollieCall::assertSendable()`, so the guard stays live
in every webhook test. Seen red against the old handlers: the two list reads in
`NoMollieCallInsideTransactionTest` and both cases of `PaymentCreditsPagingTest`. A sweep of
`src/` (`Mollie::send`, `Mollie::api()`, `->payments->`, resource methods, every
`new …Request(`) found no other request outside `MollieCall`.

##### Billing days were converted into the customer's timezone

Found by the gift walk run for a customer whose portal is set to New York, with the fake
Mollie charging at the hour real Mollie does (00:28 UTC). The portal converted every date into
the billable's timezone, charge dates included: a charge on 10 March read "9 Mar" in New York,
while the first one — anchored on the 10:00 UTC checkout payment — read "10 Feb"; and after
the gift, "plan free until 10 Apr" stood next to a full-price charge on "9 Apr". A charge date
is a date (Mollie's `nextPaymentDate`) at an hour nobody chooses, and the package compares
these values as days. `BillingTime::billingDay()` shows charge dates, period boundaries and
the end of a subscription, trial or gift as the UTC day they are computed on, in every
timezone (next-charge block, dashboard, plan, addons and seats pages); moments — invoices,
usage, purchases — keep `display()`. `docs/timezone.md` says so. Seen red: both New York
cases of `GiftLongRunMatrixTest` with `billingDay()` converting as before ("09. Mar" for
"10. Mar"); file restored byte for byte.

#### Deliberate

- **Every event and every mail has a test that drives its real cause**
  (`tests/Feature/Lifecycle/EventCatalog*Test.php`, `NotificationCatalogTest.php`). An
  event is *listened* for, never faked, so the audit recorder runs and its row is asserted
  next to the payload, with a German and an English sentence. A mail is asserted for its
  recipient and rendered in both languages with the amount and date held against the data.
- **The status transition matrix is a specification**
  (`StatusTransitionMatrixTest.php`): six statuses × six triggers. A cell that changes is a
  behaviour change somebody has to mean.
- **Statuses the package never writes.** `InvoiceStatus::Open` and `::Failed` are never
  written: every invoice comes from a settled payment. They exist for documents an app
  holds or imports, and all the package does with them is label them.
  `MollieSubscriptionStatus` is never shown as a word either; it decides whether a past-due
  customer sees "your charge is scheduled for …" or the ordinary past-due banner.
- **`SubscriptionExtended` is about an end date.** Free days given to a Local subscription
  move `subscription_ends_at` and dispatch it. Given to a Mollie subscription they move the
  next charge; nothing ends, so the event (whose payload is two end dates) is not
  dispatched — the redemption is announced as `CouponRedeemed`.
- **A subscription past due for `past_due_max_days` is cancelled by the nightly pass**:
  past due since 10 February, still past due on the night of 11 March, cancelled on the
  night of 13 March.
- **A gift with paid extras is walked to its end in the browser**
  (`tests/Browser/Matrix/GiftLongRunMatrixTest.php`, both `grant_on_expiry` values). The
  browser suite has a shared clock now (`travelSharedClockTo()`, see
  `docs/browser-testing.md`): the test process and the Dusk server read one "now" from the
  shared database, so every renewal happens on its own day and every portal page is rendered
  on that day. Pro given on 10 January for 90 days; Softdrinks bought the same day (5.83),
  one extra seat on 25 January (16 of 31 days: 6.08); renewals on 10 February and 10 March
  collect the extras only (17.61, the plan shown and taken off); the dashboard shows the
  next charge, "plan free until 10 Apr 2027" and the gift notice at every step. `end`:
  cancelled with the 10 March renewal to 10 April, nothing more collected, expired by the
  nightly pass, the app locked. `continue_paid`: Mollie holds 52.12 from 10 March, the
  renewals of 10 April and 10 May are charged in full and carry no waiver line. The invoice
  page lists exactly those documents. Every figure is computed in the test, not taken from
  the package.
- **Chargebacks cannot be produced in Mollie's test mode**, so `ChargebackHandler` is
  pinned against the SDK's own format instead of a measurement.
  `tests/Feature/Webhook/ChargebackSdkContractTest.php` serves the SDK's sample responses
  (`vendor/mollie/mollie-api-php/src/Fake/Responses/chargeback.json` and the charged-back
  payment embedded in `chargeback-list.json`, SDK v3.11.0) through the SDK's
  `MockMollieClient`: the real payment hydration, the chargeback list read through
  `MollieCall::send()` (`GetPaginatedPaymentChargebacksRequest`, see the entry on refunds and
  chargebacks above) and the real webhook controller all run. It
  covers a full, a partial and a reversed chargeback, and one first seen already reversed;
  only values are overridden, never keys. Renaming `reversedAt`, `amount` (read as
  `settlementAmount`), `id` or the payment's `amountChargedBack` turns it red (four
  mutations, each seen red). A chargeback has no status field; the handler reads `id`,
  `amount` (payment currency, positive in the sample) and `reversedAt`. `settlementAmount`
  is the merchant's settlement debit, negative and in the settlement currency, and is
  deliberately not read. What real Mollie sends live stays unmeasured.
- **A cycle on the 29th–31st keeps its day, and Mollie's cadence after a month end is
  measured for the record, not relied on.** A customer charged on the 31st is charged on
  the last day of a shorter month and returns to the 31st after it: 31 Jan → 28 Feb →
  31 Mar → 30 Apr → 31 May; a yearly subscription from 29 February is charged on
  28 February and on 29 February in leap years. `Support\BillingAnchor` keeps that day
  beside the period start (`subscription_meta.billing_anchor = {day, from}`, honoured only
  while the period still starts at `from`); `BillingAnchor::forCycle()` derives it at every
  renewal — the charge's own day, unless the charge fell on a month's last day and the
  previous period's longer day leads exactly to it — and `nextBillingDate()`, the invoice
  period, every prorata window and the portal's next charge end on it. For the charge after
  a short month Mollie may count on from that charge ("drift": 28 Mar) or from its start
  date ("clamp": 31 Mar), and its start date may itself be a shortened day (a signup on
  31 January is sent 28 February). So wherever the anchor date is not simply one interval
  after the charge, the renewal sends it (`SubscriptionPaymentHandler::pinNextChargeOnAnchor()`
  → `MollieSubscriptionPatcher::pinNextChargeDate()`, through `MollieCall::send()`, outside
  any transaction): under "clamp" these are dates Mollie had anyway, under "drift" they keep
  the customer on the 31st. *Changed 17 November 2026: Mollie refuses a new start date on a
  charged subscription, so the renewal now asks Mollie for its next date and replaces the
  subscription where that is not the anchor day; a failed attempt is retried — see "A charged
  subscription is replaced to move its next charge".* Not for a deferred charge (`PrepareUsageOverageJob` turns
  deferred periods over on the anchor day; the charge that ends the deferral sets the day
  anew), not when a gift ends the subscription; a failed PATCH is logged and Mollie's own
  charge is booked as any renewal is. A plan, seat or addon change without an interval
  change keeps the running period and its day — a change on 28 February of a cycle on the
  31st is prorated over 28 Feb → 31 Mar (31 days) and renews on 31 March; only a new period
  start (interval change, past-due reset, upgrade from Local, checkout) sets a new day, the
  day it starts (`RenewalMonthEndCadenceTest` › "keeps the anchor day through a plan change
  on or inside a month-end period", red without the anchor, and › "starts a new anchor day
  with an interval change on 28 February"). Local periods keep their day the same way (see
  the month-end row of the second round). Pinned under both answers by
  `RenewalMonthEndCadenceTest`: anchor 29/30/31 over fifteen months through the leap
  February 2028, a signup on 31 January, prorata in a 28-day and a 31-day period, the yearly
  29 February, a deferral and an ending gift (`end` and `continue_paid`), and Mollie
  refusing the date. Seen red: 18 of 20 against the previous version of this fix (which
  moved the cycle to the 28th) and against the unfixed one. In the browser,
  `MonthEndCadenceMatrixTest` walks a cycle on the 31st over February under both cadences
  (the fake Mollie plays either, `cadence_after_month_end`) and holds the dashboard's next
  charge, the invoiced period and Mollie's next date to the same day at every step.
  An upgrade from the free plan on 31 January — paid, under a fully covering code, or extras
  bought on a gift — is charged on February's last day (28th, 29th in 2028) and on the 31st
  from March; the anchor is recorded with that first renewal. A code that covers more than
  the upgrade's own period defers the first charge by its window — since 17 November 2026
  counted in billing periods, so two periods from 31 January are first charged on 31 March
  and the cycle stays on the 31st (until then N × 30 + 2 days: 2 or 3 April, and the 2nd or
  3rd from then on). Pinned by `RenewalMonthEndCadenceTest` › "keeps an upgrade from the free
  plan on 31 January on the 31st, whichever way it was made" (2027 and 2028, both cadences,
  all ways; the two-period code red against the previous rule, 4 of 4) and, in the browser,
  `CouponWindowMatrixTest::test_an_upgrade_from_the_free_plan_on_31_january_stays_on_the_31st`
  (the paid way, both cadences).
- **Real Mollie, measured on a date or not producible at all.** Three things stay
  measurements and are not open behaviour: (1) Mollie's cadence after a month end — the
  canary's `month_end` subscription (started 2026-12-31) answers it, and on 1 April 2027
  the answer is written here (`docs/sandbox-testing.md`); either answer needs no code
  change. (2) Whether Mollie takes a start date PATCHed right after a charge and charges on
  it — the canary's `pinned` subscription (created 16 November 2026, first charge
  17 November) is moved three days past Mollie's own date on the first visit after that
  charge and checked on every visit after (*answered 17 November 2026: refused, 422 "…already
  charged" — see "A charged subscription is replaced to move its next charge"*); until then
  `tests/Sandbox/MollieChargeDateContractTest` measures the start-date PATCH on a
  subscription without charges. (3) Chargebacks cannot be produced in test mode; see the
  contract test below.
- **Refund and chargeback lists are read to at most 100 pages, and the paging is not
  measured against real Mollie.** `MolliePaymentCredits` asks for 250 entries a page and
  follows `next`; Mollie's default page is 50, so the limit is reached only past 5,000
  (at least 25,000 at the page size asked for) refunds or chargebacks on one payment — that
  does not happen for a subscription charge. Past the limit it throws instead of booking what
  it has read: a partial list would credit too little and record the payment as settled, and a
  webhook that fails is re-delivered and visible, a wrong booking is neither. The shape of
  `next` is the SDK's (its fixtures and `CursorCollection`); the sandbox cannot produce more
  than a handful of refunds on one payment, and chargebacks not at all, so paging against the
  real API is not measured — `PaymentCreditsPagingTest` pins it against the SDK's format.

#### Real Mollie: what the sandbox browser suite measured (16 November 2026)

Method and page structure: `docs/sandbox-testing.md`.

- **Hosted checkout.** A payment with an amount offers card and eps; a €0.00 first payment
  offers card only. The test card goes through without 3-D Secure.
- **`failed` / `expired` chosen at the test bank do not end the payment.** Mollie returns
  to the method list, the payment stays `open`, and paying on the same payment afterwards
  works. The package's webhook sees `open` in between, releases its reservation and books
  the later `paid` once — tested end to end.
- **A first payment expires 15 minutes after creation** (measured `expiredAt` 16 min 48 s
  after `createdAt`). It carries no mandate.
- **Cancel button** → `canceled` about five seconds after the redirect. `DELETE` on an open
  first payment: `isCancelable` false, 422.
- **Refund of a settled payment:** accepted, status `pending`; the payment stays `paid`
  with `amountRefunded` 0.00 while the refund is pending. The payment's totals do not
  announce a pending refund — the package books it from `refunds()`, which lists it at once.
- **A cancelled refund stays listed**, with status `canceled`. A reader of `refunds()` must
  look at the status — the package does.
- **Refund of a first payment after its mandate was revoked:** accepted (`pending`). That
  is what `UnwantedPaymentRefund` and `GrantExtrasPurchaseRefund` rely on.
- **A recurring payment created on the captured mandate** is `directdebit`, `pending` at
  creation and still `pending` with no `paidAt` 15 seconds later.
- **Chargeback:** no API endpoint creates one and the test pages do not offer it.
- **Mollie's own scheduler (the canary, first visit 16 November):** the charges of the
  `daily` and the `monthly` subscription, both starting 2026-11-16, were created at
  00:28 UTC on that day — `createdAt` lies on the cycle date, as
  `SubscriptionPaymentHandler::cycleStart()` assumes. Both `pending`, no `paidAt` five
  hours later (direct debit), amounts as held, `nextPaymentDate` advanced by one interval.

#### Counter-checks

Seen red before the fix: `CheckoutStarted` (both call sites), `PlanChanged` after a settled
prorata charge, cancel on `new` / `expired`, the `UsageLimitReached` audit row, the
refund-failed mail subject, the refund idempotency key (against real Mollie), the
activation retry on a sync queue and the sweep net, the automation cases 1 and 4–6 (17
failing before wiring). Case 7 was wired before its test ran; the counter-check was made
afterwards with `RefundUnassignedPaymentsJob::handle()` short-circuited: 4 of 5 red.
The gift's last-day renewal: `RenewalLongRunWaiverTest` › "does not waive the renewal that
falls on the day the gift ends" — both datasets red against the unchanged `PlanWaiver` /
`CouponService` (10 April collected 17.85 instead of 52.36, and with `end` not cancelled),
green with the fix; the browser walk was red on the same point (`continue_paid`: Mollie
still held 17.61 after 10 March) and is green with it.

#### Open

Nothing. What stood here was closed on 16 November 2026:

- *The browser matrix did not walk a gifted plan with paid extras to the end of the gift* —
  the two processes shared a database, not a clock. They share a clock now, and
  `GiftLongRunMatrixTest` walks both endings month by month (see Deliberate). The walk found
  the last-day renewal above.
- *Mollie's cadence after a month end was modelled, not measured.* It still cannot be
  measured before the canary's March 2027 visit, but the package no longer depends on the
  answer: both possible cadences are run through the renewal handler, and under "clamp" that
  found a gap between period end and charge, now closed by pinning Mollie's next date (see
  Deliberate). The measurement is a dated task in `docs/sandbox-testing.md`, for the
  documentation only.
- *Chargebacks cannot be produced in test mode, and the field names were taken from the
  reference.* Pinned against the SDK's own chargeback format by a contract test that turns
  red on a renamed field (see Deliberate).

### Area: operations, overage and past due

Inventory `tests/Coverage/operations.php` (120 options and `event:OverageWrittenOff`); five
browser matrix files under `tests/Browser/Matrix/`.

#### An overage nobody could collect stands, and the renewals try again (case 10)

What it was: three failed payments ended the debt. The operators were mailed, the
subscription went past due, and the next renewal's reset wrote the units off. A person was
asked to look at a case a machine can settle — most failed overage payments are a card
limit or an expired card, fixed by the customer within a period or two.

What applies now (`ChargeUsageOverageDirectly`; `docs/usage-billing.md`, "A debt nobody
could collect stands, and the renewals try again"):

1. When a cycle is over without collecting, `deferFailedCycle()` takes the debt **off the
   wallet** (units credited back, reason `overage_debt_deferred`) and leaves it standing in
   `subscription_meta.usage_overage` — status `failed`, lines `settles_wallet: false`,
   `failed_at`.
2. Each of the next renewals starts one fresh dunning cycle for it, through the entry
   points that already existed (the sweep before a renewal, the renewal's
   `collectOutstandingOverage()`, the last sweep before a subscription ends, an immediate
   cancellation). Not before it has rested `RECOLLECT_AFTER_DAYS` = 3.
3. After `MAX_RENEWAL_RECOLLECTIONS` = 3 such cycles: written off. `OverageWrittenOff`
   (audit `overage_written_off` with amount and attempts), and only now the operator mail.

Judgement calls:

- **Off the wallet, not left negative.** A negative balance that survives a reset is netted
  against the new period's quota (−37 + 100 = 63). Left there *and* collected again in
  money, the customer pays twice — once in units, once in euros — and the settlement then
  credits units onto a wallet that no longer owes them. A receivable that lives only in the
  snapshot has one way of being paid.
- **One cycle per renewal means up to three payments per renewal** — the existing ladder,
  unchanged — so a debt that is never paid sees 3 + 3 × 3 = 12 payment attempts over four
  periods. A single attempt per renewal would be gentler on the mandate; it would also be a
  second dunning mechanism next to the first.
- **Three days of rest.** The sweep charges the night before a renewal, so its cycle fails
  hours before that renewal arrives. Without the rest the renewal of the same boundary
  would be a fourth attempt the morning after three refusals.
- **A stale payment is cancelled before its slot is freed.**
  `CleanupStalePendingOverageChargeJob` used to write off a payment still `open` /
  `pending` after 14 days. Freeing the slot for a payment that can still settle is how one
  debt is collected twice — more so now that the renewal collects on its own. It is
  cancelled at Mollie first; if Mollie refuses, it keeps the slot and the next pass looks
  again. That is the answer to "a written-off payment settles after all": a cycle is only
  ever declared over for payments that cannot.
- **A snapshot with no line items has nothing to carry** (hand-edited rows, or a charge
  that threw before recording anything): its marker is cleared rather than left `failed`.
- **Rows an older release left `failed` with the units still on the wallet** are recognised
  (their lines still settle the wallet) and collected from the wallet, once.
- **The customer mail's wording changed**: the subject no longer says "Service paused"; a
  second line says whether a later renewal will try again (`will_retry` / `final`). The
  admin mail says "written off".
- **A debt standing when a subscription ends gracefully** gets one more cycle from the
  sweep the night before the end. If that fails too, nothing collects it afterwards; the
  snapshot stays on the billable as a record.

Pinned by `tests/Feature/Wallet/UsageLongRunDebtRecollectionTest.php` (a debt across three
renewals, then paid; never paid → written off after three, one mail).

#### Past due for an overage debt

**The interim decision, and why it was revised.** The first version of this change left
the subscription active after a failed overage cycle: past due locks a paying customer out
for a period, and `past_due_max_days` cancels a yearly plan before the renewal that should
collect. The maintainer overruled that — in past due the customer must not book anything
new, because the debt is most likely no longer collectable — and the conflict is solved
the other way round.

Nothing in the renewal handler refuses a past-due subscription. The two things that broke
the recollection were the nightly auto-cancel, which is keyed on `past_due_since` and fires
`past_due_max_days` later — before the second monthly renewal, and long before a yearly
one — and `SubscriptionPaymentHandler::paid()`, which flipped every past-due subscription
back to active on a paid renewal, debt or not. So:

- the overage lock is recorded under its **own key**, `overage_past_due_since`, and never
  writes `past_due_since`. The auto-cancel pass is untouched and simply does not see it;
- a paid renewal keeps the status at PastDue while that key is set; it still clears the
  renewal's own dunning keys;
- `ChargeUsageOverageDirectly::releasePastDueForDebt()` ends the lock — when the overage
  payment settles, on the write-off, and where a spent snapshot is cleared. It returns to
  active only if no renewal is unpaid; otherwise the ordinary dunning goes on;
- **one place decides "may it go back to active?"**:
  `ChargeUsageOverageDirectly::statusAfterRecovery($meta, $recovered)`, used by
  `SubscriptionPaymentHandler::paid()`, `ProrataChargeHandler`,
  `ChargebackHandler::reverse()` and `HasBilling::extendBillingTrialUntil()`;
- a Local subscription that is past due only for the debt keeps turning its period over in
  the nightly job.

The normal dunning of a failed renewal is not changed anywhere.

Judgement calls:

1. *Status after the write-off* (the maintainer's proposal, built as proposed): renewals
   paid → active; a renewal unpaid as well → stays past due under `past_due_max_days`.
2. *A plan or interval change is refused* while the subscription is past due for the debt
   only. In past due a plan change is the "past-due reset" — a fresh period charged in
   full. Here the period is paid, so the reset would charge it a second time. That also
   refuses a downgrade; it would be reset-charged too. When a renewal is unpaid as well,
   the reset stays available as before.
3. *The dashboard's "charge now" button* is not offered (and refused server-side) for a
   debt-only past due: it PATCHes the Mollie start date to today, which would collect the
   next period early.
4. *The operator's "Retry"* in the past-due list starts a real collection cycle for a
   standing debt (`collectStandingDebtNow()`), at once — and **counts against
   `MAX_RENEWAL_RECOLLECTIONS`**, deliberately: a cycle is up to three payments against the
   mandate, and an uncounted one makes the button an unlimited dunning lever. Idempotent
   through the in-flight guard and the cycle's idempotency key.

#### Past due blocks every new booking

| Path | While past due |
|---|---|
| Seats / paid addons — portal, `syncBillingSeats()`, `enableBillingAddon()`, scheduled, preview | refused (`ValidateSubscriptionChange::assertNoPastDueExpansion()`, since 15 November) |
| Plan / interval change — portal, `changeBillingPlan()`, scheduled, preview | refused for a debt-only past due; the past-due reset with an unpaid renewal |
| One-time order — products page, `StartOneTimeOrderCheckout` | refused. A product was still purchasable with a renewal unpaid; closed, test red first |
| Extras on a grant — `StartGrantExtrasPurchase` | refused, both kinds. The screens never offered it; the service is what an app calls directly |
| A new checkout — `StartSubscriptionCheckout` | refused when an overage debt stands or Mollie still runs a subscription |
| Codes in the portal — `RedeemCouponFromPortal` | not a booking: no payment is created, the plan stays, the lock stays. Tested per type under a debt; nothing had to change |
| An operator in the admin panel — wallet credit, plan waiver, extension, grant, cancel, resubscribe | not refused: gifts and endings by an operator, not bookings |

The new checkout **was a real hole.** The service only refused a billable with an
accessible subscription, and past due is not accessible — so a customer with an overage
debt, or an unpaid renewal on a subscription still running at Mollie, could start a second
subscription. The checkout *page* redirected such a visitor; the service, which the
Livewire component and an app's own code call, did not. Left open on purpose: past due
with nothing to pay for — the trial that expired without a subscription, for which the
checkout is the way forward.

Every booking that is not seats or addons answers with one sentence,
`billing::portal.error_past_due_booking`, via
`PastDueExpansionNotAllowedException::booking()`.

#### Further overage is blocked in past due

One condition added to `HasBilling::canChargeBillingOverage()`: not past due. That method
is already the single rule behind `hasBillingQuotaLeft()` and the hard cap in
`WalletUsageService::debit()`, so the refusal is the existing one —
`UsageLimitExceededException` + `UsageLimitReached`, all-or-nothing — and an app handles
one case. No second mechanism.

- Within the wallet: allowed; beyond it: refused; partly covered: refused as a whole.
- A wallet already negative takes nothing more; other types with units stay usable.
- Back on active: the ordinary setting again.

**What a renewal credits while past due.** For the overage debt the renewal hands out the
full quota of the new period (100 of 100): the debt left the wallet when its cycle gave up
and is kept as money (3.70), so nothing is netted and the new quota does not silently pay
it off. For an ordinary negative balance (overage not yet charged, −20 when a renewal
fails) nothing is credited while the renewal is unpaid; when it is paid, the existing rule
applies unchanged.

#### Access stays for a customer who owes only overage

`hasAccessibleBillingSubscription()` answers true for PastDue when
`isPastDueForOverageDebtOnly()` (and the period has not lapsed);
`RequireActiveSubscription` redirects a past-due billable only when that method says no.

| Place | Unpaid renewal | Overage debt only |
|---|---|---|
| `hasAccessibleBillingSubscription()` | false | **true** |
| `RequireActiveSubscription` (`billing.active`) | redirect to the portal | **passes** |
| `FeatureAccess`, `billing.feature`, `@planFeature` | never looked at the status | the same — pinned by a test |
| `canChargeBillingOverage()` / `hasBillingQuotaLeft()` / `debit()` | **no overage** | **no overage** |
| Checkout page, return page | redirect when Mollie still runs a subscription | treated as subscribed: redirect to the dashboard |
| First-payment handlers, `activationCompleted()` | read "not subscribed" | read "subscribed" — a stray first payment is a duplicate, not an activation |
| `CleanupOrphanedBillablesJob` | — | not an orphan |
| `CouponService` (a plan waiver needs a subscription) | refused | accepted — a gift |
| `PrepareUsageOverageJob` | auto-cancel clock runs, no Local renewal | no clock, Local renewal runs |
| Dashboard | banner, "charge now" where Mollie has a date | debt banner with the amount, no "charge now" |
| `NextChargeSummary` / `x-mollie-billing::next-charge` | "past due" instead of a next charge | the next renewal as scheduled, plus the standing debt as a line of its own (`overage_debt`) and `total_gross` = what that renewal collects; `gross` stays the subscription's own charge |
| Admin past-due list, `AdminKpiService` | listed / counted | listed / counted |

The debt is listed in the next charge only when the renewal on that date will collect it:
standing (no payment on its way) and rested `RECOLLECT_AFTER_DAYS` by then. The announced
total equals renewal + debt payment actually requested in the long-run harness
(11.90 + 4.40 = 16.30). The open amount is also shown on the usage page.

#### Fixed

- **Refund reason "Other" without a text answered in English developer prose.** The refund
  service refuses it with an `InvalidArgumentException` whose message the invoice tab
  printed as it came — in a German panel too. The tab checks it itself and says it in the
  operator's language (`flash.reason_text_required`).
- **The refund detail did not show what the operator had written** — only the reason code,
  which says nothing for exactly the refunds filed under "Other". Shown under the badge,
  escaped.
- **A reason somebody typed was re-capitalised.** `UsageReason::label()` ran every reason
  without a translation through `Str::headline()`. For an identifier that is the point; for
  a sentence an operator typed when crediting a wallet it turned "Goodwill after the
  outage" into "Goodwill After The Outage" on the customer's usage page. A reason
  containing whitespace is shown as written — which is what `docs/usage-billing.md` had
  been promising.
- **A seat change that could not get its lock was lost without a word.** Open since the
  second round. `SyncSeatsJob` waited 30 s for the seat lock and then died with a
  `LockTimeoutException`; with `tries = 1` that was a `failed_jobs` row, a member invited
  and a seat never billed. Nothing has happened at that point, so the job queues a copy of
  itself (same delta, a minute later), at most `MAX_REQUEUES` = 5 times; only then does it
  fail as before.

#### Deliberate

| Behaviour | Why it stays | Test |
|---|---|---|
| An admin refund is entered as a **net** amount; Mollie pays back net + VAT | The field is labelled "Amount (net)" and the modal names the remaining refundable net. Every case checks the three numbers against each other: offered net, credit note net / gross, amount at the test Mollie | `AdminRefundMatrixTest` |
| The wallet-credit form cannot send 0 units | `min="1"` on the field; the server-side message is only reachable by a hand-made request | `UsageWalletMatrixTest`, `BulkActionsTest` |
| Dashboard figures are served from the cache for `admin_kpi_cache_ttl` seconds | By design. Worth knowing in tests: the cache store is shared between the two browser-suite processes and survives the database reset between scenarios | `OperationsConfigKeysTest`, `AdminListsMatrixTest` |
| A billable without a plan sorts first in the billables list | It is a NULL in the column; the screen prints a dash | `AdminListsMatrixTest` |
| A justification for a resolved mismatch is stored with the verified country on the billable, not on the mismatch row | Existing design; confirmed for all four ways of choosing the country | `AdminListsMatrixTest` |
| The concurrency suite does not run against MySQL | No MySQL server is available where the suite is developed (Herd offers PostgreSQL and Valkey here). The guards under test are cache locks and `lockForUpdate`, whose row-locking semantics PostgreSQL exercises; `BILLING_CONCURRENCY_DB` is the seam a `mysql` connection would plug into | `tests/Concurrency` on sqlite and pgsql |
| On a `sync` queue a seat job that meets another change's unconfirmed payment still fails | A put-back copy would run at once, against the same pending payment, over and over; there is no delay to wait with. `billing:check-config` warns about the sync driver | `OperationsOpenJobPathsTest` (the wait), `CheckConfigQueueTest` |
| The addons and products screens' refusal `error_past_due_booking` is asserted in the browser only | They report it as a Flux toast, and the feature suite does not render Flux ([The feature suite does not render Flux](#the-feature-suite-does-not-render-flux)): the toast is a no-op there. The browser suite renders it | browser `PastDueBookingRefusalTest` (addons, products); feature `PastDueBookingRefusalScreensTest` for the seats and checkout screens, which keep the message in component state |

#### Counter-checks

Each fix was switched off by a one-line change, only for the duration of the locked test
process, and the file compared byte for byte with its backup afterwards.

| Fix | Test | Without | With |
|---|---|---|---|
| Admin "Apply now" keeps a failed change | `OperationsAdminActionsTest` | 1 failed | 1 passed |
| Reason "Other" without text (feature and browser) | `OperationsAdminActionsTest`, `AdminRefundMatrixTest` | 1 failed each | passed |
| `SyncSeatsJob` lock timeout | `OperationsOpenJobPathsTest --filter=seat` | 1 failed, 2 passed | 3 passed |
| Stale overage payment cancelled at Mollie first | `StaleOverageChargeSweepTest --filter=cancel` | 2 failed | 2 passed |
| `statusAfterRecovery()` → always the recovered status | `ChargebackReversalTest` | 1 failed | 1 passed |
| Dashboard debt banner; `markPastDueForDebt()`; the debt-only pass through `RequireActiveSubscription` | browser `UsageWalletMatrixTest` | 1 failed each | passed |
| Operator's retry → standing-debt branch | browser `AdminListsMatrixTest` | 1 failed | 1 passed |
| Usage page "overage blocked" hint | browser `UsageWalletMatrixTest` | 1 failed | 1 passed |

Red before their fix, as ordinary runs: `OverageDebtPastDueTest` (11 of 12, then 9 of 18
for the gaps), `PastDueUsageAndAccessTest` (4 of 6). The tests that were green before pin
behaviour that must not change: ordinary renewal dunning, the expired-trial checkout, the
seven portal code types, no access with an unpaid renewal. In the `SyncSeatsJob` row the two
tests that stay green without the fix are the behaviour past the bound and the run without
a timeout — as they should.

For the record: the first attempt switched the admin "Apply now" fix off before the test
held the shared lock, for about three minutes while another browser run was going. A red
"apply now" case in a browser run of that window is this, not a defect.

#### Open

Nothing. What stood here was closed on 16 November 2026:

- *The four screen-level `catch` blocks for `error_past_due_booking` were not tested.* In a
  real past-due state the click is not there to make, so what is tested is the race: the
  page is open, the booking service answers "past due". Browser `PastDueBookingRefusalTest`
  (addons and seats of a gifted tenant, products, checkout — the message shown, the generic
  error not, nothing at Mollie), through the workbench switch `past_due_booking_race`
  (`Workbench\App\Support\PastDueBookingRace`); feature
  `PastDueBookingRefusalScreensTest` for the two screens that keep the message in component
  state (seats, checkout; de + en). The addons and products screens report it as a Flux
  toast, which the feature suite cannot see. Counter-check: each `catch` pointed at a class
  that is never thrown — feature 4 of 4 red, browser 4 of 4 red.
- *Retry jobs on the `sync` queue driver.* `billing:check-config` warns about a connection
  with the `sync` driver and says what it costs (seen red: 2 of the 3 new
  `CheckConfigQueueTest` cases). And the jobs no longer give up at the first error:
  `BillingQueue::isSyncRun()` asks the run, not the config (a job called directly — a test,
  an app — still throws to its caller). On a sync run `RetryRefundLineJob`,
  `RetrySubscriptionPatchJob` and `RetrySubscriptionCancelJob` keep their marker, and the
  hourly `SweepPendingRetriesJob` is their ladder until their window (7 days / 24 h / 24 h)
  ends with the one operator mail; `RetryUsageOverageChargeJob` leaves the charge `pending`
  for the nightly pass and reaches `failed()` on the third failed night
  (`usage_overage_inline_failures`). Found on the way: `InvoiceService` re-stamps a rejected
  refund line with `first_attempt_at` = now, so a line the sweep kept re-dispatching never
  reached its seven days — never dead-lettered, nobody told. The retry job writes the first
  failure's time back. `RetryJobsOnSyncQueueTest`, through the real sync queue: 4 of 4 red
  with the sync handling off; the refund case red on its own with only the first-attempt fix
  off. The activation retry follows the same pattern now: on `sync` it is still not
  dispatched from the webhook, but the sweep runs it every hour from the start instead of
  once after the ladder, and a sync run that does not finish keeps its marker until the
  ladder's 7 h 22 min are over; only the run after that is final (refund, one mail). Seen red:
  both new `ActivationRetrySyncQueueTest` cases with the job's guard off; with only the
  sweep's change off the "goes through after three sweeps" case (the other one ends past the
  ladder either way). See [upgrading 1.2](upgrading.md#12-a-queue-worker-and-the-scheduler-must-be-running).
- *Refunds by line.* The service could do it only through hand-built `line_items`. It
  now has its own call: `RefundInvoiceService::refundLine()` (also `refund([... 'line_index'
  => n])`) builds the credit line from the invoice read under the row lock
  (`InvoiceService::creditLineFor()`): `parent_line_item_index`, the line's own rate, VAT
  capped at what is left of that line's VAT; more than the line's remainder is refused with
  `RefundExceedsLineAmountException` (a `RefundExceedsInvoiceAmountException`). Money only —
  an overage line's units go back through `refundOverageUnits()`. The admin refund modal has
  a line select (the whole invoice, or a line with what is left of it); choosing a line
  fills in that remainder, which can be lowered but not raised. `RefundByLineTest` (9, all
  red before), browser `AdminRefundMatrixTest::test_a_refund_by_line_is_booked_against_that_line`
  (19.80 offered, 12.00 refunded → credit note −12.00 / −2.40 / −14.40, Mollie 14.40, then
  7.80 left and 7.81 refused). Found on the way and fixed: `allocateCredit()` spread an
  amount refund by each line's ORIGINAL share, not by what was left of it, so after a
  line-bound refund part of the next one landed on the spent line at 0 VAT — 4.62 instead of
  6.00 VAT credited in the test, the customer 1.38 short. Weighted by the remainder now;
  identical while nothing has been refunded. Counter-check, the line binding taken out:
  feature 5 of 9 red (the other four do not depend on it), browser red. Closed in the same
  round:
  - *The line is refunded by someone else while the modal is open.* On confirming, the
    panel reads the line again: partly refunded → `flash.exceeds_line` with the new
    remainder, used up → `flash.line_already_refunded`; and the `RefundExceedsLineAmountException`
    branch of `runRefund` says the same instead of "exceeds … 0.00". One credit note, one
    refund at Mollie, also for the narrow window between the panel's check and the refund lock
    (dataset part / whole). Seen red: the "used up" cases; counter-check without the catch 2 of
    2 red. The real two-process race on one line is in the concurrency suite.
  - *A saldo-zero plan-switch invoice* is refunded against the payment that settled it
    (`refundablePaymentId()`, the `settled_by_invoice_id` chain) — by amount and by line, as
    `InvoiceService::createRefund()` already did and as the panel's button already offered;
    only `RefundInvoiceService` refused, in English. The lock is the root invoice's (the one
    that payment's webhook takes), the credit note belongs to the plan-switch invoice. With it:
    credit lines against another invoice no longer fold the charge line to 0, the VAT cap of
    a settled invoice is `refundableBaseGross − refundableBaseNet`, `createCreditNote()`'s bound,
    `refunded_net` and `BillingInvoice::isFullyRefunded()` measure `refundableBaseNet()` (such an
    invoice was never "fully refunded" before), and a line's remainder is capped at the
    invoice's. A chain without a payment → `InvalidRefundTargetException` with a sentence. 6
    tests, all red before.
  - *Browser:* the line case is a data provider — seat line AT 20 % (19.80 offered, 12.00 →
    −14.40 at Mollie, 7.80 left, 7.81 refused), plan line DE 19 % (29.00 → 20.00 + 3.80 =
    23.80, 9.00 left), addon line reverse charge NL (4.90 → 4.90 at 0 %, line no longer
    offered); and a saldo-zero plan-switch invoice (AT: Business 14.50 charged, Pro 14.50
    credited against the renewal) — only the charge line offered, 10.00 refunded through the
    renewal's payment (−12.00 at Mollie, one refund), 4.50 left, 4.51 refused. Counter-check,
    the chain resolution switched off — red.
- *PDF content was read with `pdftotext` where installed.* Read with `smalot/pdfparser`
  (require-dev) on every machine: `tests/Support/PdfText.php`, used by the browser
  `InvoicesPortalMatrixTest` (no skip any more) and by the new feature
  `InvoicePdfContentTest` (serial, seller, buyer and every line of an invoice rendered by
  DOMPDF; a credit note with its lines and the invoice it reverses). Counter-check: every
  line label replaced in `InvoiceService::buildPdfInvoice()` — red.
- *`invoices.temporary_url_expiry` / `oss.temporary_url_expiry` were exercised with a faked
  disk only.* `RemoteDiskSignedUrlTest` uses a real S3 disk — Laravel's `s3` driver,
  `league/flysystem-aws-s3-v3` (require-dev) and the AWS SDK's SigV4 presigner — against an
  unresolvable endpoint, with only the SDK's transport answering the one `HeadObject`.
  Asserted on the signed URL: `X-Amz-Expires` is the configured minutes, `X-Amz-Date` +
  `X-Amz-Expires` is exactly "now + minutes", credential, region, a 64-hex signature, the
  OSS link's `response-content-disposition`, and that the file was never fetched through
  the app. Counter-check: one minute added in both controllers — red. And against a real
  server: `RemoteDiskLiveS3Test` (`composer test:s3`, Laravel Herd's S3 service — RustFS on
  this machine, MinIO elsewhere) uploads the file and fetches the signed link: 200 with the
  file inside `temporary_url_expiry`, 403 for a link whose `X-Amz-Expires` was altered, 403
  after the minute; the OSS link answers with the attachment header. Skipped without
  `BILLING_S3_ENDPOINT`. Counter-check: one minute added to the invoice link — red (still
  200 after 62 s).
- *Concurrency was not exercised anywhere.* `tests/Concurrency` (`composer
  test:concurrency`, its own `phpunit.concurrency.xml`; see
  [concurrency-testing.md](concurrency-testing.md)) runs two PHP processes against one
  database and one cache, with a file barrier and a Mollie fake that holds the critical call
  for 2.5 s, and checks that the two runs really overlapped and on which drivers each worker
  ran. Ten cases: the plan-change lock, the refund lock, two `refundLine()` on the same line,
  the webhook reservation, the takeover of a stale reservation, the activation claim (two
  different first payments, one `CreateSubscription`), the seat lock blocking and timing out,
  the quota check of `recordBillingUsage()` (one of two refused, balance exact) and the last
  free slot of a coupon (one redemption). Database `BILLING_CONCURRENCY_DB=sqlite|pgsql`
  (Herd's PostgreSQL), cache and locks `BILLING_CONCURRENCY_CACHE=file|redis` (Herd's
  Valkey); all four combinations green, several runs each. Every guard was switched off once
  and the case went red; the wallet and coupon `lockForUpdate` only on Postgres — SQLite's
  `IMMEDIATE` transactions serialise them anyway, which is why the Postgres run exists.
  bavix's own wallet lock is not the quota guard (`debit()` checks the balance under its own
  row lock first); switching it to `array` stays green on both databases.
  Found on the way and fixed: a second `SyncSeatsJob` inside the confirmation window of the
  first was refused ("already pending payment confirmation") and, with `tries = 1`, the
  member's seat was never billed — without any concurrency, on every second seat job in the
  window. It now waits (put back every 10 minutes, `MAX_PENDING_WAITS` = 7 days; not on a
  sync run, where a delay is ignored). And a put-back copy of an absolute seat job was dropped
  by its own running original's unique lock; copies are unique per dispatch. Seen red:
  `OperationsOpenJobPathsTest` (wait off: 2 of 2; uniqueness fix off: the absolute case) and
  the concurrency case c1 (second seat lost).

### Open decisions for the maintainer

Points 1–3 were decided by the maintainer on 16 November 2026 ("a package without open
points"). They stay here as they were asked, each with its decision; the numbers in point 3
are the ones the decision was taken on.

1. **Taking a seat or an addon away on a cancelled subscription.** It was applied and
   the unused paid share refunded (seat 11.39, addon 5.64 in the browser cell), with no rule
   saying whether a customer who has cancelled should still get partial credits.
   **Decided 16 Nov 2026 — it stays: the unused paid share is refunded.** The customer paid
   for it and no longer uses it; adding something stays refused
   (`SubscriptionEndedChangeNotAllowedException`). Pinned: the amount shown is the refund is
   the credit note, the end date does not move, and nothing is credited for what a 100 %
   discount paid. One thing did change, because the rule had never been checked against it:
   the change sent an `UpdateSubscriptionRequest` to the Mollie subscription that had been
   cancelled with the customer's cancellation. `MollieSubscriptionPatcher::updateForIntent()`
   no longer does. Tests: `ChangeMatrixRemainingCellsTest` ("refunds the unused paid share of
   a seat or addon given back on a cancelled subscription…", red on the PATCH), and the two
   former `open` cells of the browser state matrix, now with amounts computed in the test.
2. **Scheduling a change while past due.** Derived as allowed from "`schedule()` accepts
   active, trial, past_due": a downgrade was scheduled without a word about the open
   payment — and so was an upgrade, which on its night was carried out as the past-due
   reset and charged a full period of the dearer plan.
   **Decided 16 Nov 2026 — changed.** Scheduling an *expansion* (a dearer plan, more seats,
   another paid addon, an interval with a higher amount) is refused with
   `PastDueExpansionNotAllowedException` and the reason on screen. Scheduling a *reduction*
   stays possible; the plan screen says the open payment remains due, before and in the
   confirmation. On its night a scheduled expansion that meets a past-due subscription is
   dropped at once with the customer mail (`payment_outstanding`); a reduction is applied.
   Under a *pure overage debt* no plan or interval change is scheduled in either direction:
   the period is paid, the immediate path refuses it because the reset would charge a second
   period, and a stored change could only be dropped on its night — so it is refused where
   the customer is looking. Seats and addons can still be reduced there. Tests:
   `ChangeMatrixRemainingCellsTest` (five tests over both kinds of past due), browser state
   matrix (`scheduled` / `scheduled_up` × `past_due` / `past_due_overage`).
3. **A second change after a downgrade that spanned a renewal loses the credit of the plan
   held in between.** **Decided 16 Nov 2026 — fixed**: such a period is settled as if it had
   been paid on the plan in between from its first day (`ProrataComposer::planRefundLines()`);
   see [plan-changes.md](plan-changes.md#a-period-whose-plan-change-settled-after-the-renewal).
   The example the decision was taken on — AT, 20 %, February = 28 days:
   - 30 Jan: Enterprise (200.00) → Pro (50.00), paid by SEPA, still pending.
   - 1 Feb: the renewal is collected for Enterprise: 200.00 net / 240.00 gross.
   - 3 Feb: the January payment settles, the plan becomes Pro, and the February difference
     is refunded against the renewal as one amount: 150.00 net / 180.00 gross. The customer
     has now paid 50.00 net for Pro for February — but no invoice line says "Pro".
   - 10 Feb: Pro → Starter (20.00), 19 of 28 days left. Due: credit Pro
     50.00 × 19/28 = 33.93, charge Starter 20.00 × 19/28 = 13.57 → the customer gets
     20.36 net / 24.43 gross back. Actual: no credit, Starter charged 13.57 net /
     16.29 gross. The customer loses 33.93 net / 40.72 gross.
   - The same with an upgrade as second change (Pro → Enterprise on 10 Feb): charged
     200.00 × 19/28 = 135.71 instead of the difference 101.79 — again 33.93 net too much.

   In general the loss is the price of the plan held in between × the share of the period
   that is left; never more than one period price of that plan. It is always the customer
   who loses, never the merchant. Preview and booking agree, so the customer sees the
   amount before confirming. It needs all of: a slow payment method, a plan change within
   its settlement window before a renewal, and a second plan change in the same period. Not
   checked: whether a seat or addon change in that period is affected the same way. Not
   changed; a fix would have the composer read the paid share of the period off the renewal
   minus its credit note when no line names the current plan.
4. **A free-plan customer with a 100 % recurring code cannot upgrade on the plan screen.**
   *Decided on 16 November 2026: the upgrade gets the mandate path.* The checkout handles
   the same order with a zero-amount mandate payment (`mandate_only`), and the plan screen
   now does the same instead of refusing: `UpgradeLocalToMollie::handle()` hands a total of
   0 that a code covers in full to `StartMandateCheckout` (metadata
   `pending_subscription_upgrade_from_local`), and `MandateOnlyPaymentHandler` activates it
   — the checkout's own activation, not a second one. The flag only tells the gate that
   the free plan it finds is the one being replaced, not a duplicate; on completion the
   wallets are moved by `WalletPlanChangeAdjuster` (as the paid upgrade does) rather than
   credited on top, and `SubscriptionUpgradedFromLocal` is dispatched. What it books is
   what the checkout books for the same order — `FullCoverageUpgradeFromFreeTest` compares
   the two outcomes field by field (invoice lines, Mollie amount, start date, redemption).
   Three things that came with it:
   - *A customer who already has a mandate is asked for the €0 authorisation too* —
     decided, see "Deliberate" in the checkout area.
   - *The plan moves with the subscription.* The activation wrote the paid plan's code
     onto the billable before `CreateSubscription`; for a new customer that is harmless,
     for an upgrade it put an ACTIVE free-plan customer on the paid plan for as long as
     Mollie refused to create the subscription. On an upgrade the plan is now written by
     `CreateSubscription` only, and the invoice is given its period explicitly.
   - *Nothing charged is nothing taxed.* `MollieSubscriptionPatcher::recurringLineNets()`
     put one discount line against several catalog lines; fully covered, 29.50 + 11.10 +
     15.50 at 19 % left a cent of VAT, so the plan screen previewed 0.01 and would have
     sent the customer to pay it. A charged net of 0 is now a gross of 0.
   - *The return page asks whether the activation finished.* The webhook drops the
     pending-payment marker once its handlers have run, finished or not; a customer whose
     authorisation went through while Mollie refused the subscription was then told
     "Payment received" on the free plan. For a local subscription the page now also asks
     `WebhookSupport::activationCompleted()`.
   - *The recurring marker counts in the interval being booked.* It took the billable's
     interval, which on an upgrade is still the free plan's: two free YEARS of a yearly
     upgrade were counted as two free months. `applyRecurringMarker()` is handed the
     booked interval.
   - *`WebhookSupport::activationCompleted()` no longer calls an accessible LOCAL plan
     "complete" while an upgrade away from it is on record as unfinished* (a retry marker,
     or `upgrade_from_local_origin`). It did, so the retry job cleared its marker and left
     the customer on the free plan with a mandate, an invoice and no subscription.
   - *A retry can price a recurring code despite its own leftover marker.* The marker is
     written before the Mollie call; the retry then validated the code against it, got
     "already active", and could never finish. `MandateOnlyPaymentHandler` takes the
     unfinished activation's own marker off before pricing and writes it again.
   The return page had the matching fault: a customer on a free plan has an accessible
   subscription all along, so abandoning the authorisation — or a paid upgrade — at Mollie
   was answered with "Payment received". While a first payment is pending for a local
   subscription, the page now asks Mollie about that payment (`checkoutHasLanded()`).
5. **The admin mail on a successful `GrantExtrasPurchaseRefund` was removed.**
   *Decided on 16 November 2026: it stays removed.* It used to mail "…the payment has been
   refunded" when the refund went through, and "queued" when it did not. Both are gone; one
   mail comes from `RetryRefundLineJob` when the retries are used up. A refund that went
   through on its own is not an operator's business; the `GrantExtrasPurchaseRefunded`
   event and its audit entry are how the successful case stays on record. Pinned by
   `sends no admin mail on a successful automatic refund, and still dispatches the event
   and writes the audit entry` in `GrantExtrasGiftGoneRefundTest`.

## A charged subscription is replaced to move its next charge (17 November 2026)

### Finding

The canary's `pinned` subscription (`tests/SandboxBrowser/SubscriptionCanaryTest`) had its
first charge at 00:30 UTC on 17 November 2026 (direct debit, `pending`). On the visit that
morning the canary sent it a new start date, as `MollieSubscriptionPatcher::pinNextChargeDate()`
does after a short month, and Mollie answered **422 "The start date cannot be changed when the
subscription is already charged."** (request body `{"startDate":"2026-12-20"}`). Mollie's
reference does not mention the rule.

The package assumed the opposite everywhere it moves the next charge of a running
subscription: the anchor day after a short month (`pinNextChargeDate`), a trial or period
extension (`setNextChargeDate`, `pushNextChargeDate`, `deferNextChargeDate`), and every
`updateRecurringAmount()` with a start date — a fully covering code or a plan waiver given
mid-subscription, the end of a deferral, an interval change, the past-due reset. On a charged
subscription every one of them failed; the date stayed where Mollie had it, and the retry
(`RetrySubscriptionPatchJob`) could never succeed. Consequences: the anchor day was lost
under "drift"; an extension or a 100 % code was charged on the old date; after an interval
change Mollie kept the old cadence with the new amount; the past-due reset did not reset.

### The rule, measured

Measured against the real test system on 17 November 2026 (`tests/Sandbox/MollieChargedSubscriptionContractTest`,
table in [sandbox-testing.md](sandbox-testing.md#what-a-charged-subscription-showed-measured-17-november-2026)):

- A **charged** subscription refuses every start date other than the one it holds — later,
  the next payment date itself, earlier. Its own start date passes as a no-op. A refused PATCH
  is atomic (an amount sent with it is not applied).
- **Amount, interval, description and metadata** are still taken on a charged subscription. An
  interval change moves `nextPaymentDate` to start date + n × the new interval, counted from
  the **start date**.
- A subscription **not yet charged** takes any start date from today on; an earlier one is
  refused ("Start date is not today or in the future."). **Today** is taken and counts as
  charged at once (`nextPaymentDate` moves one interval on immediately).
- **Descriptions are unique among a customer's active subscriptions** (422 "A subscription with
  the same description already exists for this customer."), free again once cancelled.
- **Cancelling or PATCHing a cancelled subscription** is refused: 422 "The subscription has been
  cancelled." — not 404 or 410.
- A create repeated with the **same idempotency key** returns the same subscription.

The question in the task — does the refusal hold for every subscription with a charge, or only
while a charge of the running cycle is open — cannot be settled in one run: the only charged
subscription available was charged that night and its charge is `pending` (test-mode direct
debits stayed `pending` for over 26 hours on the 16th, and may never settle). It is measured by
the canary (below). **The package does not depend on the answer**: it always tries the PATCH,
and replaces the subscription only when Mollie gives exactly this refusal.

### What the package does now

`MollieSubscriptionPatcher::sendUpdate()` is the one door for every PATCH of the billable's
subscription. It holds the billable's subscription lock (`MollieSubscriptionReplacer::withLock()`,
`billing:mollie-subscription:{type}:{id}`), finishes an unfinished replacement first, sends the
PATCH to the id stored on the row, and — when a start date was sent and Mollie answers "already
charged" — hands the whole PATCH (amount, interval, description, metadata, start date) to
`MollieSubscriptionReplacer::replace()`:

1. read the old subscription (`GetSubscriptionRequest`) — a failure here throws to the caller's
   own retry, nothing has changed at Mollie yet;
2. write `subscription_meta.pending_subscription_replacement`: the complete successor (amount,
   interval, description, metadata, mandate, webhook URL, start date), the old id, the customer;
3. cancel the old subscription (404, 410 and 422 "has been cancelled" count as done), and note
   that on the marker;
4. create the successor, first charged on the target date — **never today or earlier**, else
   tomorrow — with the idempotency key `subscription-replace:{old id}:{date}:{amount}:{interval}`;
   a 422 "same description already exists" means an earlier attempt created it and its answer
   was lost: it is looked up (`GetPaginatedSubscriptionsRequest`, same description and
   `billable_id`) and adopted;
5. store the new id in `mollie_subscription_id`, drop the marker, dispatch
   `MollieSubscriptionReplaced` (audited `mollie_subscription_replaced`); if the customer
   cancelled in the meantime, the new one is cancelled straight away.

Once the marker is written, `replace()` does not throw: the caller's change stands (the marker
carries it), and `RetrySubscriptionReplacementJob` — a minute later, and from the hourly
`SweepPendingRetriesJob` — finishes it. No Mollie call sits in a transaction; the marker and the
new id are written in short ones of their own (`MutatesBillingMeta`). Amounts come from the
request already built with `BillingMoney::mollieAmount()`.

`pinNextChargeDate()` first asks Mollie for its own `nextPaymentDate` and does nothing where
that already is the anchor day.

### Decisions taken in this change

- **Cancel first, then create.** Measured, both orders matter: creating first would need a
  different description (Mollie keeps them unique among active subscriptions), and an
  interruption between create and cancel leaves two subscriptions, the old one charging on its
  old date — a double charge, invisible until it happens. Cancelled first, an interruption
  leaves none: nothing is collected until the successor exists, and the marker — written before
  the cancel — finishes it. Once the old one is cancelled, the uniqueness of descriptions
  guarantees at most one successor even across a lost answer and a retry after the
  idempotency key's hour. The price of this order is a window without a subscription; the
  start date of the successor is the target date (or tomorrow), so nothing the customer owes is
  skipped unless the retry runs past the target date — then that charge comes a few days later,
  never twice.
- **Try the PATCH first, replace only on the refusal.** A subscription Mollie has not charged
  (a trial, a deferred start, a successor before its first charge) keeps its id, and should
  the refusal ever turn out to hold only while a charge is open, the package replaces
  correspondingly less. A replacement is never attempted for any other error.
- **The anchor day is not kept "without a date change".** The interval PATCH showed Mollie
  counting from the start date, which is the "clamp" model — but February is not measured
  before 2027, and a signup on 29–31 January starts on February's last day, so even under
  "clamp" the start day itself is wrong once. The package therefore asks Mollie for its next
  date at the renewal and replaces only where it differs from the anchor day: under "clamp"
  once at most (start on a shortened day), under "drift" after every month shorter than the
  anchor day. Both cadences are run by `RenewalMonthEndCadenceTest` and
  `MonthEndCadenceMatrixTest`; the expected replacements differ per cadence. The 1 April 2027
  reading of the canary still needs no code change.
- **`MollieSubscriptionGate::forceImmediateCharge()` stays a plain PATCH to today.** It is
  offered only while Mollie's start date lies ahead — a subscription Mollie has not charged —
  and a replacement must never start today. A refusal is shown as an error.
- **Payments of the old subscription are not tied to it.** Every webhook finds its billable
  through the payment's metadata (copied onto the successor unchanged), and no handler compares
  `payment.subscriptionId` with the row. A renewal that settles days after the replacement, its
  refund or its failure are booked as before (`ChargedSubscriptionReplacementTest`). A cancel
  aimed at the old id later meets "has been cancelled", which is now success.
- **"Already cancelled" is success everywhere a cancel is sent** — `CancelSubscription`,
  `RetrySubscriptionCancelJob`, `MollieSubscriptionPatcher::cancelForFreeDowngrade()`,
  `WebhookSupport::cancelMollieSubscriptionById()` (`Support\MollieSubscriptionAnswer`). Before,
  a second cancel was retried for 24 hours and then mailed.
- **While a replacement is pending**, `MollieSubscriptionGate` neither drops the cancelled id
  (no `MollieSubscriptionReferenceCleared`) nor lets a checkout start; a customer who cancels in
  between ends it — the retry drops the marker and creates nothing. A downgrade to a free plan
  in between ends it too (26 November 2026, see [Renewal review round](#renewal-review-round-26-november-2026)).
- **The replacement retry never gives up**; the operators are told once, after 24 hours
  (`AdminPlanChangeFailedNotification`). Dropping the marker would make a customer without a
  running subscription permanent and invisible — the one case where a mail is the exception
  that has to stay.
- **A failed anchor pin is retried** (`pending_subscription_patch`, reason `anchor_pin`,
  `RetrySubscriptionPatchJob::applyAnchorPin()`) until its date has come — but only into a free
  slot: a waiting plan-change PATCH carries the amount the customer pays and wins. Before, the
  failure was only logged ("Mollie keeps its own date").
- **The fakes play the real rules.** `LongRunWorld`, `ChangeMatrixWorld` and the browser's
  `FakeMollieHttpAdapter` refuse a start date on a charged subscription, refuse a second cancel
  and a PATCH of a cancelled one, and keep descriptions unique. Three test fixtures that
  created a subscription while the previous one was still active were corrected
  (`CouponWindowDatedAndLegacyTest`, `StatusTransitionMatrixTest` › resubscribe): real Mollie
  would have refused them too.

### Tests: red before, green after

"Before" is the unfixed `MollieSubscriptionPatcher` (and the 404/410-only cancel checks) against
the fakes that play the measured rules; "after" the fixed code, same tests.

| Test | Before | After |
|---|---|---|
| Feature suite, `--parallel --processes=2` | 159 failed | green |
| › `CouponWindowInBillingPeriodsTest` (coupons and free time moving the charge of a running subscription) | 83 red | green |
| › `RenewalMonthEndCadenceTest` (anchor day, both cadences) | 32 red (39 with the old "same dates under both cadences" expectations) | green — expectations now per cadence |
| › `ChangeMatrixIntervalChangeTest` / `ChangeMatrixTimingAndStateTest` / `ChangeMatrixSeatsAndAddonsTest` | 9 / 8 / 8 red | green |
| › `RenewalLongRunTest` / `RenewalLongRunVariantsTest` / `CouponWindowDatedAndLegacyTest` | 5 / 1 / 2 red | green |
| › `ChargedSubscriptionReplacementTest` (new: order, everything kept, crash between cancel and create, lost create answer, never today, old payment and its refund, cancel in between, extension, 24-h mail) | 8 of 9 red (the "cancel in between" case has nothing to replace before) | green |
| › `ChargedSubscriptionCadenceChangeTest` (new: interval change and past-due reset replace, the row names the new id; a seat change keeps the PATCH) | 2 of 3 red | green |
| › `EventCatalogMoneyTest` › `MollieSubscriptionReplaced` | red | green |
| › `RetrySubscriptionCancelJobTest` › 422 "has been cancelled" | red | green |
| Browser `MonthEndCadenceMatrixTest` | drift red ("Mollie's next charge" on 28 Feb), clamp green | green; now also asserts the replacements per cadence (drift: 28 Feb and 30 Apr, clamp: none) and that one subscription charges |
| Browser `CouponWindowMatrixTest` (code over two periods on the 31st; upgrade from the free plan on 31 January) | 3 red (drift, and the upgrade under both cadences — its start date is 28 February) | green; the walk charges the subscription the row names |
| Browser, the other affected files (`ChangeMatrixTest`, `AdminGrantMatrixTest`, `GiftLongRunMatrixTest`, `LifecycleStateMatrixTest`, `CouponEndToEndMatrixTest`, `CouponDashboardRedeemMatrixTest`, `CouponDashboardActionsMatrixTest`, `AdminSubscriptionTabMatrixTest`, `CouponMidCycleTest`, `PlanChangeTest`, `TrialTest`) | — | green |
| Sandbox browser suite (real Mollie, hosted checkout, settled payments, canary) | — | green |
| Sandbox `MollieChargedSubscriptionContractTest` (real Mollie: rules above, the package's replacement and its resume after a lost answer) | — measurement | green |
| Canary (real Mollie) | failed with the 422 as an exception | green; the refusal is recorded in `start_date_answers` |

### Still measured by the canary

Not producible in one run, recorded by the canary and read on a date
([sandbox-testing.md](sandbox-testing.md#what-to-do-and-when)):

- **18 November 2026**: `pinned.start_date_today_answer` — today's date on a charged
  subscription whose start date is another day. Expected: refused.
- **Every visit until the newest `pinned` charge is `paid`**: `pinned.start_date_answers` — the
  first row with `newest_charge_status: paid` answers whether a settled charge lifts the refusal.
- Both are written here on 26 November 2026. Neither answer needs a code change (see above).

Open: nothing.

---

## CodeRabbit review of `resources/` (17 November 2026)

Eight findings on the views and translations; three refuted (see
[Refuted findings](#refuted-findings): `billingDay()`, the 0 € upgrade, past-due overage),
five real. Each of the five was seen red on the unfixed file first.

| Finding | Red before | Change |
|---|---|---|
| `admin.scheduled_changes.applied_pending_payment` was nested under `billables.subscription.grant_column` in `de` and `en` — "Apply now" on a change waiting for its payment showed the raw key. The browser test waited for `__()` of the same key, i.e. for the raw key, and passed | new guard `Coverage/TranslationKeysExistTest` (both locales) | moved into `scheduled_changes`. The guard walks `resources/views` and `src` for every literal `billing::` key and asks the translator for it without fallback; keys built at runtime (a literal ending in `.` or `_`) are skipped |
| Coupon form: plan / interval / addon / product restrictions ticked under a discount kind stayed in state when the kind was switched, and were saved onto a credits or free-time code — out of sight on the form, refused by `validate()` on every order naming another plan | `CouponFormIntentTest` › credits, trial extension, plan waiver | sent only for `single_payment` / `recurring`, the kinds the form offers them for |
| Addons screen: `dueNowFor()` passed the addons that stay as a list, which the preview reads as one unit each. With an app's `getBillingAddonQuantity()` > 1, switching another addon on was quoted as a credit (−13.44) for a change that charged 3.84 | `ChangeSideEffectsAndQuantitiesTest` › on / off | a code ⇒ quantity map, `getBillingAddonQuantity() ?: 1` — the rule of `UpdateSubscription::addonQuantities()` |
| Billing-data page: a tick of `checkForMandateUpdate()` that resolved no billable returned before counting, so the 45-tick budget never ran out | `CheckoutCatalogAndActionsTest` › `billable is gone` | such a tick counts like any other |
| Checkout: the refusal `coupon_ends_before_first_charge` was made only where the code is typed. Applied on a plan without a trial, then the trial plan picked — or applied with a day to spare and submitted later — it stayed, and the summary showed the discount the first charge does not have | `BookingMatrixCouponCrossTest` › plan, clock | one helper `couponEndsBeforeFirstCharge()`, asked by `applyCoupon()` and `refreshAppliedCoupon()` (reason `ends_before_first_charge`), so a change of selection and the submit drop it with the same message |

### Deliberate

- **A stale restriction is dropped at save, not cleared when the kind changes.** Switching
  back to a discount kind keeps what the operator ticked; only what is saved is decided by
  the kind.
- **No billable on a poll tick is a tick, not the end of the wait.** The resolver can answer
  `null` for a moment (a session refresh); stopping at once would end a wait that would have
  been answered. The budget still bounds it.

Open: nothing.

---

## Hidden usage types (17 November 2026)

`usage_types.{type}.visible => false` leaves a usage type off the plan cards. The pattern is
that of hidden features (`featureIsVisible()` / `visiblePlanFeatures()`): presentation only,
one catalog method a custom catalog answers, the literal `false` as the only value that
hides, `billing:check-config` rejecting anything else.

### What is hidden, and what deliberately is not

- **Hidden: the plan cards a customer chooses from.** The checkout's plan step
  (`checkout::planUsages()`, rendered twice in `step-plan.blade.php`) and the plan cards of
  the plan-change screen. Both read `visibleIncludedUsages()`. No other screen lists a
  plan's quotas: the dashboard's plan card names no usage type, addons carry no quota, and
  the products page names the type a top-up credits — that is what the customer buys, not a
  plan entitlement, so it stays.
- **Not hidden: wherever the customer reads what was used or what is billed.** The usage
  meters (dashboard and usage page), the usage settlement and overage lines of the
  plan-change preview, invoices and credit notes, the overage charge's line items, and the
  admin panel. A hidden type is still consumed and can still cost money; a customer who is
  charged for it has to be able to see it, and an operator has to be able to credit it.
- **Not changed: billing.** `includedUsages()`, `includedUsage()` and `usageOveragePrice()`
  ignore `visible`. Quota, credit on activation and renewal, rollover, the hard cap,
  overage, the plan-change rebalance and past-due refusals behave exactly as for a visible
  type. `HiddenUsageTypesTest` pins that, and was seen red against a deliberately
  over-eager variant (hiding in `includedUsages()` / `usageOveragePrice()` and in the
  dashboard's meter list).

### Judgement calls

- **A priced hidden type is a warning, not an error.** The customer pays per unit for
  something the plan card did not state — almost always a mistake, but an app may state it
  elsewhere (its own pricing page). Any overage price other than `false` or `null` counts,
  `0` included, as written in the request; the warning names every plan and interval.
- **`rollover` stays required in a `usage_types` entry**, also when the entry exists only to
  hide the type. `billing:check-config` already required it; relaxing that is a separate
  decision.
- **No translation keys.** Nothing new is shown to the customer; the check-config messages
  are English like all others.

### Tests: red before, green after

| Test | Without the change | With it |
|---|---|---|
| `Feature/Catalog/HiddenUsageTypesTest` (22 cases) | 16 red (catalog methods missing, check-config silent, plan cards name the type); the 6 that pin unchanged behaviour green | 22 green |
| same, against the over-eager variant above | 5 red: dashboard meter, credit/cap/charge, activation, preview, `visibleIncludedUsages` vs `includedUsages` | — |
| `Browser/HiddenUsageTypeTest` (3) | checkout cards and plan-change cards red ("Saw unexpected text [SMS]"); meters green | 3 green |
| same, with the dashboard listing only visible types | meter test red (no `usage-meter-SMS`) | — |

Open: nothing.

## Checkout draft, return page without a checkout, confirm button by booking path (20 November 2026)

Three findings from a walk through the checkout:

1. Leaving the checkout or reloading it lost everything typed, picked and the step reached.
2. `/billing/return` opened without any payment behind it showed "Activating your
   subscription…" and polled for ~90 seconds before timing out.
3. The confirm button said "Confirm and pay" on a trial, directly below the notice that
   nothing is charged during it.

### Checkout draft — what was decided

- **Session, not database, not the billable.** The draft is in the session
  (`CheckoutDraft::SESSION_KEY`), so it is bound to one browser and ends with the session.
  Nothing of it is written to the billable: an address typed into an unlocked billing step is
  persisted by `submit()` exactly as before, and only there.
- **Filed per signed-in user, billable and checkout URL.** The key hashes the default
  guard's user (or "guest"), the billable the checkout was opened for ("new" for a signup) and
  the route name plus parameters. Laravel keeps session data across `login()`, so the user is
  part of the key: a logout and another login in the same browser never read the previous
  person's draft. A page that is still open when another user signs in in another tab stops
  writing and *drops* the draft on its next update — the safe direction on a shared machine.
- **Input only, re-derived on resume.** Stored: the address fields (not while the billing step
  is locked), plan, interval, addons, seats, the coupon *code*, the step, and app `customData`
  keys listed in `checkout_draft_custom_data`. Never stored: amounts, the discount, the VIES
  verdict, the billable id, `billing_locked`. On resume the step validators run in order
  (`resumableStep()`), the VAT number is checked against VIES again, the coupon goes through
  `applyCoupon()` as if typed, unsold addons are dropped, and the resume stops at the first
  step that does not hold. A tampered session cannot price, unlock or re-target anything.
- **`customData` is opt-in per key.** App steps may hold confirmation codes or things they
  verify server-side; restoring those blindly would let a draft skip the check. Default `[]`:
  the step is shown again, everything after it is still restored.
- **A link that names another plan wins.** `?plan=` / `?interval=` that differ from the draft
  are a new choice: applied, and the resume stops at the plan step so the customer sees it.
  The same plan in the link is no new choice and resumes as far as the draft went.
- **A locked billing step stays locked**, and its step selection is mount's own: the resume
  never goes back to the billing step, and the draft's address fields are ignored.
- **A signup left at Mollie resumes as the billable it created** (`adoptCreated()`): the
  draft records the billable its checkout created; when the same signed-in user in the same
  session comes back as that (authorized) billable, the draft moves to it. Nothing grants
  access: the caller has already authorized the billable.
- **Safe exit.** Refused authorization on mount → no draft read, none written. A billable
  deleted or no longer authorized while the page is open → the draft is dropped on the next
  update, and `submit()` answers as before (`error_no_billable` / 403) — nothing is re-created.
  A signup draft whose created billable is gone → dropped, the checkout starts over.
- **Expiry.** `checkout_draft_ttl_minutes` (default 1440) since the last change, sliding;
  `0` switches drafts off. At most ten drafts per session. Dropped when the subscription
  exists: the return page (`markActivated()`), a booking that completes without Mollie, and
  the checkout redirecting a billable that already has one. A payment *created* is not the
  end: a customer who cancels at Mollie lands back on the checkout and finds it as left.
- **Known limit, unchanged:** an anonymous signup that is left at Mollie and comes back
  without the app resolving the created billable is still a new signup on submit — as it was
  without drafts. The draft does not reattach a billable the visitor cannot be authorized for.

### Return page — what was decided

- Decided on mount: landed → activated; something under way → poll; nothing → the new
  `noSubscription` state ("No subscription has been taken out yet", button "Continue
  subscription checkout") with no polling. "Under way" is `pending_first_payment_id`,
  `activation_claim`, `activation_retries` or `upgrade_from_local_origin` — the same markers
  `checkoutHasLanded()` / `WebhookSupport::activationCompleted()` already rely on. The webhook
  keeps the pending marker on a failed payment until this page reported it, and an activation
  whose retries are spent refunds the payment, so "nothing under way" really means no money
  is waiting for a subscription.
- **No visible billable at all** (neither authorized nor this browser's checkout stamp) is
  the same state: there is no process this visitor may see, so the page claims none.
- The one-time-order branch (`origin=products`) is untouched.

### Confirm button — what was decided

- `checkout::bookingPath()` mirrors `StartSubscriptionCheckout::handle()`'s order: access
  grant → free plan → trial → zero amount (mandate) → payment, with the service's own coupon
  source (form code, else session code). Each path has its own key (`confirm_and_pay`,
  `confirm_start_trial`, `confirm_add_payment_method`, `confirm_activate_free`,
  `confirm_activate_access`) and the two local paths their own notice instead of "you will be
  redirected to Mollie".
- `announcedTrialDays()` now asks the booking path instead of `trialApplies()` alone, so an
  access grant on a plan with trial days, or a free plan with trial days, no longer
  announces a trial the service will not start. `trialApplies()` keeps its meaning for the
  coupon checks.
- The German pay label stays "Bestätigen und bezahlen". Whether a trial that converts into a
  paid subscription needs "zahlungspflichtig" wording on the button (§ 312j BGB) is the
  consuming app's legal call; the keys are there to override.

### Tests: red before, green after

| Test | Against the unchanged components | With the change |
|---|---|---|
| `Feature/Checkout/CheckoutDraftTest` (22) | 19 red (nothing resumed; `draftKey` unknown); 3 green that pin unchanged guards | 22 green |
| `Feature/Checkout/ConfirmButtonBookingPathTest` (9) | 9 red (`bookingPath()` missing, "Confirm and pay" on every path) | 9 green |
| `Feature/Portal/ReturnWithoutCheckoutTest` (13) | 13 red (`noSubscription` unknown; the direct visit polls) | 13 green |
| `Browser/Matrix/CheckoutOptionsMatrixTest`: draft (desktop/mobile × monthly/yearly), accounts apart, config keys, return page without payment, confirm labels (8) | 8 red (nothing resumed, page polls, "Confirm and pay" on the trial) | 8 green |
| Browser regression: `CheckoutOptions`, `CheckoutOtherPaths`, `CheckoutSubscription` matrices, `CheckoutTest`, `TrialTest`, `CouponCheckoutTest`, `CouponCatalogCheckoutTest`, `CouponWindowMatrixTest` | — | 278 green |

Open: nothing.

## "Previous page" at Mollie: the return page and the checkout sent the customer in a circle (20 November 2026)

Reported from meinrezept: a trial checkout, credit card picked at Mollie, then "Vorige Seite".
The customer landed on "Abo wird aktiviert…" and stayed there; starting the checkout again
landed on the same page instead of Mollie.

Reproduced against the live test payment (`GetPaymentRequest`): status `open`, method
`creditcard`, **no `_links.checkout`**, expiry 15 minutes after creation. Mollie sends
"previous page" to the `redirectUrl`, not the `cancelUrl`, and the payment stays `open`.

- The return page polled an `open` payment as "still in flight" — for 90 s, and then again
  on every "check again", until the payment expired.
- `reusablePendingCheckoutUrl()` answered `open` without a checkout link with the return
  page (the fallback was written for `pending`), so every new submit went back there.

### What was decided

- **`open` without a checkout link is not a checkout to return to.** Nobody can pay it any
  more; the checkout creates a new payment. `pending` without a link still goes to the return
  page — it has left the browser and may still be paid. A payment that settles after all is
  caught by the activation gate as before. The old payment is not cancelled: a credit-card
  payment is not cancelable at Mollie, and it expires on its own.
- **`open` while the customer is on the return page is abandoned after a grace**
  (`ABANDONED_AFTER_TICKS`, 5 ticks ≈ 10 s; some methods return before Mollie has the bank's
  answer). Shown as "payment not completed" (`failureReason = 'abandoned'`) with "try again"
  into the checkout, which resumes at Mollie while the link exists and starts over when it
  does not. Unlike failed/canceled/expired the **marker stays**, so the checkout can make
  that choice and a late `paid` is still found.

### Tests: red before, green after

| Test | Against the unchanged components | With the change |
|---|---|---|
| `CheckoutReentrancyTest` › creates a payment when the open one has lost its checkout link | red (redirect to the return page) | green |
| `CheckoutReentrancyTest` › sends a pending first payment without a checkout link to the return page | green (pins the unchanged branch) | green |
| `ReturnWithoutCheckoutTest` › calls a first payment that stays open … abandoned | red (polls on) | green |
| `ReturnWithoutCheckoutTest` › keeps waiting for a pending first payment | green (pins the unchanged branch) | green |

## Validation messages use the form labels (20 November 2026)

The billing forms validated without attribute names, so Laravel printed the property:
"company name muss ausgefüllt werden" under "Name auf der Rechnung".

- **One list, `Support\BillingFieldNames::attributes()`**, returned from
  `validationAttributes()` by the checkout, billing-data and plan-change components (their
  address properties share names). The dashboard's country fix (`fix*` properties) maps to its
  own labels in place.
- **The address fields reuse the label keys** (`billing::checkout.company_name`, `street`, …)
  rather than separate attribute keys: an app that overrides a label gets the same word in the
  message without overriding twice.
- **The VAT number is the exception** (`vat_number_attribute`): its label carries
  "(optional)", which reads wrong inside a sentence. `interval_attribute` exists because the
  checkout has no label for the interval field.
- Not done: errors stay under a field until the next "Next" re-validates it, as before — a
  field fixed after the error still shows it until then.

Tests: `Feature/Checkout/ValidationFieldNamesTest` (5) — 5 red against the unchanged
components, 5 green with the change.


## E-invoice embedded in every invoice PDF (22 November 2026)

Every stored invoice and credit note is a hybrid ZUGFeRD / Factur-X document: EN 16931 CII
as `factur-x.xml` inside a PDF/A-3 (`EInvoiceService`, `horstoeko/invoicesuite`). On by
default, off with `BILLING_E_INVOICE_ENABLED=false`. See `docs/e-invoicing.md`.

### What was decided

- **Hybrid, not a separate XML file.** § 14 UStG accepts ZUGFeRD from 2.0.1 in every profile
  but MINIMUM and BASIC-WL, the customer keeps getting one readable PDF, and Austrian
  businesses accept it. `en16931` is the default profile; `xrechnung` exists for German
  public buyers.
- **A failed e-invoice keeps the plain PDF** and logs at error level. Refusing the document
  would leave a paid invoice without any document, which is worse until 2027; the cause is
  almost always incomplete seller data, fixed by regenerating. The library's message bag is
  treated as a failure, so a field the profile could not carry is never dropped silently.
- **Built from the stored invoice.** VAT category from the invoice's own rates, country and
  VIES validation (`vat_validation_id`), not the billable's current VAT number. Buyer name and
  address still come from the billable, like the readable PDF; that is a known gap (no
  snapshot), not introduced here.
- **Credit note = type 381 with positive amounts**, the stored negative figures sign-flipped;
  BT-25 names every invoice the lines' `parent_invoice_id` point at.
- **A negative line has a negative quantity, never a negative price** (BR-27). A line whose net
  is not quantity × unit price collapses to one unit, the same collapse the PDF makes.
- **Category O (customer outside the EU):** no VAT IDs (BR-O-02), the seller is identified by
  BT-29 (the tax number does not satisfy BR-CO-26), no rate on the line (BR-O-05) but 0 in the
  breakdown (XRechnung BR-DE-14). invoicesuite drops a line tax without a rate, so the line is
  built with 0 % and `EInvoiceService::render()` removes the rate from the XML.
- **Payment means 68 (online payment service)** for every Mollie method. Card (54) and SEPA
  direct debit (59) carry XRechnung rules (card number, creditor ID, mandate) we cannot fill
  from Mollie's payment details.
- **The issue date is the date the PDF prints** (`created_at`, UTC), not the billable's local
  date: the two parts of a hybrid must not disagree.
- **PDF in DejaVu Sans while e-invoicing is on**, because PDF/A-3 requires embedded fonts and
  DOMPDF never embeds Helvetica. An app's own template font wins.
- **`seller.vat_id` added; a VAT ID in `tax_number` keeps working.** The PDF prints `vat_id`
  as a labelled field when it differs from `tax_number`.
- **Zero-rated invoices print the reason** (reverse charge with the customer's VAT ID, outside
  the EU, configured exemption) — the PDF had shown 0 % without one.
- **The reverse-charge wording follows the seller's country, not the locale.** A German-language
  invoice from Vienna quoted the German § 14a formula; Austrian law words it "Übergang der
  Steuerschuld auf den Leistungsempfänger" (§ 11 Abs. 1a UStG 1994) and says "UID-Nummer".
  Country keys (`…_at`) are looked up in the rendering locale only, never through the fallback
  locale; `BILLING_REVERSE_CHARGE_TEXT` overrides everything. The note is the phrase alone —
  no norm in brackets, no VAT ID appended — because both VAT IDs are printed, labelled, with
  the parties; a longer note was read as misleading. No "§ 13b UStG" in the German
  text: § 13b governs supplies whose place is in Germany, not a German seller's supply to
  another EU country.
- **invoicesuite is pinned to `^0.0.44`:** pre-1.0, and the user asked for it over
  `horstoeko/zugferd`.

- **PDF presentation, from the review of the sample invoices:** the service period is printed
  per line from the stored period (the invoice's when a line has none), with the same inclusive
  end date as the XML; dates follow the invoice locale (`d.m.Y` German, ISO in English) through
  `invoices.date_format` for the duration of one render, restored afterwards; payment methods
  use the portal's labels; both parties' VAT IDs and the seller's tax number are labelled
  fields instead of the template's bare `tax_number`. The buyer's VAT ID is the invoice's own
  VIES validation, so the buyer block and the reverse-charge note can no longer disagree.

- **A product invoice states a delivery date, not a period.** `createForPayment()` stamps a
  one-time order with the billable's subscription period (or a month from now) because refunds
  locate lines by it, so that stored period would have been printed as "Leistungszeitraum" on a
  product. `EInvoiceService::deliveryDate()` decides at render time instead — the stored data is
  untouched: one-time orders, and credit notes whose parents are all one-time orders, state the
  invoice date as "Leistungsdatum" (PDF) and BT-72 (XML). Overage keeps its period.
- **Overage is invoiced for the period the usage was in.** Reproduced: charged on 31 October for
  October, the SEPA payment settled on 3 November after the renewal had moved the anchor, and the
  invoice said 1 November – 1 December. **Every line of the pending snapshot (`usage_overage`)
  carries the period its usage was in** (`ChargeUsageOverageDirectly::stampUsagePeriods()`):
  a line without one is new overage of the current period, a line with one keeps it — a retry
  passes the snapshot's own lines, and a debt left standing by a failed cycle keeps September
  when a later renewal or a plan change collects it together with October's overage. The
  snapshot's `period_start`/`period_end` is the span of its lines. Not in the payment metadata:
  Mollie caps it at 1024 bytes and the line items already fill it, so `createMolliePayment()`
  strips the periods and `SingleChargeHandler` copies them back from the snapshot by position
  (`overageLinePeriods()`; only when both lists have the same length) and books the invoice for
  the span (`overageUsagePeriod()`). A payment that does not belong to the snapshot's cycle — in
  flight across the upgrade — is dated by the period Mollie created it in, else the billable's
  period as before. The periods are copied **before** the reconciliation: when the tax
  treatment changed while a SEPA payment settled (a VAT number validated in between), the
  reconciliation appends an adjustment line, the position match failed and every line got the
  span (reproduced, `OverageInvoicePeriodTest`).
- **Category O drops the VAT identifiers only when the document has O lines** (CodeRabbit). The
  zero-rate category was decided by the buyer's country alone, so a non-EU country with a
  configured rate (`additional_countries`, e.g. CH 8.1 %) — every line S — lost the seller's VAT
  ID and broke BR-S-02. Reproduced, `EInvoiceTest`, and a `non-eu-taxed` KoSIT scenario.
- **A retry of a cycle from before the upgrade keeps its period** (CodeRabbit). Its lines carry
  no period, and the retry stamped them with the period it ran in; they now take the snapshot's
  period, or the period the cycle started in (`withCyclePeriod()`). An unreadable line period no
  longer sets or blocks the snapshot's span (`stampUsagePeriods()` parses each bound on its own).
- **Category O never stands next to taxed lines** (CodeRabbit, BR-O-11 to BR-O-14). A taxed
  non-EU country with one zero-rated line produced S and O on one document; the zero-rated line
  is now exempt (E) with the configured exemption reason, on the PDF as in the XML.
- **Service periods are UTC billing days** (CodeRabbit). They were converted into the billable's
  timezone, against `docs/timezone.md`: a period ending at 00:00 UTC ended on 1 December in
  Vienna and began on 31 October in New York. And the end is now the day before the next
  period's first day whatever the hour — a boundary at 00:28 UTC (Mollie's charge time) or at
  the hour of a first payment used to print the next period's first day. Reproduced for UTC,
  Vienna, New York and an incidental hour.
- **A credit note without a header period is dated by its lines** (CodeRabbit).
  `createCreditNote()` stores the credited period on the lines only; the XML fell back to the
  credit note's issue date as delivery date (December for a November subscription). The header
  period is now the span of the line periods.
- **The corrected invoices are loaded once per document.** `deliveryDate()` looked them up for
  every line of a credit note — 100 lines, 100 identical queries (reproduced). The PDF resolves
  the delivery date once before its line loop, and the XML loads the parents once for the
  delivery date and BT-25: two lookups per document, independent of the number of lines.

### Known limits, not changed here

Found by a legal review of the invoice creation against § 14 UStG, § 11 UStG 1994 and
Art. 226 VAT Directive; open, each needs its own decision:

| Gap | Norm |
|---|---|
| No net/VAT summary per rate on the PDF, only per line plus one total | § 14 (4) Nr. 7/8 UStG |
| Buyer and seller data are read live at render time; `regeneratePdf()` overwrites the archived file | § 14b UStG, GoBD, § 132 BAO |
| Plan-change credit lines and the plan-switch document do not name the invoice they correct (number and date) | Art. 219 VAT Directive, § 31 (5) UStDV |
| "Gutschrift" as the German title of a credit note (self-billing in § 14 (2) UStG) | BMF 25 Oct 2013 |
| No VAT amount in EUR for a non-EUR invoice | Art. 230 VAT Directive |
| A correction of an e-invoice is a credit note plus new invoice, not a type-384 document | BMF letter on e-invoicing |
| XRechnung needs buyer city and postcode (BR-DE-8/9) | XRechnung CIUS |
| The zero-rate category (AE/E/O) is decided from the *current* seller country when a document is (re)generated; a seller that moves its establishment would regenerate an old reverse-charge invoice as E (CodeRabbit). Same root cause as the live party data above — fixed together by snapshotting the parties and the tax treatment at issue | § 14b UStG, GoBD |
| Austrian federal government needs ebInterface or PEPPOL BIS (not ZUGFeRD) | e-rechnung.gv.at |

### Tests: red before, green after

`Feature/Webhook/OverageInvoicePeriodTest` (5): late SEPA settlement, retry after the renewal,
payment from before the upgrade — 3 red against the unchanged handler and service, green with the
fix; a standing debt collected with new overage — red with one period for the snapshot (both
lines September–November), green with a period per line; the control case (settled within its own
period) passes all versions. `Feature/Invoice/EInvoiceTest` (16): schema-valid XML embedded as `factur-x.xml`, every VAT
category, credit note, discount line, XRechnung, disabled and failing paths, embedded font,
seller VAT ID on the PDF, PDF/XML date at midnight UTC (red with the billable-timezone date,
green with the fix). `Feature/Commands/CheckConfigEInvoiceTest` (8). `EInvoiceKositTest`
runs 11 shapes × 2 profiles through the KoSIT validator (EN 16931 + XRechnung 3.0.2): 22
accepted. Before the category-O fixes it rejected the O documents (BR-O-05, BR-CO-26,
BR-DE-14).

## Admin invoice list with date filter and ZIP export (24 November 2026)

The admin panel had invoices only per billable (the invoices tab) and credit notes only in
the refunds list. `billing/admin/invoices` now lists every invoice and credit note, filtered
by an issue-date range (with quick filters this/last month, this/last year), type, status and
search; each row opens a detail, shows the PDF in the browser, downloads it, and leads to its
billable; the filtered list exports as one ZIP of the PDFs. Flow: `docs/flows.md` § 18.2.

### What was decided

- **Dates are UTC days, both ends inclusive, matched against `created_at`, and the panel says
  so.** A review raised that an operator in Vienna saw an invoice of 31 October 23:30 UTC —
  their 1 November — under October. Following the operator's timezone in the invoice list
  was built and then withdrawn by the maintainer: the admin panel works in UTC everywhere,
  so that it is unambiguous — identical to the database, the logs and Mollie — and one
  screen in another zone would make every comparison between screens a conversion. What
  was missing was not the conversion but the label: the header now carries "All times in
  UTC" on every admin page, every time is printed with "UTC"
  (`<x-mollie-billing::admin.time>`, `BillingTime::formatUtc()`), date inputs say "(UTC)",
  and `AdminUtcLabelGuardTest` fails on a view that prints a bare time. The issue date is
  `created_at`, not the service period: an accountant exports "what was invoiced in
  October". The upper bound is `< next day 00:00`, not `<= 23:59:59`, which loses rows with
  fractional seconds.
- **The list is served by an index on `(created_at, id)`, and a render counts once.**
  Found in review: every existing index starts with the billable, so the global list scanned
  and sorted the whole table — validated at 500 000 rows; with the index the first page reads
  25 rows. The count and the sums per currency are one grouped query whose count is handed
  to the paginator as its total (it no longer counts again), and they are kept in the
  component between renders: sorting, paging and opening a detail do not change them. They
  are recomputed when the filter changes, or after 60 seconds so a list left open catches
  up with new invoices.
- **One filter object for the screen and the export** (`AdminInvoiceFilter`). The export is a
  plain GET link carrying the filter as query string — the archive holds exactly what the
  list shows, and a filtered list is bookmarkable (the component's filter properties are
  `#[Url]`). A range entered backwards is swapped, not treated as empty; an unknown type or
  status is no filter, not an empty list.
- **Credit notes are in the list and the archive**, with their negative amounts; the totals
  per currency therefore show what was really billed. They can be filtered out by type.
- **The ZIP is built synchronously, capped by `invoices.export_max` (1000).** A queued job with
  a download page (like the OSS export) would be the answer for very large archives; for the
  month or year an operator exports, one request is simpler and immediate. Above the cap the
  button is disabled and the route answers 422.
- **A PDF that is missing is regenerated, one that cannot be is named in `MISSING.txt`.** The
  regeneration that `InvoiceDownloadController` did inline moved to
  `InvoiceService::ensureStoredPdf()`, so download, view and export share the lock and the
  behaviour. An archive that silently lacks an invoice would look complete to the accountant.
- **File names inside the ZIP are the single download's** (`BillingInvoice::pdfFilename()`,
  the slugged serial), so the same document has the same name wherever it is fetched from.
  A `/` or `\` in the serial becomes `-` before slugging (`RE/26/0001` → `re-26-0001.pdf`):
  `Str::slug()` alone dropped it, which gave `re260001.pdf` — unreadable, and two serials
  differing only in where the slash sits collided. This also changes the name of the single
  download, which only ever affected the suggested file name, never a stored path.
- **"View" is the download controller with `disposition=inline`** on an admin-only route
  (`billing.admin.invoice.view`), not a second controller: authorisation, regeneration and the
  signed-URL path for remote disks stay in one place.
- **`ext-zip` is suggested, not required.** Without it the export button is disabled with the
  reason, the route answers 501, and the rest of the package is unaffected.

### Tests

`Feature/Admin/AdminInvoiceListTest` (18): range boundaries to the second, presets including
the 31st-of-the-month overflow, type/status/search, sorting, detail, the ZIP's exact content,
regeneration and `MISSING.txt`, the cap, the admin gate, inline vs attachment, file names.
Review fixes, each red before and green after: on a Vienna server, 31 October 23:30 UTC
stays October in list and export and is printed "… 23:30 UTC"; `AdminUtcLabelGuardTest`
(red against the admin views before they were labelled); the query plan reads `billing_invoices_created_at_id_index` with no
temporary sort (red without the migration); one aggregate per render and none on
sort/page/detail (red with the paginator counting on its own).
`Browser/Matrix/AdminListsMatrixTest::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable`
clicks the quick filters, opens a bookmarked range, fetches the ZIP and the inline PDF, and
follows the row's action menu to the billable.

### Code review of the invoice list (24 November 2026)

Six findings; each fix has a test that was run against the code with that fix taken back
and failed there.

- **Search on PostgreSQL** — reported as a 500 for any text, because `billable_id LIKE` on
  a bigint/uuid column. **Refuted as stated:** reproduced on PostgreSQL 18 with both key
  types; Laravel's Postgres grammar casts the column to text for `like`, so the query runs.
  **What was real:** Postgres `LIKE` is case-sensitive, so "re-2026" did not find
  "RE-2026", "tr_abc" not "tr_AbC123", and — through `HasBilling::scopeBillableSearch()` —
  "acme" not "Acme GmbH"; and `%`/`_` in the term were wildcards ("%" matched everything).
  Now `AdminInvoiceFilter::applySearch()` (shared with the refunds list, which had the same
  query) compares `lower(…) like ? escape '!'` on serial and payment id and matches the
  billable id **exactly**, and only when the term has the shape of the configured key type;
  the default billable search scope does the same for name and email. `!` is the escape
  character because a backslash means different things in the three drivers' string
  literals. `AdminInvoiceSearchPostgresTest` runs against a real Postgres (skipped without
  one).
- **Half-built ZIP left in the temp directory** after a failed export — ZipArchive's
  destructor wrote it back to the path just unlinked. The archive is now discarded
  (`unchangeAll()` + `close()`) before the unlink.
- **Truncated remote PDF packed as if complete** — the copied byte count is now compared with
  the file's size on the disk; a short copy (or a failed `fopen`) goes to `MISSING.txt`.
- **Kept count used as the paginator's total** — a cached 0 made `paginate()` skip the row
  query, an empty list for up to a minute while invoices existed. The kept count is handed
  over only in the render that computed it; otherwise the paginator counts itself. Still one
  aggregate per render; the count shown and the export button follow the paginator.
- **Date with a trailing newline** — `$` matched before it and `createFromFormat()` threw.
  Now `\z` and a caught parse error. In a standard app `TrimStrings` strips it from the
  request first, so this was reachable mainly through other callers of the filter.
- **Export cap counted rows, not work** — after a disk migration an export rendered every
  missing PDF in one request. At most `InvoiceArchiveService::MAX_REGENERATIONS` (25) per
  export now; the rest is listed in `MISSING.txt` with "export again to continue", and since
  what was rendered is stored, repeated exports complete the range.


### CodeRabbit review of the invoice list (24 November 2026)

Eight findings, all reproduced and fixed; each fix has a test that failed with that fix
taken back.

- **Failed regenerations used up every export's budget** — the same unrenderable invoices at
  the head of the range took the 25 attempts each time, so the regenerable ones behind them
  were never reached and "export again to continue" was false. A failed regeneration is now
  remembered for `InvoiceArchiveService::FAILED_REGENERATION_TTL_SECONDS` (600): exports name
  it in `MISSING.txt` without attempting or counting it. The bound per request stays.
- **Billable id past the bigint range** — `9999999999999999999` had the shape of an `int`
  key and was compared with a Postgres `bigint` column, a conversion error. Now compared as
  a string against `PHP_INT_MAX` first; out of range, only the text branches search.
- **A read error of one remote PDF dropped the whole archive** — a disk with
  `throw => true` throws from `readStream()`. That `FilesystemException` now makes that
  invoice missing, not the export.
- **SQLite folds ASCII only** — `mb_strtolower("MÜLLER")` is "müller", SQLite's `lower()`
  gives "mÜller", so the exact spelling no longer found itself. A second pass found the same
  kind of mismatch on Postgres (simple case mapping keeps a final "Σ" as "σ", PHP gives
  "ς"). `Support\ContainsSearch` therefore never folds in PHP: the SQL is
  `lower(column) like lower(?) escape '!'`, both sides folded by the same database. As
  before the review, "müller" does not find "MÜLLER" on SQLite; README says so.
- **A suffixed file name could be another serial's own** — "RE/26" with id 3 became
  "re-26-3.pdf", the name of serial "RE-26-3", and `addFile()` overwrites. The suffix is now
  extended until the name is free.
- **The export cap did not bound the export** — counted once, then queried again in pages,
  so invoices booked meanwhile slipped in, past `export_max`. The ids are now selected once
  (one past the cap) and only they are built.
- **An invoice on a disk that is no longer configured could never be regenerated** —
  `Storage::disk()` threw before the regeneration. An unconfigured `pdf_disk` now counts as
  a missing PDF; errors of a configured disk still surface.
- **`BillingTime::toUtc()` kept a named zone** — "2026-11-24 16:03:00 Europe/Vienna" stayed
  16:03 and `formatUtc()` labelled it UTC. The fallback now converts to UTC (15:03).

Second pass over the merged branch, four more, each with a test that failed without its fix:

- **Empty local PDF packed as delivered** — an interrupted write leaves a zero-byte file
  that `exists()` reports; the local branch now requires a non-empty file, like the remote
  branch already did.
- **An existence check that throws dropped the archive** — S3 can throw
  `UnableToCheckFileExistence` from `hasStoredPdf()`. Any `FilesystemException` while
  getting one document now makes that invoice missing; only writing the archive is fatal.
- **A page past the last one showed the empty state** while invoices matched, with no way
  back but changing a filter. The list now shows the last page instead.
- **README promised case-insensitivity on every database** — qualified for SQLite.

## Checkout: one payment per order, and what a failed activation leaves behind (25 November 2026)

Six findings of a review of the checkout, each reproduced by a feature test before it was
changed.

### What was wrong, and the rule now

- **A first payment on record is reused only for the same order.** `reusablePendingCheckoutUrl()`
  decided on status and checkout link alone. Back from Mollie, another plan (interval, addons,
  seats, code), confirm → redirected to the OLD payment, whose metadata the activation books:
  the old order at the old price. *Rule:* `Services\Billing\PendingFirstPayment::redirectFor()`
  compares what activation reads — plan, interval, addons, seats, the `upgrade_from_local` /
  `keep_grant` flags — and, for a payment that collects money, the amount (a code's effect
  shows there). The code itself is compared only on a €0 authorisation, where the amount
  cannot tell; there the order's code is the form's, else the promotion link's, exactly as
  `StartSubscriptionCheckout` falls back. A payment whose metadata names no plan is not one
  this package wrote for an order and is reused as before.
- **Paid, not yet activated, is not "no subscription".** The checkout and
  `StartSubscriptionCheckout` looked only at `hasAccessibleBillingSubscription()`. With the
  webhook not there yet, or `CreateSubscription` failed and waiting on the retry ladder
  (~7 h), a customer redirected to the checkout (`RequireActiveSubscription` does exactly that)
  paid a second time. *Rule:* `WebhookSupport::activationInFlight()` (a retry marker, or a
  fresh activation claim) and a payment on record that Mollie reports `paid` send the customer
  to the return page; the service throws `ActivationInFlightException` for an app's own
  checkout. *Follow-up (review 3):* a €0 authorisation whose activation was **refused**
  (`reportSkippedActivation()` — typically a 100% code that lost its cover before the
  webhook) also leaves a retry marker, and every retry is refused the same way: the customer
  was kept from buying at full price for the whole ladder (~7 h) with no money collected.
  Such a marker now carries `refused_without_charge` and does not count. Every other marker
  still does — see the judgement call below.
- **The plan page's upgrade and the purchase of extras on a gift ask the same question.**
  They released their submit lock once the payment existed and never looked at the payment on
  record; the plan page's comment claimed the lock covered "browser-Back from Mollie and click
  again", which it cannot. Both call `PendingFirstPayment` under the lock.
- **A payment-method change revoked the mandate the subscription was bound to.**
  `CreateSubscription` passes `mandateId` on purpose (commit 141d422); `RevokePreviousMandate`
  then revoked that mandate and nothing moved the subscription. Mollie cancels the
  subscriptions of a revoked mandate (its documentation — measured on 26 November 2026: at once,
  see "What Mollie does with a key, a cancel and a revoke — measured"), or
  cannot charge them. *Rule:* for an accessible Mollie subscription the listener first PATCHes
  its `mandateId` through `MollieSubscriptionPatcher::rebindMandate()` (the one PATCH door; no
  start date, so a charged subscription takes it), and only then queues the revoke.
  *Follow-up (review 3):* "accessible" was the wrong question. Past due on a failed renewal
  (or a trial whose first charge failed) is not accessible, yet Mollie still runs or has
  suspended the subscription — and that is exactly when the customer is told to review their
  payment method. The old mandate was revoked at once. *Rule:* a Mollie subscription with an
  id on record is moved when it is accessible or `MollieSubscriptionGate::hasLiveMollieSubscription()`
  says it is `active`, `pending` or `suspended`. Only an id the gate dropped (Mollie: cancelled,
  completed, 404/410) means "over"; a lookup that fails leaves the id and the PATCH is tried,
  so a Mollie outage keeps the old mandate rather than revoking it.
- **An activation that failed for good left its mandate on file.** `RetrySubscriptionActivationJob::failed()`
  refunded a paid first payment and mailed the operators, but a trial's €0 authorisation kept
  its mandate — and `StartSubscriptionCheckout` / `trialApplies()` grant a trial only without
  one, so the next checkout charged the first period in full. *Rule:* the payment's own mandate
  is cleared and revoked (`RevokeMollieMandateJob`), the same reason `GrantExtrasPurchaseRefund`
  gives.
- **A restricted promotion code was tried once, against nothing.** `adoptAutoApplyCoupon()` ran
  at mount only, with no plan and a subtotal of 0; a code restricted to a plan, an addon or a
  minimum order failed silently and was never tried again, and the service then dropped it
  from the session. *Rule:* tried again on every change of plan or interval and on entering
  the confirm step.

### Judgement calls

- **An open payment for another order is withdrawn, not left beside the new one.** Not every
  method can be cancelled while open; when Mollie refuses the `CancelPaymentRequest`, nothing
  new is created and the customer is told to finish the earlier payment or wait for its
  expiry (`PendingPaymentForAnotherOrderException`). A second payment next to a payable first
  one is the double charge this exists to prevent (a duplicate that gets through anyway is
  refunded automatically since 26 November 2026 — see *Decided by the maintainer* below). Whether Mollie cancels an `open` payment that has not picked a method is not
  measured; if it does not, a changed order waits for the old payment's expiry.
  **Measured on 26 November 2026: it does not** — no open first payment can be cancelled (no
  method, card, eps, €0.00: 422 "The payment cannot be cancelled"), so a changed order always
  waits; see "What Mollie does with a key, a cancel and a revoke — measured".
- **A `pending` payment for another order still goes to the return page.** It has left the
  browser and may settle; the order it carries is booked. Unchanged.
- **A failed rebinding keeps the old mandate.** A second valid mandate costs nothing; a
  subscription on a revoked one costs the customer the subscription. A Mollie subscription
  with an id on record that is accessible, or that Mollie still runs (see the follow-up
  above), is moved; a checkout that recovers an ended subscription revokes the old mandate at
  once, as before (the new subscription is created on the new one).
- **Only a refused €0 activation is let past the checkout guard, not every €0 one.** A trial
  whose `CreateSubscription` failed on a Mollie hiccup is €0 too, but its retry will most
  likely activate it, and a second checkout beside it now finds a mandate — so no trial, and
  a full charge next to the trial about to start (the case under `mayActivate()`). A refused
  marker is recognised by how it was written (`scheduleActivationRetry(…, refused: true)`
  from `reportSkippedActivation()`, and only for a payment that collected nothing), not by
  its reason text. Accepted: when the operator restores the code's cover while the customer
  pays in full, whichever activation comes second meets the gate as a duplicate — booked and
  refunded, see *Decided by the maintainer* below. A marker written before this change carries
  no flag and still counts.
- **The mandate is given back on the refunding path too**, not only for a trial: a refunded
  first payment that leaves its mandate behind denies the next checkout its trial the same way.
  Only the payment's own mandate (`GetPaymentRequest` → `mandateId` equal to the one on file),
  and only while no `mollie_subscription_id` is on record.
- **A promotion code the customer removed is not put back** by a later change of plan
  (`$promotionRemoved`, component state). A reload still re-applies it at mount, as before,
  and `StartSubscriptionCheckout` still falls back to the session code on the trial path —
  both unchanged.

### The checkout goes to the return page when it opens, not only on submit (26 November 2026)

Maintainer's decision 3 of 26 November 2026. With a paid first payment whose activation was
still in flight, `RequireActiveSubscription` sent the customer to the checkout and the
checkout rendered every step; only the final submit asked `PendingFirstPayment` and went to
the return page. The customer walked through plans, addons and the confirm step for an order
that could not be placed. *Rule:* `checkout::mount()` asks
`PendingFirstPayment::activationUnderway()` — the order-independent half of `redirectFor()`,
sharing its `activationInFlight()` check and its probe of the payment on record — and
redirects to the return page when an activation is running (fresh `activation_claim`),
waiting for its retry (`activation_retries`), or the payment on record is `paid` and not yet
booked. The submit keeps its own check for a form opened before the activation started.

- *Red before:* `tests/Feature/Checkout/CheckoutRedirectsWhileActivatingTest.php` — the
  retry-marker, fresh-claim and paid-webhook-pending cases failed against the unfixed
  component (200, no redirect); its five controls passed before and after. Browser:
  `CheckoutDeepTest::test_a_checkout_while_the_paid_first_payment_awaits_activation_…` and
  `…_after_mollie_took_the_money_but_before_the_webhook_…` timed out waiting for
  `/billing/return` with the mount check disabled; `…_form_opened_before_the_activation_started_…`
  pins the submit guard and passes either way.
- *Judgement call — what does not redirect at mount.* A refused €0 authorisation
  (`refused_without_charge`) and an activation that failed for good leave nothing in flight
  (`activationInFlight()` is false, the webhook dropped `pending_first_payment_id`), so the
  customer can check out again. An `open` payment is judged at the submit, against the order
  the form then holds (same order → its link, another → withdrawn), and so is a `pending` one
  (SEPA, bank transfer), which the submit still sends to the return page — the decision named
  an activation in flight, and a `pending` payment can take days; *open question* whether it
  should redirect at mount too. A probe that fails shows the checkout (never locked out by a
  lookup), and a billable the visitor may not act for is not asked about at all.
- *Cost accepted:* a checkout opened with a payment on record now makes one
  `GetPaymentRequest` at mount (outside any transaction), and the submit asks again — the
  answer can change in between. Four existing feature tests that counted the submit's Mollie
  calls now reset the count after the mount; three that set the in-flight state before
  mounting now open the form first, then set it, then submit.

### Decided by the maintainer (26 November 2026)

- **A duplicate first payment is refunded automatically.** This was left open here ("kept
  unbooked … needs a way to tell which payment activated the subscription"). The rule now
  follows flows.md §13: the duplicate is booked and returned through `UnwantedPaymentRefund`
  (reason `duplicate_payment`), both at the gate and in `RetrySubscriptionActivationJob`.
  See [A duplicate first payment is refunded automatically](#a-duplicate-first-payment-is-refunded-automatically-26-november-2026).

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Checkout/PendingFirstPaymentReuseTest` › changed order → cancelled and a new payment | redirected to `tr_old` |
| › changed order, Mollie refuses the cancel → message, nothing created | redirected to `tr_old` |
| › payment on record `paid` → return page | a second payment was created |
| › activation waiting for its retry → return page | a second payment was created |
| › the service refuses while an activation is in flight | no exception, payment created |
| `Portal/UpgradeAndExtrasPaymentReuseTest` › plan page, same upgrade again | redirected to `tr_second` |
| › gift extras, same purchase again | returned `tr_second` |
| `Webhook/PaymentMethodChangeRebindsSubscriptionTest` › subscription moved before the revoke | no `UpdateSubscriptionRequest` |
| › rebinding fails → old mandate kept | revoke dispatched |
| › past-due subscription Mollie still runs (`active`, `suspended`) moved before the revoke | no `UpdateSubscriptionRequest`, revoke at once |
| › past due, Mollie cannot be asked → old mandate kept | revoke dispatched |
| `Checkout/RefusedAuthorisationDoesNotBlockCheckoutTest` › 100% code lost its cover → checkout at full price | redirected to the return page |
| › the service does not refuse a refused authorisation | `ActivationInFlightException` |
| `Webhook/ActivationRetryTest` › mandate given back when a trial failed for good | `hasMollieMandate()` true |
| `Checkout/PromotionCodeAfterPlanChoiceTest` › restricted code applied once its plan is picked | `applied_coupon` null |

Guards that stay green: the same-order reuse, `pending` → return page, "revoked at once
when no subscription runs on it", "revokes at once when the past-due subscription is over at
Mollie", and `RefusedAuthorisationDoesNotBlockCheckoutTest` › still holds the checkout while a
€0 authorisation waits for a retry that can finish it.

## Renewal review round (26 November 2026)

Five findings about renewals and what hangs off them, each reproduced by a feature test that
was red against the unfixed code before anything was changed.

### What was wrong, and the rule now

- **A free-plan downgrade during a pending replacement brought the paid subscription back.**
  A replacement waiting between its cancel and its create (`pending_subscription_replacement`,
  `old_cancelled = true`) survived a downgrade to the free plan: `cancelForFreeDowngrade()` met
  "has been cancelled" and counted it as done, phase 3 made the billable Local and Active, and
  the marker is not one of `SubscriptionMetaHygiene`'s keys. The retry then asked only the
  status — Active — and created the paid subscription again for a Local billable: charged and
  fully refunded every period by `SupersededRenewalRefund`, never cancelled, and counted as
  live by the checkout gate. *Rule:* a Local billable has no Mollie subscription to move.
  `MollieSubscriptionReplacer` abandons the replacement (and cancels one it created meanwhile)
  for a Local billable whatever its status, and `UpdateSubscription`'s free-downgrade write
  drops the marker together with `mollie_subscription_id` once the cancel went through, so the
  gate is free at once. The marker stays out of `SubscriptionMetaHygiene` — an activation is
  not a reason to forget a replacement.
- **A trial recovered from past due never converted.** `SubscriptionPaymentHandler::paid()`
  asked only `status === Trial`. A trial whose first charge failed (`failed()`), or whose
  outcome did not come within the grace days (`ProcessTrialLifecycleJob`), is PastDue; the paid
  charge that recovers it became an ordinary renewal: `trial_ends_at` stayed, no
  `trial_converted_at`, no `TrialConverted`, and the `trial_quota` grant stayed — so the next
  plan change rebalanced a paid full period against the trial's prorated grant
  (`WalletPlanChangeAdjuster`: overage under-billed, rollover over-credited). *Rule:* a PastDue
  billable with `trial_ends_at` set and no `trial_converted_at` is an unconverted trial, and the
  paid charge converts it. *Judgement:* the adjuster keeps reading `trial_quota` without a
  status gate — for an unconverted PastDue trial the grant is still the right baseline.
- **The coupon-expiry reset's failure path wrote back a stale meta.** When the reset PATCH
  failed, `pending_subscription_patch` was added to the meta the handler loaded when the webhook
  started and saved without a lock — undoing every write committed since (a seat change that
  PATCHed Mollie under the lock this reset timed out on lost its `seat_count`, and the final
  compare-and-set then found the starting count and kept it; a cleared overage slot came back).
  *Rule:* locked read-modify-write (`mutateBillingMeta`), the coupon id taken from what this
  renewal priced. `clearPendingTrialCouponSlot()` had the same unlocked pattern and follows the
  same rule. *Not changed:* the reset still takes the `pending_subscription_patch` slot when a
  different patch waits there, as before — which of two waiting patches matters more is not
  settled by this finding. `CouponService::clearActiveRecurringCouponMarker()` (the success
  path) is still an unlocked save of the instance's meta; it has seven callers and was left for
  its own change.
- **A lost create-payment answer for an undercollection balance was read as "refused".**
  `UndercollectionCharge::settle()` recorded any throw as `payment_not_created` and told the
  operators to charge by hand — while Mollie collected the payment it had created. The payment
  was then booked from fallback lines without a period, and the admin tab kept "not possible"
  next to a paid balance. *Rule:* the same as [A lost create-payment answer is marked, never
  guessed](#a-lost-create-payment-answer-is-marked-never-guessed): a throw asks Mollie's payment
  list for the case's payment (metadata `undercollection` = the case key). Found: it is the
  case's payment, recorded `charged` and announced to the customer as usual. Not found:
  `payment_not_created`, as before. The list not readable: `payment_unknown` — the operators
  are told to look at Mollie first, the case keeps its lines and period, and a payment of that
  case that arrives later is adopted by `paid()` / `failed()`. *Judgement:* no retry ladder of
  its own; the operator mail is the fallback for the case Mollie cannot be asked, as it was for
  a refusal. The customer is not told about a payment that may not exist.
- **A renewal collected after the end was kept when the first delivery died behind the
  invoice.** `paid()` writes the invoice, then returns the payment and cancels at Mollie
  (`reportRenewalForEndedSubscription()`). A throwing `InvoiceCreated` listener or a killed
  worker in between, and the re-delivery stopped at the invoice guard: charged, nothing
  returned, the subscription still running at Mollie, nobody told. *Rule:* the decision is
  recorded before the invoice (`SubscriptionPaymentHandler::ENDED_RENEWALS_KEY`, payment id →
  time) and dropped once the refund and the cancel are through; a re-delivery that finds it
  finishes them. Every step is safe to repeat (cancel: "already cancelled" is done; refund:
  skipped once credited or queued; announcement: once per payment). *Judgement:* keyed on the
  first delivery's decision, not on the status at re-delivery — a renewal booked while the
  subscription ran and delivered again after a later cancel must not be given back.
  `RenewalCollectedForEndedSubscription` can be dispatched a second time when the first run
  died after it. *Follow-up (review finding):* the entry follows the decision of the delivery
  that writes the invoice, both ways. A first delivery that judged the renewal ended and died
  *before* the invoice left its entry; when the subscription was resumed before the
  re-delivery, that re-delivery booked the renewal live (invoice, period, quota) and the stale
  entry then made a later call of the same webhook — a partial refund changes the outcome
  signature — refund the rest and cancel at Mollie. `paid()` now drops this payment's entry
  when it takes the live path. *Judgement:* the re-delivery's status decides, not the dead
  first delivery's: nothing of that decision was carried out, and the invoice and period
  are written under the second one. *Not changed:* a *live* renewal whose first delivery dies behind the invoice
  is still not resumed (no period advance, no quota) — that is the general shape of the
  renewal handler, not this finding.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Billing/ChargedSubscriptionReplacementTest` › drops a pending replacement when the customer downgrades to a free plan | `subscriptionsCreated` 2 |
| `Webhook/PastDueTrialRecoveryConvertsTest` | `trial_quota` still there |
| `Webhook/RenewalCouponResetFailureKeepsConcurrentMetaTest` | the concurrent write undone |
| `Subscription/UndercollectionLostAnswerTest` › takes over the payment whose answer was lost | status `not_possible` |
| › unknown when Mollie cannot be asked, adopted when it arrives | obstacle `payment_not_created` |
| `Webhook/EndedSubscriptionRenewalRefundTest` › finishes the refund and the cancel on the re-delivery | no credit note |
| `Webhook/EndedSubscriptionRenewalRefundTest` › never returns a renewal judged ended by a first delivery that died before its invoice (follow-up) | a `CreatePaymentRefundRequest` on the partial-refund call |

Guard that stays green: `EndedSubscriptionRenewalRefundTest` › never returns a renewal booked
while the subscription ran, delivered again after a cancel.

## Plan, seat and addon changes review round (26 November 2026)

Three findings about changes in flight, each reproduced by a feature test that was red
against the unfixed code before anything was changed.

### What was wrong, and the rule now

- **A pre-announced upgrade applied early left Mollie at the target's price when it did not
  go through.** A scheduled Starter → Pro on the same interval is announced to Mollie when it
  is scheduled (`scheduled_change.mollie_prepatched`). "Apply now" mid-period (or a dispatcher
  run ahead of the boundary) replays it as an immediate change, which carries a prorata charge
  and waits for it — the plan is not switched — and `apply()` then cleared `scheduled_change`
  and with it the only record that Mollie held Pro's amount. When the charge was declined
  (`ProrataChargeHandler::failed()`; `revertRecurringAmount()` deliberately leaves plan changes
  alone) or the customer withdrew the waiting change (`tryCancelPendingPlanChange()`), the
  customer stayed on Starter and every renewal was collected at Pro's price, refunded down
  afterwards by `SupersededRenewalRefund` — or not at all if that refund failed. *Rule:* when
  `apply()`'s `update()` comes back `pendingPaymentConfirmation` for a pre-patched change,
  Mollie is put back to what the customer is on now (`restoreRecurringAmount()`, retry queued if
  the PATCH fails). That is exactly where an ordinary immediate upgrade waits from: Phase 2
  PATCHes it forward when the charge clears, and every path that gives the change up has
  nothing to undo. *Judgement:* restore at apply time, not on the failure paths — carrying the
  marker into `pending_prorata_change` would have to be read by `failed()`, the withdraw, the
  stale clean-up and the cancel, and would leave a renewal during a SEPA wait collected at the
  new plan's price for a customer still on the old one. One PATCH more on the success path.
  *Known window:* a Phase 2 that switches the plan *and* PATCHes between `apply()`'s refresh
  and its own PATCH would be overwritten with the old amount; it needs the charge to settle and
  its webhook to complete within milliseconds of its creation. `applyAtRenewal()` is not
  affected — at the boundary there is no prorata and nothing waits.
- **Cancelling the subscription with a seat or addon change in flight kept the seats and the
  addon unpaid.** A seat or addon change writes `seat_count` / `active_addon_codes` at once and
  only its charge waits (flows.md § 9). `CancelSubscription` voids that charge through
  `tryCancelPendingPlanChange()`, which cancelled the payment (or recorded the orphan marker) and
  cleared the pending state but, unlike `withdrawPendingChangeForPayment()`, never called
  `restoreUnpaidChange()`; the `canceled` webhook then found nothing pending, a payment that
  settled anyway was refunded in full by `reportOrphanedCharge()`, and one that had settled
  before the cancel was refunded in full by `reportUnapplicableCharge()` — the seats and the
  addon's features stayed through the grace period, and over a resubscribe. *Rule:* whoever
  takes a pending change off puts back what it wrote. `tryCancelPendingPlanChange()` calls
  `restoreUnpaidChange()` with the stored intent after clearing (locally and, while the
  subscription still runs, at Mollie; a no-op for a plan or interval change, which wrote nothing
  before its charge). `reportUnapplicableCharge()` restores the local half while the
  subscription is `Cancelled` — not when it is `Expired`, where the expiry pass has wiped seats
  and addons and restoring them would hand them back to a subscription that has ended; no
  PATCH, the subscription no longer runs at Mollie.
- **A seat job that met another change's change lock was lost.** `SyncSeatsJob` caught only
  `InvalidSubscriptionStateException`. While a portal plan or addon change,
  `ApplyScheduledChangesJob` or a renewal's `applyAtRenewal()` held
  `billing:subscription-change:{id}` across its Mollie calls, `update()` refused the seat change
  with `SubscriptionChangeInProgressException` and, with `tries = 1`, it became a `failed_jobs`
  row: a member seated, the seat never billed. *Rule:* the same as the seat-lock timeout —
  nothing has happened at that point, so the job queues a copy of itself a minute on, on the
  same `MAX_REQUEUES` budget; past it, or on a sync run (where the delay is ignored and the copy
  would block on the seat lock this run still holds), it fails as before. *Not changed:* the job
  still resolves a delta's target before `update()` takes the change lock, so a portal seat
  change (absolute, without the seat lock) committed in between is overwritten by the target —
  moving the job to `update()`'s closure form changes `SyncSeats`' signature and is left for its
  own change.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Subscription/PrepatchedChangeAppliedEarlyTest` › … charge is declined | Mollie held 60.00 (Pro), expected 24.00 |
| › … upgrade is withdrawn | Mollie held 60.00 (Pro), expected 24.00 |
| `Subscription/CancelWithSeatOrAddonChargeInFlightTest` (3 cases × seat / addon) | `seat_count` 3 / addon still active, all six |
| `Jobs/OperationsOpenJobPathsTest` › puts a seat change back … subscription-change lock | `SubscriptionChangeInProgressException` thrown, nothing queued |

Guards that stay green: `PrepatchedChangeAppliedEarlyTest` › still moves Mollie to the new plan
when the charge is paid; `OperationsOpenJobPathsTest` › gives up on a subscription-change lock
that never frees, loudly.

### "Apply now" announced a waiting upgrade as changed (26 November 2026)

Found by the browser suite (`tests/Browser/ChangesInFlightTest.php`, gaps 15 and 16 of the
second browser review). `applyScheduledNow()` decided its toast only by whether
`scheduled_change` was gone after `apply()`. Mid-period a scheduled upgrade carries a prorata
charge and becomes a *waiting* change — `scheduled_change` goes, `pending_plan_change` comes —
so the customer read "Plan changed." beside the notice "An upgrade to Business is awaiting
payment confirmation", on a page whose header still said Pro; when the charge was then
declined, the same page said the payment had failed. *Rule:* a change that waits for its
payment is not announced by the action that started it. `applyScheduledNow()` returns without
a toast when `pending_plan_change` is set after `apply()`, exactly as `applyChange()` does on
`pendingPaymentConfirmation`; the render that sees the change resolve announces "changed" or
"failed" (`wasPending`). *Judgement:* no "being updated" toast either — the waiting notice with
its cancel button is already on screen, and the plan screen's own immediate upgrade has never
shown one.

| Test | Red before |
|---|---|
| `Portal/ApplyScheduledNowAwaitingPaymentTest` › does not announce an upgrade applied now as changed while it waits for its payment | toasts contained "Plan changed." |
| `Browser/ChangesInFlightTest` › scheduled upgrade applied now and withdrawn / declined (both) | "Plan changed." among the page's toasts after "Apply now" |

Guard that stays green: `ApplyScheduledNowAwaitingPaymentTest` › still announces a scheduled
change applied now that needs no payment as changed. The toast is Flux's and the feature
suite runs without it, so the feature test puts a recorder in `\Flux`'s place (the two calls
`InteractsWithFluxUi` makes); booting Flux's real provider there failed eighteen later Portal
tests in the same process, its facade alias outliving the application it was bound in.

## Coupons and gifts review round (26 November 2026)

Three findings about a code or a gift whose circumstances changed after it was given, each
reproduced by a feature test that was red against the unfixed code before anything was
changed.

### What was wrong, and the rule now

- **Revoking a gift that had run out took away what the tenant held afterwards.** Nothing
  marks a full-grant redemption when the nightly pass expires its subscription: it stays
  un-revoked and listed in the admin subscription tab with a revoke button.
  `revokeFullGrant()` subtracted `grant_days_added` from whatever `subscription_ends_at`
  the billable had *now*. After the tenant took the free plan (`ends_at` null) the wipe
  branch ran and set the free plan to Expired; after a second gift the first gift's 30
  days came off the second, or wiped it. *Rule:* the grants of a subscription end with it.
  `PrepareUsageOverageJob` pass 1 (in one transaction with the expiry) and the wipe branch
  of `revokeFullGrant()` call `CouponService::endGrantsWithSubscription()`, which stamps
  `grant_applied_snapshot.subscription_ended_at` on the billable's live `full`,
  `addon_only` and `plan_waiver` redemptions. `revokeGrant()` revokes a stamped redemption
  as a record only (row revoked, slot freed, one-off code switched off, `GrantRevoked`) and
  touches nothing on the billable. *Not covered:* a gift that ran out before this change
  carries no stamp, and revoking it still acts on the billable as before. *Not changed:*
  a grant redeemed for zero days (a gift without an end date) still revokes to Expired
  through the null-end branch — that subscription *is* the gift.
- **Revoking finished free plan periods removed a later, running waiver.**
  `revokePlanWaiverPeriods()` called `PlanWaiver::remove()` on a Mollie subscription without
  asking whose waiver was in the slot. PLANFREE-A (one period) ran out, its marker was
  cleared, PLANFREE-B (three periods) wrote a marker of its own; revoking A removed B's and
  re-priced Mollie to the full plan, B's redemption still listed as active. The same for a
  gift's waiver. *Rule:* the waiver is removed only when this redemption's periods went
  into it — it started the marker, or was appended to it: `applied_at` not before the
  marker's `first_applied_at` (less the minute `isMarkerStillRedeemable()` already allows
  for a row written a moment before its marker). Otherwise the revoke is a record only.
  Unchanged and still pinned: revoking appended periods removes the whole waiver. A marker
  without `first_applied_at` (older release) is taken to be the redemption's, as before.
  *Follow-up (review 3):* periods given on a **Local gift** are added to its end date, and
  `carryGrantOver()` makes that extended end the waiver's when the tenant buys extras and
  keeps the gift. Such a redemption predates the waiver's `first_applied_at`, so the rule
  above called the waiver "given later" and the revoke was a record only — the tenant kept
  every revoked period. It is the same free time, not a later waiver. *Rule:* a `plan_waiver`
  redemption whose snapshot says `source = local`, with `grant_days_added`, against a gift
  waiver (`isGift()`, a `redemption_id`) it predates, has its days taken off that waiver's
  end (`PlanWaiver::shortenByDays()`, never before today) — what the Local branch does to
  `subscription_ends_at`. *Judgement:* the gift stays and keeps its own rule for its end. When
  the shortened gift no longer reaches the next charge, the revoke takes the step
  `finishGrantCarryOver()` takes for a gift that ends before the first renewal: `end` →
  `CancelSubscription` (`grant_expired`) with the covered period; `continue_paid` → marker
  removed and Mollie re-priced to the full amount. Both after the revoke's transaction
  commits. Not the whole waiver removed, as for appended periods: that would also take the
  gift's own days, which nobody revoked. A shortened gift that still reaches the next
  charge changes nothing at Mollie; its renewal handles its end.
- **A code changed while the first payment travelled was dropped at the webhook.**
  `FirstPaymentCouponPricing::price()` ran the full `validate()` again, so a campaign that
  filled up, a code switched off, or a `valid_until` that passed between checkout and
  `paid` made it return null: the invoice booked the full plan plus a payment adjustment,
  `PaymentAmountMismatch` fired, no redemption and no marker were written, and the Mollie
  subscription was created at the full price — although the customer had paid the
  discounted amount and been promised the discounted periods. The documented compensation
  (`compensateFailedRedemption()`, "the campaign filled up while the payment travelled")
  was reachable only in the milliseconds between `price()` and `redeem()`. *Rule:* the
  checkout is the gate for the campaign's own state. The paid first-payment paths
  (`FirstPaymentHandler`, `LocalToMollieUpgradeHandler`) price through
  `FirstPaymentCouponPricing::afterPayment()`, which passes `paid_with_code` to `validate()`:
  `active`, `valid_from`, `valid_until` and `max_redemptions` are not asked again; plan,
  interval, addons, the per-billable limit and the recurring slot still are. A full
  campaign then meets `redeem()`'s lock and is compensated (marker written,
  `CouponRedemptionRecordMissing`). *Judgement:* a code switched off in flight honours the
  payment made with it but creates the Mollie subscription without the recurring discount
  — switching a code off is how an operator ends a discount at the next renewal
  (`isMarkerStillRedeemable()` reads `active`), and a subscription holding a discount the
  renewal will not honour books an adjustment every period. An expired code is capped by
  its own date, as at the checkout. *Judgement:* `afterPayment()` is a copy with a flag,
  not a new parameter of `price()` — apps rebind this service, and a subclass overriding
  `price()` with today's signature must stay compatible. *Not changed:* the €0 paths
  (mandate-only full coverage, the trial signup, the pending trial coupon at the trial's
  end) still validate in full — no money moved there, and the full-coverage skip is
  reported as documented under `MandateOnlyPaymentHandler`. Whether a trial customer keeps
  a code switched off during the trial is a product decision left open.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Coupons/GrantRevocationTest` › leaves the free plan a tenant took after the gift ran out … | plan wiped to Expired, source none |
| › takes nothing off a later gift … | the second gift's end moved 30 days earlier |
| `Coupons/PlanWaiverLifecycleAuditTest` › leaves a later waiver alone … | B's marker removed, Mollie PATCHed to full price |
| `Webhook/FirstPaymentCodeChangedInFlightTest` (campaign full / switched off / expired) | no coupon line, adjustment line, all three |
| `Coupons/PlanWaiverCarriedIntoGiftRevokeTest` › takes revoked free periods off a gift they were carried into (`continue_paid`) | marker unchanged, no PATCH |
| › ends the subscription with the shortened gift when the gift was given to end | marker unchanged, still Active |
| › only shortens a carried gift that still reaches the next charge | `valid_until` unchanged |

Guards that stay green: `GrantRevocationTest` › still takes its days off a running
subscription that a second gift extended; `PlanWaiverLifecycleAuditTest` › still removes
the whole waiver when appended free periods are revoked; `PlanWaiverCarriedIntoGiftRevokeTest`
› leaves a waiver alone whose free periods were given on Mollie after a carried gift ended.

## Portal and admin at their edges — browser review (26 November 2026)

Gaps 48–55 of the second browser review, driven in Chrome (`Browser/PortalAdminEdgeTest`,
`Browser/PortalAdminLocaleTenantTest`). One defect, the rest pinned as they are.

### What was wrong, and the rule now

- **The refund dialog described a yen amount as "up to two decimals".** On a JPY invoice
  the dialog's description, its hint and the refusal all said "with up to two decimals",
  and the example in the hint and the field's placeholder were formatted for the
  *configured* currency — "10.50" on a shop configured in EUR, which the parser then
  refused on the yen invoice with a message repeating the wrong rule. The amount itself was
  always read at the currency's scale (¥500 is refunded as ¥500 net, ¥595 at Mollie — the
  November fix holds). *Rule:* the dialog's wording is the invoice currency's:
  `invoices-tab::amountRuleText()` picks the key for two decimals, `<key>_whole` for a
  currency without minor units, `<key>_digits` (`:digits`) for any other scale (KWD); the
  example and placeholder are formatted in the invoice's currency. *Judgement:* the existing
  keys keep their exact wording and placeholders for two-decimal currencies, so an app that
  overrode them keeps its text; only the new variants need translating.

### Deliberate — pinned, not changed

- **A booking refused by the country gate from an open tab lands on the dashboard without
  the gate's flash.** The gate is Livewire persistent middleware, so the update answers with
  its redirect; `fetch` follows it, the dashboard GET consumes the `billing_status` flash,
  and Livewire then navigates to the same URL again. What the customer sees is the mismatch
  banner, whose text says that plans, add-ons and seats cannot be bought while the
  correction is pending. Opening the page fresh shows the flash. Nothing is booked either
  way.
- **An operator's cancel on a row that expired meanwhile is refused in the prompt** with
  `CancelSubscription`'s own message ("There is no subscription to cancel.") — English, as
  the exception is written; no audit entry, the row stays Expired.
- **A crafted `purchase()` on a free Local plan or an access grant** (also with a 100 % code
  set on the component, the inline-complete path) is refused by
  `StartOneTimeOrderCheckout` before any coupon or Mollie call and answered with the generic
  error toast: the page itself offers no purchase action, so only a crafted call gets there.
- **The return page shows nothing of another organization**, by tenant prefix or by
  `?organization=`: no polling, and Mollie is never asked about the other organization's
  pending first payment (counted by the fake per payment). A signed-in member opening it
  with another organization's id sees their own state.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Feature/Admin/InvoicesTabRefundDecimalsHintTest` › whole number on a JPY invoice (configured EUR / JPY) | "two decimals" shown, `description_whole` missing |
| › refuses a decimal yen amount with the whole-number rule | the two-decimals refusal |
| › names three decimals on a KWD invoice | "two decimals" shown |

Guards green from the start: the EUR wording in English and German
(`InvoicesTabRefundDecimalsHintTest`). `Browser/PortalAdminEdgeTest` › a refund of 500 yen
asserts the wording in Chrome as well; it was written after the fix was found and run
green against the fixed component only.

## Admin billable header follows the tab's actions (26 November 2026)

Decision 10 of the maintainer's list: the status badge in the admin billable page's header
must update after a status change made in a tab.

### What was wrong, and the rule now

- **After a resume the header kept saying "Cancelled" until a reload** (and "Active" after a
  cancel). The page `admin.billables.show` renders the header — name, status badge, plan
  card, mandate card — and mounts each tab as a keyed child component; a tab action
  re-rendered only the tab. *Rule:* every action of the subscription tab that reached a
  service (extend trial, extend by days, free plan periods, extend gift, cancel, resume,
  revoke grant) dispatches the Livewire event `billing-admin-billable-changed` (the tab's
  `CHANGED_EVENT`) from a `finally`, and the page listens with `#[On]`, re-reads the billable
  and re-renders its header. The keyed tabs are not re-rendered by it, so the tab's flash
  stays.
- *Judgement:* a **refusal from the service is announced too**, not only a success. A
  refusal there usually means the subscription moved under the open page (another operator,
  the expiry job, a webhook) — "no longer cancelled" on a resume — and the header is exactly
  as stale as the tab's buttons were. A refusal of the tab's own input (no reason, no date,
  a non-number) reaches no service and is not announced.
- *Not changed:* the invoices and wallet tabs do not announce. A refund and a wallet credit
  change neither the status, the plan nor the mandate (`RefundInvoiceService` and
  `WalletUsageService` never write `subscription_status`), so the header has nothing to show.
  A future tab action that does must dispatch the same event.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Feature/Admin/BillableHeaderRefreshTest` › announces a cancel (now / at period end), a resume, a resume refused on a stale page, a trial extension, extend by days, free plan periods, a revoked grant | event never dispatched (8 cases) |
| › the page listens for the announcement and re-reads the billable | `SupportEvents::getListenerEventNames()` of the page lacks the event |
| `Browser/AdminHeaderRefreshTest` › resuming a cancelled subscription turns the header badge active without a reload | badge stayed `cancelled` (10 s wait timed out) |
| › cancelling turns the header badge cancelled without a reload | badge stayed `active` |

Guards green from the start: a cancel without a reason and an extension with `0` days are
not announced. The browser test reads the badge through a new `data-billable-status` hook on
the header, added before the red run; it also proves the page was not reloaded (a marker on
`window` survives).

## A duplicate first payment is refunded automatically (26 November 2026)

Maintainer decision 1 of 26 November 2026, replacing the open point "A duplicate first
payment that reaches the gate is still kept unbooked" (section of 25 November above).

### What was wrong, and the rule now

- **A second first payment that met the gate was kept without a document.**
  `FirstPaymentHandler` and `LocalToMollieUpgradeHandler` (which also serves the purchase of
  extras on a gift) answered `ActivationGate::Duplicate` with `DuplicatePaymentReceived` only:
  money collected, no invoice, no credit note, no refund. *Rule:* flows.md §13 — every payment
  becomes an invoice, a payment nobody wants becomes that invoice's credit note.
  `WebhookSupport::settleDuplicatePayment()` books it and returns it in full through
  `UnwantedPaymentRefund` (reason `duplicate_payment`, customer mail with its own sentence),
  then dispatches `DuplicatePaymentReceived` — which apps still get. Outside the gate's
  transaction, since the refund is a Mollie call. A re-delivery meets the invoice guard and
  `UnwantedPaymentRefund::resume()` (posts a refund that is not through and not queued,
  announces nothing twice); the refund Mollie then reports is deduplicated by
  `RefundHandler` on the refund id the credit note carries.
- **The retry job could not tell its own activation from somebody else's.**
  `RetrySubscriptionActivationJob` read `activationCompleted()` billable-wide: when payment B
  activated while payment A waited for its retry, A's marker was cleared and A's booked
  invoice was never refunded. *Rule:* `CreateSubscription` records which payment activated
  the subscription (`subscription_meta.activated_by_payment_id`,
  `CreateSubscription::ACTIVATED_BY_META_KEY`) in the **same save** that writes
  `mollie_subscription_id` — the field `activationCompleted()` reads — so the two can never
  disagree, also not after a crash. The four webhook activation paths pass the payment id
  (`activating_payment_id`). `WebhookSupport::activatedByAnotherPayment()` answers the job's
  question; when it is true, the job settles A as a duplicate (if A collected money) before
  it clears the marker, in `handle()` and in `failed()` alike, and no operator is mailed —
  nothing is left for a person to do.
- **A resumed activation could run over the subscription another payment had just
  activated.** The handlers' resume branch checked `activationCompleted()` without the row
  lock and then claimed. B activating in between made A's replay adopt B's Mollie
  subscription (same plan and interval) and re-run the activation for A — the record then
  named A, and B's payment was the one nobody would ever refund. *Rule:*
  `WebhookSupport::claimForResume()` re-checks under the row lock and returns false — the
  handler stands down; the job then finds B on record and refunds A.

### Judgement calls

- **No record, no refund.** A subscription activated before this change has no
  `activated_by_payment_id`; it cannot be told apart from "this payment activated it", and
  refunding the payment that bought the running subscription is the worse mistake. Such a
  retry clears its marker as before. A subscription created without a payment
  (`ResubscribeSubscription`) records `null` — "not this payment" — so a payment waiting for
  its retry beside it is refunded.
- **The event after the booking, wrapped.** `DuplicatePaymentReceived` used to be the whole
  reaction and was dispatched bare. It now follows the refund and goes through
  `WebhookSupport::safely()`: a listener that throws must not cost the customer the refund,
  and a re-delivery would not dispatch it again anyway (the invoice guard answers first).
  The retry-job case dispatches it too — it is the same fact, found later.
- **An app that refunded on `DuplicatePaymentReceived` itself must stop** — the package
  now does it. Noted in the CHANGELOG.
- **A €0 mandate-only duplicate is unchanged**: `MandateOnlyPaymentHandler` still only
  dispatches the event — there is nothing to return. Its activations write the record too.
- **The duplicate's mandate is not revoked.** At the gate the duplicate never adopts its
  mandate; in the retry case A's mandate was replaced on the billable by B's. A second valid
  mandate at Mollie costs nothing (the same reasoning as "A failed rebinding keeps the old
  mandate" above).

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Webhook/DuplicateFirstPaymentRefundTest` › second first payment at the gate → invoice, credit note, one refund; re-deliveries add nothing | no invoice for `tr_lr_second` (`ModelNotFoundException`) |
| › retry for A after B activated → A refunded (dataset `retry`, `retries spent`) | no credit note (`ModelNotFoundException`), both datasets |
| › the retry for the payment that did activate keeps it | `activated_by_payment_id` null (no record) |
| › no record (subscription from before) → nothing refunded | green before — pins the conservative choice |
| › a resumed activation stands down when B activated between check and claim | record named `tr_lr_first`: A's replay adopted B's subscription |
| `Webhook/LocalUpgradeGateAndRoundingTest` › second upgrade payment booked and refunded | was "reported, not booked" (1 invoice) — updated |
| `Checkout/BookingMatrixGrantExtrasTest` › gift extras and free → paid duplicates booked and refunded | were "1 invoice" — updated |
| `Browser/RefundDeepTest` › a second first payment for a running subscription is booked and returned in full (invoices page shows payment and credit note) | no invoice for the payment (`ModelNotFoundException`), run with the old duplicate branch |

## The overage final invoice of an immediate cancellation states the real end (26 November 2026)

Maintainer decision 4 of 26 November 2026.

### What was wrong, and the rule now

- **The overage charged by an immediate cancellation was invoiced up to a billing date that
  never came.** `CancelSubscription` (immediately — also the past-due auto-cancel and the
  dashboard's "end trial") writes `subscription_ends_at = now` and then runs
  `ChargeUsageOverageDirectly::handle()`. `persistPendingCharge()` stamped the new lines and
  the snapshot with `nextBillingDate()` as the period end, and `SingleChargeHandler` books the
  invoice and every line from the snapshot: period 1–31 March, cancelled on 12 March, the
  invoice (header, lines, PDF "Leistungszeitraum") said 1–31 March. *Rule:* the end of the
  current usage period is `ChargeUsageOverageDirectly::currentUsagePeriodEnd()` — the next
  billing date, unless the subscription is cancelled or expired **and its end has already
  passed** and lies before that date; then it is 00:00 UTC of the day after the end.
- *Judgement — why the day after, not the end instant.* Period ends are exclusive bounds and
  the invoice prints the day before the bound, whatever the hour ("Service periods are UTC
  billing days" above). Stored as the end instant (12 March 10:00), the PDF would print
  1–11 March, dropping the day the service was still used on. 00:00 UTC of 13 March prints
  1–12 March, the same convention as every other period boundary. Never later than the next
  billing date (cancelled on the period's last day keeps the boundary), and an end that lies
  before the period began leaves the boundary alone.
- *Judgement — only an end that has passed.* A graceful cancel is charged by the last sweep
  the day before its end (`PrepareUsageOverageJob` case B), with the end still ahead and equal
  to the next billing date: unchanged. Normal renewal overage: unchanged. A line that already
  carries a period (a retry, a standing debt collected with the final charge) keeps its own,
  as before. A charge created *before* the cancellation (the sweep the night before, still in
  flight) keeps the period it was stamped with.
- *Not changed:* the `UsageDeepTest` trial-ending scenario used to leave "where it ends" open;
  it now asserts the cancellation's day, which there is the boundary's own day.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Webhook/ImmediateCancelOveragePeriodTest` › invoices the overage of an immediate cancellation up to the day it ended (snapshot, invoice, line `period_end`, PDF "Leistungszeitraum: 01.03.2026 – 12.03.2026") | snapshot `period_end` `2026-04-01 00:00:00`, expected `2026-03-13 00:00:00` |
| › an immediate cancellation on the period's first day is that one day ("Leistungsdatum: 01.03.2026") | `period_end` `2026-04-01`, expected `2026-03-02` |
| › on the period's last day keeps the boundary; graceful cancel keeps the full period; renewal overage keeps the full period | green before — pins the unchanged cases |
| `Browser/ImmediateCancelOverageInvoiceTest` › operator cancels now on 20 February, the overage invoice ends 21 February 00:00 and the admin invoice detail shows `2027-02-11 – 2027-02-21` | `period_end` `2027-03-11 09:00:00` (run with the old `nextBillingDate()` stamp) |

## No paid addon or extra seat in the grace period of a cancellation (26 November 2026)

Maintainer decision 5 of 26 November 2026: booking during the grace period (cancelled at the
period end, access until `subscription_ends_at`) is blocked in the service and on the
screens, with a hint; switching an addon off stays possible.

### What was wrong, and the rule now

- **The service already refused; the screens offered the booking anyway.** Since c3ed432
  `ValidateSubscriptionChange::assertSubscriptionStillRuns()` throws
  `SubscriptionEndedChangeNotAllowedException` for an added seat or paid addon on a
  cancelled subscription — `EnableAddon`'s closure path and `SyncSeats` both go through it,
  nothing reached Mollie. The task text assumed `EnableAddon` still charged a prorata; that
  was not reproducible (`ChangeMatrixRemainingCellsTest` › "refuses what costs money on a
  cancelled subscription" was already green). What was wrong: the addons screen still
  showed "Book" with a confirm dialog and a due-now amount, the seats stepper still counted
  up, and only the click answered — with a sentence that neither named the end date nor
  matched the dashboard's way back.
- *Rule:* in the grace period (`SubscriptionEndingBookingNotAllowedException::appliesTo()`:
  status Cancelled, end still ahead) the refusal for an added paid addon or seat is the new
  `SubscriptionEndingBookingNotAllowedException` — "Dein Abo endet am :date. Nimm die
  Kündigung zurück, um Zusatzoptionen zu buchen." / "… um weitere Plätze zu buchen."
  (`portal.error_subscription_ending_addons` / `_seats`). The addons screen shows that hint
  with a "Kündigung zurücknehmen" button to the dashboard (`BillingRoute::name('index')`,
  where "Abo reaktivieren" withdraws it), renders paid addons' book button disabled and
  drops their confirm dialog. The seats screen shows the seats hint and stops the stepper
  (button and `increment()`) at the booked count; fewer seats stay possible.

### Judgement calls

- **A subclass, not a sibling.** The task asked for a dedicated exception; it extends
  `SubscriptionEndedChangeNotAllowedException` instead of standing beside it, so every
  existing `catch` (addons, seats, plan-change screens, `StartGrantExtrasPurchase` callers,
  `PreviewService`'s `subscription_ended` error) handles it unchanged and only the sentence
  is more precise. A sibling class would have had to be added to all of them, and one
  missed `catch` turns the hint back into "something went wrong". Its message is English
  for the log like its parent; screens read the key through `ExceptionMessage`.
- **Seats are blocked the same way** — the same reasoning (a pro-rata charge for a period
  that ends) and the service already refused them. Only the sentence and the stepper are new.
- **A plan or interval change keeps the general sentence** (`error_subscription_ended`): it
  is not an add-on booking, and an *expired* subscription has no cancellation to withdraw,
  so neither gets the dated hint.
- **An addon that bills nothing stays bookable** in the grace period, as the service already
  allowed (`addonIsFree()`): it charges nothing for the ending period.
- **A Local (free) subscription is left to its own sentence** (`free_plan_no_paid_extras`);
  the grace hint is shown for Mollie subscriptions only, so the page never shows both.
- **Admin paths are exempt — there is nothing to exempt.** The admin panel has no addon or
  seat booking; it grants addons through coupons (`addon_only` grants, `CouponService`),
  which write `active_addon_codes` without charging. A gift charges nothing for the ending
  period, so the reason for the block does not apply. An operator who wants to sell the
  customer more withdraws the cancellation (the admin resubscribe) first, like the customer.

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Feature/Portal/BookingDuringCancellationGraceTest` › refuses a paid addon in the grace period with the end date and the way back (en, de) | `SubscriptionEndingBookingNotAllowedException` did not exist; the refusal said `error_subscription_ended` |
| › refuses an extra seat in the grace period the same way (en, de) | same |
| › the addons screen offers no paid booking in the grace period, says until when and links … (en, de) | hint missing — run with the view's `graceEndsOn` forced to null: "To contain: Your subscription ends on 01. Feb 2026. …" |
| › the seats screen does not count above the booked seats and says why (en, de); › a seat count typed past the booked seats is refused with the hint | hint missing / flash said `error_subscription_ended` |
| › keeps the general refusal for a plan change; › still lets the grace period switch an addon off and book a free one; › keeps offering paid addons to a running subscription; › a stale page click books nothing | pin the unchanged cases (the first run: 10 red, 3 green of 13; none of these asserts new behaviour) |
| `Browser/BookingDuringCancellationGraceTest` › addons screen (hint, disabled button, no dialog, link → dashboard "Resubscribe"); › switching off still works; › seats stepper stops at the booked count | `NoSuchElementException` for `[dusk="addons-grace-blocked"]` / `[dusk="seats-grace-blocked"]`, run with `graceEndsOn` forced to null |
| `Feature/Subscription/ChangeMatrixRemainingCellsTest` › refuses what costs money on a cancelled subscription | expectations updated: seat and paid addon now carry the dated sentences |
| `Browser/Matrix/ChangeMatrixTest` › cancelled cells | `knownReasons()` gained the two dated sentences (the part after the date) |

## The "Next billing" tile of a cancelled subscription names no charge (26 November 2026)

Maintainer decision 9 of 26 November 2026: the dashboard tile "Nächste Abrechnung" of a
cancelled subscription says "Abo endet am :date" without an amount, with a link to withdraw
the cancellation.

### What was wrong, and the rule now

- **The tile named the period end as a billing date.** For every status except a Local
  subscription and an access grant, the tile rendered `$b->nextBillingDate()` — for a
  cancelled subscription in its grace period that is the day it *ends*, presented under
  "Next billing" as a charge that will never come. (The "Next charge" block above it already
  said "No further charge – the subscription ends on :date" via `NextChargeSummary`'s
  `STATE_ENDING`; the tile contradicted it.)
- *Rule:* for status Cancelled the tile keeps its heading and shows, instead of a date,
  "Subscription ends on :date" / "Abo endet am :date"
  (`portal.next_billing_subscription_ends_on`) while the end is ahead, with a
  "Withdraw cancellation" / "Kündigung zurücknehmen" button (`portal.grace_withdraw_cancellation`,
  the same words as the addons and seats screens) that calls the dashboard's own
  `resubscribe` action. A cancelled subscription whose end has passed (not yet expired by
  the nightly job) says "Subscription ended on :date" / "Abo endete am :date"
  (`portal.next_billing_subscription_ended_on`) and offers nothing to withdraw. No amount is
  shown in either. Active, trial, past due, Local free plans and access grants are unchanged.

### Judgement calls

- **"Possibly a scheduled cancel" does not exist as a state.** `CancelSubscription::handle()`
  always moves the row to `Cancelled` at once — graceful or immediate only decides
  `subscription_ends_at`. There is no Active-with-pending-cancel status, so `isCancelled` is
  the one condition. A plan waiver whose subscription ends with the free period (`on_expiry`
  = `end`) still charges paid extras on the next date, so its tile keeps the date; the
  waiver notice above says when it ends. An endTrial cancellation has `subscription_ends_at`
  = now, so it shows "ended on" without a withdraw button, matching the header (no
  "Resubscribe" there either).
- **The tile's button is a second door to the same action, not a new one.** The header keeps
  its "Resubscribe" button; the tile's is the one next to the sentence that raises the
  question. Both call `resubscribe()` with its authorisation and refusals — a Local cancelled
  subscription gets the button too, exactly as it already got the header button.
- **The heading stays "Next billing".** Only the value changes, so the tile grid keeps its
  shape and the answer to "next billing?" is "none: it ends on …".

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Feature/Portal/DashboardNextBillingTileTest` › says when a cancelled subscription in its grace period ends, with no billing date, and offers to withdraw the cancellation; › says the same in German; › withdraws the cancellation through the tile's action; › says when a cancelled subscription ended, without a billing date or a withdraw action | 4 red of 7 on the unfixed view: "To contain: Subscription ends on 20. Mar 2026" / "Abo endet am …" / `dusk="next-billing-withdraw-cancellation"` / "Subscription ended on 05. Mar 2026" |
| › keeps the billing date for an active, a trial and a past-due subscription | pin the unchanged cases (green before and after) |
| `Browser/PortalDashboardTest` › the next billing tile of a cancelled subscription says when it ends and withdraws the cancellation | run with the view's cancelled branch forced off: "Waited 10s for [[dusk="next-billing-ends"]] to appear in the DOM." |

## The invoice PDF link is always shown; a missing document is rendered on the click (26 November 2026)

Decision 13 of the maintainer's list.

### What was wrong, and the rule now

- **The link was hidden while the document was not on the disk.** The portal dashboard
  (`'pdfUrl' => $inv->hasPdf() ? … : null`), the portal invoice list (`@if
  ($invoice->hasPdf())`), the admin billable's invoices tab and the admin refund detail
  (credit note and original invoice) all gated the link on `hasPdf()`. A credit note or
  one-time-order invoice is rendered *after* its booking commits (`deferDocument`), and a
  failed render leaves no pointer at all — in both cases the customer saw the charge and no
  way to the document, although `getDownloadUrl()` and `InvoiceDownloadController` were
  already built to render a missing document on request (row above: "`getDownloadUrl()` is
  not gated on `hasPdf()`"). *Rule:* every invoice row carries the link. Every row is
  persisted with its serial (`persistWithSerial()`), so every row is one that will have a
  document; there is no "will never have a PDF" case to keep hiding. The admin's all-invoices
  list already offered it ungated.
- **The deferred render and the click now share one lock.** `ensureStoredPdf()` (the click)
  serialised clicks per invoice with `billing:invoice-pdf-regenerate:{id}`; the deferred
  render after a commit (`issueInvoiceDocument()`, `storeInvoiceDocument()`,
  `issueCreditNoteDocument()`) took no lock. With the link visible from the moment the row
  exists, a click can land between the commit and that render: both wrote the same
  deterministic path, and the later write truncated the file the click was streaming.
  *Rule:* those three go through `InvoiceService::renderDeferredDocument()`, which takes the
  same lock and keeps a document that a click stored while it waited (the caller's model is
  told the stored disk and path; nothing is rendered twice).

### Judgement calls

- **No serial number is ever drawn by a render.** `generateAndStorePdf()` only reads
  `serial_number`; the serial is drawn once, by `persistWithSerial()`, when the row is
  booked. So the click can never cost a serial, with or without the lock — the lock is
  about a readable file, not about the books.
- **A lock the deferred render cannot get in 15 s does not skip the render.** It renders
  anyway, as before the change: an invoice without its document is the worse outcome than
  two identical renders overlapping. The click's own path is unchanged (`ensureStoredPdf()`
  logs and 404s when it cannot produce the document).
- **The non-deferred render inside `createInvoice()` goes through the same door**
  (`issueInvoiceDocument()`), so it takes the lock too. Nobody can contend for a row that is
  not committed yet, so that costs one uncontended lock and one `exists()` on the disk.
- **Authorisation of the download routes is unchanged** — a foreign tenant is refused before
  anything is rendered (pinned below).

### Tests: red before, green after

| Test | Red before |
|---|---|
| `Feature/Invoice/InvoicePdfOnDemandTest` › portal invoice list / portal dashboard: offers the PDF link for an invoice whose document is not rendered yet | red on the gated views: "To contain: invoices/1/download" |
| › admin billable invoices tab: offers the download link …; › admin refund detail: offers the credit note and the original invoice link … | red on the gated views (asserted on the gated Flux buttons, which the feature suite renders verbatim) |
| › deferred credit-note document: renders under the same per-invoice lock a download click takes | red before: lock not held during the render (`[false]` instead of `[true]`) |
| › deferred invoice document: does not render again what a download click already rendered | red before: "Failed asserting that 2 is identical to 1" (rendered twice) |
| › download: renders and stores a missing document on the click, keeping the serial number; › an admin click on the admin route renders it too; › a second click serves the stored document instead of rendering again; › authorization is unchanged | pin the existing controller behaviour (green before and after) |
| `Browser/Matrix/InvoicesPortalMatrixTest` › the PDF link of an invoice without a document renders it on the click | run against the gated dashboard and invoice views: `TimeoutException` waiting for the link |

## A refusal moves the idempotency key on; nothing else does (26 November 2026)

Measured the same day ("What Mollie does with a key, a cancel and a revoke — measured"): a
request Mollie refuses with a 4xx binds its idempotency key to that refusal for an hour. The
stable keys the money paths use — there so that a retry after a *lost answer* is replayed
instead of paying twice — therefore also made a retry after a *refusal* fail for an hour after
its reason had gone. Every automatic retry ladder below runs inside that hour.

### The rule (maintainer's decision)

After a **definite refusal** the next attempt of the operation sends a new key: the stable key
plus `:refused-<n>`, n the refusals counted so far (`Support\MollieRefusal::key()`). After a
timeout, a 5xx or an answer that never arrived the key stays the same — Mollie may have acted,
and only that key lets it say so. The count is bound to the stable key it was counted for
(`['key' => …, 'refusals' => n, 'at' => …]`), so a moved amount or a new payment of the cycle
starts from none; and a key nothing ever refused is byte-identical to before.

"Definite" (`MollieRefusal::isDefinite()`): an `ApiException` with a 4xx status, except
- **408** — the SDK's request timeout, not an answer about the request;
- **409** — a conflict: Mollie may be processing the same key, or something already exists
  (the code comments speak of a "duplicate refund" 409); a new key would make it a second one;
- a refusal of **the key itself** ("used before with different parameters / on a different
  URL") — something already happened under that key.
429 counts as definite: rate-limited, nothing was done. Not measured: whether a 5xx binds a key;
it is treated as unknown either way, which is the safe side.

### Call sites

| Key | Retried by | Done |
|---|---|---|
| `invoice-refund:…` (`RefundInvoiceService`) | the operator, from the panel | count in `subscription_meta.refund_key_refusals.{invoice id}`, written with `MutatesBillingMeta` after Phase 2 threw (no transaction is open there), dropped when the refund books, records older than 24 h pruned on write. An invoice with no billable keeps the old key — refused again inside the hour, never paid twice |
| `refund:…:{refund_token}` (`InvoiceService::createRefund()`: plan-change lines, `UnwantedPaymentRefund`, `GrantExtrasPurchaseRefund`, `ProrataChargeHandler`) | `RetryRefundLineJob` (1, 5, 30 min …), the hourly sweep | count on the line (`ProrataLine::$refundKeyRefusals`, serialised as `refund_key_refusals` only when set). The job re-reads it from the stored `pending_refund_retries` entry: the queue re-runs a job with its original payload, so the ladder itself never saw the count. `refund_outcome_unknown` stays set after a refusal (an *earlier* attempt's answer may have been lost), and the `refund_token` metadata never changes, so `refundCreatedWithToken()` still finds a refund whichever key created it |
| `usage-overage:…` (`ChargeUsageOverageDirectly`) | `RetryUsageOverageChargeJob` (1, 5, 15 min) | count on the snapshot (`usage_overage.key_refusals`), carried by `persistPendingCharge()` until a payment id is stored. `payment_unknown_at` was still written on a refusal at first; since 27 November 2026 it is not — see "A refused overage charge does not freeze the debt" |
| `subscription-replace:…` (`MollieSubscriptionReplacer`) | `RetrySubscriptionReplacementJob` (1, 5, 15, 30, 60 min) | count on the marker (`pending_subscription_replacement.key_refusals`); a new replacement writes a fresh marker. "Same description exists" (422) is not a refusal of ours to count — it is how a lost create is found, as before |
| `unassigned-refund:…` (`RefundUnassignedPaymentsJob`) | the next daily run | **not changed**: a day later the key is long gone; and the amount in it is read from Mollie on every run |
| `undercollection:…` (`UndercollectionCharge`) | nobody — one attempt per case, claimed; a failure goes to the operators | **not changed**: no retry sends the key again |

### Tests: red before, green after

Each run against the source without the fix (the six files swapped back) and with it.
| Test | Before the fix |
|---|---|
| `Feature/Refund/RefusedRefundKeyRotationTest` › admin refund: refused → retried at once with a new key, one refund; › the key moves on with every refusal; › lost answer after a refusal is replayed, not refunded twice | red: the retry got the 422 replayed ("Failed asserting that ApiException … is null") |
| › refund line: refused → `RetryRefundLineJob` at once, new key, one refund, `refund_token` unchanged; › the key moves on across one job's queue ladder; › lost answer after a refusal → adopted, not refunded twice | red: "Refund line retry rejected" — the replayed 422 |
| › admin refund / refund line: outage (503) → same key, one refund; › admin refund: lost answer → same key, one refund | green before and after: pin that only a refusal moves the key |
| `Feature/Wallet/OverageRefusedKeyRotationTest` › refused → retry at once, new key, one payment; › key moves on per refusal; › lost answer after a refusal → same key, one payment | red: the 422 replayed |
| › outage → same key, one payment | green before and after (pin) |
| `Feature/Billing/ReplacementRefusedKeyRotationTest` › refused → `RetrySubscriptionReplacementJob` at once, new key, one successor; › lost answer after a refusal → same key, one successor | red: "Failed asserting that 1 is identical to 2" (no successor) |
| › outage → same key, one successor | green before and after (pin) |
| `Browser/RefundDeepTest` › a refund Mollie refuses … can be retried once | the 61-minute clock jump is gone again: the operator's retry right after the refusal pays once |

The test double is `tests/Support/KeyedMollieWorld` (refunds and payments over
`MollieIdempotencyMemory`); the replacement runs in `RenewalLongRun`. No sandbox case was added:
`MollieIdempotencyContractTest` already measures that the same request with a fresh key is taken
after a refusal, and the suffix is just a fresh key.

## The renewal replay canary and what test mode does with a recurring payment (26 November 2026)

`tests/SandboxBrowser/RenewalReplayCanaryTest` replays renewals Mollie's scheduler really
created into the webhook controller, once a day (docs/sandbox-testing.md). Measured while
building it, against the real test API:

- **A test-mode recurring direct debit never settles by itself.** All 153 on the account were
  still `pending`, the oldest created on 17 April 2026; the existing canary's charges of 16
  and 17 November were `pending` ten days later. The earlier docs said "pending hours after
  creation" — it is indefinitely. A test-mode recurring payment carries
  `_links.changePaymentState` (Mollie's test-mode page with `final_state` = `paid` | `failed`);
  the canary settles through it, which is also how scenario 6 (a failed renewal → dunning) is
  reachable at all: `failed` arrives with `bankReasonCode` `MS03`.
- **A subscription started today is charged the same evening:** created 20:51 UTC with today's
  start date, its charge existed at 21:05 UTC (`pending`). A dress rehearsal of the canary with
  that (`MOLLIE_BILLING_RENEWAL_REPLAY_FIRST_CHARGE=today`, then reset) booked every scenario's
  first renewal as asserted — invoice at the collected amount, period from `createdAt`, wallets
  reset and topped up, the downgrade's 12.00, the coupon line, the failed renewal past due.
- **Recurring card payments are `paid` within a second** (266 on the account, none failed).
- **Shortest interval:** `1 day` is taken (also `2 days`, `1 week`); `12 hours` and `0 days`
  are refused (422).
- **A placeholder `webhookUrl` is refused in test mode** (`https://renewal-replay.invalid/…` →
  422 "unreachable from Mollie's point of view", payment and subscription alike).
  `http://example.com/…` — what `SandboxTestCase` forces — is taken.

Judgement calls:

- **Monthly, not "1 day".** The package knows monthly and yearly only and books a month per
  charge; a daily Mollie subscription would make every charge look like a new month and test
  nothing the package promises. The canary therefore waits a month between renewals; its first
  renewals are on the day after the first visit, which `CreateSubscription`'s
  `paid_period_ends_at` arranges through the package's own code.
- **Direct-debit mandate + `changePaymentState`, not a card mandate.** A card mandate needs a
  hosted first payment and its recurring charges cannot be made to fail; the API mandate plus
  Mollie's own test switch gives both outcomes without a hand-written row.
- **`past_due_max_days` = 2 in the canary's world**, so the failed renewal reaches
  auto-cancel and expiry within the week instead of a month.
- **Jobs once per UTC day**, as the scheduler would run them. That is what makes "a second
  visit the same day changes nothing" true and assertable.
- **Opt-in by `MOLLIE_BILLING_CANARY_STATE`.** Without it the test is skipped and creates
  nothing; a world at Mollie with no state file looking at it is what the reset cannot find.

Seen in passing, not acted on (they concern `SubscriptionCanaryTest`): its `daily`
subscription (`times: 10`) reads `canceled` with `canceledAt` at its tenth charge, not
`completed` — the canary's "…not completed" check will report it on its next visit; and the
canary's mandate now reads **410** while `monthly`, `month_end` and `pinned` read `pending`
with no `nextPaymentDate`, so the existing canary will see no further charges.

## The renewal replay canary moves to GitHub Actions and reads Mollie's real webhooks (27 November 2026)

Decided by the maintainer on 27 November 2026: the renewal replay canary runs daily for twelve
months and more without the maintainer's Mac, and observes Mollie's **real** webhook deliveries,
Mollie's retries included (docs/sandbox-testing.md, "The cloud canary").

What was decided, and why:

- **An always-on receiver of its own, not the package's app.** Mollie cannot reach a CI runner.
  A small PHP app in its own repository (`mollie-canary-receiver`) records every POST on a
  persistent SQLite file and is read back over `GET /deliveries` with a bearer token. The canary
  sets the app's root URL to the receiver's, so every URL the package builds — the webhook above
  all — goes there through the package's own URL generation; without `CANARY_RECEIVER_URL` it
  stays `https://example.com` and every delivery synthetic, as before.
- **The real deliveries ARE the replay.** They are POSTed into the webhook route in arrival order
  (the same `id=` form body). A payment the visit books must have at least one delivery at or
  after its final status change (the settle on Mollie's test page, else `paidAt`/`failedAt`);
  none within the wait → "Mollie did not deliver", plus a synthetic POST so the other checks
  still run — a missing delivery must not hide a booking bug. Only payments whose own
  `webhookUrl` is the receiver's are held to this: a payment created before the PATCH below
  could never have been delivered there.
- **The existing subscriptions are PATCHed once, with nothing but `webhookUrl`.** Through
  `MollieCall::send` — not `MollieSubscriptionPatcher`, whose door exists for start dates; no
  start date goes along, so the "…already charged" refusal cannot apply. Measured on
  27 November 2026 on a throwaway subscription (start date today, created and removed for this):
  a PATCH of only the `webhookUrl` is taken both before the first charge and after it (charge
  created 8 minutes later, `pending`), GET reads the new URL back, and the start date and
  `nextPaymentDate` stay as they were. The charge created after the first PATCH carried the
  PATCHed URL, so a renewal follows the subscription's current `webhookUrl`.
- **A later delivery of a booked payment must change nothing** while the payment's status is
  the booked one — the package's idempotency, now against Mollie's own re-deliveries.
- **Retry probes are standalone payments, not billables.** Weekly, 1.00 € each, paid on the
  hosted test page, webhook URLs on the receiver's `/probe/…` paths: 503 three times then 200,
  500 forever, and a held-back answer twice where the receiver enables it (off by default: on a
  128 MB host a sleeping PHP worker can starve the health check, and the host's own request
  timeout is undocumented). Read 30 hours after payment — Mollie documents 10 calls, the tenth
  26 hours after the first, and a 15-second timeout
  ([docs.mollie.com/reference/webhooks](https://docs.mollie.com/reference/webhooks), read
  27 November 2026). The first reading is the baseline (count, outcomes, intervals, whether a
  200 ends the chain); a later one that differs is red. Intervals get max(120 s, 50 %) of play
  because the baseline is a single sample of a scheduler whose jitter nobody has measured; the
  count, the outcomes and "a 200 ends it" are held exactly.
- **State on an orphan branch, `canary-state`.** JSON, SQLite file and invoice PDFs, committed
  after every visit by the workflow (bot identity, `[skip ci]`). A binary SQLite commit a day
  grows the repository by roughly the compressed file size (≈ 300 KB uncompressed today) — the
  price of a world that survives without a machine.
- **Red → one issue labelled `canary`**, commented on by every further red visit and closed by
  the next green one; a visit that leaves `last_problems` is red even if the test exited 0.

Not verified here, only after merge: the workflow itself (scheduled runs exist only on the
default branch), the receiver on its host, Mollie actually delivering to it, and whether the
daily bot commit on `canary-state` counts as "repository activity" for GitHub's 60-day rule.

Retry baseline: _not measured yet — copied here from `retry_probes.baseline` on
`canary-state` when the first probe set has been read._

## A failed activation gives its mandate back only on a billable that has not moved on (27 November 2026)

Reported by review: `RetrySubscriptionActivationJob::giveBackMandate()` read the mandate,
customer and `mollie_subscription_id` off the billable, called `GetPaymentRequest` — rightly
with nothing held — and then cleared `mollie_mandate_id` and dispatched `RevokeMollieMandateJob`
on that earlier reading. Reproduced (`ActivationRetryTest` › "does not give back a mandate that
a newer activation adopted…"): a second checkout that activated during the lookup kept
`sub_newer` but its mandate column became null; with the same mandate adopted, it was also
revoked, and Mollie cancels the subscriptions of a revoked mandate.

- *Rule:* after the Mollie call, the billable is re-read under `lockForUpdate` in a short
  transaction; the mandate is cleared only if it is still this payment's, the customer is the
  same and there is still no Mollie subscription. The revoke is dispatched after the commit and
  only when the clear happened. Any other state means another activation has moved on — the
  mandate is left alone and **not** revoked, even when it is no longer the one on file: an
  unreferenced leftover mandate costs nothing, revoking one a subscription charges ends that
  subscription.

## A reversed booking does not settle a refund line (27 November 2026)

Reported by review: a refund line whose Mollie answer was lost waits in
`pending_refund_retries`; the payment webhook books the refund it created (`RefundHandler`
stamps the line's `refund_token` onto the credit note); Mollie then reports that refund
`canceled` or `failed`, and `RefundHandler` reverses the booking. `RetryRefundLineJob` found
the token on the historical credit note and settled the line on it — no payout, no retry left,
and the only trace an `AdminRefundFailedNotification` for a refund the package itself still
owed. Reproduced (`RefundRetryAfterReversalTest`, both reversal cases red, 0 live refunds).

- *Rule:* `InvoiceService::createRefund()` settles a line on its token only when a booking
  carrying it **stands**. A token line whose `mollie_refund_id` has a reversal line
  (`reverses_credit`, same id — the reversal drops the token, so the id is the link) settles
  nothing; the line is posted again. That is the same answer `refundCreatedWithToken()` already
  gives when it finds the dead refund at Mollie before anything booked it, so both paths agree.
- *The key moves on.* After the reversal restored the headroom the line sends the same amount,
  and within the hour Mollie would replay the canceled refund under the old key (the retry was
  then silently swallowed by the "replayed refund id already on the books" check). On a
  reversed booking the line counts one more `refund_key_refusals` and is marked
  `refund_outcome_unknown`, so the token lookup runs first — a live refund an earlier retry
  created is adopted, not paid twice. The token itself never changes.
- *The plan-change resume reads the books the same way.* `ProrataChargeHandler::
  withoutSettledRefundLines()` treated any token on a credit note as settled: a run that died
  after paying its refund lines, whose refund Mollie then reported dead, applied the change on
  re-delivery and never refunded the customer (reproduced: `ProrataChargeResumeTest` › "pays a
  refund line again whose booking a failed refund took back…", one refund instead of two).
  Both now go through `InvoiceService::refundTokenBookings()` (one read of the credit notes for
  every token). A re-opened line is **not** an "outcome unknown" line under
  `refunds_started_at`: its refund was booked and then reported dead, so it goes to
  `createRefund()`, which asks Mollie for a live refund with its token before posting under a
  moved key. Only lines with no booking at all still stop the run with `refunds_unknown`.
- *Accepted:* the operator still gets `AdminRefundFailedNotification` for the reversal while
  the retry posts the line again. The re-posted refund shows as a new credit note, and the
  invoice's refundable remainder already counts it.

`tests/Feature/Refund/RefundRetryAfterReversalTest.php` — red before (2 of 3: "posts the line
again … canceled — within the key hour", "… failed — after the key hour"), green after; with the
key rotation alone taken out, the within-the-hour case is red again. The resume test is red
with the handler's unfixed reading and with only the `standing` check taken out again.

## A refund retry that throws puts its line back (27 November 2026)

Reported by review (CodeRabbit): `RetryRefundLineJob::handle()` merged what the stored
`pending_refund_retries` entry knew into the line, removed the entry, and called
`InvoiceService::createRefund()`. That re-appends a line Mollie rejected, but it can also
**throw** — a credit note that cannot be written after Mollie paid out, a lock-wait timeout.
The entry was then gone; the queue re-ran the job with its *dispatch* payload, which lacked the
refusal count and the outcome-unknown mark: the token lookup was skipped and the line went out
under a key Mollie no longer held — a second payout once the hour had passed. Reproduced:
`RefundRetryThrowKeepsEntryTest` › "keeps the stored entry when the booking throws…" red (no
entry after the throw). The note's "refund lock held" example does not throw: that path files
the line as failed and re-appends it.

- *Rule:* a throw out of `createRefund()` puts the entry back unless `createRefund()` already
  re-appended it — with the merged state, `refund_outcome_unknown` forced true (the throw may
  come after Mollie acted; only the token lookup tells), the original `first_attempt_at`, and the
  throw as `error`. The exception is rethrown for the queue's backoff. A line booked before the
  throw is found on the books by its token on the next run and not posted again.
- *The token is merged too, and minted by the job.* A payload dispatched before the first Mollie
  call (the lock was held) carries no token; the merge took refusals and the unknown mark from the
  stored entry but not the token, so the next run minted a fresh one and the lookup could never
  find the refund an earlier attempt created (red: "takes the token from the stored entry…",
  one extra POST). The job now takes the stored token and mints one itself before
  `createRefund()`, so the entry it writes back carries the token the refund was sent with (a
  clamped line is sent as a copy).
- *Accepted:* removing the entry up front stays — it is what makes a re-append unambiguously
  this attempt's failure. A failure to write the entry back is logged and the original throw
  still reaches the queue.

Red before (2 of 2) with the unfixed job copied back, green after; `tests/Feature/Refund` and
`tests/Feature/Jobs` green.

## A refused overage charge does not freeze the debt (27 November 2026)

Maintainer's decision. `ChargeUsageOverageDirectly::charge()` marked every throw of the
create-payment request `payment_unknown_at` — also a definite refusal, after which only the key
moved on ("A refusal moves the idempotency key on; nothing else does"). The marker freezes the
debt: `PrepareUsageOverageJob`'s retry pass skips it and `handle()` counts it as in flight
until `CleanupStalePendingOverageChargeJob` has asked Mollie's payment list — a question whose
answer a 4xx already gave.

- *Rule:* a definite refusal (`MollieRefusal::isDefinite()`: a 4xx except 408, 409 and a refusal
  of the key itself; 429 counts) writes no marker. The refusal is counted on the snapshot
  (`key_refusals`, through `MutatesBillingMeta`), the snapshot stays `pending` without an id, and
  the exception still reaches the caller, so `RetryUsageOverageChargeJob`'s ladder retries with
  the moved-on key and pass 3 dispatches it again the next night. A ladder exhausted by refusals
  takes the existing path — `failed()` mails the operators; create failures never dun and never
  set past due. A timeout, a 5xx or a lost answer is marked and frozen exactly as before.
- *Consequence for the plan change:* `WalletPlanChangeAdjuster::chargeDeferredOverage()` handed
  the excess back to the wallet whenever `paymentMayExist()` was false. Without the marker a
  refused charge would then be recorded twice — in the pending snapshot the retry charges, and
  on the wallet the next sweep charges (a plan-change line carries `settles_wallet: false`, so
  nothing cancels it out). A definite refusal with a snapshot in place now leaves the debt in the
  snapshot only.
- *Why it is safe:* `persistPendingCharge()` rebuilds the snapshot without the marker on every
  attempt, so a marker from an earlier lost answer never survived a retry anyway; and a payment
  created under a key is replayed for that key, never answered with a 4xx, inside the hour the
  ladder runs in.

`tests/Feature/Wallet/OverageRefusalReleasesDebtTest.php` — red before (3 of 5: "releases a
refused overage charge at once…", "keeps the existing failure path when refusals exhaust the
ladder…", "leaves a refused plan-change charge in the snapshot…" — all "not to have key
'payment_unknown_at'"), green after; with only the adjuster reverted the plan-change case is red
again (the wallet takes the units back). The 503 / lost-answer pins are green before and after.

## The canaries rebuild a dead world, run in the cloud together and retry once (27 November 2026)

Decided by the maintainer on 27 November 2026: `SubscriptionCanaryTest` moves into the cloud
workflow next to the renewal replay canary (the local launchd job is retired), and both heal
themselves instead of failing forever.

Why the subscription canary failed: its world of 15 November lost its mandate between
25 November 00:16 and 26 November 19:49 UTC. Read on 27 November: `GET` mandate → **410** "The
mandate is no longer available"; `daily` `canceled`, `canceledAt` 2026-11-25T00:16:33Z — the
moment of its tenth charge; `monthly`, `month_end`, `pinned` `pending` with no
`nextPaymentDate`. Its check "a subscription with `times` ends up `completed`" failed on every
visit from 26 November on, and nothing else could be observed any more. Nobody in the canary
revoked anything. Measured while rebuilding (27 November, scratch worlds, removed again): a
mandate revoked **through the API** answers 410 too, but Mollie then **cancels** every
subscription on it at once — the dead world's subscriptions read `pending`, not `canceled`, so
its mandate did not go by an ordinary API revoke. How it went is open; a rebuilt world records
`mandate_last_valid_at` every visit, so the next death has a window of a day.

Judgement calls:

- **A dead world is a problem once, then rebuilt.** Dead = the mandate gone (404/410) or
  `invalid`, an open-ended subscription gone or ended, or the `times` subscription ended before
  its last charge (`SubscriptionCanaryWorld::deathReasons()`). A `pending` mandate is not dead.
  The canary never revokes or cancels outside a reset, so every death is unexpected and worth a
  look — but only once: the old world goes to `previous_worlds` (with `died`) and is torn down in
  the same visit, so the next visit cannot report it again.
- **`times` ends with `completed` or `canceled`.** Mollie ended `daily` as `canceled` at its last
  charge (measured 25 November). Both are an end and recorded in `ended`; only charging past
  `times`, or still running after it, is a problem. The package does not use `times`.
- **The renewal replay world is judged only where the package still bills.** A scenario whose
  billable is `active` or `past_due` must have a living mandate and a running Mollie subscription;
  the cancel and dunning scenarios end theirs on purpose, and a subscription replacement in flight
  (`pending_subscription_replacement`) is not a death. The rebuild happens on the **next** visit,
  in a fresh process: deleting the SQLite file under a running app (or truncating it, which is
  what `db:wipe` does for a file database) is not safe. Kept: visits, `previous_worlds`, the retry
  probes and their baseline (standalone payments), the receiver's cursor. Lost: payments,
  invoices, wallets, scenario progress — they stay in `canary-state`'s history.
- **Retry only a visit that died without a finding.** Both visits write down what they found
  before an exception leaves (`visits[].aborted`). A visit with problems written down is not
  retried: the renewal canary books each payment once, so a second attempt would come back green
  and hide the finding. A visit that died clean is retried once after three minutes; passing then
  is `recovered` — a note in the summary, no issue.
- **The receiver's weather is not Mollie's fault.** An unreachable receiver (5xx, timeout, no
  answer after three tries) is a warning: the visit replays synthetically and does not report
  "Mollie did not deliver" for payments it could not check; probe sets wait. A rejected read token
  (401/403) is a misconfiguration nobody else would notice: red, one message per visit while it
  lasts — "once" meaning one clear message instead of a "did not deliver" for every payment; it
  stays red until fixed, since a green visit would close the issue.
- **The job conclusion is the verdict.** The visit steps continue on error so both canaries
  always run; a last step fails the job exactly when either canary is red after the retry.
- **Separate resets.** `MOLLIE_BILLING_SUBSCRIPTION_CANARY_RESET` and
  `MOLLIE_BILLING_RENEWAL_REPLAY_RESET`; `MOLLIE_BILLING_CANARY_RESET` keeps meaning both. A
  reset also tears down `previous_worlds` whose tear-down failed.

Verified locally against the real test API in scratch directories (never the cloud state): the
subscription canary built a world (incomplete), saw nothing new on a second visit (incomplete),
reported a mandate revoked behind its back once and rebuilt in the same visit, was incomplete on
the visit after, and its reset removed both worlds (customers 410). The renewal replay canary
built a world, found a living world alive on the next visit (incomplete; the cancelled scenario
not mistaken for a death), reported a revoked mandate once, rebuilt on the next visit, and its
reset removed both worlds. Not verified here, only in the cloud: the workflow itself (retry,
verdict, issue, artifact), the receiver-failure paths against a real outage.


## `createRefund()` reads the refund history once per refund lock (28 November 2026)

Reported by review (performance, v1.1.0, `InvoiceService` call sites at the token lookup and the
"Mollie replayed a refund that is already on the books" check): every refund line asked its
questions from scratch. `refundTokenBooking()` and `refundIdOnBooks()` each loaded and decoded
every credit note of the billable, and a line whose earlier answer was lost asked a third time
after `refundCreatedWithToken()` — which itself paged the payment's whole refund list through
Mollie again for every line of the same payment. A plan change with plan, seat and addon lines
on a billable with a long refund history re-read that history up to nine times, and asked Mollie
three times, while holding the refund lock. Measured: `RefundHistoryReadOncePerLockTest` red
before (7 history reads where 2 are expected; the refund list fetched 3 times, expected once).

- *Rule:* `creditNoteBookings()` reads token states and booked refund ids in one query; the
  Mollie refund list is fetched once per payment. Both are held for the run of `createRefund()`
  and the books are dropped whenever another invoice's refund lock is taken.
- *Why the snapshot is safe under the lock:* RefundHandler and RefundInvoiceService take the same
  per-invoice lock before they book, and the credit note `createRefund()` writes is persisted only
  after its last Mollie call — so nothing that could change an answer for a line lands between
  the read and the line. Refunds this run creates at Mollie carry other lines' tokens and do not
  change the lookup for the next one. Before a lock is ours the webhook can still book against
  that invoice, hence the drop on every new lock.
- *The snapshot is not trusted past the lock's lease (CodeRabbit).* The refund lock has a fixed
  120-second lease that nothing renews, so a batch that outlives it lets RefundHandler book a
  refund the batch just created. Before the credit note is written the books are read once more
  and a line whose `mollie_refund_id` is now booked is dropped (its net stays in the
  `refunded_net` write is then recomputed from the books — `effectiveRefundedNet()` read fresh,
  plus the lines still booked — because only the books know whether the other actor's booking
  stands or was reversed meanwhile; the running tracker counted it either way). One read per
  batch, after every Mollie call — it also sees a booking that landed while a later line was
  posted, which the old per-line check right after each call did not.
- *A reversed refund id re-opens the line (CodeRabbit, third round).* Same rule as "A reversed
  booking does not settle a refund line", applied to the refund id where no token links the
  booking: when the final look finds the line's refund booked *and* reversed by another actor,
  or Mollie replays a refund whose booking was reversed, the line goes to
  `pending_refund_retries` with its token, one more `refund_key_refusals` and
  `refund_outcome_unknown` — RefundHandler's reversal only notifies the operator, it queues
  nothing. A standing booking is still dropped as booked.
- *The snapshot expires with half the lease (CodeRabbit, second round).* A token read as
  `standing` settles a line without posting or retrying it, so a snapshot older than the lease
  could drop an owed refund another actor had reversed meanwhile. Once a line's lock was taken
  `REFUND_SNAPSHOT_TRUSTED_SECONDS` (60, half of `REFUND_LOCK_SECONDS`) ago, the books and the
  Mollie refund list are read again for every line — the per-line behaviour from before.
- *A cached refund is re-read before it is adopted (CodeRabbit).* When the refund list was read
  for an earlier line, the refund a later line would adopt is fetched again by id
  (`GetPaymentRefundRequest`): a queued refund that failed meanwhile is not booked, the line is
  posted again. Only reached by a line whose earlier answer was lost.
- *Not narrowed in SQL:* the token and refund id live inside `line_items` JSON, and
  `whereJsonContains` is not portable to SQLite — same reasoning as `refundChildren()`. The
  original invoice's own `refundChildren()` read (one per instance) is a separate memo and stays.
- *Removed:* `refundTokenOnBooks()` (no caller), `refundTokenBooking()`, `refundIdOnBooks()`,
  `creditNoteLineExists()`. `refundTokenBookings()` stays public for ProrataChargeHandler.

Red before (2 of 2; each CodeRabbit round's two cases 2 of 2 against the commit before it) with the unfixed
`InvoiceService` restored, green after; `tests/Feature/Refund`, `tests/Feature/Webhook`, `tests/Feature/Billing`, `tests/Feature/Coverage`
green.

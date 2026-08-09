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

## Refuted findings

Reported, investigated, and **not** changed. Listed with what the investigation showed, so
the same note does not come back.

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

### `RefundHandler` does not over-credit across refunds in one delivery

`refundChildren()` is memoised, and `creditNetForGross()` does read it, so the reasoning
looks sound. It is still wrong: the handler carries `refunded_net` forward inside the loop
and `effectiveRefundedNet()` takes the maximum of that column and the credit-note sum, so
the stale snapshot is compensated. A test that sends two refunds in one delivery whose sum
exceeds the invoice passes with and without the "fix" — which is what refuted it. The test
stayed, as a guard and as a record: see
`tests/Feature/Webhook/WebhookRobustnessTest.php`.

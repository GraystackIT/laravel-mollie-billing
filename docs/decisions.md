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
one hour, which covers the retry ladder (`[60, 300, 900]`) and not the nightly sweep. See
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
  the refund id is on record by then.

`tests/Feature/Refund/PlanChangeRefundWebhookRaceTest.php` forces the interleaving
deterministically (the webhook arrives inside the second line's Mollie call).

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
| A reversed chargeback (`reversedAt`) leaves its credit note and `PastDue` in place | How Mollie reports `amountChargedBack` after a reversal is not known; `reverseBookedCredit()` takes a reference key and would serve `mollie_chargeback_id` once that is measured. |
| `ProrataChargeHandler` derives `seat_count` from the charge invoice and falls back to the included seats when the seats line is missing | Only reachable with an unpriced or sub-cent seat line. |
| The renewal writes back the `seat_count` it read at the start | A pure interleaving with `SyncSeats`; no deterministic reproduction. |
| An invoice may book one cent more than Mollie collected | That is the documented reconciliation slack; no real trigger found. |

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

### Known limits, not changed — plan changes and proration

- **Plan change (same interval) whose SEPA charge spans a renewal.** Local switch and PATCH both
  wait for the charge, so they do agree with each other — but the renewal in between is collected
  and booked for the old plan, and Phase 2 then switches plans mid-period without settling that
  period. Closing it needs a second prorata in Phase 2 (a further charge for an upgrade), which is
  a new money flow rather than a fix.
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

### The trial wins over a coupon, and a first-payment coupon is not accepted on a trial signup

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
| A signup on the 29th/30th/31st: the start date sent is the clamped end of the first period, so Mollie's cycle is the 28th/30th. Under a clamping cadence February leaves up to two days between "one month after the last charge" and the next charge, once; the period then follows the charge and nothing accumulates | `RenewalLongRunTest`, month-end data sets (both cadence models) |
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

#### Open — not testable here, or not decided

- **Mollie's cadence after a month end.** Whether a subscription started on the 30th
  charges on 30 March or 28 March after February cannot be observed: the sandbox scheduler
  runs daily and no run sees a period roll over. The package is correct under both models
  (the anchor follows the charge), but under the clamping one the local period ends up to
  two days before the charge once a year, and during those days `nextBillingDate()` is in
  the past — `guardPeriodNotLapsed()` refuses seat and addon changes until the webhook
  arrives.
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
recurring coupon's ends `N × 30 + 2` days after it was applied; three monthly periods from
10 January put the first charge on 12 April. A0 refilled on 10 April, the webhook refilled
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
| A cycle the dunning gave up on is written off by the renewal that recovers the billable | Unchanged from round 1. Three failed payments, PastDue, renewal paid the next day: Active, wallet reset to the quota, no fourth attempt. The following period's overage is an ordinary new cycle. | `UsageLongRunLifecycleTest` (recovery) |
| An immediate cancellation makes one more attempt at a written-off debt | `CancelSubscription(immediately)` charges what the wallets owe. After a dunning episode that ended in auto-cancel this is a fourth payment on a method that failed three times, with its own two retries: six requests in all, none after expiry. It is the last moment the debt can be collected at all. | `UsageLongRunLifecycleTest` (stays past due) |
| Resuming a cancelled subscription credits nothing | It restores the paid period; the next quota comes with the next renewal. | `UsageLongRunLifecycleTest` (resubscribe) |
| A Local period that starts on the 31st moves to the 28th in February and stays there | `nextBillingDate()` clamps, and the Local renewal stores the clamped day as the new anchor. It is one clamp, not a day lost per period. Keeping the original day would need a stored anchor day that `nextBillingDate()` — and every prorata window built on it — honours. Free plans only; a paid subscription's anchor comes from Mollie. | `UsageLongRunLocalTest` (month-end) |

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
- **Seat (or addon) added after Mollie created the next debit.** 31 Jan +1 seat; the February
  debit was created on 30 Jan at 60.00. Three seats for February, two paid: **5.00 net short,
  once**; booked with an `adjustment`, not topped up. Needs a charge from inside the renewal
  webhook. Pinned.
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
still a duplicate.

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
  if the code was switched off while the payment travelled; `validate()` is the gate.
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
| Seat or addon added while past due | Expansions are refused; reducing and plan changes stay possible |
| A plan change kept the seat count | Seats fall to `max(used, included)` unless the customer chooses to keep more |
| Interval change under a plan waiver | Stays refused |
| Two cases that collect too little once | Not charged automatically; detected, audited, reported to the admins with the amount |
| Simplifications | Next charge (date and amount) on dashboard and admin tab; one "redeem a code" field; coupon form by intent |
| A single "adjust subscription" page | Not built; tracked as DEV-386 |

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

"Gone" is one predicate, `grantStillRuns()`: an active Local subscription that ends in the
future **and** a grant redemption that was not revoked. It is deliberately the same set of
conditions `carryGrantOver()` needs to write a waiver, so there is no state in which
neither a waiver is written nor a refund issued.

Not affected: a second payment while a Mollie subscription already runs is still a
`DuplicatePaymentReceived`; an upgrade without `keep_grant` is booked as before; a purchase
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
reset and charges the new period in full. Phase-2 re-validation is exempt (that change was
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
- A seat change scheduled for the period end on a non-past-due subscription that *becomes* past
  due before it is applied is applied by the dispatcher as before (factor 0). Not tested.


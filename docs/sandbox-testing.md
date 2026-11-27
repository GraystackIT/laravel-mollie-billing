# Sandbox testing — the real Mollie test system

Three suites talk to Mollie for real. None of them is part of `composer test` or
`composer test:browser`: they need a network and `MOLLIE_KEY=test_…` in `.env`, and they
create records in the Mollie dashboard. **Test keys only** — `SandboxTestCase::sandboxKey()`
refuses anything that does not start with `test_`.

| Suite | Command | Needs | What it answers |
|---|---|---|---|
| `tests/Sandbox` | `composer test:sandbox` | key, network | Does Mollie accept the requests the package builds, and do its answers carry the fields the package reads? |
| `tests/SandboxBrowser` | `composer test:sandbox:browser` | key, network, Chrome | A complete checkout, paid on Mollie's hosted page — and everything that only exists once a payment has really settled. |
| the canary | `composer test:sandbox:canary` | key, network, a daily run | What Mollie's own scheduler does with a subscription over days and months. |
| the renewal replay canary | `composer test:sandbox:renewal-replay`; daily in GitHub Actions (`renewal-canary.yml`) | key, network, Chrome, a daily run, `MOLLIE_BILLING_CANARY_STATE`; in CI the webhook receiver | What the package books when Mollie's scheduler really charges: renewals, overage, a scheduled change, a recurring code, a cancel and a failed renewal, replayed into the webhook — with the receiver, Mollie's real deliveries, and how Mollie retries. |

Everything the first two create is torn down after each test (`trackMollieResource()`):
subscriptions cancelled, mandates revoked, customers deleted. The two canaries are the
exception — surviving is their job.

## `tests/SandboxBrowser`: paying at Mollie

`tests/Sandbox` stops where a human would have to act. A hosted checkout has to be
completed by somebody, so no payment there ever settles. This suite is that somebody: it
starts a checkout through the package's own services, opens Mollie's checkout URL in the
same Chrome the browser suite uses, picks the outcome on Mollie's test-mode page, and then
calls the package's webhook route itself with the payment id (Mollie cannot reach a
Testbench app).

Unlike `tests/Browser` there is no second process and no workbench — nothing of the
package's own UI is on screen, only Mollie's. The app under test lives in the test process
on the in-memory database, with the real Mollie client. It starts its own chromedriver on
port 9517, so it does not collide with the browser suite's.

### What Mollie's test checkout looks like (measured, November 2026)

1. `https://www.mollie.com/checkout/select-method/{token}` — one
   `<button name="method" value="…">` per payment method inside `form#body`, plus
   `form#cancel-button` ("‹ previous page"). For an Austrian customer on the test
   profile a payment with an amount offers `creditcard` and `eps`; a **€0.00 first
   payment offers `creditcard` only**.
2. eps opens `…/checkout/test-mode?method=eps&token=…`: a plain form with
   `<input type="radio" name="final_state" value="open|paid|failed|canceled|expired">`
   and `<button name="submit">`. In test mode, that page is the bank.
3. The card method opens `…/checkout/credit-card/session/{token}`. The fields live in one
   Mollie Components iframe (`iframe[src*="components/card"]`): `#cardNumber`,
   `#cardExpiryDate`, `#cardCvv`, `#cardHolder` and a submit button. With the test card
   `4543 4740 0224 9996` it continues — no 3-D Secure step — to the same test-mode page
   (`method=creditcard`, radios `open|paid|failed|expired`).
4. **`paid`** redirects to the payment's `redirectUrl`; the status is final at that moment
   and the first payment has left a mandate.
5. **`failed` and `expired` do not end the payment.** Mollie shows the method list again
   and the payment stays `open`: the customer may try again on the same payment until it
   runs out. A first payment expires **15 minutes** after it was created (`expiresAt`).
   A webhook the package sees in that window therefore carries `open` — it must not
   activate anything and must not use up the payment's one booking.
6. **The cancel button** on the method list redirects to the `redirectUrl` at once; the
   payment is `canceled` about five seconds later. That is the only terminal unpaid
   status reachable from the hosted page within a test. The API cannot do it instead: an
   open first payment has `isCancelable: false` and `DELETE /payments/{id}` answers 422.
   (Measured again on 26 November 2026 for a first payment with no method, with `creditcard`,
   with `eps` and at €0.00: all refused.)

The suite pays with **eps** where it is offered (valid for a first payment, settled by one
radio button) and with the **test card** where it is not (the €0 mandate path: trials and
fully covered bookings). `SandboxBrowserTestCase::completeMollieCheckout()` is the driver;
when Mollie shows something else it saves a screenshot and the page source to
`tests/SandboxBrowser/screenshots/` (git-ignored) and fails with the URL.

If the profile behind your key offers neither eps nor card, enable one in the Mollie
dashboard (test mode).

### What is covered

- `MollieHostedCheckoutTest` — first booking without a coupon; with a single-payment
  percentage coupon; with a recurring percentage coupon; fully covered (the €0 mandate
  path); a trial; paid extras on a gifted plan; an attempt that fails or expires at the
  bank and is then paid on the same payment; and a cancelled checkout. Each asserts the amount at Mollie, the invoice, the Mollie subscription with its amount
  and `nextPaymentDate`, the mandate, and the coupon redemption.
- `MollieSettledPaymentContractTest` — the measurements a settled payment makes possible:
  a refund (is it accepted, what status, what `refunds()` lists), a cancelled refund,
  a refund after the mandate was revoked, a recurring charge on the captured mandate
  (`createdAt`, `paidAt`, status over time), a refund sent twice with one key (one refund;
  another amount with that key is a 400; above what is left a 422), and that test mode cannot
  produce a chargeback.
  Every figure is also written to `tests/SandboxBrowser/screenshots/measurements.json`.

### What a settled payment showed (measured, 16 November 2026)

| Question | Answer |
|---|---|
| Refund of a settled payment | Accepted, status `pending`. The payment stays `paid`; `amountRefunded` is `0.00` and `amountRemaining` unchanged while the refund is pending — only `refunds()` shows it. |
| A refund that is cancelled | Stays in `refunds()` with status `canceled`. Whoever reads the list has to read the status. |
| Refund of a first payment after its mandate was revoked | Accepted (`pending`). |
| A recurring payment created on the captured mandate | `directdebit` (an eps first payment leaves a direct-debit mandate), `pending` at creation, still `pending` without `paidAt` 15 seconds later. |
| An unpaid first payment left alone | `expired` 15 minutes after `createdAt` (plus Mollie's sweep: 16 min 48 s measured), no mandate. |
| A charge Mollie's scheduler creates for a subscription (canary, first visit) | Created at 00:28 UTC **on the cycle date**, `pending`, no `paidAt` hours later (direct debit); `nextPaymentDate` moves on by one interval. |
| Chargeback | Cannot be produced: no API endpoint, no option on the test pages. |
| The same idempotency key on two different payments | **400** "used before on a different URL", for an hour. Found because every in-memory test database has an invoice 1 — the refund key now contains the payment id. |
| The same refund key twice (measured 26 November 2026) | one refund, the same id; the same key with another amount: **400** "…used before with different parameters…" |
| A refund above what is left (26 November 2026) | **422** "The specified amount cannot be refunded", field `amount.value` |

### What an idempotency key, a cancel and a revoke showed (measured, 26 November 2026)

`tests/Sandbox/MollieIdempotencyContractTest` — no browser needed. What follows for the package
is in [decisions.md](decisions.md), "What Mollie does with a key, a cancel and a revoke — measured".

| Question | Answer |
|---|---|
| Same key, other parameters (payment, subscription) | **400** "You are using an idempotency key that you used before with different parameters…" |
| Same key on another URL (payment → subscriptions, customer A → customer B) | **400** "…used before on a different URL…" |
| A request refused with 422, sent again identically with its key after the reason had gone | the **same 422** again — a fresh key is taken. A refusal binds the key; another request with it is a 400 |
| A replay after the resource changed | the first answer as it was then (amount and status), not the resource now |
| PATCH with a reused key | not keyed: another body is taken, a repeat is applied again |
| DELETE (cancel subscription) with a reused key | replayed (200, cancelled subscription); a fresh key gets the 422 of a second cancel |
| Cancel an open first payment: no method, `creditcard`, `eps`, €0.00 | **422** "The payment cannot be cancelled", `isCancelable: false`, stays `open` |
| Cancel a pending recurring direct debit | taken, `isCancelable: true` → `canceled` |
| Revoke the mandate of a subscription | subscription `canceled` at once; the mandate reads **410** Gone |

### What a charged subscription showed (measured, 17 November 2026)

The canary's `pinned` subscription had its first charge at 00:30 UTC on 17 November (direct
debit, `pending`); the morning after, the canary's PATCH of its start date was refused. The
rules below are pinned by `tests/Sandbox/MollieChargedSubscriptionContractTest` — its charged
cases run against the canary's `pinned` subscription (skipped while that has no charge) and
undo every change they make. Mollie's reference documents none of them.

| Question | Answer |
|---|---|
| A start date PATCHed onto a charged subscription | **422** "The start date cannot be changed when the subscription is already charged." — for a later date, for the next payment date itself, and for an earlier one |
| …the start date it already holds | taken, as a no-op |
| …together with an amount | refused as a whole: the amount is not changed either |
| Amount, interval, description, metadata on a charged subscription | taken |
| An interval PATCHed onto a charged subscription | taken; `nextPaymentDate` becomes start date + n × the new interval, counted from the **start date** (1 month → 2 months moved 17 Dec to 17 Jan) |
| A start date in the past on a subscription not yet charged | 422 "Start date is not today or in the future." |
| Today as start date (created, or PATCHed onto one not yet charged) | taken; `nextPaymentDate` moves one interval on at once — today counts as charged. The payment itself is created later by Mollie's scheduler: not within the first five minutes, but there under two hours later (not timed more closely) — a charged subscription can be had the same morning, not within one run |
| A second subscription with the same description for one customer | 422 "A subscription with the same description already exists for this customer." while the first is active; taken once it is cancelled |
| Cancelling, or PATCHing, a cancelled subscription | 422 "The subscription has been cancelled." |
| A create sent again with the same idempotency key | the same subscription comes back |

What follows for the package is in [decisions.md](decisions.md), "A charged subscription is
replaced to move its next charge".

**Not measurable in one run, therefore in the canary:** whether the refusal ends once the
charge has settled (a test-mode direct debit was still `pending` 26 hours after creation, and
may never settle), and what Mollie answers for today's date on a charged subscription whose
start date is not today. The dates are in the table below.

## The canary: a subscription that stays

No single run can watch Mollie's scheduler: it runs daily, and the earliest start date of
a subscription is tomorrow. `SubscriptionCanaryTest` is therefore not a run but a visit.

- **First call**: creates one customer with a direct-debit mandate and four
  subscriptions — `daily` (a charge every day, ten times, then `completed`), `monthly`
  (from tomorrow), `month_end` (from the next 31st), `pinned` (from tomorrow; added on
  16 November 2026, a canary created before grows it on its next visit) — and writes their
  ids to a state file.
  It reports `incomplete`: there is nothing to observe yet.
- **Every later call**: lists the payments Mollie has created for each subscription and
  asserts
  - `createdAt` of each charge is its **cycle date** (UTC) — the premise of
    `SubscriptionPaymentHandler::cycleStart()`, which anchors the billing period there;
  - the amount is the one the subscription holds;
  - the payment names its subscription and carries the subscription's metadata — which is
    how a renewal webhook finds its billable;
  - `sequenceType` is `recurring`;
  - a subscription with `times` ends up `completed`;
  - and it records which way the cadence goes after February for a subscription started on
    the 31st (`month_end_cadence`: `clamp` = back to the 31st/30th, `drift` = stays on the
    28th). The long-run tests model both; this is where it is measured — in March.
  - `pinned`: once it has a charge, every visit PATCHes a start date three days past
    Mollie's own next date and writes the answer down — `start_date_answers`, each with the
    status of the newest charge. The refusal ("…already charged") is the measured rule and an
    observation, not a failure. Once, on a day that is not the subscription's start date, it
    sends **today** (`start_date_today_answer`). If Mollie ever takes a date, the date is
    written to `pinned_to` and the following charges are expected on it; a date taken but not
    held is the only thing reported as a problem.

It stays `incomplete` until the first charge exists and fails, with what it saw, when
Mollie does something the package does not assume.

### Setting it up

```bash
# once — creates the subscriptions and the state file
composer test:sandbox:canary

# then daily, e.g. from cron or a scheduled CI job
0 7 * * *  cd /path/to/package && composer test:sandbox:canary
```

| Variable | Default | |
|---|---|---|
| `MOLLIE_KEY` | from `.env` | must be a `test_` key |
| `MOLLIE_BILLING_CANARY_STATE` | `workbench/storage/sandbox-canary.json` | where the ids and observations are kept — point it at a path that survives your CI's workspace |
| `MOLLIE_BILLING_CANARY_RESET` | — | `1` cancels the subscriptions, revokes the mandate, deletes the customer and removes the state file |

The state file is the canary. Lose it and the subscriptions keep running at Mollie with
nothing looking at them — reset first if you mean to move it.

### What to do and when

The canary that exists today was created on **15 November 2026**. Its ids are in the state
file and nowhere else — they are deliberately not written into the documentation.

**Where the state file is.** By default `workbench/storage/sandbox-canary.json`, inside the
checkout that created it. `workbench/storage/` is git-ignored, so the file is on one machine
only and disappears with that working copy. Before the checkout is thrown away, copy the file
somewhere durable and point `MOLLIE_BILLING_CANARY_STATE` at it (the file is plain JSON;
copying it is enough). A daily run from another checkout without that variable finds no state
and **creates a second canary**.

**When to run.** Once a day, after 07:00 UTC. Mollie's scheduler created the first charges at
00:28 UTC; a run earlier in the night may find nothing new and is still green.

| Date | What Mollie should do | What the run checks that day |
|---|---|---|
| 16 – 25 November 2026 | `daily`: one charge of 1.00 per day, ten in all | each charge created on its own day (UTC), amount 1.00, names its subscription, carries the metadata, `sequenceType: recurring` |
| 25 November 2026 (or the day after) | `daily` has made its tenth charge | the subscription is `completed` — the last thing `daily` can tell; from then on it only repeats |
| 16 November, 16 December, 16 January … | `monthly`: one charge of 34.51 on the 16th | the same five checks; this is the ordinary case and runs for as long as the canary lives |
| 31 December 2026 | `month_end`: first charge | created on the 31st |
| 31 January 2027 | `month_end`: second charge | created on the 31st |
| **28 February 2027** | `month_end`: third charge | the run expects the **28th**. Any other day — 1 or 3 March, say — is red and is itself the finding |
| **28 or 31 March 2027** | `month_end`: fourth charge — the open question | the 31st → `month_end_cadence: clamp` (Mollie keeps the start day and clamps short months); the 28th → `drift` (Mollie adds a month to the previous charge). Either is green; the answer is written into the state file |
| April 2027 | `month_end`: fifth charge (30th or 28th) | confirms the answer of March |
| 17 November 2026 | `pinned`: first charge (00:30 UTC, `pending`) | from this visit on, a later start date is sent and the refusal recorded in `start_date_answers` — measured refused on 17 November |
| **18 November 2026** (first visit after 07:00 UTC) | `pinned`: its start date is the 17th, so today is a different day | `start_date_today_answer`: Mollie's answer to today's date on a charged subscription. Expected: refused |
| every visit until the newest `pinned` charge is `paid` | — | `start_date_answers[].newest_charge_status`: the first row with `paid` answers whether a settled charge lifts the refusal. Expected: refused as before |
| 17 December 2026, 17 January … | `pinned`: one charge of 34.51 on the 17th | the five ordinary checks, as `monthly` |

After each run, `last_checked_at`, the payments seen so far and `last_problems` are in the
state file. `incomplete` is not a failure: it means "created today" or "nothing charged yet".

**The dates that need a person.**

1. **26 November 2026** — look once that `daily` is `completed` with ten payments. Nothing
   else has to be done; the subscription stays in the state file as a record.
   On the same day, read `pinned.start_date_today_answer` (written on 18 November) and the
   newest `pinned.start_date_answers` row. Write both into [decisions.md](decisions.md), entry
   "A charged subscription is replaced to move its next charge", under "Still measured by the
   canary". Either answer needs no code change: the package tries the PATCH and replaces only
   on the refusal, and never sends today. If today was **taken**, also note whether Mollie
   charged that day (a second `pinned` payment) — the package never sends today, so this is
   for the record. If a row with `newest_charge_status: paid` shows **taken**, the refusal
   holds only while a charge is open; the package is right either way.
2. **1 April 2027** — open the state file and read `month_end_cadence` (and the `month_end`
   payments: the fourth charge's `createdAt` is 2027-03-28 → `drift`, 2027-03-31 → `clamp`).
   Write the answer into [decisions.md](decisions.md), entry "Mollie's cadence after a month
   end is measured for the record", in one sentence with the date and the payment id. No code
   change follows from either answer: the package keeps the anchor day itself and sends
   Mollie the date wherever the two answers could differ (`BillingAnchor`,
   `pinNextChargeOnAnchor()`), and `RenewalMonthEndCadenceTest` runs both cadences. Since
   17 November 2026 the package asks Mollie for its own next date first and moves it only
   where that is not the anchor day — moving it means replacing the subscription, because
   Mollie refuses a new start date on a charged one. With `clamp` that happens once at most
   (where the start date itself was February's last day); with `drift` after every shorter
   month. Only a third outcome — the fourth charge on neither the 28th nor the 31st, or
   February's charge not on the 28th — is a finding; record it and check
   `pinNextChargeOnAnchor()` against it before changing anything. The canary never PATCHes
   `month_end`, so it measures Mollie's own rule.

**When a run is red.** The failure message lists every deviation, and the same list is in
`last_problems`. Do not adjust the test first — a red canary means Mollie did something the
package assumes it does not.

| Message | What it means for the package | What to do |
|---|---|---|
| "created …, expected the cycle date …" | `SubscriptionPaymentHandler::cycleStart()` anchors the billing period on the charge's `createdAt`. If Mollie creates charges on another day, invoice periods, `nextBillingDate()` and every prorata window are off by that many days | note date and time of the charge from the state file; check whether it is a one-off (a late scheduler run) or a pattern over several days; record it in decisions.md before changing the anchor |
| "charged …, the subscription holds …" | the amount of a renewal is not the amount last sent to Mollie — the premise of every `PaymentAmountMismatch` check | compare with the subscription in the Mollie dashboard (test mode); nothing in the canary ever patches the amount, so this would be Mollie's doing |
| "does not name its subscription" / "metadata was not copied" | a renewal webhook finds its billable through exactly these two fields | highest priority: without them `MollieWebhookController` reports a paid payment without a billable |
| "sequenceType is …" | the handlers route on it | as above |
| "… charges made and the subscription is …, not completed" | only the canary uses `times`; the package does not | record it; no package behaviour depends on it |
| a network error, a 401, or a *skipped* run ("No Mollie test key") | nothing about Mollie's behaviour | fix the environment and run again — a missed day loses nothing, the next run sees all payments made in between |

**Cleaning up, when the canary is no longer needed.** Not before the March answer is written
down. Then:

```bash
MOLLIE_BILLING_CANARY_RESET=1 composer test:sandbox:canary
```

This cancels the four subscriptions, revokes the mandate, deletes the customer and removes
the state file; the run reports `incomplete` ("Canary reset"). Remove the cron entry or the CI
job afterwards — the next ordinary run would otherwise create a new canary. If the state file
has been lost, the reset has nothing to work from: find the customer named "Billing canary —
do not delete" in the Mollie dashboard (test mode) and cancel its subscriptions there.

Everything here runs on a `test_` key, so no money moves: the charges are test-mode direct
debits (`pending` hours after creation, when measured on 16 November).

## The renewal replay canary: what the package books at the period end

`SubscriptionCanaryTest` watches what Mollie does; `tests/SandboxBrowser/RenewalReplayCanaryTest`
watches what the **package** does with it. Mollie cannot reach a Testbench app: every visit polls
Mollie and replays each payment it has not seen into `MollieWebhookController` — the same form
POST of `id=tr_…` the route gets. Without a receiver those POSTs are the canary's own; with one
(`CANARY_RECEIVER_URL`, [below](#the-cloud-canary-github-actions-and-a-webhook-receiver)) they
are Mollie's real deliveries, read back from the receiver and replayed in arrival order.

**It runs only with `MOLLIE_BILLING_CANARY_STATE` set.** Without it the test is skipped and
creates nothing (unlike `SubscriptionCanaryTest`, which falls back to
`workbench/storage/sandbox-canary.json`). Its files lie next to that state file:

| File | What it holds |
|---|---|
| `renewal-replay.json` | ids, every payment seen with what was booked for it, the visits, the events, `last_problems` |
| `renewal-replay.sqlite` | the package world: billables, invoices, wallets, coupons, processed webhooks. Migrated on every visit, so a package update brings its migrations along |
| `renewal-replay-invoices/` | the invoice PDFs (`mollie-billing.invoices.disk`) |

**First visit.** Six billables, one per scenario, each built with the package's own services:
a Mollie customer (`MollieCustomerResolver`), a test-mode direct-debit mandate
(`CreateMandateRequest` — the one thing no package service creates; in production it comes
from a first payment), a Mollie subscription through `CreateSubscription` with a paid period
that ends tomorrow (so Mollie's first charge is tomorrow), the quota through
`WebhookSupport::creditPeriodQuota()`. Then per scenario:

| Scenario | Set up on the first visit | Asserted |
|---|---|---|
| `renewal` | 30 `tokens` (no rollover) and 20 `credits` (rollover) used | per paid renewal: one invoice of kind `subscription` at the amount Mollie collected (24.00), its period starting on the charge's `createdAt` (UTC), `tokens` back at the quota 200, `credits` = before + 50, status `active`, Mollie's `nextPaymentDate` = `nextBillingDate()` |
| `overage` | 230 `tokens` used (30 over) before the jobs; 20 more after them | first visit: the night-before sweep charges 3.60, its invoice (kind `overage`) settles the wallet to 0. At the renewal: the renewal itself collects the 20 units the sweep never saw (2.40), `tokens` right after the renewal −20 + 200 = 180 (the debt kept while its charge settles), 200 once it is paid |
| `scheduled_change` | downgrade to `canary_basic` scheduled for the period end (`UpdateSubscription`, `apply_at = end_of_period`) | first visit: Mollie already holds 12.00. At the renewal: charged 12.00, plan `canary_basic`, nothing scheduled any more, `tokens` = 100, Mollie and the package agree on the next date |
| `recurring_coupon` | 50 % for two periods, redeemed in the portal (`RedeemCouponFromPortal`) | first visit: Mollie holds 12.00. Renewals 1 and 2: 12.00 with one coupon line; from then on Mollie holds 24.00 and the marker is gone; renewal 3: 24.00, no coupon line; redemption rows = 1 + discounted renewals |
| `cancel_at_period_end` | `CancelSubscription` at the period end | first visit: `cancelled`, ends on the period end, Mollie `canceled`. Never a payment; `expired` after the first nightly pass past the end |
| `failed_renewal` | — | its first renewal is set to `failed`: `past_due`, no invoice, `PaymentFailed`; `past_due_max_days` is 2 in this world, so the first nightly pass after two days cancels (Mollie's subscription cancelled too), the next expires |

Everywhere: no `PaymentAmountMismatch`, the webhook answers 200, a second delivery of the same
outcome changes nothing, and a visit with nothing new from Mollie and no jobs changes nothing.

**Every visit:** list each customer's payments; a payment still `pending` is replayed once as
it is (nothing may be booked), then settled through its `changePaymentState` page in Chrome
(`paid`, or `failed` for the dunning scenario), replayed, and delivered a second time. Booking a
payment can create another (the renewal's overage), so this repeats until a round finds
nothing. The jobs the scheduler runs (`PrepareUsageOverageJob`, `ProcessTrialLifecycleJob`,
`RefundUnassignedPaymentsJob`, `CleanupStalePendingProrataChangeJob`,
`CleanupStalePendingOverageChargeJob`, `CleanupStalePendingCountryCorrectionJob`,
`SweepPendingRetriesJob`, `CleanupOrphanedBillablesJob`) run **once per UTC day**
(`jobs_ran_on`), as the scheduler would — a second visit the same day only replays.

**Why monthly and not "1 day".** Mollie takes `1 day`, but the package knows only monthly and
yearly and books a month for every charge; a daily Mollie subscription would test nothing the
package promises. So the renewals are a month apart: the first on the day after the first
visit, the coupon's full-price charge is the third. Most visits are therefore `incomplete`.

**Results.** `incomplete` while there is nothing new; red, with the list (also in
`last_problems`), when the package booked something it does not promise. Each payment row in
`renewal-replay.json` says what was replayed and what came of it — status, HTTP answers, the
invoice (serial, gross, period, lines), wallets before and after.

| Variable | |
|---|---|
| `MOLLIE_BILLING_CANARY_STATE` | required; the files above are written next to it |
| `MOLLIE_BILLING_CANARY_RESET=1` | cancels the subscriptions, revokes the mandates, deletes the customers and removes the three files. The launchd runner runs both canaries, so a reset through it resets both — run one file to reset one |
| `CANARY_RECEIVER_URL`, `CANARY_RECEIVER_READ_TOKEN` | the webhook receiver (below). Unset: webhook URLs on `https://example.com`, every delivery synthetic — as before |
| `CANARY_RECEIVER_WAIT_SECONDS` | how long a visit waits for Mollie's delivery after a payment reached its final status; default 300 (60 once one was missed in the visit) |
| `MOLLIE_BILLING_RENEWAL_REPLAY_FIRST_CHARGE=today` | a dress rehearsal in a **throwaway** directory: the first charge today (Mollie created it 14 minutes later on 26 November). The night-before sweep and the cancel's grace are then already behind it, so `overage` and the next-date checks report problems that belong to the rehearsal |

The world that exists was created on **26 November 2026** (first charges 27 November) in
`~/Library/Application Support/mollie-billing-canary/`, run daily at 09:15 by the launchd job
there (`run.sh` filters `SubscriptionCanaryTest|RenewalReplayCanaryTest`, from the main
checkout). It moves to the cloud canary below; **the launchd job is retired once the cloud
canary has run green** — two runners on one world would settle and book the same payments twice.

### The cloud canary: GitHub Actions and a webhook receiver

Decided by the maintainer on 27 November 2026: the canary runs for twelve months and more without
anybody's Mac, and observes Mollie's **real** webhook deliveries, Mollie's own retries included.

```
Mollie test mode ──POST id=tr_…──▶ receiver (always on, own repository) ──GET /deliveries──▶ ┐
      ▲                                  records, answers 200 / probe failures                │
      │ API (MOLLIE_KEY)                                                                       ▼
      └──────────────── renewal-canary.yml, daily 08:15 UTC: RenewalReplayCanaryTest ── replays into
                         world on branch canary-state ◀── commit ──┘            MollieWebhookController
```

**The receiver** is its own repository, `mollie-canary-receiver` (a plain PHP app: SQLite on a
persistent volume, no framework; deployed on ploi.cloud — its README holds the endpoints, the
settings and what is still to verify there). It records every POST and answers `200`; on
`/probe/{503|500|404|410}/{n|always}/…` it fails the first `n` attempts on purpose, on
`/probe/timeout/{n}/…` it holds the answer back past Mollie's timeout (off by default there).
`GET /deliveries` lists what it got, behind a bearer token.

**What Mollie documents** ([docs.mollie.com/reference/webhooks](https://docs.mollie.com/reference/webhooks),
read 27 November 2026): a form `POST` with `id`, `User-Agent: Mollie HTTP client/1.0`; only
`200` is success; calls time out after **15 seconds**; **10 calls** in all at increasing
intervals, the tenth **26 hours** after the first; a redirect loses the POST unless 307/308.
The probes measure whether test mode does that.

**What a visit does with the receiver**, on top of the replay above:

- every URL the package builds has the receiver's root, so new subscriptions and the overage
  charges post there;
- subscriptions created before (the world of 26 November) are PATCHed once with **only** their
  `webhookUrl` (`MollieCall::send`, never a start date) — recorded in
  `renewal-replay.json` → `receiver.webhooks`;
- each payment the visit books (one whose own `webhookUrl` is the receiver's) needs a real
  delivery at or after its final status change — after the settle on Mollie's test page, or its
  `paidAt`/`failedAt`. The visit polls up to `CANARY_RECEIVER_WAIT_SECONDS`; none →
  **"Mollie did not deliver"** (red), and a synthetic POST so the remaining checks still run.
  The real deliveries are replayed in arrival order; the payment row's `delivery` says which,
  when the first arrived and how long after the status change;
- a delivery of a payment booked earlier (Mollie posting again) is replayed too and must change
  nothing while the payment's status is the booked one (`late_deliveries`);
- deliveries of payments not in this world are counted (`receiver.foreign_deliveries`), not
  replayed; the cursor (`receiver.cursor`) moves past everything read.

**Retry probes.** On the first visit with a receiver, then on Mondays when none is in flight,
the visit creates standalone test payments of 1.00 € — no billable, no package code — and pays
them on Mollie's hosted test page: webhook `…/probe/503/3/<tag>-503x3` (fails three times, then
200), `…/probe/500/always/<tag>-500always` (never recovers) and, only when the receiver reports
the timeout probe enabled, `…/probe/timeout/2/<tag>-timeout2`. Thirty hours after the last was
paid (Mollie's 26-hour window plus margin) a visit reads their attempts: count, outcomes,
intervals, span, whether a 200 ended the chain. The **first** reading is the baseline
(`retry_probes.baseline`, with where it differs from the documentation) — copy it into
[decisions.md](decisions.md), entry "The renewal replay canary moves to GitHub Actions", the day
it appears ("Retry baseline measured" in the visit's observations). A later reading that differs
— another count, another outcome, a call after a 200, an interval off by more than
max(120 s, 50 %) — is red: **"Mollie changed its retry behaviour"**.

**Setting it up** (once):

| Where | Name | Value |
|---|---|---|
| repository secret | `MOLLIE_KEY` | the `test_…` key (the test refuses anything else) |
| repository secret | `CANARY_RECEIVER_READ_TOKEN` | the receiver's `READ_TOKEN` (`openssl rand -hex 32`) |
| repository variable | `CANARY_RECEIVER_URL` | `https://<receiver host>`, no trailing slash — Settings → Secrets and variables → Actions → *Variables*, or `gh variable set CANARY_RECEIVER_URL --body https://…` |
| the receiver | `READ_TOKEN` | the same token |

Moving the existing world: put `renewal-replay.json`, `renewal-replay.sqlite` and
`renewal-replay-invoices/` from `~/Library/Application Support/mollie-billing-canary/` on the
branch `canary-state` (an orphan branch: `git switch --orphan canary-state`, copy the three,
commit, push), **stop the launchd job** (`launchctl unload` its plist), then run the workflow by
hand once. Without that branch the first visit builds a new world — reset the old one first
(`MOLLIE_BILLING_CANARY_RESET=1` locally against its directory), or it charges on with nobody
looking.

**Running it.** Daily at 08:15 UTC; Actions → *renewal canary* → *Run workflow* for a visit now,
with `reset: yes` to cancel everything at Mollie and delete the world (the next visit builds a
new one). `concurrency: renewal-canary` keeps two visits from overlapping. Scheduled workflows
run **only from the default branch**, and GitHub disables a public repository's schedule after
**60 days without repository activity** ([GitHub docs](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule));
the daily commit to `canary-state` is meant to be that activity (to verify: GitHub does not say
whether a bot's commit on a non-default branch counts — if the schedule is ever disabled, enable
it under Actions and note it here).

**Reading a red run.** A red visit — the test failed, or left `last_problems` — opens one issue
labelled `canary` with the problems, what was seen and the end of the log; every further red
visit comments on it; the next green visit closes it. The full log and Mollie's pages
(screenshots of a failed settle or checkout) are the run's artifact for 30 days; the world, with
every payment row, is on `canary-state`. The messages specific to the receiver:

| Message | What it means | What to do |
|---|---|---|
| "Mollie did not deliver — …" | Mollie reached a final status and posted nothing to the receiver within the wait | check the receiver is up (`GET /`) and its log; check the payment's `webhookUrl` in the dashboard; a single miss followed by a late delivery (next visit: "delivered … again") is Mollie being slow, not silent |
| "Mollie's later delivery … changed the world although the payment is still …" | a re-delivery booked something — the package's idempotency failed against a real delivery | a package bug: reproduce it in a feature test first |
| "Mollie changed its retry behaviour (…)" | the probe's attempts differ from the baseline | compare with Mollie's documentation and changelog; the package itself does not depend on the schedule — it answers 503 to make Mollie retry, so a shorter chain matters |
| "Mollie did not deliver to the retry probe …" | no call at all to a probe URL | as the first row |
| an HTTP error from `…/deliveries` | the receiver is down or the token differs | fix the receiver; a missed day loses nothing, the next visit reads all since the cursor |

**Public by design.** The repository is public, so are `canary-state` and the issues: test-mode
ids, `@example.test` addresses, invoice PDFs of fictitious companies. Nothing there moves money.
The receiver's URL is public with it: anybody can POST to it. A forged delivery for a payment of
this world would be replayed like Mollie's (it carries nothing but an id, and the package fetches
the payment from Mollie), and could hide a real "Mollie did not deliver"; its headers are kept
in the receiver for that case.

**Cost.** GitHub-hosted runners are free for a public repository and test-mode Mollie calls
cost nothing; the receiver's host (ploi.cloud, the smallest container size) is the only running
cost — not priced here; read it off the host's pricing page when the plan is chosen.

### What the test mode does with a recurring payment (measured, 26 November 2026)

| Question | Answer |
|---|---|
| Does a recurring **direct debit** settle by itself? | **No.** 153 test-mode recurring direct debits on the account, the oldest from 17 April 2026: all still `pending` (two `canceled` by hand). The `details.dueDate` keeps moving ahead. |
| A recurring **card** payment? | `paid` within a second of creation, subscription charges included (266 on the account, none failed on its own; the page below exists for them too, `method=creditcard`, not tried) |
| Can a recurring payment be settled on purpose? | Yes: a test-mode recurring payment carries `_links.changePaymentState` → `…/checkout/test-mode?method=directdebit&token=…`, a form with `final_state` radios `paid` and `failed`. `failed` arrives with `details.bankReasonCode` `MS03` ("Reason not specified") |
| Shortest interval | `1 day` (also `2 days`, `1 week`); `12 hours` → 422 "The interval unit is invalid", `0 days` → 422 "The interval is invalid" |
| PATCH of only `webhookUrl` on a subscription (27 November 2026) | taken before the first charge and after it; start date and `nextPaymentDate` unchanged; the next charge carries the new URL |
| A placeholder `webhookUrl` (`https://….invalid/…`) | **422** "The webhook URL is invalid because it is unreachable from Mollie's point of view" — for a payment and a subscription, in test mode. `http://example.com/…` is taken |


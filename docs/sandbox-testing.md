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

Everything the first two create is torn down after each test (`trackMollieResource()`):
subscriptions cancelled, mandates revoked, customers deleted. The canary is the exception —
surviving is its job.

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
  (`createdAt`, `paidAt`, status over time), and that test mode cannot produce a chargeback.
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

# Plan Changes

This document describes how plan changes work in `laravel-mollie-billing`, including the deferred upgrade flow, validation rules, events, and extension points.

## Overview

Plan changes are handled by `UpdateSubscription::update()` and come in three flavors:

| Type | Trigger | Timing |
|------|---------|--------|
| **Upgrade** (higher price, Mollie) | User selects a more expensive plan on a Mollie subscription | Deferred until prorata payment confirmed |
| **Local → Mollie upgrade** | Free-plan user selects a paid plan | Routed to `UpgradeLocalToMollie`, applied via webhook |
| **Downgrade** (lower price) | User selects a cheaper plan | Immediate or scheduled to end of period |
| **Mollie → Free downgrade** | Paid user selects a free plan | Cancels Mollie subscription, switches source to Local |
| **Past-Due reset** | User on `past_due` selects any paid plan (same or different) | Charges the full first period of the new plan, resets the cycle |
| **Change during a trial** | Seats, addons or quantities while `subscription_status = trial` | Immediate and **free** — no charge, no credit. The trial keeps running; Mollie is PATCHed so the first real charge collects the new amount. A plan or interval change is *not* this case: it ends the trial and is charged. |
| **Zero-cost change** | Same price, different plan/interval | Immediate, booked as a saldo-zero switch invoice settled against the paying one |
| **Free → Free change** | Both source and target are free plans | Immediate, source stays Local |

### Two changes inside one period

A mid-period change is *priced* against the whole period (`newNet × remaining / total`) but
*buys* only the days that are left, and the line it writes is stamped with the window it
actually bought — `[now, period_end]`, not `[period_start, period_end]`. That stamp is what a
later change in the same period reads: `ProrataComposer` derives each credit from the line's
own window, so an amount that bought 21 days is divided by 21 days, not by 31.

A change whose two sides cancel out is still booked. `BillingPolicy::computeProrata()` nets
charge against credit, so an equal-price plan switch reports 0/0 — but the composer produces a
full charge for the new plan and a matching credit for the old one, and those have to be
recorded: the old plan line's remaining value now belongs to the new plan code, so it is no
longer refundable. `InvoiceService::createPlanSwitchInvoice()` writes them as one invoice whose
header nets to zero and increments `refunded_net` on the invoices it credits.

That switch invoice has no `mollie_payment_id` of its own — the new plan's days were paid for
out of the *previous* invoice, and `mollie_payment_id` is unique because that uniqueness is the
webhook's idempotency guard. The settlement is therefore recorded as a relationship:
`billing_invoices.settled_by_invoice_id` points at the invoice whose payment covers it, set only
when there is exactly one such invoice and it covers the switch's charges in full.

Everything that has to answer "can this be refunded, and against what" follows that pointer:

- `BillingInvoice::currentPeriodLines()` offers an invoice as a refund candidate if it collected
  a payment **or** is settled by one that did.
- `refundableBaseNet()` / `refundableBaseGross()` read a settled invoice line by line (its own
  charges, i.e. lines without a `parent_invoice_id`) rather than from its header, which nets to
  zero by construction. Every other invoice is still measured by its header — that is the money
  Mollie actually took, and lines can legitimately state something else.
- `InvoiceService::createRefund()` posts to `refundablePaymentId()`, so the refund lands on the
  payment that physically holds the money.

The books stay balanced across the pair: a switch only gets the pointer when its charge is fully
covered by credits booked against the source invoice, so the source's remaining refundable base
drops by exactly what the switch's base gains. A 2900 renewal switched on day 11 leaves
`935 + 1965 = 2900`, and a downgrade on day 20 credits `1965 × 12/21 = 1123` out of the switch.

If the pointer cannot be set (several source invoices, or a charge larger than its credits) the
switch stays unrefundable, exactly as before.

## Deferred Upgrade Flow

Upgrades on Mollie subscriptions with a prorata charge > 0 are split into two phases to ensure the plan is only changed after payment confirmation.

```
Phase 1 — User initiates upgrade (UpdateSubscription::update(), synchronous)
  Under the cache lock billing:subscription-change:{id}; no Mollie call inside a transaction.
  1. Build SubscriptionChangeContext (compute seats, addons, prorata, etc.)
  2. ValidateSubscriptionChange::validate()
     - Target plan: throw HiddenPlanNotSelectableException for hidden plans
     - Seats: auto-derive or throw SeatDowngradeRequiredException
     - Addons: strip incompatible
     - Mollie readiness: mandate, subscription ID, no pending change
  3. ProrataComposer builds the charge and refund lines from a PlanChangeIntent
  4. ProrataExecutor creates ONE Mollie recurring payment for all charge lines
     (metadata type = prorata_charge)
  5. Store pending_prorata_change (intent, lines, charge_payment_id) and
     pending_plan_change (coupon codes to redeem later) in subscription_meta
  6. Dispatch PlanChangePending event
  7. Return { pendingPaymentConfirmation: true }
  -> Plan is NOT changed. (A seat or addon increase, by contrast, is active at once
     and PATCHes Mollie in the same call.)

Phase 2a — Webhook: payment succeeded
  MollieWebhookController routes type = prorata_charge to ProrataChargeHandler::paid():
  1. Guards: a payment for an undercollected period goes to UndercollectionCharge;
     an invoice that already exists for the payment ends a re-delivery; the payment
     must be the one named in pending_prorata_change; the subscription must still run
     (otherwise the payment is booked and returned by UnwantedPaymentRefund)
  2. Create the BillingInvoice for the charge lines
  3. Update plan, interval, addons, seats in the database; rebalance wallets
  4. Redeem the coupons Phase 1 deferred
  5. PATCH the Mollie subscription in place (MollieSubscriptionPatcher::updateForIntent(),
     UpdateSubscriptionRequest) — no cancel + create
  6. Pay out the refund lines stored with the pending change (InvoiceService::createRefund())
  7. Clear the pending state
  8. Dispatch PaymentSucceeded, PlanChanged, SeatsChanged, AddonEnabled/Disabled,
     SubscriptionUpdated

Phase 2b — Webhook: payment failed
  ProrataChargeHandler::failed():
  1. pending_prorata_change / pending_plan_change removed
  2. plan_change_failed_at and plan_change_failed_reason stored in meta
  3. Seats and addons that were active already are put back; Mollie's recurring
     amount is reverted
  4. PlanChangeFailed event dispatched
  5. PlanChangeFailedNotification sent to billing admins
  -> Plan remains unchanged
  Note: if the local pending state is already gone when the failed/expired
  webhook arrives (user clicked "cancel pending change" first), the webhook
  is a silent no-op — no failed marker, no event, no notification.
```

The same flow as a sequence diagram: [flows.md, section 8](flows.md#8-plan-and-interval-change).

**Legacy payment types.** Before the collective prorata charge, a mid-cycle change created a
payment of type `prorata`, `addon` or `seats`. The webhook still accepts those —
`SingleChargeHandler` books them and applies a `pending_plan_change` through
`UpdateSubscription::applyPendingPlanChange()` — so a payment created by an older release
settles correctly. Nothing in the current code creates them.

## Validation Rules

All validation is centralized in `ValidateSubscriptionChange`. It runs in both Phase 1 (pre-flight) and Phase 2 (before applying). Apps can override it via `app->bind`.

Two rules are worth calling out because they are easy to trip over:

- **One pending change at a time.** A change that moves money (a prorata charge *or* a credit) is refused while `pending_plan_change` / `pending_prorata_change` is set. This covers credit-only downgrades, not just charges — a second change keyed to the first one's payment id was applied as the first one's intent and lost without a trace.
- **A settled charge is never applied to a subscription that no longer runs.** If the billable is Cancelled or Expired by the time Phase 2 arrives, the plan is not applied: the money is invoiced, `PlanChangeFailed` is dispatched and admins are notified for a manual refund. Applying it would resurrect the plan-scoped fields the expiry wipe removes, handing feature access back to an ended subscription.

### Established Subscription

- A plan change requires an existing subscription. If `subscription_source` is `none` (or unset) — i.e. checkout was never completed, e.g. an abandoned/cancelled first payment — `InvalidSubscriptionStateException` is thrown. Such billables must go through the checkout flow, which obtains a mandate and creates the subscription. The portal's plan screen (`BillingPortalController::plan()`) redirects these billables to checkout for the same reason.

### Seats

**A plan change does not carry seats over.** Without an explicit `seats` it lands on
`max(usedSeats, newIncludedSeats)` — the seats in use, or the seats the new plan includes
(`BillingPolicy::derivedSeats()`). That holds for upgrades and downgrades, immediate and
scheduled, and for the preview (`newSeats`, `extraSeatsCharged`).

Carrying the old count across turned the old plan's *included* seats into paid extras on a plan
that includes fewer: five seats that came with one plan became three paid seats on a plan that
includes two. Paid extra seats nobody sits on are not carried either; what was paid for them is
credited pro-rata like everything else.

- **Keeping more** is an explicit choice: pass `seats`. It is validated as any explicit count
  (not below the seats in use, not above the included seats on a plan that sells none, not above
  `max_seats`). The plan-change screen shows "after the change: N seats" before the customer
  confirms and offers "keep N extra seats" (`keepExtraSeats`, off by default) when the target plan
  sells seats and the customer holds more than the change would leave them with.
- **More seats in use than the new plan can seat** (a plan without `seat_price_net`):
  `SeatDowngradeRequiredException`, as before — the members have to be released first. The
  preview reports `seats_exceed_plan`.
- **An interval change, a seat change, an addon change** leave the seat count where it is (never
  below the seats in use or included).
- A scheduled change stores the derived count for display and re-derives it when it is applied.

### Addons

- On plan change, addons not allowed by the new plan (`catalog->planAllowsAddon()`) are automatically stripped.
- Stripped addons appear in the `addonsRemoved` diff and trigger `AddonDisabled` events.

### Wallet Usage

- For each wallet: if used quota exceeds the new plan's included quota, overage is charged via `ChargeUsageOverageDirectly`.
- Requires a Mollie mandate; throws `DowngradeRequiresMandateException` if missing.

### Mollie Readiness (upgrades only)

- `hasMollieMandate()` must be `true`
- `mollie_subscription_id` must exist in `subscription_meta`
- No `pending_plan_change` may already exist (only one deferred upgrade at a time)

### Local Subscription Guards

When the current source is `local`, `validateLocalSubscriptionExtras()` enforces:

- **Pseudo-upgrade block:** switching to a paid plan via `UpdateSubscription` throws `LocalSubscriptionUpgradeRequiresMolliePathException`. The correct path is `UpgradeLocalToMollie` (the bundled plan-change UI does this automatically).
- **Paid-extras block:** adding paid add-ons (`addonPriceNet > 0`) or paid extra seats (`seat_price_net > 0`) throws `LocalSubscriptionDoesNotSupportPaidExtrasException`. Free add-ons (price 0) and the included seat count remain available.

These guards make the legacy bug where a free plan could be silently flipped to a paid one (without any payment) impossible to reproduce — including from admin tooling.

## Prorata Calculation

All prorata calculations are centralized in `BillingPolicy::computeProrata()`. Both `UpdateSubscription` and `PreviewService` delegate to this single method.

### Nothing is prorated during a trial

A trial is free, and everything the current plan allows is part of what is being tried. Seats and addons added during one are **not charged**, and taking them away again credits nothing: no money was paid for the window they are added to, so there is no fraction of anything to prorate. Mollie is still PATCHed to the new amount, so the first real charge — at the trial end — collects it in full.

A **plan or interval change is the exception**: it ends the trial and is charged. That is a product decision, not an arithmetic one — testing one plan does not entitle anyone to test a different one for the rest of the same trial.

`BillingPolicy::isFreeTrialAdjustment()` is the single predicate, for the same reason `isPastDueReset()` is: `ProrataComposer`, `UpdateSubscription::buildContext()` and `PreviewService` all have to give the same answer. Before it existed the composer and the context disagreed with the preview — the preview quoted €0 for extra seats during a trial and the change then collected the prorated amount anyway, converting the trial with it.

### A credit is prorated from what was paid

An invoice line states the *list price* of its component; whatever reduced the payment sits on a
negative line of its own — a recurring coupon (`kind = coupon`) or the `adjustment` that books an
invoice down to what Mollie collected. `ProrataComposer::paidLineNet()` spreads those reductions
over the charge lines in proportion, so each line is worth `list × (charges − reductions) / charges`
and the credit is the unused share of *that*. A period bought with a 50 % coupon (plan 2900,
coupon −1450) and left on day 16 of 31 credits `1450 × 16/31 = 748`, not the whole 1450. A
surplus (positive adjustment) never raises a component above its list price.

### The running discount is on both sides of a change

A recurring coupon or a [plan waiver](coupons.md) lives in one slot
(`subscription_meta.active_recurring_coupon`) and every *recurring* amount honours it. So does
the prorata charge: what a change owes is the difference between two recurring amounts for the
days that are left, and the credit for the old configuration is already the unused share of what
was *paid* — with the discount in it.

| Change | Discount on the charge |
|---|---|
| Plan change, interval change, past-due reset (the whole new configuration is charged) | what `CouponService::computeMarkerDiscount()` gives the **new** configuration, × the charge's factor |
| Seats, addons (only what was added is charged) | what the discount **grows by** between the old and the new configuration, × factor |

For a coupon locked to its base amount the second row is nothing — a seat added on top is paid in
full, now and at every renewal. For a waiver it is the granted addon coming back: switching a
gifted addon off and on again costs nothing either way. The line is booked as `kind: 'coupon'`
on the charge; a waiver's carries `applies_to`, exactly as on a renewal.

Under a waiver that makes a plan change read as it should:

- **Upgrade** (Pro 50.00 free → Enterprise 200.00, day 11 of 31): nothing is credited for Pro — it
  was paid with 0 — and Enterprise is charged `(200.00 − 50.00) × 21/31 = 101.61`. The waiver
  keeps taking off what it was given for, never more. The next renewal is 150.00.
- **Downgrade** (Pro free → Starter 20.00): nothing is charged and nothing is credited for the
  plan; paid extras are credited and charged again for the same days. The waiver is capped at
  Starter's price and does not spill onto the extras.

A charge the discount covers **in full** is not a charge: the composer emits no charge lines at
all, so the change takes the credit-only path (credits are paid out at once) or books nothing.
`ProrataExecutor` guards the same case on its own — a charge of 0.00 creates no payment, and
credits parked "until the charge clears" would never be paid out.

When a change opens a **new period** (interval change, past-due reset, trial conversion) the next
recurring charge is a full interval away. Mollie is PATCHed to the amount of *that* charge, so a
coupon that is live today but over by then is dropped before the PATCH — the renewal handler's
own rule (`markerExpired($billable, $newPeriodEnd)`), applied at the one other place a period
begins. The charge made today is still discounted.

### An interval change is refused while a plan waiver runs

A waiver is denominated in the interval it was given in — its amount is that interval's plan
price, its end a date or a number of periods of that length — and none of it translates: monthly
→ yearly charged a year minus one month's price for a plan held as a gift, and a period-based
gift became twelve times as long. `ValidateSubscriptionChange::assertIntervalChangeAllowed()`
refuses it (`InvalidSubscriptionStateException`), immediately and as a scheduled change; the
preview reports `errors: [{type: 'interval_change_under_waiver'}]`. Plan, seat and addon changes
are unaffected.

A coupon entered with a change while a waiver runs is refused by `CouponService::validate()`
before anything is charged, patched or written.

### A free plan with paid addons is prorated like any other

`isFreePlan()` looks at base and seat price only, so it is also true for a free plan that carries
a paid addon — and that one bills through Mollie. The composer only skips a free origin when the
subscription is **Local** (Free → paid runs through `UpgradeLocalToMollie`). On a Mollie
subscription an addon added mid-period is charged and one removed is credited; and a downgrade
*onto* a free plan charges the addons it keeps, since the refund side has just credited them.

### Seats and addons: local state and Mollie move together

A seat or addon change writes `seat_count` / `active_addon_codes` at once, even while its prorata
charge is still in flight. Mollie's recurring amount is PATCHed in that same call — not when the
charge clears — so a renewal in between is collected for what is active. If the charge is
declined, `ProrataChargeHandler::failed()` restores the local state *and* PATCHes Mollie back.
A plan or interval change is different: it switches locally only once its charge has cleared, and
its PATCH stays with that switch in Phase 2.

`CleanupStalePendingProrataChangeJob` — the sweep for a prorata charge whose webhook never
arrived — hands a `failed`/`canceled`/`expired` charge to the same `failed()` handler, so seats,
addons and Mollie go back together there too.

### Addons priced per seat

An addon with a `seat_price_net` has two lines — `addon` (base) and `addon_seats` (per seat,
quantity = seats beyond the addon's `included_seats`, see
[configuration](configuration.md#per-seat-addon-pricing)) — and they move on different axes:

| Change | `addon` line | `addon_seats` line |
|---|---|---|
| Addon switched on mid-period | charged pro-rata | charged pro-rata for every charged seat |
| Addon switched off mid-period | credited pro-rata | credited pro-rata, all of it |
| Seats changed, addon stays | untouched | the **difference in charged seats** only — with 5 free addon seats, 4 → 6 seats charges exactly one |
| Plan or interval change | credited and charged again, like everything else | credited and charged again |
| Past-due reset | full first period | full first period |
| Trial (seats/addons) | free | free; the first real charge carries it |

Credits are taken newest line first, like the plan's seat line, and are prorated from what was
paid for the line (coupons and adjustments included). `BillingInvoice::deriveSeatCount()` reads
the plan's `seats` line only — an `addon_seats` quantity is not a seat count.

All of it is priced by `SubscriptionAmount` (`net()`, `lineItems()`, `addonChargedSeats()`), which
the preview, the prorata composer, the Mollie subscription amount and the renewal invoice share.

### A period collected at less than it costs is charged the difference

Two cases the package recognises with certainty:

| `RenewalUndercollected::$reason` | What happened | Where it is recognised |
|---|---|---|
| `renewal_created_before_change` | A seat or addon was booked after Mollie had already created the next debit. That renewal settles at the old amount while the new configuration is active for the whole period. | `SupersededRenewalRefund::settle()`, on the renewal: the invoice carries a negative `adjustment` **and** the payment's own metadata names another configuration. |
| `plan_change_spanned_renewal` | A plan change's prorata payment (SEPA) was still pending when the period renewed. The renewal was collected for the old plan; the change then took effect inside the period it had paid for. | `ProrataChargeHandler::paid()`, in Phase 2: the same change is composed as if it were made now; a positive balance is the shortfall. |

`Services\Billing\UndercollectionCharge` then collects the difference on its own:

1. The case is **claimed** under the billable's row lock
   (`subscription_meta.undercollection_charges`, keyed `reason:invoice id`) — exactly once, also
   across webhook re-deliveries.
2. A **recurring payment on the mandate** is created for the difference plus the VAT that applies
   to the billable (reverse charge included), with an idempotency key that is stable for the
   case. Its metadata is `type: prorata_charge` + `undercollection: <case>`, which routes the
   webhook back to this class.
3. The **customer is told** the amount and the reason (`UndercollectionChargedNotification`).
4. When it settles it gets **its own invoice**, with `completes_invoice_id` pointing at the
   renewal: the balance of a plan change is one line naming the plan; the balance of seats
   and addons is **one line per component** (`seats`, `addon`, `addon_seats`), each marked
   `undercollection`, over the renewal's period. See
   [The balance of seats and addons names its components](#the-balance-of-seats-and-addons-names-its-components).
5. When it **fails**, `SubscriptionPaymentHandler::failed()` runs — the call a failed renewal
   makes. Past due, the payment-failed mail, the auto-cancel clock: the ordinary dunning, no
   second mechanism. A plan or interval change from there is an ordinary change, not the
   past-due reset, and closes the declined balance — see
   [Past due on a paid period](#past-due-on-a-paid-period-is-not-a-reset).

`RenewalUndercollected` is dispatched and audited (`renewal_undercollected`) in every case and
carries `chargePaymentId`. **An admin is told only when the balance cannot be charged
automatically**: the subscription is past due, cancelled or expired, there is no mandate, or
Mollie refused to create the payment (`obstacle` in the notification's context).

Nothing is charged for a difference a discount or waiver explains (both are part of the expected
amount), for a shortfall whose payment metadata matches the current configuration (a PATCH that
did not land — `PaymentAmountMismatch`, as before), or within the one-cent rounding slack.

The mirror image is `SupersededRenewalRefund`: a renewal that collected **too much** is refunded
the difference, and a *downgrade* that spanned a renewal is refunded for the period it lands in.
A renewal has a surplus or a shortfall, never both, so the two directions exclude each other.

### A payment that arrives for nothing is returned automatically

| What happened | Before | Now |
|---|---|---|
| The prorata payment settles after the customer cancelled the pending change (the payment could not be cancelled any more) | admin mail "needs to be refunded manually" | `UnwantedPaymentRefund`, reason `change_cancelled` |
| The prorata payment settles after the subscription ended | admin mail | `UnwantedPaymentRefund`, reason `subscription_ended` (`PlanChangeFailed` still fires) |
| A change is cancelled locally while its payment can still settle | admin mail at once | nothing — if the payment settles, the first row applies |
| The prorata payment of a scheduled change settles after that change was dropped or withdrawn | the change was carried out after all — after the "not carried out" mail | `UnwantedPaymentRefund`, reason `change_cancelled`; see "A failure after the payment exists" below |

`Services\Webhook\UnwantedPaymentRefund` is the one place that returns such a payment: it is
**booked** (an invoice with one `unwanted_payment` line for exactly what Mollie collected) and
**credited** (a credit note through `InvoiceService::createRefund()`, carrying the Mollie refund
id), so net and VAT come back to zero at whatever rate applied. The customer gets
`UnwantedPaymentRefundedNotification`; `UnwantedPaymentRefunded` is audited. It is safe to
repeat — invoice unique per payment, refund skipped once credited or queued
(`pending_refund_retries` → `RetryRefundLineJob`), announcements once per payment — and a
re-delivery picks an unposted refund up again. **An admin is told only when the refund could not
be posted** and is queued for retry.

### A scheduled change that cannot be applied is resolved without an operator

`ScheduleSubscriptionChange::apply()` no longer mails the admins per failure and no longer fails
the nightly job:

- **Refused by the rules** (seats in use, an outstanding payment, a plan/interval/addon that is no
  longer available): dropped at once, the customer is told why
  (`ScheduledChangeNotAppliedNotification`). Not retried — it would be refused again tomorrow.
- **Anything else** is taken to be transient. The change stays, `scheduled_change.apply_attempts`
  counts up and the dispatcher brings it back the following night.
- **After `MAX_APPLY_ATTEMPTS` (3)** it is dropped, the customer is told, and only then the
  admins.

Dropping goes through `cancel()`, so an amount announced to Mollie is taken back.
`SubscriptionChangeApplyFailed` is dispatched once, when the change is given up.

#### A failure after the payment exists

`update()` can throw after `InvoiceService::createCharge()` has returned — an app listener on
`PlanChangePending`, a failed write. The prorata payment is then in flight at Mollie and
`pending_prorata_change` names it, while `scheduled_change` is still stored and is tried again.

| Question | Answer |
|---|---|
| Does the next attempt create a second payment? | No. `ValidateSubscriptionChange` refuses a change while another waits for its payment (`InvalidSubscriptionStateException`), before anything reaches Mollie. The attempt is counted. |
| How does the stored change know the payment is its own? | `apply()` compares `pending_prorata_change.charge_payment_id` before and after the attempt and notes a new id on `scheduled_change.charge_payment_id` (`ScheduleSubscriptionChange::OWN_PAYMENT_KEY`) — also for "apply now", which counts no attempt. A payment that was already waiting before the attempt belongs to another change and is never claimed. |
| The payment settles before the next attempt | Phase 2 (`ProrataChargeHandler::paid()`) carries the change out. The next run finds the target in place: nothing charged, nothing counted, `scheduled_change` cleared. |
| The payment is declined before the next attempt | `ProrataChargeHandler::failed()` clears the waiting change as for any declined charge. The stored change stays and the next run makes a new attempt with a new payment. |
| The stored change is given up (attempts used up, refused by the rules, cancelled by the customer or the operator) | `cancel()` calls `UpdateSubscription::withdrawPendingChangeForPayment()`: the payment is cancelled at Mollie if Mollie still allows it, `pending_prorata_change` / `pending_plan_change` / `prorata_pending_payment_id` are cleared, and what a seat or addon change had already written is put back — locally and at Mollie (`ProrataChargeHandler::restoreUnpaidChange()`, the same step a declined charge takes). |
| …and the payment could not be cancelled, or had settled already | `orphaned_prorata_payment` is kept (`not_cancelable`, `already_settled`, `lookup_failed`). When the payment arrives it is booked, credited and refunded in full by `UnwantedPaymentRefund` (`change_cancelled`); the plan stays; the customer gets `UnwantedPaymentRefundedNotification`. A re-delivery does none of it twice. |
| …and the payment had already failed, expired or been cancelled | Nothing is kept. The late `failed` webhook finds no waiting change and is a no-op — no `PlanChangeFailed`, no second mail. |
| A *different* change is waiting for its payment when the scheduled one is given up | Untouched. Only the payment noted on the stored change is withdrawn. |
| Mollie could not be asked when the change was given up (`lookup_failed`) | Withdrawn all the same. The daily `CleanupStalePendingProrataChangeJob` asks again: cancelled if Mollie still allows it, forgotten if dead, otherwise marked `not_cancelable` and returned when it settles. |

The customer is told about the refund up front: `ScheduledChangeNotAppliedNotification` takes
`$paymentOnItsWay` and adds a sentence, and `droppedNotice()` reports `payment_on_its_way` for
as long as the marker exists, i.e. until the payment has been returned.

#### A payment whose creation went unanswered

Mollie can accept the payment and the answer can be lost (a timeout), or the write that records
it can fail. Then nothing points at the payment, and the next attempt used to create a second
one. An idempotency key cannot close this — Mollie keeps a key for one hour and the next attempt
is a night away — so `InvoiceService::createCharge()` writes a note before it asks Mollie
(`prorata_charge_in_creation`: a token, the charge and refund lines, the intent) and puts the
token into the payment's metadata (`charge_token`). The write that records the payment removes
the note. A note that is still there is resolved against the customer's payment list at Mollie
(`InvoiceService::resolveChargeInCreation()`), the way `CleanupStalePendingOverageChargeJob`
finds a lost overage payment:

| Who finds it | What happens |
|---|---|
| The next attempt, for the **same** change | The payment is adopted as the change's pending payment (`adoptChargeInCreation()`); no second one is created. Its lines are the ones it was charged for. |
| The next attempt, for a **different** change | The lost payment is cancelled where Mollie allows it; the new change is charged and applied normally. A lost payment that can no longer be stopped is claimed by nothing and returned in full by `UnwantedPaymentRefund` when it settles. |
| The payment's own webhook, first | `ProrataChargeHandler::paid()` adopts it and carries the change out — once; the next attempt finds it in place. A `failed` webhook drops the note. |
| Mollie holds no such payment, or only a dead or already booked one | The note goes; the next payment is created normally. |
| Mollie cannot be asked | Nothing is created — the attempt fails and is retried. An unanswered question is not "none". |
| The scheduled change is given up | Its note is resolved and the payment withdrawn like any waiting change; if Mollie cannot be asked, the note goes anyway, so a late settlement is returned, never applied. |
| Nobody takes it over within 48 hours (`CleanupStalePendingProrataChangeJob::CHARGE_IN_CREATION_LIMIT_HOURS`) | The daily pass cancels the payment where possible and drops the note; a late settlement is returned. |

### Day Granularity

Billing uses **whole calendar days** (`startOfDay()`). A change made on the same day as the period start yields a factor of exactly `1.0` — the current day counts as a full remaining day.

**The window is the period Mollie actually bills.** A subscription keeps the day of the month it is charged on through the months that do not have it (`Support\BillingAnchor`): a cycle on the 31st runs 31 Jan → 28 Feb → 31 Mar → 30 Apr. So the window from 28 February is 31 days long, not 28, and an addon added on 14 March is prorated over 17 of 31 days. `nextBillingDate()` — and with it every prorata window, the portal's next charge and the invoice period — ends on that anchor day, and the renewal sends Mollie the same date wherever Mollie's own rule after a short month could differ ([flows, section 6](flows.md#6-renewal)).

### Same-Interval Changes

When the billing interval stays the same (e.g. monthly → monthly):

- **Upgrade** (`newNet > currentNet`): `charge = (newNet - currentNet) * factor`
- **Downgrade** (`newNet < currentNet`): `credit = (currentNet - newNet) * factor`

**The period boundary does not move**, and neither does the day it is charged on: a change on 28 February of a cycle on the 31st is prorated over 28 Feb → 31 Mar and renews on 31 March (`BillingAnchor`); only an interval change or the past-due reset, which open a new window, start a new day — the day they start. The factor covers only the remainder of the *current* window, and Mollie's charge date is left untouched (the PATCH changes the amount, not the cadence). So `subscription_period_starts_at` stays where it was, and the change's invoice is stamped with the existing period. Phase 2 re-anchoring the period here made `nextBillingDate()` report a date Mollie does not charge on, and a seat added afterwards was prorated against that phantom window while the real renewal billed the same days again.

Two exceptions do open a new window, and both also move Mollie's schedule: an interval change, and the [past-due reset](#past-due-reset). A plan change that converts an in-flight trial is a third case where the *schedule* moves — Mollie's `startDate` is the trial end, and the charge has just paid for the rest of the local period, so the first recurring charge is pushed one full period out.

### Interval Changes

When the interval changes (e.g. monthly → yearly or yearly → monthly), the old and new amounts cover different period lengths and cannot be compared directly. The unused portion of the current period is credited in full. The new plan's first payment is collected by Mollie via the new subscription.

- `credit = currentNet * factor`
- No prorata charge — Mollie collects the full new amount via the recreated subscription.

## Invoice Types

Each subscription change creates an invoice with a specific `invoice_kind` depending on what changed. This ensures invoices are clearly labeled in the billing portal.

| Change | `type` (Mollie metadata) | `invoice_kind` | Description / Label |
|--------|--------------------------|----------------|---------------------|
| Plan, interval, seat or addon change with a charge | `prorata_charge` | `subscription` | One invoice with a line per charged item (plan, seats, addon, addon seats) |
| Legacy: plan upgrade | `prorata` | `prorata` | "Pro-rata plan upgrade" — payments created by an older release |
| Legacy: addon added | `addon` | `addon` | "Addon: Print Gateway (pro-rata)" — as above |
| Legacy: seats increased | `seats` | `seats` | "Extra seats (pro-rata)" — as above |
| Usage overage | `overage` | `overage` | "Usage overage" |
| Subscription renewal | — | `subscription` | Regular recurring payment |
| Refund / downgrade credit | — | `credit_note` | Credit note referencing original invoice |

### How Invoice Types Are Resolved

Every change that costs money now creates one payment of type `prorata_charge`, whatever changed: `ProrataComposer` builds a line per charged item and `ProrataExecutor` collects them in a single Mollie payment. The webhook controller routes that type to `ProrataChargeHandler` (`paid()` / `failed()`).

`overage` — and the legacy types `prorata`, `addon`, `seats` of payments an older release created — are routed to `SingleChargeHandler`. The per-type resolution that used to live in `UpdateSubscription::resolveChargeInfo()` was removed with the move to the collective charge.

## Local → Mollie Upgrade

A free-plan user selecting a paid plan in the bundled UI is diverted to `UpgradeLocalToMollie` instead of going through the regular `UpdateSubscription` deferred flow:

```
User selects paid plan in plan-change UI
  -> applyChange() detects (isLocal && ! isFreePlan(target))
  -> Show confirmation step (read-only billing data + previewed gross)
  -> UpgradeLocalToMollie::handle()
     - Reuse / create Mollie customer (MollieCustomerResolver)
     - Create Mollie first payment with metadata.upgrade_from_local=true
  -> Redirect to Mollie checkout
  -> Webhook -> handleLocalToMollieUpgrade()
     - persistFirstPaymentArtifacts() (mandate, customer, invoice)
     - CreateSubscription::handle() (Mollie subscription + state)
     - WalletPlanChangeAdjuster::adjust() (rebalance, preserve purchased_balance)
     - SubscriptionUpgradedFromLocal event
```

The wallet is **not** seeded fresh — `WalletPlanChangeAdjuster` rebalances plan credits while preserving any purchased balance the user already had.

### When a code covers the whole price

A recurring code that covers the target plan in full leaves nothing to pay today — but a paid plan needs a mandate, and a mandate needs a first payment. The plan screen then does what the checkout does for the same order:

```
confirmAndPay() with a previewed gross of 0 and one applied code
  -> UpgradeLocalToMollie::handle(amount_gross: 0)
     - refuses without a code, or when the code does not cover the order in full
     - StartMandateCheckout::handle(): a 0.00 first payment, type mandate_only,
       metadata pending_subscription_* and pending_subscription_upgrade_from_local
  -> Redirect to Mollie for the authorisation
  -> Webhook -> MandateOnlyPaymentHandler (the checkout's own activation)
     - the gate admits the local plan as the one being replaced
     - zero-amount audit invoice, coupon lines mirroring each line
     - CreateSubscription::handle() at the FULL price, first charge after the code
     - coupon redeemed once; WalletPlanChangeAdjuster::adjust(); SubscriptionUpgradedFromLocal
```

The confirmation step says what happens: the total is 0.00 and, below it, "Nothing is due today. First charge on <date>: <amount>." A customer who already has a mandate is asked for the authorisation as well. Abandoning it at Mollie changes nothing — the free plan stays, the code is not spent, and the return page says the payment did not go through.

The screen admits recurring codes only; a `single_payment` code is answered at the field. An app calling `UpgradeLocalToMollie::handle()` itself may pass any code that covers the order in full.

## Mollie → Free Downgrade

When the target plan is free (`catalog->isFreePlan()`), `UpdateSubscription::update()`:

1. Cancels the Mollie subscription via `CancelSubscriptionRequest` (tolerant of API failures).
2. Removes `mollie_subscription_id` from `subscription_meta`.
3. Sets `subscription_source = local`, `subscription_status = active`, `subscription_ends_at = null`, `subscription_period_starts_at = now()`.
4. Skips `syncMollieSubscription()`.
5. Runs `WalletPlanChangeAdjuster` to reduce plan credits to the free-plan quota; `purchased_balance` is preserved.
6. Refunds any prorata credit through `ProrataExecutor` → `InvoiceService::createRefund()` if `apply_at = immediate` and a mandate is still available.

This is the path for a target that bills **nothing**. A free plan that keeps a paid addon still has a recurring amount: the subscription stays on Mollie, the kept addon is charged for the rest of the period (its old line was credited with everything else), and the switch waits for that charge like any other plan change.

`config('mollie-billing.plan_change_mode')` controls whether this happens immediately or is scheduled (`ScheduleSubscriptionChange::apply()` re-enters `UpdateSubscription::update()` as an internal re-entry to bypass the user-input mode check). The marker is set only through the typed `SubscriptionUpdateRequest::asInternalReentry()`; `SubscriptionUpdateRequest::from()` ignores an `internal` key in array data, like `allow_hidden_plan`, because it also waives the lapsed-period guard.

### When "end of period" falls

`ScheduleSubscriptionChange::schedule()` anchors on `nextBillingDate()`, except **on a trial**, where it takes the earlier of `nextBillingDate()` and `trial_ends_at`. A trial's first charge lands on `trial_ends_at` (day 14), not on the period anniversary (day 30) — anchoring on the latter applied the change in the middle of the first paid period, and since `apply()` re-enters as an `internal` immediate update the lapsed-period guard is waived, so it raised the very prorata charge the customer scheduled the change to avoid.

`schedule()` refuses (`InvalidSubscriptionStateException`) for any status `apply()` would not act on — i.e. anything other than `active`, `trial`, `past_due`. A cancelled-in-grace customer used to get a "scheduled for &lt;date&gt;" confirmation for a change the daily dispatcher then silently discarded; the path for them is to resubscribe.

### The renewal at the boundary is created at the new amount

Mollie creates a recurring payment with the amount the subscription holds *at that moment* —
for a SEPA debit around two days before the charge date — and a PATCH only ever reaches the
next payment to be created. `apply()` runs once `scheduled_change_at <= now`, i.e. at or after
the charge date, so patching there reached the renewal after next: the one in flight settled at
the old amount, the period had lapsed (factor 0, nothing credited), and a customer who booked
Pro → Starter for the period end paid Pro for the first Starter period.

So a scheduled change is announced to Mollie **when it is scheduled**:

| Scheduled change | At schedule time | At the boundary |
|---|---|---|
| Same interval, target still bills something | Mollie is PATCHed to the target amount (`scheduled_change.mollie_prepatched = true`). Nothing local moves. | `SubscriptionPaymentHandler::paid()` calls `ScheduleSubscriptionChange::applyAtRenewal()` before it prices the renewal: the change is applied without prorata (`SubscriptionUpdateRequest::asPeriodBoundary()`), and the renewal is invoiced as the new configuration. If the dispatcher gets there first (SEPA still pending) it applies with factor 0 as before, and the renewal that follows already matches. |
| Target bills nothing (free plan) | Mollie is left alone — there is no amount to patch to, and a cancel could not be taken back. | Applied with the renewal (or by the dispatcher); the renewal Mollie collected is refunded in full, see below. |
| Interval change | Mollie is left alone — the cadence cannot be moved ahead of time without moving the charge date. | The dispatcher waits for the old interval's renewal, then applies it as a normal interval change in that paid period: the new interval charged in full, the renewal just paid credited. |

A due change that Mollie was **not** told about — an interval change, a free target, or one whose
announcement failed — is not applied by the dispatcher while the period has lapsed and its
renewal is still outstanding (`ScheduleSubscriptionChange::WAIT_FOR_RENEWAL_DAYS`, 7). Applied
there it had a prorata factor of 0: an upgrade was free for a period, and an interval change
charged the new interval on top of the old interval's renewal that was still on its way. Once
the renewal is booked the next run applies it as an ordinary change in a paid period (a free
target is applied *with* the renewal). A PastDue subscription does not wait — its renewal
failed, and the past-due reset is its path — and "apply now" mid-period is unaffected.

Withdrawing the change (`cancel()`) PATCHes Mollie back to the current configuration, and so
does replacing it by a change that cannot be announced. An immediate seat or addon change made
while a change is scheduled PATCHes the *current* configuration's amount, so
`realignScheduled()` re-announces the stored change right after it.

### A renewal that was created too early

Where a renewal could not hear about a change in time, `SupersededRenewalRefund` gives back what
it collected for a configuration the customer has left. The payment's own metadata — Mollie
copies the subscription's `plan_code`, `interval`, `extra_seats` and `addon_codes` onto every
payment it creates — says what it was priced for:

- It differs from the local configuration and **more** was collected than the current one costs:
  the surplus (the `adjustment` line) is refunded, and the payment is booked as the renewal it is.
- The payment bought **nothing**: the billable is on a Local plan, or on another interval (whose
  own charge pays for these days). It is refunded in full and not booked as a new period.

It is one-sided on purpose. A renewal that collected *less* than the current configuration costs
is not topped up from inside a webhook, and a mismatch whose metadata agrees with the local state
(a failed PATCH, a coupon) is not a superseded renewal and stays with `PaymentAmountMismatch`.

An interval change whose own charge clears only after a renewal of the old interval was booked is
handled in Phase 2: the refund lines are composed again for the period that renewal opened,
instead of the ones stored in Phase 1.

### The balance of seats and addons names its components

A renewal that was created before a seat or addon was added is booked with the lines of the
configuration the billable is on and one negative `adjustment` down to what Mollie collected.
The balance used to be a single amount of a kind no credit reads, so a seat given back later
in that period was credited from the renewal alone — whose seat line carried its
proportional share of the adjustment: 5.00 × 50/55 × 19/28 = 3.08 for a seat that was paid
5.00, where 5.00 × 19/28 = 3.39 is owed.

Now the two documents say what they mean:

- `SupersededRenewalRefund::componentsAddedSince()` works out what the payment did not know
  about — from the payment's own metadata (`extra_seats`, `addon_codes`) against the billable —
  at the list amounts the renewal's lines state.
- The renewal's `adjustment` gets `reduces` (line key => net): it takes its amount off exactly
  those lines. The plan was paid in full; the added seat was not paid at all. Document
  metadata only — no amount or printed line changes.
- The balance invoice carries one line per component, in the same amounts
  (`UndercollectionCharge::split()`, largest remainder when the shortfall is not the plain
  list sum). **Its total and its VAT are those of the single amount**: VAT is rounded per
  line and the difference to `round(shortfall × rate)` is settled on the largest line, so
  two lines of 5.50 at 19 % book 13.09, not 13.10.
- `ProrataComposer` credits a component out of both documents in the same share — the units
  given back over the units the renewal's line states (`balanceRefundLines()`). With one seat
  in the debit and one added after it, each seat given back is 2.50 × f from the renewal and
  2.50 × f from the balance.

A component given back **while its balance is still out** is credited only for what is paid;
the balance's share is noted (`UndercollectionCharge::componentsGivenBack()`) and returned
for the unused days the moment the balance settles. A plan or interval change does the same
for everything the balance covers (`configurationLeft()`).

## Past-Due Reset

When a recurring charge fails the subscription enters `subscription_status = past_due`. From this state the user can recover in two ways: pay the failed invoice (regular dunning), or switch to any paid plan. The plan-switch path is the **Past-Due reset** — a special branch through the standard `UpdateSubscription` flow.

The intuition: in `past_due`, the current period was never paid. There is nothing to pro-rate against — no money was collected that could be credited and no fractional period can be left over. So a plan change in this state is conceptually a **fresh start**, not a mid-period adjustment.

### Detection

Past-Due reset triggers when **all** of the following hold:

- `billable->isBillingPastDue()` is `true` (subscription status is `past_due`).
- The running period is **not paid** (`! BillingPolicy::runningPeriodIsPaid()`), see below.
- The new plan is **not** a free plan (`! catalog->isFreePlan(newPlan, newInterval)`).
- Either the plan or the interval changed (`planChanged || intervalChanged`).

Pure seat or addon changes on a `past_due` subscription do **not** reset. The reset is intentionally limited to plan/interval switches because that is the recovery action exposed to users in the UI.

**Adding** seats or a paid addon while past due is refused (`PastDueExpansionNotAllowedException`, "Please settle the outstanding payment first"): the period has not been paid and its end has passed, so the addition prorates with a factor of 0 — it used to be accepted and was free for the unpaid period. The rule lives in `ValidateSubscriptionChange::assertNoPastDueExpansion()` and applies to immediate and scheduled changes and to the preview (`errors: [{type: 'past_due_expansion'}]`); the seats, addons and plan-change screens show the hint.

Still possible while past due: fewer seats, an addon switched off, an addon that bills nothing (`addonIsFree()`), and a plan or interval change — the reset above, which may carry more seats and addons because it charges the new period in full. Only a seat count the caller *asked for* counts as an addition; a count derived from the members in use does not.

A `past_due` user downgrading to a free plan does **not** reset either — the existing `Mollie → Free downgrade` path runs and the subscription switches to `local`, no charge.

### Past due on a paid period is not a reset

The reset's premise is that nothing of the period was paid. A subscription is also past due
when its renewal *was* collected and only a balance charged on top of it
(`UndercollectionCharge`) was declined. Resetting there charged a second full period on top
of the paid one and credited nothing: Starter → Pro across a renewal, the balance declined,
back to Starter on 10 Feb cost 20.00 for a February already paid at 20.00.

`BillingPolicy::runningPeriodIsPaid()` is the one test, and `isPastDueReset()` asks it — so
the preview, `buildContext()`, the composer and the Mollie start date all follow:

- the period has not ended (`now < nextBillingDate()` — after a failed renewal the anchor
  stays on the period that has run out, so this is false there);
- an invoice that collected a payment states a line for the period that is not a balance
  line and has not been fully taken back;
- no chargeback stands against that invoice (`ChargebackHandler::hasStandingChargebackBookings()`,
  also a partial one) and the dunning was not started by one (`payment_failure.reason`): a
  renewal the bank pulled back paid for nothing — whichever came first, the chargeback or the
  declined balance.

Then the change is the ordinary one:

| | |
|---|---|
| Credit | the unused share of what **is** paid — the renewal; the declined balance is not |
| Charge | the new configuration for the days that are left (a full new period only on an interval change, as always) |
| Period / Mollie start date | unchanged (`forceResetStartDate = false`) |
| Dunning | ends with the change: `payment_failure` and `past_due_since` are cleared and the status goes through `ChargeUsageOverageDirectly::statusAfterRecovery()` — in Phase 3 of `UpdateSubscription::update()` when nothing has to be collected, in `ProrataChargeHandler` when the change waits for its charge |
| The declined balance | closed by `UndercollectionCharge::configurationLeft()`: not collected again, shown as "Declined — closed by a plan change" to the operator and no longer to the customer. Its decline delivered again does not restart the dunning. If Mollie collects it after all, the days after the change are returned at once |

The pinned example (AT, February = 28 days): Starter → Pro effective 3 Feb, balance
30.00 × 26/28 = 27.86 declined on 5 Feb, back to Starter on 10 Feb. Pro's paid share — the
renewal's 20.00 — is credited for 19 days (13.57) and Starter charged for the same 19 days
(13.57): no money moves, the change is booked as the saldo-zero plan-switch document, and
February costs 20.00 instead of 40.00. Pro's seven days (30.00 × 7/28 = 7.50) are what the
declined balance stood for; they are paid only if that payment arrives — then the customer
has paid exactly plan × days used, 27.50 for the month and 13.93 for 1–10 Feb.

An upgrade from the same state (Pro → Enterprise) credits the same 13.57 and charges
200.00 × 19/28 = 135.71.

Unchanged: a failed renewal is the reset; a subscription that owes only a usage overage is
refused a plan change (`ValidateSubscriptionChange::assertNoPastDueExpansion()`); adding seats
or a paid addon while past due is refused.

### What changes vs. a normal upgrade

The reset uses the same Phase 1 / Phase 2 pipeline as a regular deferred upgrade. The only differences:

| Aspect | Normal upgrade | Past-Due reset |
|--------|----------------|----------------|
| Prorata charge | `(newNet - currentNet) × factor` | `newNet` (full first period at list price) |
| Prorata credit | `(currentNet - newNet) × factor`, or `currentNet × factor` on interval change | `0` — nothing was paid that could be credited |
| Mollie subscription start date | `null` (cadence preserved) on amount-only changes; `now + 1 interval` on interval change | `now + 1 interval` always (`forceResetStartDate=true`) |
| `subscription_period_starts_at` | Untouched on amount-only changes | Set to `now` (Phase 2, derived from `startsNewPeriod`) |
| `payment_failure` marker | Untouched | Cleared in Phase 2 |
| `subscription_status` | Stays `active` | Flipped from `past_due` back to `active` in Phase 2 |
| `subscription_ends_at` | Untouched | Cleared in Phase 2 |

`forceResetStartDate=true` is critical: without it, Mollie's PATCH would keep the failed-charge cadence and immediately retry charging the (new) amount right after the PATCH lands, producing a duplicate of the prorata charge that just succeeded.

### Code paths

The reset is implemented at three points that must stay in sync. Each builds the same line items, just from a different angle:

1. **`PreviewService::computePreview()`** — sets `prorataChargeNet = newNet`, `prorataCreditNet = 0`, `prorataFactor = 1.0`. Surfaces `isPastDueReset` in the preview array so the UI can show the explanatory callout (`portal.past_due_reset_notice`).
2. **`UpdateSubscription::buildContext()`** — computes the same charge values for the live mutation. The resulting `SubscriptionChangeContext` flows through Phase 1's payment creation unchanged.
3. **`ProrataComposer::composePastDueReset()`** — produces the actual `ProrataLine[]` (plan + extra seats + addons) using a synthetic `now → now + 1 interval` window so the standard charge-line helpers compute `factor = 1.0` and emit list-price amounts. No refund lines.

`UpdateSubscription::buildIntent()` then sets `forceResetStartDate = BillingPolicy::isPastDueReset(…)` on the `PlanChangeIntent`. `MollieSubscriptionPatcher::updateRecurringAmount()` reads that flag and forces `startDate = now + 1 interval` on the PATCH. A subscription Mollie has charged refuses that start date (422 "…already charged", measured 17 November 2026) — so for a past-due reset, an interval change, and every other change that moves the date, the patcher replaces the subscription instead: cancel, then create it with the new amount, interval and start date (`MollieSubscriptionReplacer`, see [flows.md § 6.1](flows.md#61-moving-the-next-charge-patch-or-replacement)). `subscription_meta.mollie_subscription_id` then names the new subscription.

### Phase 2 cleanup

When the prorata payment succeeds, `ProrataChargeHandler::paid()` runs the standard Phase 2 apply on top of additional past-due-specific writes:

- Capture `wasPastDue = (status === PastDue)` **before** rewriting the meta.
- Drop `payment_failure` from `subscription_meta` — the failed-charge marker no longer reflects reality.
- Set `subscription_status = active`, `subscription_ends_at = null`, and the new `subscription_period_starts_at = now`.

If Phase 2 fails (re-validation throws, Mollie PATCH errors, etc.), the user stays in `past_due` and the pending state is rolled back via the standard `clearPendingPlanChange()` path. The user can retry the plan switch — there is no half-applied state.

### UI surface

In the bundled plan-change page (`livewire/billing/⚡plan-change.blade.php`), the preview shows `portal.past_due_reset_notice` as an amber callout whenever `preview.isPastDueReset` is true:

> Your previous charge failed, so no part of the current period was paid. Switching plans here charges the full first period of the new plan immediately and starts a fresh billing cycle from today.

This makes the difference from a regular upgrade explicit — the user sees the full price (not a prorated delta) and understands why.

### Edge cases

| Scenario | Behavior |
|----------|----------|
| Past-due user picks the **same** plan they already have | `planChanged=false`, `intervalChanged=false` → no reset, no plan-change path runs. The user must use the "pay failed invoice" recovery instead. |
| Past-due user picks the same plan with a different interval | `intervalChanged=true` → reset applies. Charges full first period of the new interval. |
| Past-due user downgrades to a **free** plan | Reset does **not** apply (`isFreePlan` check). The `Mollie → Free downgrade` path runs: Mollie subscription cancelled, source flipped to `local`, no charge. `payment_failure` is cleared by the standard downgrade flow. |
| Reset's prorata payment fails | Standard Phase 2b: `clearPendingPlanChange()` removes the pending state. User remains `past_due`, `payment_failure` marker untouched, `PlanChangeFailed` event + notification sent. |
| Webhook arrives twice for the same charge | Idempotent via `BillingProcessedWebhook` (standard webhook-dedup) — the `wasPastDue` capture happens once. |
| Past-due on a Local subscription | Cannot occur. Local subscriptions never enter `past_due` (no recurring charges). |

## Events

| Event | When |
|-------|------|
| `PlanChangePending` | Phase 1: prorata payment created, plan not yet changed |
| `PlanChanged` | Phase 2a or immediate: plan actually changed |
| `PlanChangeFailed` | Phase 2b: prorata payment failed, or Phase 2a validation failed |
| `SubscriptionUpgradedFromLocal` | Local → Mollie upgrade completed via webhook |
| `SeatsChanged` | Seat count changed |
| `AddonEnabled` | Addon added |
| `AddonDisabled` | Addon removed (or stripped as incompatible) |
| `SubscriptionUpdated` | Always dispatched after a change is applied, with full diff |
| `SubscriptionChangeScheduled` | Downgrade scheduled for end of period |

## Extension Points

### Custom Validation

```php
// In AppServiceProvider::register()
$this->app->bind(ValidateSubscriptionChange::class, MyCustomValidator::class);
```

Your custom validator can extend `ValidateSubscriptionChange` and override individual methods (`validateSeats`, `validateAddons`, `validateWalletUsage`, `validateMollieReadiness`).

### Event Listeners

React to plan changes via Laravel event listeners:

```php
Event::listen(PlanChanged::class, function (PlanChanged $event) {
    // $event->billable, $event->oldPlan, $event->newPlan, $event->interval
});

Event::listen(PlanChangeFailed::class, function (PlanChangeFailed $event) {
    // $event->billable, $event->pendingChange, $event->paymentId, $event->reason
});
```

### Catalog Interface

Plan definitions, pricing, quotas, and addon compatibility all come from `SubscriptionCatalogInterface`. The default implementation reads from config (`config/mollie-billing-plans.php`). Bind your own implementation for database-driven catalogs.

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| User cancels pending change via "Ausstehende Änderung abbrechen" | `UpdateSubscription::cancelPendingPlanChange()` issues a `CancelPaymentRequest` against Mollie if the payment is still `isCancelable=true` (typical for cards, SEPA) and then clears local state. Some methods (e.g. paypal) report `isCancelable=false` — those expire on their own at Mollie; local state is still cleared. |
| User cancels pending change, then payment succeeds anyway | Invoice is created (money was collected), but no `pending_plan_change` exists, so plan stays unchanged. Admin must refund manually. This window is small because `cancelPendingPlanChange()` actively cancels the Mollie payment when possible. |
| User cancels pending change, Mollie payment then expires/fails | The failed/expired webhook becomes a no-op: no `plan_change_failed_at` marker, no `PlanChangeFailed` event, no admin notification. The user already decided this charge should not apply — surfacing a failure toast would be misleading. |
| Webhook arrives before Phase 1 transaction commits | Row lock blocks the webhook until commit. |
| Phase 2 validation fails after payment | Logged, pending stays in meta for admin review. `PlanChangeFailed` event + notification sent. |
| Local → Local plan switch | Never enters the deferred path. Applies immediately, source stays Local. |
| Local → Mollie via `UpdateSubscription` (no UI) | Throws `LocalSubscriptionUpgradeRequiresMolliePathException`. Use `UpgradeLocalToMollie` instead. |
| Paid extras on a Local subscription | Throws `LocalSubscriptionDoesNotSupportPaidExtrasException`. UI surfaces this as a callout that links to the plan-change page. |
| Change onto a plan marked `hidden` | Throws `HiddenPlanNotSelectableException` — for immediate *and* scheduled (`apply_at => end_of_period`) changes, the latter before anything is stored. Staying on a hidden plan and changing interval within it are allowed. Deliberate programmatic moves pass a typed `SubscriptionUpdateRequest` with `allowHiddenPlan: true` — array payloads cannot enable the override. See [Hidden plans](configuration.md#hidden-plans). |
| Second plan change while one is pending | `InvalidSubscriptionStateException` thrown. |

## Transaction boundaries

`UpdateSubscription::update()` reaches Mollie three times — `applyProrata()` creates payments
and posts refunds, and the patcher cancels or PATCHes the subscription. None of those may sit
inside a database transaction: a transaction can be rolled back, an HTTP call to a payment
provider cannot, and a rollback after the money moved is exactly how a refund came to be paid
out twice.

So the method runs in three phases, with a **cache lock** (`billing:subscription-change:{id}`)
providing the serialisation a long transaction used to:

1. **Decide** — row lock in a short transaction: build the context, validate, resolve coupons.
2. **Talk to Mollie** — nothing held.
3. **Write** — a second short transaction that re-takes the row lock and re-reads
   `subscription_meta`, because a job (a retry, the overage sweep) may have committed a key in
   between and the write below replaces the whole column.

A concurrent change to the same billable is refused with `SubscriptionChangeInProgressException`
rather than queued: it would otherwise sit on a worker for the length of a payment plus a
PATCH, and its seats, addons and prorata amounts are stale by the time it gets its turn.
Events are dispatched through `DB::afterCommit()` for the same underlying reason — a throwing
listener must not be able to undo settled money.

## What the package resolves on its own is still shown

Resolving something automatically must not mean it happens unseen. The same read models
feed every screen, so the customer and the operator are told the same thing:

| What | Customer | Operator |
|---|---|---|
| A balance charged after a renewal that was too low (`UndercollectionCharge::cases()`: `pending`, `paid`, `failed`, `not_possible`) | Dashboard and plan screen: a notice with amount and reason while it is open or was declined (`<x-mollie-billing::change-notices>`). Invoice list: the paid balance is an invoice of its own, labelled "Balance charge" with the reason its line carries. | Billable → Subscription tab: every balance charge with date, reason, amount, status and payment id; for `not_possible` the obstacle (no mandate, past due, cancelled, …). |
| A scheduled change that failed to apply and is being retried (`ScheduleSubscriptionChange::retryState()`) | Dashboard and plan screen: "tried again in the coming night (attempt n of 3)". | Scheduled changes: a badge "Attempt n of 3 failed" and the last error — and, when an attempt had already created the prorata payment, its id and that no further one is made. |
| A scheduled change that was dropped (`ScheduleSubscriptionChange::droppedNotice()`, kept for `DROPPED_NOTICE_DAYS`) | Dashboard and plan screen: that it was not carried out, and why — the same reasons the mail gives — and, while a payment the change had created can still arrive, that it is refunded in full when it does. Gone once a new change is scheduled. | — (the admin mail, and only after the last attempt) |

## A cancelled subscription is not charged for a change

A settled prorata charge is never applied to a subscription that no longer runs — the money
is returned in full. Accepting the change anyway charged the customer, refunded them and
left the plan where it was; and a seat or a paid addon, which are active as soon as they are
ordered, stayed active after the refund, for free.

`ValidateSubscriptionChange::assertSubscriptionStillRuns()` therefore refuses, before anything
is charged, on `cancelled` and `expired`: a plan change, an interval change, an added seat
and an added paid addon (`SubscriptionEndedChangeNotAllowedException`, "Reactivate it
first"). The preview carries it as the blocking error `subscription_ended`, so the plan screen
shows the reason and offers no button — for "now" and for "at the period end" alike.

Taking a seat or an addon away on a cancelled subscription is still possible and refunds the
unused paid share (decided 16 November 2026): the customer paid for it and no longer uses it.
The end date does not move, and the Mollie subscription — cancelled with the customer's
cancellation — is not PATCHed (`MollieSubscriptionPatcher::updateForIntent()` returns for a
cancelled or expired subscription). What a 100 % discount paid is credited at nothing.

## A plan change drops the addons the new plan does not allow

A plan change that names no addons keeps the ones the target allows and drops the rest —
which is what the preview has always announced. `UpdateSubscription::buildContext()` used to
carry them over unfiltered: a downgrade to a free plan with one addon kept source `mollie`
and a subscription for the addon alone, on a plan that does not offer it.

## An operator hears about a refund when its retries are used up

`UnwantedPaymentRefund` no longer mails an admin when the first refund attempt fails. The line
is queued (`pending_refund_retries`), logged, and the audit entry says "queued"; an admin is
told once, by `RetryRefundLineJob`, when the line moves to the dead letter.

## A period whose plan change settled after the renewal

A plan change paid by a slow method can settle after the period has been renewed for the
plan the customer still had. The period is then paid in two documents:

| First change | The renewal says | The rest |
|---|---|---|
| Downgrade (Enterprise → Pro) | Enterprise 200.00 | the difference for the days Pro runs is returned against the renewal as one amount (`SupersededRenewalRefund::refundOverpaid()`) |
| Upgrade (Starter → Pro) | Starter 20.00 | the difference for the days Pro runs is collected as a balance (`UndercollectionCharge`); its line names the plan it pays for, carries `undercollection`, and runs from the day the plan took effect |

Either way the customer has paid for Pro from the day it took effect, and no single line says
so. A further change in that period is settled **as if the period had been paid on the plan
in between from its first day** (`ProrataComposer::planRefundLines()`): the plan's price for
the days that are left is credited — against the renewal's plan line, and against the balance
line if there is one — at the share the renewal was actually paid at, never beyond what a
line paid or what is left to refund on its invoice.

Example (AT, 20 %, February = 28 days): Enterprise → Pro on 30 Jan, renewal 1 Feb, effective
3 Feb. Pro → Starter on 10 Feb, 19 days left: Pro is credited 50.00 × 19/28 = 33.93, Starter
charged 20.00 × 19/28 = 13.57. Before, nothing was credited and the customer lost 33.93 net.
With an upgrade first (Starter → Pro, balance 30.00 × 26/28 = 27.86), Pro → Enterprise credits
13.57 against the renewal and 27.86 × 19/26 = 20.36 against the balance — 33.93 again.

Seats and addons are credited from their own lines on the renewal — with one cap, below.

**While the balance of an upgrade is still open** only what is paid is credited — the
renewal's share. `UndercollectionCharge::configurationLeft()` notes the moment the plan (or
the interval) is left; if
the balance is collected afterwards, the days from then on are credited at once (its own
credit note, a refund on its own payment), so the customer has paid for the plan exactly as
long as it ran: Starter → Pro effective 3 Feb, back to Starter on 10 Feb, balance paid on
12 Feb — February costs 20.00 + 30.00 × 7/28 = 27.50.

**Under a fixed-amount coupon** every line — plan, seats, addons — is credited at no more
than the share the discount leaves of the *current* configuration, not at the share the
renewal's line was paid at: a fixed amount weighs more on the cheaper plan. 10.00 off
Enterprise with a seat and Storage (214.00) leaves each line at 204/214; on Pro (64.00) it
leaves 54/64. The rule is the one an invoice spreads a fixed amount over its lines with —
in proportion (`ProrataComposer::capAtCurrentDiscountShare()`, and `planRefundLines()` for
the plan). It applies only to lines of a renewal booked for another plan than the current
one; an invoice that states the configuration the customer is on has exact shares already.

**An interval change** that settles after the renewal starts its new period on the day it
settles; the renewal of the old interval is returned for the days after that
(50.00 × 26/28 = 46.43) and kept for the days before.

## Scheduling a change while past due

| | Renewal failed | Only a usage overage is open |
|---|---|---|
| Schedule a dearer plan / the dearer interval | refused | refused |
| Schedule more seats / another paid addon | refused | refused |
| Schedule a cheaper plan | allowed — the screen says the open payment remains due | refused: the period is paid, and a plan change on it would be the past-due reset charging a second one. The refusal says what to do instead — settle the amount; seats and add-ons can still be reduced (`PastDueExpansionNotAllowedException::planChange()`) |
| Schedule fewer seats / an addon off | allowed | allowed |

All refusals are `PastDueExpansionNotAllowedException` with the customer-facing sentence.
`ScheduleSubscriptionChange::schedule()` and `apply()` share the plan rule, so a change that
was scheduled while everything was fine and meets a past-due subscription on its night gets
the same answer: an expansion is dropped at once (`payment_outstanding`, customer mail, no
admin), a reduction is applied. A cheaper plan after a failed renewal is applied as the
[past-due reset](#past-due-reset) — the new plan's first period, charged in full.


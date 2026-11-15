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
Phase 1 — User initiates upgrade (synchronous)
  1. Build SubscriptionChangeContext (compute seats, addons, prorata, etc.)
  2. ValidateSubscriptionChange::validate()
     - Target plan: throw HiddenPlanNotSelectableException for hidden plans
     - Seats: auto-derive or throw SeatDowngradeRequiredException
     - Addons: strip incompatible
     - Wallet usage: charge overage if needed
     - Mollie readiness: mandate, subscription ID, no pending change
  3. Store pending_plan_change in subscription_meta
  4. Create Mollie recurring payment (prorata charge)
  5. Dispatch PlanChangePending event
  6. Return { pendingPaymentConfirmation: true }
  -> Plan is NOT changed, subscription is NOT updated

Phase 2a — Webhook: payment succeeded
  1. MollieWebhookController::handleSingleChargePaid() creates BillingInvoice
  2. UpdateSubscription::applyPendingPlanChange() is called:
     a. Re-validate via ValidateSubscriptionChange (state may have changed)
     b. Cancel old + create new Mollie subscription
     c. Clear pending state via clearPendingPlanChange()
     d. Update plan, interval, addons, seats in database
     e. Adjust wallets
     f. Redeem coupon (if stored)
     g. Dispatch PlanChanged, SeatsChanged, AddonEnabled/Disabled events

Phase 2b — Webhook: payment failed
  1. UpdateSubscription::clearPendingPlanChange() removes pending state
  2. plan_change_failed_at and plan_change_failed_reason stored in meta
  3. PlanChangeFailed event dispatched
  4. PlanChangeFailedNotification sent to billing admins
  -> Plan remains unchanged
  Note: if the local pending state is already gone when the failed/expired
  webhook arrives (user clicked "cancel pending change" first), the webhook
  is a silent no-op — no failed marker, no event, no notification.
```

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

### A period collected at less than it costs is reported, not charged

Two cases the package recognises with certainty:

| `RenewalUndercollected::$reason` | What happened | Where it is recognised |
|---|---|---|
| `renewal_created_before_change` | A seat or addon was booked after Mollie had already created the next debit. That renewal settles at the old amount while the new configuration is active for the whole period. | `SupersededRenewalRefund::settle()`, on the renewal: the invoice carries a negative `adjustment` **and** the payment's own metadata names another configuration. |
| `plan_change_spanned_renewal` | A plan change's prorata payment (SEPA) was still pending when the period renewed. The renewal was collected for the old plan; the change then took effect inside the period it had paid for. | `ProrataChargeHandler::paid()`, in Phase 2: the same change is composed as if it were made now; a positive balance is the shortfall. |

`UndercollectionReporter` then does three things and nothing else: it dispatches
`RenewalUndercollected` (billable, renewal invoice, expected / collected / shortfall net, reason),
which lands in the audit trail as `renewal_undercollected`; it logs; and it notifies the admins
(`MollieBilling::notifyAdmin()`) with the amount. **No payment is created, no status changes and
the invoice stays as booked** — taking money from inside a webhook is a decision for a person.

It reports once per case: both places run behind the per-payment invoice guard, so a re-delivered
webhook does not get there. It stays silent for a difference a discount or waiver explains (those
are part of the expected amount), for a shortfall whose payment metadata matches the current
configuration (a PATCH that did not land — `PaymentAmountMismatch`, as before), and within the
one-cent rounding slack.

The mirror image of the second case — a *downgrade* that spanned a renewal — is not reported but
settled: the difference is refunded against the renewal, like any renewal collected at a
superseded price.

### Day Granularity

Billing uses **whole calendar days** (`startOfDay()`). A change made on the same day as the period start yields a factor of exactly `1.0` — the current day counts as a full remaining day.

### Same-Interval Changes

When the billing interval stays the same (e.g. monthly → monthly):

- **Upgrade** (`newNet > currentNet`): `charge = (newNet - currentNet) * factor`
- **Downgrade** (`newNet < currentNet`): `credit = (currentNet - newNet) * factor`

**The period boundary does not move.** The factor covers only the remainder of the *current* window, and Mollie's charge date is left untouched (the PATCH changes the amount, not the cadence). So `subscription_period_starts_at` stays where it was, and the change's invoice is stamped with the existing period. Phase 2 re-anchoring the period here made `nextBillingDate()` report a date Mollie does not charge on, and a seat added afterwards was prorated against that phantom window while the real renewal billed the same days again.

Two exceptions do open a new window, and both also move Mollie's schedule: an interval change, and the [past-due reset](#past-due-reset). A plan change that converts an in-flight trial is a third case where the *schedule* moves — Mollie's `startDate` is the trial end, and the charge has just paid for the rest of the local period, so the first recurring charge is pushed one full period out.

### Interval Changes

When the interval changes (e.g. monthly → yearly or yearly → monthly), the old and new amounts cover different period lengths and cannot be compared directly. The unused portion of the current period is credited in full. The new plan's first payment is collected by Mollie via the new subscription.

- `credit = currentNet * factor`
- No prorata charge — Mollie collects the full new amount via the recreated subscription.

## Invoice Types

Each subscription change creates an invoice with a specific `invoice_kind` depending on what changed. This ensures invoices are clearly labeled in the billing portal.

| Change | `type` (Mollie metadata) | `invoice_kind` | Description / Label |
|--------|--------------------------|----------------|---------------------|
| Plan upgrade | `prorata` | `prorata` | "Pro-rata plan upgrade" |
| Addon added | `addon` | `addon` | "Addon: Print Gateway (pro-rata)" |
| Seats increased | `seats` | `seats` | "Extra seats (pro-rata)" |
| Usage overage | `overage` | `overage` | "Usage overage" |
| Subscription renewal | — | `subscription` | Regular recurring payment |
| Refund / downgrade credit | — | `credit_note` | Credit note referencing original invoice |

### How Invoice Types Are Resolved

`UpdateSubscription::resolveChargeInfo()` inspects the `SubscriptionChangeContext` to determine what changed:

1. **Only addons changed** (no plan, interval, or seat change): `type = 'addon'`, label includes addon name
2. **Only seats changed**: `type = 'seats'`, label shows seat count
3. **Everything else** (plan change, interval change, mixed): `type = 'prorata'`

The webhook controller routes all these types (`prorata`, `addon`, `seats`, `overage`) through `handleSingleChargePaid()` / `handleSingleChargeFailed()`.

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

## Past-Due Reset

When a recurring charge fails the subscription enters `subscription_status = past_due`. From this state the user can recover in two ways: pay the failed invoice (regular dunning), or switch to any paid plan. The plan-switch path is the **Past-Due reset** — a special branch through the standard `UpdateSubscription` flow.

The intuition: in `past_due`, the current period was never paid. There is nothing to pro-rate against — no money was collected that could be credited and no fractional period can be left over. So a plan change in this state is conceptually a **fresh start**, not a mid-period adjustment.

### Detection

Past-Due reset triggers when **all** of the following hold:

- `billable->isBillingPastDue()` is `true` (subscription status is `past_due`).
- The new plan is **not** a free plan (`! catalog->isFreePlan(newPlan, newInterval)`).
- Either the plan or the interval changed (`planChanged || intervalChanged`).

Pure seat or addon changes on a `past_due` subscription do **not** reset. The reset is intentionally limited to plan/interval switches because that is the recovery action exposed to users in the UI.

**Adding** seats or a paid addon while past due is refused (`PastDueExpansionNotAllowedException`, "Please settle the outstanding payment first"): the period has not been paid and its end has passed, so the addition prorates with a factor of 0 — it used to be accepted and was free for the unpaid period. The rule lives in `ValidateSubscriptionChange::assertNoPastDueExpansion()` and applies to immediate and scheduled changes and to the preview (`errors: [{type: 'past_due_expansion'}]`); the seats, addons and plan-change screens show the hint.

Still possible while past due: fewer seats, an addon switched off, an addon that bills nothing (`addonIsFree()`), and a plan or interval change — the reset above, which may carry more seats and addons because it charges the new period in full. Only a seat count the caller *asked for* counts as an addition; a count derived from the members in use does not.

A `past_due` user downgrading to a free plan does **not** reset either — the existing `Mollie → Free downgrade` path runs and the subscription switches to `local`, no charge.

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

`UpdateSubscription::buildIntent()` then sets `forceResetStartDate = isBillingPastDue() && (planChanged || intervalChanged)` on the `PlanChangeIntent`. `MollieSubscriptionPatcher::updateRecurringAmount()` reads that flag and forces `startDate = now + 1 interval` on the PATCH.

### Phase 2 cleanup

When the prorata payment succeeds, `MollieWebhookController::handleSingleChargePaid()` runs the standard Phase 2 apply on top of additional past-due-specific writes:

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

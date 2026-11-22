# Upgrading — what an application has to change

## From 0.8.x to 1.0.0

No migration and no breaking API change. What an application should look at:

- **Every invoice PDF is now an e-invoice** (ZUGFeRD / Factur-X, EN 16931): PDF/A-3 with
  `factur-x.xml` attached. On by default; `BILLING_E_INVOICE_ENABLED=false` turns it off. See
  [e-invoicing.md](e-invoicing.md).
- **Set the seller's VAT ID** in `BILLING_SELLER_VAT_ID` (a VAT ID kept in
  `BILLING_SELLER_TAX_NUMBER` keeps working) and run `php artisan billing:check-config`: it
  reports what the e-invoice is missing.
- **The PDF looks different:** rendered in DejaVu Sans (PDF/A needs embedded fonts; your own
  `invoices.pdf.template_data.font` wins), a service period per line ("Leistungsdatum" for
  products), dates in the invoice locale (`d.m.Y` in German, `BILLING_INVOICE_DATE_FORMAT`
  overrides), labelled VAT IDs and tax number, payment methods in words, and the
  reverse-charge statement in the seller country's wording (`BILLING_REVERSE_CHARGE_TEXT`
  overrides). Re-publish translations if you published them: new keys under
  `billing::portal.e_invoice.*`, `service_period`, `service_date`, `invoice_date_format`,
  `payment_method.method.*`.
- **Overage invoices state the period the usage was in**, also when a SEPA payment settles
  after the renewal.
- New dependency: `horstoeko/invoicesuite` (pinned to `^0.0.44`, pre-1.0).

---

The rest of this page covers the step from **0.4.1** onwards.

This part covers the step from **0.4.1 to 0.8.x** (see the
[changelog](../CHANGELOG.md)). It is written for the application that *uses* the package:
what breaks if nothing is done, what behaves differently and deserves a look, and what is new
and optional. The reasoning behind each change is in [decisions.md](decisions.md), mostly under
"Third audit round".

The short version:

- run the migrations, keep a queue worker and the scheduler running;
- handle a refused `recordBillingUsage()` — it can now refuse for a customer who allows overage;
- stop treating "past due" as "locked out" without asking `hasAccessibleBillingSubscription()`;
- remove whatever your app did by hand for the cases the package now resolves itself
  (back-charging an undercollected renewal, refunding a stray payment);
- re-publish translations and views if you published them;
- a recurring code's free periods are now billing periods: check texts that promise a date;
- `subscription_meta.mollie_subscription_id` can change while a subscription runs: do not
  cache it, or listen to `MollieSubscriptionReplaced` (§ 2.10a).

## 1. Required — something breaks without it

### 1.1 Run the migrations

Two migrations were added since 0.4.1:

- `2026_11_15_000001_add_plan_waiver_fields_to_coupons_table` (`grant_on_expiry`,
  `waiver_periods`)
- `2026_11_16_000001_add_discount_periods_to_coupons_table` (`discount_periods`; it also moves
  the period count of existing recurring coupons — see the changelog entry on
  `max_redemptions_per_billable`)

The package loads its migrations itself, so `php artisan migrate` is enough. An application
that published them (`--tag=mollie-billing-migrations`) has to publish the two new files as
well.

The automatic-resolution work of this release added **no** table and no column: its state
lives in `subscription_meta` and in rows of the existing `billing_processed_webhooks` table.

### 1.2 A queue worker and the scheduler must be running

**What.** The package now settles a number of cases with queued jobs that carry a delay: an
unfinished activation is retried after one minute, five, fifteen, an hour and six hours
(`RetrySubscriptionActivationJob`); a refund Mollie did not take is retried for seven days
(`RetryRefundLineJob`); a seat change that cannot get its lock re-queues itself
(`SyncSeatsJob`). One scheduled job is new: `RefundUnassignedPaymentsJob`, daily at 04:15 UTC.
`SweepPendingRetriesJob` (hourly, already registered) is the safety net for all of them.

**Why it matters.** These jobs are what stands between a paying customer and "money taken,
nothing delivered". On `QUEUE_CONNECTION=sync` a dispatched job runs at once, inside the
request that dispatched it, and ignores its delay; a job that throws has failed for good. The
retry jobs are written to survive that, but only by falling back to the scheduled passes:

- `RetryRefundLineJob`, `RetrySubscriptionPatchJob` and `RetrySubscriptionCancelJob` leave
  their marker in place on a sync run instead of throwing, and `SweepPendingRetriesJob` runs
  them again every hour until their window (7 days, 24 h, 24 h) is over — then the one
  operator mail, as on a real queue.
- `RetryUsageOverageChargeJob` leaves an overage charge Mollie would not create `pending`
  for the nightly `PrepareUsageOverageJob` pass, and mails the operator after three failed
  nights (`usage_overage_inline_failures` counts them) — where a worker would have tried three
  times within a quarter of an hour.
- The activation retry is not dispatched from the webhook on a sync connection at all; the
  hourly `SweepPendingRetriesJob` runs it, and a run that does not finish keeps its marker
  until the ladder's time (about seven and a half hours) is over. Only then is a failure
  final: the payment is refunded and the operator mailed.

So `sync` loses nothing for good any more, but every retry is hours instead of minutes.

**What to do.**

- Run a real queue (`database`, `redis`, …) with a worker in production. The connection and
  queue name can be set for the package alone:

  ```dotenv
  BILLING_QUEUE_CONNECTION=redis
  BILLING_QUEUE_NAME=billing
  ```

- Keep `php artisan schedule:run` on its cron. The package registers its jobs itself; there is
  nothing to add to your own schedule.
- `billing:check-config` warns when the package's jobs run on a connection with the `sync`
  driver (`mollie-billing.queue.connection`, or `queue.default` when that is unset).

### 1.3 Handle a refused `recordBillingUsage()`

**What.** While a subscription is `past_due` — for an unpaid renewal *or* for an overage debt
— usage beyond what the wallets hold is refused. `recordBillingUsage()` throws
`UsageLimitExceededException` (and `UsageLimitReached` is dispatched); `canChargeBillingOverage()`
and `hasBillingQuotaLeft()` answer false. This is the refusal a billable *without* overage
always got; it now also reaches a billable that allows overage. The booking is all-or-nothing:
a quantity only partly covered by the wallet is refused as a whole.

**Why.** A customer who owes runs up nothing further. The included quota and purchased units
stay usable.

**What to do.** If your app assumed that a plan with an overage price never refuses usage,
that assumption no longer holds:

```php
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;

try {
    $organization->recordBillingUsage('tokens', $tokens);
} catch (UsageLimitExceededException $e) {
    // $e->usageType, $e->balance, $e->attemptedQuantity
    return response()->json([
        'message' => __('Your included usage is used up and a payment is open.'),
    ], 402);
}
```

Or ask first:

```php
if (! $organization->hasBillingQuotaLeft('tokens')) {
    // show the customer where to settle the open payment
}
```

### 1.4 `UndercollectionReporter` is gone

`Services\Billing\UndercollectionReporter` was replaced by
`Services\Billing\UndercollectionCharge`. An application that bound, extended or called the
old class has to move. Most applications never touched it.

### 1.5 A custom catalog needs the new interface methods

Only for applications that implement `SubscriptionCatalogInterface` themselves instead of
extending `ConfigSubscriptionCatalog`: the interface gained `addonSeatPriceNet()`,
`addonIncludedSeats()`, `addonIsFree()`, `featureIsVisible()`, `visiblePlanFeatures()` and
`visibleAddonFeatures()`. See [configuration.md](configuration.md).

After 0.5.1 it gained two more, for hidden usage types:

- `usageTypeIsVisible(string $usageType): bool` — `false` only for a type that is to be left
  off the plan cards. Answer `true` for anything you do not know, and look the type up
  case-insensitively, as every usage-type lookup does.
- `visibleIncludedUsages(string $planCode, ?string $interval): array` — `includedUsages()`
  without the hidden types, keys and order kept. The checkout and plan-change plan cards read
  this one; everything that credits or charges keeps reading `includedUsages()`.

A catalog without hidden usage types implements them as `return true;` and
`return $this->includedUsages($planCode, $interval);`. Without them PHP refuses to load the
class. See [configuration.md](configuration.md#hidden-usage-types).

## 2. Check — behaviour has changed

### 2.1 Past due no longer always means "no access"

**What.** A failed overage charge used to end in a write-off after one dunning cycle. Now the
debt stands: the subscription becomes `past_due`, recorded under
`subscription_meta.overage_past_due_since` (not `past_due_since`, so `past_due_max_days` does
not cancel it), the next three renewals each try to collect it, and only then is it written
off. A customer in that state has paid their plan, so they **keep access**:

- `hasAccessibleBillingSubscription()` answers `true`;
- the `billing.active` middleware (`RequireActiveSubscription`) lets them through.

With an unpaid renewal nothing changes: no access, redirect to the portal.

**What to do.** Search your application for `isBillingPastDue()` and for comparisons with
`SubscriptionStatus::PastDue`. Where the question is "may this customer use the application",
ask `hasAccessibleBillingSubscription()` instead:

```php
// before — locks out a customer who owes €3.70 of overage on a paid plan
if ($organization->isBillingPastDue()) {
    abort(402);
}

// after
if (! $organization->hasAccessibleBillingSubscription()) {
    abort(402);
}
```

Feature gates (`billing.feature`, `@planFeature`, `MollieBilling::features()`) never looked at
the status and still do not.

### 2.2 Past due blocks every new booking

`PastDueExpansionNotAllowedException` used to be thrown for added seats and paid addons. It is
now also thrown by:

| Call | When |
|---|---|
| Plan or interval change (`changeBillingPlan()`, `UpdateSubscription`, scheduled, preview) | past due for an overage debt only. With an unpaid renewal the change stays possible — it is the past-due reset |
| `StartOneTimeOrderCheckout::handle()` | any past-due state |
| `StartGrantExtrasPurchase::handle()` | any past-due state |
| `StartSubscriptionCheckout::handle()` | past due while an overage debt stands or Mollie still runs a subscription. A trial that expired without a subscription can still check out |

The exception's message is the translated customer sentence
(`billing::portal.error_past_due_booking` for everything that is not seats or addons). The
package's own screens show it; code of your own that calls these services should catch it:

```php
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;

try {
    $organization->changeBillingPlan('pro', 'monthly');
} catch (PastDueExpansionNotAllowedException $e) {
    return back()->withErrors(['plan' => $e->getMessage()]);
}
```

### 2.3 Two more refusals to catch

- **`SubscriptionEndedChangeNotAllowedException`** — a plan or interval change, an added seat
  or a paid addon on a *cancelled* or *expired* subscription. Such a change used to be charged
  and refunded at once (and left the seat or addon active for free). The preview reports it as
  `subscription_ended`. Removing a seat or an addon is still possible.
- **`PastDueExpansionNotAllowedException` from a *scheduled* change** — while past due,
  scheduling a dearer plan or interval, more seats or another paid addon for the period end
  is refused, and under a pure overage debt so is every scheduled plan or interval change.
  It used to be stored and, on its night, applied as the past-due reset. Scheduling a
  reduction still works. If your application schedules changes itself
  (`apply_at => 'end_of_period'`), catch the exception and show its message.
- **`InvalidSubscriptionStateException` from `cancelBillingSubscription()`** — for a billable
  in status `new` or `expired`. Cancelling nothing used to write `cancelled` with an end date
  thirty days ahead. If your application offers "cancel" to billables that may never have
  subscribed, guard the call or catch the exception.

### 2.4 Remove what your application did by hand

The package now resolves these cases itself. An application (or a runbook) that handled them
manually would do it a second time:

| Case | The package now | Remove from your side |
|---|---|---|
| A renewal collected less than the configuration costs (`RenewalUndercollected`) | charges the difference as its own payment with its own invoice, once; the event carries `chargePaymentId` | **any listener that charges the shortfall itself** — it would charge twice |
| A renewal collected for a subscription that no longer runs | cancels the Mollie subscription and refunds the payment | manual refunds |
| A prorata payment that settles after its change was cancelled or its subscription ended | refunds it | manual refunds |
| A first payment whose activation did not finish | retries, adopts an existing Mollie subscription, refunds when the retries are spent | manual activation or refund — or do it within the first hours and expect the retry to find it done |
| A paid payment that names no billable | books it when the billable turns up, refunds it after 14 days | nothing, but note the deadline: an operator who wants to assign the payment has 14 days |
| A scheduled change that fails to apply | retries on three nights, then drops it and tells the customer | — |
| An overage charge that never settled | keeps the debt, three further collections | manual re-charging |

`RenewalUndercollected::$chargePaymentId` is `null` exactly when the package could *not*
charge (past due, cancelled, expired, no mandate, Mollie refused). That is the only case left
for a person:

```php
Event::listen(function (RenewalUndercollected $event) {
    if ($event->chargePaymentId !== null) {
        return; // collected by the package
    }

    // not collected — $event->shortfallNet is still open
});
```

### 2.5 Events fire where they did not, and one fires less often

- **`CheckoutStarted` is dispatched now.** The class existed and was never fired. A listener
  you registered for it starts running; the audit trail gains `checkout_started` rows.
- **`PlanChanged`, `SeatsChanged`, `AddonEnabled` / `AddonDisabled`, `SubscriptionUpdated`**
  are now also dispatched for a change that is applied when its prorata payment settles. If
  you worked around the missing event (for example by reacting to the payment), make sure the
  work is not done twice.
- **`SubscriptionChangeApplyFailed`** is dispatched once, when a scheduled change is given up
  — not on every failed attempt.
- **`PaymentSucceeded` and the invoice mail** are held back for a first payment whose
  activation is still being retried, and sent when it completes.
- **`UsageLimitReached`** is dispatched after the refusing transaction has rolled back (it
  keeps its audit row now).
- **`ScheduleSubscriptionChange::apply()`** no longer throws; it resolves a failure itself.
  Code that calls it for a person and wants the error passes `onRequest: true`.

New events: `UnwantedPaymentRefunded` (billable, invoice, credit note, payment id, reason),
`OverageWrittenOff` (billable, line items, net, gross, attempts),
`CouponRedemptionRecordMissing` (billable, coupon, discount, error). All three are audited.

### 2.6 Fewer admin notifications

The principle is now: an operator is told when the automatic way is exhausted. In practice
the following mails are no longer sent, or are sent later:

- "an overage charge failed" to the admins — only at the final write-off;
- "please refund this payment manually" for prorata payments and renewals of an ended
  subscription — replaced by the automatic refund; one mail if the refund's retries run out;
- "activation failed" — the admins are mailed when the retries are spent, with what was done
  about the payment; `SubscriptionActivationFailed` is dispatched once per payment, when the
  retries start;
- "the redemption record is missing" — now an audit entry, unless the recurring marker could
  not be written either;
- the admin alert for a refunded purchase of extras on an ended gift
  (`GrantExtrasPurchaseRefund`) — also on success.

If your monitoring counted on one of these mails, listen for the events instead.

### 2.7 New and changed customer notifications

| Notification | Sent when | Translation keys |
|---|---|---|
| `UnwantedPaymentRefundedNotification` | a payment was returned because it could not be applied | `notifications.unwanted_payment_refunded.*` (reasons: `renewal_after_end`, `activation_failed`, `change_cancelled`, `subscription_ended`) |
| `UndercollectionChargedNotification` | the difference of an undercollected period is charged | `notifications.undercollection_charged.*` |
| `ScheduledChangeNotAppliedNotification` | a scheduled change was dropped | `notifications.scheduled_change_not_applied.*` |
| `OverageBillingFailedNotification` (changed) | an overage dunning cycle gave up | subject and body reworded; new lines `will_retry` / `final`; new public property `$willRetry` |
| `AdminOverageBillingFailedNotification` (changed) | the debt is finally written off | subject and body reworded |
| `AdminRefundFailedNotification` (changed) | — | the subject names the invoice number; `toArray()` gained `invoice_number` |

All of them build their mail from translation lines. An application that replaces package
notifications with its own classes (`MollieBilling::useNotification()`) should decide whether
the three new ones need a replacement too, and a replacement for
`OverageBillingFailedNotification` should take the retry promise into account — the old text
"service paused" is no longer true.

### 2.8 Published translations and views

Only if you published them. The package's own files are used otherwise.

**Translations** (`--tag=billing-lang`, in `lang/vendor/billing/{locale}/`). New keys, in
`en` and `de` alike:

| File | Added | Changed text |
|---|---|---|
| `admin.php` | 38 keys — `balance_charges.*`, `scheduled_changes.attempts*` / `last_error`, `past_due.debt_collection_*`, `coupons.create.applicable_*`, `coupons.show.*`, `billables.*.flash.extension_repeated` / `reason_text_required` | `coupons.type_info.recurring`, `coupons.create.info.discount_periods` (a code's periods are billing periods, § 2.9) |
| `audit.php` | `overage_written_off`, `unwanted_payment_refunded`, `coupon_redemption_record_missing` | `renewal_undercollected` |
| `checkout.php` | `addon_included_seats`, `coupon_ends_before_first_charge`, `full_coverage_redirect_notice` | — |
| `notifications.php` | 24 keys — the three new notifications, `overage_billing_failed.will_retry` / `final` | `overage_billing_failed.subject` / `body`, `admin_overage_billing_failed.subject` / `body` |
| `portal.php` | 23 keys — among them `error_past_due_booking`, `error_subscription_ended`, `overage_debt_banner`, `usage_overage_locked_past_due`, `usage_history_outstanding_overage`, `next_charge.overage_debt`, `scheduled_change_retry` / `_dropped*`, `balance_charge.*`, `undercollection_line.*`, `prorata_refund_only`, `usage_carried_over`, `products.confirm.total`, `payment_method.update_timeout` / `check_again`, `upgrade_first_charge`, `flash.scheduled_change_not_yet`, `usage_reasons.overage_debt_deferred` | — |

A published file that lacks a key shows the raw key to the customer. Compare your copies with
the package's `resources/lang`, or publish again and re-apply your wording.

**Views** (`--tag=mollie-billing-views`). Two kinds of file changed, and they behave
differently when published:

- **Blade components** — `components/next-charge.blade.php` changed (it prints the overage
  debt and `total_gross`), `components/change-notices.blade.php` is new. A published copy of
  `next-charge` overrides the package's and shows neither; compare and merge it.
- **Checkout component** — `components/checkout/step-confirm.blade.php` names the first
  charge under a fully covering code (`dusk="confirm-first-charge"`); a published copy does
  not.
- **Livewire screens** — a published copy of these is *not read* by Livewire (see "Publishing
  the views does not override Livewire components" in [decisions.md](decisions.md)), so there
  is nothing to merge; the package's own files are used. They are listed because an
  application that copied one as the starting point of a screen of its own will want to see
  what changed:
  - portal: `dashboard`, `plan-change`, `seats`, `addons`, `products`, `invoices`,
    `billing-data`, `usage-history`, `components/usage-meter`
  - checkout: `checkout`, `components/checkout/step-plan`, `components/checkout/step-addons`
  - admin: `billables/subscription-tab`, `billables/invoices-tab`, `coupons/create`,
    `coupons/show`, `past-due/index`, `refunds/index`, `scheduled-changes/index`

### 2.9 A recurring code's free periods are billing periods, not 30-day blocks

A recurring coupon limited by `discount_periods` now counts the subscription's own billing
periods — calendar months or years on its billing day, shortened where a month does not have
it (31 Jan → 28 Feb → 31 Mar) — instead of `N × 30` (or `365`) days. For a fully covering code
that moves the first charge onto the regular billing day:

| A 100 % code on a cycle on the 31st | First charge before | First charge now |
|---|---|---|
| 3 periods, checkout on 31 January | 3 May, and the 3rd from then on | 30 April, then the 31st |
| 1 period, typed into the dashboard while the next charge is 28 February | 1 April, and the 1st from then on | 31 March, then the 31st |
| 3 periods, 14-day trial from 17 January (ending 31 January) | 19 April, and the 19th from then on | 30 April, then the 31st |

What to check:

- **Texts that promise a date or a number of days.** The marker's `valid_until` is now the day
  before the first charge it does not cover, and a fully covering code's first charge is
  `subscription_meta.active_recurring_coupon.charge_resumes_at` — the same date as
  `charge_deferred_until`, the portal's next charge and Mollie's start date. A mail or screen of
  your own that computed "`valid_until` + 1 day" or "signup + N × 30 days" names another day
  than the one charged; read `NextChargeSummary::for()['date']` or `ChargeDeferral::nextChargeDate()`.
- **A trial in front of the code.** The periods start at the trial end, not at the signup: the
  trial no longer uses part of them.
- **A code entered with a plan, seat or addon change** counts the running period as its first
  when the code discounts the change's own charge (the prorata of an upgrade); otherwise its
  periods start with the next charge. Before, they were counted from the day of the change.
- **A code with a fixed end date** (`valid_until`) is first charged on the first regular
  charge on or after that day — not on the day after it. A code that ends at noon on a
  renewal day still covers that renewal.
- **A trial in front of a fully covering code ends at the trial end.** The subscription
  becomes `active` at the first run of `ProcessTrialLifecycleJob` after the trial end, with
  its period starting there and the full quota; `TrialConverted` is dispatched at that moment
  (not with the first charge, months later). `TrialConvertedNotification` is not sent for
  such a trial — it describes a charge, and the first charge's invoice mail goes out as
  usual. A listener that treated `TrialConverted` as "money arrived" needs a look.
- **Free plan periods** (`plan_waiver`, "plan free for N periods") are counted on the billing
  day too: from a next charge on 28 February of a cycle on the 31st, two periods end on
  30 April (it was 28 April, and the 28th from then on).
- **An interval change that charges nothing** (a recurring code covers its whole charge)
  starts the new cycle on the day of the change, as a charged one always did.
- **The checkout's confirm step** names the first charge of a code that covers the whole
  order (`checkout.full_coverage_redirect_notice`, new in `en` and `de`), and the portal names
  the day of a deferred charge until Mollie's charge has arrived.
- **Nothing that is running moves.** A marker written before this release carries no
  `charge_resumes_at` and keeps its date (`valid_until + 1 day`), at Mollie and in the books.
- **`CouponService`**: `recurringMarkerValidUntil()` keeps its signature and counts on `from`'s
  own day; new are `recurringMarkerValidUntilOnCycle()`, `recurringChargeResumesAt()`,
  `recurringAnchorDay()`, `recurringLifetimeFrom()`, `applyRecurringMarkerFrom()`,
  `computeDiscountOfMarkerOn()` and `markerChargeResumesAt()`. A plan, seat or addon change
  now prices Mollie's amount with the discount as it will be on the next charge — a code that
  ends before it is no longer sent. An app that overrides `applyRecurringMarker()` is no longer called
  for the trial signup's marker (that is `applyRecurringMarkerFrom()`).

### 2.10a The Mollie subscription id can change while the subscription runs

**What.** Mollie refuses a new start date on a subscription it has already charged (422 "The
start date cannot be changed when the subscription is already charged", measured on
17 November 2026; not in Mollie's reference). Wherever the package has to move the next charge
of such a subscription — the anchor day after a short month, a trial or period extension, a
fully covering code or a plan waiver given mid-subscription, an interval change, the past-due
reset — it now **replaces** the subscription: the old one is cancelled at Mollie and a new one
is created with the same amount (or the new one), interval, description, metadata, mandate and
webhook, first charged on the new date. Before, the PATCH failed, and the date stayed where
Mollie had it (the anchor day was lost, an extension was charged early, an interval change
kept the old cadence) — the retry of that PATCH could never succeed.

**What to check.**

- **`subscription_meta.mollie_subscription_id` is not permanent.** Code of your own that stores
  it elsewhere, or compares a payment's `subscriptionId` with it, must allow a change. The event
  `MollieSubscriptionReplaced` (`previousSubscriptionId`, `subscriptionId`, `startDate`,
  `reason`: `anchor_pin`, `next_charge_date`, `amount_update`) is the seam; it is also written
  to the audit log (`mollie_subscription_replaced`, new key in `en` and `de` — re-publish
  translations if you published them).
- **Payments of the old subscription keep arriving** (a SEPA renewal that settles days later,
  its refund, a failure) and are booked as before: the webhook finds the billable through the
  payment's metadata. In the Mollie dashboard the customer has one cancelled and one active
  subscription with the same description.
- **A new marker and a new job.** `subscription_meta.pending_subscription_replacement` holds a
  replacement that was started and not finished (the old subscription is cancelled first, so
  nothing charges twice); `RetrySubscriptionReplacementJob` finishes it, dispatched a minute
  after the failure and by the hourly `SweepPendingRetriesJob`. It does not give up; the admins
  get one `AdminPlanChangeFailedNotification` after 24 hours. Nothing to register.
- **Cancelling a subscription Mollie has already cancelled** (422 "The subscription has been
  cancelled.") now counts as done in `CancelSubscription`, `RetrySubscriptionCancelJob` and the
  refund of a renewal after the end. Before, it was retried for 24 hours and then mailed.
- **A failed anchor-day PATCH is retried** (`pending_subscription_patch`, reason
  `anchor_pin`) instead of only being logged — only when no other PATCH is waiting there.

### 2.10 Smaller changes worth knowing

- **`NextChargeSummary::for()`** returns two more keys: `overage_debt` (`['net' => …,
  'gross' => …]` or `null`) and `total_gross`. `gross` is still the subscription's own charge;
  `total_gross` is what leaves the account on that date. For a customer who is past due only
  for an overage debt the state is now "scheduled" with the next renewal, not "past due". A
  custom rendering should print `total_gross`:

  ```php
  $summary = app(NextChargeSummary::class)->for($organization);

  $amount = $summary['total_gross'] ?? $summary['gross'];
  $debt   = $summary['overage_debt']['gross'] ?? 0;
  ```

- **The refund idempotency key contains the Mollie payment id.** It used to start with the
  local invoice id, which collided between two installations on one Mollie account (staging
  beside production, a restored database). Nothing to change — but a refund that is retried
  *across the deployment* of this release is sent under a new key and is not recognised by
  Mollie as the same attempt. Do not deploy in the middle of a refund you are retrying by hand.
- **`CouponService::create()`** throws `InvalidArgumentException` for a code that already
  exists (case-insensitively) instead of letting the unique index throw a `QueryException`.
- **A dated recurring coupon** that ends before the next charge no longer discounts that
  charge; the checkout refuses such a code at the field when a trial lies in front of the
  first charge — and drops one already applied when a plan with a trial is picked
  afterwards, or when the trial outlasts it by the time the order is submitted.
- **An app that overrides `getBillingAddonQuantity()`** now sees that quantity in the addons
  screen's "due now" quote for the addons a toggle keeps, as in the change itself; the quote
  used to count each of them once.
- **A plan's `description`** in the catalog is now printed on the checkout's plan step. It was
  a documented field that nothing showed; check what you wrote there.
- **Usage reasons** that contain whitespace are shown as written on the customer's usage page;
  identifiers are still turned into a headline.
- **The admin "Retry"** in the past-due list starts a real collection cycle for a standing
  overage debt and counts as one of its three further attempts.

### 2.11 Configuration

No configuration key was added or renamed in this release, and nothing has to be re-published.
The limits of the automatic resolution are class constants, not config:
`RefundUnassignedPaymentsJob::GRACE_DAYS` (14),
`ScheduleSubscriptionChange::MAX_APPLY_ATTEMPTS` (3),
`ChargeUsageOverageDirectly::MAX_RENEWAL_RECOLLECTIONS` (3) and `RECOLLECT_AFTER_DAYS` (3),
`SyncSeatsJob::MAX_REQUEUES` (5).

One meaning changed since 0.4.1: `checkout_countries.include` only offers countries that can
be invoiced. A non-EU code listed there alone is ignored and reported by
`billing:check-config`; declare it under `additional_countries` instead.

The cache store matters more than before: the change lock, the refund lock and the seat
lock live in it. On the `array` or `null` store they guard nothing across requests —
`billing:check-config` reports that. (The five-second repeat guard of the admin extensions
does not depend on it: it is kept on the billable's row, in
`subscription_meta.admin_extension_guard`.)

### 2.12 Checkout draft, return page and confirm button (0.7.5)

- **The checkout resumes an unfinished order.** What was typed and picked is kept in the
  **session** and restored on a reload or a later visit (see § 3). It is filed per signed-in
  user (default guard), billable and checkout URL, so nothing has to be done for logout or
  account switches — but a logout that does **not** invalidate the session (Laravel's default
  `logout` does) keeps the previous user's draft in that browser until it expires; it is
  never shown to anyone else. Apps whose own tests open the checkout twice in one session and
  expect an empty form either use a fresh session or forget `CheckoutDraft::SESSION_KEY`.
- **The confirm button follows the booking path.** "Confirm and pay" now appears only when
  the first period is paid at once. A trial reads "Start trial and add payment method", a code
  covering the first charge "Confirm and add payment method", a free plan "Activate free
  plan", an access grant "Activate access" — new keys in `checkout.php` (`confirm_start_trial`,
  `confirm_add_payment_method`, `confirm_activate_free`, `confirm_activate_access`,
  `free_activation_notice`, `access_grant_notice`). The free and grant paths no longer say
  "you will be redirected to Mollie". An access grant or a free plan that carries trial days no
  longer announces a trial (the service never started one). Check own tests or texts that
  looked for "Confirm and pay" on these paths. Whether your trial button needs
  "zahlungspflichtig" wording (§ 312j BGB) is a legal decision for your shop; override the key.
- **The return page opened without a checkout** (bookmark, typed in, reopened after a reported
  failure) no longer shows "Activating your subscription…" and polls; it says "No
  subscription has been taken out yet" and links to the checkout. New keys in `portal.php`:
  `return.no_subscription_title`, `return.no_subscription_body`, `return.continue_checkout`.
- **Published views and translations** have to be re-published to get any of this:
  `livewire/billing/checkout.blade.php`, `livewire/billing/return.blade.php`,
  `livewire/billing/components/checkout/step-confirm.blade.php`, and `checkout.php` /
  `portal.php` in every published locale (`en`, `de` ship with the package).

### 2.13 Earlier entries of this release

The release also contains the changes that were already listed under `[Unreleased]` before
this round — among them a plan change that lands on `max(used seats, included seats)`,
Switzerland / Monaco / Isle of Man no longer being invoiceable by default, overage in a trial
being charged, and the billing period following Mollie's charge date. They are described in
the [changelog](../CHANGELOG.md) and are not repeated here.

## 3. Optional — new possibilities

- **Checkout draft** (0.7.5). On by default; two keys tune it:

  ```php
  'checkout_draft_ttl_minutes' => 1440,          // since the last change; 0 = off
  'checkout_draft_custom_data' => ['terms'],     // customData keys of your own steps to keep
  ```

  List in `checkout_draft_custom_data` only plain answers (a ticked box, a chosen option),
  never a confirmation code or anything your step verifies server-side: those steps are shown
  again on resume, which is the point. See [configuration.md](configuration.md).

- **Hidden usage types** (after 0.5.1). `'visible' => false` on a usage type keeps it off the
  plan cards of the checkout and the plan-change screen, while it is credited, metered and
  charged like any other and stays on the usage meters, the plan-change preview and invoices:

  ```php
  'usage_types' => [
      'SMS' => ['rollover' => false, 'visible' => false],
  ],
  ```

  `billing:check-config` warns when such a type carries an overage price. See
  [configuration.md](configuration.md#hidden-usage-types).
- **Hidden features.** `'visible' => false` on a feature keeps a technical feature code off
  the plan and addon cards while it still unlocks like any other:

  ```php
  'features' => [
      'internal-beta' => ['name' => 'Internal beta', 'visible' => false],
  ],
  ```

- **Unit price on product cards.** `'show_unit_price' => true` on a product with a
  `usage_type` and a `quantity` above 1 shows "€0.0833 per SMS" under the price — gross or
  net as the price beside it. New translation key `portal.products.unit_price` (`en`, `de`);
  re-publish translations if you published them. See [configuration.md](configuration.md).

- **Per-seat addons.** An addon interval can carry `seat_price_net` next to `price_net`, and
  an addon `included_seats`; billed addon seats are `max(0, seats − included_seats)`. The
  checkout's extras step now says how many seats are included. See
  [configuration.md](configuration.md).
- **Restrict a discount to plans or intervals** from the admin coupon form
  (`applicable_plans`, `applicable_intervals` — settable in code before).
- **Show an open overage debt in your own UI.** `NextChargeSummary::for()['overage_debt']`,
  or the `<x-mollie-billing::next-charge>` component, which lists it.
- **Listen for the new events** to feed your own reporting: `UnwantedPaymentRefunded`,
  `OverageWrittenOff`, `CouponRedemptionRecordMissing`, and `RenewalUndercollected` with
  `chargePaymentId`.
- **Return a payment you cannot deliver for** with the package's building block instead of a
  bare Mollie refund — it writes the invoice and the credit note, retries, and tells the
  customer:

  ```php
  app(UnwantedPaymentRefund::class)->settle($payment, $billable, $reasonKey, $lineLabel);
  ```

  A new reason key needs its sentences under
  `billing::notifications.unwanted_payment_refunded.reasons.*`.
- **Test against real Mollie.** `composer test:sandbox:browser` pays a checkout on Mollie's
  hosted test page; `composer test:sandbox:canary` watches long-lived subscriptions. See
  [sandbox-testing.md](sandbox-testing.md).

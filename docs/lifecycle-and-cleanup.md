# Lifecycle and Cleanup

This document describes what happens to billables in the boundary cases of the subscription lifecycle: abandoned checkouts, trials that never produce a mandate, cancellations, deletions of the billable record itself, persistent payment failures, and webhooks that reference billables we can no longer resolve.

The goal of this page is to give integrators a precise picture of which states the package handles automatically and which require the host application to step in.

## Quick reference

| Scenario | Handled by the package | Host app must do |
|---|---|---|
| 1. Checkout started, never completed | `CleanupOrphanedBillablesJob` (every 15 min) | Register `MollieBilling::cleanupOrphanedBillableUsing()` if the billable record should be hard-deleted with side effects |
| 2. Trial / mandate-only started, mandate never arrives | Same as 1 (orphan cleanup) plus `ProcessTrialLifecycleJob` once the mandate is captured | – |
| 3. Subscription cancelled | `CancelSubscription` (immediately or end-of-period) + `PrepareUsageOverageJob` Pass 3b (`cancelled → expired`) | Decide whether to revoke the Mollie mandate (the package leaves it alive on purpose) |
| 4. Billable record deleted by the host app | – | Cascade delete of `wallets`, `billing_vat_validations`, `billing_country_mismatches`, `coupon_redemptions` (invoices are usually retained for legal reasons) |
| 5. Stuck in `past_due` indefinitely | `PrepareUsageOverageJob` Pass 3a auto-cancels after `past_due_max_days` (default 30) | Adjust `BILLING_PAST_DUE_MAX_DAYS` to taste, or set `0` to keep the legacy behavior |
| 6. Paid webhook for an unresolvable billable | Logs a warning + sends `AdminPaidWithoutBillableNotification` | Reconcile manually — the money cleared at Mollie but no local record was touched |

## 1. Checkout started, never completed

A checkout flow has several side effects before the user finishes paying. `StartMandateCheckout::ensureMollieCustomer()` creates a Mollie Customer and persists `mollie_customer_id` on the billable **before** Mollie answers. `StartSubscriptionCheckout::handle()` then creates a Mollie Payment via `CreatePaymentRequest`. If the user closes the browser at this point, the local record stays at `subscription_source = null` / `subscription_status = null` (or `new`) and Mollie holds an `open` payment that will eventually transition to `expired` on its own.

**`CleanupOrphanedBillablesJob`** ([src/Jobs/CleanupOrphanedBillablesJob.php](../src/Jobs/CleanupOrphanedBillablesJob.php)) handles this. Default schedule: every 15 minutes via the package service provider; configurable through `mollie-billing.cleanup.cron_expression`.

Detection is hybrid:

- **If** the billable has a `pending_first_payment_id` in `subscription_meta`, Mollie is polled. The record is cleaned **only** when the payment is in a terminal failure state (`failed`, `canceled`, `expired`).
- **Otherwise**, the billable is cleaned purely on age — older than `mollie-billing.cleanup.threshold_minutes` (default 60).

The job calls `MollieBilling::runCleanupOrphanedBillable()` first, then dispatches `RevokeMollieMandateJob` to release the mandate on Mollie's side (relevant when the user captured a mandate but never paid the first invoice). The mandate-revoke call uses a snapshot of the customer/mandate IDs taken before the cleanup closure runs, so it still works after the closure has deleted the underlying row. If you have not registered `MollieBilling::cleanupOrphanedBillableUsing()`, the fallback is `$billable->delete()` — fine for soft-deleting models, **not** sufficient if the billable represents a tenant whose user records, auth tokens, or other tables need to go too.

The Mollie Customer object itself is **not** deleted from Mollie. Mollie allows orphaned customers; this is a deliberate non-decision rather than a bug.

A `CheckoutAbandoned` event fires for every cleaned billable so apps can hook into the deletion (analytics, audit trail).

**Vetoing cleanup from the closure.** The query is intentionally permissive — any row with `subscription_source ∈ {null, None}` and `subscription_status ∈ {null, New}` older than the threshold matches, including users that legitimately exist without a subscription (admins, employees, internal accounts). The cleanup closure may return `false` to declare such a row "not actually orphan"; the job then suppresses **all** side-effects: no `CheckoutAbandoned` event, no mandate revocation, no log entry. Returning `true` or `void` keeps the legacy behaviour.

```php
MollieBilling::cleanupOrphanedBillableUsing(function ($billable): bool {
    if ($billable instanceof User && ($billable->isAdmin() || $billable->isEmployee())) {
        return false; // veto — leave the row untouched
    }

    $billable->delete();

    return true;
});
```

**Configuration**

```php
// config/mollie-billing.php
'cleanup' => [
    'enabled' => env('BILLING_CLEANUP_ORPHANED_ENABLED', true),
    'threshold_minutes' => (int) env('BILLING_CLEANUP_ORPHANED_THRESHOLD_MINUTES', 60),
    'cron_expression' => env('BILLING_CLEANUP_ORPHANED_CRON', '*/15 * * * *'),
],
```

```php
// AppServiceProvider::boot()
MollieBilling::cleanupOrphanedBillableUsing(function ($billable) {
    // Return false to veto: an admin / employee / internal user that matches
    // the cleanup query but should never be deleted.
    if ($billable instanceof User && ($billable->isAdmin() || $billable->isEmployee())) {
        return false;
    }

    DB::transaction(function () use ($billable) {
        $billable->users()->delete();
        $billable->personal_access_tokens()->delete();
        $billable->delete();
    });
});
```

## 2. Trial / mandate-only started, mandate never arrives

When a trial-eligible plan is checked out, `StartSubscriptionCheckout` routes to `StartMandateCheckout` — a `0.00` payment with `sequenceType=first` whose only purpose is to capture a mandate. If the user never returns from Mollie's hosted page, no `mandate_only` webhook ever fires.

Until the mandate is captured the billable still has `subscription_source ∈ {null, None}` and `subscription_status ∈ {null, New}` — exactly the shape `CleanupOrphanedBillablesJob` matches. Scenario 1 covers it.

Once the mandate arrives and the billable transitions to `subscription_status = Trial`, the trial is owned by a different job. **`ProcessTrialLifecycleJob`** ([src/Jobs/ProcessTrialLifecycleJob.php](../src/Jobs/ProcessTrialLifecycleJob.php)) runs daily (`mollie-billing.trial_lifecycle_job_time`, default `02:05 UTC`) and:

- Sends `TrialEndingSoonNotification` `trial_ending_soon_notice_days` ahead of `trial_ends_at`.
- After `trial_ends_at` passes without a successful first charge, flips the billable to `PastDue`. Recovery is then driven by Mollie's own retry schedule or by manual user action in the portal.

## 3. Subscription cancelled

`CancelSubscription::handle($billable, bool $immediately)` ([src/Services/Billing/CancelSubscription.php](../src/Services/Billing/CancelSubscription.php)) behaves differently depending on the subscription source:

- **Local** subscription: status flip only. No Mollie call.
- **Mollie** subscription: `CancelSubscriptionRequest` is sent to Mollie. Mollie SDK exceptions are caught and logged so we never block on a temporary API outage. The local status moves to `Cancelled` and `subscription_ends_at` is set to the end of the current period (or `now()` when `$immediately === true`).

When `$immediately === true`, a final overage charge runs immediately via `ChargeUsageOverageDirectly` — so any open wallet debt is settled before the subscription dies.

`PrepareUsageOverageJob` Pass 3b ([src/Jobs/PrepareUsageOverageJob.php](../src/Jobs/PrepareUsageOverageJob.php)) then completes the transition once `subscription_ends_at` is in the past: `cancelled → expired` with `subscription_source = None`.

### Mandate policy: the mandate stays alive after cancel/expire

The Mollie **mandate** is intentionally **not** revoked when a subscription is cancelled or expired. This is a deliberate design choice:

- **Re-subscribe in the grace period** — `ResubscribeSubscription::handle()` ([src/Services/Billing/ResubscribeSubscription.php](../src/Services/Billing/ResubscribeSubscription.php)) calls `CreateSubscription` to rebuild a Mollie subscription on the existing customer. That call requires a valid mandate; without one, Mollie rejects the request. Revoking on cancel would force every resubscribe through a fresh checkout.
- **Immediate-cancel overage charge** — `CancelSubscription($immediately=true)` triggers `ChargeUsageOverageDirectly`, which sends a `CreatePaymentRequest` with an explicit `mandateId`. Revoking before the charge would zero out the final overage.
- **Mollie's own contract** — cancelling a Mollie subscription does not invalidate the mandate. A mandate exists on the customer level and is reusable for new subscriptions or one-off charges.

The mandate only gets revoked via `RevokeMollieMandateJob` in **one** path: when `CleanupOrphanedBillablesJob` decides to delete an abandoned billable. There, the billable goes away entirely, so we revoke the mandate alongside.

If your business model needs to revoke mandates eagerly (e.g. for GDPR data-minimisation), do this in a host-app listener on the `SubscriptionExpired` event (or on whatever boundary you choose) — but be aware that you forfeit fast resubscribes and lose the ability to settle late overages.

### Wallets and invoices after cancel

Wallets are **not** wiped on cancel. Negative balances (open overages) persist. Pass 3b's `cancelled → expired` flip does not touch wallets either. The cancel-immediately overage charge is the only place a final balance settlement runs; for end-of-period cancellations, Pass 1 Case B charges one last time the day before the period ends.

`BillingInvoice` rows are retained indefinitely. EU fiscal rules typically require seven to ten years of invoice retention — the package does not enforce this, but it does ensure that invoices outlive subscriptions and customers.

## 4. Billable record deleted by the host app

The package has **no** model observer or `deleting`/`deleted` hook on `HasBilling`. When the host app hard-deletes a billable, the polymorphic `billable_type` / `billable_id` columns across these tables are left dangling:

| Table | Migration | Cascade behavior |
|---|---|---|
| `billing_invoices` | [2026_01_01_000001](../database/migrations/2026_01_01_000001_create_billing_invoices_table.php) | None — kept on purpose (legal retention) |
| `billing_country_mismatches` | [2026_01_01_000002](../database/migrations/2026_01_01_000002_create_billing_country_mismatches_table.php) | None |
| `coupon_redemptions` | [2026_01_01_000004](../database/migrations/2026_01_01_000004_create_coupon_redemptions_table.php) | None |
| `billing_processed_webhooks` | [2026_01_01_000005](../database/migrations/2026_01_01_000005_create_billing_processed_webhooks_table.php) | No billable FK at all — keyed on `mollie_payment_id` |
| `billing_vat_validations` | [2026_05_03_000001](../database/migrations/2026_05_03_000001_create_billing_vat_validations_table.php) | None |
| `wallets` (`bavix/laravel-wallet`) | [2026_01_01_000006](../database/migrations/2026_01_01_000006_alter_wallet_morphs_to_match_billable_key_type.php) | None |

This is a deliberate trade-off — polymorphic foreign keys cannot use database-level `cascadeOnDelete`, and the host app is in a better position to know which side effects must happen alongside a billable deletion. The package supplies the entry point (`cleanupOrphanedBillableUsing`) but no opinionated default cascade.

If you delete billables in production, register a cleanup closure or a model observer in your `AppServiceProvider`:

```php
// AppServiceProvider::boot()
Organization::deleting(function (Organization $org) {
    $org->wallets()->delete();
    BillingVatValidation::where('billable_type', $org->getMorphClass())
        ->where('billable_id', $org->getKey())
        ->delete();
    BillingCountryMismatch::where('billable_type', $org->getMorphClass())
        ->where('billable_id', $org->getKey())
        ->delete();
    CouponRedemption::where('billable_type', $org->getMorphClass())
        ->where('billable_id', $org->getKey())
        ->delete();
    // Intentionally keep BillingInvoice rows for fiscal retention.
});
```

`CleanupOrphanedBillablesJob` does **not** call this closure — it has its own entry point (`cleanupOrphanedBillableUsing`). Apps that want a single cleanup path can have the closure call into the same code as the model observer.

## 5. Stuck in `past_due` indefinitely

`past_due` is set in two places:

- **`MollieWebhookController::handleSubscriptionPaymentFailed`** — one `failed` webhook is enough to flip the status. The webhook stores `subscription_meta.payment_failure` (failure reason + timestamp) and `subscription_meta.past_due_since` (UTC ISO 8601).
- **`RetryUsageOverageChargeJob`** — after three failed overage charge attempts (backoff 60s, 5min, 15min) the status flips to `past_due` and `past_due_since` is recorded.

Mollie retries the recurring charge on its own schedule (≈14 days). A successful retry triggers `handleSubscriptionPaymentPaid` and — via the prorata charge path or a successful recovery payment — clears `payment_failure` + `past_due_since` and moves the status back to `active`.

If Mollie gives up entirely the billable would, **without** Pass 3a, sit in `past_due` forever. `RequireActiveSubscription` middleware locks the app out, but the database state never finalizes — making KPI counts, churn analysis, and seat-license freeing impossible.

**`PrepareUsageOverageJob` Pass 3a** closes this. After `mollie-billing.past_due_max_days` (default 30) of unbroken `past_due`, the billable is transitioned to `cancelled` with `subscription_ends_at = now()`. The next run's Pass 3b then flips it to `expired`.

Recovery still works up to the cutoff — Mollie retries are usually exhausted within 14 days, so a default of 30 days gives a comfortable buffer plus a deliberate "we tried" grace period.

**Configuration**

```php
// config/mollie-billing.php
'past_due_max_days' => (int) env('BILLING_PAST_DUE_MAX_DAYS', 30),
```

Set `BILLING_PAST_DUE_MAX_DAYS=0` to disable auto-cancel entirely and keep the pre-1.x behavior (manual finalisation only).

**Edge case — legacy billables without `past_due_since`** — billables that were already `past_due` before this feature shipped do **not** have a `past_due_since` marker. Pass 3a skips them on purpose; only `past_due` periods that started **after** the upgrade are eligible for auto-cancel. If you need to backfill, set `subscription_meta.past_due_since` to a timestamp of your choice (e.g. the upgrade date) via a one-time script.

## 6. Paid webhook for an unresolvable billable

Edge case, but real: Mollie reports `paid` and the payment's metadata (`billable_type`, `billable_id`) points to a record that no longer exists. Causes include the billable being deleted between checkout and webhook (rare but possible — webhooks can lag minutes), stale metadata from a long-buried test payment, or the billable model class being renamed without metadata migration.

`MollieWebhookController::route()` catches this in the `paid` branch and calls `reportPaidWithoutBillable()`:

1. Logs a `warning` with the payment id, attempted billable type/id, amount in cents, and currency.
2. If `MollieBilling::notifyAdminUsing()` is registered, sends `AdminPaidWithoutBillableNotification` to the registered admin recipients.
3. Marks the webhook as processed (via the `BillingProcessedWebhook` reservation) and returns `200` to Mollie so it does not keep redelivering.

The money has cleared at Mollie. The package will **not** issue an invoice (no billable to address it to), **not** credit any wallets, and **not** make subscription transitions. The notification is the prompt to reconcile manually — refund the customer, or recreate the billable and post-process via an artisan command of your own.

**Configuration**

```php
// AppServiceProvider::boot()
MollieBilling::notifyAdminUsing(fn () => [
    new AnonymousNotifiable(['mail' => 'finance@example.com']),
]);
```

## Related

- [docs/subscription-lifecycle.md](subscription-lifecycle.md) — state machine and normal flows
- [docs/configuration.md](configuration.md) — full config reference
- [docs/usage-billing.md](usage-billing.md) — overage charging and wallet credits
- [docs/refund-management.md](refund-management.md) — refunds and credit notes

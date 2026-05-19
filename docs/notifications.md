# Notifications

The package dispatches several built-in `Illuminate\Notifications\Notification` classes on lifecycle events (trial reminders, payment failures, invoices, refunds, usage thresholds, admin alerts, etc.).

There are three layers at which an application can customize them.

## 1. Choose who receives them

Recipient resolution is fully delegated to closures registered on the `MollieBilling` facade. The package never assumes `auth()->user()` — it always asks your app.

```php
// AppServiceProvider::boot()
use GraystackIT\MollieBilling\Facades\MollieBilling;

// Billing-admin notifications (per billable, e.g. organisation owners)
MollieBilling::notifyBillingAdminsUsing(function ($billable) {
    return $billable->users()->wherePivot('billing_admin', true)->get();
});

// Platform-admin notifications (your team)
MollieBilling::notifyAdminUsing(function () {
    return User::where('is_platform_admin', true)->get();
});
```

Return an empty array to suppress a particular delivery entirely.

## 2. Override the text via translations

All shipped notifications use the `billing::notifications.*` and `billing::emails.*` translation keys. Publish them and edit at will:

```bash
php artisan vendor:publish --tag=billing-lang
```

Subjects, body lines, button labels and signatures live in `resources/lang/vendor/billing/{locale}/notifications.php`. Laravel resolves vendor translations first, so app overrides take precedence over package defaults. See [translations.md](translations.md) for the full file list and placeholder reference.

## 3. Swap a notification class entirely

When wording changes are not enough — different channels (Slack, database, push), Markdown templates, MJML, extra CTAs, additional data — replace the notification class itself via `useNotification()`:

```php
// AppServiceProvider::boot()
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use App\Notifications\TrialEndingSoonMail;

MollieBilling::useNotification(
    TrialEndingSoonNotification::class,
    TrialEndingSoonMail::class,
);
```

From that point on, every `Notification::send(..., new TrialEndingSoonNotification(...))` site inside the package transparently dispatches `TrialEndingSoonMail` instead. The constructor of the replacement class must accept the same positional arguments as the original (typically the `Billable`, optionally extra context such as an invoice or payment id — see the source of the original class for the exact signature).

`useNotification()` registers the swap globally for the current process — register it once in your service provider.

## Notification reference

### To billing admins of the billable

| Class | Trigger | Constructor signature |
|-------|---------|----------------------|
| `TrialEndingSoonNotification` | Trial ends in `trial_ending_soon_notice_days` days, no mandate captured yet. CTA: add payment method. | `(Billable $billable)` |
| `TrialConvertedNotification` | Trial ends soon and a Mollie mandate is on file. Informational: first charge will happen on `trial_ends_at`. | `(Billable $billable)` |
| `TrialExpiredNotification` | Trial passed without a successful first charge — billable was flipped to `PastDue`. | `(Billable $billable)` |
| `InvoiceAvailableNotification` | A new invoice (subscription renewal, one-time order, plan-change proration) has been generated. | `(Billable $billable, BillingInvoice $invoice)` |
| `SubscriptionCancelledNotification` | Subscription cancelled (immediate or end-of-period). | `(Billable $billable)` |
| `SubscriptionPaymentFailedNotification` | Recurring subscription payment failed. | `(Billable $billable, string $paymentId)` |
| `OverageBillingFailedNotification` | Wallet-overage charge failed after retries. | `(Billable $billable)` |
| `UsageThresholdNotification` | Wallet for a usage type hit the configured warning threshold (`usage_threshold_percent`). | `(Billable $billable, string $type, int $percent)` |
| `PlanChangeFailedNotification` | Immediate-prorata or scheduled plan change failed at the Mollie payment step. | `(Billable $billable, string $paymentId)` |
| `RefundProcessedNotification` | Refund / credit note issued. | `(Billable $billable, BillingCreditNote $creditNote)` |
| `CountryMismatchSelfNotification` | Declared country and IP/payment country don't match — request to resolve in portal. | `(Billable $billable, BillingCountryMismatch $mismatch)` |

### To the platform admin

| Class | Trigger | Constructor signature |
|-------|---------|----------------------|
| `AdminPaidWithoutBillableNotification` | Mollie webhook arrived for a payment whose billable cannot be resolved. | `(string $paymentId, ?string $billableType, ?string $billableId, ?int $amountCents, ?string $currency)` |
| `AdminOverageBillingFailedNotification` | Overage charge gave up after final retry. | `(Billable $billable, Throwable $exception)` |
| `AdminPlanChangeFailedNotification` | Plan change failed terminally — patch retries exhausted or stale pending state cleaned up. | `(string $reason, array $context = [])` |
| `AdminRefundFailedNotification` | Mollie refund API call failed. | `(Billable $billable, Throwable $exception)` |
| `CountryMismatchReissueFailedNotification` | Auto-reissue of a credit note + corrected invoice after a country correction failed. | `(Billable $billable, BillingCountryMismatch $mismatch, string $reason)` |

## Choosing the right layer

- **Just want to change the wording?** Publish translations (layer 2).
- **Want a different audience?** Adjust the `notifyBillingAdminsUsing` / `notifyAdminUsing` closures (layer 1).
- **Need a different mail layout, channel, or extra logic?** Swap the class with `useNotification()` (layer 3).

For one-off automations triggered by lifecycle changes (e.g. "ping Slack when a trial expires"), listen to the matching event in `src/Events/` (`TrialExpired`, `SubscriptionCancelled`, `OverageCharged`, …) instead of overriding the notification.

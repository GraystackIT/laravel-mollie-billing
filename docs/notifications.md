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

### Recipients must be notifiable

Whatever the closures return is passed straight to `Notification::send()`, so every recipient has to be a real notifiable: an Eloquent model using `Illuminate\Notifications\Notifiable`, or an `Illuminate\Notifications\AnonymousNotifiable`. The package does not wrap or validate them.

This bites most often when the closure returns the **billable itself** — a tenant/organisation model is usually not notifiable, and the failure only surfaces later inside the queued `SendQueuedNotifications` job as:

```
BadMethodCallException: Call to undefined method App\Models\Organization::routeNotificationFor()
```

If you want the billable to be the recipient, add the trait and route the mail channel explicitly — tenant models rarely have an `email` column, which is what `Notifiable` reads by default:

```php
use Illuminate\Notifications\Notifiable;

class Organization extends Model implements Billable
{
    use HasBilling, Notifiable;

    // Returning null makes Laravel skip delivery instead of failing in the mailer.
    public function routeNotificationForMail(): ?string
    {
        return $this->getBillingEmail() ?: null;
    }
}
```

Otherwise return actual users (`$billable->billingAdmins`) or `new AnonymousNotifiable(['mail' => $billable->getBillingEmail()])`.

## 2. Override the text via translations

All shipped notifications use the `billing::notifications.*` and `billing::emails.*` translation keys. Publish them and edit at will:

```bash
php artisan vendor:publish --tag=billing-lang
```

Subjects, body lines, button labels and signatures live in `resources/lang/vendor/billing/{locale}/notifications.php`. Laravel resolves vendor translations first, so app overrides take precedence over package defaults. See [translations.md](translations.md) for the full file list and placeholder reference.

### Queueing

Every shipped notification implements `ShouldQueue` and is routed onto the connection and queue from the [`queue`](configuration.md#queue) config block, so a mail-transport outage can no longer fail the webhook that triggered it — previously an SMTP error propagated out of `Notification::send()`, the webhook returned 500, Mollie re-delivered and the state event fired twice.

Two consequences for replacement classes registered via `resolveNotificationUsing()`:

- They must be serializable. `Illuminate\Notifications\Notification` already uses `SerializesModels`, so model properties are reloaded from the database when the job runs — capture any point-in-time value (an amount, an end date) in the constructor rather than reading it in `toMail()`. That is also why the package's own notifications no longer hold a `Throwable`: exception traces routinely carry closures, which makes the job permanently unqueueable.
- Money is rendered through `Support\BillingMoney`, using the invoice's own currency where one is stored.

#### Point-in-time values are captured in the constructor

Anything a notification *asserts about a past moment* is frozen when the notification is constructed and exposed as a public readonly property — scalars, or a `CarbonInterface` for dates. Reading it in `toMail()` instead would describe the state at delivery time, which for a queued mail can be the opposite of the truth: a mandate that arrived after the reminder was queued turned "please add a payment method" into "we will charge your saved card", and a plan downgrade inverted the overage hint on a usage warning.

| Class | Frozen at construction |
|-------|------------------------|
| `TrialEndingSoonNotification` | `$hasMandate`, `$trialEndsAt`, `$days` |
| `TrialExpiredNotification` | `$trialEndsAt` |
| `TrialConvertedNotification` | `$planName`, `$planCode`, `$amountGross`, `$currency` |
| `SubscriptionCancelledNotification` | `$endsAt` |
| `SubscriptionPaymentFailedNotification` | `$amountGross`, `$currency`, `$failedAt` |
| `UsageThresholdNotification` | `$overageAllowed` |
| `OverageBillingFailedNotification` | `$amountGross`, `$currency` (from `subscription_meta['usage_overage']`) |
| `AdminOverageBillingFailedNotification` | `$amountGross`, `$currency`, `$reason` |
| `AdminRefundFailedNotification` | `$invoiceId` (the refund target), `$reason` |

Invoice amounts are the exception: `InvoiceAvailableNotification` and `RefundProcessedNotification` read `amount_gross` / `currency` off the invoice model itself, and those columns are only ever written when the row is created — corrections issue a new document rather than rewriting an old one, so there is nothing to drift.

A replacement class registered via `useNotification()` is subject to the same rule: it is handed the live `Billable` and must capture what it needs, because by the time its `toMail()` runs the billable has been reloaded from the database.

### Choosing the locale per recipient

The package does not introduce its own "notification language" setting. Notifications render in whatever locale is active when `Notification::send()` runs — which for queued notifications is `config('app.locale')` at the moment the job executes, not the locale of the request that queued it.

To send each customer their preferred language, implement Laravel's `Illuminate\Contracts\Translation\HasLocalePreference` on the **notifiable** that `notifyBillingAdminsUsing()` / `notifyAdminUsing()` returns (typically your `User` model). Laravel calls `preferredLocale()` automatically before rendering the mail and switches the translator for the duration of that notification — no changes needed inside the package.

```php
use Illuminate\Contracts\Translation\HasLocalePreference;

class User extends Authenticatable implements HasLocalePreference
{
    public function preferredLocale(): string
    {
        return $this->locale ?? config('app.locale');
    }
}
```

If you store the language on the billable (e.g. `Organization`) rather than on the user, resolve it inside the closure:

```php
MollieBilling::notifyBillingAdminsUsing(function ($billable) {
    return $billable->billingAdmins->each(
        fn ($user) => $user->setAttribute('locale', $billable->locale)
    );
});
```

Combined with `HasLocalePreference` on `User`, every recipient gets the tenant's language regardless of their personal setting.

If you need a single hard-coded language for all billing mails (e.g. operator-only platform), the simplest route is to pin it in your queue worker / scheduler config (`APP_LOCALE=de`) — no package config required.

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
| `TrialEndingSoonNotification` | Trial ends in `trial_ending_soon_notice_days` days. Wording branches on `hasMollieMandate()`: with mandate → heads-up that the first charge will be made automatically; without mandate → CTA to add a payment method. | `(Billable $billable)` |
| `TrialConvertedNotification` | Mollie's first recurring charge has actually landed (fired from `SubscriptionPaymentHandler::paid()`, not from the lifecycle job). The converting charge's invoice is handed in so the mail names the amount that was really collected; without it the newest subscription invoice is used. | `(Billable $billable, ?BillingInvoice $invoice = null)` |
| `TrialExpiredNotification` | Trial passed without a successful first charge — billable was flipped to `PastDue`. | `(Billable $billable)` |
| `InvoiceAvailableNotification` | A new invoice (subscription renewal, one-time order, plan-change proration) has been generated. | `(Billable $billable, BillingInvoice $invoice)` |
| `SubscriptionCancelledNotification` | Subscription cancelled (immediate or end-of-period). | `(Billable $billable)` |
| `SubscriptionPaymentFailedNotification` | Recurring subscription payment failed. The amount, currency and failure date come from the Mollie payment at the call site — a failed payment produces no invoice, so looking one up returned an unrelated document (last month's renewal, or a credit note's negative gross). | `(Billable $billable, string $paymentId, ?int $amountGross = null, ?string $currency = null, ?CarbonInterface $failedAt = null)` |
| `OverageBillingFailedNotification` | Wallet-overage charge failed after retries. The amount is read from `subscription_meta['usage_overage']` — the failed charge never produced an invoice, so the previous version quoted an unrelated one. | `(Billable $billable)` |
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
| `AdminRefundFailedNotification` | Mollie refund API call failed. `$invoice` is the refund target — the invoice the refund was posted against, which is what an operator needs: the credit note was never written (the call failed before it) and the billable's newest invoice is an unrelated renewal or an earlier credit note. Omitting it falls back to a best-effort guess narrowed to paid, non-credit-note invoices. | `(Billable $billable, Throwable $exception, ?BillingInvoice $invoice = null)` |
| `CountryMismatchReissueFailedNotification` | Auto-reissue of a credit note + corrected invoice after a country correction failed. | `(Billable $billable, BillingCountryMismatch $mismatch, string $reason)` |

## Choosing the right layer

- **Just want to change the wording?** Publish translations (layer 2).
- **Want a different audience?** Adjust the `notifyBillingAdminsUsing` / `notifyAdminUsing` closures (layer 1).
- **Need a different mail layout, channel, or extra logic?** Swap the class with `useNotification()` (layer 3).

For one-off automations triggered by lifecycle changes (e.g. "ping Slack when a trial expires"), listen to the matching event in `src/Events/` (`TrialExpired`, `SubscriptionCancelled`, `OverageCharged`, …) instead of overriding the notification.

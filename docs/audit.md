# Audit trail

Every billing event is recorded against the billable and rendered as a timeline in the admin
panel — plan changes, invoices, payments, payment-method changes, promo code redemptions, trial
lifecycle, usage and VAT compliance.

The trail is built on [`spatie/laravel-activitylog`](https://spatie.be/docs/laravel-activitylog),
a hard dependency of this package.

## What gets stored

**Not a rendered sentence — a translation key plus raw values.** A row looks like this:

| column | value |
|---|---|
| `log_name` | `billing` |
| `description` | `audit.plan_changed` |
| `event` | `plan_changed` |
| `subject_type` / `subject_id` | the billable |
| `causer_type` / `causer_id` | the acting user, or `null` for webhooks, jobs and commands |
| `properties` | `{"category":"subscription","actor":"customer","replace":{"old_plan":"starter","new_plan":"pro","interval":"monthly"}}` |

Text is produced at render time from `resources/lang/{locale}/audit.php`. That is the point of the
design:

- the same row reads correctly in every locale, including rows written years ago;
- a plan renamed in the catalog updates retroactively across the whole history, because
  `replace.new_plan` holds the plan **code**, not its name at the time;
- amounts stay integer cents and are formatted by the current locale's rules.

`properties.replace` is always a flat map of scalars. Free-form event payloads (`request`, `diff`,
`pendingChange`, `lineItems`, `metadata`) are never dumped — each descriptor pulls out named fields
instead. Events carrying a `\Throwable` store the exception class and a truncated message, never a
stack trace.

## Reading the trail

```php
use GraystackIT\MollieBilling\Support\BillingAuditEntry;

foreach ($billable->billingAuditTrail()->paginate(20) as $activity) {
    $entry = new BillingAuditEntry($activity);

    $entry->title();        // "Plan changed from Starter to Pro (Monthly)"
    $entry->category();     // AuditCategory::Subscription
    $entry->causerLabel();  // "Jane Doe" — or the localised "System"
    $entry->occurredAt();   // Carbon
    $entry->meta();         // the raw replace values, for a technical detail view
}
```

`billingAuditTrail()` is a `MorphMany` scoped to the configured `log_name`, ordered newest first,
so an app that uses activitylog for its own purposes never bleeds into the billing timeline.

## Categories

| Category | Covers |
|---|---|
| `subscription` | created, cancelled, resumed, expired, updated, extended, upgraded from local, activation failed, plan changed / pending / failed, scheduled change (scheduled, rescheduled, cancelled, apply failed), seats, add-ons |
| `payment` | succeeded, failed, amount mismatch, duplicate, checkout started / abandoned, one-time orders, usage overage |
| `invoice` | created, refunded, credit note issued, PDF regenerated |
| `payment_method` | mandate updated |
| `coupon` | promo code redeemed, access grant revoked |
| `trial` | started, converted, expired, extended |
| `usage` | wallet credited, wallet reset, usage limit reached |
| `compliance` | country mismatch flagged / resolved |

## Configuration

```php
'audit' => [
    'enabled'  => env('BILLING_AUDIT_ENABLED', true),
    'log_name' => env('BILLING_AUDIT_LOG_NAME', 'billing'),

    'categories' => ['subscription', 'payment', 'invoice', 'payment_method',
                     'coupon', 'trial', 'usage', 'compliance'],

    'retention_days' => env('BILLING_AUDIT_RETENTION_DAYS', 3650),
],
```

**`categories`** — drop `usage` on high-volume metered setups. `UsageLimitReached` and
`WalletCredited` can fire on every request, and each one costs a synchronous insert.

**`retention_days`** — defaults to 10 years, which covers the statutory retention periods for
billing records in AT/DE (7 years) and most other EU jurisdictions at negligible storage cost.
`PruneBillingAuditJob` runs monthly and deletes older rows **scoped to `log_name`**, so the app's
own activitylog entries are never touched. Set to `null` to keep everything forever.

## Migrations

The package ships its own `activity_log` migration. **Do not also publish spatie's**
(`vendor:publish --tag=activitylog-migrations`) — you would end up with two migrations creating the
same table.

The reason we ship our own: spatie's stub uses `nullableMorphs()`, i.e. an `unsignedBigInteger`
subject id, while this package defaults `billable_key_type` to `uuid`. With spatie's schema the very
first audit insert fails. Our migration creates `subject_id` and `causer_id` as plain strings — the
table is polymorphic, so it must accept integer-keyed *and* uuid/ulid-keyed subjects and causers side
by side. It also consolidates spatie's three stubs into one table and adds a
`(subject_type, subject_id, created_at)` index for the timeline query.

If your app already had `activity_log` — because it uses activitylog independently — our create
migration is a no-op and a companion migration widens the morph columns **in place** (never
drop-and-recreate, so existing rows survive), and only when they are actually still numeric. Rolling
back is equally safe: `down()` only drops the table when our own migration created it, detected via a
marker index.

A third migration adds the `attribute_changes` column that spatie/laravel-activitylog **v5** requires
(v4 keeps model diffs inside `properties` and never writes it). It runs against any `activity_log`
table — ours, the app's, or one created by spatie's published migration — because without the column
every insert fails, and `RecordBillingAudit` deliberately swallows write errors, so the trail would
silently stay empty. The package supports `^4.12|^5.0`.

`php artisan billing:check-config` verifies all of this: table present, morph columns compatible
with your key types, and every audit translation key resolvable in the current locale.

## Adding an event to the trail

`src/Support/BillingAuditMap.php` is the single source of truth. Add a descriptor there:

```php
Events\MyNewEvent::class => self::make('my_new_event', AuditCategory::Subscription,
    fn (Events\MyNewEvent $e): array => ['plan' => $e->planCode]),
```

Then add `my_new_event` to **both** `resources/lang/en/audit.php` and `resources/lang/de/audit.php`.
The listener registration derives from the map automatically, and
`tests/Feature/Audit/AuditTranslationCoverageTest.php` fails if an event is missing from the map or
a translation is missing from either locale.

Placeholders named `plan`, `old_plan`, `new_plan`, `addon`, `usage_type` and `interval` are resolved
to catalog names / localised labels automatically by `BillingAuditEntry`; anything else is passed
through as stored.

## Privacy and deletion

Audit rows hold the causer's identity and, depending on the event, ids and amounts. They have **no
foreign key to the billable** — deleting a billable does not remove its audit trail. If your data
retention policy requires it, delete the trail explicitly in your own deletion path:

```php
$billable->billingAuditTrail()->delete();
```

## Failure behaviour

Auditing never breaks a billing flow. The listener wraps everything in `try/catch` and hands
failures to `report()`. It runs synchronously rather than queued because several events carry a
`\Throwable`, which cannot be serialised onto a queue.

Webhook redelivery is deduplicated upstream by `BillingProcessedWebhook`, so duplicate rows are
rare; the trail does not add a second layer of deduplication.

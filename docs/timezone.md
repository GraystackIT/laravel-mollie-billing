# Timezones

How the package handles dates and times — persistence, computation, customer-portal display, and admin display.

- **Persistence:** all date values are stored in **UTC** in the database.
- **Computation:** all internal calculations (period math, OSS quarter boundaries, trial / cancel / coupon logic, job cutoffs) are performed in **UTC**.
- **Portal display:** in the billable's timezone — overridable per app/user via `getBillingTimezone()`. Without an override the default `mollie-billing.billing_timezone` (default `UTC`) is used.
- **Admin display:** **always UTC**, identical to the database values and to logs / Mollie events.

## Independence from `app.timezone`

The package's correctness does **not** depend on `APP_TIMEZONE`. It works under `UTC`, `Europe/Berlin`, `Pacific/Auckland` or any other IANA timezone.

Two mechanisms together deliver this guarantee:

1. **Write path:** every datetime the package writes goes through `BillingTime::nowUtc()` (or an explicit `->setTimezone('UTC')` after parse), so Eloquent always receives a UTC Carbon instance. The string written to the database is therefore always the UTC representation of the value.
2. **Read path:** all datetime columns owned by the package use the [`UtcDatetime`](../src/Casts/UtcDatetime.php) cast instead of the default `'datetime'` cast. Where the default cast rehydrates a stored string in `config('app.timezone')`, `UtcDatetime` rehydrates strictly as UTC. The cast also normalizes incoming values on write — if a caller hands in a Berlin-local Carbon, the cast converts it to UTC before formatting it for storage.

Together this means: the same value is on disk regardless of what `app.timezone` is set to, and the same UTC-typed Carbon is returned regardless of what `app.timezone` is set to. The test `tests/Unit/Casts/UtcDatetimeTest.php` exercises both paths under non-UTC `app.timezone` configurations.

### Database connection caveat

One layer remains outside what the package can control: a non-default MySQL session timezone (`time_zone` variable) on the database connection. MySQL `TIMESTAMP` columns are converted by the server between the session timezone and UTC. The defaults that ship with most managed MySQL setups are UTC (`+00:00`), in which case there is nothing to do. If you have changed the session timezone on the connection (for example via the Laravel database config `'timezone'` key), set it back to UTC for the connection used by this package — otherwise stored values will be shifted at the connection layer before the cast ever sees them.

`DATETIME` columns and SQLite are not affected; they store the literal string.

## Configuration

```env
BILLING_TIMEZONE=UTC                 # Default for the portal (when the billable does not say otherwise)
```

The config key is `mollie-billing.billing_timezone`. Any [IANA timezone string](https://www.iana.org/time-zones) is allowed (`Europe/Berlin`, `America/New_York`, `Pacific/Auckland`, …).

`BILLING_TIMEZONE` only affects the customer-portal display fallback. `APP_TIMEZONE` does not need to be UTC for the package to work — see the section above.

## Per-user portal timezone

A consuming app that wants each user (or tenant) to have their own display timezone overrides `getBillingTimezone()` on the billable model:

```php
use GraystackIT\MollieBilling\Concerns\HasBilling;

class Organization extends Model implements \GraystackIT\MollieBilling\Contracts\Billable
{
    use HasBilling;

    public function getBillingTimezone(): string
    {
        return $this->preferred_timezone ?? parent::getBillingTimezone();
    }
}
```

`parent::getBillingTimezone()` returns the config default (`mollie-billing.billing_timezone`, otherwise `'UTC'`).

## Display in your own views

The package ships a helper:

```php
use GraystackIT\MollieBilling\Support\BillingTime;
```

### Portal (billable timezone)

```blade
{{ BillingTime::display($invoice->created_at, $billable)->translatedFormat('d. M Y') }}
{{ BillingTime::display($billable->getBillingTrialEndsAt(), $billable)?->diffForHumans() }}
```

`display(?CarbonInterface $dt, ?Billable $billable = null)` returns a clone in the billable's timezone. Without a `$billable`, `mollie-billing.billing_timezone` is used.

### Admin (UTC)

```blade
{{ BillingTime::displayUtc($invoice->created_at)->format('Y-m-d H:i') }} UTC
```

`displayUtc(?CarbonInterface $dt)` returns a clone in UTC, regardless of config or billable. This way an admin sees the same value in every list and detail tab as in the database row, the Mollie dashboard, and the logs.

### Time computations in your own code

```php
use GraystackIT\MollieBilling\Support\BillingTime;

$expiresAt = BillingTime::nowUtc()->addDays(30);
```

`BillingTime::nowUtc(): CarbonImmutable` always returns UTC, regardless of `app.timezone`. Use it everywhere values are written into the database or compared against stored values.

## Admin vs. portal

| Area                  | Display timezone                                          | Reason |
|-----------------------|-----------------------------------------------------------|--------|
| Customer portal       | `Billable::getBillingTimezone()` (config fallback)        | A customer reads "their" times. |
| Admin / staff area    | UTC                                                       | Forensic consistency with database values, logs, and Mollie webhooks. A staff member can map bug reports and events 1:1. |
| Mails & notifications | Carbon default (= UTC when `app.timezone=UTC`)            | Day differences (`diffInDays`) are invariant; date formatting is up to the consuming app. |

## OSS export

`OssProtocolService::export($year)` aggregates invoices per quarter. Quarter boundaries are computed strictly in UTC:

```php
$start = Carbon::create($year, 1, 1, 0, 0, 0, 'UTC');
$end   = Carbon::create($year, 12, 31, 23, 59, 59, 'UTC');
```

An invoice with `created_at = 2025-12-31 23:30 UTC` therefore always lands in Q4/2025 — even when the server runs in Pacific/Auckland, where local time is already in Q1/2026. The UTC view is authoritative for the OSS export.

## Known limitations

- **`usage-history` date filters** (`dateFrom` / `dateTo`) operate on the server's UTC day. If a user in `Pacific/Auckland` filters shortly after midnight, they may see an off-by-one discrepancy compared with the portal display. Workaround on the application side: convert the date into the user's timezone before applying it.
- **`TrialEndingSoonNotification`** renders a date inside the notification without an explicit billable-timezone conversion step. Since only day differences via `diffInDays` are exposed, the result is invariant across timezone changes.

## Upgrading from older package versions

For consumers who are already running the package in production:

- The behavior is independent of `APP_TIMEZONE`. Whatever value the consuming app uses, the package's stored values and computations stay UTC.
- If `BILLING_TIMEZONE` is unset, the portal continues to display UTC — same as before.
- To switch the portal display to a specific timezone, set `BILLING_TIMEZONE=Europe/Berlin` (or similar).
- To use a different display timezone per customer, override `getBillingTimezone()` on the billable as shown above.
- If you maintain custom views with date displays, migrate them to `BillingTime::display(...)` (portal) or `BillingTime::displayUtc(...)` (admin) so behavior stays consistent.

## Acceptance checks (smoke tests)

- Trial creation at 23:30 UTC: the database stores `2026-05-02 23:30:00`, the portal with `BILLING_TIMEZONE=Europe/Berlin` displays `03. May 2026, 01:30`, and the admin shows `2026-05-02 23:30 UTC`.
- The OSS export for a previous year under `APP_TIMEZONE=Pacific/Auckland` produces a CSV identical to the run under `APP_TIMEZONE=UTC`.
- `BillingPolicy::prorataPeriodDays()` returns the same result for UTC and non-UTC Carbon inputs.

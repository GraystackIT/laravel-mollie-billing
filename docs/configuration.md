# Configuration

This document describes the structure of the package's two configuration files:

- `config/mollie-billing.php` — global behavior, ENV-driven switches, invoicing, VAT/OSS extensions
- `config/mollie-billing-plans.php` — catalog: plans, intervals, addons, features, products and product groups

Both files are loaded by the service provider. The plan catalog is read through `SubscriptionCatalogInterface` (default: `Support\ConfigSubscriptionCatalog`) — apps can replace this binding in their `AppServiceProvider` with a custom implementation (e.g. database-driven) without having to change the config structure.

> All monetary amounts are **integer net values in the smallest currency unit** (cents for EUR). `2900` therefore represents 29.00 €. Gross/VAT is added at runtime by `VatCalculationService`.

---

## `config/mollie-billing.php`

Global settings. Most values can be overridden via ENV variables — the default in the file is used when the ENV is not set.

### Billable & Branding

| Key | ENV | Description |
|-----|-----|-------------|
| `billable_model` | `BILLING_BILLABLE_MODEL` | FQCN of the model that implements `Billable` (User, Team, Organization, …). Required before the first migration. |
| `logo_url` | `BILLING_LOGO_URL` | URL of the logo shown in portal/checkout headers. |
| `favicon_url` | `BILLING_FAVICON_URL` | URL of the favicon used by the portal views. |
| `primary_color` | `BILLING_PRIMARY_COLOR` | Flux/Tailwind color name (`teal`, `indigo`, …). Default: `teal`. |
| `company_name` | — | Default: `APP_NAME`. Used in mails and views. |
| `dashboard_url` | `BILLING_DASHBOARD_URL` | Target of the portal logo link. Accepts a URL or `route:<name>` (e.g. `route:dashboard`). `null` → the logo links back to the billing dashboard. |
| `checkout_back_url` | `BILLING_CHECKOUT_BACK_URL` | Default target of the "Back" link in the checkout when no explicit `$backUrl` is set. |

### Checkout country selection

```php
'checkout_countries' => [
    'regions' => ['EU'],   // currently supported: 'EU' (27 member states)
    'include' => [],       // additional ISO-3166-1 alpha-2 codes, e.g. ['CH', 'GB']
    'exclude' => [],       // codes to remove from the resolved list
],
```

Countries from `additional_countries` (see below) are added automatically.

```php
'default_billing_country' => env('BILLING_DEFAULT_COUNTRY', 'AT'),
```

ISO-3166-1 alpha-2 fallback for the country dropdown when there is no persisted country on the billable yet and the IP-based lookup yields no usable result. See "IP geolocation" below for the lookup chain.

### Behavior

| Key | ENV | Description |
|-----|-----|-------------|
| `redirect_after_return` | `BILLING_REDIRECT_AFTER_RETURN` | URL to redirect to after a successful Mollie return. |
| `require_payment_method_for_zero_amount` | `BILLING_REQUIRE_PM_ZERO` | When `true`, a mandate is required even for 0 € plans (so later paid changes can charge it). Default: `true`. |
| `currency` | `BILLING_CURRENCY` | ISO-4217 code. Amounts in `mollie-billing-plans.php` and coupons are interpreted in this currency. Default: `EUR`. |
| `currency_symbol` | `BILLING_CURRENCY_SYMBOL` | Display symbol. Default: `€`. |
| `allow_overage_default` | `BILLING_ALLOW_OVERAGE` | Default for `Billable::allowsBillingOverage()`. Overridable per billable. |
| `plan_change_mode` | `BILLING_PLAN_CHANGE_MODE` | `Immediate` / `EndOfPeriod` / `UserChoice`. Controls when plan changes are applied. Default: `UserChoice`. |
| `mollie_api_key` | `BILLING_MOLLIE_KEY` | Mollie API key. Alias for `MOLLIE_KEY` from `mollie/laravel-mollie` — when set, the package propagates it into `mollie.key` at boot so you can keep all settings on the `BILLING_*` prefix. Leave empty to keep using `MOLLIE_KEY` directly. |
| `mollie_locale` | `BILLING_MOLLIE_LOCALE` | Locale for Mollie hosted pages. `null` lets Mollie auto-detect. |
| `billable_key_type` | `BILLING_BILLABLE_KEY_TYPE` | `uuid` / `ulid` / `int`. **Set before the first migration** — affects FK column shapes. Default: `uuid`. |
| `user_key_type` | `BILLING_USER_KEY_TYPE` | `uuid` / `ulid` / `int`. Primary key type of your auth user model — used for columns like `billing_country_mismatches.resolved_by_user_id`. **Set before the first migration**. Default: `int`. |
| `overage_job_time` | `BILLING_OVERAGE_JOB_TIME` | Time of day (`HH:MM`) for `PrepareOverageCommand`. Default: `02:00`. |
| `trial_lifecycle_job_time` | `BILLING_TRIAL_LIFECYCLE_JOB_TIME` | Time of day (`HH:MM`) for `ProcessTrialLifecycleJob`. Sends trial-ending notifications and expires stale trials. Default: `02:05`. |
| `trial_ending_soon_notice_days` | `BILLING_TRIAL_ENDING_SOON_NOTICE_DAYS` | Days before `trial_ends_at` at which `TrialEndingSoonNotification` is dispatched (the notification picks with/without-mandate wording itself). `TrialConvertedNotification` is independent and fires from `SubscriptionPaymentHandler::paid()` when the first recurring charge actually lands. Missed windows are **not** backfilled when this value is increased. Default: `1`. |
| `usage_threshold_percent` | `BILLING_USAGE_THRESHOLD` | Threshold for usage-warning events (in %). Default: `80`. |
| `usage_rollover_fallback` | `BILLING_USAGE_ROLLOVER_FALLBACK` | Fallback for usage types that have no explicit entry under `usage_types` in `mollie-billing-plans.php`. When `true`, unused credits of such types carry over to the next billing period; when `false`, the wallet is reset to the plan's included quota on each renewal. Default: `false`. |
| `admin_kpi_cache_ttl` | `BILLING_ADMIN_KPI_TTL` | TTL of the admin-panel KPI cache (seconds). Default: `300`. |
| `show_yearly_savings` | `BILLING_SHOW_YEARLY_SAVINGS` | Shows the computed savings (yearly vs. monthly) in the plan selector. Default: `true`. |
| `local_subscription.allow_one_time_orders` | `BILLING_LOCAL_ALLOW_ONE_TIME_ORDERS` | Whether free / Local subscribers may purchase one-time products. Default: `false` — purchase attempts throw `LocalSubscriptionCannotPurchaseProductsException` and the products page hides the buy buttons with an upgrade hint. Set `true` if your free plan is a default tier monetised through token packs etc. Paid add-ons and extra seats remain blocked on Local subs regardless of this flag (no mandate available). |
| `billing_timezone` | `BILLING_TIMEZONE` | IANA timezone for the customer-portal display (fallback when `Billable::getBillingTimezone()` is not overridden). Persistence and computation always remain UTC; the admin panel also renders UTC. Default: `UTC`. See [docs/timezone.md](timezone.md). |
| `cleanup.enabled` | `BILLING_CLEANUP_ORPHANED_ENABLED` | Master switch for `CleanupOrphanedBillablesJob`. Default: `true`. |
| `cleanup.threshold_minutes` | `BILLING_CLEANUP_ORPHANED_THRESHOLD_MINUTES` | Minimum age before an orphaned billable is eligible for deletion. Default: `60`. |
| `cleanup.cron_expression` | `BILLING_CLEANUP_ORPHANED_CRON` | Cron expression for the cleanup schedule. Default: `*/15 * * * *` (every 15 minutes). |
| `past_due_max_days` | `BILLING_PAST_DUE_MAX_DAYS` | Days a billable may sit in `past_due` before `PrepareUsageOverageJob` Pass 3a auto-cancels the subscription (`past_due → cancelled` with `subscription_ends_at = now`, then Pass 3b finalises to `expired` on the next run). Recovery (Mollie retries succeeding, manual portal payment) still works up to the cutoff. Set to `0` to disable and keep the legacy behavior (PastDue lives forever until manual action). Default: `30`. See [docs/lifecycle-and-cleanup.md](lifecycle-and-cleanup.md). |

### Audit trail (`audit`)

Records every billing event against the billable and renders it as a timeline in the admin panel. Full reference: [docs/audit.md](audit.md).

| Key | ENV | Meaning |
| --- | --- | --- |
| `audit.enabled` | `BILLING_AUDIT_ENABLED` | Master switch. Default: `true`. |
| `audit.log_name` | `BILLING_AUDIT_LOG_NAME` | `log_name` written to `activity_log`, and the filter every package query applies — keeps our rows separate from an app's own activitylog usage. Default: `billing`. |
| `audit.categories` | — | Which categories to record: `subscription`, `payment`, `invoice`, `payment_method`, `coupon`, `trial`, `usage`, `compliance`. Drop `usage` on high-volume metered setups: `UsageLimitReached` / `WalletCredited` can fire on every request, each costing a synchronous insert. Default: all. |
| `audit.retention_days` | `BILLING_AUDIT_RETENTION_DAYS` | Age at which `PruneBillingAuditJob` (monthly) deletes rows, scoped to `log_name`. Default: `3650` (10 years) — covers the statutory retention periods for billing records in AT/DE and most EU jurisdictions at negligible storage cost. `null` keeps everything. |

The package ships its own `activity_log` migration with string morph ids, so integer-, uuid- and ulid-keyed subjects and causers can coexist. **Do not additionally publish spatie's migrations** (`vendor:publish --tag=activitylog-migrations`) — spatie's stub uses `nullableMorphs()` (bigint), which cannot hold the default `uuid` billable keys, and you would get two migrations creating the same table. `billing:check-config` verifies the table exists, that neither `subject_id` nor `causer_id` is still numeric when the matching key type is `uuid`/`ulid`, that `audit.categories` and `audit.retention_days` are well-formed, and that every audit translation key resolves.

#### Orphaned-billable cleanup (`cleanup`)

Removes billables that were created during a checkout flow but never reached an active subscription — abandoned tabs, expired Mollie sessions, captured-but-unused mandates. Detection is hybrid:

- When the billable has a `pending_first_payment_id` in `subscription_meta` the job polls Mollie and only cleans up when the payment is in a terminal failure state (`failed`/`canceled`/`expired`).
- Otherwise the billable is cleaned purely based on age plus the absence of any accessible subscription (`subscription_source` is `none`/`null` and `subscription_status` is `new`/`null`).

Cascading cleanup (e.g. removing tenants and orphaned users) is delegated to the closure registered via `MollieBilling::cleanupOrphanedBillableUsing(...)`. When no closure is registered the package falls back to `$billable->delete()`. The closure may return `false` to veto cleanup for billables that legitimately exist without a subscription (admins, employees, internal accounts); in that case the job emits no `CheckoutAbandoned` event, no mandate revocation, and no log entry. Returning `true` or `void` keeps the legacy behaviour. A captured Mollie mandate is revoked best-effort after a successful cleanup so we don't leave permission-to-charge floating in Mollie.

### Invoices (`invoices`)

PDF invoices are generated locally via `elegantly/laravel-invoices` and stored on a Laravel filesystem disk.

```php
'invoices' => [
    'disk' => env('BILLING_INVOICE_DISK', 'local'),
    'path' => 'billing/invoices',           // → {disk}/billing/invoices/{YYYY/MM}/{serial}.pdf
    'logo' => env('BILLING_INVOICE_LOGO'),
    'seller' => [ /* company master data */ ],
    'serial_number' => [
        'format' => 'PP-YYCCCCCC',           // P=prefix, Y=year, C=counter (each character = one slot)
        'prefix' => [
            'invoice'         => 'IN',
            'credit_note'     => 'CR',
            'one_time_order'  => 'OT',
        ],
    ],
    'temporary_url_expiry' => 30,            // minutes (relevant for S3-compatible disks)
],
```

Supported `logo` formats:

- `${APP_URL}/logo.png` (resolved via `public_path`)
- relative path: `images/logo.png`
- absolute path: `/var/www/public/logo.png`
- data URI: `data:image/png;base64,…`

Serial numbers are issued atomically by `InvoiceNumberGenerator`.

### IP geolocation

```php
'ip_geolocation' => [
    'driver'  => env('BILLING_IP_DRIVER', 'ipinfo_lite'),
    'drivers' => [
        'ipinfo_lite' => ['token' => env('IPINFO_TOKEN')],
        'db_ip'       => ['api_key' => env('DB_IP_API_KEY')],
        'null'        => [],
    ],
],
```

Used to pre-fill the country dropdown at checkout and in the billing-data portal. The lookup is cached for 24h per IP, gracefully falls back to `default_billing_country` when no token is configured / the lookup fails / the resolved country is not in `checkout_countries`. The IP country is **never persisted** on the billable — it is purely a UX default.

Built-in drivers:

- `ipinfo_lite` — uses [IPinfo Lite](https://ipinfo.io/lite). Requires `IPINFO_TOKEN`.
- `db_ip` — uses the [DB-IP API](https://db-ip.com/api/doc.php). Set `DB_IP_API_KEY` for paid tiers; when empty, the driver falls back to the public free tier (rate-limited, not recommended for production).
- `null` — disables lookups; every request resolves to `default_billing_country`.

Custom drivers can be registered through `MollieBilling::ipGeolocation(...)`.

> **Behind a reverse proxy / Cloudflare?** `request()->ip()` only returns the real client IP when Laravel's `App\Http\Middleware\TrustProxies` is configured for your environment. Without that, the geolocation will see the proxy IP and fall back to `default_billing_country`.

### IP-based country gating

```php
'ip_block' => [
    'enabled'       => env('BILLING_IP_BLOCK_ENABLED', false),
    'mode'          => env('BILLING_IP_BLOCK_MODE', 'blocklist'), // blocklist | allowlist
    'countries'     => array_values(array_filter(array_map(
        fn (string $iso): string => strtoupper(trim($iso)),
        explode(',', (string) env('BILLING_IP_BLOCK_COUNTRIES', ''))
    ))),
    'block_unknown' => env('BILLING_IP_BLOCK_UNKNOWN', false),
],
```

Blocks requests originating from disallowed countries on every package route — checkout, billing portal, admin portal, promotion links — based on the same `ip_geolocation` driver used for the country dropdown. The Mollie webhook is **never** wrapped: webhook calls come from Mollie's servers, not from the buyer, and would otherwise be falsely blocked.

When a request fails the gate, the user is redirected to a static blocked page (`/billing/blocked`) that names the detected country, explains that paid services are unavailable in their region, and links back to `/`. The page renders without any auth, tenant, or subscription middleware so it stays reachable in every flow.

Modes:

- `blocklist` — `countries` lists the codes to block. Everything else passes.
- `allowlist` — `countries` lists the only codes allowed. Everything else is blocked.

`block_unknown` controls what happens when geolocation returns no country (private IP, lookup failure, `null` driver). Defaults to `false` — unknown clients pass through. Set to `true` for strict allowlist setups.

`countries` accepts a comma-separated list via the `BILLING_IP_BLOCK_COUNTRIES` env variable, e.g.:

```env
BILLING_IP_BLOCK_ENABLED=true
BILLING_IP_BLOCK_MODE=blocklist
BILLING_IP_BLOCK_COUNTRIES=RU,KP,IR
```

Whitespace is trimmed and codes are upper-cased automatically. The env value takes precedence over any inline array in the published config.

> Combining the gate with `ip_geolocation.driver=null` effectively disables it (unknowns always pass) — `php artisan billing:check-config` warns about this combination.

### VAT / OSS

```php
'vat_rate_overrides' => [
    // 'DE' => 19.0,
],

'additional_countries' => [
    // 'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
],

'oss' => [
    'disk' => env('BILLING_OSS_DISK'),                    // null → falls back to invoices.disk
    'path' => env('BILLING_OSS_PATH', 'billing/oss-exports'),
    'temporary_url_expiry' => 30,                         // minutes (S3-compatible disks)
],
```

- `vat_rate_overrides` — overrides the rates resolved by `mpociot/vat-calculator` per ISO code.
- `additional_countries` — adds countries that are not part of the EU VAT system. They are automatically included in the checkout country list.
- `oss.disk` — Laravel filesystem disk where generated OSS protocol CSVs are stored. Works with any S3-compatible private bucket; the admin portal hands out a short-lived presigned URL via `Storage::temporaryUrl()`. When `null`, the disk configured under `invoices.disk` is reused.
- `oss.path` — base path on the disk. Files are written as `{path}/oss-export-{YYYY}-{timestamp}.csv` so previous exports remain available as an audit trail.
- `oss.temporary_url_expiry` — validity window of the download link in minutes (only relevant for non-`local` disks).

OSS exports run as a queued job (`GenerateOssExportJob`) — admins click "Generate" / "Regenerate" in the admin panel and the table auto-refreshes via `wire:poll` until the file is ready. The CLI command `php artisan billing:oss-export {year}` runs synchronously and persists a row in the same `billing_oss_exports` audit table, so CLI exports are downloadable from the portal too.

### Queue

```php
'queue' => [
    'connection' => env('BILLING_QUEUE_CONNECTION'),
    'name'       => env('BILLING_QUEUE_NAME'),
],
```

All background jobs shipped by the package (`PrepareUsageOverageJob`, `RetryUsageOverageChargeJob`, `RevokeMollieMandateJob`, `RetrySubscriptionPatchJob`, `RetryRefundLineJob`, `CleanupStalePendingProrataChangeJob`, `CleanupStalePendingCountryCorrectionJob`, `PruneProcessedWebhooksJob`, `ApplyScheduledChangesJob`, `SyncSeatsJob`, `GenerateOssExportJob`) and the queued `PlanChangeFailedNotification` are dispatched onto this connection/queue.

Both keys default to `null`, which falls back to the framework default connection (`config('queue.default')`) and the default queue name. Set `BILLING_QUEUE_NAME=billing` (and a dedicated worker) when you want to isolate billing work from the rest of your application's queue.

---

## `config/mollie-billing-plans.php`

Defines the catalog: what customers can subscribe to, what is included and what overage costs.

Top-level sections:

```php
return [
    'plans'           => [ /* … */ ],
    'features'        => [ /* … */ ],
    'addons'          => [ /* … */ ],
    'product_groups'  => [ /* … */ ],
    'products'        => [ /* … */ ],
];
```

### `plans`

Each plan is keyed by its `planCode`.

```php
'pro' => [
    'name'           => 'Pro',                              // fallback when no translation exists
    'description'    => null,
    'tier'           => 2,                                  // numeric tier (upgrade/downgrade detection)
    'included_seats' => 3,
    'feature_keys'   => ['dashboard', 'advanced-reports'],  // references into 'features'
    'allowed_addons' => ['softdrinks'],                     // references into 'addons'
    'intervals' => [
        'monthly' => [
            'base_price_net'        => 2900,                // cents — base plan price
            'seat_price_net'        => 990,                 // cents per additional seat above included_seats, or null
            'trial_days'            => 14,                  // optional — interval-scoped trial length
            'included_usages'       => ['Tokens' => 100, 'SMS' => 50],
            'usage_overage_prices'  => ['Tokens' => 10, 'SMS' => 15],   // cents per unit
        ],
        'yearly' => [
            'base_price_net'        => 29000,
            'seat_price_net'        => 9900,
            'trial_days'            => 14,
            'included_usages'       => ['Tokens' => 1500, 'SMS' => 600],
            'usage_overage_prices'  => ['Tokens' => 10, 'SMS' => 15],
        ],
    ],
],
```

| Field | Description |
|-------|-------------|
| `name`, `description` | Display values. If a translation exists under `billing::plans.<code>.{name,description}` it overrides these. |
| `tier` | Integer. Higher = more expensive/larger. Drives upgrade/downgrade detection in `UpdateSubscription`. |
| `included_seats` | Number of seats included in the base price. `SyncSeats` charges anything above this as additional seats. |
| `feature_keys` | List of feature keys from the `features` block. Resolved together with addon features by `FeatureAccess`. |
| `allowed_addons` | Whitelist of addons. Other addons cannot be enabled on this plan. |
| `intervals` | Required — one block per supported interval (`monthly`, `yearly`). |

> **Breaking change**: Plan-level `trial_days` is no longer supported. Move the value into each interval that should offer a trial (`intervals.monthly.trial_days`, `intervals.yearly.trial_days`). `php artisan billing:check-config` will fail with a migration hint until the config is updated.

**Per interval block:**

| Field | Required | Description |
|-------|----------|-------------|
| `base_price_net` | ✓ | Net base price in cents. |
| `seat_price_net` | ✓ | Net price per additional seat in cents, or `null` if the plan does not allow seat upgrades. When `null` for every plan/interval in your config, the portal hides the **Seats** sidebar entry and the `/billing/seats` page returns the "not available" callout — there is nothing for the user to change. The server-side guard in `ValidateSubscriptionChange` also rejects explicit seat increases above `included_seats` for plans with `seat_price_net === null`. |
| `trial_days` *(optional)* | | Trial length in days for this specific interval. Missing or `0` means no trial. Trials only apply on fresh checkout — never on plan changes. See [Trial flow](subscription-lifecycle.md#trial-flow). |
| `included_usages` | ✓ | Map `usage_type => quantity`. These quantities are credited additively to the wallet on every period rollover (negative balances from prior overage are preserved). |
| `usage_overage_prices` | ✓ | Map `usage_type => price_in_cents_per_unit`. Charged once a wallet goes negative. |

> **Important:** `included_usages`, `usage_overage_prices`, and `trial_days` live **inside** each `intervals.{monthly|yearly}` block, not at plan level. `SubscriptionCatalogInterface` lookups are always keyed by `(planCode, interval)`.

### `usage_types`

Per-usage-type configuration. Each usage type referenced in `plans.*.intervals.*.included_usages` or `usage_overage_prices` can opt in or out of rollover here. Types without an entry fall back to `mollie-billing.usage_rollover_fallback`.

```php
'usage_types' => [
    'Tokens' => ['rollover' => true],
    'SMS'    => ['rollover' => false],
],
```

| Field | Description |
|-------|-------------|
| `rollover` | `true` → unused credits carry over to the next billing period. `false` → wallet is reset to the plan's included quota on each renewal. Purchased credits from one-time orders always survive both modes. |

Resolution order for `SubscriptionCatalogInterface::usageRollover($type)`:

1. `usage_types.<type>.rollover` (when boolean)
2. `mollie-billing.usage_rollover_fallback`
3. `false`

### `features`

Defines the features available in the system. Plans and addons reference these via `feature_keys`.

```php
'features' => [
    'dashboard' => [
        'name'        => 'Dashboard',
        'description' => null,
    ],
    'advanced-reports' => [
        'name'        => 'Advanced Reports',
        'description' => null,
    ],
],
```

`name` / `description` are overridden by translations under `billing::features.<key>.{name,description}` when present. Used by:

- `MollieBilling::features()` (list of active features for the billable)
- The `@planFeature('<key>')` Blade directive
- The `billing.feature:<key>[,<key>]` middleware (OR semantics)

### `addons`

Bookable add-ons that unlock additional features and/or cost.

```php
'addons' => [
    'softdrinks' => [
        'name'         => 'Softdrinks',
        'feature_keys' => ['softdrinks'],
        'intervals' => [
            'monthly' => ['price_net' => 490],
            'yearly'  => ['price_net' => 4900],
        ],
    ],
],
```

| Field | Description |
|-------|-------------|
| `name`, `description` *(optional)* | Overridden by `billing::addons.<code>.{name,description}` when a translation exists. |
| `feature_keys` | Features unlocked by the addon. Merged with the plan's features into the effective set. |
| `intervals.{monthly,yearly}.price_net` | Net price in cents per interval. |

> Addons do **not** contribute to `included_usages` — wallet quotas are plan-scoped.

### `product_groups`

Optional grouping for the one-time-order overview in the portal.

```php
'product_groups' => [
    'top-ups'  => ['name' => 'Top-Ups',  'sort' => 1],
    'services' => ['name' => 'Services', 'sort' => 2],
],
```

| Field | Description |
|-------|-------------|
| `name` | Display name. Overridden by `billing::product_groups.<key>` when a translation exists. |
| `sort` | Sort order in the portal (ascending). |

### `products`

One-off purchases (top-ups, consulting hours, etc.). Sold through the one-time-order flows.

```php
'products' => [
    'token-pack-500' => [
        'name'        => '500 Token Pack',
        'description' => 'Top up your account with 500 tokens.',
        'image_url'   => null,
        'price_net'   => 4900,
        'usage_type'  => 'Tokens',   // optional — links to a wallet
        'quantity'    => 500,        // optional — units credited on purchase
        'group'       => 'top-ups',  // optional — key from 'product_groups'
    ],
    'consulting-hour' => [
        'name'        => '1h Consulting',
        'description' => 'Book a one-hour consulting session.',
        'price_net'   => 14900,
        'onetimeonly' => true,       // optional — purchasable only once per billable (default: false)
        'group'       => 'services',
    ],
],
```

| Field | Required | Description |
|-------|----------|-------------|
| `name`, `description` | `name` ✓ | Translatable via `billing::products.<code>.{name,description}`. |
| `image_url` | — | Optional — product image. |
| `price_net` | ✓ | Net price in cents. |
| `usage_type` | — | When set together with `quantity`, the purchase additionally credits that quantity to the corresponding wallet. |
| `quantity` | — | See `usage_type`. |
| `onetimeonly` | — | When `true`, the product can only be purchased once per billable. |
| `group` | — | Reference into `product_groups`. |

> **Sidebar visibility.** The portal sidebar dynamically hides entries that
> have nothing to manage:
>
> - **Addons** — hidden when `mollie-billing-plans.addons` is empty.
> - **Seats** — hidden when no plan in your config defines `seat_price_net`
>   for any interval (i.e. seats are not purchasable anywhere).
> - **Products** — hidden when `mollie-billing-plans.products` is empty.

---

## Translations

Display strings (`name`, `description`) are loaded from language files when a translation exists, overriding the values in the config. Translation keys used:

| Config area | Translation key |
|-------------|-----------------|
| `plans.<code>.name` / `description` | `billing::plans.<code>.{name,description}` |
| `addons.<code>.name` / `description` | `billing::addons.<code>.{name,description}` |
| `features.<key>.name` / `description` | `billing::features.<key>.{name,description}` |
| Usage type display | `billing::usages.<type>` |
| `products.<code>.name` / `description` | `billing::products.<code>.{name,description}` |
| `product_groups.<key>` | `billing::product_groups.<key>` |

See [translations.md](translations.md) for details on language-file publishing.

---

## Database-driven catalog

To hold plan/addon data in the database instead of the config, implement `SubscriptionCatalogInterface` and bind the class in `AppServiceProvider`:

```php
$this->app->bind(SubscriptionCatalogInterface::class, MyDatabaseCatalog::class);
```

`config/mollie-billing-plans.php` is then ignored. The interface methods must respect the same lookup keys (`planCode`, `interval`, `usageType`, `addonCode`, `featureKey`, `productCode`, `groupKey`).

---

## Validating the configuration

The package ships a console command that validates both `mollie-billing.php` and `mollie-billing-plans.php`:

```bash
php artisan billing:check-config
```

It reports two classes of issues:

- **Errors** — broken references or invalid values that will cause runtime failures. Exit status `1`.
  - `billable_model` missing, not found, or not implementing `Billable` / not using `HasBilling`
  - `billable_key_type` not in `uuid|ulid|int`
  - `user_key_type` not in `uuid|ulid|int`
  - `plan_change_mode` not a valid `PlanChangeMode` enum value
  - `invoices.disk` not declared in `config/filesystems.php`
  - `oss.disk` (when set) not declared in `config/filesystems.php`
  - `invoices.serial_number.format` empty or missing the `C` (counter) slot
  - `ip_geolocation.driver` not declared in `ip_geolocation.drivers`
  - `checkout_countries.include|exclude` entries that are not uppercase ISO-3166-1 alpha-2 codes
  - `additional_countries` entries with non-numeric `vat_rate`
  - `billing_timezone` not a valid IANA identifier
  - `overage_job_time` not in `HH:MM` format
  - `usage_threshold_percent` outside `0–100`
  - `plans` empty
  - Plan / addon `feature_keys` referencing undefined features
  - Plan `allowed_addons` referencing undefined addons
  - Plan `tier` missing or non-integer; `included_seats` invalid; `trial_days` set at plan root (must move into `intervals.{interval}.trial_days`)
  - Interval `base_price_net` / `seat_price_net` missing or negative
  - Plan / addon defining an unknown interval (only `monthly` and `yearly` are recognised)
  - Product `group` referencing an undefined product group
  - Product `quantity` invalid

- **Warnings** — likely misconfigurations that don't break runtime but degrade behavior. Exit status stays `0`.
  - `currency` not in uppercase ISO-4217 form (e.g. `eur` instead of `EUR`)
  - `invoices.seller.*` fields empty (generated invoices will be incomplete)
  - `invoices.serial_number.prefix.{invoice|credit_note|one_time_order}` missing
  - `ip_geolocation.drivers.ipinfo_lite.token` empty when IPinfo is selected
  - `ip_geolocation.drivers.db_ip.api_key` empty when DB-IP is selected (falls back to the public free tier)
  - `additional_countries.<ISO>.name` empty
  - Multiple plans sharing the same `tier` (ranking becomes ambiguous)
  - `included_usages` defines a quota without a matching `usage_overage_prices` entry — overages cannot be charged
  - `usage_overage_prices` defines a type without a matching `included_usages` entry — quota defaults to 0
  - Addon with empty `feature_keys` (unlocks no features)
  - Product `usage_type` not declared in any plan's `included_usages` / `usage_overage_prices`
  - Product declaring `usage_type` without `quantity` (or vice versa)
  - Features defined but never referenced by any plan or addon

Run it after editing either config file or wire it into CI to catch typos before deployment.

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;

return [
    'billable_model' => env('BILLING_BILLABLE_MODEL'),

    'logo_url' => env('BILLING_LOGO_URL'),
    'favicon_url' => env('BILLING_FAVICON_URL'),
    'primary_color' => env('BILLING_PRIMARY_COLOR', 'teal'),
    'company_name' => env('APP_NAME'),

    // URL the portal logo links to (e.g. main app dashboard). When null the logo
    // links to the billing dashboard itself. Supports a plain URL string or a named
    // route via the 'route:' prefix, e.g. 'route:dashboard'.
    'dashboard_url' => env('BILLING_DASHBOARD_URL'),

    // Where the admin panel's "Back" link in the sidebar leads. Same two forms as
    // `dashboard_url` — a plain URL or `route:some.name`. Left unset it falls back to
    // `dashboard_url`, so a single-app install configures one value; set it explicitly when
    // staff administer from somewhere other than where customers land.
    'admin_back_url' => env('BILLING_ADMIN_BACK_URL'),

    // Livewire components rendered in the portal header (left of the theme
    // switcher), in the checkout header (left of the "Back" link) and in the
    // admin header — e.g. a language switcher. Resolved by the consuming app, so
    // any alias renderable via @livewire() works.
    'header_components' => [],

    // Locale the admin panel renders in. null (default) follows the application
    // locale, so a header language switcher applies to the admin panel too. Set
    // a fixed locale (e.g. 'en') to pin the panel regardless of the app locale —
    // useful when operators share one working language.
    'admin_locale' => env('BILLING_ADMIN_LOCALE'),

    // Where the checkout "Back" link leads when no explicit $backUrl is passed.
    'checkout_back_url' => env('BILLING_CHECKOUT_BACK_URL', '/'),

    // Countries available in the checkout country selector.
    // 'regions': built-in groups — currently only 'EU' (27 member states).
    // 'include': individual ISO-3166-1 alpha-2 codes to offer on top of the regions.
    //            Only countries that can be invoiced are offered: EU member states, or
    //            codes that ALSO have an 'additional_countries' entry with their VAT
    //            rate. A non-EU code listed here alone (e.g. 'GB') is ignored and
    //            reported by `billing:check-config` — declare it under
    //            'additional_countries' instead, which adds it to this list by itself.
    // 'exclude': codes to remove from the resolved list.
    // Countries from 'additional_countries' below are also included automatically.
    'checkout_countries' => [
        'regions' => ['EU'],
        'include' => [],
        'exclude' => [],
    ],

    // Default country pre-selected in the checkout/billing-data dropdown when no
    // billable country is persisted yet and the IP-based lookup yields no result.
    'default_billing_country' => env('BILLING_DEFAULT_COUNTRY', 'AT'),

    'vat' => [
        // Your own country (ISO-3166-1 alpha-2). Reverse charge under Art. 196 VAT
        // Directive is cross-border only, so without this a customer in your own
        // country holding a valid VAT ID is invoiced at 0% instead of domestic VAT.
        // Falls back to invoices.seller.address.country; when neither is set the
        // package logs a warning and grants reverse charge on VIES validity alone.
        'seller_country' => env('BILLING_SELLER_COUNTRY'),

        // Refuse reverse charge entirely while no seller country is known, instead
        // of falling back to the pre-0.4 behaviour above.
        'require_seller_country' => env('BILLING_REQUIRE_SELLER_COUNTRY', false),
    ],

    // VAT country used when a billable has none persisted at the moment we have to
    // price something. Deliberately separate from `default_billing_country`, which
    // only pre-selects a dropdown: this one decides the rate actually charged, and
    // checkout and webhook MUST agree on it or a paid order cannot be invoiced.
    'checkout_country_fallback' => env('BILLING_CHECKOUT_COUNTRY_FALLBACK', 'DE'),

    'redirect_after_return' => env('BILLING_REDIRECT_AFTER_RETURN'),


    // Package-wide currency (ISO-4217). Prices in mollie-billing-plans.php and coupon amounts
    // are interpreted in this currency. Mollie payments, sales invoices and BillingInvoice
    // persist this code. The EU VAT/OSS system (mpociot/vat-calculator, VIES, OSS export)
    // is EU-only but the currency itself is independent of it.
    /*
     * Largest seat count a single change may request.
     *
     * The portal's number input is client-side only and `SyncSeats` clamped nothing but
     * the lower bound, so a mistyped order of magnitude (or a `$wire.set()`) reached
     * `SubscriptionAmount::net()` and raised a prorata charge in the millions. Checkout has
     * capped the equivalent field at 100 all along; this is the same intent for the change
     * path. Raise it if you sell to organisations that large.
     */
    'max_seats' => (int) env('BILLING_MAX_SEATS', 1000),

    'currency' => env('BILLING_CURRENCY', 'EUR'),
    // Leave unset to let BillingMoney derive the symbol from `currency` (EUR → €,
    // USD → $, GBP → £, anything else → the ISO code). Only set this to override
    // that, e.g. 'CHF ' or 'Fr.' — a hardcoded value here would otherwise be used
    // for every currency.
    'currency_symbol' => env('BILLING_CURRENCY_SYMBOL'),

    'routes' => [
        // The admin panel's routes (`/billing/admin/*`) are registered by the service
        // provider. Set this to false when you mount them yourself with
        // `MollieBilling::adminRoutes()` inside your own route group — otherwise both
        // registrations exist, the auto-loaded ones match first, and your middleware
        // stack never runs.
        'auto_load_admin' => env('BILLING_AUTO_LOAD_ADMIN_ROUTES', true),
    ],

    // Invoice PDF generation and storage. PDFs are generated locally via elegantly/laravel-invoices
    // and stored on the configured Laravel filesystem disk.
    'invoices' => [
        // Laravel filesystem disk for storing generated PDF invoices.
        'disk' => env('BILLING_INVOICE_DISK', 'local'),

        // Locale the PDF is rendered in. Pinned per invoice so a regenerated
        // document matches the archived one — without it the PDF would follow
        // whatever locale the request that triggered the (re)generation ran in.
        // Falls back to app.fallback_locale.
        'locale' => env('BILLING_INVOICE_LOCALE'),

        // Base path within the disk. Invoices are stored as {path}/{YYYY/MM}/{serial}.pdf.
        'path' => 'billing/invoices',

        // Logo image displayed in the top-right corner of invoices.
        // Supported formats:
        //   - APP_URL-based URL: "${APP_URL}/logo.png" (resolved to public_path)
        //   - Relative path:    "images/logo.png"      (resolved to public_path)
        //   - Absolute path:    "/var/www/public/logo.png"
        //   - Data URI:         "data:image/png;base64,..."
        'logo' => env('BILLING_INVOICE_LOGO'),

        // Seller information printed on every invoice.
        'seller' => [
            'company' => env('BILLING_SELLER_COMPANY'),
            'name' => env('BILLING_SELLER_NAME'),
            'email' => env('BILLING_SELLER_EMAIL'),
            'phone' => env('BILLING_SELLER_PHONE'),
            'tax_number' => env('BILLING_SELLER_TAX_NUMBER'),
            'address' => [
                'street' => env('BILLING_SELLER_STREET'),
                'city' => env('BILLING_SELLER_CITY'),
                'postal_code' => env('BILLING_SELLER_POSTAL_CODE'),
                'state' => env('BILLING_SELLER_STATE'),
                'country' => env('BILLING_SELLER_COUNTRY'),
            ],
        ],

        // Serial number format. P=prefix, Y=year, C=counter (each char = one digit slot).
        'serial_number' => [
            'format' => 'PP-YYCCCCCC',
            'prefix' => [
                'invoice' => 'IN',
                'credit_note' => 'CR',
                'one_time_order' => 'OT',
            ],
        ],

        // Temporary URL expiry in minutes (for S3-compatible disks).
        'temporary_url_expiry' => 30,
    ],

    // OSS (One-Stop Shop) export storage. CSVs are generated by a queued job
    // and stored on the configured Laravel filesystem disk so the admin portal
    // can hand out a short-lived download URL — works with S3-compatible
    // private buckets via temporaryUrl().
    'oss' => [
        // Disk for storing OSS export CSVs. Falls back to invoices.disk when null.
        'disk' => env('BILLING_OSS_DISK'),

        // Base path within the disk. Files are stored as {path}/oss-export-{YYYY}-{timestamp}.csv.
        'path' => env('BILLING_OSS_PATH', 'billing/oss-exports'),

        // Temporary URL expiry in minutes (for S3-compatible disks).
        'temporary_url_expiry' => 30,
    ],

    // Default for Billable::allowsBillingOverage() — overridable per billable.
    'allow_overage_default' => env('BILLING_ALLOW_OVERAGE', true),

    // Controls how plan changes are applied. Note the values are the snake_case
    // backing values, not the enum case names:
    // immediate      — always applied immediately (with prorata billing)
    // end_of_period  — always scheduled for the end of the current period
    // user_choice    — the user decides (both options shown in the portal)
    //
    // `tryFrom`, not `from`: this file is evaluated inside the service provider's
    // `mergeConfigFrom()`, so a typo in the env value used to throw a ValueError during
    // `register()` — every request and every artisan call dead, including the
    // `billing:check-config` that would have named the problem. It degrades to the
    // default instead, and check-config reports the offending raw value.
    'plan_change_mode' => PlanChangeMode::tryFrom(
        strtolower(trim((string) env('BILLING_PLAN_CHANGE_MODE', PlanChangeMode::UserChoice->value)))
    ) ?? PlanChangeMode::UserChoice,

    // Mollie API key. When set, propagates to `mollie.key` so the underlying
    // `mollie/laravel-mollie` package picks it up. Leave empty to keep using
    // `MOLLIE_KEY` from the `mollie/laravel-mollie` config — both work, this
    // alias just keeps all package settings on the `BILLING_*` prefix.
    'mollie_api_key' => env('BILLING_MOLLIE_KEY'),

    'mollie_locale' => env('BILLING_MOLLIE_LOCALE'),

    // Primary key type of the billable model — set before first migration run.
    'billable_key_type' => env('BILLING_BILLABLE_KEY_TYPE', 'uuid'), // uuid|ulid|int

    // Primary key type of the auth user model (used e.g. for resolved_by_user_id
    // on country mismatches). Set before first migration run.
    'user_key_type' => env('BILLING_USER_KEY_TYPE', 'int'), // uuid|ulid|int

    // Audit trail — every billing event is recorded against the billable via
    // spatie/laravel-activitylog and shown as a timeline in the admin panel.
    // Rows store a translation key plus raw values, never a rendered sentence,
    // so the history reads correctly in any locale. See docs/audit.md.
    'audit' => [
        'enabled' => env('BILLING_AUDIT_ENABLED', true),

        // Separates our rows from the app's own activitylog usage. Every query
        // the package makes filters on this.
        'log_name' => env('BILLING_AUDIT_LOG_NAME', 'billing'),

        // Categories to record. Drop 'usage' on high-volume metered setups:
        // UsageLimitReached / WalletCredited can fire on every request, and each
        // one costs a synchronous insert.
        'categories' => [
            'subscription',
            'payment',
            'invoice',
            'payment_method',
            'coupon',
            'trial',
            'usage',
            'compliance',
        ],

        // How long audit rows are kept. PruneBillingAuditJob runs monthly and
        // deletes older rows — scoped to `log_name` above, so the app's own
        // activitylog entries are never touched. Set to null to keep forever.
        //
        // Defaults to 10 years: the volume is low (a handful of rows per billable
        // per year) and it comfortably covers the statutory retention periods for
        // billing records in AT/DE (7 years) and most other EU jurisdictions.
        'retention_days' => env('BILLING_AUDIT_RETENTION_DAYS', 3650),
    ],

    'ip_geolocation' => [
        'driver' => env('BILLING_IP_DRIVER', 'ipinfo_lite'),
        'drivers' => [
            'ipinfo_lite' => ['token' => env('IPINFO_TOKEN')],
            'db_ip' => ['api_key' => env('DB_IP_API_KEY')],
            'null' => [],
        ],
    ],

    // IP-based country gating. Applied to every package route except the Mollie
    // webhook (we don't know where webhooks come from). When a request resolves
    // to a country that is not allowed, the user is sent to a static "blocked"
    // page that explains why and links back to "/".
    //
    //   mode = 'blocklist' → 'countries' lists the COUNTRIES TO BLOCK; everything else passes.
    //   mode = 'allowlist' → 'countries' lists the ONLY COUNTRIES ALLOWED; everything else is blocked.
    //
    // 'block_unknown' decides what happens when the geolocation lookup yields
    // no country (private IP, lookup error, driver returned null). Default is
    // false — unknowns pass through. Set to true for strict allowlist setups.
    //
    // 'countries' can be configured via the BILLING_IP_BLOCK_COUNTRIES env
    // variable as a comma-separated list of ISO-3166-1 alpha-2 codes
    // (e.g. "RU,KP,IR"). Whitespace is trimmed and codes are upper-cased. When
    // set, the env value takes precedence over any inline array below.
    'ip_block' => [
        'enabled' => env('BILLING_IP_BLOCK_ENABLED', false),
        'mode' => env('BILLING_IP_BLOCK_MODE', 'blocklist'),
        'countries' => array_values(array_filter(array_map(
            fn (string $iso): string => strtoupper(trim($iso)),
            explode(',', (string) env('BILLING_IP_BLOCK_COUNTRIES', ''))
        ))),
        'block_unknown' => env('BILLING_IP_BLOCK_UNKNOWN', false),
    ],

    'overage_job_time' => env('BILLING_OVERAGE_JOB_TIME', '02:00'),

    // Daily run-time for ProcessTrialLifecycleJob (UTC). The job sends
    // trial-ending notifications and expires trials whose `trial_ends_at`
    // has passed without a successful first charge. Scheduled separately
    // from the overage job so trial-lifecycle bookkeeping stays decoupled.
    'trial_lifecycle_job_time' => env('BILLING_TRIAL_LIFECYCLE_JOB_TIME', '02:05'),

    // How many days before `trial_ends_at` the TrialEndingSoonNotification /
    // TrialConvertedNotification (with mandate) is sent. The job runs daily
    // and matches billables whose trial ends on the calendar day exactly
    // `trial_ending_soon_notice_days` ahead. Missed windows are NOT
    // backfilled — increasing this value will not retroactively notify
    // billables whose window already passed.
    'trial_ending_soon_notice_days' => (int) env('BILLING_TRIAL_ENDING_SOON_NOTICE_DAYS', 1),

    // Cleanup of orphaned billables — billables that were created during a
    // checkout flow but never reached an active subscription (user closed the
    // tab, Mollie webhook never arrived, mandate was set up but the first
    // payment was abandoned, …). The CleanupOrphanedBillablesJob runs every
    // 15 minutes by default and deletes such records once they exceed
    // `threshold_minutes` of age.
    //
    // Detection (hybrid):
    //   - If the billable has a `pending_first_payment_id` in subscription_meta,
    //     Mollie is polled and the record is only cleaned when the payment is
    //     in a terminal failure state (failed/canceled/expired).
    //   - Otherwise the billable is cleaned purely based on age + the absence
    //     of an accessible subscription.
    //
    // The actual deletion is delegated to MollieBilling::cleanupOrphanedBillableUsing()
    // when an app registers a closure (e.g. to also delete the related User /
    // tenant record). When no closure is registered the package soft-deletes
    // the billable directly via `$billable->delete()`.
    'cleanup' => [
        'enabled' => env('BILLING_CLEANUP_ORPHANED_ENABLED', true),
        'threshold_minutes' => (int) env('BILLING_CLEANUP_ORPHANED_THRESHOLD_MINUTES', 60),
        'cron_expression' => env('BILLING_CLEANUP_ORPHANED_CRON', '*/15 * * * *'),
    ],

    // Auto-cancel of subscriptions stuck in `past_due`. After this many days
    // without a successful recovery payment, PrepareUsageOverageJob's Pass 4
    // transitions the billable to `cancelled` with `subscription_ends_at = now`.
    // Pass 1 of the *next* run then flips `cancelled → expired` and clears the
    // plan-scoped fields, releasing access permanently — the expiry pass runs
    // before the auto-cancel precisely so both never happen in one invocation,
    // which used to make `cancelled` unobservable. Recovery (PastDue → Active)
    // still works as long as Mollie succeeds within this window. Set
    // BILLING_PAST_DUE_MAX_DAYS=0 to disable the auto-cancel (PastDue then lives
    // forever until manual action).
    'past_due_max_days' => (int) env('BILLING_PAST_DUE_MAX_DAYS', 30),

    'usage_threshold_percent' => env('BILLING_USAGE_THRESHOLD', 80),

    // Fallback for usage types that have no explicit `rollover` entry
    // under `usage_types` in mollie-billing-plans.php. When true, unused
    // credits of that type carry over to the next billing period; when
    // false, the wallet is reset to the plan's included quota on each
    // renewal. Per-type overrides always win.
    'usage_rollover_fallback' => env('BILLING_USAGE_ROLLOVER_FALLBACK', false),

    // Admin KPI cache TTL in seconds
    'admin_kpi_cache_ttl' => env('BILLING_ADMIN_KPI_TTL', 300),

    'show_yearly_savings' => env('BILLING_SHOW_YEARLY_SAVINGS', true),

    // Behavior of free / Local subscriptions (subscription_source = local).
    //
    // Local subs have no Mollie mandate, so paid extras (add-ons, extra seats)
    // can never be charged and are always blocked. One-time product purchases,
    // however, are oneoff card payments and Mollie accepts them without a
    // mandate — whether that fits your business model is up to you.
    //
    //   true  → free-plan users can purchase one-time products (token packs etc.)
    //   false → purchase attempts throw LocalSubscriptionCannotPurchaseProductsException
    //           and the products page hides the buy buttons with an upgrade hint.
    'local_subscription' => [
        'allow_one_time_orders' => env('BILLING_LOCAL_ALLOW_ONE_TIME_ORDERS', false),
    ],

    'vat_rate_overrides' => [
        // 'DE' => 19.0,
    ],

    'additional_countries' => [
        // 'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
    ],

    // Queue connection and name used for all background jobs and queued
    // notifications dispatched by this package (PrepareUsageOverageJob,
    // RetryUsageOverageChargeJob, RevokeMollieMandateJob, …).
    // null on either key falls back to the framework default
    // (queue.default connection / "default" queue name).
    //
    // Everything routed here is also dispatched after-commit. Almost all of it is
    // enqueued from inside a DB::transaction, and a worker that dequeues first loses
    // the race twice over: a notification whose invoice row does not exist yet fails
    // its model lookup and is dropped into failed_jobs with no retry, and a Mollie
    // PATCH can land for a change the transaction then rolls back.
    'queue' => [
        'connection' => env('BILLING_QUEUE_CONNECTION'),
        'name' => env('BILLING_QUEUE_NAME'),
    ],

    // IANA timezone for displaying billing dates in the portal as the fallback
    // when a billable does not override Billable::getBillingTimezone(). Persistence
    // and computation are always in UTC; admin views also render in UTC for
    // forensic parity with the database. See docs/timezone.md.
    'billing_timezone' => env('BILLING_TIMEZONE', 'UTC'),
];

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;

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

    // Where the checkout "Back" link leads when no explicit $backUrl is passed.
    'checkout_back_url' => env('BILLING_CHECKOUT_BACK_URL', '/'),

    // Countries available in the checkout country selector.
    // 'regions': built-in groups — currently only 'EU' (27 member states).
    // 'include': additional ISO-3166-1 alpha-2 codes (e.g. ['CH', 'GB']).
    // 'exclude': codes to remove from the resolved list.
    // Countries from 'additional_countries' below are also included automatically.
    'checkout_countries' => [
        'regions' => ['EU'],
        'include' => [],
        'exclude' => [],
    ],

    'redirect_after_return' => env('BILLING_REDIRECT_AFTER_RETURN'),

    'require_payment_method_for_zero_amount' => env('BILLING_REQUIRE_PM_ZERO', true),

    // Package-wide currency (ISO-4217). Prices in mollie-billing-plans.php and coupon amounts
    // are interpreted in this currency. Mollie payments, sales invoices and BillingInvoice
    // persist this code. The EU VAT/OSS system (mpociot/vat-calculator, VIES, OSS export)
    // is EU-only but the currency itself is independent of it.
    'currency' => env('BILLING_CURRENCY', 'EUR'),
    'currency_symbol' => env('BILLING_CURRENCY_SYMBOL', '€'),

    // Invoice PDF generation and storage. PDFs are generated locally via elegantly/laravel-invoices
    // and stored on the configured Laravel filesystem disk.
    'invoices' => [
        // Laravel filesystem disk for storing generated PDF invoices.
        'disk' => env('BILLING_INVOICE_DISK', 'local'),

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
            ],
        ],

        // Temporary URL expiry in minutes (for S3-compatible disks).
        'temporary_url_expiry' => 30,
    ],

    // Default for Billable::allowsBillingOverage() — overridable per billable.
    'allow_overage_default' => env('BILLING_ALLOW_OVERAGE', true),

    // Controls how plan changes are applied:
    // Immediate    — always applied immediately (with prorata billing)
    // EndOfPeriod  — always scheduled for the end of the current period
    // UserChoice   — the user decides (both options shown in the portal)
    'plan_change_mode' => PlanChangeMode::from(
        env('BILLING_PLAN_CHANGE_MODE', PlanChangeMode::UserChoice->value)
    ),

    'mollie_locale' => env('BILLING_MOLLIE_LOCALE'),

    // Primary key type of the billable model — set before first migration run.
    'billable_key_type' => env('BILLING_BILLABLE_KEY_TYPE', 'uuid'), // uuid|ulid|int

    'ip_geolocation' => [
        'driver' => env('BILLING_IP_DRIVER', 'ipinfo_lite'),
        'drivers' => [
            'ipinfo_lite' => ['token' => env('IPINFO_TOKEN')],
            'null' => [],
        ],
    ],

    'overage_job_time' => env('BILLING_OVERAGE_JOB_TIME', '02:00'),

    'usage_threshold_percent' => env('BILLING_USAGE_THRESHOLD', 80),

    // Whether unused usage credits carry over to the next billing period.
    // When false, wallets are reset to the plan's included quota on each renewal.
    // Can be overridden per plan in mollie-billing-plans.php.
    'usage_rollover' => env('BILLING_USAGE_ROLLOVER', false),

    // Admin KPI cache TTL in seconds
    'admin_kpi_cache_ttl' => env('BILLING_ADMIN_KPI_TTL', 300),

    'show_yearly_savings' => env('BILLING_SHOW_YEARLY_SAVINGS', true),

    'vat_rate_overrides' => [
        // 'DE' => 19.0,
    ],

    'additional_countries' => [
        // 'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
    ],

    'queue' => [
        'connection' => env('BILLING_QUEUE_CONNECTION'),
        'name' => env('BILLING_QUEUE_NAME', 'billing'),
    ],
];

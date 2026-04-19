<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;

return [
    'billable_model' => env('BILLING_BILLABLE_MODEL'),

    'logo_url' => env('BILLING_LOGO_URL'),
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

    'prorata_enabled' => env('BILLING_PRORATA_ENABLED', false),

    'redirect_after_return' => env('BILLING_REDIRECT_AFTER_RETURN'),

    'require_payment_method_for_zero_amount' => env('BILLING_REQUIRE_PM_ZERO', true),

    // Package-wide currency (ISO-4217). Prices in mollie-billing-plans.php and coupon amounts
    // are interpreted in this currency. Mollie payments, sales invoices and BillingInvoice
    // persist this code. The EU VAT/OSS system (mpociot/vat-calculator, VIES, OSS export)
    // is EU-only but the currency itself is independent of it.
    'currency' => env('BILLING_CURRENCY', 'EUR'),

    // Enable creation of Mollie Sales Invoices (B2B add-on) for each payment. When disabled
    // (default) the package still persists a local BillingInvoice with full line items, but
    // Mollie-side sales invoice id / url / pdf stay null.
    'mollie_sales_invoices_enabled' => env('BILLING_MOLLIE_SALES_INVOICES', false),

    // Default for Billable::allowsBillingOverage() — overridable per billable.
    'allow_overage_default' => env('BILLING_ALLOW_OVERAGE', true),

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

    // Default subscription status when a billable is created.
    'default_subscription_status' => SubscriptionStatus::Active->value,
];

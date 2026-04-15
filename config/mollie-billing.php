<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;

return [
    'billable_model' => env('BILLING_BILLABLE_MODEL'),

    'logo_url' => env('BILLING_LOGO_URL'),
    'primary_color' => env('BILLING_PRIMARY_COLOR', '#6366f1'),
    'company_name' => env('APP_NAME'),

    'prorata_enabled' => env('BILLING_PRORATA_ENABLED', false),

    'redirect_after_return' => env('BILLING_REDIRECT_AFTER_RETURN', 'billing.index'),

    // Consuming app owns the checkout/plan-selection flow. Package code (promotion redirect,
    // past-due middleware, trial-expired mail) needs to know where to send users who don't
    // yet have an active subscription. Set this to your app's checkout route name.
    'checkout_route' => env('BILLING_CHECKOUT_ROUTE', 'billing.index'),

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

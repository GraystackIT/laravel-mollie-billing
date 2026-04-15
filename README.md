# graystackit/laravel-mollie-billing

> Mollie billing for Laravel with VAT, metered billing, coupons, access grants and admin panel.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/graystackit/laravel-mollie-billing.svg?style=flat-square)](https://packagist.org/packages/graystackit/laravel-mollie-billing)
[![PHP Version](https://img.shields.io/packagist/php-v/graystackit/laravel-mollie-billing.svg?style=flat-square)](https://packagist.org/packages/graystackit/laravel-mollie-billing)
[![Laravel Version](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x%20%7C%2013.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Total Downloads](https://img.shields.io/packagist/dt/graystackit/laravel-mollie-billing.svg?style=flat-square)](https://packagist.org/packages/graystackit/laravel-mollie-billing)
[![License](https://img.shields.io/packagist/l/graystackit/laravel-mollie-billing.svg?style=flat-square)](LICENSE)

A batteries-included Mollie billing layer for Laravel that wraps `mollie/laravel-mollie` ^4 and adds VAT/OSS compliance, wallet-based metered billing, a coupon engine, scheduled plan changes, an admin panel, and a Livewire 4 customer portal — all keyed off a `Billable` contract that lives on whichever model owns the subscription (typically your `Organization`, not your `User`).

## Highlights

- Mollie subscriptions, mandates and webhooks (built on Mollie's official Laravel SDK v4 with typed request objects)
- VAT calculation, VIES validation and OSS export (`mpociot/vat-calculator`)
- Country-mismatch reconciliation across declared / IP / payment country
- Wallet-based metered billing with included quotas and overage prices (`bavix/laravel-wallet`)
- Direct overage charging with retry and `past_due` state
- Five coupon types — `FirstPayment`, `Recurring`, `Credits`, `TrialExtension`, `AccessGrant`
- Access Grants for full-plan or addon-only complimentary access
- Scheduled plan changes, prorata, end-of-period downgrades
- Refunds and credit notes (full, overage units, wallet-only)
- IP geolocation hook for tax-country detection
- Trial flow with Local-to-Mollie subscription conversion
- Feature gating via `@planFeature` Blade directive and `billing.feature` middleware
- Livewire 4 SFC customer portal with optional Flux Pro admin panel
- Promotion links via signed `/promotion/{token}` URLs
- Localized notifications (English and German out of the box)

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- A Mollie account with API key
- Livewire 4 (for the customer portal views)
- `livewire/flux` (free) for portal components, or `livewire/flux-pro` for the admin panel

## Installation

> **Laravel 13 note** — `mpociot/vat-calculator` does not yet declare Laravel 13 compatibility upstream. We maintain a drop-in fork at [GraystackIT/laravel-vat-calculator](https://github.com/GraystackIT/laravel-vat-calculator) that loosens the constraint and `replace`s the original package. Add it as a VCS repository **before** requiring the billing package in your root `composer.json`:
>
> ```json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/GraystackIT/laravel-vat-calculator" }
> ]
> ```
>
> Composer will then transparently resolve `mpociot/vat-calculator` through the fork. Laravel 11 and 12 consumers can skip this step.

```bash
composer require graystackit/laravel-mollie-billing
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=mollie-billing-config
php artisan vendor:publish --tag=mollie-billing-migrations
php artisan vendor:publish --tag=mollie-billing-plans
php artisan vendor:publish --tag=mollie-billing-lang
```

Edit `config/mollie-billing.php` and set the billable model:

```php
'billable_model' => \App\Models\Organization::class,
'billable_key_type' => 'uuid', // 'uuid' | 'ulid' | 'int'
```

> **Important:** `billable_key_type` must be set **before running migrations for the first time**. It controls the column type of every polymorphic foreign key that references your billable — including `bavix/laravel-wallet`'s `wallets.holder_id`, `transactions.payable_id`, and `transfers.{from,to}_id`, which we rewrite from the default `bigint` to `uuid`/`ulid`. Changing it later requires manually altering those columns.

Then run migrations:

```bash
php artisan migrate
```

## Quick start

Add the `HasBilling` trait and implement the `Billable` contract on your billable model — typically a tenant or organization, not the `User`:

```php
<?php

namespace App\Models;

use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model implements Billable
{
    use HasBilling;
}
```

Configure your environment:

```dotenv
MOLLIE_API_KEY=test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
BILLING_BILLABLE_MODEL=App\Models\Organization
BILLING_BILLABLE_KEY_TYPE=uuid
BILLING_CURRENCY=EUR
```

Mount the package routes in `routes/web.php`. Tenant-scoped routes (webhook, promotion, customer portal) and admin-panel routes are registered separately so they can run under different middleware stacks — the customer portal needs a resolved tenant, the admin panel does not:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

// Customer portal — needs auth + your tenant resolution middleware
Route::middleware(['web', 'auth', 'tenant'])->group(function () {
    MollieBilling::routes();
});

// Admin panel — auth only, no tenant scope. AuthorizeBillingAdmin runs inside the group.
Route::middleware(['web', 'auth'])->group(function () {
    MollieBilling::adminRoutes();
});
```

Tell the facade how to resolve the current billable for the authenticated user — usually in `AppServiceProvider::boot()`:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

MollieBilling::resolveBillableUsing(fn () => auth()->user()?->currentOrganization);
MollieBilling::authUsing(fn () => auth()->check());
```

Customize `config/mollie-billing-plans.php` to define your plans, addons and feature keys.

## Configuration

Highlights of `config/mollie-billing.php`:

| Key | Purpose |
| --- | --- |
| `currency` | Default currency for prices and invoices (e.g. `EUR`). |
| `prorata_enabled` | Enable prorated charges/credits when changing plans mid-period. |
| `allow_overage_default` | Default policy when a plan does not declare its own overage rule. |
| `ip_driver` | IP geolocation driver name (rebind `MollieBilling::ipGeolocation()` for custom). |
| `additional_countries` | ISO-3166 codes for non-EU jurisdictions you also serve. |
| `vat_rate_overrides` | Map of country code to override VAT percentage. |
| `company_name` | Display name used in notification subjects and signatures. |
| `billable_model` | Fully-qualified class name of your billable model. |
| `billable_key_type` | `uuid`, `ulid`, or `int` — determines morph column shape. |

## Plans and addons

Define your catalog in `config/mollie-billing-plans.php`. Free plans run as `SubscriptionSource::Local` (no Mollie subscription), paid plans are `SubscriptionSource::Mollie`.

```php
<?php

return [
    'plans' => [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'trial_days' => 14,
            'included_seats' => 3,
            'feature_keys' => ['dashboard', 'advanced-reports'],
            'allowed_addons' => ['softdrinks'],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900,
                    'seat_price_net' => 990,
                    // Included quota per billing period (here: per month)
                    'included_usages' => ['tokens' => 100, 'sms' => 50],
                    // Cents per unit over quota; omit a key for "no overage"
                    'usage_overage_prices' => ['tokens' => 10, 'sms' => 15],
                ],
                'yearly' => [
                    'base_price_net' => 29000,
                    'seat_price_net' => 9900,
                    // Included quota per billing period (here: per year)
                    'included_usages' => ['tokens' => 1500, 'sms' => 600],
                    'usage_overage_prices' => ['tokens' => 10, 'sms' => 15],
                ],
            ],
        ],
    ],

    'addons' => [
        'softdrinks' => [
            'name' => 'Softdrinks',
            'feature_keys' => ['softdrinks'],
            'intervals' => [
                'monthly' => ['price_net' => 490],
                'yearly' => ['price_net' => 4900],
            ],
        ],
    ],
];
```

## The Billable contract

A minimal billable model needs nothing beyond the trait — `HasBilling` handles casts, relations, URLs and delegates actions to the underlying services through the container. To override behavior (e.g. how the portal URL is generated), bind your own implementation of the relevant service interface.

```php
class Organization extends Model implements Billable
{
    use HasBilling;
}
```

`HasBilling` provides among others:

- `recordBillingUsage($type, $quantity)` and `creditBillingUsage(...)`
- `hasPlanFeature('reports.export')`
- `cancelBillingSubscription()`, `changeBillingPlan(...)`, `enableBillingAddon(...)`
- `billingPortalUrl()`, `billingPlanChangeUrl()`
- `latestBillingInvoice()` and `billingInvoices()` morph relation

## Coupon types

| Type | Behavior | Quick example |
| --- | --- | --- |
| `FirstPayment` | Discounts only the first invoice. | `MollieBilling::coupons()->firstPaymentCoupon('LAUNCH', 50, 'percent');` |
| `Recurring` | Discounts each invoice for N periods. | `MollieBilling::coupons()->recurringCoupon('LOYAL', 10, 'percent', periods: 6);` |
| `Credits` | Adds wallet credit balance. | `MollieBilling::coupons()->creditsCoupon('PROMO5', cents: 500);` |
| `TrialExtension` | Extends the active trial. | `MollieBilling::coupons()->trialExtensionCoupon('EXTEND14', days: 14);` |
| `AccessGrant` | Grants free access without payment. | See below. |

## Access Grants

Access Grants come in two flavors — full-plan grants and addon-only grants:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

// Full plan access for 90 days, no payment method required:
MollieBilling::coupons()->accessGrantCoupon(
    code: 'BETA90',
    planCode: 'pro',
    interval: 'monthly',
    days: 90,
);

// Addon-only grant — the customer keeps their existing plan:
MollieBilling::coupons()->addonGrantCoupon(
    code: 'PRIORITY30',
    addonCode: 'priority_support',
    days: 30,
);
```

## Updating subscriptions

The `update` orchestrator handles plan changes, addon toggles and seat sync atomically:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

MollieBilling::subscriptions()->update($organization, [
    'plan_code' => 'pro',
    'interval' => 'yearly',
    'addons' => ['priority_support' => true],
    'seats' => 12,
    'apply' => 'immediate', // or 'end_of_period'
]);
```

## Preview

Preview the financial impact of an update before applying it:

```php
$preview = MollieBilling::preview()->previewUpdate($organization, [
    'plan_code' => 'pro',
    'interval' => 'yearly',
]);

// $preview->prorataCredit, $preview->newChargeGross, $preview->vatAmount, ...
```

## Refunds

Three convenience methods cover the common cases:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

// Refund a full invoice and issue a credit note:
MollieBilling::refunds()->refundFully($invoice, reason: 'duplicate_payment');

// Refund just the overage units billed for a period:
MollieBilling::refunds()->refundOverageUnits($invoice, units: 1_000);

// Wallet-only refund — credits the wallet without touching Mollie:
MollieBilling::refunds()->creditWalletOnly($organization, cents: 500, reason: 'goodwill');
```

## Admin panel

The admin panel lives at `/billing/admin` and requires `livewire/flux-pro`. Authorize access by implementing `AuthorizesBillingAdmin` directly on your user model. The `billing.admin` middleware checks `auth()->user() instanceof AuthorizesBillingAdmin && canAccessBillingAdmin()`; users without the interface receive a 403.

```php
<?php

namespace App\Models;

use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool
    {
        return $this->is_admin === true;
    }
}
```

## Promotion links

Generate signed promotion URLs that auto-apply a coupon when the customer follows them:

```
https://your-app.test/promotion/{token}
```

Tokens are generated via `MollieBilling::coupons()->promotionToken($coupon)`.

## Events

Every state change dispatches a Laravel event so apps can react via listeners. Notable events include:

- `SubscriptionStarted`, `SubscriptionActivated`, `SubscriptionChanged`, `SubscriptionCancelled`
- `TrialStarted`, `TrialConverted`, `TrialExpired`
- `MandateAdded`, `MandateRevoked`
- `InvoiceIssued`, `InvoicePaid`, `InvoiceFailed`
- `OverageBilled`, `OverageBillingFailed`
- `CouponRedeemed`, `AccessGrantApplied`, `AccessGrantExpired`
- `CountryMismatchDetected`, `CountryMismatchResolved`
- `RefundIssued`, `CreditNoteCreated`

Subscribe in your `EventServiceProvider` exactly like any other Laravel event.

## Testing

The package ships with helpers for both unit and feature tests:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Testing\BillableStateHelper;

MollieBilling::fake();

$billable = TestBillable::factory()->create();
BillableStateHelper::onPaidPlan($billable, 'pro', 'monthly');

$billable->recordBillingUsage('api_calls', 1_500);

MollieBilling::assertSubscriptionStarted($billable);
```

## Commands

```bash
# Re-queue overage charges for everyone whose period ended in the last hour
php artisan billing:prepare-overage

# Export the OSS report for a given calendar year
php artisan billing:oss-export 2026
```

## Architecture

This package sits on top of `mollie/laravel-cashier-mollie` and adds a VAT/OSS layer (`mpociot/vat-calculator` plus VIES), a wallet layer for metered billing (`bavix/laravel-wallet`), a coupon engine, an admin panel and a Livewire 4 customer portal. Subscription lifecycle is split into single-purpose service classes per action (Start, Create, Activate, Change, Cancel, Resubscribe, EnableAddon, DisableAddon, SyncSeats) — the `HasBilling` trait delegates to them via the container, so apps customize behavior by rebinding services rather than subclassing models.

Free or zero-price plans run as `SubscriptionSource::Local` without a Mollie subscription; paid plans are `SubscriptionSource::Mollie`. The trial flow seamlessly converts Local subscriptions to Mollie ones the moment a mandate is added.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

## Credits

- [graystackit](https://github.com/GraystackIT)
- [mollie/laravel-cashier-mollie](https://github.com/mollie/laravel-cashier-mollie)
- [mpociot/vat-calculator](https://github.com/mpociot/vat-calculator)
- [bavix/laravel-wallet](https://github.com/bavix/laravel-wallet)
- [livewire/flux](https://fluxui.dev)

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
- Built-in first-checkout flow with configurable country list, VAT/VIES validation and coupon support
- Livewire 4 SFC customer portal with optional Flux Pro admin panel
- Promotion links via signed `/promotion/{token}` URLs
- Localized notifications (English and German out of the box)
- All Livewire SFC views publishable and overridable

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

    public function getUsedBillingSeats(): int
    {
        return $this->users()->count();
    }
}
```

Configure your environment:

```dotenv
MOLLIE_KEY=test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
BILLING_BILLABLE_MODEL=App\Models\Organization
BILLING_BILLABLE_KEY_TYPE=uuid
BILLING_CURRENCY=EUR
```

Mount the package routes in `routes/web.php`. The three route groups serve different scopes and need different middleware:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

// Customer portal — needs auth + your tenant resolution middleware
Route::middleware(['web', 'auth', 'tenant'])->group(function () {
    MollieBilling::routes();
});

// Checkout — needs auth but NOT tenant resolution (the checkout creates the tenant)
Route::middleware(['web', 'auth'])->group(function () {
    MollieBilling::checkoutRoutes();
});

// Admin panel — auth only, no tenant scope. AuthorizeBillingAdmin runs inside the group.
Route::middleware(['web', 'auth'])->group(function () {
    MollieBilling::adminRoutes();
});
```

The admin routes are auto-loaded by the service provider as well, so you only need to call `adminRoutes()` if you want them under a custom middleware stack.

### Multi-tenant URL prefixes

If your app nests the portal behind a tenant parameter (e.g. `prefix('{organization:slug}')`), mount `MollieBilling::routes()` **inside** that group — do not apply your own `->name('tenant.')` prefix around it, because the package's views call `route('billing.*')` by those exact names. Keep `checkoutRoutes()` **outside** the tenant group since no tenant exists yet at checkout time:

```php
Route::middleware(['auth', 'tenant'])
    ->prefix('{organization:slug}')
    ->group(function () {
        MollieBilling::routes();
    });

// Checkout lives outside the tenant prefix — the billable is created during checkout
Route::middleware(['auth'])->group(function () {
    MollieBilling::checkoutRoutes();
});
```

The package ships a `PropagateRouteDefaults` middleware that copies the active route's parameters into `URL::defaults`, so generated links inside the portal (e.g. `route('billing.plan')`) automatically carry the tenant slug — no app-side `URL::defaults` wiring required.

For contexts **without** an active HTTP request — queued notifications, background jobs, or services that run before tenant resolution — `PropagateRouteDefaults` cannot help. Register a global URL parameter resolver so the package can build correct URLs from any context:

```php
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;

MollieBilling::urlParametersUsing(
    fn (?Billable $billable) => $billable
        ? ['organization' => $billable->slug]
        : []
);
```

The `$billable` parameter is `null` in rare cases where no billable is available yet (e.g. some middleware checks). In those cases the closure should return `[]` or derive a fallback from `auth()->user()` or session state.

> **How the two mechanisms interact:** `PropagateRouteDefaults` covers the request context (portal views, form submissions). `urlParametersUsing` covers everything else (Mollie webhook URLs, redirect URLs sent to Mollie, queued mail, background jobs). They complement each other — both can be active simultaneously without conflict.

If your billable model needs custom logic beyond the global resolver, you can still override `urlRouteParameters()` on the model directly — the override takes precedence.

Tell the facade how to resolve the current billable for the authenticated user — usually in `AppServiceProvider::boot()`:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

MollieBilling::resolveBillableUsing(fn () => auth()->user()?->currentOrganization);
MollieBilling::authUsing(fn () => auth()->check());
```

Customize `config/mollie-billing-plans.php` to define your plans, addons and feature keys.

## First checkout

The package ships a complete first-checkout flow — a multi-step Livewire wizard that collects billing details, lets the customer choose a plan, optional addons/seats, apply a coupon, and redirects to Mollie for payment.

### Setup

Register three callbacks in your `AppServiceProvider::boot()`:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

// How to create a billable (Organization, Team, …) from checkout form data:
MollieBilling::createBillableUsing(function (array $data) {
    return Organization::create([
        'name'              => $data['name'],
        'billing_street'    => $data['billing_street'],
        'billing_city'      => $data['billing_city'],
        'billing_postal_code' => $data['billing_postal_code'],
        'billing_country'   => $data['billing_country'],
        'vat_number'        => $data['vat_number'],
    ]);
});

// Optional: run logic before the Mollie payment is created.
// Return null to proceed, or a string to block checkout with that error message.
MollieBilling::beforeCheckoutUsing(function (Billable $billable): ?string {
    // e.g. create a User and attach to the billable
    return null;
});

// Optional: run cleanup after checkout succeeds or fails.
MollieBilling::afterCheckoutUsing(function (Billable $billable, bool $success): void {
    if (! $success) {
        // e.g. delete the orphaned user
    }
});
```

### Link to checkout

```php
<a href="{{ MollieBilling::checkoutUrl('/pricing') }}">Subscribe now</a>
```

The optional `$backUrl` parameter controls where the "Back" link in the checkout header leads. When omitted, the package falls back to `config('mollie-billing.checkout_back_url')` (default `/`).

To pre-select a plan and/or billing interval, pass them as additional parameters:

```php
<a href="{{ MollieBilling::checkoutUrl('/pricing', plan: 'pro', interval: 'yearly') }}">
    Get Pro yearly
</a>
```

The plan step will still be shown so the customer can change their mind, but the given plan will be pre-selected. Invalid plan codes or intervals are silently ignored.

### Checkout countries

By default the checkout shows all 27 EU member states. Customize via config:

```php
// config/mollie-billing.php
'checkout_countries' => [
    'regions' => ['EU'],          // built-in: 'EU' (27 member states)
    'include' => ['CH', 'GB'],    // additional ISO codes
    'exclude' => ['MT'],          // remove from the list
],

// Countries defined here are auto-included in the checkout selector:
'additional_countries' => [
    'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
],
```

Country names are translated via the package's `billing::countries` lang files (English and German included). Publish and extend them for additional locales:

```bash
php artisan vendor:publish --tag=billing-lang
```

### Custom checkout steps

If your app needs additional steps before the billing-address form (e.g. "Create your account"), register them via the facade. Custom steps are inserted **before** the package's built-in steps; numbering, timeline and navigation adjust automatically.

```php
use Livewire\Component;
use GraystackIT\MollieBilling\Facades\MollieBilling;

// AppServiceProvider::boot()
MollieBilling::checkoutStepsUsing(fn () => [
    [
        'key'         => 'account',
        'label'       => 'Account',
        'headline'    => 'Create your account',
        'description' => 'Set up your login credentials before we continue.',
        'view'        => 'checkout.steps.account', // your app's Blade view
        'validate'    => function (Component $component) {
            $component->validate([
                'customData.name'  => ['required', 'string', 'max:255'],
                'customData.email' => ['required', 'email', 'unique:users,email'],
            ]);
        },
    ],
]);
```

Each step definition requires:

| Key | Type | Description |
| --- | --- | --- |
| `key` | `string` | Unique identifier for the step. |
| `label` | `string` | Short label shown in the timeline. |
| `headline` | `string` | Heading displayed above the step content. |
| `description` | `string` | Subheading text below the headline. |
| `view` | `string` | Blade view name to `@include` for this step's form fields. |
| `validate` | `Closure` | *(optional)* Receives the Livewire `Component` instance. Throw a `ValidationException` (or call `$component->validate(...)`) to block navigation. |

**Binding form data** — The checkout component exposes a `public array $customData = []` property. Use `wire:model` with dot notation in your step view:

```blade
{{-- resources/views/checkout/steps/account.blade.php --}}
<div class="flex flex-col gap-5">
    <flux:input wire:model.live="customData.name" label="Full name" required />
    <flux:input wire:model.live="customData.email" label="Email" type="email" required />
    <flux:input wire:model="customData.password" label="Password" type="password" required />
</div>
```

The `customData` array is passed to your `createBillableUsing` callback as `$data['custom']`, so you can access it when creating the billable:

```php
MollieBilling::createBillableUsing(function (array $data) {
    $user = User::create([
        'name'     => $data['custom']['name'],
        'email'    => $data['custom']['email'],
        'password' => Hash::make($data['custom']['password']),
    ]);

    $org = Organization::create([
        'name'            => $data['name'],
        'billing_street'  => $data['billing_street'],
        'billing_city'    => $data['billing_city'],
        'billing_postal_code' => $data['billing_postal_code'],
        'billing_country' => $data['billing_country'],
        'vat_number'      => $data['vat_number'],
    ]);

    $user->organizations()->attach($org);

    return $org;
});
```

You can register multiple custom steps — they appear in the order returned by the callback.

### Customizing views

All Livewire views (checkout, portal, admin) can be published and customized:

```bash
php artisan vendor:publish --tag=mollie-billing-views
```

Views are published to `resources/views/vendor/mollie-billing/`. SFC files use the ⚡ prefix convention (e.g. `⚡checkout.blade.php`).

### Tailwind CSS content source

The package's Blade views use Tailwind utility classes (including responsive breakpoints like `sm:`, `lg:`). Your host app's Tailwind build must scan the package views, otherwise these classes will be purged.

**Tailwind v4** — add a `@source` directive in your `resources/css/app.css`:

```css
@source "../../vendor/graystackit/laravel-mollie-billing/resources/views/**/*.blade.php";
```

**Tailwind v3** — add the path to the `content` array in `tailwind.config.js`:

```js
content: [
    // ...
    './vendor/graystackit/laravel-mollie-billing/resources/views/**/*.blade.php',
],
```

Without this, responsive grid layouts and other utility classes in the portal, checkout and admin panel may not render correctly.

## Configuration

Highlights of `config/mollie-billing.php`:

| Key | Purpose |
| --- | --- |
| `currency` | Default currency for prices and invoices (e.g. `EUR`). |
| `logo_url` | Logo displayed in checkout and portal headers. |
| `primary_color` | Accent color for checkout UI (hex, e.g. `#6366f1`). |
| `dashboard_url` | URL the portal logo links to (e.g. your app's main dashboard). Supports `route:` prefix. |
| `checkout_back_url` | Where the checkout "Back" link leads (default `/`). |
| `checkout_countries` | Countries shown in checkout (regions, include, exclude). |
| `allow_overage_default` | Default policy when a plan does not declare its own overage rule. |
| `additional_countries` | ISO-3166 codes + VAT rates for non-EU jurisdictions. |
| `vat_rate_overrides` | Map of country code to override VAT percentage. |
| `company_name` | Display name used in headers, notifications and signatures. |
| `billable_model` | Fully-qualified class name of your billable model. |
| `billable_key_type` | `uuid`, `ulid`, or `int` — determines morph column shape. |

### Portal "back to dashboard" link

By default the portal logo links to the billing dashboard itself. Set `dashboard_url` to link it to your app's main dashboard instead — a "Back to dashboard" link will also appear at the bottom of the sidebar:

```dotenv
# Plain URL:
BILLING_DASHBOARD_URL=/dashboard

# Or a named route (prefix with "route:"):
BILLING_DASHBOARD_URL=route:dashboard
```

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

A minimal billable model needs the trait **plus** one required method — `getUsedBillingSeats()`. This method is intentionally not provided by the trait because only your app knows how to count active seats (team members, users, etc.):

```php
class Organization extends Model implements Billable
{
    use HasBilling;

    public function getUsedBillingSeats(): int
    {
        return $this->users()->count();
    }
}
```

The seat count is used during plan-change previews to calculate whether extra seats need to be purchased on the new plan.

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

## Local subscriptions

The package distinguishes two subscription sources via the `subscription_source` column:

- `mollie` — a real Mollie subscription with mandate, recurring charges and invoices.
- `local` — a free / coupon-granted subscription with no Mollie mandate. The wallet receives the included usages on activation (and at scheduled renewals via `PrepareUsageOverageJob`), but no money flows.

### When does a Local subscription arise?

| Trigger | Service | Notes |
|---|---|---|
| Free plan checkout | `StartSubscriptionCheckout` → `ActivateLocalSubscription` | Mollie returns no `checkout_url` for a 0 € first payment; the app activates the plan locally. |
| `AccessGrant` coupon | `CouponService::applyAccessGrant` → `ActivateLocalSubscription` | Coupon-granted plans (timed or unlimited) live as Local. |
| Mollie → Free downgrade | `UpdateSubscription` | Cancels the Mollie subscription, sets `subscription_source = local`, status remains `active`, wallets are rebalanced (purchased credits preserved). |

### What is allowed on a Local subscription?

| Operation | Allowed? |
|---|---|
| Free addons (price 0) | yes |
| Paid addons | **no** — `LocalSubscriptionDoesNotSupportPaidExtrasException` |
| Extra seats on a plan with `seat_price_net > 0` | **no** — same exception |
| Switch to another free plan | yes |
| Switch directly to a paid plan via `UpdateSubscription` | **no** — `LocalSubscriptionUpgradeRequiresMolliePathException`. Use `UpgradeLocalToMollie` instead (the bundled plan-change UI does this automatically). |
| Cancel | yes — status switches to `cancelled`, wallets are kept until `subscription_ends_at`. |

### Local → Mollie upgrade

```php
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;

['checkout_url' => $url, 'payment_id' => $id] = app(UpgradeLocalToMollie::class)->handle($organization, [
    'plan_code'   => 'pro',
    'interval'    => 'monthly',
    'addon_codes' => [],
    'extra_seats' => 0,
    'amount_gross' => $previewedGross, // pre-computed by PreviewService
]);

return redirect()->away($url);
```

The webhook on the resulting first payment carries `metadata.upgrade_from_local = true` and routes through `MollieWebhookController::handleLocalToMollieUpgrade()`, which reuses the existing wallet (purchased balance preserved) instead of seeding a fresh one.

The bundled plan-change UI (`resources/views/livewire/billing/⚡plan-change.blade.php`) detects `Local → paid plan` automatically and shows a confirmation step before the Mollie redirect — no second checkout wizard.

### Mollie → Free behaviour

A user-initiated downgrade follows whatever `config('mollie-billing.plan_change_mode')` is set to (`Immediate`, `EndOfPeriod`, `UserChoice`). For `EndOfPeriod`, `ScheduleSubscriptionChange` queues the change and `PrepareUsageOverageJob` applies it at the period boundary — the same Mollie cancel + Source=Local flip happens then.

`purchased_balance` (one-time orders, coupon credits) is preserved across every plan change, including downgrades to free.

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
MollieBilling::refunds()->refundFully($invoice, RefundReasonCode::BillingError);

// Partial refund of a specific net amount (in cents):
MollieBilling::refunds()->refundPartially($invoice, 500, RefundReasonCode::Goodwill, 'customer request');

// Refund specific overage units (auto-calculates amount from unit price, credits wallet):
MollieBilling::refunds()->refundOverageUnits($invoice, 'tokens', 1_000, RefundReasonCode::Goodwill);

// Wallet-only credit without touching Mollie — use WalletUsageService directly:
app(WalletUsageService::class)->credit($organization, 'tokens', 500, 'goodwill bonus');
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

- `CheckoutStarted`, `CheckoutAbandoned`
- `SubscriptionCreated`, `SubscriptionCancelled`, `SubscriptionExpired`, `SubscriptionResumed`
- `PlanChanged`, `SubscriptionUpdated`, `SubscriptionChangeScheduled`
- `TrialStarted`, `TrialConverted`, `TrialExpired`, `TrialExtended`
- `MandateUpdated`
- `PaymentSucceeded`, `PaymentFailed`, `PaymentAmountMismatch`, `DuplicatePaymentReceived`
- `InvoiceCreated`, `InvoiceRefunded`, `CreditNoteIssued`
- `OverageCharged`, `OverageChargeFailed`
- `CouponRedeemed`, `GrantRevoked`
- `CountryMismatchFlagged`, `CountryMismatchResolved`
- `WalletCredited`, `UsageLimitReached`

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

## Documentation

Detailed technical documentation is available in the [`docs/`](docs/) directory:

- [Configuration](docs/configuration.md) — `mollie-billing.php` and `mollie-billing-plans.php` reference
- [Plan Changes](docs/plan-changes.md) — deferred upgrade flow, validation rules, events, extension points
- [Subscription Lifecycle](docs/subscription-lifecycle.md) — states, transitions, service overview

## Architecture

This package wraps `mollie/laravel-mollie` ^4 and adds a VAT/OSS layer (`mpociot/vat-calculator` plus VIES), a wallet layer for metered billing (`bavix/laravel-wallet`), a coupon engine, a built-in first-checkout wizard, an admin panel and a Livewire 4 customer portal. Subscription lifecycle is split into single-purpose service classes per action (Start, Create, Activate, Change, Cancel, Resubscribe, EnableAddon, DisableAddon, SyncSeats) — the `HasBilling` trait delegates to them via the container, so apps customize behavior by rebinding services rather than subclassing models. Extension points are provided via facade callbacks (`createBillableUsing`, `beforeCheckoutUsing`, `afterCheckoutUsing`, `resolveBillableUsing`, etc.) and events.

Free or zero-price plans run as `SubscriptionSource::Local` without a Mollie subscription; paid plans are `SubscriptionSource::Mollie`. The trial flow seamlessly converts Local subscriptions to Mollie ones the moment a mandate is added.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

## Credits

- [graystackit](https://github.com/GraystackIT)
- [mollie/laravel-mollie](https://github.com/mollie/laravel-mollie)
- [mpociot/vat-calculator](https://github.com/mpociot/vat-calculator)
- [bavix/laravel-wallet](https://github.com/bavix/laravel-wallet)
- [livewire/flux](https://fluxui.dev)

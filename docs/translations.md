# Translations

This document describes the translation system in `laravel-mollie-billing`, including all available translation files, the resolution order for catalog entity names, and how to publish and override translations in your application.

## Namespace

All translations are registered under the `billing` namespace via `loadTranslationsFrom()` in the service provider. Reference them as `billing::file.key`:

```php
__('billing::portal.dashboard')
trans_choice('billing::portal.products.purchased_count', $count, ['count' => $count])
```

## Shipped languages

The package ships with two locales out of the box:

- `en` (English)
- `de` (German)

## Translation files

| File | Purpose |
|------|---------|
| `portal.php` | Billing portal UI: dashboard, plan change, invoices, usage history, addons, seats, products, payment method, return page, navigation, flash messages |
| `checkout.php` | Checkout flow: steps, billing address, plan selection, addon/seat configuration, order confirmation, coupon handling, VAT display |
| `notifications.php` | Email notification subjects and bodies: trial reminders, payment failures, invoice available, usage thresholds, overage billing, refunds, plan change failures |
| `emails.php` | Shared email partials: greeting, signature, action buttons |
| `errors.php` | User-facing error messages: country validation, coupons, usage limits, access grants, refunds |
| `enums.php` | Human-readable labels for all enums: `SubscriptionStatus`, `InvoiceStatus`, `InvoiceKind`, `SubscriptionSource`, `SubscriptionInterval`, `CouponType`, `DiscountType`, `RefundReasonCode`, `CountryMismatchStatus`, `PlanChangeMode`, `MollieSubscriptionStatus` |
| `countries.php` | EU-27 country names keyed by ISO 3166-1 alpha-2 code |
| `features.php` | Feature names and descriptions (keyed by feature key) |
| `plans.php` | Plan name and description overrides (keyed by plan code) |
| `addons.php` | Addon name and description overrides (keyed by addon code) |
| `products.php` | Product name and description overrides (keyed by product code) |
| `product_groups.php` | Product group name overrides (keyed by group key) |

## Catalog entity name resolution

Plans, addons, products, features, and product groups all support localized names and descriptions. The resolution follows a consistent priority chain:

### Plans

```
billing::plans.{code}.name        →  config name  →  null
billing::plans.{code}.description →  config description  →  null
```

Translation file structure:

```php
// resources/lang/de/vendor/billing/plans.php
return [
    'pro' => [
        'name' => 'Profi',
        'description' => 'Für wachsende Teams.',
    ],
];
```

### Addons

```
billing::addons.{code}.name        →  config name  →  null
billing::addons.{code}.description →  config description  →  null
```

Translation file structure:

```php
// resources/lang/de/vendor/billing/addons.php
return [
    'softdrinks' => [
        'name' => 'Softdrinks',
        'description' => 'Gratis Softdrinks inklusive.',
    ],
];
```

### Products

```
billing::products.{code}.name        →  config name  →  null
billing::products.{code}.description →  config description  →  null
```

Translation file structure:

```php
// resources/lang/de/vendor/billing/products.php
return [
    'token-pack-500' => [
        'name' => '500 Token-Paket',
        'description' => 'Lade dein Konto mit 500 Tokens auf.',
    ],
];
```

### Features

```
billing::features.{key}.name        →  config name  →  null
billing::features.{key}.description →  config description  →  null
```

### Product groups

```
billing::product_groups.{key}  →  config name  →  null
```

Product groups use a flat key (not nested `name`/`description`) since they only have a name. The sort order is always read from the config `product_groups.{key}.sort` and is not translatable.

Translation file structure:

```php
// resources/lang/de/vendor/billing/product_groups.php
return [
    'top-ups' => 'Aufladungen',
    'services' => 'Dienstleistungen',
];
```

## Resolution summary

| Entity | Name fallback chain | Description fallback chain |
|--------|---------------------|---------------------------|
| Plan | translation &rarr; config &rarr; `null` | translation &rarr; config &rarr; `null` |
| Addon | translation &rarr; config &rarr; `null` | translation &rarr; config &rarr; `null` |
| Product | translation &rarr; config &rarr; `null` | translation &rarr; config &rarr; `null` |
| Feature | translation &rarr; config &rarr; `null` | translation &rarr; config &rarr; `null` |
| Product group | translation &rarr; config &rarr; `null` | n/a |

## Publishing translations

To override or extend translations in your application, publish them with:

```bash
php artisan vendor:publish --tag=billing-lang
```

This copies all translation files to `resources/lang/vendor/billing/{locale}/`. Laravel resolves vendor translations first, so your overrides take precedence over the package defaults.

To add a new locale (e.g. French), create the directory and translation files:

```
resources/lang/vendor/billing/fr/
    portal.php
    checkout.php
    notifications.php
    emails.php
    errors.php
    enums.php
    countries.php
    features.php
    plans.php
    addons.php
    products.php
    product_groups.php
```

Only include files and keys you want to override or add. Missing keys fall through to the package defaults.

## Single-language apps

If your application only uses one language and you don't need translation files at all, you can define names directly in the config:

```php
// config/mollie-billing-plans.php
'plans' => [
    'pro' => [
        'name' => 'Professional',
        'description' => 'For growing teams.',
        // ...
    ],
],

'product_groups' => [
    'top-ups' => ['name' => 'Top-Ups', 'sort' => 1],
],
```

The config values serve as the second-priority fallback and work without any translation files.

## Placeholder reference

Many translations use Laravel's `:placeholder` syntax. Common placeholders:

| Placeholder | Used in | Value |
|-------------|---------|-------|
| `:app` | portal, checkout, notifications | `config('app.name')` |
| `:name` | emails, portal | Billable name |
| `:plan` | portal, notifications | Plan name |
| `:date` | portal, notifications | Formatted date |
| `:amount` | notifications | Formatted currency amount |
| `:price` | checkout, portal | Formatted price |
| `:count` | portal | Numeric count |
| `:rate` | checkout, portal | VAT rate percentage |
| `:country` | checkout, errors | Country name |
| `:code` | checkout, errors | Coupon code |
| `:type` | portal, notifications | Usage type name |
| `:percent` | checkout, notifications | Percentage value |
| `:interval` | portal, checkout | `monthly` / `yearly` |
| `:product` | portal | Product name |
| `:currency` | checkout | Currency symbol |

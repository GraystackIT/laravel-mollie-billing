# VAT and VAT-Number Handling

This document explains how the package calculates VAT, validates VAT numbers (UID), reconciles country evidence for OSS audit trails, and exports OSS protocol data. Read this when wiring up a new tenant, debugging a VAT calculation, or preparing for an audit.

## Components at a glance

| Concern | Class / File |
|---|---|
| VAT calculation (rate, amount, reverse-charge) | [src/Services/Vat/VatCalculationService.php](../src/Services/Vat/VatCalculationService.php) |
| Live VAT-number validation in Livewire components | [src/Concerns/ValidatesVatNumber.php](../src/Concerns/ValidatesVatNumber.php) |
| Country reconciliation (audit trail) | [src/Services/Vat/CountryMatchService.php](../src/Services/Vat/CountryMatchService.php) |
| OSS quarterly CSV export | [src/Services/Vat/OssProtocolService.php](../src/Services/Vat/OssProtocolService.php) |
| Audit-trail table | `billing_country_mismatches` |
| IP-based country pre-fill (UX only) | [src/IpGeolocation/IpGeolocationManager.php](../src/IpGeolocation/IpGeolocationManager.php) |

## VAT calculation

`VatCalculationService::calculate(string $country, int $netAmount, ?string $vatNumber = null)` is the single entry point used by all pricing code (subscription, addons, overage, one-time charges).

Resolution order:

1. **Reverse-charge** — when a non-empty `$vatNumber` is passed and the destination is an EU country, the service runs the VIES live check. If valid → VAT = 0, gross = net, rate = 0. Returns immediately.
2. **VAT rate lookup** — `vatRateFor($country)` resolves in this order:
    - `config('mollie-billing.vat_rate_overrides.{COUNTRY}')`
    - `config('mollie-billing.additional_countries.{COUNTRY}.vat_rate')`
    - `mpociot/vat-calculator`'s built-in rate (decimal → multiplied by 100)
3. **Calculation** — `vat = round(net * rate / 100)`, `gross = net + vat`.

For non-EU countries that are also not declared in `additional_countries`, `calculate()` throws `NonEuCountryException`. Apps that sell outside the EU must declare the country in `additional_countries` (with its rate) or override pricing upstream.

> **Important:** the country passed in is always the **customer's country** (the destination of the supply), not the seller's. This is what OSS requires: a German private customer paying an Austrian seller pays 19% German VAT, which the seller declares via OSS to the German tax authority.

## VAT-number live validation

The `ValidatesVatNumber` trait powers the live UID check in both checkout and billing-data Livewire components. Public state required on the consuming component:

```php
public ?string $vat_number;
public string  $billing_country;
public ?bool   $vatNumberValid;
public ?string $vatStatusMessage;
```

The `validateVatNumberLive(VatCalculationService $vat, string $field = 'vat_number')` method runs three checks in order:

1. **Format** — must match `/^[A-Z]{2}[A-Z0-9]{2,12}$/` (uppercase, country prefix, alphanumeric body).
2. **Country prefix** — first two chars must equal `strtoupper($billing_country)`. This is **the user-vs-UID match** the package enforces at checkout.
3. **VIES** — `mpociot/vat-calculator`'s `isValidVATNumber()`. On `VATCheckUnavailableException` (VIES down) the field is left as "unknown" (`vatNumberValid = null`), the form does not block.

The trait is wired up via `wire:model.live.debounce.500ms="vat_number"` and via the `updatedBillingCountry` Livewire hook, so editing either field re-runs the validation immediately.

## Reverse-charge logic

Reverse-charge applies when **all three** are true:

- `vat_number` is filled and VIES-valid (`vatNumberValid === true`),
- `billing_country` is in the EU-27 list,
- the seller's tax country (i.e. the country running this app) is also in the EU.

When all three conditions hold, `VatCalculationService::calculate()` returns gross = net, rate = 0, and the invoice line shows the customer's VAT number with a reverse-charge note. The invoice currency and the VAT-rate-source mechanics still flow through the same code path.

## Country reconciliation (audit trail)

The package compares two country sources to detect discrepancies that may indicate VAT fraud or misdeclaration:

- `tax_country_user` — what the customer entered in the form (mirrored from `billing_country` on save).
- `tax_country_payment` — what Mollie returns as `payment.countryCode` (BIN-derived for cards, IBAN-derived for SEPA, fixed-per-method for iDEAL/Bancontact). This is **independent of user input**.

`CountryMatchService::check(Billable $billable)` runs:

- After the **first payment** (in `MollieWebhookController::persistFirstPaymentArtifacts()`), and
- After **every recurring payment** (in `MollieWebhookController::handleSubscriptionPaymentPaid()`).

The recurring path also refreshes `tax_country_payment` from the latest payment, so a customer switching to a card from another country triggers a fresh check.

When the two sources are present and differ, a row is inserted into `billing_country_mismatches` with status `Pending`. The check is **idempotent** — repeated calls with the same `(tax_country_user, tax_country_payment)` for the same billable do not create duplicate rows (any `Pending` or `Resolved` row with the same tuple blocks a new insert).

A mismatch is informational, not blocking. The customer's checkout and renewals continue to work. An admin notification is dispatched (`CountryMismatchNotification`) and the row is resolvable from the admin portal.

> **Note:** A mismatch is a mismatch — there is no priority field. Admins can use the billable's VAT-number column to triage: a row with a VIES-valid VAT number is likely B2B reverse-charge (where the payment country is OSS-secondary anyway), and rows without a VAT number deserve closer review.

### Resolution

When an admin clicks "Resolve" in the admin portal, `CountryMatchService::resolve()`:

1. Marks the row `Resolved` with `resolved_at` and `resolved_by_user_id`.
2. Compares the corrected `tax_country_user` rate against the original invoice's plan-line rate.
3. If they differ by more than 0.001%, **automatically issues a credit note** for the original invoice's net amount. Apps can then re-issue an invoice with the correct VAT.

This is the only place in the package where a country reconciliation event triggers a financial document.

## OSS quarterly export

`OssProtocolService::export(int $year)` generates a CSV file aggregating all invoices in the given calendar year by `(quarter, country, vat_rate)`:

```
quarter, country, sales_count, net_amount_eur, vat_amount_eur, vat_rate
```

Quarter boundaries are computed in **UTC** so the export is deterministic regardless of `app.timezone`. Multi-VAT invoices contribute one bucket entry per distinct VAT rate.

Run via:

```bash
php artisan billing:oss-export 2026
```

The output path is returned by the artisan command and printed to stdout.

## IP-based country pre-fill (UX only)

`IpGeolocationManager::defaultCountryFor(?string $ip): string` is used by both checkout and billing-data Livewire components to pre-select a country in the dropdown when no billable country is yet persisted. It is:

- Cached per IP for 24 h.
- Skipped for empty / private / loopback IPs.
- Validated against the configured `checkout_countries` allowlist.
- Always falls back to `default_billing_country` (config) when the lookup is inconclusive.

The IP country is **never persisted** on the billable. It is purely a UX hint — what the user submits is what counts.

## Live payment-country warning in billing-data

When a billable already has a `tax_country_payment` (i.e. has paid at least once) and the user changes the country dropdown to something different, the billing-data form shows an inline amber callout immediately:

> Heads up: your payment method comes from `XX`, but you're selecting `YY`. This will be recorded in our audit trail at the next renewal.

The save still goes through. The actual `billing_country_mismatches` row is created at the next recurring webhook.

## Configuration touchpoints

| Key | Purpose |
|---|---|
| `default_billing_country` | Fallback for the country dropdown when no IP country is resolved. |
| `checkout_countries` | Allowlist for the dropdown; drives which IP-resolved countries are accepted. |
| `vat_rate_overrides` | Per-country rate override (highest precedence). |
| `additional_countries` | Non-EU countries with their own rate; auto-included in `checkout_countries`. |
| `ip_geolocation` | Driver and credentials for the IP lookup (UX-only). |

See [configuration.md](configuration.md) for details.

## Operational notes

- **VIES outage handling.** `validateVatNumber()` throws `ViesUnavailableException` when VIES is unreachable. `ValidatesVatNumber::validateVatNumberLive()` treats this as "unknown" and shows a status message; the form does not block. The actual `VatCalculationService::calculate()` re-runs the VIES check at invoice time — if it still fails, reverse-charge is *not* applied (gross VAT is collected) and the customer can dispute via the resolution flow.
- **Webhook idempotency.** `MollieWebhookController` is deduped via `BillingProcessedWebhook`. A single Mollie payment cannot trigger two reconciliation rows even if Mollie retries the webhook.
- **Signed webhooks.** Mollie does not sign legacy webhooks. The `BillingProcessedWebhook` dedup table plus the round-trip `GetPaymentRequest` against the Mollie API (which fails for spoofed IDs) are the integrity guarantees. See [CLAUDE.md](../CLAUDE.md).

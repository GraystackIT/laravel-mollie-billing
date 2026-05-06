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

## Country reconciliation (three-way check)

The package compares **three** country sources to detect billable / payment / IP divergence:

- `tax_country_user` — what the customer entered in the form (mirrored from `billing_country` on save).
- `tax_country_payment` — what Mollie returns as `payment.countryCode` (BIN-derived for cards, IBAN-derived for SEPA, fixed-per-method for iDEAL/Bancontact). Independent of user input.
- `tax_country_ip` — what `IpGeolocationManager::getCountry($ip)` returns at the moment the user submits the checkout form. Captured once at billable creation and again on the final submit (same checkout request), persisted on the billable.

### B2B short-circuit

If the billable has a non-empty `vat_number`, the country-mismatch flow is **skipped entirely**. VIES (validated up-front in `ValidatesVatNumber::validateVatNumberLive()`) is the authoritative signal under reverse-charge — the bank or card country is fiscally irrelevant. The form-level VIES gate ensures that a non-empty `vat_number` is always either VIES-valid for the matching country prefix or a save error.

### B2C three-way logic

`CountryMatchService::check(Billable $billable)` runs after every payment (first-payment, recurring, local→Mollie upgrade — same call sites as before). For B2C:

- If `tax_country_user` is null or no payment / IP signal is yet known → no flag.
- If `tax_country_user` matches **at least one** of `tax_country_payment` or `tax_country_ip` → no flag.
- Otherwise → flag.

The flag is idempotent: only one Pending row per billable. Repeated `check()` calls while the row exists are no-ops. They do not re-send the customer notification and do not re-cancel the subscription.

### What `flag()` does

When a mismatch is flagged, the service performs all of these atomically:

1. Insert a `BillingCountryMismatch` row with `status=Pending` and the three country values.
2. Mark every existing positive-net invoice of the billable (any `invoice_kind` except `Refund`) with `mismatch_id = $mismatch->id` so the resolve flow knows exactly which invoices to refund and reissue.
3. Set `country_mismatch_flagged_at` on the billable for downstream filtering.
4. Cancel the subscription at end of period via `CancelSubscription::handle($billable, immediately: false)`. The current paid period stays active; no further Mollie renewal will fire.
5. Dispatch the `CountryMismatchFlagged` event.
6. Email the **billable** (not system admins) via `CountryMismatchSelfNotification`. Recipients are resolved through `MollieBilling::notifyBillingAdmins($billable)`; if that callback returns nothing, the package falls back to a single on-demand mail to `$billable->getBillingEmail()`.

A `RequireResolvedCountryMismatch` middleware (alias `billing.country-resolved`) is mounted on the booking-relevant portal routes (`plan`, `addons`, `seats`, `products`, `usage`) and on the checkout route. While a Pending mismatch exists for the resolved billable, those routes redirect to the billing dashboard. The dashboard, billing-data, invoices, and return routes stay reachable so the user can correct the address and view history.

### Self-service resolve (dashboard modal)

The dashboard surfaces three banner states:

1. **Pending mismatch** — danger callout listing the three country values plus a `Correct address` CTA that opens the self-service modal.
2. **Resolved but subscription still cancelled** — warning callout with a `Reactivate subscription` button (within the subscription end-date grace period) or a `Choose a plan` button (after grace period).
3. **Re-charge in progress** — info callout shown while `subscription_meta.country_corrections` has pending entries (waiting for Mollie's `country_correction` webhook).

The self-service modal asks the user for the correct invoice address and a country chosen from the **payment- or IP-derived options only**. The country dropdown does not allow free-form input. The user must tick a confirmation checkbox before submit (refund + reissue is acknowledged). The modal explicitly disables saving to a country that doesn't match either signal — there is no escape hatch on this UI; if neither signal is correct, the user must contact support.

The address-edit page (`billing-data.blade.php`) disables the country dropdown while a Pending mismatch exists and surfaces a hint banner pointing back to the dashboard. The `save()` action is a defence-in-depth gate: even direct wire calls cannot change the country while the mismatch is open.

### Resolve flow (`CountryMatchService::resolve()`)

Triggered from the user modal or the admin override. Steps, in order:

1. **Refund every linked invoice** (filter: `mismatch_id = $mismatch->id` AND `invoice_kind != Refund` AND `amount_net > 0` AND not already fully refunded). Each refund goes through `RefundInvoiceService::refundFully()` with `RefundReasonCode::BillingError`, which creates a local credit note and calls Mollie's refund API. The credit-note serial is captured per original invoice for the reissue PDF.
2. **Update the billable** in a DB transaction: `tax_country_user = billing_country = $newCountry`, plus the mismatch row gets `chosen_country`, `status=Resolved`, `resolved_at`, and `resolved_by_user_id`.
3. **Issue a Mollie correction charge** per refunded invoice via `InvoiceService::issueCorrectionCharge()` with metadata `type=country_correction`. The customer-facing reissue invoice is created when the `country_correction` payment confirms (in `MollieWebhookController::handleCountryCorrectionPaid()`, which preserves the original invoice's `period_start` / `period_end` for OSS-correct accounting).
4. Dispatch `CountryMismatchResolved`.

**Idempotency.** If a re-charge fails (e.g. mandate revoked) the webhook handler `handleCountryCorrectionFailed` rolls the mismatch back to Pending, drops the failed payment from `subscription_meta.country_corrections`, and notifies the billable's admins via `CountryMismatchReissueFailedNotification`. The user can retry the resolve modal: refunds with an existing credit note are skipped (`refunded_net >= amount_net`), and re-charges with a still-pending entry in `subscription_meta.country_corrections` are skipped (no double charge). Only the missing pieces are retried.

**No auto-reactivate.** Mollie subscriptions cannot be un-cancelled — `ResubscribeSubscription` creates a new Mollie subscription via `CreateSubscription`, which can trigger an immediate charge at the next renewal day. The package therefore never auto-resumes after a resolve. The user clicks the explicit `Reactivate subscription` button on the dashboard.

### Admin override

The admin portal's mismatch list still has Pending and Resolved tabs. The Pending resolve modal lets an admin pick the user-, payment-, or IP-derived country, **or** enter any ISO-2 code freely (admins know the context). The chosen country goes through the same `CountryMatchService::resolve()` path as the user modal. `resolved_by_user_id` records the operator.

### Edge cases

- **Local-subscription invoices** (no `mollie_payment_id`) still get a local credit note (`RefundInvoiceService` is no-op for the Mollie call) but the reissue charge in step 3 will fail (no mandate) — these billables stay flagged and surface a manual follow-up via `handleCountryCorrectionFailed`. In practice, free-tier billables almost never produce paid invoices, so this is a rare edge case.
- **VIES outage during B2B save** — `validateVatNumberLive` throws `ViesUnavailableException` and the form blocks. Status quo, intentional: we never persist a `vat_number` we couldn't verify, otherwise reverse-charge silently stops applying when the audit row is missing.
- **VIES says invalid** — same hard block: form refuses save until the user fixes or clears the UID. Clearing the UID drops the billable into the B2C flow.
- **User fixes UID after a B2C mismatch was flagged** — entering a valid UID does not auto-clear the existing mismatch; past invoices were issued without reverse-charge and need the explicit refund + reissue. The user runs the resolve modal first, then enters the UID.
- **Mandate from a different country than the chosen one** (e.g. SEPA DE-IBAN paying for an AT-resident customer) — the resolve corrects the past and reactivates the sub on the new country, but the next Mollie recurring payment will still report `payment.countryCode = DE`. With IP also pointing at AT, that's still an `AT vs DE+AT` match (one signal agrees) — no new flag. With IP at CH, the cycle starts again.

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

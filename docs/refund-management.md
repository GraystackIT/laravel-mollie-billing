# Refund Management

This document describes how refunds and credit notes are issued, tracked, and synced with Mollie.

## Architecture

| Component | Responsibility |
|-----------|----------------|
| `RefundInvoiceService` | Primary entry point for programmatic refunds |
| `InvoiceService` | Creates credit note invoices (linked and standalone) |
| `UpdateSubscription` | Issues prorata refunds on plan downgrades |
| `MollieWebhookController` | Syncs refunds initiated via the Mollie dashboard |
| `BillingInvoice` | Tracks cumulative refunds via `refunded_net` |

## Refund Flow

Every refund follows the same core pattern:

1. **Validate** -- invoice must be `Paid` with a `mollie_payment_id`
2. **Call Mollie** -- `CreatePaymentRefundRequest` with gross amount
3. **Create credit note** -- negative-amount `BillingInvoice` with `InvoiceKind::CreditNote`
4. **Update tracking** -- increment `refunded_net` on the original invoice
5. **Dispatch events** -- `InvoiceRefunded`, `CreditNoteIssued`
6. **Notify** -- `RefundProcessedNotification` to billable admins

## RefundInvoiceService

The service is available via the facade:

```php
use GraystackIT\MollieBilling\Facades\MollieBilling;

$refundService = MollieBilling::refunds();
```

### Methods

#### `refundFully($invoice, $reason, $reasonText)`

Refund the full remaining amount. For overage invoices, wallet units are automatically credited back based on the line items.

```php
$refundService->refundFully($invoice, RefundReasonCode::BillingError);
```

#### `refundPartially($invoice, $amountNet, $reason, $reasonText, $lineItems)`

Refund a specific net amount (in cents). Works for any invoice type.

```php
$refundService->refundPartially($invoice, 500, RefundReasonCode::Goodwill, 'customer request');
```

#### `refundOverageUnits($invoice, $usageType, $units, $reason, $reasonText)`

Refund specific overage units. The refund amount is computed from the unit price on the invoice. Wallet units are credited back automatically.

```php
$refundService->refundOverageUnits($invoice, 'Tokens', 1000, RefundReasonCode::Goodwill);
```

#### `refundOverageUnitsBulk($invoice, $unitsPerType, $reason, $reasonText)`

Refund overage units across multiple usage types in a single call.

```php
$refundService->refundOverageUnitsBulk($invoice, [
    'Tokens' => 500,
    'SMS' => 10,
], RefundReasonCode::ServiceOutage);
```

#### `refund($invoice, $request)` (low-level)

The core method that all others delegate to. Accepts a request array:

```php
$refundService->refund($invoice, [
    'amount_net' => 500,            // null = full refund
    'wallet_credits' => ['Tokens' => 100],
    'reason_code' => RefundReasonCode::Goodwill,
    'reason_text' => 'optional explanation',
    'notify_user' => true,
    'line_items' => null,           // custom credit note line items
]);
```

### Wallet-only credits

For goodwill credits that don't involve a Mollie refund or credit note, use `WalletUsageService` directly:

```php
app(WalletUsageService::class)->credit($billable, 'Tokens', 500, 'goodwill bonus');
```

This fires a `WalletCredited` event and does not touch Mollie.

## Credit Notes

### Two types

| Type | Created by | `parent_invoice_id` | Use case |
|------|-----------|---------------------|----------|
| **Linked** | `InvoiceService::createCreditNote()` | Set to original invoice | Standard refunds |
| **Standalone** | `InvoiceService::createStandaloneCreditNote()` | `null` | Prorata downgrade refunds |

### Key properties

- **Negative amounts** -- `amount_net`, `amount_vat`, `amount_gross` are all negative
- **VAT rate** -- copied from the original invoice (never re-calculated)
- **Status** -- always `InvoiceStatus::Refunded`
- **Kind** -- always `InvoiceKind::CreditNote`
- **Serial number** -- prefixed with `CR` (via `InvoiceNumberGenerator`)
- **PDF** -- generated and stored on the configured disk

### Mollie payment ID format

Credit notes use a synthetic `mollie_payment_id` to maintain uniqueness:

| Source | Format |
|--------|--------|
| Standard refund | `{original_payment_id}:cn:{uniqid}` |
| Standalone (prorata) | `{payment_id}:cn:{uniqid}` or `cn:{uniqid}` |
| Webhook sync | `{payment_id}:re:{mollie_refund_id}` |

## Refund Reason Codes

| Code | Label | Color |
|------|-------|-------|
| `ServiceOutage` | Service outage | Red |
| `BillingError` | Billing error | Amber |
| `Goodwill` | Goodwill | Blue |
| `Chargeback` | Chargeback | Red |
| `Cancellation` | Cancellation | Zinc |
| `PlanDowngrade` | Plan downgrade | Zinc |
| `Other` | Other (requires `reason_text`) | Zinc |

## Cumulative Refund Tracking

The original invoice tracks all refunds via `refunded_net`:

```php
$invoice->refunded_net;            // total refunded so far (cents)
$invoice->remainingRefundableNet(); // amount still refundable
$invoice->isFullyRefunded();       // true when fully refunded
```

Multiple partial refunds are supported. `RefundExceedsInvoiceAmountException` is thrown if a refund exceeds the remaining refundable amount.

## Prorata Refunds (Plan Downgrades)

When a user downgrades their plan mid-period, `UpdateSubscription::refundProrataCredit()` handles the refund:

1. Finds the most recent paid invoice for the current subscription via `findSubscriptionPaymentId()` (local DB lookup, no Mollie API call)
2. Calculates VAT using the billable's country
3. Calls Mollie refund against that payment
4. Creates a standalone credit note with `RefundReasonCode::PlanDowngrade`
5. Handles 409 conflicts (duplicate refunds) by checking for existing credit notes

## Webhook Sync (Mollie Dashboard Refunds)

When refunds are initiated directly in the Mollie dashboard, `MollieWebhookController::handleRefundWebhook()` syncs them:

- Runs **after** the normal paid-flow (does not block subscription payment processing)
- Deduplicates by Mollie refund ID (`re_xxx`), not by amount -- safe for multiple partial refunds of the same value
- Converts gross to net using the original invoice's VAT rate
- Sets reason to `RefundReasonCode::Other` with text `'synced from Mollie dashboard'`
- Dispatches `InvoiceRefunded` event

## Mollie API Description

The refund description sent to Mollie includes the reason code label and optional text:

```
Refund: Goodwill -- customer request
Refund: Service outage
Pro-rata credit: Pro Plan -> Basic Plan
```

This makes refunds identifiable in the Mollie dashboard.

## Idempotency

| Flow | Protection |
|------|------------|
| `RefundInvoiceService::refund()` | `lockForUpdate` + `remainingRefundableNet()` check |
| `refundProrataCredit()` | 409 error handling + credit note existence check |
| Webhook sync | Deduplication by Mollie refund ID in `mollie_payment_id` |

## Events

| Event | When | Payload |
|-------|------|---------|
| `CreditNoteIssued` | Credit note created | `$billable`, `$creditNote`, `$originalInvoice` (nullable) |
| `InvoiceRefunded` | Refund completed | `$billable`, `$originalInvoice`, `$creditNote`, `$request` |
| `WalletCredited` | Wallet units credited back | `$billable`, `$usageType`, `$units`, `$reason` |

## Notifications

| Notification | Recipient | When |
|-------------|-----------|------|
| `RefundProcessedNotification` | Billable admins | Refund succeeds (unless `notify_user = false`) |
| `AdminRefundFailedNotification` | System admin | Mollie refund API call fails |

## Admin UI

The admin panel includes a refund modal at `/billing/admin` that calls `RefundInvoiceService::refund()` directly. It supports:

- Full or partial refunds by invoice ID
- All reason codes with optional text
- Optional user notification toggle

## Exceptions

| Exception | When |
|-----------|------|
| `InvalidRefundTargetException` | Invoice not paid, no Mollie payment ID, or wrong invoice type for method |
| `RefundExceedsInvoiceAmountException` | Refund amount exceeds remaining refundable balance |
| `InvalidArgumentException` | Missing `reason_code`, or `reason_text` required for `Other` |

## Artisan Command

```bash
php artisan billing:sync-purchased-balance
```

Backfills `purchased_balance` on all wallets from transaction history. Run once after upgrading to a version that tracks purchased credits separately.

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
3. **Create credit note** -- negative-amount `BillingInvoice` with `InvoiceKind::Refund`
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

Both unit helpers require every count to be a positive integer and no larger than the units the invoice billed for that type, minus what earlier refunds already credited back; anything else throws `InvalidArgumentException` before Mollie is called. The money cap alone could not catch this: a negative entry for one type used to offset the price of a positive one on another, while the wallet credited only the positive side.

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
| **Prorata** | `InvoiceService::createRefund()` | Set per line, on the invoice each line reverses | Prorata downgrade refunds |

`createRefund()` takes `ProrataLine` objects rather than an amount: each line already
knows which original invoice and which of its line items it reverses, so one call can
refund across several past invoices at once and each credit line carries its own
`parent_invoice_id`. Both entry points are on `InvoiceService`; there is no separate
standalone-credit-note API.

### Key properties

- **Negative amounts** -- `amount_net`, `amount_vat`, `amount_gross` are all negative
- **VAT rate** -- copied from the original invoice (never re-calculated)
- **Status** -- always `InvoiceStatus::Refunded`
- **Kind** -- always `InvoiceKind::Refund` (the enum has no `CreditNote` case; a credit note *is* a refund invoice)
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

When a user downgrades their plan mid-period, the refund is composed and executed by
`ProrataComposer` → `ProrataExecutor` → `InvoiceService::createRefund()`:

1. `ProrataComposer` builds one refund line per original line item, resolved through `BillingInvoice::currentPeriodLines()` — so each credit is posted against the invoice and the payment that actually holds the money, at that line's own VAT rate.
2. Each line is capped against the original invoice's remaining refundable amount (a `refund_cap_note` records the reduction).
3. `InvoiceService::createRefund()` calls Mollie per line with a per-line idempotency token that survives a retry, then writes **one** credit note covering the batch and increments `refunded_net`.
4. Lines Mollie rejected go to `subscription_meta.pending_refund_retries`, which `RetryRefundLineJob` works through.

`createRefund()` holds the per-invoice refund lock (`billing:invoice-refund:{id}`, keyed on the invoice that holds the payment) from a line's Mollie call until the credit note is committed — the same lock `RefundInvoiceService` and the refund webhook use. Mollie calls the payment webhook as soon as the first refund exists, while the credit note carrying the refund ids is only written after the last line; without the lock the webhook booked that refund as "synced from Mollie dashboard" and the credit note then booked it again. A line whose invoice is being refunded by somebody else is not posted at all: it goes to `pending_refund_retries`.

The credit note inherits country, currency **and VAT validation** from the invoice it credits, not from the billable's state on the day of the refund.

## Webhook Sync (Mollie Dashboard Refunds)

When refunds are initiated directly in the Mollie dashboard, `MollieWebhookController::handleRefundWebhook()` syncs them:

- Runs **after** the normal paid-flow (does not block subscription payment processing)
- Deduplicates by Mollie refund ID (`re_xxx`), not by amount -- safe for multiple partial refunds of the same value
- Converts gross to net using the original invoice's VAT rate
- Sets reason to `RefundReasonCode::Other` with text `'synced from Mollie dashboard'`
- Dispatches `InvoiceRefunded` event
- Books a refund in any status that can still pay out (`queued`, `pending`, `processing`, `refunded`) and **skips** one Mollie reports as `failed` or `canceled`

### When a booked refund does not pay out

A refund is booked when it is created — by the admin refund, by a plan change, or by this sync — but Mollie can still report it as `failed` (the bank bounced it) or `canceled` afterwards, and the funds return to the merchant. The next delivery for that payment then **reverses** the booking:

- The credit note is not deleted. `InvoiceService::reverseBookedCredit()` writes a reversal document (a `Refund`-kind row with its own `CR-` serial, positive amounts, status `paid`) whose lines mirror the credited lines and carry `reverses_credit: true`.
- `refunded_net` on the original drops by the reversed amount, and `derivedRefundedNet()`, `creditedPerOriginalLine()`, `creditedVatTotal()` and `currentPeriodLines()` subtract reversal lines, so the invoice is refundable again and the refund can be retried.
- `RefundReversed` is dispatched and the admins receive `AdminRefundFailedNotification`. Wallet units the refund had handed back are **not** taken away automatically — they may have been consumed since.
- It is idempotent on the refund id, and it works even when the failed refund was the only one: `amountRefunded` is back at zero then, so the controller also syncs when a refund is still on the books, and it dedups against the *latest* processed outcome rather than any earlier one (the delivery is otherwise byte-identical to the original `paid`).

### When a refund has to be reduced

Mollie only knows the original payment's gross — it has no concept of the local net/VAT split — so a refund can never exceed what Mollie collected minus what has already been returned. When a composed line does not fit (typically because something else refunded part of the same invoice between a plan change's Phase 1 and its Phase 2, which for SEPA is days apart), the line is **rebuilt** at the reduced amount rather than only the API call being clamped: net resolved through the invoice's own line composition, VAT proportional to the line's rate, and a `refund_cap_note` on the line recording the uncapped and capped figures.

Clamping only the API call meant the credit note — a tax document — stated more than Mollie returned, `refunded_net` could exceed `amount_net`, and `creditedPerOriginalLine()` / `derivedRefundedNet()` were inflated for every later refund decision.

## Chargebacks

A chargeback is money the customer's bank pulls back after a payment has already settled — for SEPA Direct Debit an eight-week, no-questions right, so a routine event rather than an exotic one.

Mollie reports it on the **same payment**, and in the v2 payments API the status stays `paid`: only `amountChargedBack` and `_links.chargebacks` change. That is why the webhook's dedup signature carries the charged-back total as its own component (`…:paid:chargedback:2380`) — without it the re-delivery was byte-identical to the paid one already on record and was skipped, leaving the money gone while the invoice still claimed it: output VAT and the OSS return over-declared by that amount, no credit note, and the customer still subscribed.

`ChargebackHandler` mirrors the refund sync, because a chargeback *is* a refund the bank made on the customer's behalf:

- Deduplicated by Mollie chargeback ID (`chb_xxx`) — Mollie can report several against one payment, and each arrives as its own delivery
- Net resolved against the original invoice's line composition (`creditNetForGross()`), not one line's rate applied to the whole gross
- Reason `RefundReasonCode::Chargeback`, text `'chargeback reported by Mollie'`
- Dispatches `InvoiceRefunded`

Two behaviours are deliberate:

- **The subscription becomes `past_due`.** Money collected and taken back is a failed payment, so the existing ladder owns what follows — dunning, then the auto-cancel after `past_due_max_days`. An already-past-due billable keeps its original `past_due_since`: the clock belongs to the episode, and restarting it would extend the grace period every time the bank pulled more money back.
- **The Mollie subscription is *not* cancelled here.** That decision belongs to the past-due ladder and to the operator. A chargeback on one period is not necessarily the end of the relationship, and cancelling is irreversible — a Mollie subscription can only be recreated, never resumed.

A chargeback on a `cancelled` or `expired` subscription still books its credit note (the money did leave) but does not change the status: forcing `past_due` there would feed an ended subscription back into the auto-cancel ladder.

### When a chargeback is reversed

A chargeback can be disputed and won; Mollie states that on the chargeback itself (`reversedAt`), and the money comes back. `ChargebackHandler` then takes the booking out again, the same way a failed refund is un-booked:

- A chargeback that is already reversed when it is first seen is not booked at all.
- One that was booked gets a reversal document (`InvoiceService::reverseBookedCredit()`, keyed on `mollie_chargeback_id`); the invoice is refundable in full again.
- The subscription returns to `active` **only if** this chargeback is what it is past due for — `subscription_meta.payment_failure` still names it. If a renewal has failed since, the dunning episode has another owner and the status is left alone.
- `ChargebackReversed` is dispatched and the admins are notified.

Not measured against Mollie (the sandbox cannot produce a chargeback): whether `amountChargedBack` changes on a reversal and whether the payment webhook is called for it. The handler therefore reads only `reversedAt`, and the controller looks at a payment's refunds and chargebacks again on every delivery for as long as one of them is still on the books, whatever the totals say.

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
| `RefundInvoiceService::refund()` | Cache lock per invoice (`billing:invoice-refund:{id}`, `RefundInProgressException` for the loser) + `lockForUpdate` and a `remainingRefundableNet()` check inside the short decide/write transactions. The Mollie call and the PDF render sit between them, with nothing held — a transaction across them could be rolled back after the money had moved, and the retry then refunded again. |
| `InvoiceService::createRefund()` | Per-line idempotency token (stable across retries) + clamp against `effectiveRefundedNet()` |
| Any Mollie call with a stable key | `MollieCall::send($request, $key)` sets it on the shared client and clears it in a `finally`. The SDK clears the key from a *response* middleware, which a throw never reaches — so a failed call used to leave its key behind and the next mutating request carried it, getting this operation's cached response or a 400 for reusing a key with different parameters. Mollie caches a key for **one hour**. |
| Webhook sync | Deduplication by Mollie refund ID in `mollie_payment_id` |
| Chargeback sync | Deduplication by Mollie chargeback ID in the credit note's `line_items` |

Both dedup keys are stamped onto the credit note's lines **before the row is committed** (`createCreditNote(..., externalReference: …)`). They used to be written in a second save, after the row existed and after the synchronous `CreditNoteIssued` event had run — a throwing app listener or a DB blip in that window produced a 500, and the re-delivery found no stamp and wrote a second credit note for the same refund.

## Events

| Event | When | Payload |
|-------|------|---------|
| `CreditNoteIssued` | Credit note created | `$billable`, `$creditNote`, `$originalInvoice` (nullable) |
| `InvoiceRefunded` | Refund completed | `$billable`, `$originalInvoice`, `$creditNote`, `$request` |
| `ChargebackReversed` | A booked chargeback was reversed and its credit note taken out of the books | `$billable`, `$original`, `$reversal`, `$mollieChargebackId` |
| `RefundReversed` | A booked refund was reported `failed`/`canceled` and its credit note reversed | `$billable`, `$reversal`, `$mollieRefundId`, `$status` |
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

The same invoice list also exposes a **Regenerate PDF** action per row. It calls `InvoiceService::regeneratePdf($invoice)`, which deletes the previous file from the configured disk before re-rendering with the current template / seller config. Invoice data, serial number and amounts are untouched — this is purely a re-render of the PDF blob. Useful when the original render failed silently, the stored file was corrupted, or the seller details on the PDF need to reflect updated config without re-issuing the invoice itself. The action dispatches `InvoicePdfRegenerated` (`billable`, `invoice`) for auditing.

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

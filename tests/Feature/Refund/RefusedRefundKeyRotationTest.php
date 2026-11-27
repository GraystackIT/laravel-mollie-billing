<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\KeyedMollieWorld;
use Illuminate\Support\Facades\Storage;

/**
 * A refund Mollie refused can be retried at once, once the reason has gone.
 *
 * Measured on 26 November 2026: a 4xx binds the idempotency key to that refusal for an hour,
 * so the same request with the same key is refused again even after the balance was topped
 * up. The rule since: after a *definite* refusal the next attempt carries a new key (the
 * stable key plus the number of refusals); after an outage or a lost answer the key stays,
 * because Mollie may have acted and only that key lets it say so.
 *
 * Two call sites: the admin refund (`RefundInvoiceService`, key `invoice-refund:…`) and the
 * refund line (`InvoiceService::createRefund()` and `RetryRefundLineJob`, key `refund:…`,
 * also the path of `UnwantedPaymentRefund` and `GrantExtrasPurchaseRefund`).
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function refusedKeyBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Refused Key Ltd',
        'email' => uniqid('refused-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function refusedKeyInvoice(TestBillable $billable, int $net = 10000): BillingInvoice
{
    $vat = (int) round($net * 0.2);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_refused_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => 20.0,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
    ])->refresh();
}

function refusedKeyLine(BillingInvoice $invoice, int $net): ProrataLine
{
    $line = new ProrataLine(
        originalInvoice: $invoice,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'pro',
        label: 'Pro',
        quantity: 1,
        amountNet: -$net,
        vatRate: 20.0,
        amountVat: -(int) round($net * 0.2),
        amountGross: -($net + (int) round($net * 0.2)),
        periodStart: BillingTime::nowUtc()->subDays(5),
        periodEnd: BillingTime::nowUtc()->addDays(25),
        daysActive: 5,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );
    // A token that is the same on every attempt — the unwanted-payment refund's shape.
    $line->refundToken = 'full-'.substr(sha1((string) $invoice->getKey()), 0, 16);

    return $line;
}

function refusedKeyAdminRefund(BillingInvoice $invoice, int $net): ?Throwable
{
    try {
        app(RefundInvoiceService::class)->refund($invoice->fresh(), [
            'amount_net' => $net,
            'reason_code' => RefundReasonCode::Goodwill,
            'notify_user' => false,
        ]);

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/** The retry the hourly sweep would dispatch for the one stored line, run now. */
function refusedKeyRetryLine(TestBillable $billable): void
{
    $entry = $billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0] ?? null;
    expect($entry)->not->toBeNull();

    (new RetryRefundLineJob($billable::class, $billable->getKey(), $entry['line'], (string) $entry['first_attempt_at']))
        ->handle(app(InvoiceService::class));
}

// ── The admin refund ──────────────────────────────────────────────────────────────────

it('retries an admin refund Mollie refused with a new key, at once, and refunds once', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    expect(refusedKeyAdminRefund($invoice, 4000))->not->toBeNull();

    // The balance is topped up; the operator clicks again right away.
    $mollie->refuseWith = null;
    expect(refusedKeyAdminRefund($invoice, 4000))->toBeNull();

    expect($mollie->refunds())->toHaveCount(1)
        ->and($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->not->toBe($mollie->sentKeys[0])
        ->and($mollie->sentKeys[1])->toStartWith((string) $mollie->sentKeys[0])
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('moves the admin refund key on with every refusal, not only the first', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    refusedKeyAdminRefund($invoice, 4000);
    refusedKeyAdminRefund($invoice, 4000);

    $mollie->refuseWith = null;
    expect(refusedKeyAdminRefund($invoice, 4000))->toBeNull();

    expect(array_unique($mollie->sentKeys))->toHaveCount(3)
        ->and($mollie->refunds())->toHaveCount(1);
});

it('keeps the admin refund key after an outage, and refunds once', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->outagesLeft = 1;
    expect(refusedKeyAdminRefund($invoice, 4000))->not->toBeNull();
    expect(refusedKeyAdminRefund($invoice, 4000))->toBeNull();

    expect($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->toBe($mollie->sentKeys[0])
        ->and($mollie->refunds())->toHaveCount(1);
});

it('keeps the admin refund key after a lost answer, so the retry is the refund Mollie made', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->loseNextAnswer = true;
    expect(refusedKeyAdminRefund($invoice, 4000))->not->toBeNull();
    expect(refusedKeyAdminRefund($invoice, 4000))->toBeNull();

    expect($mollie->sentKeys[1])->toBe($mollie->sentKeys[0])
        ->and($mollie->refunds())->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('does not move the admin refund key after a lost answer that follows a refusal', function (): void {
    // Refused, then sent under the new key — whose answer is lost. The retry must replay that
    // one, not count the lost answer as a refusal and pay out a second time.
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    refusedKeyAdminRefund($invoice, 4000);

    $mollie->refuseWith = null;
    $mollie->loseNextAnswer = true;
    expect(refusedKeyAdminRefund($invoice, 4000))->not->toBeNull();
    expect(refusedKeyAdminRefund($invoice, 4000))->toBeNull();

    expect($mollie->sentKeys[2])->toBe($mollie->sentKeys[1])
        ->and($mollie->refunds())->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

// ── The refund line ───────────────────────────────────────────────────────────────────

it('retries a refund line Mollie refused with a new key, at once, and refunds once', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    expect(app(InvoiceService::class)->createRefund($billable, [refusedKeyLine($invoice, 4000)], 'Payment not applied'))->toBeNull();

    $mollie->refuseWith = null;
    refusedKeyRetryLine($billable);

    expect($mollie->refunds())->toHaveCount(1)
        ->and($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->not->toBe($mollie->sentKeys[0])
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);

    // The token Mollie holds is the line's own, unchanged by the new key: it is what a
    // retry after a lost answer looks the refund up by.
    expect($mollie->refunds()[0]->metadata->refund_token)->toBe(refusedKeyLine($invoice, 4000)->refundToken);
});

it('moves the refund line key on across the queue ladder of one job, not only through the sweep', function (): void {
    // The queue retries a job with the payload it was dispatched with; the refusal count
    // lives on the stored entry, which the job has to read.
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    app(InvoiceService::class)->createRefund($billable, [refusedKeyLine($invoice, 4000)], 'Payment not applied');

    $entry = $billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0];
    $job = new RetryRefundLineJob($billable::class, $billable->getKey(), $entry['line'], (string) $entry['first_attempt_at']);

    try {
        $job->handle(app(InvoiceService::class));
    } catch (RuntimeException) {
        // Refused again: the queue backs off and runs the same job object again.
    }

    $mollie->refuseWith = null;
    $job->handle(app(InvoiceService::class));

    expect(array_unique($mollie->sentKeys))->toHaveCount(3)
        ->and($mollie->refunds())->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('keeps the refund line key after an outage, and refunds once', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->outagesLeft = 1;
    app(InvoiceService::class)->createRefund($billable, [refusedKeyLine($invoice, 4000)], 'Payment not applied');
    refusedKeyRetryLine($billable);

    expect($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->toBe($mollie->sentKeys[0])
        ->and($mollie->refunds())->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('does not refund a line twice after a lost answer that follows a refusal', function (): void {
    $billable = refusedKeyBillable();
    $invoice = refusedKeyInvoice($billable);
    $mollie = (new KeyedMollieWorld)->install();

    $mollie->refuseWith = 422;
    app(InvoiceService::class)->createRefund($billable, [refusedKeyLine($invoice, 4000)], 'Payment not applied');

    // The new key gets through — and its answer is lost.
    $mollie->refuseWith = null;
    $mollie->loseNextAnswer = true;

    try {
        refusedKeyRetryLine($billable);
    } catch (RuntimeException) {
        // The job rethrows for the queue's backoff; the line stays queued, outcome unknown.
    }

    refusedKeyRetryLine($billable);

    expect($mollie->refunds())->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);
});

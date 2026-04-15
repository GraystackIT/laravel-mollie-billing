<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\WalletCredited;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $service = $this->mock(RefundInvoiceService::class, function ($mock): void {
        // use real instance but stub Mollie refund call via property injection in subclass below
    });

    // Replace the real binding with a subclass that skips the real Mollie HTTP call.
    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(MollieSalesInvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService {
            protected function callMollieRefund(string $paymentId, int $grossCents): void
            {
                // no-op for tests
            }
        };
    });
});

function makeBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'test@example.com',
        'name' => 'Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function makePaidInvoice(TestBillable $billable, int $net = 1000, string $kind = 'subscription', ?array $lineItems = null): BillingInvoice
{
    $lineItems ??= [[
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => $net,
        'unit_price_net' => $net,
        'total_net' => $net,
    ]];

    $vat = (int) round($net * 19 / 100);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = $kind;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->vat_rate = 19.0;
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = $lineItems;
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('fully refunds a subscription invoice and creates a credit note', function (): void {
    Event::fake([InvoiceRefunded::class]);

    $billable = makeBillable();
    $invoice = makePaidInvoice($billable, 1000);

    $creditNote = app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::Goodwill);

    expect($creditNote->amount_net)->toBe(-1000);
    expect($creditNote->invoice_kind)->toBe('credit_note');
    expect($invoice->fresh()->refunded_net)->toBe(1000);

    Event::assertDispatched(InvoiceRefunded::class);
});

it('tracks partial refunds across multiple calls', function (): void {
    $billable = makeBillable();
    $invoice = makePaidInvoice($billable, 1000);

    app(RefundInvoiceService::class)->refundPartially($invoice, 300, RefundReasonCode::Goodwill);
    app(RefundInvoiceService::class)->refundPartially($invoice, 400, RefundReasonCode::Goodwill);

    expect($invoice->fresh()->refunded_net)->toBe(700);
});

it('throws RefundExceedsInvoiceAmountException beyond the remaining amount', function (): void {
    $billable = makeBillable();
    $invoice = makePaidInvoice($billable, 1000);

    app(RefundInvoiceService::class)->refundPartially($invoice, 800, RefundReasonCode::Goodwill);

    expect(fn () => app(RefundInvoiceService::class)->refundPartially($invoice, 300, RefundReasonCode::Goodwill))
        ->toThrow(RefundExceedsInvoiceAmountException::class);
});

it('rejects refundOverageUnits on a non-overage invoice', function (): void {
    $billable = makeBillable();
    $invoice = makePaidInvoice($billable, 1000, 'subscription');

    expect(fn () => app(RefundInvoiceService::class)->refundOverageUnits($invoice, 'tokens', 5, RefundReasonCode::Goodwill))
        ->toThrow(InvalidRefundTargetException::class);
});

it('requires reason_text when reason_code is Other', function (): void {
    $billable = makeBillable();
    $invoice = makePaidInvoice($billable, 1000);

    expect(fn () => app(RefundInvoiceService::class)->refundPartially($invoice, 100, RefundReasonCode::Other))
        ->toThrow(\InvalidArgumentException::class);
});

it('creditWalletOnly dispatches WalletCredited and does not call Mollie', function (): void {
    Event::fake([WalletCredited::class]);

    $billable = makeBillable();
    $billable->createWallet(['name' => 'tokens', 'slug' => 'tokens']);

    app(RefundInvoiceService::class)->creditWalletOnly($billable, 'tokens', 10, 'Goodwill bonus');

    Event::assertDispatched(WalletCredited::class, function (WalletCredited $event): bool {
        return $event->usageType === 'tokens' && $event->units === 10;
    });
});

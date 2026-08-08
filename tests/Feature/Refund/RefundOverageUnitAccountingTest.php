<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * Refunding overage units is capped in money by remainingRefundableNet(); the
 * wallet units have to be capped by what earlier refunds already credited.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    $this->app->bind(InvoiceService::class, fn ($app): InvoiceService => new class ($app->make(VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();

            return true;
        }
    });

    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService
    {
        protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
        {
            return 'rf_'.$paymentId.'_'.$grossCents;
        }
    });
});

function refundOverageBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'overage-refund@example.com',
        'name' => 'Overage Refund',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function overageInvoice(TestBillable $billable): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_overage_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(1000, 999999);
    $invoice->invoice_kind = InvoiceKind::Overage;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->line_items = [[
        'kind' => 'overage', 'code' => 'tokens', 'label' => 'Tokens overage',
        'quantity' => 100, 'unit_price_net' => 10, 'amount_net' => 1000,
        'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('does not credit overage units twice when a partial refund is followed by a full one', function (): void {
    $b = refundOverageBillable();
    $invoice = overageInvoice($b);

    app(RefundInvoiceService::class)->refundOverageUnits($invoice, 'tokens', 50, RefundReasonCode::Goodwill);

    expect($b->getWallet('tokens')?->balanceInt)->toBe(50);

    app(RefundInvoiceService::class)->refundFully($invoice->fresh(), RefundReasonCode::Goodwill);

    // 100 units were charged, so at most 100 may be credited back.
    expect($b->getWallet('tokens')->refresh()->balanceInt)->toBe(100)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(0);
});

it('credits the full quantity when nothing was refunded before', function (): void {
    $b = refundOverageBillable();
    $invoice = overageInvoice($b);

    app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::Goodwill);

    expect($b->getWallet('tokens')?->balanceInt)->toBe(100);
});

it('records the credited units on the credit note', function (): void {
    $b = refundOverageBillable();
    $invoice = overageInvoice($b);

    $creditNote = app(RefundInvoiceService::class)->refundOverageUnits($invoice, 'tokens', 30, RefundReasonCode::Goodwill);

    expect($creditNote->line_items[0]['wallet_credits'])->toBe(['tokens' => 30]);
});

it('persists the Mollie refund id on service-issued credit notes', function (): void {
    $b = refundOverageBillable();
    $invoice = overageInvoice($b);

    $creditNote = app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::Goodwill);

    $refundId = $creditNote->line_items[0]['mollie_refund_id'];

    expect($refundId)->not->toBeNull()
        // The id RefundHandler dedups on must be findable, otherwise a webhook
        // re-delivery books a second credit note for the same money.
        ->and(app(\GraystackIT\MollieBilling\Services\Webhook\RefundHandler::class)
            ->refundIdAlreadyPersisted($invoice->billable_type, $invoice->billable_id, $refundId))
        ->toBeTrue();
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * Every refund reason, through the service the admin panel calls: each is accepted, is
 * what the credit note records, and has a label in both shipped languages.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService
        {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
            {
                return 'rf_reason_'.$grossCents;
            }
        };
    });
});

it('refunds under every reason code and records it on the credit note', function (RefundReasonCode $reason): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Reason Co', 'email' => uniqid('reason-').'@x.test', 'billing_country' => 'DE']);

    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_reason_'),
        'serial_number' => 'IN-26'.random_int(100000, 999999),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'refunded_net' => 0,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 1000, 'total_net' => 1000, 'amount_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
    ])->save();

    // "Other" is the one reason that has to be explained.
    $text = $reason === RefundReasonCode::Other ? 'Agreed by phone' : null;

    app(RefundInvoiceService::class)->refundPartially($invoice, 400, $reason, $text);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect($creditNote->refund_reason_code)->toBe($reason)
        ->and((int) $creditNote->amount_net)->toBe(-400)
        ->and((int) $creditNote->amount_gross)->toBe(-476)
        ->and((int) $invoice->refresh()->refunded_net)->toBe(400);

    // A label a person can read, in both languages, and not the raw key.
    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        expect($reason->label())->not->toContain('billing::')->not->toBe($reason->value);
    }

    app()->setLocale('en');
})->with(RefundReasonCode::cases());

it('refuses the reason Other without a text', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Reason Co', 'email' => uniqid('reason-').'@x.test', 'billing_country' => 'DE']);

    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_reason_other',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'refunded_net' => 0,
        'line_items' => [],
    ])->save();

    expect(fn () => app(RefundInvoiceService::class)->refundPartially($invoice, 400, RefundReasonCode::Other, '  '))
        ->toThrow(InvalidArgumentException::class);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0);
});

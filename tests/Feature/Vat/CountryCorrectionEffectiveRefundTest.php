<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * The correction gate has to read the *effective* refunded net. A credit note
 * written by a path that never updated the `refunded_net` cache column (legacy
 * pro-rata refunds) leaves the column at 0, so the gate waves the invoice
 * through to refundFully() — which rejects it, and the rethrow aborts the whole
 * resolve. The mismatch then stays Pending with no way out.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
            {
                return 'rf_fake_'.$paymentId;
            }
        };
    });

    $self = $this;
    $this->app->singleton(InvoiceService::class, function ($app) use ($self) {
        $svc = new class (
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator::class),
        ) extends InvoiceService {
            /** @var list<array<string, mixed>> */
            public array $charges = [];

            public function issueCorrectionCharge(BillingInvoice $original, $billable, string $newCountry, int $mismatchId, ?string $creditNoteSerial = null): object
            {
                $paymentId = 'tr_effcorr_'.(count($this->charges) + 1);

                $this->charges[] = [
                    'payment_id' => $paymentId,
                    'original_invoice_id' => (int) $original->id,
                    'credit_note_serial' => $creditNoteSerial,
                ];

                return (object) ['id' => $paymentId, 'status' => 'open'];
            }
        };

        $self->invoiceSpy = $svc;

        return $svc;
    });
});

function effRefundBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'name' => 'Acme',
        'email' => 'eff@example.com',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $b;
}

function effRefundOriginal(TestBillable $billable, int $net): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->serial_number = 'INV-'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = [[
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 20.0,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

/**
 * A credit note that reverses the original in full but — like the legacy
 * pro-rata refund path — never touches the original's `refunded_net` column.
 */
function effRefundLegacyCreditNote(TestBillable $billable, BillingInvoice $original, int $net): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    $creditNote = new BillingInvoice;
    $creditNote->billable_type = $billable->getMorphClass();
    $creditNote->billable_id = $billable->getKey();
    $creditNote->serial_number = 'CR-'.uniqid();
    $creditNote->invoice_kind = InvoiceKind::Refund;
    $creditNote->status = InvoiceStatus::Refunded;
    $creditNote->country = 'AT';
    $creditNote->amount_net = -$net;
    $creditNote->amount_vat = -$vat;
    $creditNote->amount_gross = -($net + $vat);
    $creditNote->line_items = [[
        'kind' => 'refund',
        'label' => 'Credit note',
        'quantity' => 1,
        'unit_price_net' => -$net,
        'amount_net' => -$net,
        'vat_rate' => 20.0,
        'vat_amount' => -$vat,
        'amount_gross' => -($net + $vat),
        'parent_invoice_id' => (int) $original->id,
        'parent_line_item_index' => 0,
    ]];
    $creditNote->refunded_net = 0;
    $creditNote->save();

    return $creditNote;
}

it('resolves a mismatch whose invoice was credited without the refunded_net cache', function (): void {
    $billable = effRefundBillable();
    $original = effRefundOriginal($billable, 1000);
    effRefundLegacyCreditNote($billable, $original, 1000);

    // The discriminating fixture: cache column says nothing was refunded, the
    // credit note says everything was.
    expect((int) $original->fresh()->refunded_net)->toBe(0);
    expect($original->fresh()->effectiveRefundedNet())->toBe(1000);

    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch, 'DE');

    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Resolved);
    expect($billable->fresh()->billing_country)->toBe('DE');

    // No second credit note for money that is already back with the customer …
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    // … and the correction is still re-issued at the corrected country's rate.
    expect($this->invoiceSpy->charges)->toHaveCount(1);
    expect($this->invoiceSpy->charges[0]['original_invoice_id'])->toBe((int) $original->id);
});

it('refunds only the invoice that still has money on it', function (): void {
    $billable = effRefundBillable();
    $credited = effRefundOriginal($billable, 1000);
    $open = effRefundOriginal($billable, 2000);
    effRefundLegacyCreditNote($billable, $credited, 1000);

    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch, 'DE');

    // One pre-existing credit note plus exactly one new one, for the open invoice.
    $creditNotes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get();
    expect($creditNotes)->toHaveCount(2);
    expect((int) $open->fresh()->refunded_net)->toBe(2000);
    expect((int) $credited->fresh()->refunded_net)->toBe(0);

    // Both originals are corrected — being already refunded is no reason to skip
    // the reissue at the corrected rate.
    expect($this->invoiceSpy->charges)->toHaveCount(2);
    expect(array_column($this->invoiceSpy->charges, 'original_invoice_id'))
        ->toEqualCanonicalizing([(int) $credited->id, (int) $open->id]);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\CountryMismatchFlagged;
use GraystackIT\MollieBilling\Events\CountryMismatchResolved;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\CountryMismatchSelfNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Test double for RefundInvoiceService that skips the real Mollie HTTP call.
    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description): void {}
        };
    });

    // Test double for InvoiceService that records correction-charge calls instead of hitting Mollie.
    $this->correctionCharges = [];
    $self = $this;
    $this->app->singleton(InvoiceService::class, function ($app) use ($self) {
        $invoiceService = new class (
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator::class),
        ) extends InvoiceService {
            public array $charges = [];
            public function issueCorrectionCharge(BillingInvoice $original, $billable, string $newCountry, int $mismatchId, ?string $creditNoteSerial = null): object
            {
                $payload = [
                    'original_invoice_id' => (int) $original->id,
                    'new_country' => strtoupper($newCountry),
                    'mismatch_id' => $mismatchId,
                    'credit_note_serial' => $creditNoteSerial,
                ];
                $this->charges[] = $payload;
                $billable->forceFill([
                    'subscription_meta' => array_merge((array) $billable->subscription_meta, [
                        'country_corrections' => array_merge(
                            (array) ($billable->subscription_meta['country_corrections'] ?? []),
                            ['tr_correction_'.count($this->charges) => $payload],
                        ),
                    ]),
                ])->save();
                return (object) ['id' => 'tr_correction_'.count($this->charges), 'status' => 'open'];
            }
        };
        $self->correctionChargesRecorder = $invoiceService;
        return $invoiceService;
    });
});

function makeMismatchBillable(array $overrides = []): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'name' => 'Acme',
        'email' => 'acme@example.com',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ], $overrides))->save();

    return $b;
}

function makeMismatchInvoice(TestBillable $billable, int $net = 1000, string $country = 'AT'): BillingInvoice
{
    $line = [
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => $net,
        'unit_price_net' => $net,
        'total_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 20.0,
        'vat_amount' => (int) round($net * 0.20),
        'amount_gross' => $net + (int) round($net * 0.20),
    ];

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->serial_number = 'INV-'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = $country;
    $invoice->amount_net = $net;
    $invoice->amount_vat = (int) round($net * 0.20);
    $invoice->amount_gross = $net + (int) round($net * 0.20);
    $invoice->line_items = [$line];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

// ──────────────────────────────────────────────────────────────────────────
// B2B Hard-Gate (handled at the Livewire form layer via ValidatesVatNumber)
// — covered by VatCalculationService tests; this file tests the service layer.
// ──────────────────────────────────────────────────────────────────────────

it('skips the check entirely when a vat_number is set (B2B)', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'vat_number' => 'ATU12345678',
    ]);

    $result = app(CountryMatchService::class)->check($billable);

    expect($result)->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────
// B2C Three-Way Check
// ──────────────────────────────────────────────────────────────────────────

it('does not flag when user country matches the payment country', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'DE',
    ]);

    expect(app(CountryMatchService::class)->check($billable))->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('does not flag when user country matches the IP country', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'AT',
    ]);

    expect(app(CountryMatchService::class)->check($billable))->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('flags a mismatch when no signal matches the user country', function (): void {
    Event::fake([CountryMismatchFlagged::class]);
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);

    $result = app(CountryMatchService::class)->check($billable);

    expect($result)->toBeInstanceOf(BillingCountryMismatch::class);
    expect($result->status)->toBe(CountryMismatchStatus::Pending);
    expect($result->tax_country_user)->toBe('AT');
    expect($result->tax_country_payment)->toBe('DE');
    expect($result->tax_country_ip)->toBe('CH');
    expect($result->notified_at)->not->toBeNull();
    Event::assertDispatched(CountryMismatchFlagged::class);
});

it('does not flag when no comparison signal is available', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => null,
        'tax_country_ip' => null,
    ]);

    expect(app(CountryMatchService::class)->check($billable))->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('does not flag when payment is missing but ip matches user', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => null,
        'tax_country_ip' => 'AT',
    ]);

    expect(app(CountryMatchService::class)->check($billable))->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('is idempotent on repeated calls with the same conflicting state', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);

    app(CountryMatchService::class)->check($billable);
    app(CountryMatchService::class)->check($billable);
    app(CountryMatchService::class)->check($billable);

    expect(BillingCountryMismatch::count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────
// Flag side-effects: invoice marking, cancel-at-period-end, notification
// ──────────────────────────────────────────────────────────────────────────

it('marks all positive-net invoices with mismatch_id when flagging', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);
    $invoiceA = makeMismatchInvoice($billable, 1000);
    $invoiceB = makeMismatchInvoice($billable, 2500);
    $creditNote = makeMismatchInvoice($billable, 1000);
    $creditNote->forceFill([
        'invoice_kind' => InvoiceKind::Refund,
        'amount_net' => -1000,
    ])->save();

    $mismatch = app(CountryMatchService::class)->check($billable);

    expect($invoiceA->fresh()->mismatch_id)->toBe($mismatch->id);
    expect($invoiceB->fresh()->mismatch_id)->toBe($mismatch->id);
    expect($creditNote->fresh()->mismatch_id)->toBeNull();
});

it('triggers cancel-at-period-end on flag', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);

    app(CountryMatchService::class)->check($billable);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});

it('sends a CountryMismatchSelfNotification to the billable on flag', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);

    app(CountryMatchService::class)->check($billable);

    Notification::assertSentOnDemand(CountryMismatchSelfNotification::class);
});

// ──────────────────────────────────────────────────────────────────────────
// Resolve flow
// ──────────────────────────────────────────────────────────────────────────

it('refunds linked invoices and triggers correction charges on resolve', function (): void {
    Event::fake([CountryMismatchResolved::class]);
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
    ]);
    makeMismatchInvoice($billable, 1000);
    $mismatch = app(CountryMatchService::class)->check($billable);
    expect($mismatch)->not->toBeNull();

    /** @var \GraystackIT\MollieBilling\Services\Vat\CountryMatchService $service */
    $service = app(CountryMatchService::class);
    $service->resolve($billable, $mismatch, 'DE');

    $mismatch->refresh();
    $billable->refresh();

    expect($mismatch->status)->toBe(CountryMismatchStatus::Resolved);
    expect($mismatch->chosen_country)->toBe('DE');
    expect($billable->tax_country_user)->toBe('DE');
    expect($billable->billing_country)->toBe('DE');
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->where('amount_net', '<', 0)->count())->toBe(1);
    expect($this->correctionChargesRecorder->charges)->toHaveCount(1);
    expect($this->correctionChargesRecorder->charges[0]['new_country'])->toBe('DE');
    Event::assertDispatched(CountryMismatchResolved::class);
});

it('handles multiple linked invoices in a single resolve', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
    ]);
    makeMismatchInvoice($billable, 1000);
    makeMismatchInvoice($billable, 2000);
    makeMismatchInvoice($billable, 3000);
    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable, $mismatch, 'DE');

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->where('amount_net', '<', 0)->count())->toBe(3);
    expect($this->correctionChargesRecorder->charges)->toHaveCount(3);
});

it('skips already-refunded invoices on retry', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
    ]);
    $invoice = makeMismatchInvoice($billable, 1000);
    $mismatch = app(CountryMatchService::class)->check($billable);

    // First resolve succeeds
    app(CountryMatchService::class)->resolve($billable, $mismatch, 'DE');
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    $beforeCharges = count($this->correctionChargesRecorder->charges);

    // Simulate a retry to the same chosen country: refunds must not duplicate.
    app(CountryMatchService::class)->resolve($billable, $mismatch->fresh(), 'DE');

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    // Re-charge must not duplicate either since the pending entry exists.
    expect(count($this->correctionChargesRecorder->charges))->toBe($beforeCharges);
});

it('rejects an invalid ISO country code', function (): void {
    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);
    $mismatch = app(CountryMatchService::class)->check($billable);

    expect(fn () => app(CountryMatchService::class)->resolve($billable, $mismatch, 'XYZ'))
        ->toThrow(InvalidArgumentException::class);
});

// ──────────────────────────────────────────────────────────────────────────
// HasBilling helpers
// ──────────────────────────────────────────────────────────────────────────

it('exposes hasOpenCountryMismatch and latestOpenCountryMismatch', function (): void {
    Notification::fake();

    $billable = makeMismatchBillable([
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
    ]);
    expect($billable->hasOpenCountryMismatch())->toBeFalse();

    app(CountryMatchService::class)->check($billable);

    expect($billable->fresh()->hasOpenCountryMismatch())->toBeTrue();
    expect($billable->fresh()->latestOpenCountryMismatch())->toBeInstanceOf(BillingCountryMismatch::class);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    // Refunds without the Mollie HTTP call.
    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
            {
                return 'rf_fake_'.$paymentId;
            }
        };
    });

    // Records correction charges and mimics InvoiceService's pending-state write.
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
                $paymentId = 'tr_corr_'.(count($this->charges) + 1);

                $this->charges[] = [
                    'payment_id' => $paymentId,
                    'original_invoice_id' => (int) $original->id,
                    'new_country' => strtoupper($newCountry),
                    'mismatch_id' => $mismatchId,
                ];

                $meta = (array) $billable->subscription_meta;
                $meta['country_corrections'] = array_merge(
                    (array) ($meta['country_corrections'] ?? []),
                    [$paymentId => [
                        'mismatch_id' => $mismatchId,
                        'original_invoice_id' => (int) $original->id,
                        'new_country' => strtoupper($newCountry),
                    ]],
                );
                $billable->forceFill(['subscription_meta' => $meta])->save();

                return (object) ['id' => $paymentId, 'status' => 'open'];
            }
        };

        $self->invoiceSpy = $svc;

        return $svc;
    });
});

function correctionBillable(array $overrides = []): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'name' => 'Acme',
        'email' => 'acme@example.com',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ], $overrides))->save();

    return $b;
}

function correctionInvoice(TestBillable $billable, int $net, string $country = 'AT', ?string $paymentId = null): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = $paymentId ?? 'tr_'.uniqid();
    $invoice->serial_number = 'INV-'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = $country;
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = [[
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => $net,
        'unit_price_net' => $net,
        'total_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 20.0,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

/** Simulate CountryCorrectionHandler::paid() for one correction payment. */
function settleCorrection(TestBillable $billable, BillingCountryMismatch $mismatch, string $paymentId, int $net): BillingInvoice
{
    $reissue = correctionInvoice($billable, $net, 'DE', $paymentId);
    $reissue->forceFill(['mismatch_id' => $mismatch->id])->save();

    $meta = (array) $billable->fresh()->subscription_meta;
    unset($meta['country_corrections'][$paymentId]);
    if (empty($meta['country_corrections'])) {
        unset($meta['country_corrections']);
    }
    $billable->forceFill(['subscription_meta' => $meta])->save();

    return $reissue;
}

/** Simulate CountryCorrectionHandler::failed() for one correction payment. */
function failCorrection(TestBillable $billable, BillingCountryMismatch $mismatch, string $paymentId): void
{
    $meta = (array) $billable->fresh()->subscription_meta;
    unset($meta['country_corrections'][$paymentId]);
    if (empty($meta['country_corrections'])) {
        unset($meta['country_corrections']);
    }
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $mismatch->forceFill(['status' => CountryMismatchStatus::Pending])->save();
}

it('re-issues only the failed correction after a partially failed resolve', function (): void {
    $billable = correctionBillable();
    $i1 = correctionInvoice($billable, 1000);
    $i2 = correctionInvoice($billable, 2000);

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);

    $service->resolve($billable, $mismatch, 'DE');

    expect($this->invoiceSpy->charges)->toHaveCount(2);
    $c1 = collect($this->invoiceSpy->charges)->firstWhere('original_invoice_id', (int) $i1->id);
    $c2 = collect($this->invoiceSpy->charges)->firstWhere('original_invoice_id', (int) $i2->id);

    // C1 confirms and produces reissue R1; C2 is declined at Mollie.
    $r1 = settleCorrection($billable, $mismatch, $c1['payment_id'], 1000);
    failCorrection($billable, $mismatch, $c2['payment_id']);

    $refundsBefore = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count();

    // The portal tells the user to retry.
    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch->fresh(), 'DE');

    // Exactly one additional charge, and it is for I2 — not for I1 and not for R1.
    expect($this->invoiceSpy->charges)->toHaveCount(3);
    expect($this->invoiceSpy->charges[2]['original_invoice_id'])->toBe((int) $i2->id);

    // R1 is a valid paid correction and must never be refunded.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe($refundsBefore);
    expect((int) $r1->fresh()->refunded_net)->toBe(0);
});

it('never treats a reissue invoice as a correction target', function (): void {
    $billable = correctionBillable();
    $original = correctionInvoice($billable, 1000);

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);
    $service->resolve($billable, $mismatch, 'DE');

    $paymentId = $this->invoiceSpy->charges[0]['payment_id'];
    $reissue = settleCorrection($billable, $mismatch, $paymentId, 1000);

    // The reissue carries the same mismatch_id for audit traceability, so a naive
    // $mismatch->invoices() would pick it up as an original.
    expect($reissue->fresh()->mismatch_id)->toBe($mismatch->id);

    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch->fresh(), 'DE');

    expect($this->invoiceSpy->charges)->toHaveCount(1);
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    expect((int) $original->fresh()->refunded_net)->toBe(1000);
    expect((int) $reissue->fresh()->refunded_net)->toBe(0);
});

it('does not re-issue while a correction payment is still in flight', function (): void {
    $billable = correctionBillable();
    correctionInvoice($billable, 1000);

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);
    $service->resolve($billable, $mismatch, 'DE');

    // Pending entry still present (no webhook yet) → retry is a no-op.
    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch->fresh(), 'DE');

    expect($this->invoiceSpy->charges)->toHaveCount(1);
});

it('keeps a concurrent writer’s meta key while recording the issued correction', function (): void {
    $billable = correctionBillable();
    correctionInvoice($billable, 1000);

    // The other writer is another *process*: `issueCorrectionCharge()` posts a payment and
    // the customer can pay it at once, so the webhook handler is writing this same JSON
    // column while `resolve()` sits between its own read and its save. Modelled with a raw
    // update, which is the one thing the in-memory instance cannot see — the stub in
    // `beforeEach` writes through the same model, which is why the existing tests never
    // caught it.
    $this->app->singleton(InvoiceService::class, function ($app) {
        return new class (
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator::class),
        ) extends InvoiceService
        {
            public function issueCorrectionCharge(BillingInvoice $original, $billable, string $newCountry, int $mismatchId, ?string $creditNoteSerial = null): object
            {
                $paymentId = 'tr_corr_1';

                $meta = (array) $billable->fresh()->subscription_meta;
                $meta['country_corrections'] = [$paymentId => [
                    'mismatch_id' => $mismatchId,
                    'original_invoice_id' => (int) $original->id,
                    'new_country' => strtoupper($newCountry),
                ]];

                \Illuminate\Support\Facades\DB::table('test_billables')
                    ->where('id', $billable->getKey())
                    ->update(['subscription_meta' => json_encode($meta)]);

                return (object) ['id' => $paymentId, 'status' => 'open'];
            }
        };
    });

    app(CountryMatchService::class)->resolve(
        $billable,
        app(CountryMatchService::class)->check($billable),
        'DE',
    );

    $meta = (array) $billable->fresh()->subscription_meta;

    // Both keys, or the next pass is wrong in one of two directions: losing
    // `country_corrections` makes `needsCorrection()` re-issue a charge that is already in
    // flight — the customer pays twice for one supply period — and losing
    // `country_corrections_issued` loses the record that it was issued at all.
    expect($meta)->toHaveKey('country_corrections')
        ->and($meta['country_corrections'])->toHaveKey('tr_corr_1')
        ->and($meta['country_corrections_issued'] ?? [])->not->toBeEmpty();
});

it('rejects a country matching neither signal without a justification', function (): void {
    $billable = correctionBillable(['tax_country_payment' => 'DE', 'tax_country_ip' => 'CH']);
    $mismatch = app(CountryMatchService::class)->check($billable);

    expect(fn () => app(CountryMatchService::class)->resolve($billable, $mismatch, 'FR'))
        ->toThrow(InvalidArgumentException::class);

    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Pending);
});

it('accepts a third country when a justification is recorded', function (): void {
    $billable = correctionBillable(['tax_country_payment' => 'DE', 'tax_country_ip' => 'CH']);
    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable, $mismatch, 'FR', null, 'Support verified the French establishment');

    $billable->refresh();

    expect($mismatch->fresh()->chosen_country)->toBe('FR');
    expect($billable->billing_country)->toBe('FR');
    expect($billable->subscription_meta['tax_country_verified']['country'] ?? null)->toBe('FR');
    expect($billable->subscription_meta['tax_country_verified']['justification'] ?? null)
        ->toBe('Support verified the French establishment');
});

it('stops re-flagging a verified country while the signals are unchanged', function (): void {
    $billable = correctionBillable(['tax_country_payment' => 'DE', 'tax_country_ip' => 'CH']);
    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable, $mismatch, 'FR', null, 'verified');

    // Every successful payment re-runs check(). Without the verified marker the
    // signals (payment DE, IP CH) still contradict the user country FR, so a
    // brand-new mismatch would be flagged and the loop would never end.
    expect(app(CountryMatchService::class)->check($billable->fresh()))->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(1);
});

it('re-flags once a signal changes after a verified resolution', function (): void {
    $billable = correctionBillable(['tax_country_payment' => 'DE', 'tax_country_ip' => 'CH']);
    $mismatch = app(CountryMatchService::class)->check($billable);

    app(CountryMatchService::class)->resolve($billable, $mismatch, 'FR', null, 'verified');

    // New evidence: the card country changed. The old confirmation does not cover it.
    $billable->fresh()->forceFill(['tax_country_payment' => 'IT'])->save();

    $fresh = app(CountryMatchService::class)->check($billable->fresh());

    expect($fresh)->not->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(2);
});

it('refuses to correct an already-resolved mismatch to a different country', function (): void {
    $billable = correctionBillable();
    correctionInvoice($billable, 1000);

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);
    $service->resolve($billable, $mismatch, 'DE');

    // Correcting the same supply period twice would refund the valid reissue and
    // charge the customer again — a new mismatch is the only safe route.
    expect(fn () => app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch->fresh(), 'NL', null, 'moved'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->invoiceSpy->charges)->toHaveCount(1);
});

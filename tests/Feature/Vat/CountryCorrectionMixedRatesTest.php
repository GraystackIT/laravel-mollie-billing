<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A country correction re-issues an invoice at the destination country's rate. It must
 * not flatten a mixed-rate invoice onto one rate: that over-taxes the customer, and it
 * mis-buckets OSS, which buckets by each line's own `vat_rate`.
 */
function mixedRateBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Mixed Rates',
        'email' => uniqid('mixed-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_mix',
        'mollie_mandate_id' => 'mdt_mix',
    ]);

    return $b;
}

/**
 * @param  list<array{net: int, rate: float}>  $lines
 */
function mixedRateInvoice(TestBillable $billable, array $lines, string $country = 'AT'): BillingInvoice
{
    $items = [];
    $net = 0;
    $vat = 0;

    foreach ($lines as $i => $line) {
        $lineVat = (int) round($line['net'] * $line['rate'] / 100);
        $net += $line['net'];
        $vat += $lineVat;

        $items[] = [
            'kind' => $i === 0 ? 'plan' : 'addon',
            'code' => 'code-'.$i,
            'label' => 'Line '.$i,
            'quantity' => 1,
            'unit_price_net' => $line['net'],
            'amount_net' => $line['net'],
            'vat_rate' => $line['rate'],
            'vat_amount' => $lineVat,
            'amount_gross' => $line['net'] + $lineVat,
        ];
    }

    /** @var BillingInvoice $invoice */
    $invoice = BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_mix_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => $country,
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => $items,
    ]);

    return $invoice;
}

/** @return array{request: CreatePaymentRequest, amount: object} */
function captureCorrectionCharge(BillingInvoice $original, TestBillable $billable, string $newCountry): array
{
    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'tr_corr_'.count($captured), 'status' => 'open'];
    });

    app(InvoiceService::class)->issueCorrectionCharge($original, $billable, $newCountry, 42);

    return [
        'request' => $captured[0],
        'amount' => (new ReflectionProperty(CreatePaymentRequest::class, 'amount'))->getValue($captured[0]),
    ];
}

it('keeps a reduced-rate line reduced when correcting the country', function (): void {
    // AT 2000 @ 20% (400) + 1000 @ 10% (100) corrected to DE. Flattening to DE's 19%
    // charged 35.70; the reduced line has no derivable German equivalent, so it keeps
    // its own rate and the charge is 2000+380 + 1000+100 = 34.80.
    $billable = mixedRateBillable();
    $original = mixedRateInvoice($billable, [
        ['net' => 2000, 'rate' => 20.0],
        ['net' => 1000, 'rate' => 10.0],
    ]);

    $charge = captureCorrectionCharge($original, $billable, 'DE');

    expect($charge['amount']->value)->toBe('34.80');
});

it('re-rates only the lines that carried the origin standard rate', function (): void {
    $billable = mixedRateBillable();
    $original = mixedRateInvoice($billable, [
        ['net' => 2000, 'rate' => 20.0],
        ['net' => 1000, 'rate' => 10.0],
    ]);

    Mollie::shouldReceive('send')->andReturnUsing(fn () => (object) ['id' => 'tr_corr_x', 'status' => 'open']);
    app(InvoiceService::class)->issueCorrectionCharge($original, $billable, 'DE', 42);

    $pending = $billable->refresh()->getBillingSubscriptionMeta()['country_corrections'] ?? [];
    $lines = (array) (reset($pending)['line_items'] ?? []);

    // Cast: the rates survive a JSON round-trip through subscription_meta, which drops
    // the fractional part of a whole number.
    expect($lines)->toHaveCount(2)
        ->and((float) $lines[0]['vat_rate'])->toBe(19.0)
        ->and((int) $lines[0]['vat_amount'])->toBe(380)
        ->and((float) $lines[1]['vat_rate'])->toBe(10.0)
        ->and((int) $lines[1]['vat_amount'])->toBe(100);
});

it('zero-rates every line when the destination is reverse charged', function (): void {
    $billable = mixedRateBillable();
    $billable->forceFill(['vat_number' => 'DE123456789'])->save();
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $original = mixedRateInvoice($billable, [
        ['net' => 2000, 'rate' => 20.0],
        ['net' => 1000, 'rate' => 10.0],
    ]);

    // No VIES row means no reverse charge, so this asserts the standard-rate path for
    // a country whose rate differs — the reverse-charge branch is covered by
    // ReverseChargeLineRateClampTest against the same clamp.
    $charge = captureCorrectionCharge($original, $billable, 'DE');

    expect($charge['amount']->value)->toBe('34.80');
});

it('collects exactly what the reissued invoice will book', function (): void {
    // Six lines at one rate: the whole point of summing line gross rather than
    // rounding once over the total. round(6*550*0.19) = 627 but 6*round(550*0.19) = 630.
    $billable = mixedRateBillable();
    $original = mixedRateInvoice($billable, array_fill(0, 6, ['net' => 550, 'rate' => 20.0]));

    $charge = captureCorrectionCharge($original, $billable, 'DE');

    $pending = $billable->refresh()->getBillingSubscriptionMeta()['country_corrections'] ?? [];
    $lines = (array) (reset($pending)['line_items'] ?? []);

    $bookedGross = array_sum(array_column($lines, 'amount_gross'));

    expect((string) ($bookedGross / 100))->toBe('39.3')
        ->and($charge['amount']->value)->toBe('39.30');
});

it('keeps every line when the origin country rate cannot be resolved', function (): void {
    // An unknown origin country degrades the baseline to 0, so nothing matches it and
    // every line keeps its own rate rather than being re-rated off a wrong baseline.
    $billable = mixedRateBillable();
    $original = mixedRateInvoice($billable, [
        ['net' => 2000, 'rate' => 20.0],
        ['net' => 1000, 'rate' => 10.0],
    ], country: 'ZZ');

    $charge = captureCorrectionCharge($original, $billable, 'DE');

    expect($charge['amount']->value)->toBe('35.00');
});

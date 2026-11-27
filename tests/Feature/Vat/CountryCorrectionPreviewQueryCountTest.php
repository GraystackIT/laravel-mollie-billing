<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;

/**
 * The resolve confirmation (portal dashboard, operator modal) previews what
 * `CountryMatchService::resolve()` will refund. Each invoice read its remaining net and its
 * credit VAT off every credit note of the billable — and loaded that set again per invoice,
 * so 100 linked invoices meant 100 identical queries on every dashboard render. The history
 * is the same for all of them; it is loaded once per preview.
 */
function previewQueryCountScenario(int $invoiceCount): array
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'preview-'.$invoiceCount.'@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_country' => 'DE',
        'tax_country_user' => 'DE',
        'tax_country_payment' => 'NL',
        'tax_country_ip' => 'NL',
        'subscription_meta' => [],
    ])->save();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'DE',
        'tax_country_payment' => 'NL',
        'tax_country_ip' => 'NL',
        'status' => CountryMismatchStatus::Pending,
    ]);

    $invoices = [];
    for ($i = 0; $i < $invoiceCount; $i++) {
        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = 'tr_preview_'.$invoiceCount.'_'.$i;
        $invoice->invoice_kind = InvoiceKind::Subscription;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = 'DE';
        $invoice->currency = 'EUR';
        $invoice->amount_net = 3390;
        $invoice->amount_vat = 644;
        $invoice->amount_gross = 4034;
        $invoice->refunded_net = 0;
        $invoice->mismatch_id = $mismatch->id;
        $invoice->line_items = [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 19.0, 'vat_amount' => 551, 'amount_gross' => 3451],
            ['kind' => 'addon', 'code' => 'softdrinks', 'label' => 'Softdrinks', 'quantity' => 1, 'unit_price_net' => 490, 'amount_net' => 490, 'vat_rate' => 19.0, 'vat_amount' => 93, 'amount_gross' => 583],
        ];
        $invoice->save();
        $invoices[] = $invoice;
    }

    // A goodwill credit of the addon line on the first invoice, booked before the mismatch:
    // the preview must keep naming only what is left of it.
    $note = new BillingInvoice;
    $note->billable_type = $billable->getMorphClass();
    $note->billable_id = $billable->getKey();
    $note->invoice_kind = InvoiceKind::Refund;
    $note->status = InvoiceStatus::Refunded;
    $note->country = 'DE';
    $note->currency = 'EUR';
    $note->amount_net = -490;
    $note->amount_vat = -93;
    $note->amount_gross = -583;
    $note->refunded_net = 0;
    $note->refund_reason_code = RefundReasonCode::Goodwill;
    $note->line_items = [[
        'kind' => 'refund', 'label' => 'Goodwill', 'quantity' => 1,
        'unit_price_net' => -490, 'amount_net' => -490, 'vat_rate' => 19.0, 'vat_amount' => -93, 'amount_gross' => -583,
        'parent_invoice_id' => (int) $invoices[0]->id, 'parent_line_item_index' => 1,
    ]];
    $note->save();
    $invoices[0]->forceFill(['refunded_net' => 490])->save();

    return [$billable->refresh(), $mismatch->refresh()];
}

/** @return array{queries: int, refunds: list<array{invoice: BillingInvoice, net: int, gross: int}>} */
function previewQueryCountMeasure(TestBillable $billable, BillingCountryMismatch $mismatch): array
{
    $service = app(CountryMatchService::class);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $refunds = $service->refundsOnResolve($billable, $mismatch);

    return ['queries' => $queries, 'refunds' => $refunds];
}

it('previews the resolve with a query count that does not grow with the invoices', function (): void {
    [$oneBillable, $oneMismatch] = previewQueryCountScenario(1);
    [$tenBillable, $tenMismatch] = previewQueryCountScenario(10);

    $one = previewQueryCountMeasure($oneBillable, $oneMismatch);
    $ten = previewQueryCountMeasure($tenBillable, $tenMismatch);

    // Each measurement returns its own counter by value, so the first listener staying
    // registered does not leak into the second figure.
    expect($ten['queries'])->toBe($one['queries']);

    // Figures unchanged: the first invoice keeps only Pro (29.00 + 19 % = 34.51), every
    // other one is refunded in full (33.90 + 6.44 = 40.34).
    expect(array_map(fn (array $r): array => [$r['net'], $r['gross']], $one['refunds']))
        ->toBe([[2900, 3451]]);
    expect(array_map(fn (array $r): array => [$r['net'], $r['gross']], $ten['refunds']))
        ->toBe([[2900, 3451], ...array_fill(0, 9, [3390, 4034])]);
});

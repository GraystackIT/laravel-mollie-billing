<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * The prorata VAT probe resolves one rate for whichever side is non-zero, and the
 * charge side wins when both are. An interval change produces a charge *and* a credit,
 * so reusing the probe's gross reported the charge's amount as the credit — which is
 * what the plan-change panel renders to the customer as "you get back".
 */
beforeEach(function (): void {
    // Both intervals priced, and yearly far above the monthly credit, so an
    // interval-change upgrade lands in `computeProrata()`'s both-sides branch:
    // chargeNet = newNet - unusedCredit, creditNet = unusedCredit. That is the only
    // shape in which the two figures can be confused for one another.
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function creditGrossBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Credit Gross',
        'email' => uniqid('credit-gross-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_cg',
        'mollie_mandate_id' => 'mdt_cg',
    ]);

    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->copy()->subDays(10),
    ])->save();

    return $b->refresh();
}

function creditGrossPeriodInvoice(TestBillable $billable, int $net, float $rate): BillingInvoice
{
    $vat = (int) round($net * $rate / 100);

    /** @var BillingInvoice $invoice */
    $invoice = BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_cg_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'period_start' => $billable->subscription_period_starts_at,
        'period_end' => BillingTime::nowUtc()->copy()->addDays(20),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'starter',
            'label' => 'Starter',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
    ]);

    return $invoice;
}

it('grosses up the prorata credit from its own net, not the charge net', function (): void {
    $billable = creditGrossBillable();
    creditGrossPeriodInvoice($billable, 3000, 20.0);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'starter', 'yearly');

    $creditNet = (int) $preview['prorataCreditNet'];
    $chargeNet = (int) $preview['prorataChargeNet'];

    // Only meaningful if this really is the both-sides case the bug needs.
    expect($creditNet)->toBeGreaterThan(0)
        ->and($chargeNet)->toBeGreaterThan(0)
        ->and($creditNet)->not->toBe($chargeNet);

    $rate = (float) $preview['vatRate'];

    expect((int) $preview['prorataCreditGross'])
        ->toBe($creditNet + (int) round($creditNet * $rate / 100))
        ->and((int) $preview['prorataCreditVat'])
        ->toBe((int) round($creditNet * $rate / 100));
});

it('reports no credit gross when there is nothing to credit', function (): void {
    $billable = creditGrossBillable();
    creditGrossPeriodInvoice($billable, 3000, 20.0);

    // Same plan, same interval: nothing moves in either direction.
    $preview = app(PreviewService::class)->previewPlanChange($billable, 'starter', 'monthly');

    expect((int) $preview['prorataCreditNet'])->toBe(0)
        ->and((int) $preview['prorataCreditGross'])->toBe(0)
        ->and((int) $preview['prorataCreditVat'])->toBe(0);
});

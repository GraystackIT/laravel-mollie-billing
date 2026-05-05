<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1, 'trial_days' => 0, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => ['gateway-a', 'addon-b'],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);

    config()->set('mollie-billing-plans.addons.gateway-a', [
        'name' => 'Gateway A',
        'intervals' => ['monthly' => ['price_net' => 900]],
    ]);
    config()->set('mollie-billing-plans.addons.addon-b', [
        'name' => 'Addon B',
        'intervals' => ['monthly' => ['price_net' => 990]],
    ]);
});

it('PreviewService exposes prorataLines with multi-VAT line items per category', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Multi VAT', 'email' => 'multi@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_mv',
        'mollie_mandate_id' => 'mdt_mv',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'active_addon_codes' => ['gateway-a', 'addon-b'],
        'subscription_meta' => ['seat_count' => 3, 'mollie_subscription_id' => 'sub_mv'],
    ])->save();
    $billable->refresh();

    $now = now();
    $start = now()->subDays(15)->toIso8601String();
    $end = now()->addDays(15)->toIso8601String();

    $lines = [
        [
            'kind' => 'plan', 'code' => 'starter', 'label' => 'Starter',
            'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000,
            'vat_rate' => 20.0, 'vat_amount' => 200, 'amount_gross' => 1200,
            'period_start' => $start, 'period_end' => $end,
        ],
        [
            'kind' => 'seats', 'code' => null, 'label' => 'Seats',
            'quantity' => 2, 'unit_price_net' => 500, 'amount_net' => 1000,
            'vat_rate' => 20.0, 'vat_amount' => 200, 'amount_gross' => 1200,
            'period_start' => $start, 'period_end' => $end,
        ],
        [
            'kind' => 'addon', 'code' => 'gateway-a', 'label' => 'Gateway A',
            'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900,
            'vat_rate' => 20.0, 'vat_amount' => 180, 'amount_gross' => 1080,
            'period_start' => $start, 'period_end' => $end,
        ],
        [
            'kind' => 'addon', 'code' => 'addon-b', 'label' => 'Addon B',
            'quantity' => 1, 'unit_price_net' => 990, 'amount_net' => 990,
            'vat_rate' => 10.0, 'vat_amount' => 99, 'amount_gross' => 1089,
            'period_start' => $start, 'period_end' => $end,
        ],
    ];

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_mv',
        'mollie_subscription_id' => 'sub_mv',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT', 'currency' => 'EUR',
        'amount_net' => 3890, 'amount_vat' => 679, 'amount_gross' => 4569,
        'line_items' => $lines,
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'starter', interval: 'monthly',
        seats: 1, // 3 -> 1 = -2 seats
        addons: [], // disable both addons
    ));

    expect($preview)->toHaveKey('prorataLines');
    $prorataLines = $preview['prorataLines'];

    // Expected: 1 seat refund + 2 addon refunds = 3 lines (plan unchanged)
    expect($prorataLines)->toHaveCount(3);

    $byKind = collect($prorataLines)->groupBy('kind');
    expect($byKind->has('seats'))->toBeTrue();
    expect($byKind->has('addon'))->toBeTrue();

    // Per-item VAT correct: gateway-a has 20%, addon-b has 10%.
    $addonRates = $byKind['addon']->pluck('vat_rate')->all();
    expect($addonRates)->toContain(20.0);
    expect($addonRates)->toContain(10.0);
});

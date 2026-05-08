<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.business', [
        'name' => 'Business',
        'tier' => 2,
        'included_seats' => 3,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 10,
        'feature_keys' => [],
        'allowed_addons' => ['example-addon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 39000, 'seat_price_net' => 9000, 'included_usages' => []],
            'yearly' => ['base_price_net' => 39000, 'seat_price_net' => 9000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.example-addon', [
        'name' => 'Example Addon',
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['price_net' => 8900, 'included_usages' => []],
            'yearly' => ['price_net' => 8900, 'included_usages' => []],
        ],
    ]);
});

function planChangeBillable(string $plan, string $interval): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => $interval === 'yearly' ? SubscriptionInterval::Yearly : SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'active_addon_codes' => ['example-addon'],
        'subscription_meta' => ['seat_count' => 11, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    return $billable->refresh();
}

function planChangeInvoice(TestBillable $billable, array $lines, string $mollieIdSuffix = ''): BillingInvoice
{
    $sumNet = array_sum(array_column($lines, 'amount_net'));
    $sumGross = array_sum(array_column($lines, 'amount_gross'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_'.uniqid().$mollieIdSuffix,
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $sumNet,
        'amount_vat' => 0,
        'amount_gross' => $sumGross,
        'line_items' => $lines,
        'period_start' => now()->subDays(2),
        'period_end' => now()->addYear()->subDays(2),
        'refunded_net' => 0,
    ]);
}

it('plan change refunds ALL extra seats from the old period and recharges new ones', function (): void {
    $billable = planChangeBillable('enterprise', 'yearly');

    // Old enterprise yearly invoice: 1 extra seat at 9000 net.
    planChangeInvoice($billable, [[
        'kind' => 'plan',
        'code' => 'enterprise',
        'label' => 'Enterprise',
        'quantity' => 1,
        'unit_price_net' => 39000,
        'amount_net' => 39000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'amount_gross' => 39000,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);
    planChangeInvoice($billable, [[
        'kind' => 'seats',
        'code' => null,
        'label' => '1 Extra-Sitz (Enterprise)',
        'quantity' => 1,
        'unit_price_net' => 9000,
        'amount_net' => 9000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'amount_gross' => 9000,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);
    planChangeInvoice($billable, [[
        'kind' => 'addon',
        'code' => 'example-addon',
        'label' => 'Example Addon (Enterprise)',
        'quantity' => 1,
        'unit_price_net' => 8900,
        'amount_net' => 8900,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'amount_gross' => 8900,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);

    // Switch to Business monthly with same total seat count (11).
    // Business has 3 included → 8 extra. Drop the addon (not allowed in Business).
    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'business',
        currentInterval: 'yearly', newInterval: 'monthly',
        currentSeats: 11, newSeats: 11,
        currentAddons: ['example-addon' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    // Expect:
    //   refunds: plan (enterprise), 1 seat (enterprise), 1 addon (example-addon)
    //   charges: plan (business), 8 seats (business)
    //   no addon charge (not in newAddons)
    $charges = array_values(array_filter($lines, fn (ProrataLine $l) => $l->direction === 'charge'));
    $refunds = array_values(array_filter($lines, fn (ProrataLine $l) => $l->direction === 'refund'));

    $chargeKinds = array_map(fn (ProrataLine $l) => $l->kind, $charges);
    $refundKinds = array_map(fn (ProrataLine $l) => $l->kind, $refunds);

    expect($chargeKinds)->toContain('plan');
    expect($chargeKinds)->toContain('seats');
    expect($chargeKinds)->not->toContain('addon');

    expect($refundKinds)->toContain('plan');
    expect($refundKinds)->toContain('seats');
    expect($refundKinds)->toContain('addon');

    // The seat charge must be for 8 (full new extra-seat count), NOT the diff (7).
    $seatCharge = $charges[array_search('seats', $chargeKinds)];
    expect($seatCharge->quantity)->toBe(8);

    // The seat refund must cover the 1 existing extra seat from enterprise.
    $seatRefund = $refunds[array_search('seats', $refundKinds)];
    expect($seatRefund->quantity)->toBe(1);
});

it('plan change refunds all addons and recharges only those still selected', function (): void {
    config()->set('mollie-billing-plans.addons.second-addon', [
        'name' => 'Second Addon',
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['price_net' => 1500, 'included_usages' => []],
            'yearly' => ['price_net' => 1500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.business.allowed_addons', ['second-addon']);

    $billable = planChangeBillable('enterprise', 'yearly');
    $billable->forceFill(['active_addon_codes' => ['example-addon']])->save();

    // Both an enterprise plan-line and an enterprise addon-line exist.
    planChangeInvoice($billable, [[
        'kind' => 'plan', 'code' => 'enterprise', 'label' => 'Enterprise', 'quantity' => 1,
        'unit_price_net' => 39000, 'amount_net' => 39000, 'vat_rate' => 0, 'vat_amount' => 0, 'amount_gross' => 39000,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);
    planChangeInvoice($billable, [[
        'kind' => 'addon', 'code' => 'example-addon', 'label' => 'Example Addon', 'quantity' => 1,
        'unit_price_net' => 8900, 'amount_net' => 8900, 'vat_rate' => 0, 'vat_amount' => 0, 'amount_gross' => 8900,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);

    // Switch to business with a different addon active.
    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'business',
        currentInterval: 'yearly', newInterval: 'monthly',
        currentSeats: 3, newSeats: 3, // matches business included_seats — no extra
        currentAddons: ['example-addon' => 1],
        newAddons: ['second-addon' => 1],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    $addonRefunds = array_values(array_filter(
        $lines,
        fn (ProrataLine $l) => $l->kind === 'addon' && $l->direction === 'refund'
    ));
    $addonCharges = array_values(array_filter(
        $lines,
        fn (ProrataLine $l) => $l->kind === 'addon' && $l->direction === 'charge'
    ));

    expect($addonRefunds)->toHaveCount(1);
    expect($addonRefunds[0]->code)->toBe('example-addon');

    expect($addonCharges)->toHaveCount(1);
    expect($addonCharges[0]->code)->toBe('second-addon');
});

it('mid-cycle seat reduction (no plan change) still uses diff logic', function (): void {
    $billable = planChangeBillable('enterprise', 'yearly');

    // 2 extra seats originally bought.
    planChangeInvoice($billable, [[
        'kind' => 'plan', 'code' => 'enterprise', 'label' => 'Enterprise', 'quantity' => 1,
        'unit_price_net' => 39000, 'amount_net' => 39000, 'vat_rate' => 0, 'vat_amount' => 0, 'amount_gross' => 39000,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);
    planChangeInvoice($billable, [[
        'kind' => 'seats', 'code' => null, 'label' => '2 Extra-Sitze', 'quantity' => 2,
        'unit_price_net' => 9000, 'amount_net' => 18000, 'vat_rate' => 0, 'vat_amount' => 0, 'amount_gross' => 18000,
        'period_start' => now()->subDays(2)->toIso8601String(),
        'period_end' => now()->addYear()->subDays(2)->toIso8601String(),
    ]]);

    // Same plan, same interval, just 1 fewer seat.
    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'enterprise',
        currentInterval: 'yearly', newInterval: 'yearly',
        currentSeats: 12, newSeats: 11, // 12-10=2 extra → 11-10=1 extra → diff -1
        currentAddons: [],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    $charges = array_values(array_filter($lines, fn (ProrataLine $l) => $l->direction === 'charge'));
    $refunds = array_values(array_filter($lines, fn (ProrataLine $l) => $l->direction === 'refund'));

    // No plan refund/charge — same plan & interval. Only seat diff.
    expect($charges)->toBeEmpty();
    expect($refunds)->toHaveCount(1);
    expect($refunds[0]->kind)->toBe('seats');
    expect($refunds[0]->quantity)->toBe(1);
});

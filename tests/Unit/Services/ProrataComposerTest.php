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
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway', 'example-addon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'intervals' => ['monthly' => ['price_net' => 900]],
    ]);

    config()->set('mollie-billing-plans.addons.example-addon', [
        'name' => 'Example Addon',
        'intervals' => ['monthly' => ['price_net' => 990]],
    ]);
});

function makeComposerBillable(string $currentPlan = 'enterprise', int $seats = 1, array $addons = []): TestBillable
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
        'subscription_plan_code' => $currentPlan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'active_addon_codes' => array_keys($addons),
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    return $billable->refresh();
}

function makePeriodInvoiceWithLines(TestBillable $billable, array $lines): BillingInvoice
{
    $sumNet = array_sum(array_column($lines, 'amount_net'));
    $sumVat = array_sum(array_column($lines, 'vat_amount'));
    $sumGross = array_sum(array_column($lines, 'amount_gross'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $sumNet,
        'amount_vat' => $sumVat,
        'amount_gross' => $sumGross,
        'line_items' => $lines,
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);
}

function planLine(string $code, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'plan',
        'code' => $code,
        'label' => ucfirst($code),
        'quantity' => 1,
        'unit_price_net' => $netCents,
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

function seatsLine(int $quantity, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'seats',
        'code' => null,
        'label' => "{$quantity}× Sitz",
        'quantity' => $quantity,
        'unit_price_net' => intdiv($netCents, max(1, $quantity)),
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

function addonLine(string $code, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'addon',
        'code' => $code,
        'label' => $code,
        'quantity' => 1,
        'unit_price_net' => $netCents,
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

it('returns empty list for Free→Free (Free origin)', function (): void {
    $billable = makeComposerBillable('free');
    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'free', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toBe([]);
});

it('Mollie→Mollie plan change creates 2 lines: refund old, charge new', function (): void {
    $billable = makeComposerBillable('enterprise');
    makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(2);
    expect($lines[0]->direction)->toBe('charge'); // Charges first
    expect($lines[0]->kind)->toBe('plan');
    expect($lines[0]->code)->toBe('starter');
    expect($lines[0]->amountNet)->toBeGreaterThan(0);

    expect($lines[1]->direction)->toBe('refund');
    expect($lines[1]->kind)->toBe('plan');
    expect($lines[1]->code)->toBe('enterprise');
    expect($lines[1]->amountNet)->toBeLessThan(0);
    expect($lines[1]->vatRate)->toBe(20.0);
});

it('Mollie→Free downgrade creates only refund (no charge)', function (): void {
    $billable = makeComposerBillable('enterprise');
    makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->kind)->toBe('plan');
});

it('seat reduction creates one refund line against current period seats line', function (): void {
    $billable = makeComposerBillable('starter', 5);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        seatsLine(4, 4 * 500, 20.0), // 4 extra seats × 500 cents
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 5, newSeats: 3,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->kind)->toBe('seats');
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->quantity)->toBe(2);
});

it('addon disable creates refund line via currentPeriodLines lookup', function (): void {
    $billable = makeComposerBillable('starter', 1, ['print-gateway' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        addonLine('print-gateway', 900, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['print-gateway' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->kind)->toBe('addon');
    expect($lines[0]->code)->toBe('print-gateway');
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->vatRate)->toBe(20.0);
});

it('multi-VAT: refund of addon with 10% VAT is independent of plan 20%', function (): void {
    $billable = makeComposerBillable('starter', 1, ['example-addon' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        addonLine('example-addon', 990, 10.0), // 10% VAT
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['example-addon' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->vatRate)->toBe(10.0); // Aus Original-Line, nicht Plan
});

it('coupon-covered original (amount_net=0) marks line as isCouponCovered', function (): void {
    $billable = makeComposerBillable('starter', 1, ['print-gateway' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        // print-gateway via Coupon: amount_net = 0
        addonLine('print-gateway', 0, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['print-gateway' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->isCouponCovered)->toBeTrue();
    expect($lines[0]->amountGross)->toBe(0);
});

it('seat reduction LIFO across multiple original line items', function (): void {
    $billable = makeComposerBillable('starter', 10);
    // 5 seats from period start, 3 seats added at day 5, 2 seats added at day 10.
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        seatsLine(5, 5 * 500, 20.0, now()->subDays(15)->toIso8601String(), now()->addDays(15)->toIso8601String()),
    ]);
    // Mid-cycle invoices (separate Subscription invoices via mid-cycle activation):
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seats3',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3 * 500,
        'amount_vat' => 3 * 100,
        'amount_gross' => 3 * 600,
        'line_items' => [
            seatsLine(3, 3 * 500, 20.0, now()->subDays(10)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(10),
        'period_end' => now()->addDays(15),
    ]);
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seats2',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 2 * 500,
        'amount_vat' => 2 * 100,
        'amount_gross' => 2 * 600,
        'line_items' => [
            seatsLine(2, 2 * 500, 20.0, now()->subDays(5)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(5),
        'period_end' => now()->addDays(15),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 10, newSeats: 5,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    // 5 Sitze zurückgeben: jüngste (2) zuerst, dann nächst-jüngste (3) → 2 Refund-Lines.
    expect($lines)->toHaveCount(2);
    expect($lines[0]->kind)->toBe('seats');
    expect($lines[0]->quantity)->toBe(2); // jüngste
    expect($lines[1]->quantity)->toBe(3); // zweit-jüngste
});

it('throws RuntimeException when refund target line item is missing', function (): void {
    $billable = makeComposerBillable('enterprise');
    // Keine Period-Invoice mit Plan-Line erstellt!

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    expect(fn () => app(ProrataComposer::class)->compose($intent))
        ->toThrow(\RuntimeException::class);
});

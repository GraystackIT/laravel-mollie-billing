<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

function billableForPatcherTest(?CarbonInterface $markerValidUntil = null, int $discountValue = 100): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Patcher Test',
        'email' => 'patcher@x.test',
        'billing_country' => 'AT',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(2),
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_test',
            'active_recurring_coupon' => $markerValidUntil !== null ? [
                'coupon_id' => 1,
                'code' => 'FREE100',
                'discount_type' => 'percentage',
                'discount_value' => $discountValue,
                'valid_until' => $markerValidUntil->toIso8601String(),
                'base_amount_net' => 1000,
                'first_applied_at' => BillingTime::nowUtc()->toIso8601String(),
            ] : null,
        ],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $billable;
}

function inspectRequest(MollieUpdateSubscriptionRequest $request): array
{
    $reflection = new \ReflectionClass($request);
    $get = function (string $prop) use ($reflection, $request) {
        $p = $reflection->getProperty($prop);
        $p->setAccessible(true);
        return $p->getValue($request);
    };

    return [
        'amount' => $get('amount'),
        'startDate' => $get('startDate'),
    ];
}

it('100% coverage → PATCH uses full price + startDate after marker.valid_until', function (): void {
    $validUntil = BillingTime::nowUtc()->copy()->addDays(91); // 3 months × 30 + 1
    $billable = billableForPatcherTest($validUntil, 100);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;
        return (object) ['id' => 'sub_test'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 0,
        intervalChanged: false,
        couponDiscountNet: 1000, // 100% of 1000
    );

    expect($captured)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class);
    $inspected = inspectRequest($captured);

    // Full price (10.00 EUR + 20% AT VAT = 12.00 EUR), NOT 0.
    expect($inspected['amount']->value)->toBe('12.00');

    // startDate is the day after marker.valid_until.
    expect($inspected['startDate'])->not->toBeNull();
    $startDateStr = (string) $inspected['startDate'];
    $expectedStart = $validUntil->copy()->addDay()->format('Y-m-d');
    expect(str_contains($startDateStr, $expectedStart))->toBeTrue();
});

it('partial discount (50%) → PATCH uses discounted amount, no startDate override', function (): void {
    $billable = billableForPatcherTest(BillingTime::nowUtc()->copy()->addDays(180), 50);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;
        return (object) ['id' => 'sub_test'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 0,
        intervalChanged: false,
        couponDiscountNet: 500, // 50% of 1000
    );

    $inspected = inspectRequest($captured);

    // Discounted price: (1000 - 500) net + 20% VAT = 600 → 6.00 EUR.
    expect($inspected['amount']->value)->toBe('6.00');

    // No startDate override for amount-only changes.
    expect($inspected['startDate'])->toBeNull();
});

it('100% coverage with interval change → still uses deferred startDate (full-coverage wins)', function (): void {
    $validUntil = BillingTime::nowUtc()->copy()->addDays(91);
    $billable = billableForPatcherTest($validUntil, 100);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;
        return (object) ['id' => 'sub_test'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 0,
        intervalChanged: true,
        couponDiscountNet: 1000,
    );

    $inspected = inspectRequest($captured);
    expect($inspected['startDate'])->not->toBeNull();
    $startDateStr = (string) $inspected['startDate'];
    $expectedStart = $validUntil->copy()->addDay()->format('Y-m-d');
    expect(str_contains($startDateStr, $expectedStart))->toBeTrue();
});

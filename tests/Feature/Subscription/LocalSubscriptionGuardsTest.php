<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionDoesNotSupportPaidExtrasException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['paid-addon', 'free-addon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.paid', [
        'name' => 'Paid',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['paid-addon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.free-with-paid-seats', [
        'name' => 'Free with paid extra seats',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => 200, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.paid-addon', [
        'name' => 'Paid Addon',
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['price_net' => 990],
            'yearly' => ['price_net' => 9900],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.free-addon', [
        'name' => 'Free Addon',
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['price_net' => 0],
            'yearly' => ['price_net' => 0],
        ],
    ]);
});

function makeLocalGuardBillable(array $addons = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('blocks adding a paid addon to a Local subscription', function (): void {
    $billable = makeLocalGuardBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'addons' => ['paid-addon' => 1],
    ]))->toThrow(LocalSubscriptionDoesNotSupportPaidExtrasException::class);
});

it('allows adding a free addon to a Local subscription', function (): void {
    $billable = makeLocalGuardBillable();

    $result = app(UpdateSubscription::class)->update($billable, [
        'addons' => ['free-addon' => 1],
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toContain('free-addon');
    expect($result['addonsAdded'])->toContain('free-addon');
});

it('blocks adding paid extra seats to a Local subscription', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free-with-paid-seats',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'seats' => 3,
    ]))->toThrow(LocalSubscriptionDoesNotSupportPaidExtrasException::class);
});

it('blocks switching a Local subscription directly to a paid plan via UpdateSubscription', function (): void {
    $billable = makeLocalGuardBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'paid',
        'interval' => 'monthly',
    ]))->toThrow(LocalSubscriptionUpgradeRequiresMolliePathException::class);
});

it('allows switching between two free plans on a Local subscription', function (): void {
    config()->set('mollie-billing-plans.plans.free-other', [
        'name' => 'Other Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    $billable = makeLocalGuardBillable();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free-other',
        'interval' => 'monthly',
    ]);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('free-other');
    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);
    expect($result['planChanged'])->toBeTrue();
});

// ── Paid seats lost on plan change to a plan without extra-seat support ──

it('blocks plan change that would silently drop paid extra seats', function (): void {
    config()->set('mollie-billing-plans.plans.pro-with-paid-seats', [
        'name' => 'Pro with paid seats',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro-with-paid-seats',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_seat',
        'mollie_mandate_id' => 'mdt_test_seat',
        'subscription_meta' => ['seat_count' => 5, 'mollie_subscription_id' => 'sub_test_seat'],
    ])->save();
    $billable->refresh();

    // Pro has 5 seats (1 included + 4 paid), used = 0. Switching to free
    // (1 included, no extra seats) without an explicit `seats` value would
    // silently keep 5 seats on a plan that can't host them — must throw.
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]))->toThrow(SeatDowngradeRequiredException::class);
});

it('allows the same change when seats are explicitly set (drop-extras path)', function (): void {
    config()->set('mollie-billing-plans.plans.pro-with-paid-seats', [
        'name' => 'Pro with paid seats',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro-with-paid-seats',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_seat2',
        'mollie_mandate_id' => 'mdt_test_seat2',
        'subscription_meta' => ['seat_count' => 5, 'mollie_subscription_id' => 'sub_test_seat2'],
    ])->save();
    $billable->refresh();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'seats' => 1, // user explicitly drops the paid extras
    ]);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('free');
    expect($billable->subscription_meta['seat_count'])->toBe(1);
    expect($result['planChanged'])->toBeTrue();
});

it('returns paid_seats_lost error in preview when paid extras would be dropped', function (): void {
    config()->set('mollie-billing-plans.plans.pro-with-paid-seats', [
        'name' => 'Pro with paid seats',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro-with-paid-seats',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'subscription_meta' => ['seat_count' => 5],
    ])->save();
    $billable->refresh();

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'free',
        interval: 'monthly',
    ));

    $errors = $preview['errors'] ?? [];
    $paidSeatsLost = collect($errors)->firstWhere('type', 'paid_seats_lost');

    expect($paidSeatsLost)->not->toBeNull();
    expect($paidSeatsLost['current'])->toBe(5);
    expect($paidSeatsLost['included'])->toBe(1);
    expect($paidSeatsLost['lost'])->toBe(4);
});

it('exposes prorata remaining and total days in the preview', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'paid',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(10),
        'billing_country' => 'AT',
        'subscription_meta' => ['seat_count' => 1],
    ])->save();
    $billable->refresh();

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'free',
        interval: 'monthly',
    ));

    // Period: now -10 → now +20 (30 days), remaining = 20
    expect($preview['prorataTotalDays'])->toBe(30);
    expect($preview['prorataRemainingDays'])->toBe(20);
});

it('clears the paid_seats_lost error when seats are explicitly set', function (): void {
    config()->set('mollie-billing-plans.plans.pro-with-paid-seats', [
        'name' => 'Pro with paid seats',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro-with-paid-seats',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'subscription_meta' => ['seat_count' => 5],
    ])->save();
    $billable->refresh();

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'free',
        interval: 'monthly',
        seats: 1,
    ));

    $errors = collect($preview['errors'] ?? []);
    expect($errors->firstWhere('type', 'paid_seats_lost'))->toBeNull();
});

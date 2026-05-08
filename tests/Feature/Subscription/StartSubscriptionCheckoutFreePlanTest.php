<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 0,
                'seat_price_net' => null,
                'included_usages' => ['emails' => 50],
            ],
        ],
    ]);
});

it('activates a free plan with no coupon as a Local subscription without contacting Mollie', function (): void {
    Event::fake([SubscriptionCreated::class]);
    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Free Sub',
        'email' => 'free@x.test',
        'billing_country' => 'AT',
    ]);

    $result = app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'amount_gross' => 0,
    ]);

    expect($result['checkout_url'])->toBeNull()
        ->and($result['payment_id'])->toBe('');

    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);

    Event::assertDispatched(SubscriptionCreated::class);
});

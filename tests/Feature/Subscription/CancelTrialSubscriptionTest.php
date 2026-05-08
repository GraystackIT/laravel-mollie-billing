<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1900,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => [],
            ],
        ],
    ]);
});

function makeTrialingMollieBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trial@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'trial_ends_at' => BillingTime::nowUtc()->addDays(14),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_trial', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('immediately cancels a Mollie trial subscription, calls Mollie cancel and dispatches SubscriptionCancelled', function (): void {
    Event::fake([SubscriptionCancelled::class]);

    $billable = makeTrialingMollieBillable();

    $cancelCalled = false;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$cancelCalled) {
        if ($request instanceof CancelSubscriptionRequest) {
            $cancelCalled = true;

            return (object) ['id' => 'sub_trial', 'status' => 'canceled'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    app(CancelSubscription::class)->handle($billable, immediately: true);

    expect($cancelCalled)->toBeTrue();

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at)->not->toBeNull();
    // Within a couple of seconds of "now" — immediate cancel
    expect(BillingTime::nowUtc()->diffInSeconds($billable->subscription_ends_at, false))
        ->toBeLessThan(5);

    Event::assertDispatched(SubscriptionCancelled::class, fn ($event) => $event->immediately === true);
});

it('endTrial via HasBilling::cancelBillingSubscription(immediately:true) yields the same outcome', function (): void {
    $billable = makeTrialingMollieBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial', 'status' => 'canceled']);

    $billable->cancelBillingSubscription(immediately: true);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->hasAccessibleBillingSubscription())->toBeFalse();
});

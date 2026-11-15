<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;

/**
 * `addons` arrives in two shapes — a list of codes and a code => quantity map — and
 * `UpdateSubscription` documents both. `schedule()` filtered it with `(int) $q > 0`,
 * which is right for the map and empties the list (`(int) 'analytics'` is 0): a change
 * scheduled with the list form was stored with no addons at all and removed every addon
 * the customer had when it was applied.
 */
beforeEach(function (): void {
    Notification::fake();

    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['analytics', 'storage'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 600]]],
        'storage' => ['name' => 'Storage', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
    ]);
});

function scheduledAddonListBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Addon List Co',
        'email' => uniqid('addon-list-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_addon_list',
        'mollie_mandate_id' => 'mdt_addon_list',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => ['analytics', 'storage'],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_addon_list', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('keeps the addons of a change scheduled with a list of codes', function (): void {
    $billable = scheduledAddonListBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'addons' => ['analytics', 'storage'],
        'apply_at' => 'end_of_period',
    ]);

    $stored = $billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['addons'];

    // Starter cannot host `storage`, so that one is filtered — `analytics` stays.
    expect($stored)->toBe(['analytics' => 1]);
});

it('stores a code => quantity map as before', function (): void {
    $billable = scheduledAddonListBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'addons' => ['analytics' => 1, 'storage' => 0],
        'apply_at' => 'end_of_period',
    ]);

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['addons'])->toBe(['analytics' => 1]);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Services\Billing\ProrataExecutor;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    $this->app->singleton(
        \GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class,
        SpyMollieSubscriptionPatcher::class,
    );
    SpyMollieSubscriptionPatcher::$calls = [];

    config()->set('mollie-billing.plan_change_mode', \GraystackIT\MollieBilling\Enums\PlanChangeMode::UserChoice);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 5800, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 58000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function noopBranchBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Noop Co',
        'email' => 'noop@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_noop',
        'mollie_mandate_id' => 'mdt_noop',
    ]);

    // No BillingInvoice exists, so the composer finds no refundable plan line and — for a
    // free target — produces no effective line at all.
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(15),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_noop', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('reports in_flight rather than noop when a prorata change is already pending', function (): void {
    $billable = noopBranchBillable();

    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_prorata_change'] = ['charge_payment_id' => 'tr_existing'];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $intent = new PlanChangeIntent(
        billable: $billable->refresh(),
        currentPlan: 'pro', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    Mollie::shouldReceive('send')->never();

    $result = app(ProrataExecutor::class)->execute($billable, $intent, []);

    // 'noop' would tell the caller "Mollie still needs patching" and patch over a change
    // that is waiting on a Mollie charge.
    expect($result['path'])->toBe('in_flight');
});

it('returns noop when the composer produced no effective line', function (): void {
    $billable = noopBranchBillable();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    expect($lines)->toBe([]);

    expect(app(ProrataExecutor::class)->execute($billable, $intent, $lines)['path'])->toBe('noop');
});

it('still cancels the Mollie subscription on a free downgrade the executor no-opped', function (): void {
    $billable = noopBranchBillable();

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('free');
    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);

    // The bug: `$hasProrata` was true, so cancelForFreeDowngrade() was skipped while
    // `mollie_subscription_id` was dropped anyway — a live Mollie subscription nobody
    // could ever cancel, charging forever.
    $cancelCalls = array_values(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $call) => $call[0] === 'cancel' || $call[0] === 'cancel_for_free_downgrade',
    ));
    expect($cancelCalls)->not->toBeEmpty();
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');
});

it('patches Mollie for a plan change that prorates to nothing', function (): void {
    $billable = noopBranchBillable();

    // On the renewal day the remaining-window factor collapses to 0, so within one interval
    // neither a charge nor a refund line survives — the executor no-ops.
    $billable->forceFill([
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth(),
    ])->save();
    $billable->refresh();

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));
    expect($lines)->toBe([]);

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
        'internal' => true,
    ]);

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('enterprise');

    // Without deriving the branch from the executor result, the local plan flipped with no
    // PATCH at all — `mollieSubscriptionPatched` was reported true while Mollie kept
    // charging the old amount every period.
    $updateCalls = array_values(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $call) => $call[0] === 'update',
    ));
    expect($updateCalls)->not->toBeEmpty();
});

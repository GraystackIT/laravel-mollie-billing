<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// `supplier` mirrors the real use case from DEV-182: a hidden, free plan that
// carries feature gating, a seat allowance and a usage quota with no overage.
beforeEach(function (): void {
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

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 100], 'usage_overage_prices' => ['Tokens' => 5]],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 1200], 'usage_overage_prices' => ['Tokens' => 5]],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.supplier', [
        'name' => 'Supplier',
        'hidden' => true,
        'tier' => 2, // deliberately mirrors pro's tier — must not warn, see CheckConfigHiddenPlansTest
        'included_seats' => 25,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 5000], 'usage_overage_prices' => ['Tokens' => false]],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 60000], 'usage_overage_prices' => ['Tokens' => false]],
        ],
    ]);
});

function makeHiddenPlanMollieBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_123',
        'mollie_mandate_id' => 'mdt_test_123',
        'subscription_meta' => ['seat_count' => 5, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    return $billable->refresh();
}

function makeSupplierBillable(string $interval = 'monthly'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Supplier Co', 'email' => 'supplier@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'supplier',
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 25],
    ])->save();

    return $billable->refresh();
}

// ── Catalog: hidden means unlisted, not unresolvable ──

it('excludes hidden plans from visiblePlans but keeps them in allPlans', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->allPlans())->toContain('supplier')
        ->and($catalog->visiblePlans())->not->toContain('supplier')
        ->and($catalog->visiblePlans())->toContain('free', 'pro')
        ->and($catalog->planIsHidden('supplier'))->toBeTrue()
        ->and($catalog->planIsHidden('pro'))->toBeFalse();
});

it('treats a plan without the hidden key as visible', function (): void {
    expect(app(SubscriptionCatalogInterface::class)->planIsHidden('pro'))->toBeFalse();
});

it('resolves every by-code lookup for a hidden plan so the portal can render it', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->planName('supplier'))->toBe('Supplier')
        ->and($catalog->includedSeats('supplier'))->toBe(25)
        ->and($catalog->includedUsage('supplier', 'monthly', 'Tokens'))->toBe(5000)
        ->and($catalog->includedUsages('supplier', 'monthly'))->toBe(['Tokens' => 5000])
        ->and($catalog->basePriceNet('supplier', 'monthly'))->toBe(0)
        ->and($catalog->isFreePlan('supplier', 'monthly'))->toBeTrue();
});

it('reports an explicit overage => false as no overage price rather than zero', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    // null, not 0 — a 0 would render as "€0.00 per unit" in the usage lists.
    expect($catalog->usageOveragePrice('supplier', 'monthly', 'Tokens'))->toBeNull()
        ->and($catalog->usageOveragePrice('pro', 'monthly', 'Tokens'))->toBe(5);
});

// ── Server-side enforcement: plan change ──

it('rejects a hidden plan as a plan-change target', function (): void {
    $billable = makeHiddenPlanMollieBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'supplier',
        'interval' => 'monthly',
    ]))->toThrow(HiddenPlanNotSelectableException::class);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro');
});

it('rejects a hidden plan target on a scheduled change before anything is stored', function (): void {
    // The scheduling branch runs before ValidateSubscriptionChange, so without the
    // guard in UpdateSubscription::update() the change would be persisted here and
    // only fail when it is applied at period end.
    $billable = makeHiddenPlanMollieBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'supplier',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]))->toThrow(HiddenPlanNotSelectableException::class);

    $billable->refresh();
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->scheduled_change_at)->toBeNull();
});

it('carries allow_hidden_plan into the scheduled change so it survives to period end', function (): void {
    $billable = makeHiddenPlanMollieBillable();

    app(UpdateSubscription::class)->update($billable, (new SubscriptionUpdateRequest(
        planCode: 'supplier',
        interval: 'monthly',
        applyAt: 'end_of_period',
        allowHiddenPlan: true,
    )));

    $billable->refresh();
    $scheduled = $billable->subscription_meta['scheduled_change'] ?? null;

    expect($scheduled)->not->toBeNull()
        ->and($scheduled['plan_code'])->toBe('supplier')
        ->and($scheduled['allow_hidden_plan'] ?? null)->toBeTrue();
});

it('does not add allow_hidden_plan to scheduled changes that never asked for it', function (): void {
    $billable = makeHiddenPlanMollieBillable();

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();

    expect($billable->subscription_meta['scheduled_change'])
        ->not->toHaveKey('allow_hidden_plan');
});

// ── Staying on a hidden plan ──

it('allows an interval change within a hidden plan', function (): void {
    Mollie::shouldReceive('send')->never();

    $billable = makeSupplierBillable('monthly');

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'supplier',
        'interval' => 'yearly',
    ]);

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('supplier')
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Yearly);
});

it('allows a subscriber to move off a hidden plan onto a visible one', function (): void {
    $billable = makeSupplierBillable();

    // seats given explicitly because the supplier plan's 25-seat allowance does
    // not fit into free — the regular "drop extra seats" path, unrelated to hiding.
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'seats' => 1,
    ]);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('free');
});

// ── Server-side enforcement: checkout ──

it('refuses to start a checkout for a hidden plan', function (): void {
    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Crafted',
        'email' => 'crafted@example.test',
        'billing_country' => 'AT',
    ]);

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'supplier',
        'interval' => 'monthly',
        'amount_gross' => 0,
    ]))->toThrow(HiddenPlanNotSelectableException::class);
});

it('refuses a Local→Mollie upgrade onto a hidden plan', function (): void {
    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Local', 'email' => 'local@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    expect(fn () => app(UpgradeLocalToMollie::class)->handle($billable->refresh(), [
        'plan_code' => 'supplier',
        'interval' => 'monthly',
        'amount_gross' => 1000,
    ]))->toThrow(HiddenPlanNotSelectableException::class);
});

// ── Programmatic assignment stays open ──

it('activates a hidden plan programmatically via ActivateLocalSubscription', function (): void {
    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Supplier Co', 'email' => 'sup@example.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'supplier', 'monthly');

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('supplier')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->includedBillingQuota('Tokens'))->toBe(5000);
});

it('allows a programmatic move onto a hidden plan with allow_hidden_plan', function (): void {
    $billable = makeSupplierBillable();

    // free → supplier, i.e. onto the hidden plan, which the guard would refuse
    // without the explicit opt-in.
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'free', 'interval' => 'monthly', 'seats' => 1]);
    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('free');

    app(UpdateSubscription::class)->update($billable, (new SubscriptionUpdateRequest(
        planCode: 'supplier',
        interval: 'monthly',
        allowHiddenPlan: true,
    )));

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('supplier');
});

// ── The override is not reachable from array payloads ──

it('ignores allow_hidden_plan in an array payload, whatever the value looks like', function (): void {
    // An app forwarding request data into update() must not be able to hand the
    // caller a hidden plan. Note `(bool) 'false' === true` in PHP — the old array
    // parsing would have enabled the override for the string "false" too.
    foreach ([true, 'true', 'false', '1', 1] as $value) {
        $billable = makeHiddenPlanMollieBillable();

        expect(fn () => app(UpdateSubscription::class)->update($billable, [
            'plan_code' => 'supplier',
            'interval' => 'monthly',
            'allow_hidden_plan' => $value,
            'allowHiddenPlan' => $value,
        ]))->toThrow(HiddenPlanNotSelectableException::class);

        expect($billable->refresh()->subscription_plan_code)->toBe('pro');
    }
});

it('ignores allow_hidden_plan in an array payload on the scheduling path', function (): void {
    $billable = makeHiddenPlanMollieBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'supplier',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
        'allow_hidden_plan' => true,
    ]))->toThrow(HiddenPlanNotSelectableException::class);

    $billable->refresh();
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull();
});

it('re-checks the hidden-plan rule on the locked row when the caller model is stale', function (): void {
    // The pre-check in UpdateSubscription::update() runs on the caller's model.
    // Here that model still sits on the hidden `supplier` plan, so "keep your own
    // plan, switch interval" looks legitimate — while the persisted row has since
    // been moved to `pro`. Only ScheduleSubscriptionChange::schedule() sees the
    // refreshed row, where the request becomes a move *onto* a hidden plan.
    $stale = makeSupplierBillable('monthly');

    TestBillable::query()
        ->whereKey($stale->getKey())
        ->update(['subscription_plan_code' => 'pro']);

    expect(fn () => app(UpdateSubscription::class)->update($stale, [
        'plan_code' => 'supplier',
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]))->toThrow(HiddenPlanNotSelectableException::class);

    $fresh = TestBillable::query()->whereKey($stale->getKey())->first();

    expect($fresh->subscription_plan_code)->toBe('pro')
        ->and($fresh->subscription_meta['scheduled_change'] ?? null)->toBeNull()
        ->and($fresh->scheduled_change_at)->toBeNull();
});

it('still schedules an interval change within a hidden plan the billable is really on', function (): void {
    $billable = makeSupplierBillable('monthly');

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'supplier',
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();

    expect($billable->subscription_meta['scheduled_change']['plan_code'] ?? null)->toBe('supplier')
        ->and($billable->subscription_meta['scheduled_change']['interval'] ?? null)->toBe('yearly');
});

it('keeps the override when it is set through the typed request', function (): void {
    $request = SubscriptionUpdateRequest::from([
        'plan_code' => 'supplier',
        'interval' => 'monthly',
        'allow_hidden_plan' => true,
    ]);

    expect($request->allowHiddenPlan)->toBeFalse()
        ->and($request->withAllowHiddenPlan()->allowHiddenPlan)->toBeTrue()
        ->and($request->withAllowHiddenPlan()->planCode)->toBe('supplier');
});

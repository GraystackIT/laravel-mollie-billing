<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/*
 * A feature can be kept off everything a customer reads while it still unlocks — so an
 * app can gate on technical feature codes that are not selling points.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.features', [
        'dashboard' => ['name' => 'Dashboard'],
        'internal-beta' => ['name' => 'Internal Beta Flag', 'visible' => false],
        'reports' => ['name' => 'Reports', 'visible' => true],
        'addon-shown' => ['name' => 'Shown Addon Feature'],
        'addon-secret' => ['name' => 'Secret Addon Flag', 'visible' => false],
    ]);
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => ['dashboard', 'internal-beta', 'reports'],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'max' => [
            'name' => 'Max', 'tier' => 3, 'included_seats' => 1,
            'feature_keys' => ['dashboard', 'reports'],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 4900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => ['name' => 'Extra', 'feature_keys' => ['addon-shown', 'addon-secret'], 'intervals' => ['monthly' => ['price_net' => 500]]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function hiddenFeatureBillable(array $addons = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Hidden Co', 'email' => uniqid('hidden-').'@x.test', 'billing_country' => 'AT']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_hidden', 'seat_count' => 1],
        'mollie_customer_id' => 'cst_hidden',
        'mollie_mandate_id' => 'mdt_hidden',
    ])->save();

    $b = $b->fresh();
    MollieBilling::resolveBillableUsing(fn () => $b);

    return $b;
}

it('reads visibility from the definition, and only a literal false hides', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureIsVisible('dashboard'))->toBeTrue()
        ->and($catalog->featureIsVisible('reports'))->toBeTrue()
        ->and($catalog->featureIsVisible('internal-beta'))->toBeFalse()
        // Unknown is visible: that is a misconfiguration to see, not to hide.
        ->and($catalog->featureIsVisible('never-defined'))->toBeTrue();

    config()->set('mollie-billing-plans.features.dashboard.visible', 0);
    config()->set('mollie-billing-plans.features.reports.visible', null);

    expect($catalog->featureIsVisible('dashboard'))->toBeTrue()
        ->and($catalog->featureIsVisible('reports'))->toBeTrue();
});

it('lists only the visible features of a plan and an addon, in their order', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->visiblePlanFeatures('pro'))->toBe(['dashboard', 'reports'])
        ->and($catalog->planFeatures('pro'))->toBe(['dashboard', 'internal-beta', 'reports'])
        ->and($catalog->visibleAddonFeatures('extra'))->toBe(['addon-shown'])
        ->and($catalog->allFeatures()['internal-beta']['visible'])->toBeFalse()
        ->and($catalog->allFeatures()['dashboard']['visible'])->toBeTrue();
});

it('still unlocks a hidden feature', function (): void {
    $billable = hiddenFeatureBillable(addons: ['extra']);
    $access = app(FeatureAccess::class);

    expect($access->has($billable, 'internal-beta'))->toBeTrue()
        ->and($access->has($billable, 'addon-secret'))->toBeTrue()
        ->and($access->has($billable, 'dashboard'))->toBeTrue();
});

it('keeps a hidden feature off the checkout plan cards', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    $component = Livewire::test('mollie-billing::checkout')
        ->assertDontSee('Internal Beta Flag');

    // The plan step renders its cards from this method, whichever step is open.
    $names = array_column($component->instance()->planFeatures('pro'), 'name');

    expect($names)->toBe(['Dashboard', 'Reports']);
});

it('keeps a hidden feature off the plan-change screen', function (): void {
    hiddenFeatureBillable();

    Livewire::test('mollie-billing::plan-change')
        ->assertSee('Reports')
        ->assertDontSee('Internal Beta Flag');
});

it('keeps a hidden feature off the addon cards', function (): void {
    hiddenFeatureBillable();

    Livewire::test('mollie-billing::addons')
        ->assertSee('Shown Addon Feature')
        ->assertDontSee('Secret Addon Flag');
});

it('rejects a visible flag that is not a boolean in check-config', function (mixed $value): void {
    config()->set('mollie-billing-plans.features.dashboard.visible', $value);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('features.dashboard.visible must be a boolean')
        ->assertExitCode(1);
})->with(['false', 'no', 0, 1]);

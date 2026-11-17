<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The dashboard buttons a customer only sees in one particular state. Each is called
 * directly here — a Livewire action can be sent whether its button is drawn or not —
 * in the state it is for and in one it is not for.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);

    DashboardStateMollie::$sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        DashboardStateMollie::$sent[] = $request;

        return (object) ['id' => 'sub_dashstate', 'status' => 'active', 'startDate' => null, 'nextPaymentDate' => null];
    });

    MollieBilling::authUsing(fn (): bool => true);
});

final class DashboardStateMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static function count(string $class): int
    {
        return count(array_filter(self::$sent, fn ($r) => $r instanceof $class));
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dashboardStateBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'State', 'email' => uniqid('ds-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'billing_street' => 'Street 1', 'billing_postal_code' => '1010', 'billing_city' => 'Vienna',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_dashstate', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_dashstate',
        'mollie_mandate_id' => 'mdt_dashstate',
    ], $overrides))->save();

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

    return $billable;
}

it('ends a running trial at once and does nothing without one', function (): void {
    $trial = dashboardStateBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(5),
    ]);

    Livewire::test('mollie-billing::dashboard')->call('endTrial')
        ->assertSet('flash', __('billing::portal.flash.trial_ended'));

    $trial->refresh();

    // No grace period: nothing was paid.
    expect($trial->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($trial->hasAccessibleBillingSubscription())->toBeFalse()
        ->and(DashboardStateMollie::count(CancelSubscriptionRequest::class))->toBe(1);

    // A paying customer sending the same action keeps their subscription.
    $paying = dashboardStateBillable();
    DashboardStateMollie::$sent = [];

    Livewire::test('mollie-billing::dashboard')->call('endTrial')->assertSet('flash', null);

    expect($paying->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(DashboardStateMollie::$sent)->toBe([]);
});

it('brings the first charge forward only while the billable is past due', function (): void {
    $pastDue = dashboardStateBillable(['subscription_status' => SubscriptionStatus::PastDue]);

    Livewire::test('mollie-billing::dashboard')->call('forceImmediateMollieCharge')
        ->assertSet('flash', __('billing::portal.flash.force_charge_scheduled'))
        ->assertSet('flashError', false);

    // A date-only PATCH to today.
    expect(DashboardStateMollie::count(UpdateSubscriptionRequest::class))->toBe(1);

    // Active by now (the charge went through in the meantime): a second full period
    // must not be collected by a stale button.
    $active = dashboardStateBillable();
    DashboardStateMollie::$sent = [];

    Livewire::test('mollie-billing::dashboard')->call('forceImmediateMollieCharge')
        ->assertSet('flash', __('billing::portal.flash.force_charge_no_longer_needed'))
        ->assertSet('flashError', true);

    expect(DashboardStateMollie::count(UpdateSubscriptionRequest::class))->toBe(0)
        ->and($active->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('resubscribes during the grace period', function (): void {
    $billable = dashboardStateBillable();
    app(CancelSubscription::class)->handle($billable, false, 'customer');

    Livewire::test('mollie-billing::dashboard')->call('resubscribe')
        ->assertSet('flashError', false);

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->fresh()->getBillingSubscriptionEndsAt())->toBeNull();
});

it('reactivates after a resolved mismatch and refuses while one is open', function (): void {
    $billable = dashboardStateBillable();
    app(CancelSubscription::class)->handle($billable, false, 'country_mismatch');

    $mismatch = BillingCountryMismatch::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'status' => CountryMismatchStatus::Pending,
    ]);

    // Still open: resuming would book the next period on the data under review.
    Livewire::test('mollie-billing::dashboard')->call('reactivateAfterMismatch')
        ->assertSet('flash', __('billing::portal.country_mismatch.reactivate_failed'))
        ->assertSet('flashError', true);
    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);

    $mismatch->forceFill(['status' => CountryMismatchStatus::Resolved, 'resolved_at' => BillingTime::nowUtc()])->save();

    Livewire::test('mollie-billing::dashboard')->call('reactivateAfterMismatch')
        ->assertSet('flash', __('billing::portal.flash.resubscribed'))
        ->assertSet('flashError', false);

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('cancels a scheduled change from the dashboard', function (): void {
    $billable = dashboardStateBillable();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['scheduled_change'] = ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 2, 'addons' => []];
    $billable->forceFill([
        'subscription_meta' => $meta,
        'scheduled_change_at' => $billable->nextBillingDate(),
    ])->save();

    Livewire::test('mollie-billing::dashboard')->call('cancelScheduledChange')
        ->assertSet('flash', __('billing::portal.flash.scheduled_cancelled'))
        ->assertSet('flashError', false);

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change')
        ->and($billable->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');
});

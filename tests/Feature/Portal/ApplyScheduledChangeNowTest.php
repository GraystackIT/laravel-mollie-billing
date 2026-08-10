<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

// "Apply the scheduled change now" reassembled the payload by hand and cancelled the
// stored change BEFORE attempting it. Four ways to lose a change the customer had booked:
// it could never succeed under `plan_change_mode = end_of_period` (no `internal` flag), it
// replayed an auto-derived seat count as an explicit one, it dropped stacked coupon codes,
// and any failure deleted the change instead of leaving it for the next attempt.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->first());

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 5,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 0, 'included_usages' => []]],
        ],
        // A free target applies synchronously; an upgrade would defer behind a prorata
        // charge and prove nothing about whether the button works.
        'free' => [
            'name' => 'Free',
            'tier' => 0,
            'included_seats' => 5,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        // Sells no extra seats, so a billable with more members than it includes is a
        // refusal the customer can still resolve — the case `apply()` must not discard.
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 5,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
});

function scheduledNowBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Sched Co',
        'email' => 'sched@example.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_sched',
        'mollie_mandate_id' => 'mdt_sched',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_sched', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('applies a scheduled change early even when the mode forbids immediate changes', function (): void {
    // The exact install this button was unusable on: the mode that makes scheduling the
    // only way to change a plan is also the mode the hand-rolled payload could not satisfy.
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_sched', 'status' => 'active']);

    $billable = scheduledNowBillable();
    $billable->changeBillingPlan('free', 'monthly');

    expect($billable->fresh()->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('free');

    Livewire::test('mollie-billing::plan-change')->call('applyScheduledNow');

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('free')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->scheduled_change_at)->toBeNull();
});

it('keeps the scheduled change when applying it early fails', function (): void {
    // Deleting first meant the customer lost a booked change to any transient failure.
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_sched', 'status' => 'active']);

    $billable = scheduledNowBillable();
    $billable->changeBillingPlan('pro', 'monthly');

    // Ten members assigned, and `pro` sells no extra seats beyond its five included —
    // a refusal the customer can resolve, so the change must survive for the next attempt.
    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    $meta['used_seats'] = 10;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Livewire::test('mollie-billing::plan-change')->call('applyScheduledNow');

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('pro');
});

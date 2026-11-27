<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * Decision 9 (26 November 2026): the dashboard's "Next billing" tile of a cancelled
 * subscription named the period end as a billing date — a charge that will never come.
 * A cancelled subscription still in its grace period says when it ends instead, without
 * an amount, and offers to withdraw the cancellation (the dashboard's own `resubscribe`).
 * One that has already ended says when it ended. Active, trial and past due keep their
 * date.
 *
 * The period start (1 March) and the end (20 March) are deliberately apart from the
 * would-be next billing date (1 April), so the tile can be told apart from the other
 * dates on the page.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);

    Mollie::shouldReceive('send')->andReturnUsing(
        fn () => (object) ['id' => 'sub_tile', 'status' => 'active', 'startDate' => null, 'nextPaymentDate' => null],
    );

    MollieBilling::authUsing(fn (): bool => true);

    $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function nextBillingTileBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Tile', 'email' => uniqid('tile-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-03-01 12:00:00', 'UTC'),
        'billing_street' => 'Street 1', 'billing_postal_code' => '1010', 'billing_city' => 'Vienna',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tile', 'seat_count' => 1],
        'mollie_customer_id' => 'cst_tile',
        'mollie_mandate_id' => 'mdt_tile',
    ], $overrides))->save();

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

    return $billable;
}

it('says when a cancelled subscription in its grace period ends, with no billing date, and offers to withdraw the cancellation', function (): void {
    nextBillingTileBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => Carbon::parse('2026-03-20 12:00:00', 'UTC'),
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('Subscription ends on 20. Mar 2026')
        // The period end it used to name as a billing date, and the amount next to it.
        ->assertDontSee('01. Apr 2026')
        ->assertDontSee('€50.00')
        ->assertSeeHtml('dusk="next-billing-withdraw-cancellation"')
        ->assertSee(__('billing::portal.grace_withdraw_cancellation'));
});

it('says the same in German', function (): void {
    app()->setLocale('de');

    nextBillingTileBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => Carbon::parse('2026-03-20 12:00:00', 'UTC'),
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('Abo endet am '.BillingTime::billingDay(Carbon::parse('2026-03-20 12:00:00', 'UTC'))->translatedFormat('d. M Y'))
        ->assertSee('Kündigung zurücknehmen');
});

it('withdraws the cancellation through the tile\'s action', function (): void {
    $billable = nextBillingTileBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => Carbon::parse('2026-04-01 12:00:00', 'UTC'),
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->assertSeeHtml('dusk="next-billing-withdraw-cancellation"')
        ->call('resubscribe')
        ->assertSet('flash', __('billing::portal.flash.resubscribed'))
        ->assertDontSeeHtml('dusk="next-billing-withdraw-cancellation"')
        ->assertDontSee('Subscription ends on');

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('says when a cancelled subscription ended, without a billing date or a withdraw action', function (): void {
    nextBillingTileBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => Carbon::parse('2026-03-05 12:00:00', 'UTC'),
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('Subscription ended on 05. Mar 2026')
        ->assertDontSee('01. Apr 2026')
        ->assertDontSeeHtml('dusk="next-billing-withdraw-cancellation"');
});

it('keeps the billing date for an active, a trial and a past-due subscription', function (array $overrides, string $date): void {
    nextBillingTileBillable($overrides);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee(__('billing::portal.next_billing'))
        ->assertSee($date)
        ->assertDontSee('Subscription ends on')
        ->assertDontSee('Subscription ended on')
        ->assertDontSeeHtml('dusk="next-billing-withdraw-cancellation"');
})->with([
    'active' => [[], '01. Apr 2026'],
    'trial' => [['subscription_status' => SubscriptionStatus::Trial, 'trial_ends_at' => Carbon::parse('2026-03-24 12:00:00', 'UTC')], '24. Mar 2026'],
    'past due' => [['subscription_status' => SubscriptionStatus::PastDue], '01. Apr 2026'],
]);

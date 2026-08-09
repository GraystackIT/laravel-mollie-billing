<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// Seats cannot be taken back by moving a counter. The package never revokes them — it
// counts what the host app reports through `getUsedBillingSeats()` — so a seat count set
// below that number pays out a prorata refund for capacity every one of those members
// keeps using. The auto-derive branch always refused it; the explicit branch, which is the
// only one the portal's seats screen reaches, did not.

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
});

function seatFloorBillable(int $seatCount, int $usedSeats): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Seat Co',
        'email' => uniqid('seat-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_seat',
        'mollie_mandate_id' => 'mdt_seat',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_seat',
            'seat_count' => $seatCount,
            'used_seats' => $usedSeats,
        ],
    ])->save();

    return $billable->refresh();
}

it('refuses an explicit seat count below the seats actually in use', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $billable = seatFloorBillable(seatCount: 10, usedSeats: 10);

    expect(fn () => $billable->syncBillingSeats(1))
        ->toThrow(SeatDowngradeRequiredException::class);

    // Nothing moved — no refund, no reduced count.
    expect($billable->fresh()->getBillingSeatCount())->toBe(10);
});

it('allows a seat reduction down to the seats in use', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_seat', 'status' => 'active']);

    $billable = seatFloorBillable(seatCount: 10, usedSeats: 4);

    $billable->syncBillingSeats(4);

    expect($billable->fresh()->getBillingSeatCount())->toBe(4);
});

it('still allows raising the seat count', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_seat', 'status' => 'open', 'subscriptionId' => 'sub_seat']);

    $billable = seatFloorBillable(seatCount: 2, usedSeats: 2);

    $billable->syncBillingSeats(5);

    // Either applied straight away or deferred behind a prorata charge — what matters is
    // that the floor did not refuse an increase.
    expect($billable->fresh()->getBillingSubscriptionMeta())->not->toBeEmpty();
});

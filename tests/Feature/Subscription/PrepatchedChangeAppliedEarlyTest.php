<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * A scheduled upgrade that was announced to Mollie when it was scheduled
 * (`mollie_prepatched`) and is then applied early — "Apply now" mid-period.
 *
 * Mid-period the upgrade has a prorata charge, so it waits for that payment like any
 * immediate upgrade: the plan is not switched yet. The scheduled change is gone at that
 * point, and with it the only record that Mollie holds the target's amount. When the
 * payment was then declined or the customer withdrew the waiting change, nothing put
 * Mollie back: the customer stayed on Starter and every renewal was collected at Pro's
 * price, refunded down after the fact by SupersededRenewalRefund — or not at all, if that
 * refund failed.
 */
beforeEach(function (): void {
    World::boot();
});

/** Starter, with Starter → Pro scheduled for the period end and announced to Mollie. */
function prepatchedUpgradeAppliedNow(): \GraystackIT\MollieBilling\Testing\TestBillable
{
    $billable = World::subscription('none', 'starter', 2, []);
    $starterGross = World::heldGross();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change']['mollie_prepatched'] ?? null)->toBeTrue()
        ->and(World::heldGross())->toBeGreaterThan($starterGross)
        ->and(World::$heldMetadata['plan_code'] ?? null)->toBe('pro');

    // "Apply now", mid-period.
    app(ScheduleSubscriptionChange::class)->apply($billable, onRequest: true);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['pending_plan_change']['plan_code'] ?? null)->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter');

    return $billable;
}

it('puts Mollie back to the plan the customer stays on when the early-applied upgrade\'s charge is declined', function (): void {
    $billable = prepatchedUpgradeAppliedNow();
    $starterGross = 2400;

    World::settle($billable, 'failed');
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->toBeNull()
        ->and(World::heldGross())->toBe($starterGross)
        ->and(World::$heldMetadata['plan_code'] ?? null)->toBe('starter');
});

it('puts Mollie back to the plan the customer stays on when the early-applied upgrade is withdrawn', function (): void {
    $billable = prepatchedUpgradeAppliedNow();
    $paymentId = (string) $billable->getBillingSubscriptionMeta()['prorata_pending_payment_id'];
    World::$paymentStatus[$paymentId] = 'open';
    World::$cancelable[$paymentId] = true;

    app(UpdateSubscription::class)->tryCancelPendingPlanChange($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and(World::$paymentStatus[$paymentId])->toBe('canceled')
        ->and(World::heldGross())->toBe(2400)
        ->and(World::$heldMetadata['plan_code'] ?? null)->toBe('starter');
});

it('still moves Mollie to the new plan when the early-applied upgrade\'s charge is paid', function (): void {
    $billable = prepatchedUpgradeAppliedNow();

    World::settle($billable, 'paid');
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::heldGross())->toBe(6000)
        ->and(World::$heldMetadata['plan_code'] ?? null)->toBe('pro');
});

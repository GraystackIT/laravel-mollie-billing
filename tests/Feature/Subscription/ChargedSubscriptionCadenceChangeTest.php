<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/**
 * A change that restarts the cadence of a subscription Mollie has charged.
 *
 * An interval change and the past-due reset send Mollie a new start date — one full period
 * from now. Mollie refuses that on a charged subscription (422 "…already charged", measured
 * 17 November 2026), so the patcher replaces it: cancel, then create it with the new amount,
 * interval and start date. The change runs through UpdateSubscription's three phases and the
 * prorata webhook; what has to hold at the end is that the row names the NEW subscription —
 * none of the writes that follow the Mollie call may put the old id back — and that exactly
 * one subscription is left charging.
 *
 * ChangeMatrixWorld: the running subscription was paid by a renewal on 1 Jan 2026; changes
 * are made on 11 Jan.
 */
beforeEach(fn () => World::boot());

it('moves a charged subscription to the yearly cadence by replacing it, and the row names the new one', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);

    expect(World::$charged)->toBeTrue();

    World::change($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate']);
    $billable->refresh();

    $cancel = collect(World::$sent)->search(fn ($r) => $r instanceof CancelSubscriptionRequest);
    $create = collect(World::$sent)->search(fn ($r) => $r instanceof CreateSubscriptionRequest);

    expect($billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($cancel)->not->toBeFalse()
        ->and($create)->toBeGreaterThan($cancel)
        ->and(World::$subscriptionsCreated)->toBe(1)
        ->and(World::$cancelled)->toBeFalse()
        ->and(World::$heldInterval)->toBe('12 months')
        // 500.00 + 20 %.
        ->and(World::$heldValue)->toBe('600.00')
        // A year from the change, never today.
        ->and((string) World::$heldStartDate)->toBe('2027-01-11')
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe(World::$subscriptionId)
        ->and(World::$subscriptionId)->toBe('sub_cm_2')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch');

    // The first renewal of the new subscription is booked at what it holds, without an
    // adjustment.
    $renewal = World::renew($billable, '2027-01-11T09:00:00+00:00');

    expect((int) $renewal->amount_gross)->toBe(60000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('replaces the subscription for the past-due reset, and the row names the new one', function (): void {
    $billable = World::failRenewal(World::subscription('none', 'pro', 2, []));

    World::change($billable, ['plan_code' => 'enterprise', 'apply_at' => 'immediate']);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(World::$subscriptionsCreated)->toBe(1)
        ->and(World::$cancelled)->toBeFalse()
        // Enterprise 200.00 + 20 %, from one period after the reset on 3 Feb.
        ->and(World::$heldValue)->toBe('240.00')
        ->and((string) World::$heldStartDate)->toBe('2026-03-03')
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_cm_2')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch');
});

it('keeps the PATCH for a change that does not move the date', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);

    World::change($billable, ['seats' => 3]);
    $billable->refresh();

    expect(World::$subscriptionsCreated)->toBe(0)
        ->and(collect(World::$sent)->contains(fn ($r) => $r instanceof CancelSubscriptionRequest))->toBeFalse()
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_cm');
});

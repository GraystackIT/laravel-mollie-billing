<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * A renewal whose recurring coupon ends before the next charge PATCHes Mollie back to the
 * full price. When that PATCH fails, the reset is queued as `pending_subscription_patch`.
 *
 * It was queued by writing back the copy of the whole meta the handler had loaded when the
 * webhook started — outside any lock. Everything another process committed in between was
 * undone: a seat change that PATCHed Mollie and wrote `seat_count` (it holds the very
 * subscription lock the reset times out on), an overage webhook clearing its slot. And the
 * final compare-and-set then found the count it started from again, and kept it.
 */

beforeEach(function (): void {
    longRunBoot();
    config()->set('mollie-billing.billable_model', TestBillable::class);
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('queues the failed coupon-expiry reset without undoing what another process wrote meanwhile', function (): void {
    $world = longRunStart('monthly', '2027-01-10 10:00:00', '34.51');

    // A recurring coupon that no longer covers the next charge: its coupon is gone.
    $meta = $world->billable->fresh()->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon'] = ['coupon_id' => 999999, 'code' => 'GONE', 'valid_until' => null];
    $world->billable->fresh()->forceFill(['subscription_meta' => $meta])->save();
    $world->billable = $world->billable->fresh();

    $seatsAtStart = $world->billable->getBillingSubscriptionMeta()['seat_count'] ?? null;
    $concurrent = false;

    // The reset PATCH: while it waits, another process commits its own write — then the
    // PATCH fails.
    $world->intercept = function (object $request) use (&$concurrent, $world): mixed {
        if ($request instanceof UpdateSubscriptionRequest && ! $concurrent) {
            $concurrent = true;

            $row = TestBillable::query()->findOrFail($world->billable->getKey());
            $meta = $row->getBillingSubscriptionMeta();
            $meta['seat_count'] = 5;
            $meta['written_by_another_process'] = true;
            $row->forceFill(['subscription_meta' => $meta])->save();

            throw new RuntimeException('Mollie is unavailable.');
        }

        return null;
    };

    longRunRenew($world);

    $meta = $world->billable->fresh()->getBillingSubscriptionMeta();

    expect($concurrent)->toBeTrue()
        ->and($seatsAtStart)->not->toBe(5)
        ->and($meta['pending_subscription_patch']['reason'] ?? null)->toBe('recurring_coupon_expiry_reset')
        ->and($meta['written_by_another_process'] ?? null)->toBeTrue()
        ->and($meta['seat_count'] ?? null)->toBe(5);
});

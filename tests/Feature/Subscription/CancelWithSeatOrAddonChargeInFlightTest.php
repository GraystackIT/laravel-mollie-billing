<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Cancelling the subscription while a seat or addon change still waits for its payment.
 *
 * A seat or addon change writes `seat_count` / `active_addon_codes` at once and only its
 * charge waits (flows.md § 9). The cancellation voids that charge — cancelled at Mollie,
 * or refunded in full if it settles anyway — so the seats and addons it was for go with
 * it, exactly as for a declined charge. They used to stay for the rest of the grace
 * period, and over a resubscribe, unpaid.
 */
beforeEach(function (): void {
    World::boot();
});

/** @return array{0: TestBillable, 1: string} the billable with its change in flight, and the payment id */
function cancelInFlightChange(array $payload): array
{
    $billable = World::subscription('none', 'pro', 2, []);

    World::change($billable, $payload, settle: null);

    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    return [$billable, $paymentId];
}

it('takes the seats and addons back when the cancel voids their charge at Mollie', function (array $payload): void {
    [$billable, $paymentId] = cancelInFlightChange($payload);

    // Written at once, as a seat or addon change is.
    expect($billable->getBillingSeatCount() + count($billable->getActiveBillingAddonCodes()))->toBe(3);

    World::$paymentStatus[$paymentId] = 'open';
    World::$cancelable[$paymentId] = true;

    app(CancelSubscription::class)->handle($billable);
    $billable->refresh();

    expect(World::$paymentStatus[$paymentId])->toBe('canceled')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([]);

    // Mollie's word on the cancelled payment changes nothing further.
    World::deliver($billable, $paymentId, 'canceled', World::$payments[$paymentId]['value'], World::$payments[$paymentId]['metadata']);
    $billable->refresh();

    expect($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([]);
})->with([
    'a seat' => [['seats' => 3]],
    'an addon' => [['addons' => ['storage']]],
]);

it('takes the seats and addons back when their charge can no longer be cancelled and is refunded on arrival', function (array $payload): void {
    [$billable, $paymentId] = cancelInFlightChange($payload);

    World::$paymentStatus[$paymentId] = 'pending';
    World::$cancelable[$paymentId] = false;

    app(CancelSubscription::class)->handle($billable);

    World::deliver($billable, $paymentId, 'paid', World::$payments[$paymentId]['value'], World::$payments[$paymentId]['metadata']);
    $billable->refresh();

    expect(World::$refunds[$paymentId] ?? [])->toHaveCount(1)
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([]);
})->with([
    'a seat' => [['seats' => 3]],
    'an addon' => [['addons' => ['storage']]],
]);

it('takes the seats and addons back when their charge had settled before the cancel and is refunded as the subscription has ended', function (array $payload): void {
    [$billable, $paymentId] = cancelInFlightChange($payload);

    // Settled at Mollie, not delivered yet: the cancel leaves the change to its webhook.
    World::$paymentStatus[$paymentId] = 'paid';

    app(CancelSubscription::class)->handle($billable);

    World::deliver($billable, $paymentId, 'paid', World::$payments[$paymentId]['value'], World::$payments[$paymentId]['metadata']);
    $billable->refresh();

    expect(World::$refunds[$paymentId] ?? [])->toHaveCount(1)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([]);
})->with([
    'a seat' => [['seats' => 3]],
    'an addon' => [['addons' => ['storage']]],
]);

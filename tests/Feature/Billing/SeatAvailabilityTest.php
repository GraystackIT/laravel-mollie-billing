<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

function billableWithSeats(int $seatCount, int $usedSeats): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $billable->forceFill([
        'subscription_meta' => [
            'seat_count' => $seatCount,
            'used_seats' => $usedSeats,
        ],
    ])->save();

    return $billable;
}

it('reports the number of available seats', function (): void {
    expect(billableWithSeats(seatCount: 5, usedSeats: 2)->getAvailableBillingSeats())->toBe(3);
});

it('never reports negative available seats when overbooked', function (): void {
    expect(billableWithSeats(seatCount: 3, usedSeats: 7)->getAvailableBillingSeats())->toBe(0);
});

it('confirms a single seat is available when capacity remains', function (): void {
    expect(billableWithSeats(seatCount: 5, usedSeats: 4)->isBillingSeatAvailable())->toBeTrue();
});

it('rejects a seat when capacity is exhausted', function (): void {
    expect(billableWithSeats(seatCount: 5, usedSeats: 5)->isBillingSeatAvailable())->toBeFalse();
});

it('checks availability for multiple seats at once', function (): void {
    $billable = billableWithSeats(seatCount: 10, usedSeats: 7);

    expect($billable->isBillingSeatAvailable(3))->toBeTrue();
    expect($billable->isBillingSeatAvailable(4))->toBeFalse();
});

it('treats a non-positive requested count as a request for one seat', function (): void {
    expect(billableWithSeats(seatCount: 5, usedSeats: 5)->isBillingSeatAvailable(0))->toBeFalse();
    expect(billableWithSeats(seatCount: 5, usedSeats: 4)->isBillingSeatAvailable(0))->toBeTrue();
});

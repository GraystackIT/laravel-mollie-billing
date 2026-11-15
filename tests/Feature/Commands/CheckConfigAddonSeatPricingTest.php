<?php

declare(strict_types=1);

/**
 * DEV-380 — what `billing:check-config` says about per-seat addon pricing.
 *
 * `seat_price_net` sits on top of `price_net`, so the base price stays required next to
 * it (0 is valid). A negative seat price or a negative `included_seats` would not fail
 * anywhere: every amount, invoice line and Mollie PATCH would faithfully compute with it.
 */
function checkConfigAddonSeatSetup(array $addon): void
{
    config()->set('mollie-billing-plans.plans.pro.allowed_addons', ['seat-addon']);
    config()->set('mollie-billing-plans.addons.seat-addon', ['name' => 'Seat Addon', 'feature_keys' => []] + $addon);
}

it('accepts an addon with a base and a per-seat price and free seats', function (): void {
    checkConfigAddonSeatSetup([
        'included_seats' => 5,
        'intervals' => ['monthly' => ['price_net' => 0, 'seat_price_net' => 200]],
    ]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('addons.seat-addon.intervals')
        ->doesntExpectOutputToContain('addons.seat-addon.included_seats');
});

it('requires price_net next to seat_price_net', function (): void {
    checkConfigAddonSeatSetup(['intervals' => ['monthly' => ['seat_price_net' => 200]]]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('addons.seat-addon.intervals.monthly.price_net is required')
        ->assertExitCode(1);
});

it('rejects a negative seat_price_net', function (): void {
    checkConfigAddonSeatSetup(['intervals' => ['monthly' => ['price_net' => 1000, 'seat_price_net' => -200]]]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('addons.seat-addon.intervals.monthly.seat_price_net must be null or a non-negative integer')
        ->assertExitCode(1);
});

it('rejects a seat_price_net that is not an integer', function (): void {
    checkConfigAddonSeatSetup(['intervals' => ['monthly' => ['price_net' => 1000, 'seat_price_net' => '2.00']]]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('addons.seat-addon.intervals.monthly.seat_price_net must be null or a non-negative integer')
        ->assertExitCode(1);
});

it('rejects a negative included_seats on an addon', function (): void {
    checkConfigAddonSeatSetup([
        'included_seats' => -1,
        'intervals' => ['monthly' => ['price_net' => 1000, 'seat_price_net' => 200]],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('addons.seat-addon.included_seats must be a non-negative integer')
        ->assertExitCode(1);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
});

it('fails when queue.connection is not a configured queue connection', function (): void {
    // A typo here passed validation and then made every single queued billing job
    // throw `InvalidArgumentException: The [xyz] queue connection has not been
    // configured` on dispatch — no retry ladder, no sweep, no overage charge.
    config()->set('mollie-billing.queue.connection', 'reddis');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('queue.connection [reddis]')
        ->assertExitCode(1);
});

it('accepts a configured queue connection', function (): void {
    config()->set('mollie-billing.queue.connection', 'sync');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('queue.connection')
        ->assertExitCode(0);
});

it('accepts an unset queue connection as the framework default', function (): void {
    config()->set('mollie-billing.queue.connection', null);
    config()->set('mollie-billing.queue.name', null);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('queue.connection')
        ->assertExitCode(0);
});

it('rejects a non-string queue name', function (): void {
    config()->set('mollie-billing.queue.name', ['billing']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('queue.name must be a string')
        ->assertExitCode(1);
});

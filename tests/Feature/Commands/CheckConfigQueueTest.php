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

it('warns that a sync queue never retries a job', function (): void {
    // On `sync` a job runs inside the request that dispatched it, and one that throws has
    // failed for good: `failed()` runs at once, `tries` and `backoff()` are never read. The
    // retry jobs then have only the scheduled passes behind them — the operator should
    // know that before the first Mollie hiccup.
    config()->set('queue.default', 'sync');
    config()->set('mollie-billing.queue.connection', null);

    expect(\Illuminate\Support\Facades\Artisan::call('billing:check-config'))->toBe(0);

    // One warning line; it says what happens and what to do.
    expect(\Illuminate\Support\Facades\Artisan::output())
        ->toContain('[sync] connection, which uses the sync driver')
        ->toContain('has failed for good at its first error')
        ->toContain('fall back to the scheduled passes')
        ->toContain('BILLING_QUEUE_CONNECTION');
});

it('warns when only the billing connection is sync, and names it', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.billing-inline', ['driver' => 'sync']);
    config()->set('mollie-billing.queue.connection', 'billing-inline');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('[billing-inline] connection, which uses the sync driver')
        ->assertExitCode(0);
});

it('does not warn about the queue on a real one', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('mollie-billing.queue.connection', 'database');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('uses the sync driver')
        ->assertExitCode(0);
});

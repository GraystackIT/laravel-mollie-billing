<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

it('nowUtc returns a CarbonImmutable in UTC', function (): void {
    $now = BillingTime::nowUtc();

    expect($now)->toBeInstanceOf(CarbonImmutable::class);
    expect($now->getTimezone()->getName())->toBe('UTC');
});

it('nowUtc is independent of app.timezone', function (): void {
    $previous = date_default_timezone_get();
    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    try {
        $now = BillingTime::nowUtc();

        expect($now->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('display returns null for null input', function (): void {
    expect(BillingTime::display(null))->toBeNull();
    expect(BillingTime::display(null, new TestBillable()))->toBeNull();
});

it('displayUtc returns null for null input', function (): void {
    expect(BillingTime::displayUtc(null))->toBeNull();
});

it('display converts to billable timezone without mutating the source', function (): void {
    $source = CarbonImmutable::create(2026, 5, 2, 23, 30, 0, 'UTC');
    $billable = new class extends TestBillable {
        public function getBillingTimezone(): string
        {
            return 'Europe/Berlin';
        }
    };

    $displayed = BillingTime::display($source, $billable);

    expect($displayed)->toBeInstanceOf(CarbonInterface::class);
    expect($displayed->getTimezone()->getName())->toBe('Europe/Berlin');
    expect($displayed->format('Y-m-d H:i'))->toBe('2026-05-03 01:30');
    // Source remains UTC.
    expect($source->getTimezone()->getName())->toBe('UTC');
    expect($source->format('Y-m-d H:i'))->toBe('2026-05-02 23:30');
});

it('display falls back to mollie-billing.billing_timezone without a billable', function (): void {
    config()->set('mollie-billing.billing_timezone', 'America/New_York');

    $source = CarbonImmutable::create(2026, 5, 2, 12, 0, 0, 'UTC');
    $displayed = BillingTime::display($source);

    expect($displayed->getTimezone()->getName())->toBe('America/New_York');
    expect($displayed->format('Y-m-d H:i'))->toBe('2026-05-02 08:00');
});

it('display falls back to UTC when no billable and no config override', function (): void {
    config()->set('mollie-billing.billing_timezone', null);

    $source = CarbonImmutable::create(2026, 5, 2, 12, 0, 0, 'UTC');
    $displayed = BillingTime::display($source);

    expect($displayed->getTimezone()->getName())->toBe('UTC');
});

it('displayUtc forces UTC regardless of input timezone or config', function (): void {
    config()->set('mollie-billing.billing_timezone', 'Europe/Berlin');

    $source = CarbonImmutable::create(2026, 5, 2, 23, 30, 0, 'Europe/Berlin');
    $displayed = BillingTime::displayUtc($source);

    expect($displayed->getTimezone()->getName())->toBe('UTC');
    expect($displayed->format('Y-m-d H:i'))->toBe('2026-05-02 21:30');
});

it('toUtc keeps a CarbonInterface in UTC under non-UTC app.timezone', function (): void {
    $previous = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    try {
        $source = CarbonImmutable::create(2026, 5, 2, 23, 30, 0, 'UTC');
        $result = BillingTime::toUtc($source);

        expect($result->getTimezone()->getName())->toBe('UTC');
        expect($result->format('Y-m-d H:i:s'))->toBe('2026-05-02 23:30:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('toUtc interprets an offset-less string as UTC under non-UTC app.timezone', function (): void {
    $previous = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    try {
        // The bug we are guarding against: Carbon::parse('2026-05-02 23:30:00')
        // would interpret it as Berlin local (= 21:30 UTC). toUtc must treat
        // an offset-less string as already UTC.
        $result = BillingTime::toUtc('2026-05-02 23:30:00');

        expect($result->getTimezone()->getName())->toBe('UTC');
        expect($result->format('Y-m-d H:i:s'))->toBe('2026-05-02 23:30:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('toUtc converts an offset-bearing string to the equivalent UTC moment', function (): void {
    // Berlin offset string with explicit +02:00 means 21:30 UTC.
    $result = BillingTime::toUtc('2026-05-02T23:30:00+02:00');

    expect($result->getTimezone()->getName())->toBe('UTC');
    expect($result->format('Y-m-d H:i:s'))->toBe('2026-05-02 21:30:00');
});

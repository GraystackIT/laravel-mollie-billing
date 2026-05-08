<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\IpGeolocation\Drivers\DbIpDriver;
use Illuminate\Support\Facades\Http;

it('returns the upper-cased country code from the DB-IP response', function (): void {
    Http::fake([
        'api.db-ip.com/*' => Http::response(['countryCode' => 'de'], 200),
    ]);

    expect((new DbIpDriver('secret-key'))->getCountry('8.8.8.8'))->toBe('DE');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/secret-key/8.8.8.8'));
});

it('uses the public free tier key when no api key is configured', function (): void {
    Http::fake([
        'api.db-ip.com/*' => Http::response(['countryCode' => 'AT'], 200),
    ]);

    expect((new DbIpDriver(null))->getCountry('8.8.8.8'))->toBe('AT');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/free/8.8.8.8'));
});

it('returns null when DB-IP responds with a non-2xx status', function (): void {
    Http::fake([
        'api.db-ip.com/*' => Http::response('rate limited', 429),
    ]);

    expect((new DbIpDriver('secret-key'))->getCountry('8.8.8.8'))->toBeNull();
});

it('returns null when the response payload has no countryCode', function (): void {
    Http::fake([
        'api.db-ip.com/*' => Http::response(['error' => 'unknown ip'], 200),
    ]);

    expect((new DbIpDriver('secret-key'))->getCountry('8.8.8.8'))->toBeNull();
});

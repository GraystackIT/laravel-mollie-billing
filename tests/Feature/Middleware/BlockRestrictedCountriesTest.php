<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use Illuminate\Support\Facades\Route;

/**
 * Stub driver returning a hard-coded country regardless of IP — keeps the test
 * deterministic and avoids hitting any external service.
 */
function fakeIpDriver(?string $country): IpGeolocationDriver
{
    return new class($country) implements IpGeolocationDriver
    {
        public function __construct(private readonly ?string $country) {}

        public function getCountry(string $ip): ?string
        {
            return $this->country;
        }
    };
}

function bindFakeIpDriver(?string $country): void
{
    app()->singleton(IpGeolocationManager::class, function ($app) use ($country) {
        $manager = new class($app) extends IpGeolocationManager
        {
            public ?string $stubCountry = null;

            public function getCountry(string $ip): ?string
            {
                return $this->stubCountry;
            }
        };
        $manager->stubCountry = $country;

        return $manager;
    });
}

beforeEach(function () {
    Route::middleware(BlockRestrictedCountries::class)
        ->get('/test-ip-block', fn () => response('OK'));

    config()->set('mollie-billing.ip_block.enabled', true);
});

it('passes through when the gate is disabled', function () {
    config()->set('mollie-billing.ip_block.enabled', false);
    bindFakeIpDriver('RU');
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['RU']);

    $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk();
});

it('blocks a request from a blocklisted country', function () {
    bindFakeIpDriver('RU');
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['RU', 'KP']);

    $response = $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8']);

    $response->assertRedirect(route('billing.blocked', ['country' => 'RU']));
});

it('lets a request from a non-blocklisted country through', function () {
    bindFakeIpDriver('AT');
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['RU']);

    $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk();
});

it('blocks a request from a country not on the allowlist', function () {
    bindFakeIpDriver('RU');
    config()->set('mollie-billing.ip_block.mode', 'allowlist');
    config()->set('mollie-billing.ip_block.countries', ['AT', 'DE']);

    $response = $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8']);

    $response->assertRedirect(route('billing.blocked', ['country' => 'RU']));
});

it('lets allowlisted countries through', function () {
    bindFakeIpDriver('AT');
    config()->set('mollie-billing.ip_block.mode', 'allowlist');
    config()->set('mollie-billing.ip_block.countries', ['AT', 'DE']);

    $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk();
});

it('passes through unknown country lookups by default', function () {
    bindFakeIpDriver(null);
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['RU']);
    config()->set('mollie-billing.ip_block.block_unknown', false);

    $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk();
});

it('blocks unknown country lookups when block_unknown is true', function () {
    bindFakeIpDriver(null);
    config()->set('mollie-billing.ip_block.mode', 'allowlist');
    config()->set('mollie-billing.ip_block.countries', ['AT']);
    config()->set('mollie-billing.ip_block.block_unknown', true);

    $response = $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '8.8.8.8']);

    $response->assertRedirect(route('billing.blocked'));
});

it('lets private IPs through without consulting the driver', function () {
    bindFakeIpDriver('RU');
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['AT']);

    $this->call('GET', '/test-ip-block', server: ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertOk();
});

it('does not loop the blocked page back through itself', function () {
    $this->withoutVite();

    bindFakeIpDriver('RU');
    config()->set('mollie-billing.ip_block.mode', 'blocklist');
    config()->set('mollie-billing.ip_block.countries', ['RU']);

    $this->call('GET', '/billing/blocked?country=RU', server: ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk();
});

it('serves the blocked page with the country name', function () {
    $this->withoutVite();

    $response = $this->get('/billing/blocked?country=CH');

    $response->assertOk()
        ->assertSee('Switzerland', false);
});

it('serves the blocked page when no country is provided', function () {
    $this->withoutVite();

    $response = $this->get('/billing/blocked');

    $response->assertOk();
});

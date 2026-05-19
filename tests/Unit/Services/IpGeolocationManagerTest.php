<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use Illuminate\Support\Facades\Cache;

function fakeDriverReturning(?string $country, bool &$called): IpGeolocationDriver
{
    return new class($country, $called) implements IpGeolocationDriver {
        public function __construct(private readonly ?string $country, private bool &$called) {}

        public function getCountry(string $ip): ?string
        {
            $this->called = true;

            return $this->country;
        }
    };
}

beforeEach(function (): void {
    Cache::flush();
    config()->set('mollie-billing.default_billing_country', 'AT');
});

it('returns the configured fallback for empty/null IPs without calling the driver', function (): void {
    $called = false;
    app()->singleton(IpGeolocationManager::class, function ($app) use (&$called): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('null', fn () => fakeDriverReturning('DE', $called));

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'null');

    expect(app(IpGeolocationManager::class)->defaultCountryFor(null))->toBe('AT');
    expect(app(IpGeolocationManager::class)->defaultCountryFor(''))->toBe('AT');
    expect($called)->toBeFalse();
});

it('returns the fallback for private/loopback/invalid IPs', function (): void {
    $called = false;
    app()->singleton(IpGeolocationManager::class, function ($app) use (&$called): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('null', fn () => fakeDriverReturning('DE', $called));

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'null');

    expect(app(IpGeolocationManager::class)->defaultCountryFor('127.0.0.1'))->toBe('AT');
    expect(app(IpGeolocationManager::class)->defaultCountryFor('192.168.1.1'))->toBe('AT');
    expect(app(IpGeolocationManager::class)->defaultCountryFor('::1'))->toBe('AT');
    expect(app(IpGeolocationManager::class)->defaultCountryFor('not-an-ip'))->toBe('AT');
    expect($called)->toBeFalse();
});

it('caches the resolved country for the same IP', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            return 'DE';
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('test', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'test');

    $manager = app(IpGeolocationManager::class);
    expect($manager->defaultCountryFor('8.8.8.8'))->toBe('DE');
    expect($manager->defaultCountryFor('8.8.8.8'))->toBe('DE');
    expect($driver->callCount)->toBe(1);
});

it('falls back when the driver throws or returns an unknown country', function (): void {
    app()->singleton(IpGeolocationManager::class, function ($app): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('throws', fn () => new class implements IpGeolocationDriver {
            public function getCountry(string $ip): ?string
            {
                throw new \RuntimeException('boom');
            }
        });

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'throws');

    expect(app(IpGeolocationManager::class)->defaultCountryFor('8.8.8.8'))->toBe('AT');
});

it('falls back when the resolved country is not in the checkout_countries allowlist', function (): void {
    $called = false;
    app()->singleton(IpGeolocationManager::class, function ($app) use (&$called): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('us', fn () => fakeDriverReturning('US', $called));

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'us');
    config()->set('mollie-billing.checkout_countries.regions', ['EU']);

    expect(app(IpGeolocationManager::class)->defaultCountryFor('8.8.8.8'))->toBe('AT');
});

it('caches getCountry() results across calls', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            return 'DE';
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('test', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'test');

    $manager = app(IpGeolocationManager::class);
    expect($manager->getCountry('8.8.8.8'))->toBe('DE');
    expect($manager->getCountry('8.8.8.8'))->toBe('DE');
    expect($driver->callCount)->toBe(1);
});

it('caches negative lookups so a failing driver is not retried per request', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            throw new \RuntimeException('boom');
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('boom', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'boom');

    $manager = app(IpGeolocationManager::class);
    expect($manager->getCountry('8.8.8.8'))->toBeNull();
    expect($manager->getCountry('8.8.8.8'))->toBeNull();
    expect($driver->callCount)->toBe(1);
});

it('caches a null driver result so the driver is not re-asked on every call', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            return null;
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('null-returning', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'null-returning');

    $manager = app(IpGeolocationManager::class);
    expect($manager->getCountry('8.8.8.8'))->toBeNull();
    expect($manager->getCountry('8.8.8.8'))->toBeNull();
    expect($driver->callCount)->toBe(1);
});

it('normalizes lowercase driver results to upper-case before caching', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            return 'de';
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('lower', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'lower');

    $manager = app(IpGeolocationManager::class);
    expect($manager->getCountry('8.8.8.8'))->toBe('DE');
    expect($manager->getCountry('8.8.8.8'))->toBe('DE');
    expect($driver->callCount)->toBe(1);
});

it('returns null and does not consult the driver for invalid/private IPs', function (): void {
    $called = false;
    app()->singleton(IpGeolocationManager::class, function ($app) use (&$called): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('null', fn () => fakeDriverReturning('DE', $called));

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'null');

    $manager = app(IpGeolocationManager::class);
    expect($manager->getCountry(''))->toBeNull();
    expect($manager->getCountry('127.0.0.1'))->toBeNull();
    expect($manager->getCountry('192.168.1.1'))->toBeNull();
    expect($manager->getCountry('not-an-ip'))->toBeNull();
    expect($called)->toBeFalse();
});

it('shares the cache between defaultCountryFor() and getCountry()', function (): void {
    $driver = new class implements IpGeolocationDriver {
        public int $callCount = 0;

        public function getCountry(string $ip): ?string
        {
            $this->callCount++;

            return 'DE';
        }
    };

    app()->singleton(IpGeolocationManager::class, function ($app) use ($driver): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('test', fn () => $driver);

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'test');

    $manager = app(IpGeolocationManager::class);
    expect($manager->defaultCountryFor('8.8.8.8'))->toBe('DE');
    expect($manager->getCountry('8.8.8.8'))->toBe('DE');
    expect($manager->defaultCountryFor('8.8.8.8'))->toBe('DE');
    expect($driver->callCount)->toBe(1);
});

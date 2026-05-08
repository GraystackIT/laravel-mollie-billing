<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\DbIpDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\IpInfoLiteDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\NullDriver;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Manager;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class IpGeolocationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('mollie-billing.ip_geolocation.driver', 'null');
    }

    public function createIpinfoLiteDriver(): IpGeolocationDriver
    {
        $token = $this->config->get('mollie-billing.ip_geolocation.drivers.ipinfo_lite.token');

        return new IpInfoLiteDriver($token !== null ? (string) $token : null);
    }

    public function createDbIpDriver(): IpGeolocationDriver
    {
        $apiKey = $this->config->get('mollie-billing.ip_geolocation.drivers.db_ip.api_key');

        return new DbIpDriver($apiKey !== null ? (string) $apiKey : null);
    }

    public function createNullDriver(): IpGeolocationDriver
    {
        return new NullDriver();
    }

    public function getCountry(string $ip): ?string
    {
        return $this->driver()->getCountry($ip);
    }

    /**
     * Sentinel value cached when a lookup yields no usable country, so we don't
     * re-hit the driver on every page load. `Cache::remember` does not persist
     * raw nulls; this string round-trips through the cache safely.
     */
    private const NEGATIVE_CACHE_SENTINEL = '_';

    /**
     * Resolve a UX default country for the given client IP.
     *
     * Cached per IP for 24h on success and for 1h on failure (negative cache).
     * Returns the configured fallback when the IP is empty/private, the driver
     * answers null/throws, or the resolved country is not in the country
     * selector allowlist.
     */
    public function defaultCountryFor(?string $ip): string
    {
        $fallback = (string) $this->config->get('mollie-billing.default_billing_country', 'AT');

        if ($ip === null || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || IpUtils::isPrivateIp($ip)) {
            return $fallback;
        }

        $cacheKey = "billing:ip:country:{$ip}";
        $cached = Cache::get($cacheKey);

        if ($cached === self::NEGATIVE_CACHE_SENTINEL) {
            return $fallback;
        }

        if (is_string($cached) && $cached !== '') {
            $allowed = array_keys(CountryResolver::resolve());

            return in_array($cached, $allowed, true) ? $cached : $fallback;
        }

        try {
            $resolved = $this->getCountry($ip);
        } catch (Throwable) {
            $resolved = null;
        }

        if ($resolved === null || $resolved === '') {
            Cache::put($cacheKey, self::NEGATIVE_CACHE_SENTINEL, now()->addHour());

            return $fallback;
        }

        $resolved = strtoupper($resolved);
        Cache::put($cacheKey, $resolved, now()->addDay());

        $allowed = array_keys(CountryResolver::resolve());

        return in_array($resolved, $allowed, true) ? $resolved : $fallback;
    }
}

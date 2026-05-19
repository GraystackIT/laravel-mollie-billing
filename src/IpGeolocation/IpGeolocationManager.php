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

    /**
     * Sentinel value cached when a lookup yields no usable country, so we don't
     * re-hit the driver on every page load. `Cache::remember` does not persist
     * raw nulls; this string round-trips through the cache safely.
     */
    private const NEGATIVE_CACHE_SENTINEL = '_';

    /**
     * Resolve the country for a client IP via the active driver, cached.
     *
     * Cached per IP for 24h on success and for 1h on failure (negative cache).
     * Returns null for empty/private/invalid IPs and when the driver answers
     * null or throws. Result is upper-cased ISO 3166-1 alpha-2.
     *
     * This is the single entry point both the UX default-country resolver and
     * the country-block middleware go through, so both share the same cache.
     */
    public function getCountry(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || IpUtils::isPrivateIp($ip)) {
            return null;
        }

        $cacheKey = "billing:ip:country:{$ip}";
        $cached = Cache::get($cacheKey);

        if ($cached === self::NEGATIVE_CACHE_SENTINEL) {
            return null;
        }

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $resolved = $this->driver()->getCountry($ip);
        } catch (Throwable) {
            $resolved = null;
        }

        if ($resolved === null || $resolved === '') {
            Cache::put($cacheKey, self::NEGATIVE_CACHE_SENTINEL, now()->addHour());

            return null;
        }

        $resolved = strtoupper($resolved);
        Cache::put($cacheKey, $resolved, now()->addDay());

        return $resolved;
    }

    /**
     * Resolve a UX default country for the given client IP.
     *
     * Thin wrapper over getCountry() that applies the country-selector
     * allowlist and returns the configured fallback when no usable country
     * can be determined.
     */
    public function defaultCountryFor(?string $ip): string
    {
        $fallback = (string) $this->config->get('mollie-billing.default_billing_country', 'AT');

        if ($ip === null) {
            return $fallback;
        }

        $resolved = $this->getCountry($ip);

        if ($resolved === null) {
            return $fallback;
        }

        $allowed = array_keys(CountryResolver::resolve());

        return in_array($resolved, $allowed, true) ? $resolved : $fallback;
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use Illuminate\Http\Request;

/**
 * Blocks requests originating from disallowed countries based on the
 * 'ip_block' config block. Resolves the client IP's country via the
 * IpGeolocationManager and short-circuits to the static blocked page when the
 * country fails the gate. Webhook routes are never wrapped — see
 * MollieBillingServiceProvider::registerMiddleware().
 */
class BlockRestrictedCountries
{
    public function __construct(private readonly IpGeolocationManager $geolocation) {}

    public function handle(Request $request, Closure $next)
    {
        $config = (array) config('mollie-billing.ip_block', []);

        if (! ($config['enabled'] ?? false)) {
            return $next($request);
        }

        if ($request->routeIs('billing.blocked')) {
            return $next($request);
        }

        $mode = (string) ($config['mode'] ?? 'blocklist');
        $countries = array_map('strtoupper', (array) ($config['countries'] ?? []));
        $blockUnknown = (bool) ($config['block_unknown'] ?? false);

        $ip = $request->ip();
        $country = $ip !== null ? $this->geolocation->getCountry($ip) : null;

        if ($country === null) {
            if ($blockUnknown) {
                return $this->redirectToBlocked($request, null);
            }

            return $next($request);
        }

        $isListed = in_array($country, $countries, true);
        $blocked = $mode === 'allowlist' ? ! $isListed : $isListed;

        if ($blocked) {
            return $this->redirectToBlocked($request, $country);
        }

        return $next($request);
    }

    private function redirectToBlocked(Request $request, ?string $country)
    {
        return redirect()->route('billing.blocked', $country !== null ? ['country' => $country] : []);
    }
}

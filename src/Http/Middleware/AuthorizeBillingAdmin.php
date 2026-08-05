<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use Illuminate\Http\Request;

class AuthorizeBillingAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user instanceof AuthorizesBillingAdmin || ! $user->canAccessBillingAdmin()) {
            abort(403);
        }

        // By default the admin panel follows the application locale, so a header
        // language switcher (config `header_components`) applies here too. Apps
        // that want operator tooling pinned to one working language set
        // `admin_locale` — that locale then drives __(), <html lang> and Flux's
        // own built-in strings for the whole request.
        $locale = config('mollie-billing.admin_locale');

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}

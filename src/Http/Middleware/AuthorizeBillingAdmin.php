<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use Illuminate\Http\Request;

class AuthorizeBillingAdmin
{
    /** Request attribute set once this middleware has admitted an operator. */
    public const CONTEXT_ATTRIBUTE = 'mollie_billing_admin_context';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user instanceof AuthorizesBillingAdmin || ! $user->canAccessBillingAdmin()) {
            abort(403);
        }

        // Marks the rest of this request as operator work, for BillingAuditActor.
        //
        // The actor used to be derived from the current route name, which holds for the
        // initial page load and for nothing after it: an admin panel built on Livewire
        // answers every later button from `/livewire/update`, so each mutation an operator
        // made was filed as if the customer had made it. This middleware is in Livewire's
        // persistent list precisely because it must run on those requests too, which makes
        // it the one place that reliably knows.
        $request->attributes->set(self::CONTEXT_ATTRIBUTE, true);

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

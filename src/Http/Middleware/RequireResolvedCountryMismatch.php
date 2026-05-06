<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Http\Request;

/**
 * Blocks new bookings (checkout, plan-change, addons, seats, …) while a
 * country-mismatch is still Pending for the resolved billable. The user is
 * redirected to the dashboard, where the self-service correction modal is
 * available. The dashboard route itself is whitelisted so the user can reach
 * the modal in the first place.
 */
class RequireResolvedCountryMismatch
{
    public function handle(Request $request, Closure $next)
    {
        $billable = MollieBilling::resolveBillable($request);

        if ($billable === null) {
            return $next($request);
        }

        if (! $billable->hasOpenCountryMismatch()) {
            return $next($request);
        }

        $urlParams = MollieBilling::resolveUrlParameters($billable);

        return redirect()->route(BillingRoute::name('index'), $urlParams)
            ->with('billing_status', __('billing::portal.country_mismatch.middleware_block'));
    }
}

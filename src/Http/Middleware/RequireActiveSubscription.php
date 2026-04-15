<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Illuminate\Http\Request;

class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $billable = MollieBilling::resolveBillable($request);

        if ($request->routeIs('billing.*')) {
            return $next($request);
        }

        if ($billable === null) {
            return $next($request);
        }

        if ($billable->isBillingPastDue()) {
            return redirect()->route('billing.index');
        }

        if (! $billable->hasAccessibleBillingSubscription()) {
            return redirect()->route(config('mollie-billing.checkout_route', 'billing.index'));
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Http\Request;

class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $billable = MollieBilling::resolveBillable($request);

        if ($request->routeIs(BillingRoute::name('*'))) {
            return $next($request);
        }

        if ($billable === null) {
            return $next($request);
        }

        $urlParams = MollieBilling::resolveUrlParameters($billable);

        if ($billable->isBillingPastDue()) {
            return redirect()->route(BillingRoute::name('index'), $urlParams);
        }

        if (! $billable->hasAccessibleBillingSubscription()) {
            return redirect()->route(config('mollie-billing.checkout_route', BillingRoute::name('index')), $urlParams);
        }

        return $next($request);
    }
}

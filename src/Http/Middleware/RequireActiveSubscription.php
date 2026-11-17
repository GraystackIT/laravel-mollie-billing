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

        // An unpaid renewal sends the customer to the portal to settle it. A customer who
        // paid the plan and owes only overage is let through — see
        // HasBilling::hasAccessibleBillingSubscription().
        if ($billable->isBillingPastDue() && ! $billable->hasAccessibleBillingSubscription()) {
            return redirect()->route(BillingRoute::name('index'), $urlParams);
        }

        if (! $billable->hasAccessibleBillingSubscription()) {
            return redirect()->route(BillingRoute::checkout(), $urlParams);
        }

        return $next($request);
    }
}

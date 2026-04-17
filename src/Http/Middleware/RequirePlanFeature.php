<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Http\Request;

class RequirePlanFeature
{
    public function handle(Request $request, Closure $next, string ...$features)
    {
        $billable = MollieBilling::resolveBillable($request);

        if ($billable !== null && MollieBilling::features()->hasAny($billable, $features)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Upgrade required.'], 403);
        }

        return redirect()->route(BillingRoute::name('index'))->with('billing.status', 'upgrade_required');
    }
}

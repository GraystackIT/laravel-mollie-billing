<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Illuminate\Http\Request;

class AuthorizeBillingPortal
{
    public function handle(Request $request, Closure $next)
    {
        $billable = MollieBilling::resolveBillable($request);

        if ($billable === null || ! MollieBilling::authorizes($request, $billable)) {
            abort(403);
        }

        return $next($request);
    }
}

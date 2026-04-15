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

        return $next($request);
    }
}

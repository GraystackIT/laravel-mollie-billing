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

        // Admin panel is operator tooling — pin locale to English for the whole
        // request so __() resolves against en/* files, <html lang> is "en" (which
        // suppresses Chrome's auto-translate prompt that would otherwise machine-
        // translate the panel into the user's browser language), and Flux's own
        // built-in strings render in English too.
        app()->setLocale('en');

        return $next($request);
    }
}

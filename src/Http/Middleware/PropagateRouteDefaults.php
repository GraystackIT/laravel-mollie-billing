<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Copies the current route's parameters into URL::defaults so that calls to
 * route('billing.plan') inside package views resolve any wrapping prefix
 * parameters (e.g. a tenant slug like {organization:slug}) without the
 * consuming app having to wire up URL::defaults itself.
 */
class PropagateRouteDefaults
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        if ($route !== null) {
            $defaults = [];

            foreach ($route->parameters() as $name => $value) {
                if (is_object($value)) {
                    $key = $route->bindingFieldFor($name);
                    $defaults[$name] = $key !== null && isset($value->{$key})
                        ? $value->{$key}
                        : (method_exists($value, 'getRouteKey') ? $value->getRouteKey() : $value);
                } else {
                    $defaults[$name] = $value;
                }
            }

            if ($defaults !== []) {
                URL::defaults($defaults);
            }
        }

        return $next($request);
    }
}

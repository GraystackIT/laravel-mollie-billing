<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

class BillingRoute
{
    protected static ?string $dashboardPrefix = null;

    protected static ?string $checkoutPrefix = null;

    protected static ?string $adminPrefix = null;

    /** Resolve the full route name for a dashboard/portal route. */
    public static function name(string $suffix): string
    {
        return self::dashboardPrefix().$suffix;
    }

    /** Resolve the full route name for an admin route. */
    public static function admin(string $suffix): string
    {
        return self::adminPrefix().$suffix;
    }

    /** Resolve the full route name for the checkout route. */
    public static function checkout(string $suffix = 'checkout'): string
    {
        return self::checkoutPrefix().$suffix;
    }

    /**
     * Detect the actual dashboard prefix by looking up the anchor route 'billing.index'.
     *
     * The route files register e.g. ->name('billing.index'). If mounted inside
     * Route::name('tenant.'), it becomes 'tenant.billing.index'. We find it
     * and strip the known suffix to get the real prefix.
     */
    private static function dashboardPrefix(): string
    {
        if (self::$dashboardPrefix !== null) {
            return self::$dashboardPrefix;
        }

        foreach (app('router')->getRoutes()->getRoutesByName() as $name => $route) {
            if (str_ends_with($name, 'billing.index')) {
                return self::$dashboardPrefix = substr($name, 0, -strlen('index'));
            }
        }

        return self::$dashboardPrefix = 'billing.';
    }

    private static function adminPrefix(): string
    {
        if (self::$adminPrefix !== null) {
            return self::$adminPrefix;
        }

        foreach (app('router')->getRoutes()->getRoutesByName() as $name => $route) {
            if (str_ends_with($name, 'billing.admin.dashboard')) {
                return self::$adminPrefix = substr($name, 0, -strlen('dashboard'));
            }
        }

        return self::$adminPrefix = 'billing.admin.';
    }

    private static function checkoutPrefix(): string
    {
        if (self::$checkoutPrefix !== null) {
            return self::$checkoutPrefix;
        }

        foreach (app('router')->getRoutes()->getRoutesByName() as $name => $route) {
            if (str_ends_with($name, 'billing.checkout')) {
                return self::$checkoutPrefix = substr($name, 0, -strlen('checkout'));
            }
        }

        return self::$checkoutPrefix = 'billing.';
    }

    /** Reset cached prefixes (for testing). */
    public static function flush(): void
    {
        self::$dashboardPrefix = null;
        self::$checkoutPrefix = null;
        self::$adminPrefix = null;
    }
}

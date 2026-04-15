<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling;

use Closure;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\MollieBillingFake;
use Illuminate\Http\Request;

class MollieBilling
{
    /** @var Closure(Request, Billable): bool|null */
    protected static ?Closure $authCallback = null;

    /** @var Closure(Request): ?Billable|null */
    protected static ?Closure $resolveBillableCallback = null;

    /** @var Closure(Billable): iterable|null */
    protected static ?Closure $notifyBillingAdminsCallback = null;

    /** @var Closure(): iterable|null */
    protected static ?Closure $notifyAdminCallback = null;

    /** @var Closure(string): ?string|null */
    protected static ?Closure $ipGeolocationCallback = null;

    protected static ?MollieBillingFake $fake = null;

    public static function authUsing(Closure $callback): void
    {
        self::$authCallback = $callback;
    }

    public static function resolveBillableUsing(Closure $callback): void
    {
        self::$resolveBillableCallback = $callback;
    }

    public static function notifyBillingAdminsUsing(Closure $callback): void
    {
        self::$notifyBillingAdminsCallback = $callback;
    }

    public static function notifyAdminUsing(Closure $callback): void
    {
        self::$notifyAdminCallback = $callback;
    }

    public static function authorizes(Request $request, Billable $billable): bool
    {
        if (self::$authCallback === null) {
            return false;
        }

        return (bool) (self::$authCallback)($request, $billable);
    }

    public static function resolveBillable(Request $request): ?Billable
    {
        if (self::$resolveBillableCallback === null) {
            return null;
        }

        return (self::$resolveBillableCallback)($request);
    }

    /** @return iterable<int, mixed> */
    public static function notifyBillingAdmins(Billable $billable): iterable
    {
        return self::$notifyBillingAdminsCallback
            ? (self::$notifyBillingAdminsCallback)($billable)
            : [];
    }

    /** @return iterable<int, mixed> */
    public static function notifyAdmin(): iterable
    {
        return self::$notifyAdminCallback
            ? (self::$notifyAdminCallback)()
            : [];
    }

    public static function ipGeolocation(): IpGeolocationManager
    {
        return app(IpGeolocationManager::class);
    }

    public static function features(): FeatureAccess
    {
        return app(FeatureAccess::class);
    }

    public static function coupons(): CouponService
    {
        return app(CouponService::class);
    }

    public static function subscriptions(): UpdateSubscription
    {
        return app(UpdateSubscription::class);
    }

    public static function preview(): PreviewService
    {
        return app(PreviewService::class);
    }

    public static function refunds(): RefundInvoiceService
    {
        return app(RefundInvoiceService::class);
    }

    public static function fake(): MollieBillingFake
    {
        return self::$fake ??= new MollieBillingFake;
    }

    public static function resetFake(): void
    {
        self::$fake = null;
    }

    /**
     * Tenant-scoped routes (webhook, promotion, customer portal). Mount inside the route group
     * that carries auth + tenant resolution middleware. Does NOT include the admin panel —
     * register those separately via `adminRoutes()` so they can run under different middleware
     * (e.g. without tenant scoping).
     */
    public static function routes(): void
    {
        require __DIR__.'/../routes/web.php';
    }

    /**
     * Admin-panel routes (`/billing/admin/*`). Mount inside a route group that authorizes
     * staff access — the package's `AuthorizeBillingAdmin` middleware is already applied
     * inside the group. Keep this separate from `routes()` so admins do not need a tenant
     * context to reach the panel.
     */
    public static function adminRoutes(): void
    {
        require __DIR__.'/../routes/admin.php';
    }
}

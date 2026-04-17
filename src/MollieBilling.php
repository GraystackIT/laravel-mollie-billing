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
use GraystackIT\MollieBilling\Support\BillingRoute;
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

    /** @var Closure(array): Billable|null */
    protected static ?Closure $createBillableCallback = null;

    /** @var Closure(Billable): ?string|null */
    protected static ?Closure $beforeCheckoutCallback = null;

    /** @var Closure(Billable, bool): void|null */
    protected static ?Closure $afterCheckoutCallback = null;

    /** @var Closure(): array<int, array{key:string, label:string, headline:string, description:string, view:string, validate?:Closure}>|null */
    protected static ?Closure $checkoutStepsCallback = null;

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

    // ── Checkout callbacks ──

    public static function createBillableUsing(Closure $callback): void
    {
        self::$createBillableCallback = $callback;
    }

    public static function beforeCheckoutUsing(Closure $callback): void
    {
        self::$beforeCheckoutCallback = $callback;
    }

    public static function afterCheckoutUsing(Closure $callback): void
    {
        self::$afterCheckoutCallback = $callback;
    }

    public static function checkoutStepsUsing(Closure $callback): void
    {
        self::$checkoutStepsCallback = $callback;
    }

    /** @return array<int, array{key:string, label:string, headline:string, description:string, view:string, validate?:Closure}> */
    public static function resolveCheckoutSteps(): array
    {
        return self::$checkoutStepsCallback ? (self::$checkoutStepsCallback)() : [];
    }

    public static function createBillable(array $data): Billable
    {
        if (self::$createBillableCallback === null) {
            throw new \RuntimeException(
                'No createBillable callback registered. Call MollieBilling::createBillableUsing() in your AppServiceProvider.',
            );
        }

        return (self::$createBillableCallback)($data);
    }

    public static function runBeforeCheckout(Billable $billable): ?string
    {
        if (self::$beforeCheckoutCallback === null) {
            return null;
        }

        return (self::$beforeCheckoutCallback)($billable);
    }

    public static function runAfterCheckout(Billable $billable, bool $success): void
    {
        if (self::$afterCheckoutCallback !== null) {
            (self::$afterCheckoutCallback)($billable, $success);
        }
    }

    public static function checkoutUrl(?string $backUrl = null, ?string $plan = null, ?string $interval = null): string
    {
        $params = [];
        if ($backUrl !== null) {
            $params['back'] = $backUrl;
        }
        if ($plan !== null) {
            $params['plan'] = $plan;
        }
        if ($interval !== null) {
            $params['interval'] = $interval;
        }

        return route(BillingRoute::checkout(), $params);
    }

    // ── Service accessors ──

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
     * that carries auth + tenant resolution middleware. Does NOT include the checkout or admin
     * panel — register those separately via `checkoutRoutes()` / `adminRoutes()` so they can
     * run under different middleware (e.g. without tenant scoping).
     */
    public static function dashboardRoutes(): void
    {
        require __DIR__.'/../routes/web.php';
    }

    /**
     * Checkout route (`/billing/checkout`). Mount inside a route group that carries auth
     * middleware but NOT tenant resolution — the checkout creates the billable (tenant)
     * as part of its flow, so no tenant context exists yet.
     */
    public static function checkoutRoutes(): void
    {
        require __DIR__.'/../routes/checkout.php';
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

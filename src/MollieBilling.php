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
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
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

    /** @var Closure(?Billable): array<string, mixed>|null */
    protected static ?Closure $urlParametersCallback = null;

    /** @var Closure(Billable): void|null */
    protected static ?Closure $cleanupOrphanedBillableCallback = null;

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
        if (self::$resolveBillableCallback !== null) {
            $billable = (self::$resolveBillableCallback)($request);

            if ($billable !== null) {
                return $billable;
            }
        }

        // Fallback: when no tenant-resolution middleware ran for this request
        // (e.g. the checkout route is mounted outside any tenant prefix), look
        // up the billable directly from the request's route or query parameters
        // using the billable model's `getRouteKeyName()` as the matching column.
        // This lets `?organization=lev-jimenez` resolve a billable on the
        // checkout route without forcing the host app to mount custom
        // middleware on the package's routes.
        return self::resolveBillableFromRequestParameters($request);
    }

    /**
     * Reserved query parameters used by package routes that must NEVER be
     * interpreted as billable-model lookup values.
     */
    private const RESERVED_QUERY_KEYS = ['back', 'plan', 'interval', 'redirect', 'token'];

    private static function resolveBillableFromRequestParameters(Request $request): ?Billable
    {
        $modelClass = config('mollie-billing.billable_model');
        if (! is_string($modelClass) || $modelClass === '' || ! class_exists($modelClass)) {
            return null;
        }

        $model = app($modelClass);
        if (! $model instanceof \Illuminate\Database\Eloquent\Model || ! $model instanceof Billable) {
            return null;
        }

        // Route parameters first — a route-bound model takes precedence and
        // is already an instance.
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Billable) {
                return $value;
            }
        }

        $key = $model->getRouteKeyName();
        $query = $request->query();
        if (! is_array($query)) {
            return null;
        }

        foreach ($query as $name => $value) {
            if (! is_string($name) || in_array($name, self::RESERVED_QUERY_KEYS, true)) {
                continue;
            }
            if (! is_string($value) || $value === '') {
                continue;
            }
            $found = $model->newQuery()->where($key, $value)->first();
            if ($found instanceof Billable) {
                return $found;
            }
        }

        return null;
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

    public static function urlParametersUsing(Closure $callback): void
    {
        self::$urlParametersCallback = $callback;
    }

    /** @return array<string, mixed> */
    public static function resolveUrlParameters(?Billable $billable = null): array
    {
        if (self::$urlParametersCallback === null) {
            return [];
        }

        return (self::$urlParametersCallback)($billable);
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

    /**
     * Register a closure that performs the actual deletion when
     * CleanupOrphanedBillablesJob identifies an abandoned-checkout billable.
     *
     * The closure receives the Billable and is responsible for cascading
     * cleanup (e.g. removing related users, tenants, organizations). When no
     * closure is registered the package falls back to `$billable->delete()`.
     */
    public static function cleanupOrphanedBillableUsing(Closure $callback): void
    {
        self::$cleanupOrphanedBillableCallback = $callback;
    }

    public static function runCleanupOrphanedBillable(Billable $billable): void
    {
        if (self::$cleanupOrphanedBillableCallback !== null) {
            (self::$cleanupOrphanedBillableCallback)($billable);

            return;
        }

        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $billable->delete();
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

    public static function orders(): StartOneTimeOrderCheckout
    {
        return app(StartOneTimeOrderCheckout::class);
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
     * Tenant-scoped portal routes (dashboard, plan, invoices, return). Mount inside the
     * route group that carries auth + tenant resolution middleware.
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
     * Webhook route (`POST /billing/webhook`). Mount in `routes/api.php` or a
     * group WITHOUT the `web` middleware — Mollie calls this server-to-server
     * with no session, no CSRF token, and no tenant slug.
     */
    public static function webhookRoutes(): void
    {
        require __DIR__.'/../routes/webhook.php';
    }

    /**
     * Promotion route (`/promotion/{token}`). Mount WITHOUT tenant scoping —
     * the token identifies the promotion, no tenant context is needed.
     */
    public static function promotionRoutes(): void
    {
        require __DIR__.'/../routes/promotion.php';
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

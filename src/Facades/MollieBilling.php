<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Facades;

use GraystackIT\MollieBilling\MollieBilling as MollieBillingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void authUsing(\Closure $callback)
 * @method static void resolveBillableUsing(\Closure $callback)
 * @method static void notifyBillingAdminsUsing(\Closure $callback)
 * @method static void notifyAdminUsing(\Closure $callback)
 * @method static bool authorizes(\Illuminate\Http\Request $request, \GraystackIT\MollieBilling\Contracts\Billable $billable)
 * @method static ?\GraystackIT\MollieBilling\Contracts\Billable resolveBillable(\Illuminate\Http\Request $request)
 * @method static iterable notifyBillingAdmins(\GraystackIT\MollieBilling\Contracts\Billable $billable)
 * @method static iterable notifyAdmin()
 * @method static \GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager ipGeolocation()
 * @method static \GraystackIT\MollieBilling\Features\FeatureAccess features()
 * @method static \GraystackIT\MollieBilling\Services\Billing\CouponService coupons()
 * @method static \GraystackIT\MollieBilling\Services\Billing\UpdateSubscription subscriptions()
 * @method static \GraystackIT\MollieBilling\Services\Billing\PreviewService preview()
 * @method static \GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService refunds()
 * @method static \GraystackIT\MollieBilling\Testing\MollieBillingFake fake()
 * @method static void routes()
 *
 * @see \GraystackIT\MollieBilling\MollieBilling
 */
class MollieBilling extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MollieBillingManager::class;
    }
}

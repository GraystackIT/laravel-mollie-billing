<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PromotionController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $coupon = app(CouponService::class)->resolveByAutoApplyToken($token);

        if ($coupon && $coupon->isWithinValidity(now()) && $coupon->hasGlobalRedemptionsLeft()) {
            session(['billing.auto_apply_coupon' => $coupon->code]);
        } else {
            session()->flash('billing.promotion_status', 'expired_or_invalid');
        }

        return redirect()->route(BillingRoute::checkout());
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PromotionController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $coupon = app(CouponService::class)->resolveByAutoApplyToken($token);

        if ($coupon && $coupon->isWithinValidity(BillingTime::nowUtc()) && $coupon->hasGlobalRedemptionsLeft()) {
            session([StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY => $coupon->code]);
        } else {
            // An expired or exhausted link also retires whatever an earlier link left
            // behind — the customer followed THIS one, and it carries no discount.
            session()->forget(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY);

            // Read by the checkout layout. Under `billing.promotion_status` nothing read it,
            // so a customer following an expired promo link arrived at the full price with
            // no hint that the code had not applied.
            session()->flash('billing_status', __('billing::checkout.promotion_expired'));
        }

        return redirect()->route(
            BillingRoute::checkout(),
            MollieBilling::resolveUrlParameters(),
        );
    }
}

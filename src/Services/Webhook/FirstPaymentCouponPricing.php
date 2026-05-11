<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class FirstPaymentCouponPricing
{
    public function __construct(
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CouponService $couponService,
    ) {
    }

    /**
     * Validate the first-payment coupon and pre-compute its discount, but do
     * NOT consume the redemption yet. The redemption must happen only after
     * the new Mollie subscription has been created — otherwise the coupon
     * could be permanently consumed against a subscription that never came
     * into existence.
     *
     * @param  array<int, string>  $addonCodes
     * @return array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}|null
     */
    public function price(
        Billable $billable,
        string $couponCode,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): ?array {
        $couponCode = trim($couponCode);
        if ($couponCode === '') {
            return null;
        }

        $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);
        $orderAmountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addonCodes);

        try {
            $coupon = $this->couponService->validate($couponCode, $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'addonCodes' => $addonCodes,
                'orderAmountNet' => $orderAmountNet,
                'allowed_types' => [
                    CouponType::SinglePayment,
                    CouponType::Recurring,
                    CouponType::TrialExtension,
                    CouponType::AccessGrant,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('First-payment coupon validation failed', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $discount = 0;
        if (in_array($coupon->type, [
            CouponType::SinglePayment,
            CouponType::Recurring,
        ], true)) {
            $discount = $this->couponService->computeRecurringDiscount($coupon, $orderAmountNet);
        }

        return [
            'coupon' => $coupon,
            'discount_net' => $discount,
            'recurring_discount_net' => $coupon->type === CouponType::Recurring ? $discount : 0,
            'order_amount_net' => $orderAmountNet,
        ];
    }

    /**
     * Consume the redemption for a coupon previously validated via price().
     * Call only after the new subscription has been created.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $priced
     */
    public function redeem(
        Billable $billable,
        array $priced,
        string $planCode,
        string $interval,
        int $invoiceId,
    ): void {
        try {
            $this->couponService->redeem($priced['coupon'], $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'orderAmountNet' => $priced['order_amount_net'],
                'discount_amount_net' => $priced['discount_net'],
                'invoice_id' => $invoiceId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('First-payment coupon redemption failed', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => (string) $priced['coupon']->code,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

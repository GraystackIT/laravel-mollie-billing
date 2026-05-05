<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class StartOneTimeOrderCheckout
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @param  array{product_code:string,coupon_code?:?string,coupon_codes?:array<int,string>,metadata?:array}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws \InvalidArgumentException if the product code does not exist in the catalog
     * @throws \RuntimeException if the product has a zero price
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        $productCode = $request['product_code'];
        $product = $this->catalog->product($productCode);

        if ($product === []) {
            throw new \InvalidArgumentException("Unknown product code: {$productCode}");
        }

        $priceNet = $this->catalog->productPriceNet($productCode);
        if ($priceNet <= 0) {
            throw new \RuntimeException("Product \"{$productCode}\" has a zero or negative price.");
        }

        // Accept both single `coupon_code` and multi `coupon_codes` payloads.
        $couponCodes = (array) ($request['coupon_codes'] ?? []);
        if ($couponCodes === [] && ! empty($request['coupon_code'])) {
            $couponCodes = [(string) $request['coupon_code']];
        }
        $couponCodes = array_values(array_unique(array_map(
            'strtoupper',
            array_filter(array_map('trim', $couponCodes), fn (string $c) => $c !== ''),
        )));

        $netForCharge = $priceNet;
        $existingCouponIds = [];
        $remainingNet = $priceNet;
        foreach ($couponCodes as $code) {
            $coupon = $this->couponService->validate($code, $billable, [
                'productCodes' => [$productCode],
                'orderAmountNet' => $remainingNet,
                'existingCouponIds' => $existingCouponIds,
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
            // Only SinglePayment / Recurring discount coupons are accepted on
            // one-time-order purchases; other types are blocked by the allow-list.
            $discount = $this->couponService->computeRecurringDiscount($coupon, $remainingNet);
            $netForCharge = max(0, $netForCharge - $discount);
            $remainingNet = max(0, $remainingNet - $discount);
            $existingCouponIds[] = $coupon->id;
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vatResult = $this->vatService->calculate($country, $netForCharge, $billable);
        $amountGross = $vatResult['gross'];

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);
        $customMetadata = $request['metadata'] ?? [];

        $payment = Mollie::send(new CreatePaymentRequest(
            description: $this->catalog->productName($productCode) ?? $productCode,
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            redirectUrl: route(BillingRoute::name('return'), array_merge($urlParams, ['origin' => 'products'])),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: array_merge($customMetadata, [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'one_time_order',
                'product_code' => $productCode,
                'coupon_codes' => $couponCodes,
            ]),
        ));

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
        ];
    }
}

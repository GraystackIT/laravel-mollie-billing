<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\OneTimeOrderFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class OneTimeOrderHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CouponService $couponService,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');
        if ($productCode === '') {
            Log::warning('One-time order webhook with missing product_code', ['id' => $payment->id]);

            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for one-time order, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'product_code' => $productCode,
            ]);
            return;
        }

        $priceNet = $this->catalog->productPriceNet($productCode);

        $couponCodes = (array) ($metadata['coupon_codes'] ?? []);
        if ($couponCodes === [] && ! empty($metadata['coupon_code'])) {
            $couponCodes = [(string) $metadata['coupon_code']];
        }
        $couponCodes = array_values(array_unique(array_map(
            'strtoupper',
            array_filter(array_map(fn ($c) => is_string($c) ? trim($c) : '', $couponCodes), fn (string $c) => $c !== ''),
        )));

        /** @var array<int, array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}> */
        $resolvedCoupons = [];
        $totalDiscountNet = 0;
        $existingCouponIds = [];
        $remainingNet = $priceNet;
        foreach ($couponCodes as $code) {
            try {
                $coupon = $this->couponService->validate($code, $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $remainingNet,
                    'existingCouponIds' => $existingCouponIds,
                    'allowed_types' => [
                        \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    ],
                ]);
                $discount = $this->couponService->computeRecurringDiscount($coupon, $remainingNet);
                $resolvedCoupons[] = ['coupon' => $coupon, 'discount' => $discount];
                $totalDiscountNet += $discount;
                $remainingNet = max(0, $remainingNet - $discount);
                $existingCouponIds[] = $coupon->id;
            } catch (\Throwable $e) {
                Log::warning('One-time order coupon validation failed during webhook', [
                    'product_code' => $productCode,
                    'coupon_code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $lineItems = [[
            'kind' => 'one_time_order',
            'code' => $productCode,
            'label' => $this->catalog->productName($productCode) ?? $productCode,
            'quantity' => 1,
            'unit_price' => $priceNet,
            'unit_price_net' => $priceNet,
            'total_net' => $priceNet,
        ]];

        foreach ($resolvedCoupons as $entry) {
            if ($entry['discount'] > 0) {
                $lineItems[] = [
                    'kind' => 'coupon',
                    'code' => $entry['coupon']->code,
                    'label' => 'Coupon '.$entry['coupon']->code,
                    'quantity' => 1,
                    'unit_price' => -$entry['discount'],
                    'unit_price_net' => -$entry['discount'],
                    'total_net' => -$entry['discount'],
                ];
            }
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, 'one_time_order', $lineItems, $billable);

        foreach ($resolvedCoupons as $entry) {
            try {
                $this->couponService->redeem($entry['coupon'], $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $priceNet,
                    'discount_amount_net' => $entry['discount'],
                    'invoice_id' => (int) $invoice->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('One-time order coupon redemption failed', [
                    'product_code' => $productCode,
                    'coupon_code' => $entry['coupon']->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $usageType = $this->catalog->productUsageType($productCode);
        $quantity = $this->catalog->productQuantity($productCode);

        Log::info('One-time order: checking wallet credit', [
            'product_code' => $productCode,
            'usage_type' => $usageType,
            'quantity' => $quantity,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
        ]);

        if ($usageType !== null && $quantity !== null && $quantity > 0) {
            $this->walletService->credit($billable, $usageType, $quantity, 'one_time_order:'.$productCode);

            if ($billable instanceof Model) {
                $wallet = $billable->getWallet($usageType);
                if ($wallet !== null) {
                    WalletUsageService::addPurchasedBalance($wallet, $quantity);
                }
            }

            Log::info('One-time order: wallet credited', [
                'product_code' => $productCode,
                'usage_type' => $usageType,
                'quantity' => $quantity,
            ]);
        }

        event(new OneTimeOrderCompleted($billable, $invoice, $productCode, $metadata));
        event(new PaymentSucceeded($billable, $invoice));
        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');

        event(new OneTimeOrderFailed(
            $billable,
            $productCode,
            (string) ($payment->id ?? ''),
            (string) ($payment->status ?? 'unknown'),
        ));
    }
}

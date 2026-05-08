<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionCannotPurchaseProductsException;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class StartOneTimeOrderCheckout
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly CouponService $couponService,
        private readonly InvoiceService $invoiceService,
        private readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array{product_code:string,coupon_code?:?string,coupon_codes?:array<int,string>,metadata?:array}  $request
     * @return array{checkout_url:?string,payment_id:string,completed?:bool}
     *
     * @throws \InvalidArgumentException if the product code does not exist in the catalog
     * @throws \RuntimeException if the product has a zero price
     * @throws LocalSubscriptionCannotPurchaseProductsException if the billable is on a
     *         Local subscription and config('mollie-billing.local_subscription.allow_one_time_orders') is false
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        $productCode = $request['product_code'];
        $product = $this->catalog->product($productCode);

        if ($product === []) {
            throw new \InvalidArgumentException("Unknown product code: {$productCode}");
        }

        if ($billable->isLocalBillingSubscription()
            && ! (bool) config('mollie-billing.local_subscription.allow_one_time_orders', false)
        ) {
            throw new LocalSubscriptionCannotPurchaseProductsException($billable, $productCode);
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

        // Recurring is intentionally not allowed on one-time-orders: there are no
        // follow-up charges to attach the recurring marker to. Only SinglePayment
        // discounts make sense here.
        /** @var list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}> $resolvedCoupons */
        $resolvedCoupons = [];
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
                ],
            ]);
            $discount = $this->couponService->computeRecurringDiscount($coupon, $remainingNet);
            $resolvedCoupons[] = ['coupon' => $coupon, 'discount' => $discount];
            $netForCharge = max(0, $netForCharge - $discount);
            $remainingNet = max(0, $remainingNet - $discount);
            $existingCouponIds[] = $coupon->id;
        }

        // 100%-coverage: skip Mollie entirely and write a local 0-EUR audit invoice.
        // No mandate, no Mollie roundtrip — one-time-orders have no follow-up charges,
        // so there's nothing the payment provider needs to know about.
        // Note: idempotency relies on the caller's UI guard (e.g. the Livewire
        // `processing` flag). There is no BillingProcessedWebhook protection here
        // because no webhook fires for this flow.
        if ($netForCharge === 0) {
            return $this->completeInline($billable, $productCode, $priceNet, $resolvedCoupons);
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

    /**
     * Inline 0-EUR completion for one-time-orders fully covered by coupons. Mirrors
     * MollieWebhookController::handleOneTimeOrderPaid() — local invoice, redemptions,
     * wallet credit, events — but without the Mollie payment.
     *
     * @param  list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}>  $resolvedCoupons
     * @return array{checkout_url:?string,payment_id:string,completed:bool}
     */
    private function completeInline(
        Billable $billable,
        string $productCode,
        int $priceNet,
        array $resolvedCoupons,
    ): array {
        /** @var Model&Billable $billable */
        $country = (string) ($billable->getBillingCountry() ?? '');
        $vat = $this->vatService->calculate($country, $priceNet, $billable);
        $rate = (float) ($vat['rate'] ?? 0.0);

        $now = BillingTime::nowUtc();

        $productVat = (int) round($priceNet * $rate / 100);
        $lineItems = [[
            'kind' => 'one_time_order',
            'code' => $productCode,
            'label' => $this->catalog->productName($productCode) ?? $productCode,
            'quantity' => 1,
            'unit_price' => $priceNet,
            'unit_price_net' => $priceNet,
            'amount_net' => $priceNet,
            'vat_rate' => $rate,
            'vat_amount' => $productVat,
            'amount_gross' => $priceNet + $productVat,
            'period_start' => $now->toIso8601String(),
            'period_end' => $now->toIso8601String(),
        ]];

        foreach ($resolvedCoupons as $entry) {
            if ($entry['discount'] <= 0) {
                continue;
            }
            $discount = (int) $entry['discount'];
            $couponVat = (int) round($discount * $rate / 100);
            $lineItems[] = [
                'kind' => 'coupon',
                'code' => (string) $entry['coupon']->code,
                'label' => 'Coupon '.$entry['coupon']->code,
                'quantity' => 1,
                'unit_price' => -$discount,
                'unit_price_net' => -$discount,
                'amount_net' => -$discount,
                'vat_rate' => $rate,
                'vat_amount' => -$couponVat,
                'amount_gross' => -($discount + $couponVat),
                'period_start' => $now->toIso8601String(),
                'period_end' => $now->toIso8601String(),
            ];
        }

        $invoice = $this->invoiceService->createInvoice(
            billable: $billable,
            kind: InvoiceKind::OneTimeOrder,
            molliePaymentId: null,
            mollieSubscriptionId: null,
            lineItems: $lineItems,
            periodStart: $now,
            periodEnd: $now,
        );

        foreach ($resolvedCoupons as $entry) {
            try {
                $this->couponService->redeem($entry['coupon'], $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $priceNet,
                    'discount_amount_net' => (int) $entry['discount'],
                    'invoice_id' => (int) $invoice->id,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('One-time order inline coupon redemption failed', [
                    'product_code' => $productCode,
                    'coupon_code' => $entry['coupon']->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $usageType = $this->catalog->productUsageType($productCode);
        $quantity = $this->catalog->productQuantity($productCode);

        if ($usageType !== null && $quantity !== null && $quantity > 0) {
            $this->walletService->credit($billable, $usageType, $quantity, 'one_time_order:'.$productCode);

            if ($billable instanceof Model) {
                $wallet = $billable->getWallet($usageType);
                if ($wallet !== null) {
                    WalletUsageService::addPurchasedBalance($wallet, $quantity);
                }
            }
        }

        event(new OneTimeOrderCompleted($billable, $invoice, $productCode, []));
        event(new PaymentSucceeded($billable, $invoice));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new InvoiceAvailableNotification($billable, $invoice));
        }

        return [
            'checkout_url' => null,
            'payment_id' => '',
            'completed' => true,
        ];
    }
}

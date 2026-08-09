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
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

class StartOneTimeOrderCheckout
{
    /**
     * Session key holding the pending one-time-order payments as a
     * `token => mollie_payment_id` map. Mollie echoes nothing back on the
     * redirect, so the token we put into `redirectUrl` is the return page's only
     * handle on "which payment do I report on" — without it the page cannot tell
     * a completed order from a cancelled one.
     *
     * A map rather than a single id: two checkouts started in parallel (two tabs,
     * a double-click that opened two Mollie windows) would otherwise overwrite
     * each other and both return pages would judge the *last* payment — one of
     * them reporting a cancellation the customer never made, or a success they
     * never paid for.
     */
    public const PENDING_ORDER_SESSION_KEY = 'mollie-billing.pending_order_payments';

    /** Query parameter carrying the per-checkout token back from Mollie. */
    public const ORDER_TOKEN_PARAM = 'order_token';

    /**
     * Upper bound on the map. An abandoned checkout never reaches the return page,
     * so nothing would ever evict its entry; the oldest ones drop out instead of
     * growing the session cookie without limit.
     */
    private const MAX_PENDING_ORDERS = 10;

    /**
     * Look up the payment a return-page token refers to. Returns null for an
     * unknown/expired token — the caller must then report "unresolved" rather
     * than assume success.
     */
    public static function pendingOrderPaymentId(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $paymentId = ((array) session(self::PENDING_ORDER_SESSION_KEY, []))[$token] ?? null;

        return is_string($paymentId) && $paymentId !== '' ? $paymentId : null;
    }

    /**
     * Drop a token once its payment reached a terminal state. Keeps the map from
     * accumulating settled orders across a long session.
     */
    public static function forgetPendingOrder(?string $token): void
    {
        if ($token === null || $token === '' || ! app()->bound('session.store')) {
            return;
        }

        $pending = (array) session(self::PENDING_ORDER_SESSION_KEY, []);
        unset($pending[$token]);

        session([self::PENDING_ORDER_SESSION_KEY => $pending]);
    }

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
     * @return array{checkout_url:?string,payment_id:string,completed?:bool,order_token?:string}
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

        $country = $billable->getBillingCountry() ?? (string) config('mollie-billing.checkout_country_fallback', 'DE');
        $vatResult = $this->vatService->calculate($country, $netForCharge, $billable);

        // OneTimeOrderHandler books this payment as a product line plus one negative
        // line per coupon, and the invoice header sums their VAT — so the amount we
        // collect is assembled from the same lines. See
        // BillingMoney::grossWithLineVat(); rounding the discounted total instead
        // leaves the customer paying a cent more or less than the invoice states.
        $lineNets = [$priceNet];
        foreach ($resolvedCoupons as $entry) {
            if ((int) $entry['discount'] > 0) {
                $lineNets[] = -(int) $entry['discount'];
            }
        }

        $amountGross = BillingMoney::grossWithLineVat($lineNets, (float) $vatResult['rate']);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);
        $customMetadata = $request['metadata'] ?? [];

        // Unguessable per-checkout handle. It only ever indexes the customer's own
        // session map, so it grants nothing on its own — but it must not be
        // enumerable, otherwise a crafted return URL could surface another
        // checkout's outcome to whoever shares the session.
        $orderToken = Str::random(32);

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: $this->catalog->productName($productCode) ?? $productCode,
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            redirectUrl: route(BillingRoute::name('return'), array_merge($urlParams, [
                'origin' => 'products',
                self::ORDER_TOKEN_PARAM => $orderToken,
            ])),
            locale: MollieBilling::mollieLocale(),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: array_merge($customMetadata, [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'one_time_order',
                'product_code' => $productCode,
                'coupon_codes' => $couponCodes,
            ]),
        ));

        // Correlate the Mollie roundtrip with the return page. Guarded because the
        // flow is also reachable from queued/CLI callers, which have no session.
        if (request()->hasSession()) {
            $pending = (array) session(self::PENDING_ORDER_SESSION_KEY, []);
            $pending[$orderToken] = (string) $payment->id;

            if (count($pending) > self::MAX_PENDING_ORDERS) {
                $pending = array_slice($pending, -self::MAX_PENDING_ORDERS, preserve_keys: true);
            }

            request()->session()->put(self::PENDING_ORDER_SESSION_KEY, $pending);
        }

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
            'order_token' => $orderToken,
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

        // VAT is allocated from the *cumulative* discount rather than per line, so the
        // per-line roundings always add up to the product's own VAT. Rounding each
        // discount independently makes a fully-covered order book ±1 cent — an invoice
        // with amount_gross = -1 for something that cost the customer nothing.
        $discountTotal = 0;
        $vatAllocated = 0;

        foreach ($resolvedCoupons as $entry) {
            if ($entry['discount'] <= 0) {
                continue;
            }
            $discount = (int) $entry['discount'];
            $discountTotal += $discount;
            $couponVat = (int) round($discountTotal * $rate / 100) - $vatAllocated;
            $vatAllocated += $couponVat;
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

        // Invoice and redemptions in one transaction, and a refused redemption aborts the
        // whole thing.
        //
        // Inline means no money changed hands: the coupons ARE the consideration. So
        // logging a failed redeem and delivering anyway gave the goods away — two tabs on
        // a `max_redemptions_per_billable = 1` coupon both passed the lock-free validate(),
        // both wrote a 0-EUR invoice, one redeem() won the coupon lock and the other's
        // exception was swallowed, and the wallet was credited twice. Two token packs, one
        // redemption on record. Same for a `max_redemptions` race against another billable.
        //
        // The Mollie-paid path deliberately keeps logging instead: there the money really
        // cleared, so refusing to fulfil would take the customer's payment and give
        // nothing back.
        $invoice = DB::transaction(function () use (
            $billable, $productCode, $priceNet, $resolvedCoupons, $lineItems, $now
        ): BillingInvoice {
            $invoice = $this->invoiceService->createInvoice(
                billable: $billable,
                kind: InvoiceKind::OneTimeOrder,
                molliePaymentId: null,
                mollieSubscriptionId: null,
                lineItems: $lineItems,
                periodStart: $now,
                periodEnd: $now,
                // Rendered after the commit below. A refused redemption rolls the invoice
                // row back but cannot roll back a file, so the PDF outlived the invoice it
                // documented — a serial number on disk that the books have no record of.
                deferDocument: true,
            );

            foreach ($resolvedCoupons as $entry) {
                // Not caught: a redemption that cannot be recorded is a coupon that was
                // not available, and this order has nothing else paying for it.
                $this->couponService->redeem($entry['coupon'], $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $priceNet,
                    'discount_amount_net' => (int) $entry['discount'],
                    'invoice_id' => (int) $invoice->id,
                ]);
            }

            return $invoice;
        });

        // Committed — the invoice exists for good, so it may have its document.
        $this->invoiceService->issueInvoiceDocument($invoice, $billable);

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
            Notification::send($recipients, MollieBilling::resolveNotification(InvoiceAvailableNotification::class, $billable, $invoice));
        }

        return [
            'checkout_url' => null,
            'payment_id' => '',
            'completed' => true,
        ];
    }
}

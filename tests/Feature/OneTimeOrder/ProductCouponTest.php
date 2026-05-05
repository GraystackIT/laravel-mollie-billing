<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'description' => 'Tokens for your account.',
            'image_url' => null,
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
        'consulting' => [
            'name' => 'Consulting',
            'description' => null,
            'image_url' => null,
            'price_net' => 10000,
        ],
    ]);
});

function fakeProductPaymentResponse(string $id): object
{
    return new class($id) {
        public string $id;

        public function __construct(string $id)
        {
            $this->id = $id;
        }

        public function getCheckoutUrl(): string
        {
            return 'https://checkout.mollie.com/test';
        }
    };
}

it('applies a SinglePayment coupon discount to the Mollie payment amount', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'PROD20',
        'name' => 'Product 20% off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
    ]);

    $billable = TestBillable::create([
        'name' => 'Buyer',
        'email' => 'buyer@example.test',
        'billing_country' => 'AT',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->andReturnUsing(function ($request) use (&$captured) {
            $captured = $request;
            return fakeProductPaymentResponse('tr_test_1');
        });

    app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'token-pack',
        'coupon_code' => 'PROD20',
    ]);

    expect($captured)->toBeInstanceOf(CreatePaymentRequest::class);

    // 4900 net - 20% = 3920 net; +20% VAT (AT) = 4704 gross.
    $reflected = new ReflectionObject($captured);
    $amount = $reflected->getProperty('amount');
    $amount->setAccessible(true);

    expect($amount->getValue($captured)->value)->toBe('47.04');
});

it('rejects a coupon that is not in applicable_products', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'CONSULT_ONLY',
        'name' => 'Consulting only',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'applicable_products' => ['consulting'],
    ]);

    $billable = TestBillable::create([
        'name' => 'Buyer',
        'email' => 'buyer@example.test',
        'billing_country' => 'AT',
    ]);

    expect(fn () => $service->validate('CONSULT_ONLY', $billable, [
        'productCodes' => ['token-pack'],
        'orderAmountNet' => 4900,
    ]))->toThrow(InvalidCouponException::class);

    // The other product is allowed.
    $resolved = $service->validate('CONSULT_ONLY', $billable, [
        'productCodes' => ['consulting'],
        'orderAmountNet' => 10000,
    ]);
    expect($resolved->id)->toBe($coupon->id);
});

it('passes the coupon discount through to the Mollie amount and writes a CouponRedemption on webhook', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'PROD20',
        'name' => 'Product 20% off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'applicable_products' => ['token-pack'],
    ]);

    $billable = TestBillable::create([
        'name' => 'Buyer',
        'email' => 'buyer@example.test',
        'billing_country' => 'AT',
    ]);

    // Simulate the webhook payload as it would arrive from Mollie after a successful charge.
    $payment = (object) [
        'id' => 'tr_webhook_1',
        'status' => 'paid',
        'amount' => (object) ['value' => '47.04', 'currency' => 'EUR'],
        'paidAt' => now()->toIso8601String(),
        'metadata' => (object) [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'type' => 'one_time_order',
            'product_code' => 'token-pack',
            'coupon_code' => 'PROD20',
        ],
    ];

    $controller = app(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class);
    $reflection = new ReflectionMethod($controller, 'handleOneTimeOrderPaid');
    $reflection->setAccessible(true);
    $reflection->invoke(
        $controller,
        $payment,
        $billable->fresh(),
        json_decode(json_encode($payment->metadata), true),
    );

    expect(\GraystackIT\MollieBilling\Models\CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);
});

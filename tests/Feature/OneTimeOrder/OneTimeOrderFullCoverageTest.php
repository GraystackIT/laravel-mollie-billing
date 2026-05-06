<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'description' => 'Tokens for your account.',
            'image_url' => null,
            'price_net' => 5000,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
    ]);
});

it('100% SinglePayment coupon on a one-time order completes inline without a Mollie roundtrip', function (): void {
    Event::fake([OneTimeOrderCompleted::class, PaymentSucceeded::class]);

    $service = app(CouponService::class);
    $service->create([
        'code' => 'FREE100',
        'name' => 'Free Token Pack',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = TestBillable::create([
        'name' => 'Free Buyer',
        'email' => 'free@x.test',
        'billing_country' => 'AT',
    ]);

    Mollie::shouldReceive('send')->never();

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), [
        'product_code' => 'token-pack',
        'coupon_codes' => ['FREE100'],
    ]);

    expect($result['checkout_url'])->toBeNull()
        ->and($result['completed'] ?? false)->toBeTrue();

    // Local 0-EUR invoice was written.
    $invoice = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::OneTimeOrder)
        ->latest('id')
        ->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(0)
        ->and((int) $invoice->amount_net)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->mollie_payment_id)->toBeNull();

    $lineItems = (array) $invoice->line_items;
    expect($lineItems)->toHaveCount(2);

    $kinds = array_map(fn (array $line) => $line['kind'] ?? null, $lineItems);
    expect($kinds)->toContain('one_time_order')
        ->and($kinds)->toContain('coupon');

    // CouponRedemption with the full discount, linked to the local invoice.
    $redemption = CouponRedemption::query()
        ->where('billable_id', $billable->getKey())
        ->latest('id')
        ->first();
    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(5000)
        ->and((int) $redemption->invoice_id)->toBe((int) $invoice->id);

    Event::assertDispatched(OneTimeOrderCompleted::class);
    Event::assertDispatched(PaymentSucceeded::class);
});

it('partial-coverage SinglePayment coupon still routes through Mollie', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'PROD20',
        'name' => 'Product 20% off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
    ]);

    $billable = TestBillable::create([
        'name' => 'Partial Buyer',
        'email' => 'partial@x.test',
        'billing_country' => 'AT',
    ]);

    Mollie::shouldReceive('send')->once()->andReturn(new class {
        public string $id = 'tr_partial';

        public function getCheckoutUrl(): string
        {
            return 'https://checkout.mollie.com/test';
        }
    });

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), [
        'product_code' => 'token-pack',
        'coupon_codes' => ['PROD20'],
    ]);

    expect($result['checkout_url'])->toBe('https://checkout.mollie.com/test')
        ->and($result['payment_id'])->toBe('tr_partial')
        ->and($result['completed'] ?? null)->toBeNull();
});

it('rejects a Recurring coupon on a one-time order with type_not_allowed_in_context', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'REC10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = TestBillable::create([
        'name' => 'Wrong Buyer',
        'email' => 'wrong@x.test',
        'billing_country' => 'AT',
    ]);

    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), [
        'product_code' => 'token-pack',
        'coupon_codes' => ['REC10'],
    ]))->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidCouponException::class);
});

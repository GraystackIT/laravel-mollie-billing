<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    // 900 net at DE's 19%: product VAT = round(171.0) = 171. Split as 50 + 850, the
    // independent per-line roundings are round(9.5) = 10 and round(161.5) = 162 — 172 in
    // total, one cent more than the product's own VAT. That is the discrepancy that made a
    // fully-covered order book amount_gross = -1.
    config()->set('mollie-billing-plans.products.credits', [
        'name' => 'Credit pack',
        'price_net' => 900,
        'usage_type' => 'emails',
        'quantity' => 100,
    ]);
});

function zeroTotalBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Zero Co',
        'email' => 'zero-total@x.test',
        'billing_country' => 'DE',
    ]);

    return $b->refresh();
}

it('books an exactly-zero invoice when stacked coupons cover the whole order', function (): void {
    // Guard: the split above only exercises the rounding conflict at a non-zero VAT rate.
    $service = app(CouponService::class);

    $service->create([
        'code' => 'HALF',
        'name' => 'Half',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 50,
        'stackable' => true,
        'active' => true,
    ]);

    $service->create([
        'code' => 'REST',
        'name' => 'Rest',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'stackable' => true,
        'active' => true,
    ]);

    $billable = zeroTotalBillable();

    Mollie::shouldReceive('send')->never();

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'credits',
        'coupon_codes' => ['HALF', 'REST'],
    ]);

    expect($result['completed'] ?? false)->toBeTrue();

    $invoice = BillingInvoice::query()->latest('id')->firstOrFail();

    // Per-line VAT rounding used to leave amount_gross = -1 / amount_vat = -1 on an order
    // that cost the customer nothing.
    expect($invoice->amount_net)->toBe(0);
    expect($invoice->amount_vat)->toBe(0);
    expect($invoice->amount_gross)->toBe(0);

    // The line items must be internally consistent too.
    $lines = (array) $invoice->line_items;
    expect(array_sum(array_column($lines, 'amount_net')))->toBe(0);
    expect(array_sum(array_column($lines, 'vat_amount')))->toBe(0);
    expect(array_sum(array_column($lines, 'amount_gross')))->toBe(0);
});

it('books an exactly-zero invoice for a single 100% coupon', function (): void {
    app(CouponService::class)->create([
        'code' => 'ALL',
        'name' => 'All',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'active' => true,
    ]);

    $billable = zeroTotalBillable();

    Mollie::shouldReceive('send')->never();

    app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'credits',
        'coupon_code' => 'ALL',
    ]);

    $invoice = BillingInvoice::query()->latest('id')->firstOrFail();

    expect($invoice->amount_net)->toBe(0);
    expect($invoice->amount_vat)->toBe(0);
    expect($invoice->amount_gross)->toBe(0);
});

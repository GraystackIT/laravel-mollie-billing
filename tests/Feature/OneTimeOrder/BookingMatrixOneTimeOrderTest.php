<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/*
 * Booking matrix, part 5: a one-time product (Token pack, 49.50 net) with coupons —
 * amount shown on the products page == amount sent to Mollie == invoice.
 */

/** @param  array<string, mixed>  $attributes */
function bmOtoCoupon(string $code, array $attributes = [], CouponType $type = CouponType::SinglePayment): Coupon
{
    return app(CouponService::class)->create(array_merge(['code' => $code, 'name' => $code, 'type' => $type, 'active' => true], $attributes));
}

function bmOtoGross(array $lineNets, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lineNets));
}

dataset('bm oto countries', ['AT 20 %' => ['AT', 20.0], 'DE 19 %' => ['DE', 19.0]]);

it('one-time order with one coupon: shown == charged == invoiced', function (string $country, float $rate, DiscountType $type, int $value, int $discount): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    bmOtoCoupon('PACK', ['discount_type' => $type, 'discount_value' => $value]);

    $lines = ['one_time_order:pack' => 4950, 'coupon:PACK' => -$discount];

    // The products page: a price, and what the coupon takes off it.
    $page = Livewire::test('mollie-billing::products')
        ->set('couponInputs.pack', 'PACK')
        ->call('applyCoupon', 'pack');

    expect($page->get('couponErrors')['pack'] ?? null)->toBeNull();
    $view = $page->instance()->with();
    $shownPrice = collect($view['availableGroups'])->flatMap(fn ($g) => $g['products'])->firstWhere('code', 'pack')['price_net'];
    $shownTotal = $shownPrice - $view['couponDiscountsDisplay']['pack'];
    expect($shownTotal)->toBe(bmOtoGross($lines, $rate));

    $page->call('purchase', 'pack');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shownTotal);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, 4950 - $discount, bmOtoGross($lines, $rate) - (4950 - $discount), $rate, $lines);

    expect(CouponRedemption::query()->sole()->discount_amount_net)->toBe($discount)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);
    $m->assertNoMismatch();
})->with('bm oto countries')->with([
    '15 %' => [DiscountType::Percentage, 15, 743],
    'fixed 5.55' => [DiscountType::Fixed, 555, 555],
    'fixed, larger than a second product but not this one' => [DiscountType::Fixed, 4949, 4949],
]);

it('one-time order with two stacked coupons: shown == charged == invoiced', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $m->billable($country);
    bmOtoCoupon('S1', ['discount_type' => DiscountType::Fixed, 'discount_value' => 1555, 'stackable' => true]);
    bmOtoCoupon('S2', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'stackable' => true]);

    // 49.50 − 15.55 = 33.95; 15 % of that = 5.09.
    $lines = ['one_time_order:pack' => 4950, 'coupon:S1' => -1555, 'coupon:S2' => -509];

    $page = Livewire::test('mollie-billing::products')
        ->set('couponInputs.pack', 'S1')->call('applyCoupon', 'pack')
        ->set('couponInputs.pack', 'S2')->call('applyCoupon', 'pack');

    expect($page->get('appliedCouponCodes')['pack'])->toBe(['S1', 'S2']);

    $view = $page->instance()->with();
    $shownPrice = collect($view['availableGroups'])->flatMap(fn ($g) => $g['products'])->firstWhere('code', 'pack')['price_net'];
    $shownTotal = $shownPrice - $view['couponDiscountsDisplay']['pack'];

    $page->call('purchase', 'pack');
    $payment = $m->lastPayment();

    expect(BookingMatrix::minor($payment))->toBe(bmOtoGross($lines, $rate))
        ->and($shownTotal)->toBe(BookingMatrix::minor($payment));

    $m->assertInvoice($m->settle($payment, $country), 2886, bmOtoGross($lines, $rate) - 2886, $rate, $lines);
    expect(CouponRedemption::query()->count())->toBe(2);
    $m->assertNoMismatch();
})->with('bm oto countries');

it('one-time order: a coupon that is not stackable does not combine, in either order', function (string $first, string $second): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    bmOtoCoupon('STACK', ['discount_type' => DiscountType::Fixed, 'discount_value' => 500, 'stackable' => true]);
    bmOtoCoupon('ALONE', ['discount_type' => DiscountType::Fixed, 'discount_value' => 700, 'stackable' => false]);

    // The page keeps the first and refuses the second.
    $page = Livewire::test('mollie-billing::products')
        ->set('couponInputs.pack', $first)->call('applyCoupon', 'pack')
        ->set('couponInputs.pack', $second)->call('applyCoupon', 'pack');

    expect($page->get('appliedCouponCodes')['pack'])->toBe([$first])
        ->and($page->get('couponErrors')['pack'])->not->toBeNull();

    // And the service refuses the pair outright — no payment, nothing redeemed.
    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack', 'coupon_codes' => [$first, $second]]))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\CouponNotStackableException::class);

    expect($m->lastPayment())->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(BillingInvoice::query()->count())->toBe(0);
})->with([['STACK', 'ALONE'], ['ALONE', 'STACK']]);

it('one-time order fully covered by coupons: a 0.00 invoice, no Mollie call', function (string $country, float $rate, array $coupons): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    foreach ($coupons as $code => $attributes) {
        bmOtoCoupon($code, $attributes);
    }

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack', 'coupon_codes' => array_keys($coupons)]);

    expect($result['completed'] ?? false)->toBeTrue()
        ->and($result['checkout_url'])->toBeNull()
        ->and($m->sent)->toBe([]);

    $invoice = BillingInvoice::query()->sole();
    $items = collect($invoice->line_items);

    expect((int) $invoice->amount_net)->toBe(0)
        ->and((int) $invoice->amount_vat)->toBe(0)
        ->and((int) $invoice->amount_gross)->toBe(0)
        ->and((int) $items->sum('amount_net'))->toBe(0)
        ->and((int) $items->sum('vat_amount'))->toBe(0)
        ->and((int) $items->sum('amount_gross'))->toBe(0)
        ->and($invoice->mollie_payment_id)->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(count($coupons))
        ->and((int) CouponRedemption::query()->sum('discount_amount_net'))->toBe(4950)
        ->and($billable->fresh()->boughtBillingProducts())->toBe(['pack']);
})->with('bm oto countries')->with([
    'one 100 % coupon' => [['FULL' => ['discount_type' => DiscountType::Percentage, 'discount_value' => 100]]],
    'two stacked' => [[
        'A' => ['discount_type' => DiscountType::Fixed, 'discount_value' => 1555, 'stackable' => true],
        'B' => ['discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'stackable' => true],
    ]],
]);

it('one-time order: refuses a coupon that does not fit, before any payment exists', function (Closure $make, string $reason): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $make();

    try {
        app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack', 'coupon_code' => 'NOPE']);
        $thrown = null;
    } catch (InvalidCouponException $e) {
        $thrown = $e->reason();
    }

    expect($thrown)->toBe($reason)
        ->and($m->lastPayment())->toBeNull()
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(0);

    // The page says so at the field, and a purchase without the code is the full price.
    $page = Livewire::test('mollie-billing::products')->set('couponInputs.pack', 'NOPE')->call('applyCoupon', 'pack');
    expect($page->get('couponErrors')['pack'])->not->toBeNull()
        ->and($page->get('appliedCouponCodes')['pack'] ?? [])->toBe([]);

    $page->call('purchase', 'pack');
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(5940);
})->with([
    'expired' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'valid_until' => now()->subDay()]), 'expired'],
    'inactive' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'active' => false]), 'inactive'],
    'for another product' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'applicable_products' => ['audit']]), 'product_not_applicable'],
    'for a plan' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'applicable_plans' => ['pro']]), 'plan_not_applicable'],
    'minimum order not met' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'minimum_order_amount_net' => 4951]), 'min_order_not_met'],
    'a recurring coupon' => [fn () => bmOtoCoupon('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'max_redemptions_per_billable' => 3], CouponType::Recurring), 'type_not_allowed_in_context'],
    'unknown' => [fn () => null, 'not_found'],
]);

it('one-time order: a product-scoped coupon applies to its product', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    bmOtoCoupon('PACKONLY', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'applicable_products' => ['pack'], 'minimum_order_amount_net' => 4950]);

    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack', 'coupon_code' => 'PACKONLY']);

    $lines = ['one_time_order:pack' => 4950, 'coupon:PACKONLY' => -495];
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(bmOtoGross($lines, 20.0));
    $m->assertInvoice($m->settle($m->lastPayment()), 4455, 891, 20.0, $lines);
    $m->assertNoMismatch();
});

it('one-time order for a billable without a billing country: the page shows what is charged and invoiced', function (bool $covered): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing.checkout_country_fallback', 'DE');
    $billable = $m->billable('AT', ['billing_country' => null, 'tax_country_user' => null]);

    if ($covered) {
        bmOtoCoupon('FULL', ['discount_type' => DiscountType::Percentage, 'discount_value' => 100]);

        $result = app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack', 'coupon_code' => 'FULL']);

        expect($result['completed'] ?? false)->toBeTrue()
            ->and((int) BillingInvoice::query()->sole()->amount_gross)->toBe(0);

        return;
    }

    $view = Livewire::test('mollie-billing::products')->instance()->with();
    $shown = collect($view['availableGroups'])->flatMap(fn ($g) => $g['products'])->firstWhere('code', 'pack')['price_net'];

    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);
    $payment = $m->lastPayment();

    // 49.50 + 19 % (the configured fallback country), on the page and at Mollie alike.
    expect(BookingMatrix::minor($payment))->toBe(5891)
        ->and($shown)->toBe(5891);

    $invoice = $m->settle($payment, 'DE');
    $m->assertInvoice($invoice, 4950, 941, 19.0, ['one_time_order:pack' => 4950]);
    expect($invoice->country)->toBe('DE');
    $m->assertNoMismatch();
})->with(['paid' => [false], 'fully covered by a coupon' => [true]]);

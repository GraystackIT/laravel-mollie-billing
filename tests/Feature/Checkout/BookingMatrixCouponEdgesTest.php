<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;

/*
 * Booking matrix, part 7: coupons crossed with the things that change the arithmetic —
 * VAT state, a per-seat addon, a yearly interval, a returning customer — and the coupon
 * states a subscription checkout does not admit at all.
 */

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function bmEdgeCoupon(string $code, CouponType $type, array $attributes = []): Coupon
{
    return app(CouponService::class)->create(array_merge(['code' => $code, 'name' => $code, 'type' => $type, 'active' => true], $attributes));
}

function bmEdgeGross(array $lineNets, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lineNets));
}

it('recurring coupon in every VAT state: shown == charged == invoiced == subscription == renewal', function (string $country, ?string $vatNumber, array $additional, float $rate): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing.additional_countries', $additional);
    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);
    bmEdgeCoupon('VAT15', CouponType::Recurring, ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'max_redemptions_per_billable' => 2]);

    // Pro 29.50 + 1 seat 5.55 + SSO 15.50 = 50.55; 15 % = 7.58.
    $discounted = ['plan' => 2950, 'seats' => 555, 'addon:sso' => 1550, 'coupon:VAT15' => -758];
    $full = ['plan' => 2950, 'seats' => 555, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 1, 'VAT15');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);
    expect($shown['gross'])->toBe(bmEdgeGross($discounted, $rate))
        ->and($shown['reverseCharge'])->toBe($rate === 0.0);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross']);

    $m->assertInvoice($m->settle($payment, $country), 4297, bmEdgeGross($discounted, $rate) - 4297, $rate, $discounted);
    expect($m->subscriptionMinor())->toBe($shown['gross']);
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 4297, bmEdgeGross($discounted, $rate) - 4297, $rate, $discounted);
    expect($m->subscriptionMinor())->toBe(bmEdgeGross($full, $rate));
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 5055, bmEdgeGross($full, $rate) - 5055, $rate, $full);
    $m->assertNoMismatch();
})->with([
    'B2C AT' => ['AT', null, [], 20.0],
    'B2C DE' => ['DE', null, [], 19.0],
    'B2B DE, reverse charge' => ['DE', 'DE123456789', [], 0.0],
    'B2B AT, domestic' => ['AT', 'ATU12345678', [], 20.0],
    'additional country CH 8.1 %' => ['CH', null, ['CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland']], 8.1],
]);

it('recurring coupon with a per-seat addon, through the renewals', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('DE');
    bmEdgeCoupon('SEAT20', CouponType::Recurring, ['discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'max_redemptions_per_billable' => 2]);

    // Pro 29.50 + 2 seats 11.10 + CRM 10.00 + CRM for 3 charged seats 9.99 = 60.59; 20 % = 12.12.
    $discounted = ['plan' => 2950, 'seats' => 1110, 'addon:crm' => 1000, 'addon_seats:crm' => 999, 'coupon:SEAT20' => -1212];
    $full = ['plan' => 2950, 'seats' => 1110, 'addon:crm' => 1000, 'addon_seats:crm' => 999];

    $checkout = $m->checkout('pro', 'monthly', ['crm'], 2, 'SEAT20');
    $shown = $m->shown($checkout);
    expect($checkout->get('couponError'))->toBeNull()
        ->and($shown['gross'])->toBe(bmEdgeGross($discounted, 19.0));

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross']);

    $m->assertInvoice($m->settle($payment, 'DE'), 4847, bmEdgeGross($discounted, 19.0) - 4847, 19.0, $discounted);
    expect($m->subscriptionMinor())->toBe($shown['gross']);
    $m->assertInvoice($m->renew($billable, 'monthly', 'DE'), 4847, bmEdgeGross($discounted, 19.0) - 4847, 19.0, $discounted);
    expect($m->subscriptionMinor())->toBe(bmEdgeGross($full, 19.0));
    $m->assertInvoice($m->renew($billable, 'monthly', 'DE'), 6059, bmEdgeGross($full, 19.0) - 6059, 19.0, $full);
    $m->assertNoMismatch();
});

it('recurring coupon on a yearly plan: the count is in years', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    bmEdgeCoupon('YEAR10', CouponType::Recurring, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'max_redemptions_per_billable' => 2]);

    $discounted = ['plan' => 29950, 'addon:sso' => 15550, 'coupon:YEAR10' => -4550];
    $full = ['plan' => 29950, 'addon:sso' => 15550];

    $checkout = $m->checkout('pro', 'yearly', ['sso'], 0, 'YEAR10');
    $shown = $m->shown($checkout);
    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross'])->toBe(bmEdgeGross($discounted, 20.0));

    $m->assertInvoice($m->settle($payment), 40950, 8190, 20.0, $discounted);
    expect($m->subscriptionMinor())->toBe($shown['gross'])
        ->and($m->subscriptionStartDate())->toBe('2027-03-10');

    // Year two is the second and last discounted charge; year three is full price.
    $m->assertInvoice($m->renew($billable, 'yearly'), 40950, 8190, 20.0, $discounted);
    expect($m->subscriptionMinor())->toBe(bmEdgeGross($full, 20.0));
    $m->assertInvoice($m->renew($billable, 'yearly'), 45500, 9100, 20.0, $full);
    $m->assertNoMismatch();
});

it('a subscription checkout takes one coupon: a second code is refused, stackable or not', function (bool $stackable): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    bmEdgeCoupon('ONE', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'stackable' => $stackable]);
    bmEdgeCoupon('TWO', CouponType::SinglePayment, ['discount_type' => DiscountType::Fixed, 'discount_value' => 500, 'stackable' => $stackable]);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'ONE');
    $checkout->set('coupon_input', 'TWO')->call('applyCoupon');

    expect($checkout->get('applied_coupon')['code'])->toBe('ONE')
        ->and($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_already_applied'));

    $checkout->call('submit');
    $payment = $m->lastPayment();

    // One coupon in the price, one in the metadata, one redemption.
    expect(BookingMatrix::minor($payment))->toBe(4860)
        ->and(BookingMatrix::prop($payment, 'metadata')['coupon_code'])->toBe('ONE');

    $m->assertInvoice($m->settle($payment), 4050, 810, 20.0, ['plan' => 2950, 'addon:sso' => 1550, 'coupon:ONE' => -450]);
    expect(CouponRedemption::query()->count())->toBe(1);
    $m->assertNoMismatch();
})->with(['both stackable' => [true], 'neither stackable' => [false]]);

it('a subscription checkout refuses the coupon types that do not belong there', function (CouponType $type, array $attributes): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    bmEdgeCoupon('WRONG', $type, $attributes);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'WRONG');

    expect($checkout->get('applied_coupon'))->toBeNull()
        ->and($checkout->get('couponError'))->not->toBeNull()
        ->and($m->shown($checkout)['gross'])->toBe(5400);

    $checkout->call('submit');
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(5400)
        ->and(BookingMatrix::prop($m->lastPayment(), 'metadata')['coupon_code'])->toBeNull();
})->with([
    'credits' => [CouponType::Credits, ['credits_payload' => ['tokens' => 100]]],
    'period_extension' => [CouponType::PeriodExtension, ['grant_duration_days' => 30]],
    'plan_waiver' => [CouponType::PlanWaiver, ['waiver_periods' => 2]],
    'trial_extension on a plan without a trial' => [CouponType::TrialExtension, ['trial_extension_days' => 14]],
]);

it('re-booking with a coupon: an unused one is priced through, a spent one is refused', function (bool $spent): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT', [
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_ends_at' => BillingTime::nowUtc()->subDays(40),
        'mollie_mandate_id' => 'mdt_old',
    ]);
    $coupon = bmEdgeCoupon('BACK', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

    if ($spent) {
        CouponRedemption::create([
            'coupon_id' => $coupon->id, 'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
            'discount_amount_net' => 450, 'applied_at' => now()->subMonths(6),
        ]);
    }

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'BACK');

    if ($spent) {
        expect($checkout->get('applied_coupon'))->toBeNull()
            ->and($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_per_billable_limit_reached'));

        $checkout->call('submit');
        expect(BookingMatrix::minor($m->lastPayment()))->toBe(5400);
        $m->assertInvoice($m->settle($m->lastPayment()), 4500, 900, 20.0, ['plan' => 2950, 'addon:sso' => 1550]);
        expect(CouponRedemption::query()->count())->toBe(1);
    } else {
        $checkout->call('submit');
        expect(BookingMatrix::minor($m->lastPayment()))->toBe(4860);
        $m->assertInvoice($m->settle($m->lastPayment()), 4050, 810, 20.0, ['plan' => 2950, 'addon:sso' => 1550, 'coupon:BACK' => -450]);
        expect(CouponRedemption::query()->count())->toBe(1)
            ->and($m->subscriptionMinor())->toBe(5400);
    }

    $m->assertNoMismatch();
})->with(['unused' => [false], 'already spent by this customer' => [true]]);

it('one-time order with two stacked coupons whose VAT rounds apart: the page shows what is charged', function (): void {
    // 1.50 + 1.50 at 19 %: each line carries 0.29 of VAT (0.285 rounded), the two together
    // 0.58 — while 19 % of 3.00 is 0.57. Grossing the summed discount put the page a cent
    // away from the payment.
    $m = BookingMatrix::boot();
    $m->billable('DE');
    bmEdgeCoupon('A150', CouponType::SinglePayment, ['discount_type' => DiscountType::Fixed, 'discount_value' => 150, 'stackable' => true]);
    bmEdgeCoupon('B150', CouponType::SinglePayment, ['discount_type' => DiscountType::Fixed, 'discount_value' => 150, 'stackable' => true]);

    $lines = ['one_time_order:pack' => 4950, 'coupon:A150' => -150, 'coupon:B150' => -150];

    $page = Livewire::test('mollie-billing::products')
        ->set('couponInputs.pack', 'A150')->call('applyCoupon', 'pack')
        ->set('couponInputs.pack', 'B150')->call('applyCoupon', 'pack');

    $view = $page->instance()->with();
    $shownPrice = collect($view['availableGroups'])->flatMap(fn ($g) => $g['products'])->firstWhere('code', 'pack')['price_net'];
    $shownTotal = $shownPrice - $view['couponDiscountsDisplay']['pack'];

    $page->call('purchase', 'pack');
    $payment = $m->lastPayment();

    expect(BookingMatrix::minor($payment))->toBe(bmEdgeGross($lines, 19.0))->toBe(5533)
        ->and($shownTotal)->toBe(5533);

    $m->assertInvoice($m->settle($payment, 'DE'), 4650, 883, 19.0, $lines);
    $m->assertNoMismatch();
});

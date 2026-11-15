<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;

/*
 * Booking matrix, part 3: the first booking of a paid plan WITH a coupon, through the
 * checkout component, the webhook and the renewals the coupon reaches.
 *
 * Order in every case: Pro 29.50 + SSO 15.50 = 45.00 net.
 */

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function bmCoupon(string $code, CouponType $type, array $attributes = []): Coupon
{
    return app(CouponService::class)->create(array_merge([
        'code' => $code,
        'name' => $code,
        'type' => $type,
        'active' => true,
    ], $attributes));
}

/** Gross for line nets, VAT per line — computed here, independently of the package. */
function bmGross(array $lineNets, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lineNets));
}

dataset('bm countries', ['AT 20 %' => ['AT', 20.0], 'DE 19 %' => ['DE', 19.0]]);

// ── single_payment ──────────────────────────────────────────────────────────────

it('single_payment: discounts the first payment only', function (string $country, float $rate, DiscountType $discountType, int $value, int $discount): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    bmCoupon('ONCE', CouponType::SinglePayment, ['discount_type' => $discountType, 'discount_value' => $value]);

    $first = ['plan' => 2950, 'addon:sso' => 1550, 'coupon:ONCE' => -$discount];
    $full = ['plan' => 2950, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'ONCE');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);
    expect($shown['gross'])->toBe(bmGross($first, $rate))->and($shown['net'])->toBe(4500 - $discount);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross']);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, 4500 - $discount, bmGross($first, $rate) - (4500 - $discount), $rate, $first);

    // Mollie holds the FULL price: the discount was for one payment.
    expect($m->subscriptionMinor())->toBe(bmGross($full, $rate));

    $redemption = CouponRedemption::query()->sole();
    expect((int) $redemption->discount_amount_net)->toBe($discount)
        ->and((int) $redemption->invoice_id)->toBe((int) $invoice->id);

    $renewal = $m->renew($billable, 'monthly', $country);
    $m->assertInvoice($renewal, 4500, bmGross($full, $rate) - 4500, $rate, $full);

    expect(CouponRedemption::query()->count())->toBe(1);
    $m->assertNoMismatch();
})->with('bm countries')->with([
    '10 %' => [DiscountType::Percentage, 10, 450],
    '33 %' => [DiscountType::Percentage, 33, 1485],
    'fixed 5.55' => [DiscountType::Fixed, 555, 555],
]);

it('single_payment 100 %: nothing now, full price from the second period', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    bmCoupon('FREE1', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 100]);
    Carbon::setTestNow($start = BillingTime::nowUtc()->startOfSecond());

    $full = ['plan' => 2950, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'FREE1');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($m->shown($checkout)['gross'])->toBe(0);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only');

    $invoice = $m->settle($payment, $country);
    // Net 0, VAT 0, gross 0 — at 19 % as well, where one coupon line for the whole
    // discount would leave a cent of VAT behind.
    $m->assertInvoice($invoice, 0, 0, $rate);
    expect((int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net'))->toBe(-4500)
        ->and((int) collect($invoice->line_items)->firstWhere('kind', 'plan')['amount_net'])->toBe(2950);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($m->subscriptionMinor())->toBe(bmGross($full, $rate))
        ->and($m->subscriptionStartDate())->toBe(BillingTime::addInterval($start, 'monthly')->toDateString())
        ->and(CouponRedemption::query()->count())->toBe(1);

    $renewal = $m->renew($billable, 'monthly', $country);
    $m->assertInvoice($renewal, 4500, bmGross($full, $rate) - 4500, $rate, $full);
    $m->assertNoMismatch();
})->with('bm countries');

// ── recurring ───────────────────────────────────────────────────────────────────

it('recurring, limited by applications: discounts exactly that many charges', function (string $country, float $rate, DiscountType $discountType, int $value, int $discount, int $max): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    bmCoupon('REC', CouponType::Recurring, [
        'discount_type' => $discountType, 'discount_value' => $value, 'max_redemptions_per_billable' => $max,
    ]);

    $discounted = ['plan' => 2950, 'addon:sso' => 1550, 'coupon:REC' => -$discount];
    $full = ['plan' => 2950, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'REC');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);
    expect($shown['gross'])->toBe(bmGross($discounted, $rate));

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross']);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, 4500 - $discount, bmGross($discounted, $rate) - (4500 - $discount), $rate, $discounted);

    // Charge 1 was the first payment. Charges 2…max are discounted renewals; after
    // that Mollie has to hold the full price again BEFORE it collects.
    for ($charge = 2; $charge <= $max + 2; $charge++) {
        $expectDiscount = $charge <= $max;

        expect($m->subscriptionMinor())->toBe(
            bmGross($expectDiscount ? $discounted : $full, $rate),
            "amount Mollie holds ahead of charge {$charge}",
        );

        $renewal = $m->renew($billable, 'monthly', $country);
        $lines = $expectDiscount ? $discounted : $full;
        $m->assertInvoice($renewal, array_sum($lines), bmGross($lines, $rate) - array_sum($lines), $rate, $lines);
    }

    // One redemption per discounted charge, no more.
    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe($max);
    $m->assertNoMismatch();
})->with('bm countries')->with([
    '10 %, once' => [DiscountType::Percentage, 10, 450, 1],
    '10 %, three times' => [DiscountType::Percentage, 10, 450, 3],
    'fixed 5.55, three times' => [DiscountType::Fixed, 555, 555, 3],
]);

it('recurring, limited by date: discounts every charge that falls before valid_until', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    Carbon::setTestNow($start = Carbon::parse('2026-03-10 09:00:00', 'UTC'));

    // Runs out between the second and the third charge.
    bmCoupon('UNTIL', CouponType::Recurring, [
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        // A count is always in force too (column default 1, the admin form's minimum):
        // the earlier of the two limits ends the discount. 12 lets the date decide.
        'max_redemptions_per_billable' => 12, 'valid_until' => $start->copy()->addDays(45),
    ]);

    $discounted = ['plan' => 2950, 'addon:sso' => 1550, 'coupon:UNTIL' => -900];
    $full = ['plan' => 2950, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'UNTIL');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($shown['gross'])->toBe(bmGross($discounted, $rate));

    $m->assertInvoice($m->settle($payment, $country), 3600, bmGross($discounted, $rate) - 3600, $rate, $discounted);

    // Charge 2 (10 April) is inside the validity.
    expect($m->subscriptionMinor())->toBe(bmGross($discounted, $rate));
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 3600, bmGross($discounted, $rate) - 3600, $rate, $discounted);

    // Charge 3 (10 May) is past it: Mollie must already hold the full price.
    expect($m->subscriptionMinor())->toBe(bmGross($full, $rate));
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 4500, bmGross($full, $rate) - 4500, $rate, $full);

    $m->assertInvoice($m->renew($billable, 'monthly', $country), 4500, bmGross($full, $rate) - 4500, $rate, $full);
    $m->assertNoMismatch();
})->with('bm countries');

it('recurring 100 %: nothing is collected until the coupon ends, then the full price', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    Carbon::setTestNow($start = Carbon::parse('2026-03-10 09:00:00', 'UTC'));

    bmCoupon('FREE3M', CouponType::Recurring, [
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
        // A count is always in force too (column default 1, the admin form's minimum):
        // the earlier of the two limits ends the discount. 12 lets the date decide.
        'max_redemptions_per_billable' => 12, 'valid_until' => $start->copy()->addDays(75),
    ]);

    $full = ['plan' => 2950, 'addon:sso' => 1550];

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'FREE3M');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($m->shown($checkout)['gross'])->toBe(0);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, 0, 0, $rate);
    expect((int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net'))->toBe(-4500);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        // Mollie cannot hold 0: it holds the full price, with the first charge after the coupon.
        ->and($m->subscriptionMinor())->toBe(bmGross($full, $rate))
        ->and(Carbon::parse($m->subscriptionStartDate())->gt($start->copy()->addDays(75)))->toBeTrue();

    // That first charge is a full-price one, and is booked as such.
    $charge = $m->renew($billable, 'monthly', $country, Carbon::parse($m->subscriptionStartDate(), 'UTC'));
    $m->assertInvoice($charge, 4500, bmGross($full, $rate) - 4500, $rate, $full);
    $m->assertNoMismatch();
})->with('bm countries');

// ── scope filters ───────────────────────────────────────────────────────────────

it('applies a scoped coupon only where its scope is met', function (array $scope, string $plan, string $interval, array $addons, bool $applies): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    bmCoupon('SCOPED', CouponType::SinglePayment, array_merge(['discount_type' => DiscountType::Percentage, 'discount_value' => 10], $scope));

    $checkout = $m->checkout($plan, $interval, $addons, 0, 'SCOPED');

    if ($applies) {
        expect($checkout->get('couponError'))->toBeNull()
            ->and($checkout->get('applied_coupon'))->not->toBeNull();

        return;
    }

    // Refused cleanly: nothing applied, the full price shown and charged, nothing redeemed.
    expect($checkout->get('couponError'))->not->toBeNull()
        ->and($checkout->get('applied_coupon'))->toBeNull();

    $plain = $m->shown($m->checkout($plan, $interval, $addons));
    expect($m->shown($checkout)['gross'])->toBe($plain['gross']);

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($plain['gross'])
        ->and(BookingMatrix::prop($payment, 'metadata')['coupon_code'])->toBeNull();

    $invoice = $m->settle($payment);
    expect(collect($invoice->line_items)->firstWhere('kind', 'coupon'))->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(0);
    $m->assertNoMismatch();
})->with([
    'applicable_plans — hit' => [['applicable_plans' => ['pro']], 'pro', 'monthly', [], true],
    'applicable_plans — miss' => [['applicable_plans' => ['team']], 'pro', 'monthly', [], false],
    'applicable_intervals — hit' => [['applicable_intervals' => ['yearly']], 'pro', 'yearly', [], true],
    'applicable_intervals — miss' => [['applicable_intervals' => ['yearly']], 'pro', 'monthly', [], false],
    'applicable_addons — hit' => [['applicable_addons' => ['sso']], 'pro', 'monthly', ['sso'], true],
    'applicable_addons — miss' => [['applicable_addons' => ['crm']], 'pro', 'monthly', ['sso'], false],
    'applicable_products — a product coupon on a plan' => [['applicable_products' => ['pack']], 'pro', 'monthly', [], false],
    'minimum order — hit' => [['minimum_order_amount_net' => 4500], 'pro', 'monthly', ['sso'], true],
    'minimum order — miss (net, not gross)' => [['minimum_order_amount_net' => 4501], 'pro', 'monthly', ['sso'], false],
]);

it('refuses a coupon that cannot be used, and leaves no half state', function (Closure $make, string $reasonKey): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $make();

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'DEAD');

    expect($checkout->get('applied_coupon'))->toBeNull()
        ->and($checkout->get('couponError'))->toBe(__('billing::checkout.'.$reasonKey));

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(5400)
        ->and(BookingMatrix::prop($payment, 'metadata')['coupon_code'])->toBeNull();

    $m->assertInvoice($m->settle($payment), 4500, 900, 20.0, ['plan' => 2950, 'addon:sso' => 1550]);
    expect($m->subscriptionMinor())->toBe(5400)
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(0);
    $m->assertNoMismatch();
})->with([
    'expired' => [fn () => bmCoupon('DEAD', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'valid_until' => now()->subDay()]), 'coupon_expired'],
    'not yet valid' => [fn () => bmCoupon('DEAD', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'valid_from' => now()->addDay()]), 'coupon_not_yet_valid'],
    'inactive' => [fn () => bmCoupon('DEAD', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'active' => false]), 'coupon_inactive'],
    'exhausted' => [function (): void {
        $coupon = bmCoupon('DEAD', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'max_redemptions' => 1]);
        $coupon->forceFill(['redemptions_count' => 1])->save();
    }, 'coupon_exhausted'],
    'unknown code' => [fn () => null, 'coupon_not_found'],
]);

it('refuses a coupon that died between checkout and submit, before any payment exists', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    $coupon = bmCoupon('LATE', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'LATE');
    expect($checkout->get('applied_coupon'))->not->toBeNull();

    $coupon->forceFill(['active' => false])->save();
    $checkout->call('submit');

    // The customer is told and sees the new total; nothing was sent to Mollie.
    expect($m->lastPayment())->toBeNull()
        ->and($checkout->get('applied_coupon'))->toBeNull()
        ->and($checkout->get('errorMessage'))->toBe(__('billing::checkout.coupon_dropped_revisit'))
        ->and($m->shown($checkout)['gross'])->toBe(5400);
});

it('books the paid amount when a coupon dies between payment and webhook, and says so', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $coupon = bmCoupon('GONE', CouponType::SinglePayment, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'GONE');
    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(4860);

    $coupon->forceFill(['active' => false])->save();
    $invoice = $m->settle($payment);

    // The payment wins: the customer paid 48.60 and that is what the invoice states.
    expect((int) $invoice->amount_gross)->toBe(4860)
        ->and($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($m->subscriptionMinor())->toBe(5400)
        ->and(CouponRedemption::query()->count())->toBe(0);
});

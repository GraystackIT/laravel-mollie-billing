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
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;

/*
 * Booking matrix, part 4: coupons on the booking paths other than a plain first payment —
 * trial, access grant, promotion link, upgrade from a free plan.
 */

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function bmPathCoupon(string $code, CouponType $type, array $attributes = []): Coupon
{
    return app(CouponService::class)->create(array_merge(['code' => $code, 'name' => $code, 'type' => $type, 'active' => true], $attributes));
}

function bmPathGross(array $lineNets, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lineNets));
}

// ── trial ───────────────────────────────────────────────────────────────────────

it('trial + recurring coupon: the trial-end charge is the discounted amount the checkout showed', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    Carbon::setTestNow($start = Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    bmPathCoupon('TRIALREC', CouponType::Recurring, ['discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'max_redemptions_per_billable' => 2]);

    // Team 19.50 + SSO 15.50 = 35.00, −20 % = 28.00.
    $discounted = ['plan' => 1950, 'addon:sso' => 1550, 'coupon:TRIALREC' => -700];
    $full = ['plan' => 1950, 'addon:sso' => 1550];

    $checkout = $m->checkout('team', 'monthly', ['sso'], 0, 'TRIALREC');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);
    expect($shown['gross'])->toBe(bmPathGross($discounted, $rate))
        ->and($checkout->instance()->firstChargeDate()->toDateString())->toBe('2026-03-24');

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0);
    expect($m->settle($payment, $country))->toBeNull();

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($m->subscriptionMinor())->toBe($shown['gross'])
        ->and($m->subscriptionStartDate())->toBe('2026-03-24');

    // Two discounted charges, then the full price — which Mollie must hold in time.
    $m->assertInvoice($m->renew($billable, 'monthly', $country, $billable->trial_ends_at), 2800, bmPathGross($discounted, $rate) - 2800, $rate, $discounted);
    expect($m->subscriptionMinor())->toBe(bmPathGross($discounted, $rate));
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 2800, bmPathGross($discounted, $rate) - 2800, $rate, $discounted);
    expect($m->subscriptionMinor())->toBe(bmPathGross($full, $rate));
    $m->assertInvoice($m->renew($billable, 'monthly', $country), 3500, bmPathGross($full, $rate) - 3500, $rate, $full);

    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe(2);
    $m->assertNoMismatch();
})->with(['AT' => ['AT', 20.0], 'DE' => ['DE', 19.0]]);

it('trial + trial_extension coupon: the trial is longer by exactly those days, and nothing is discounted', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    bmPathCoupon('PLUS16', CouponType::TrialExtension, ['trial_extension_days' => 16]);

    $checkout = $m->checkout('team', 'monthly', [], 0, 'PLUS16');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($checkout->instance()->announcedTrialDays())->toBe(30)
        ->and($checkout->instance()->firstChargeDate()->toDateString())->toBe('2026-04-09')
        ->and($m->shown($checkout)['gross'])->toBe(2340);

    $checkout->call('submit');
    $m->settle($m->lastPayment());
    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())->toBe('2026-04-09');

    // Mollie's first charge follows the extended trial: created for the plan's own end,
    // then moved by the extension.
    $moved = collect($m->sent)->last(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest);
    $chargeDate = $moved !== null ? (string) BookingMatrix::prop($moved, 'startDate') : $m->subscriptionStartDate();
    expect($chargeDate)->toBe('2026-04-09')
        ->and($m->subscriptionMinor())->toBe(2340);

    $m->assertInvoice($m->renew($billable, 'monthly', 'AT', $billable->trial_ends_at), 1950, 390, 20.0, ['plan' => 1950]);
    $m->assertNoMismatch();
});

it('trial + 100 % recurring coupon: the checkout names the date Mollie really charges on', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    bmPathCoupon('FREE3', CouponType::Recurring, ['discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'max_redemptions_per_billable' => 3]);

    $checkout = $m->checkout('team', 'monthly', [], 0, 'FREE3');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($m->shown($checkout)['gross'])->toBe(0);

    // Three periods of 30 days + 1, and charged the day after: later than the trial's end.
    $announced = $checkout->instance()->firstChargeDate();
    expect($announced->toDateString())->toBe('2026-06-10');

    // … and that is the date on the confirm step, not the trial's end (24 March).
    $checkout->set('step', $checkout->instance()->customStepCount() + 4)
        ->assertSee($announced->translatedFormat('d. M Y'))
        ->assertDontSee(Carbon::parse('2026-03-24')->translatedFormat('d. M Y'));

    $checkout->call('submit');
    $m->settle($m->lastPayment());
    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($m->subscriptionStartDate())->toBe($announced->toDateString())
        // Mollie cannot hold 0; it holds the full price for that later date.
        ->and($m->subscriptionMinor())->toBe(2340);
});

it('names the trial end on the confirm step for an ordinary trial', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));

    $checkout = $m->checkout('team');

    $checkout->set('step', $checkout->instance()->customStepCount() + 4)
        ->assertSee(__('billing::checkout.trial_summary_heading', ['days' => 14]))
        ->assertSee(Carbon::parse('2026-03-24')->translatedFormat('d. M Y'));

    // No trial, no trial text: a plan without one …
    $m->checkout('pro')->set('step', 4)->assertDontSee(__('billing::checkout.trial_summary_heading', ['days' => 14]));
});

it('announces no trial to a returning customer with a mandate on file', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT', ['mollie_mandate_id' => 'mdt_old', 'subscription_status' => SubscriptionStatus::Expired]);

    $checkout = $m->checkout('team');

    expect($checkout->instance()->announcedTrialDays())->toBe(0)
        ->and($checkout->instance()->firstChargeDate())->toBeNull();

    $checkout->set('step', $checkout->instance()->customStepCount() + 4)
        ->assertDontSee(__('billing::checkout.trial_summary_heading', ['days' => 14]))
        ->assertSee(__('billing::checkout.redirect_notice'));
});

// ── access grant ────────────────────────────────────────────────────────────────

it('access_grant for plan + addon: activates locally for the grant period, nothing is charged', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    Carbon::setTestNow($start = Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    app(CouponService::class)->accessGrantCoupon('GIFT90', 'pro', 'monthly', ['sso'], 90);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'GIFT90');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($m->shown($checkout)['gross'])->toBe(0);

    $checkout->call('submit');
    $billable->refresh();

    expect($m->sent)->toBe([])
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->getActiveBillingAddonCodes())->toBe(['sso'])
        ->and($billable->subscription_ends_at->toDateString())->toBe($start->copy()->addDays(90)->toDateString())
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and(CouponRedemption::query()->count())->toBe(1);

    // And it ends: after the grant the billable is no longer accessible.
    Carbon::setTestNow($start->copy()->addDays(91));
    expect($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();
});

it('access_grant: refuses an order that goes beyond what the grant gives, and activates nothing', function (array $addons, int $extraSeats, string $interval): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    app(CouponService::class)->accessGrantCoupon('GIFT90', 'pro', 'monthly', ['sso'], 90);

    $checkout = $m->checkout('pro', $interval, $addons, $extraSeats, 'GIFT90');

    // Either the form already refuses the code …
    if ($checkout->get('applied_coupon') !== null) {
        // … or the submit does. What must not happen is a free activation of the extras.
        $checkout->call('submit');
    }

    $billable->refresh();
    $bookedFree = $billable->hasAccessibleBillingSubscription() && BillingInvoice::query()->count() === 0 && $m->lastPayment() === null;

    expect($bookedFree)->toBeFalse()
        ->and(CouponRedemption::query()->count())->toBe(0);
})->with([
    'an addon the grant does not include' => [['sso', 'crm'], 0, 'monthly'],
    'extra seats' => [['sso'], 2, 'monthly'],
    'another interval' => [['sso'], 0, 'yearly'],
]);

it('addon-only access_grant at a first checkout: refused at the form, the order stays payable', function (): void {
    // It adds addons to a subscription that already runs — the checkout never has one.
    // The form used to accept it and show the addon as free; the submit then failed with
    // "the payment could not be created".
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    app(CouponService::class)->addonGrantCoupon('SSOFREE', ['sso']);

    $checkout = $m->checkout('pro', 'monthly', ['sso'], 0, 'SSOFREE');

    expect($checkout->get('applied_coupon'))->toBeNull()
        ->and($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_requires_active_subscription'))
        ->and($m->shown($checkout)['gross'])->toBe(5400);

    $checkout->call('submit');

    $payment = $m->lastPayment();
    expect($checkout->get('errorMessage'))->toBeNull()
        ->and(BookingMatrix::minor($payment))->toBe(5400)
        ->and(CouponRedemption::query()->count())->toBe(0);

    $m->assertInvoice($m->settle($payment), 4500, 900, 20.0, ['plan' => 2950, 'addon:sso' => 1550]);
    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

// ── promotion link ──────────────────────────────────────────────────────────────

it('promotion link: the code is applied on arrival and priced through to the renewal', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    bmPathCoupon('SPRING', CouponType::Recurring, [
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
        'max_redemptions_per_billable' => 2, 'auto_apply_token' => 'spring-sale',
    ]);

    $this->get(route('billing.promotion', ['token' => 'spring-sale']))->assertRedirect();
    expect(session(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY))->toBe('SPRING');

    // No code typed: mount() adopted it.
    $checkout = $m->checkout('pro', 'monthly', ['sso']);
    $discounted = ['plan' => 2950, 'addon:sso' => 1550, 'coupon:SPRING' => -450];

    // The discount follows the selection made after arrival.
    $checkout->call('next');
    expect($checkout->get('applied_coupon')['code'] ?? null)->toBe('SPRING');

    $checkout->call('submit');
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(bmPathGross($discounted, 20.0))
        ->and(BookingMatrix::prop($payment, 'metadata')['coupon_code'])->toBe('SPRING');

    $m->assertInvoice($m->settle($payment), 4050, 810, 20.0, $discounted);
    expect($m->subscriptionMinor())->toBe(4860);
    $m->assertInvoice($m->renew($billable), 4050, 810, 20.0, $discounted);
    expect($m->subscriptionMinor())->toBe(5400);
    $m->assertNoMismatch();
});

it('promotion link that is no longer valid: no code in the session, and an earlier one is retired', function (Closure $spoil): void {
    BookingMatrix::boot();
    $coupon = bmPathCoupon('SPRING', CouponType::SinglePayment, [
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'auto_apply_token' => 'spring-sale',
    ]);
    $spoil($coupon);

    // What an earlier, valid link left behind.
    session([StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY => 'EARLIER']);

    $this->get(route('billing.promotion', ['token' => 'spring-sale']))
        ->assertRedirect()
        ->assertSessionHas('billing_status', __('billing::checkout.promotion_expired'));

    expect(session()->has(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY))->toBeFalse();
})->with([
    'expired' => [fn (Coupon $c) => $c->forceFill(['valid_until' => now()->subDay()])->save()],
    'exhausted' => [fn (Coupon $c) => $c->forceFill(['max_redemptions' => 1, 'redemptions_count' => 1])->save()],
    'unknown token' => [fn (Coupon $c) => $c->forceFill(['auto_apply_token' => 'something-else'])->save()],
]);

// ── upgrade free → paid ─────────────────────────────────────────────────────────

it('upgrade free → paid with a coupon: charged == invoiced, and the renewals follow the coupon', function (CouponType $type, int $max, int $discountedRenewals): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('DE');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    bmPathCoupon('UP10', $type, ['discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'max_redemptions_per_billable' => $max]);

    $discounted = ['plan' => 2950, 'addon:sso' => 1550, 'coupon:UP10' => -450];
    $full = ['plan' => 2950, 'addon:sso' => 1550];

    app(UpgradeLocalToMollie::class)->handle($billable->fresh(), [
        'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => ['sso'],
        'coupon_code' => 'UP10', 'amount_gross' => bmPathGross($discounted, 19.0),
    ]);

    $payment = $m->lastPayment();
    $m->assertInvoice($m->settle($payment, 'DE'), 4050, bmPathGross($discounted, 19.0) - 4050, 19.0, $discounted);

    for ($i = 1; $i <= $discountedRenewals + 1; $i++) {
        $lines = $i <= $discountedRenewals ? $discounted : $full;
        expect($m->subscriptionMinor())->toBe(bmPathGross($lines, 19.0), "amount Mollie holds ahead of renewal {$i}");
        $m->assertInvoice($m->renew($billable, 'monthly', 'DE'), array_sum($lines), bmPathGross($lines, 19.0) - array_sum($lines), 19.0, $lines);
    }

    $m->assertNoMismatch();
})->with([
    'single_payment' => [CouponType::SinglePayment, 1, 0],
    'recurring, three times' => [CouponType::Recurring, 3, 2],
]);

<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/*
 * Booking matrix, part 8: the cells the earlier parts reported as "—".
 *
 * Every coupon state a subscription admits, crossed with
 *   - re-booking after the subscription ended (expired, cancelled, past due),
 *   - the upgrade of a free plan to a YEARLY paid one,
 *   - the things that change the arithmetic of a first booking: extra seats, an addon,
 *     a per-seat addon, a yearly interval.
 *
 * One runner follows every case to the same end: what the customer is shown is what is
 * charged, what is invoiced, what Mollie holds for the next period, and what the renewals
 * book — for as long as the coupon reaches, and one charge beyond.
 */

afterEach(fn () => Carbon::setTestNow());

const BMX_START = '2026-03-10 09:00:00';

/** The coupon states a subscription booking admits. */
dataset('bmx coupon states', [
    'single_payment 15 %' => ['single-percent'],
    'single_payment fixed 5.55' => ['single-fixed'],
    'single_payment 100 %' => ['single-full'],
    'recurring 15 %, discount_periods 3' => ['recurring-periods'],
    'recurring fixed 5.55, discount_periods 2' => ['recurring-fixed'],
    'recurring 20 % until a date' => ['recurring-date'],
    'recurring 100 %, discount_periods 2' => ['recurring-full'],
]);

function bmxCode(string $state): string
{
    return 'X'.strtoupper(substr(md5($state), 0, 6));
}

function bmxCoupon(string $state, string $interval): void
{
    $start = Carbon::parse(BMX_START, 'UTC');

    $attributes = match ($state) {
        'single-percent' => ['type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 15],
        'single-fixed' => ['type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Fixed, 'discount_value' => 555],
        'single-full' => ['type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 100],
        'recurring-periods' => ['type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'discount_periods' => 3],
        'recurring-fixed' => ['type' => CouponType::Recurring, 'discount_type' => DiscountType::Fixed, 'discount_value' => 555, 'discount_periods' => 2],
        // Runs out between the second and the third charge: 45 days of months, 400 of years.
        'recurring-date' => ['type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
            'valid_until' => $start->copy()->addDays($interval === 'yearly' ? 400 : 45)],
        'recurring-full' => ['type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 2],
    };

    app(CouponService::class)->create(array_merge(['code' => bmxCode($state), 'name' => $state, 'active' => true], $attributes));
}

function bmxDiscount(string $state, int $subtotal): int
{
    return match ($state) {
        'single-percent', 'recurring-periods' => (int) round($subtotal * 0.15),
        'single-fixed', 'recurring-fixed' => 555,
        'recurring-date' => (int) round($subtotal * 0.20),
        'single-full', 'recurring-full' => $subtotal,
    };
}

/** How many charges, the first payment included, carry the discount. */
function bmxDiscountedCharges(string $state): int
{
    return match ($state) {
        'single-percent', 'single-fixed', 'single-full' => 1,
        'recurring-periods' => 3,
        'recurring-fixed', 'recurring-date', 'recurring-full' => 2,
    };
}

/** @param  array<string, int>  $lines */
function bmxGross(array $lines, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lines));
}

/**
 * From the first payment to one charge past the coupon.
 *
 * @param  array<string, int>  $full  the order's lines at full price
 */
function bmxFollow(BookingMatrix $m, TestBillable $billable, CreatePaymentRequest $payment, string $state, string $interval, string $country, float $rate, array $full): void
{
    $start = Carbon::parse(BMX_START, 'UTC');
    $subtotal = (int) array_sum($full);
    $discount = bmxDiscount($state, $subtotal);
    $discounted = $full + ['coupon:'.bmxCode($state) => -$discount];

    if ($discount >= $subtotal) {
        // Nothing is owed now: a zero payment for the mandate, an invoice over nothing.
        expect(BookingMatrix::minor($payment))->toBe(0);

        $invoice = $m->settle($payment, $country);
        $m->assertInvoice($invoice, 0, 0, $rate);
        expect((int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net'))->toBe(-$subtotal);

        // Mollie cannot hold 0: it holds the full price and starts charging after the coupon.
        $firstCharge = Carbon::parse((string) $m->subscriptionStartDate(), 'UTC');
        $covered = BillingTime::addInterval($start, $interval);
        expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($m->subscriptionMinor())->toBe(bmxGross($full, $rate));

        if ($state === 'single-full') {
            expect($firstCharge->toDateString())->toBe($covered->toDateString());
        } else {
            // Two periods are free, so the first charge is the third period's.
            expect($firstCharge->gte(BillingTime::addInterval($covered, $interval)->startOfDay()))->toBeTrue(
                "first charge {$firstCharge->toDateString()} falls inside the two free periods",
            );
        }

        $m->assertInvoice($m->renew($billable, $interval, $country, $firstCharge), $subtotal, bmxGross($full, $rate) - $subtotal, $rate, $full);
        $m->assertNoMismatch();

        return;
    }

    $net = $subtotal - $discount;

    expect(BookingMatrix::minor($payment))->toBe(bmxGross($discounted, $rate));
    $m->assertInvoice($m->settle($payment, $country), $net, bmxGross($discounted, $rate) - $net, $rate, $discounted);

    $last = bmxDiscountedCharges($state);

    for ($charge = 2; $charge <= $last + 2; $charge++) {
        $lines = $charge <= $last ? $discounted : $full;

        expect($m->subscriptionMinor())->toBe(bmxGross($lines, $rate), "amount Mollie holds ahead of charge {$charge}");
        $m->assertInvoice($m->renew($billable, $interval, $country), (int) array_sum($lines), bmxGross($lines, $rate) - (int) array_sum($lines), $rate, $lines);
    }

    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe($last);
    $m->assertNoMismatch();
}

// ── re-booking after the subscription ended ─────────────────────────────────────

it('re-booking × coupon: shown == charged == invoiced == subscription == renewals', function (SubscriptionStatus $status, string $state): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow(Carbon::parse(BMX_START, 'UTC'));

    // Had Team (which offers a trial); the subscription is over and the mandate is on file.
    $billable = $m->billable('AT', [
        'subscription_status' => $status,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => $status === SubscriptionStatus::Expired ? null : 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonths(3),
        'subscription_ends_at' => BillingTime::nowUtc()->subDays(40),
        'trial_ends_at' => BillingTime::nowUtc()->subMonths(4),
        'mollie_mandate_id' => 'mdt_old',
        'subscription_meta' => ['seat_count' => 5, 'past_due_since' => BillingTime::nowUtc()->subDays(50)->toIso8601String()],
    ]);
    bmxCoupon($state, 'monthly');

    $full = ['plan' => 1950, 'addon:sso' => 1550];

    $checkout = $m->checkout('team', 'monthly', ['sso'], 0, bmxCode($state));
    expect($checkout->get('couponError'))->toBeNull()
        ->and($checkout->instance()->trialApplies('team', 'monthly'))->toBeFalse()
        ->and($m->shown($checkout)['gross'])->toBe(bmxGross($full + ['c' => -bmxDiscount($state, 3500)], 20.0));

    $checkout->call('submit');
    expect(BookingMatrix::minor($m->lastPayment()))->toBe($m->shown($checkout)['gross']);

    bmxFollow($m, $billable, $m->lastPayment(), $state, 'monthly', 'AT', 20.0, $full);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since');
})->with([
    'expired' => [SubscriptionStatus::Expired],
    'cancelled, grace over' => [SubscriptionStatus::Cancelled],
    'past due' => [SubscriptionStatus::PastDue],
])->with('bmx coupon states');

// ── upgrade free → yearly paid ──────────────────────────────────────────────────

it('upgrade free → yearly plan × coupon: charged == invoiced == subscription == renewals', function (string $state): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow(Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('DE');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    bmxCoupon($state, 'yearly');

    $full = ['plan' => 29950, 'addon:sso' => 15550];
    $discounted = $full + ['c' => -bmxDiscount($state, 45500)];

    app(UpgradeLocalToMollie::class)->handle($billable->fresh(), [
        'plan_code' => 'pro', 'interval' => 'yearly', 'addon_codes' => ['sso'],
        'coupon_code' => bmxCode($state), 'amount_gross' => bmxGross($discounted, 19.0),
    ]);

    bmxFollow($m, $billable, $m->lastPayment(), $state, 'yearly', 'DE', 19.0, $full);

    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Yearly);
})->with([
    'single_payment 15 %' => ['single-percent'],
    'single_payment fixed 5.55' => ['single-fixed'],
    'recurring 15 %, discount_periods 3' => ['recurring-periods'],
    'recurring fixed 5.55, discount_periods 2' => ['recurring-fixed'],
    'recurring 20 % until a date' => ['recurring-date'],
]);

/*
 * A recurring code that covers the whole yearly price: nothing is owed now, so the plan
 * screen asks Mollie for a €0 authorisation — the paid plan needs a mandate — and nothing
 * changes until Mollie reports back. (The whole path, compared with the checkout's, is in
 * FullCoverageUpgradeFromFreeTest.)
 */
it('upgrade free → yearly plan with a coupon that covers everything: a €0 authorisation, and the first charge after the code', function (string $state): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow($start = Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('DE');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    bmxCoupon($state, 'yearly');

    $screen = Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'yearly')
        ->call('previewFor', 'pro')
        ->set('couponInput', bmxCode($state))
        ->call('applyCoupon');

    expect($screen->get('couponError'))->toBeNull()
        ->and($screen->get('appliedCouponCodes'))->toBe([bmxCode($state)])
        ->and((int) $screen->get('preview')['grossTotal'])->toBe(0);

    $screen->call('confirmAndPay');

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only')
        ->and($billable->fresh()->subscription_plan_code)->toBe('free')
        ->and(CouponRedemption::query()->count())->toBe(0);

    $m->settle($payment, 'DE');

    // Pro yearly 299.50 at 19 %; two free years, so the first charge is the third year's.
    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Yearly)
        ->and($m->subscriptionMinor())->toBe(35641)
        ->and(Carbon::parse((string) $m->subscriptionStartDate(), 'UTC')->gte($start->copy()->addDays(730)->startOfDay()))->toBeTrue()
        ->and(CouponRedemption::query()->count())->toBe(1);
})->with(['recurring 100 %' => ['recurring-full']]);

/*
 * The plan screen admits recurring coupons only: a single_payment code is answered at the
 * field and never reaches the price. (`UpgradeLocalToMollie::handle()` itself prices any
 * code it is handed — the cells above — so an app calling the service can use one.)
 */
it('upgrade free → yearly plan on the plan screen: a single_payment code is refused at the field', function (string $state): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow(Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('DE');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    bmxCoupon($state, 'yearly');

    $screen = Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'yearly')
        ->call('previewFor', 'pro')
        ->set('couponInput', bmxCode($state))
        ->call('applyCoupon');

    // Pro yearly 299.50 at 19 %, undiscounted.
    expect($screen->get('couponError'))->toBe(__('billing::checkout.coupon_type_not_allowed_in_context'))
        ->and($screen->get('appliedCouponCodes'))->toBe([])
        ->and((int) $screen->get('preview')['grossTotal'])->toBe(35641)
        ->and(CouponRedemption::query()->count())->toBe(0);
})->with(['15 %' => ['single-percent'], 'fixed 5.55' => ['single-fixed'], '100 %' => ['single-full']]);

// ── first booking: what changes the arithmetic ──────────────────────────────────

it('first booking × order shape × coupon: shown == charged == invoiced == subscription == renewals', function (string $country, float $rate, string $interval, array $addons, int $seats, array $full, string $state): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow(Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable($country);
    bmxCoupon($state, $interval);

    $checkout = $m->checkout('pro', $interval, $addons, $seats, bmxCode($state));
    expect($checkout->get('couponError'))->toBeNull()
        ->and($checkout->instance()->lineNets())->toBe([...array_values($full), -bmxDiscount($state, (int) array_sum($full))]);

    $shown = $m->shown($checkout);
    $checkout->call('submit');
    expect(BookingMatrix::minor($m->lastPayment()))->toBe($shown['gross']);

    bmxFollow($m, $billable, $m->lastPayment(), $state, $interval, $country, $rate, $full);
})->with([
    // Pro includes 2 seats; every further one is 5.55 a month / 55.55 a year.
    'extra seats, DE' => ['DE', 19.0, 'monthly', [], 2, ['plan' => 2950, 'seats' => 1110]],
    'addon, AT' => ['AT', 20.0, 'monthly', ['sso'], 0, ['plan' => 2950, 'addon:sso' => 1550]],
    // CRM: 10.00 base + 3.33 for each of the 3 billed seats beyond its own free one.
    'per-seat addon, DE' => ['DE', 19.0, 'monthly', ['crm'], 1, ['plan' => 2950, 'seats' => 555, 'addon:crm' => 1000, 'addon_seats:crm' => 666]],
    'yearly with seats and per-seat addon, AT' => ['AT', 20.0, 'yearly', ['crm'], 1, ['plan' => 29950, 'seats' => 5555, 'addon:crm' => 10000, 'addon_seats:crm' => 6666]],
])->with('bmx coupon states');

/*
 * A dated recurring coupon that is over before the NEXT charge. A yearly plan booked with
 * a code valid for another 100 days: the first payment is discounted, the second (a year
 * later) is not — so the Mollie subscription, whose first charge IS that second payment,
 * has to be created at the full price. Created at the discounted amount, Mollie collects
 * the discount for a year the coupon no longer covers, and the invoice for it carries a
 * "payment adjustment" line because the books (rightly) price it in full.
 */
it('recurring coupon that ends before the next charge: the subscription is created at the full price', function (string $interval, int $validDays, array $full): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow($start = Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('AT');

    app(CouponService::class)->create([
        'code' => 'SHORT', 'name' => 'Short', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 15,
        'valid_until' => $start->copy()->addDays($validDays),
    ]);

    $subtotal = (int) array_sum($full);
    $discounted = $full + ['coupon:SHORT' => -(int) round($subtotal * 0.15)];

    $checkout = $m->checkout('pro', $interval, ['sso'], 0, 'SHORT');
    expect($checkout->get('couponError'))->toBeNull();
    $shown = $m->shown($checkout);
    $checkout->call('submit');

    expect(BookingMatrix::minor($m->lastPayment()))->toBe($shown['gross'])->toBe(bmxGross($discounted, 20.0));
    $m->assertInvoice($m->settle($m->lastPayment()), (int) array_sum($discounted), bmxGross($discounted, 20.0) - (int) array_sum($discounted), 20.0, $discounted);

    // The next charge falls after the coupon's last day: full price, and booked as such.
    expect($m->subscriptionMinor())->toBe(bmxGross($full, 20.0));
    $m->assertInvoice($m->renew($billable, $interval), $subtotal, bmxGross($full, 20.0) - $subtotal, 20.0, $full);
    $m->assertNoMismatch();
})->with([
    'yearly, valid 100 more days' => ['yearly', 100, ['plan' => 29950, 'addon:sso' => 15550]],
    'monthly, valid 10 more days' => ['monthly', 10, ['plan' => 2950, 'addon:sso' => 1550]],
]);

/*
 * The same with a trial in front. Team 19.50 + SSO 15.50 = 35.00 net, 14 days of trial, a
 * 15 % code valid for another 10 days: it is over before the trial ends, so the first
 * charge — at the trial end — is 42.00 (AT), not 35.70. What the checkout shows, what
 * Mollie holds and what the invoice says have to be that one number.
 */
it('recurring coupon that ends before the trial does: the first charge after the trial is the full price', function (): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow($start = Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('AT');

    app(CouponService::class)->create([
        'code' => 'SHORT', 'name' => 'Short', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 15,
        'valid_until' => $start->copy()->addDays(10),
    ]);

    $full = ['plan' => 1950, 'addon:sso' => 1550];

    $checkout = $m->checkout('team', 'monthly', ['sso'], 0, 'SHORT');

    // Answered at the field: the code would discount nothing.
    expect($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_ends_before_first_charge'))
        ->and($checkout->get('applied_coupon'))->toBeNull();

    $shown = $m->shown($checkout);
    $checkout->call('submit');

    expect(BookingMatrix::minor($m->lastPayment()))->toBe(0);
    expect($m->settle($m->lastPayment()))->toBeNull();

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($m->subscriptionStartDate())->toBe('2026-03-24')
        ->and($m->subscriptionMinor())->toBe(4200)
        ->and($shown['gross'])->toBe(4200);

    $m->assertInvoice($m->renew($billable, 'monthly', 'AT', $billable->trial_ends_at), 3500, 700, 20.0, $full);
    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe(0);
    $m->assertNoMismatch();
});

/*
 * The refusal above was made where the code is typed, and nowhere else. A code applied to a
 * plan without a trial stayed applied when the customer then picked one with a trial, and
 * a code applied while it still outlived the trial stayed applied once it no longer did:
 * the confirm step showed 35.70 for a first charge that is 42.00, and the submit went
 * through with it. The re-validation on a change of selection and on submit asks the same
 * question now.
 */
it('recurring coupon that ends before the trial does: refused also when the selection or the clock moves it there', function (string $how): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow($start = Carbon::parse(BMX_START, 'UTC'));
    $m->billable('AT');

    app(CouponService::class)->create([
        'code' => 'SHORT', 'name' => 'Short', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 15,
        'valid_until' => $start->copy()->addDays($how === 'plan' ? 10 : 15),
    ]);

    // Applied while it is good: on a plan without a trial, or on the trial plan with a
    // day to spare.
    $checkout = $m->checkout($how === 'plan' ? 'pro' : 'team', 'monthly', ['sso'], 0, 'SHORT');
    expect($checkout->get('couponError'))->toBeNull()
        ->and($checkout->get('applied_coupon'))->not->toBeNull();

    if ($how === 'plan') {
        // The customer goes back and picks the plan with a 14-day trial.
        $checkout->set('plan_code', 'team');

        expect($checkout->get('applied_coupon'))->toBeNull()
            ->and($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_ends_before_first_charge'))
            ->and($m->shown($checkout)['gross'])->toBe(4200);

        return;
    }

    // The page stays open over two days: the trial now ends after the code does.
    Carbon::setTestNow($start->copy()->addDays(2));
    $checkout->call('submit');

    expect($m->lastPayment())->toBeNull()
        ->and($checkout->get('applied_coupon'))->toBeNull()
        ->and($checkout->get('couponError'))->toBe(__('billing::checkout.coupon_ends_before_first_charge'))
        ->and($checkout->get('errorMessage'))->toBe(__('billing::checkout.coupon_dropped_revisit'));
})->with(['plan', 'clock']);

/*
 * … and behind the form: a code that reaches the activation anyway (an app that starts the
 * checkout itself, a promotion link) must not put its discount on the Mollie subscription
 * when it is over before the trial ends. 35.00 net, 15 % = 5.25.
 */
it('recurring coupon and a trial: the subscription carries the discount only if the code outlives the trial', function (int $validDays, int $recurringDiscount): void {
    $m = BookingMatrix::boot();
    Carbon::setTestNow($start = Carbon::parse(BMX_START, 'UTC'));
    $billable = $m->billable('AT');

    app(CouponService::class)->create([
        'code' => 'SHORT', 'name' => 'Short', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 15,
        'valid_until' => $start->copy()->addDays($validDays),
    ]);

    $priced = app(\GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing::class)
        ->price($billable, 'SHORT', 'team', 'monthly', ['sso'], 0, trialStarting: true);

    expect($priced['recurring_discount_net'])->toBe($recurringDiscount);
})->with([
    'valid 10 days, trial 14: over before the first charge' => [10, 0],
    'valid exactly until the trial end' => [14, 0],
    'valid 20 days: covers the first charge' => [20, 525],
]);

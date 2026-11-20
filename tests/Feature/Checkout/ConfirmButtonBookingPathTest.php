<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Features\SupportTesting\Testable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/*
 * The confirm step's button said "Confirm and pay" on every path — also under a free trial,
 * directly below the notice that nothing is charged during it. The label now follows the
 * branch StartSubscriptionCheckout::handle() will actually take (`bookingPath()`), and these
 * tests hold the label to that branch: each scenario is shown, then submitted, and what the
 * service really did is compared with what the button said.
 */

/** What the service really did on submit, read from what it sent to Mollie. */
function bpActualPath(BookingMatrix $m): string
{
    $payment = $m->lastPayment();

    if ($payment === null) {
        return 'local';
    }

    $metadata = (array) BookingMatrix::prop($payment, 'metadata');

    if (($metadata['type'] ?? null) !== 'mandate_only') {
        return 'payment';
    }

    return isset($metadata['pending_subscription_trial_days']) ? 'trial' : 'mandate';
}

function bpConfirm(Testable $checkout): Testable
{
    return $checkout->set('step', $checkout->instance()->customStepCount() + 4);
}

it('names the booking path on the button and the service takes exactly that path', function (string $scenario, string $path, string $label, string $actual): void {
    $m = BookingMatrix::boot();

    [$plan, $coupon] = match ($scenario) {
        'trial for a first customer' => (function () use ($m): array {
            $m->billable('AT');

            return ['team', null];
        })(),
        'no trial again for a customer with a mandate' => (function () use ($m): array {
            $m->billable('AT', ['mollie_mandate_id' => 'mdt_old', 'subscription_status' => SubscriptionStatus::Expired]);

            return ['team', null];
        })(),
        'paid plan without trial' => (function () use ($m): array {
            $m->billable('AT');

            return ['pro', null];
        })(),
        'free plan' => (function () use ($m): array {
            $m->billable('AT');

            return ['free', null];
        })(),
        'a code covering the whole first charge' => (function () use ($m): array {
            $m->billable('AT');
            app(CouponService::class)->create([
                'code' => 'ALL100', 'name' => 'All of it', 'active' => true, 'type' => CouponType::Recurring,
                'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 2,
            ]);

            return ['pro', 'ALL100'];
        })(),
        'an access grant on a plan with a trial' => (function () use ($m): array {
            $m->billable('AT');
            app(CouponService::class)->accessGrantCoupon('GIFT', 'team', 'monthly', [], 30);

            return ['team', 'GIFT'];
        })(),
    };

    $checkout = bpConfirm($m->checkout($plan, 'monthly', [], 0, $coupon));

    expect($checkout->get('couponError'))->toBeNull()
        ->and($checkout->instance()->bookingPath())->toBe($path);

    $checkout->assertSeeHtml('data-booking-path="'.$path.'"')
        ->assertSee(__('billing::checkout.'.$label));

    // Only the pay path says "pay".
    if ($path !== 'payment') {
        $checkout->assertDontSee(__('billing::checkout.confirm_and_pay'));
    }

    // A trial is announced on the trial path and on no other.
    $trialDays = $checkout->instance()->announcedTrialDays();
    expect($trialDays > 0)->toBe($path === 'trial');

    if ($path === 'trial') {
        $checkout->assertSee(__('billing::checkout.trial_summary_heading', ['days' => 14]))
            ->assertSee(__('billing::checkout.trial_redirect_notice', ['date' => $checkout->instance()->firstChargeDate()->translatedFormat('d. M Y')]));
    } else {
        $checkout->assertDontSee(__('billing::checkout.trial_summary_heading', ['days' => 14]));
    }

    $checkout->call('submit');

    expect(bpActualPath($m))->toBe($actual);
})->with([
    'trial for a first customer' => ['trial for a first customer', 'trial', 'confirm_start_trial', 'trial'],
    'no trial again for a customer with a mandate' => ['no trial again for a customer with a mandate', 'payment', 'confirm_and_pay', 'payment'],
    'paid plan without trial' => ['paid plan without trial', 'payment', 'confirm_and_pay', 'payment'],
    'free plan' => ['free plan', 'free', 'confirm_activate_free', 'local'],
    'a code covering the whole first charge' => ['a code covering the whole first charge', 'mandate', 'confirm_add_payment_method', 'mandate'],
    'an access grant on a plan with a trial' => ['an access grant on a plan with a trial', 'access_grant', 'confirm_activate_access', 'local'],
]);

it('tells a free or granted booking it is activated without payment instead of sending it to Mollie', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');

    bpConfirm($m->checkout('free'))
        ->assertSee(__('billing::checkout.free_activation_notice'))
        ->assertDontSee(__('billing::checkout.redirect_notice'));

    app(CouponService::class)->accessGrantCoupon('GIFT', 'pro', 'monthly', [], 30);

    bpConfirm($m->checkout('pro', 'monthly', [], 0, 'GIFT'))
        ->assertSee(__('billing::checkout.access_grant_notice'))
        ->assertDontSee(__('billing::checkout.redirect_notice'));
});

it('announces no trial for a free plan that carries trial days: the free plan is activated locally', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');
    config()->set('mollie-billing-plans.plans.free.intervals.monthly.trial_days', 14);

    $checkout = bpConfirm($m->checkout('free'));

    expect($checkout->instance()->bookingPath())->toBe('free')
        ->and($checkout->instance()->announcedTrialDays())->toBe(0)
        ->and($checkout->instance()->firstChargeDate())->toBeNull();

    $checkout->assertDontSee(__('billing::checkout.trial_summary_heading', ['days' => 14]))
        ->call('submit');

    expect($m->lastPayment())->toBeNull();
});

it('names the yearly booking of a paid plan a payment, too', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');

    $checkout = bpConfirm($m->checkout('pro', 'yearly'));

    expect($checkout->instance()->bookingPath())->toBe('payment');
    $checkout->assertSee(__('billing::checkout.confirm_and_pay'))->call('submit');

    $payment = $m->lastPayment();
    expect($payment)->toBeInstanceOf(CreatePaymentRequest::class)
        ->and(BookingMatrix::prop($payment, 'sequenceType'))->toBe('first')
        ->and(BookingMatrix::minor($payment))->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

// The confirm step announces a trial whenever `trialApplies()` is true — heading, body and
// "your first charge will be on <now + trial days>" — and `trialApplies()` documents itself
// as mirroring the gate in StartSubscriptionCheckout::handle().
//
// It did not mirror it for a coupon that covers the whole first payment: handle() tested
// `amount_gross === 0` BEFORE the trial branch and routed to the mandate-only flow without
// `trial_days`. Status Active at once, coupon redeemed against the period that was supposed
// to be the free trial, first full-price charge at now + 1 interval.
//
// The trial now wins. A SinglePayment coupon then discounts the first charge there is — the
// one at the trial's end (CouponService::applyFirstChargeMarkerFrom()). For a while the form
// refused such a code on a trial signup, because Mollie collected the full price at the
// trial's end and the reduced total shown was not what was charged; it is accepted again
// now that the trial-end charge really is the discounted one.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    config()->set('mollie-billing-plans.plans', [
        'solo' => [
            'name' => 'Solo',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'trial_days' => 0, 'included_usages' => []],
            ],
        ],
    ]);
});

function trialCheckoutBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => uniqid('trial-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::New])->save();
    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function captureMandatePayment(): \ArrayObject
{
    $captured = new \ArrayObject;

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($captured) {
        if ($request instanceof CreatePaymentRequest) {
            $metadata = (new ReflectionObject($request))->getProperty('metadata');
            $metadata->setAccessible(true);
            $captured->append((array) $metadata->getValue($request));

            return new class
            {
                public string $id = 'tr_mandate';

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/tr_mandate';
                }
            };
        }

        return (object) ['id' => 'cst_trial'];
    });

    return $captured;
}

function trialCheckoutForm(string $interval = 'monthly'): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'Trial Co')
        ->set('billing_street', 'Teststr. 1')
        ->set('billing_postal_code', '1010')
        ->set('billing_city', 'Wien')
        ->set('billing_country', 'AT')
        ->set('plan_code', 'solo')
        ->set('interval', $interval);
}

it('starts the trial when a coupon covers the whole first payment', function (CouponType $type): void {
    app(CouponService::class)->create([
        'code' => 'FIRSTFREE',
        'name' => 'First month free',
        'type' => $type,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 1,
    ]);

    $billable = trialCheckoutBillable();
    $captured = captureMandatePayment();

    // amount_gross 0 is what a form that priced the 100 % coupon submits.
    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'solo',
        'interval' => 'monthly',
        'coupon_code' => 'FIRSTFREE',
        'amount_gross' => 0,
    ]);

    expect($captured->count())->toBe(1)
        ->and($captured[0]['type'] ?? null)->toBe('mandate_only')
        ->and($captured[0]['pending_subscription_trial_days'] ?? null)->toBe(14)
        // The coupon travels with the trial; the trial path decides what it is worth.
        ->and($captured[0]['pending_subscription_coupon_code'] ?? null)->toBe('FIRSTFREE');
})->with(['single payment' => [CouponType::SinglePayment], 'recurring' => [CouponType::Recurring]]);

it('still routes a full-coverage coupon to the coupon flow when no trial can start', function (): void {
    app(CouponService::class)->create([
        'code' => 'FIRSTFREE',
        'name' => 'First month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    // A returning customer: mandate on file, so no trial — the coupon's own path applies.
    $billable = trialCheckoutBillable();
    $billable->forceFill(['mollie_customer_id' => 'cst_trial', 'mollie_mandate_id' => 'mdt_old'])->save();
    $captured = captureMandatePayment();

    app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'solo',
        'interval' => 'monthly',
        'coupon_code' => 'FIRSTFREE',
        'amount_gross' => 0,
    ]);

    expect($captured->count())->toBe(1)
        ->and($captured[0]['type'] ?? null)->toBe('mandate_only')
        ->and($captured[0])->not->toHaveKey('pending_subscription_trial_days');
});

it('accepts a first-payment coupon on a checkout that starts a trial, priced for the charge at its end', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALFOFF',
        'name' => 'Half off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    trialCheckoutBillable();

    $component = trialCheckoutForm()
        ->set('coupon_input', 'HALFOFF')
        ->call('applyCoupon');

    expect($component->instance()->trialApplies('solo', 'monthly'))->toBeTrue()
        ->and($component->get('couponError'))->toBeNull()
        ->and($component->get('applied_coupon')['discount_net'] ?? null)->toBe(500)
        ->and($component->instance()->bookingPath())->toBe('trial');

    // The total the confirm step shows is the one Mollie collects at the trial's end: half
    // of 10.00 plus 20 % AT VAT — and that charge is on the trial's last day.
    $totals = $component->instance()->totals(app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class));
    expect($totals['gross'])->toBe(600)
        ->and($component->instance()->firstChargeDate()?->toDateString())
        ->toBe(\GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->addDays(14)->toDateString());
});

it('names the trial end as the first charge for a first-payment coupon that covers it all, at 0.00', function (): void {
    app(CouponService::class)->create([
        'code' => 'FIRSTFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    trialCheckoutBillable();

    $component = trialCheckoutForm()
        ->set('coupon_input', 'FIRSTFREE')
        ->call('applyCoupon');

    $trialEnd = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->addDays(14);
    $totals = $component->instance()->totals(app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class));

    // The charge the code is for is the one at the trial's end; the customer is shown it
    // there, costing nothing — like a partial code, only at 0.00. Naming the day Mollie
    // first collects (a period later) read as a first charge postponed at full price.
    expect($component->get('couponError'))->toBeNull()
        ->and($component->instance()->bookingPath())->toBe('trial')
        ->and($totals['gross'])->toBe(0)
        ->and($component->instance()->firstChargeDate()?->toDateString())->toBe($trialEnd->toDateString());

    $component->set('step', $component->instance()->customStepCount() + 4)
        ->assertSee(__('billing::checkout.trial_covered_redirect_notice', [
            'date' => $trialEnd->translatedFormat('d. M Y'),
            'amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(0),
        ]), false);
});

it('keeps a first-payment coupon when the selection moves onto a plan with a trial', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALFOFF',
        'name' => 'Half off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    trialCheckoutBillable();

    $component = trialCheckoutForm('yearly')
        ->set('coupon_input', 'HALFOFF')
        ->call('applyCoupon');

    expect($component->get('couponError'))->toBeNull()
        ->and($component->get('applied_coupon'))->not->toBeNull();

    $component->set('interval', 'monthly');

    expect($component->get('couponError'))->toBeNull()
        ->and($component->get('applied_coupon')['discount_net'] ?? null)->toBe(500);
});

it('submits a trial with a first-payment coupon as a trial', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALFOFF',
        'name' => 'Half off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    trialCheckoutBillable();
    $captured = captureMandatePayment();

    trialCheckoutForm()
        ->set('coupon_input', 'HALFOFF')
        ->call('applyCoupon')
        ->call('submit');

    expect($captured->count())->toBe(1)
        ->and($captured[0]['pending_subscription_trial_days'] ?? null)->toBe(14)
        ->and($captured[0]['pending_subscription_coupon_code'] ?? null)->toBe('HALFOFF');
});

it('submits a trial with a recurring coupon as a trial', function (): void {
    app(CouponService::class)->create([
        'code' => 'TWENTY',
        'name' => 'Twenty',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    trialCheckoutBillable();
    $captured = captureMandatePayment();

    $component = trialCheckoutForm()
        ->set('coupon_input', 'TWENTY')
        ->call('applyCoupon');

    expect($component->get('couponError'))->toBeNull();

    $component->call('submit');

    expect($captured->count())->toBe(1)
        ->and($captured[0]['pending_subscription_trial_days'] ?? null)->toBe(14)
        ->and($captured[0]['pending_subscription_coupon_code'] ?? null)->toBe('TWENTY');
});

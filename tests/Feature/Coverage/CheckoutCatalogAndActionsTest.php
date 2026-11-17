<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/*
 * Every catalog field and every public method of the checkout, products, billing-data
 * and return components, exercised and asserted by its result. (The same options are
 * shown working in Chrome by tests/Browser/Matrix/CheckoutOptionsMatrixTest.php.)
 */

afterEach(fn () => Carbon::setTestNow());

function ccaCoupon(string $code, array $attributes = [], CouponType $type = CouponType::SinglePayment): Coupon
{
    return app(CouponService::class)->create(array_merge(['code' => $code, 'name' => $code, 'type' => $type, 'active' => true], $attributes));
}

/** The matrix catalog plus what the catalog tests need on top of it. */
function ccaWorld(): BookingMatrix
{
    $m = BookingMatrix::boot();

    config()->set('mollie-billing-plans.features', [
        'reports' => ['name' => 'Reports', 'description' => 'Detailed analytics.'],
        'internal' => ['name' => 'Internal Flag', 'description' => 'Never shown.', 'visible' => false],
        'crm-feature' => ['name' => 'CRM Records'],
    ]);
    config()->set('mollie-billing-plans.plans.pro.description', 'For teams that need seats.');
    config()->set('mollie-billing-plans.plans.pro.feature_keys', ['reports', 'internal']);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 7]);
    config()->set('mollie-billing-plans.addons.crm.feature_keys', ['crm-feature']);
    config()->set('mollie-billing-plans.plans.secret', [
        'name' => 'Secret', 'tier' => 9, 'hidden' => true, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 100, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    return $m;
}

// ── catalog: plans ─────────────────────────────────────────────────────────────

it('plan_config plans.name and plans.description: are what the plan step is fed and shows', function (): void {
    $m = ccaWorld();
    $m->billable('AT');

    $checkout = Livewire::test('mollie-billing::checkout');
    $plans = $checkout->instance()->plans();

    expect($plans['pro']['name'])->toBe('Pro')
        ->and($plans['pro']['description'])->toBe('For teams that need seats.');

    $checkout->assertSee('Pro')->assertSee('For teams that need seats.');
});

it('plan_config plans.hidden: a hidden plan is neither offered nor bookable', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    expect(Livewire::test('mollie-billing::checkout')->instance()->plans())->not->toHaveKey('secret')->toHaveKey('pro');

    // Not through `?plan=` either …
    expect(Livewire::withQueryParams(['plan' => 'secret'])->test('mollie-billing::checkout')->get('plan_code'))->not->toBe('secret');

    // … and not through the service.
    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, ['plan_code' => 'secret', 'interval' => 'monthly', 'amount_gross' => 120]))
        ->toThrow(HiddenPlanNotSelectableException::class);
    expect($m->lastPayment())->toBeNull();
});

it('plan_config plans.tier: is the app\'s own rank — the package prices and orders nothing by it', function (): void {
    $m = ccaWorld();
    $m->billable('AT');
    $before = $m->shown($m->checkout('pro', 'monthly', ['sso']));

    config()->set('mollie-billing-plans.plans.pro.tier', 99);

    expect($m->shown($m->checkout('pro', 'monthly', ['sso'])))->toBe($before);

    // What does read it is check-config: present but not an integer is an error.
    config()->set('mollie-billing-plans.plans.pro.tier', 'high');
    $this->artisan('billing:check-config')->expectsOutputToContain('plans.pro.tier')->assertExitCode(1);
});

it('plan_config plans.included_seats: seats up to it cost nothing, the per-seat addon counts them', function (): void {
    $m = ccaWorld();
    $m->billable('AT');

    // Pro includes 2 seats; the CRM gives 1 away: 1 charged CRM seat at 0 extra seats.
    expect($m->checkout('pro', 'monthly', ['crm'])->instance()->lineNets())->toBe([2950, 1000, 333]);

    config()->set('mollie-billing-plans.plans.pro.included_seats', 5);

    $checkout = $m->checkout('pro', 'monthly', ['crm']);
    expect($checkout->instance()->plans()['pro']['included_seats'])->toBe(5)
        ->and($checkout->instance()->lineNets())->toBe([2950, 1000, 4 * 333]);
});

it('plan_config plans.intervals.base_price_net: is the plan line, per interval', function (): void {
    $m = ccaWorld();
    $m->billable('AT');

    expect($m->checkout('pro', 'monthly')->instance()->subtotalNet())->toBe(2950)
        ->and($m->checkout('pro', 'yearly')->instance()->subtotalNet())->toBe(29950);

    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.base_price_net', 3333);
    expect($m->shown($m->checkout('pro', 'monthly')))->toMatchArray(['net' => 3333, 'gross' => 4000]);
});

it('plan_config plans.intervals.seat_price_net: prices extra seats, and null sells none', function (): void {
    $m = ccaWorld();
    $m->billable('AT');

    expect($m->checkout('pro', 'monthly', [], 3)->instance()->lineNets())->toBe([2950, 3 * 555])
        ->and($m->checkout('pro')->instance()->hasAddonsOrSeatsStep())->toBeTrue();

    // No seat price and no addons: there is no extras step, and seats are refused.
    expect($m->checkout('solo')->instance()->hasAddonsOrSeatsStep())->toBeFalse();
    $m->checkout('solo', 'monthly', [], 2)->call('submit')->assertHasErrors('extra_seats');
    expect($m->lastPayment())->toBeNull();
});

it('plan_config plans.intervals.trial_days: starts a trial of that length, per interval', function (): void {
    $m = ccaWorld();
    $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));

    $checkout = $m->checkout('team');
    expect($checkout->instance()->planTrialDays('team', 'monthly'))->toBe(14)
        ->and($checkout->instance()->announcedTrialDays())->toBe(14)
        ->and($checkout->instance()->firstChargeDate()->toDateString())->toBe('2026-03-24')
        ->and($m->checkout('pro')->instance()->announcedTrialDays())->toBe(0);

    config()->set('mollie-billing-plans.plans.team.intervals.monthly.trial_days', 30);
    $checkout = $m->checkout('team');
    $checkout->call('submit');

    expect($checkout->instance()->announcedTrialDays())->toBe(30)
        ->and(BookingMatrix::prop($m->lastPayment(), 'metadata')['pending_subscription_trial_days'])->toBe(30);
});

it('plan_config plans.intervals.included_usages and usage_overage_prices: are stated on the plan card and credited on activation', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    $checkout = $m->checkout('pro');
    expect($checkout->instance()->planUsages('pro', 'monthly'))->toBe([
        'Tokens' => ['label' => 'Tokens', 'included' => 100, 'overage_price' => 7],
    ]);
    $checkout->assertSee(__('billing::checkout.usage_included', ['count' => '100', 'type' => 'Tokens']));

    $checkout->call('submit');
    $m->settle($m->lastPayment());

    expect($billable->fresh()->remainingBillingQuota('Tokens'))->toBe(100)
        ->and($billable->fresh()->billingOveragePrice('Tokens'))->toBe(7);
});

it('plan_config plans.allowed_addons: only those are offered, and only those can be booked', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    expect(array_keys($m->checkout('pro')->instance()->planAddons('pro', 'monthly')))->toBe(['sso', 'crm'])
        ->and(array_keys($m->checkout('team')->instance()->planAddons('team', 'monthly')))->toBe(['sso']);

    $m->checkout('team', 'monthly', ['crm'])->call('submit')->assertHasErrors('addon_codes.0');
    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, ['plan_code' => 'team', 'interval' => 'monthly', 'addon_codes' => ['crm'], 'amount_gross' => 5000]))
        ->toThrow(AddonNotAllowedException::class);
    expect($m->lastPayment())->toBeNull();
});

it('plan_config plans.feature_keys, features.name, features.description, features.visible: the visible ones are listed, all of them unlock', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    $checkout = $m->checkout('pro');
    expect($checkout->instance()->planFeatures('pro'))->toBe([
        ['key' => 'reports', 'name' => 'Reports', 'description' => 'Detailed analytics.'],
    ]);
    $checkout->assertSee('Reports')->assertSee('Detailed analytics.')->assertDontSee('Internal Flag')->assertDontSee('Never shown.');

    $checkout->call('submit');
    $m->settle($m->lastPayment());

    expect($billable->fresh()->hasPlanFeature('reports'))->toBeTrue()
        ->and($billable->fresh()->hasPlanFeature('internal'))->toBeTrue()
        ->and($billable->fresh()->hasPlanFeature('crm-feature'))->toBeFalse();
});

// ── catalog: addons ────────────────────────────────────────────────────────────

it('plan_config addons.name, addons.intervals.price_net, seat_price_net, included_seats: price the addon and its seats', function (): void {
    $m = ccaWorld();
    $m->billable('AT');

    $checkout = $m->checkout('pro', 'monthly', ['crm'], 2);
    $addons = $checkout->instance()->addons();

    expect($addons['crm']['name'])->toBe('CRM')
        ->and($addons['crm']['intervals']['monthly'])->toBe(['price_net' => 1000, 'seat_price_net' => 333])
        ->and($addons['crm']['included_seats'])->toBe(1)
        // 4 seats in all, 1 free: 3 × 3.33.
        ->and($checkout->instance()->addonSeatsNet('crm'))->toBe(999)
        ->and($checkout->instance()->addonSeatsNet('sso'))->toBe(0)
        ->and($checkout->instance()->lineNets())->toBe([2950, 1110, 1000, 999]);

    config()->set('mollie-billing-plans.addons.crm.included_seats', 3);
    config()->set('mollie-billing-plans.addons.crm.intervals.monthly', ['price_net' => 1200, 'seat_price_net' => 100]);

    expect($m->checkout('pro', 'monthly', ['crm'], 2)->instance()->lineNets())->toBe([2950, 1110, 1200, 100]);
});

it('plan_config addons.feature_keys: an addon unlocks its features once it is booked', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    $m->checkout('pro', 'monthly', ['crm'])->call('submit');
    $m->settle($m->lastPayment());

    expect($billable->fresh()->hasPlanFeature('crm-feature'))->toBeTrue()
        ->and(app(SubscriptionCatalogInterface::class)->addonFeatures('crm'))->toBe(['crm-feature']);
});

it('plan_config usage_types.rollover: unused units of a rollover type survive the renewal, others are reset', function (): void {
    $m = ccaWorld();
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100, 'SMS' => 50]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => true], 'SMS' => ['rollover' => false]]);
    $billable = $m->billable('AT');

    $m->checkout('pro')->call('submit');
    $m->settle($m->lastPayment());

    $billable->fresh()->recordBillingUsage('Tokens', 30);
    $billable->fresh()->recordBillingUsage('SMS', 30);
    $m->renew($billable);

    // Tokens: 70 left + 100 new. SMS: back to the quota.
    expect($billable->fresh()->remainingBillingQuota('Tokens'))->toBe(170)
        ->and($billable->fresh()->remainingBillingQuota('SMS'))->toBe(50);
});

// ── checkout: navigation ───────────────────────────────────────────────────────

it('actions checkout@next, @back, @displayStep, @totalSteps, @timelineSteps, @timelineStatus, @stepHeadline, @stepDescription, @customStepCount, @hasAddonsOrSeatsStep: walk the steps', function (): void {
    $m = ccaWorld();
    MollieBilling::resolveBillableUsing(fn () => null);

    $c = Livewire::test('mollie-billing::checkout');
    $i = fn () => $c->instance();

    // Four steps for a plan with extras; the billing step is first.
    $c->set('plan_code', 'pro');
    expect($i()->customStepCount())->toBe(0)
        ->and($i()->totalSteps())->toBe(4)
        ->and($i()->displayStep())->toBe(1)
        ->and($i()->stepHeadline())->toBe(__('billing::checkout.headline_billing'))
        ->and($i()->stepDescription())->toBe(__('billing::checkout.description_billing'))
        ->and(array_column($i()->timelineSteps(), 'label'))->toBe([
            __('billing::checkout.step_billing_details'), __('billing::checkout.step_plan'),
            __('billing::checkout.step_addons'), __('billing::checkout.step_confirm'),
        ])
        ->and($i()->timelineStatus(1))->toBe('current')
        ->and($i()->timelineStatus(2))->toBe('incomplete');

    // next() validates the step it leaves.
    $c->call('next')->assertHasErrors(['company_name', 'billing_street']);
    expect($c->get('step'))->toBe(1);

    $c->set('company_name', 'Acme')->set('billing_street', 'Weg 1')->set('billing_postal_code', '1010')
        ->set('billing_city', 'Wien')->set('billing_country', 'AT')->call('next');
    expect($c->get('step'))->toBe(2)
        ->and($i()->stepHeadline())->toBe(__('billing::checkout.headline_plan'))
        ->and($i()->timelineStatus(1))->toBe('complete');

    $c->call('next');
    expect($c->get('step'))->toBe(3)->and($i()->stepHeadline())->toBe(__('billing::checkout.headline_addons'));

    $c->call('next');
    expect($c->get('step'))->toBe(4)
        ->and($i()->stepHeadline())->toBe(__('billing::checkout.headline_confirm'))
        ->and($i()->stepDescription())->toBe(__('billing::checkout.description_confirm'))
        ->and($i()->displayStep())->toBe(4);

    $c->call('back');
    expect($c->get('step'))->toBe(3);

    // A plan without addons or seat price has no extras step: three steps, and back()
    // from the confirm step lands on the plan.
    $c->set('step', 2)->set('plan_code', 'solo');
    expect($i()->hasAddonsOrSeatsStep())->toBeFalse()->and($i()->totalSteps())->toBe(3);
    $c->call('next');
    expect($c->get('step'))->toBe(4)->and($i()->displayStep())->toBe(3);
    $c->call('back');
    expect($c->get('step'))->toBe(2);

    // A custom step of the app comes first and is counted.
    MollieBilling::checkoutStepsUsing(fn (): array => [[
        'key' => 'terms', 'label' => 'Terms', 'headline' => 'Accept the terms', 'description' => 'Before anything else.',
        'view' => 'mollie-billing::livewire.billing.components.checkout.step-billing',
    ]]);
    $custom = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');
    expect($custom->instance()->customStepCount())->toBe(1)
        ->and($custom->instance()->totalSteps())->toBe(5)
        ->and($custom->instance()->stepHeadline())->toBe('Accept the terms')
        ->and($custom->instance()->stepDescription())->toBe('Before anything else.')
        ->and($custom->instance()->timelineSteps()[0]['label'])->toBe('Terms');
});

it('actions checkout@countries, @plans, @addons, @selectedPlan, @offeredIntervals, @planAddons, @planFeatures, @planUsages, @planTrialDays, @trialApplies: feed the steps from the catalog', function (): void {
    $m = ccaWorld();
    $m->billable('AT');
    config()->set('mollie-billing.checkout_countries', ['regions' => [], 'include' => ['AT', 'DE'], 'exclude' => []]);

    $c = $m->checkout('pro');
    $i = $c->instance();

    expect(array_keys($i->countries()))->toEqualCanonicalizing(['AT', 'DE'])
        ->and(array_keys($i->plans()))->toEqualCanonicalizing(['free', 'pro', 'team', 'solo'])
        ->and(array_keys($i->addons()))->toEqualCanonicalizing(['sso', 'crm'])
        ->and($i->selectedPlan()['name'])->toBe('Pro')
        ->and($i->offeredIntervals('pro'))->toBe(['monthly', 'yearly'])
        ->and($i->offeredIntervals('team'))->toBe(['monthly'])
        ->and($i->planAddons('pro', 'yearly')['sso']['price_net'])->toBe(15550)
        ->and(array_column($i->planFeatures('pro'), 'key'))->toBe(['reports'])
        ->and($i->planUsages('pro', 'monthly')['Tokens']['included'])->toBe(100)
        ->and($i->planTrialDays('team', 'monthly'))->toBe(14)
        ->and($i->planTrialDays('pro', 'monthly'))->toBe(0)
        ->and($i->trialApplies('team', 'monthly'))->toBeTrue()
        ->and($i->trialApplies('pro', 'monthly'))->toBeFalse();

    // A plan sold monthly only cannot be put on yearly.
    expect($m->checkout('team', 'yearly')->get('interval'))->toBe('monthly');

    // No trial for a customer with a mandate on file.
    $m->billable('AT', ['mollie_mandate_id' => 'mdt_old']);
    expect($m->checkout('team')->instance()->trialApplies('team', 'monthly'))->toBeFalse();
});

it('actions checkout@subtotalNet, @totalNet, @lineNets, @totals, @vatRate, @displayAmount, @showsNetPrices, @addonSeatsNet: price the order', function (): void {
    $m = ccaWorld();
    $vat = app(VatCalculationService::class);
    ccaCoupon('TEN', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

    // Consumer in Germany: gross prices, VAT per line.
    $m->billable('DE');
    $c = $m->checkout('pro', 'monthly', ['sso', 'crm'], 1, 'TEN');
    $i = $c->instance();

    // Pro 29.50, 1 seat 5.55, SSO 15.50, CRM 10.00 + 2 × 3.33; 10 % of 67.21 = 6.72.
    // VAT is rounded per invoice line: 561 + 105 + 295 + 190 + 127 − 128 = 1150.
    expect($i->subtotalNet())->toBe(6721)
        ->and($i->addonSeatsNet('crm'))->toBe(666)
        ->and($i->totalNet())->toBe(6049)
        ->and($i->lineNets())->toBe([2950, 555, 1550, 1000, 666, -672])
        ->and($i->vatRate($vat))->toBe(19.0)
        ->and($i->showsNetPrices())->toBeFalse()
        ->and($i->displayAmount(2950, $vat))->toBe(3511)
        ->and($i->displayAmount(0, $vat))->toBe(0)
        ->and($i->totals($vat))->toBe(['net' => 6049, 'vat' => 1150, 'gross' => 7199, 'rate' => 19.0, 'reverseCharge' => false]);

    // A reverse-charged business: net prices, no VAT.
    $m->business('DE', 'DE123456789');
    $rc = $m->checkout('pro', 'monthly', ['sso']);
    expect($rc->instance()->showsNetPrices())->toBeTrue()
        ->and($rc->instance()->displayAmount(2950, $vat))->toBe(2950)
        ->and($rc->instance()->totals($vat))->toBe(['net' => 4500, 'vat' => 0, 'gross' => 4500, 'rate' => 0.0, 'reverseCharge' => true]);
});

it('actions checkout@applyCoupon, @removeCoupon, @submit: a coupon is applied, removed, and what is shown is what is sent', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');
    ccaCoupon('TEN', ['discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

    $c = $m->checkout('pro', 'monthly', ['sso']);

    $c->set('coupon_input', '')->call('applyCoupon');
    expect($c->get('couponError'))->toBe(__('billing::checkout.coupon_empty'));

    $c->set('coupon_input', 'ten')->call('applyCoupon');
    expect($c->get('applied_coupon'))->toMatchArray(['code' => 'TEN', 'discount_net' => 450])
        ->and($c->get('coupon_input'))->toBe('')
        ->and($m->shown($c)['gross'])->toBe(4860);

    $c->call('removeCoupon');
    expect($c->get('applied_coupon'))->toBeNull()->and($m->shown($c)['gross'])->toBe(5400);

    $c->set('coupon_input', 'TEN')->call('applyCoupon')->call('submit');

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(4860)
        ->and(BookingMatrix::prop($payment, 'metadata'))->toMatchArray(['plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => ['sso'], 'coupon_code' => 'TEN'])
        ->and($billable->fresh()->getPendingFirstPaymentId())->not->toBeNull();
});

it('actions checkout@announcedTrialDays, @firstChargeDate: name the trial and the date money is first collected', function (): void {
    $m = ccaWorld();
    $m->billable('AT');
    Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00', 'UTC'));
    ccaCoupon('PLUS7', ['trial_extension_days' => 7], CouponType::TrialExtension);

    expect($m->checkout('pro')->instance()->announcedTrialDays())->toBe(0)
        ->and($m->checkout('pro')->instance()->firstChargeDate())->toBeNull();

    $c = $m->checkout('team', 'monthly', [], 0, 'PLUS7');
    expect($c->instance()->announcedTrialDays())->toBe(21)
        ->and($c->instance()->firstChargeDate()->toDateString())->toBe('2026-03-31');
});

// ── products ───────────────────────────────────────────────────────────────────

it('actions products@applyCoupon, @removeCoupon, @canAddMoreCouponsFor, @purchase: coupons on a product, and the purchase', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');
    ccaCoupon('STACK', ['discount_type' => DiscountType::Fixed, 'discount_value' => 500, 'stackable' => true]);
    ccaCoupon('ALONE', ['discount_type' => DiscountType::Fixed, 'discount_value' => 700, 'stackable' => false]);

    $p = Livewire::test('mollie-billing::products');

    expect($p->instance()->canAddMoreCouponsFor('pack'))->toBeTrue();

    $p->set('couponInputs.pack', 'STACK')->call('applyCoupon', 'pack');
    expect($p->get('appliedCouponCodes')['pack'])->toBe(['STACK'])
        ->and($p->get('couponDiscounts')['pack'])->toBe(500)
        ->and($p->instance()->canAddMoreCouponsFor('pack'))->toBeTrue();

    $p->call('removeCoupon', 'pack', 'STACK');
    expect($p->get('appliedCouponCodes')['pack'] ?? [])->toBe([])
        ->and($p->get('couponDiscounts')['pack'] ?? 0)->toBe(0);

    $p->set('couponInputs.pack', 'ALONE')->call('applyCoupon', 'pack');
    expect($p->instance()->canAddMoreCouponsFor('pack'))->toBeFalse();

    $p->call('purchase', 'pack');

    // 49.50 − 7.00 = 42.50, + 20 %.
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(5100)
        ->and(BookingMatrix::prop($payment, 'metadata'))->toMatchArray(['type' => 'one_time_order', 'product_code' => 'pack', 'coupon_codes' => ['ALONE']]);

    $m->settle($payment);
    expect((int) BillingInvoice::query()->sole()->amount_gross)->toBe(5100)
        ->and($billable->fresh()->boughtBillingProducts())->toBe(['pack']);
});

// ── billing data ───────────────────────────────────────────────────────────────

it('actions billing-data@countries, @save, @isReverseCharge, @paymentCountryMismatch, @mandateInfo: the address, the VAT number and what they imply', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT', ['tax_country_payment' => 'AT']);
    config()->set('mollie-billing.checkout_countries', ['regions' => [], 'include' => ['AT', 'DE'], 'exclude' => []]);

    $c = Livewire::test('mollie-billing::billing-data');

    expect(array_keys($c->instance()->countries()))->toEqualCanonicalizing(['AT', 'DE'])
        ->and($c->instance()->isReverseCharge())->toBeFalse()
        ->and($c->instance()->paymentCountryMismatch($billable))->toBeNull()
        ->and($c->instance()->mandateInfo($billable))->toBeNull()
        ->and($c->instance()->mandateInfo(null))->toBeNull();

    $c->set('billing_street', '')->call('save')->assertHasErrors('billing_street');

    $c->set('company_name', 'Neu GmbH')->set('billing_street', 'Neue Gasse 2')->set('billing_postal_code', '80331')
        ->set('billing_city', 'München')->set('billing_country', 'DE')->call('save')->assertHasNoErrors();

    $billable->refresh();
    expect($billable->getBillingName())->toBe('Neu GmbH')
        ->and($billable->getBillingStreet())->toBe('Neue Gasse 2')
        ->and($billable->billing_country)->toBe('DE')
        ->and($billable->tax_country_user)->toBe('DE')
        // The card on file is Austrian, the address now German: the page says so.
        ->and($c->instance()->paymentCountryMismatch($billable))->toBe('AT');

    // A VIES-valid number from another member state: saved, audited, reverse charge.
    $m->business('DE', 'DE999999999');
    $b2b = Livewire::test('mollie-billing::billing-data');
    expect($b2b->instance()->isReverseCharge())->toBeTrue();

    // An unknown number is refused and nothing is stored.
    $b2b->set('vat_number', 'DE000000000')->call('save')->assertHasErrors('vat_number');
});

it('actions billing-data@changePaymentMethod, @checkForMandateUpdate: a new mandate is asked for at Mollie and noticed when it arrives', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT', ['mollie_mandate_id' => 'mdt_old']);

    Livewire::test('mollie-billing::billing-data')->call('changePaymentMethod')->assertRedirect();

    $payment = collect($m->sentOf(CreatePaymentRequest::class))->last();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only')
        ->and(BookingMatrix::prop($payment, 'sequenceType'))->toBe('first')
        ->and((string) BookingMatrix::prop($payment, 'redirectUrl'))->toContain('payment_method_updated=1');

    // Back from Mollie: the page waits until the webhook has swapped the mandate.
    $back = Livewire::withQueryParams(['payment_method_updated' => 1])->test('mollie-billing::billing-data');
    expect($back->get('awaitingMandateUpdate'))->toBeTrue();

    $back->call('checkForMandateUpdate');
    expect($back->get('awaitingMandateUpdate'))->toBeTrue();

    $billable->forceFill(['mollie_mandate_id' => 'mdt_new'])->save();
    $back->call('checkForMandateUpdate');
    expect($back->get('awaitingMandateUpdate'))->toBeFalse();
});

/*
 * Mollie calls the webhook when the payment settles and sends the customer back when they
 * click — and the webhook is regularly first. The page took "the mandate I see now" as the
 * one to wait to change, so it then waited for a change that had already happened: the
 * "waiting for confirmation" notice never went away and the page polled every 2 s for good.
 */
it('billing-data: a mandate the webhook replaced before the customer came back is not waited for', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT', ['mollie_mandate_id' => 'mdt_old']);

    Livewire::test('mollie-billing::billing-data')->call('changePaymentMethod')->assertRedirect();

    // The webhook lands first.
    $billable->forceFill(['mollie_mandate_id' => 'mdt_new'])->save();

    $back = Livewire::withQueryParams(['payment_method_updated' => 1])->test('mollie-billing::billing-data');
    expect($back->get('awaitingMandateUpdate'))->toBeFalse();

    // Reloading the same URL does not start waiting again either.
    expect(Livewire::withQueryParams(['payment_method_updated' => 1])->test('mollie-billing::billing-data')->get('awaitingMandateUpdate'))->toBeFalse();
});

/*
 * A payment-method change that is never confirmed — abandoned or failed at Mollie after the
 * redirect back. The page polled every two seconds for as long as it stayed open. It now
 * gives up after its tick budget and offers to check again.
 */
it('actions billing-data@recheckMandateUpdate: waiting for a new mandate ends after its tick budget and can be resumed', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT', ['mollie_mandate_id' => 'mdt_old']);

    Livewire::test('mollie-billing::billing-data')->call('changePaymentMethod')->assertRedirect();

    $back = Livewire::withQueryParams(['payment_method_updated' => 1])->test('mollie-billing::billing-data');
    expect($back->get('awaitingMandateUpdate'))->toBeTrue()
        ->and($back->get('mandateUpdateTimedOut'))->toBeFalse();
    $back->assertSee(__('billing::portal.payment_method.awaiting_update'));

    // 45 ticks of 2 s: a minute and a half.
    for ($tick = 1; $tick <= 44; $tick++) {
        $back->call('checkForMandateUpdate');
    }
    expect($back->get('awaitingMandateUpdate'))->toBeTrue();

    $back->call('checkForMandateUpdate');
    expect($back->get('awaitingMandateUpdate'))->toBeFalse()
        ->and($back->get('mandateUpdateTimedOut'))->toBeTrue();
    $back->assertSee(__('billing::portal.payment_method.update_timeout'))
        ->assertSee(__('billing::portal.payment_method.check_again'))
        ->assertDontSee(__('billing::portal.payment_method.awaiting_update'));

    // "Check again" with nothing new: back to waiting, with a fresh budget.
    $back->call('recheckMandateUpdate');
    expect($back->get('awaitingMandateUpdate'))->toBeTrue()
        ->and($back->get('mandateUpdateTimedOut'))->toBeFalse()
        ->and($back->get('mandatePollCount'))->toBe(0);

    // Run out again, the webhook arrives late, "check again" sees it at once.
    for ($tick = 1; $tick <= 45; $tick++) {
        $back->call('checkForMandateUpdate');
    }
    expect($back->get('mandateUpdateTimedOut'))->toBeTrue();

    $billable->forceFill(['mollie_mandate_id' => 'mdt_new'])->save();
    $back->call('recheckMandateUpdate');
    expect($back->get('awaitingMandateUpdate'))->toBeFalse()
        ->and($back->get('mandateUpdateTimedOut'))->toBeFalse();
    $back->assertDontSee(__('billing::portal.payment_method.update_timeout'));
});

/*
 * The poll asks for the billable on every tick. When that answer is "none" — the session
 * ended, the tenant was switched in another tab — the tick returned before counting
 * itself, so the budget above never ran out and the page polled every two seconds for as
 * long as it stayed open.
 */
it('billing-data: waiting for a new mandate ends after its tick budget also when the billable is gone', function (): void {
    $m = ccaWorld();
    $m->billable('AT', ['mollie_mandate_id' => 'mdt_old']);

    Livewire::test('mollie-billing::billing-data')->call('changePaymentMethod')->assertRedirect();

    $back = Livewire::withQueryParams(['payment_method_updated' => 1])->test('mollie-billing::billing-data');
    expect($back->get('awaitingMandateUpdate'))->toBeTrue();

    \GraystackIT\MollieBilling\Facades\MollieBilling::resolveBillableUsing(fn () => null);

    for ($tick = 1; $tick <= 45; $tick++) {
        $back->call('checkForMandateUpdate');
    }

    expect($back->get('awaitingMandateUpdate'))->toBeFalse()
        ->and($back->get('mandateUpdateTimedOut'))->toBeTrue();
});

// ── return page ────────────────────────────────────────────────────────────────

it('actions return@checkStatus, @shouldPoll, @pollInterval, @retry, @returnUrl, @retryUrl, @checkoutUrl: every outcome of a checkout', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');

    // Still open: the page polls.
    $billable->recordPendingFirstPayment('tr_pending');
    $m->intercept = fn (object $r) => $r instanceof \Mollie\Api\Http\Requests\GetPaymentRequest ? (object) ['id' => 'tr_pending', 'status' => 'open'] : null;

    $page = Livewire::test('mollie-billing::return');
    expect($page->get('activated'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue()
        ->and($page->instance()->pollInterval())->toMatch('/^\d+(ms|s)$/')
        ->and($page->instance()->returnUrl())->toBe(route('billing.index'))
        ->and($page->instance()->checkoutUrl())->toBe(route('billing.checkout'))
        ->and($page->instance()->retryUrl())->toBe(route('billing.checkout'));

    $page->call('checkStatus');
    expect($page->get('pollCount'))->toBe(1)->and($page->get('failed'))->toBeFalse();

    // Failed / cancelled / expired at Mollie: the page says which, and stops polling.
    foreach (['failed', 'canceled', 'expired'] as $status) {
        $billable->fresh()->recordPendingFirstPayment('tr_'.$status);
        $m->intercept = fn (object $r) => $r instanceof \Mollie\Api\Http\Requests\GetPaymentRequest ? (object) ['id' => 'tr_x', 'status' => $status] : null;

        $failed = Livewire::test('mollie-billing::return')->call('checkStatus');

        expect($failed->get('failed'))->toBeTrue()
            ->and($failed->get('failureReason'))->toBe($status)
            ->and($failed->instance()->shouldPoll())->toBeFalse()
            ->and($billable->fresh()->getPendingFirstPaymentId())->toBeNull();

        $failed->assertSee(__('billing::portal.return.failed_title'))
            ->assertSee(__('billing::portal.return.failed_body_'.$status));

        // retry() puts the page back into polling.
        $failed->call('retry');
        expect($failed->get('failed'))->toBeFalse()->and($failed->get('pollCount'))->toBe(0);
    }

    // Paid: activated, nothing left to poll.
    $m->intercept = null;
    app(ActivateLocalSubscription::class)->handle($billable->fresh(), 'free', 'monthly');

    $paid = Livewire::test('mollie-billing::return');
    expect($paid->get('activated'))->toBeTrue()->and($paid->instance()->shouldPoll())->toBeFalse();
    $paid->assertSee(__('billing::portal.return.title'));

    // A product order reports on its own payment and leads back to the products page.
    $product = Livewire::withQueryParams(['origin' => 'products', 'order_token' => 'unknown'])->test('mollie-billing::return');
    expect($product->get('unresolved'))->toBeTrue()
        ->and($product->instance()->returnUrl())->toBe(route('billing.products'))
        ->and($product->instance()->retryUrl())->toBe(route('billing.products'));
    $product->assertSee(__('billing::portal.return.unresolved_title'));
});

// ── screens without an action of their own ─────────────────────────────────────

it('screens billing.index and billing.blocked: the dashboard states the booked plan, the blocked page names the country', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');
    $m->checkout('pro', 'monthly', ['crm'])->call('submit');
    $m->settle($m->lastPayment());

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('Pro')
        ->assertSee('CRM');

    $this->withoutVite();
    $this->get(route('billing.blocked', ['country' => 'ru']))->assertOk()
        ->assertSee(__('billing::portal.blocked.heading'))
        ->assertSee(__('billing::portal.blocked.detected_country', ['code' => 'RU']));
    $this->get(route('billing.blocked', ['country' => '<script>']))->assertOk()
        ->assertSee(__('billing::portal.blocked.body_unknown_country'))
        ->assertDontSee(__('billing::portal.blocked.detected_country', ['code' => '']));
});

// ── what a page must not carry, and what it must say ───────────────────────────

/*
 * A customer on a free plan may not buy products (unless the app allows it). The buy
 * button was disabled and the server refused — but the purchase dialog, with its live
 * "purchase" button, was still rendered into the page for every product.
 */
it('products: a customer who may not buy gets no purchase dialog at all', function (): void {
    $m = ccaWorld();
    $billable = $m->billable('AT');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');

    config()->set('mollie-billing.local_subscription.allow_one_time_orders', false);
    Livewire::test('mollie-billing::products')
        ->assertSee(__('billing::portal.free_plan_no_products'))
        ->assertDontSee("purchase('pack')", false)
        ->assertDontSee('purchase-pack', false);

    config()->set('mollie-billing.local_subscription.allow_one_time_orders', true);
    Livewire::test('mollie-billing::products')
        ->assertDontSee(__('billing::portal.free_plan_no_products'))
        ->assertSee("purchase('pack')", false);
});

/*
 * `addons.*.description` is handed to the extras step by `checkout@addons()` — and was
 * printed nowhere, so the customer chose an addon by its name alone.
 */
it('checkout: the extras step prints an addon\'s description', function (): void {
    $m = ccaWorld();
    config()->set('mollie-billing-plans.addons.sso.description', 'Sign in with your company account.');
    $m->billable('AT');

    $checkout = $m->checkout('pro');
    $html = '';

    for ($i = 0; $i < 3 && ! str_contains($html, __('billing::checkout.addons_heading')); $i++) {
        $html = $checkout->call('next')->html();
    }

    expect($html)->toContain(__('billing::checkout.addons_heading'))
        ->toContain('Sign in with your company account.');
});

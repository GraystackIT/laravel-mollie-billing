<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The admin screens for giving free time, and the coupon form's explanations.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => ['tokens' => 100]]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
    ]);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_tab']);
});

function freeTimePaying(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Tab Paying', 'email' => uniqid('tab-pay-').'@x.test', 'billing_country' => 'AT']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => ['sso'],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tab', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_tab',
        'mollie_mandate_id' => 'mdt_tab',
    ])->save();

    return $b->fresh();
}

function freeTimeGifted(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Tab Gifted', 'email' => uniqid('tab-gift-').'@x.test', 'billing_country' => 'AT']);
    $service = app(CouponService::class);
    $service->redeem($service->accessGrantCoupon('GRANT-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], 30), $b->fresh(), []);

    return $b->fresh();
}

it('extends a paying subscription by days from the subscription tab', function (): void {
    $b = freeTimePaying();
    $before = $b->nextBillingDate();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->assertSee(__('billing::admin.billables.subscription.free_time.extend_days_title'))
        ->set('extendDays', '14')
        ->call('extendSubscription')
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.extended_days', ['count' => 14]))
        ->assertSee(__('billing::admin.billables.subscription.mode_period_extension'));

    expect($b->fresh()->nextBillingDate()->toDateString())->toBe($before->copy()->addDays(14)->toDateString());
});

it('gives free plan periods from the subscription tab and lists them with a revoke button', function (): void {
    $b = freeTimePaying();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('freePeriods', '3')
        ->call('giveFreePlanPeriods')
        ->assertSet('error', null)
        ->assertSee(__('billing::admin.billables.subscription.mode_plan_waiver', ['count' => 3]))
        ->assertSeeHtml('openRevoke(');

    expect(app(PlanWaiver::class)->marker($b->fresh())['max_applications'])->toBe(3);
});

it('extends a gift and changes what happens at its end from the subscription tab', function (): void {
    $b = freeTimeGifted();
    $before = $b->getBillingSubscriptionEndsAt();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->assertSee(__('billing::admin.billables.subscription.free_time.extend_grant_title'))
        ->set('grantDays', '60')
        ->set('grantOnExpiry', 'continue_paid')
        ->call('extendGrant')
        ->assertSet('error', null);

    $b->refresh();

    expect($b->getBillingSubscriptionEndsAt()->toDateString())->toBe($before->copy()->addDays(60)->toDateString())
        ->and($b->getBillingSubscriptionMeta()['grant_on_expiry'])->toBe('continue_paid');
});

it('rejects a number that is not a positive whole number, without doing anything', function (string $input): void {
    $b = freeTimePaying();
    $before = $b->nextBillingDate();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('extendDays', $input)
        ->call('extendSubscription')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.extension_number_invalid'));

    expect($b->fresh()->nextBillingDate()->toDateString())->toBe($before->toDateString())
        ->and(Coupon::query()->where('code', 'like', 'EXT-%')->count())->toBe(0);
})->with(['', '0', '-3', '2.5', 'abc', '99999']);

it('shows a refusal as a sentence, not as an exception', function (): void {
    $b = freeTimePaying();
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'REC20', 'name' => '20%', 'type' => CouponType::Recurring,
        'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage,
        'discount_value' => 20, 'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $b, 6500, 'monthly');

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('freePeriods', '2')
        ->call('giveFreePlanPeriods')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.extension_refused', [
            'reason' => __('billing::admin.billables.subscription.extension_reason.recurring_conflict'),
        ]));
});

it('does not offer a revoke button for a period extension, and refuses a forged one', function (): void {
    $b = freeTimePaying();
    $redemption = app(\GraystackIT\MollieBilling\Services\Billing\AdminExtensionService::class)->extendByDays($b, 7);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        // No row offers it (the modal's own button is always in the page).
        ->assertDontSeeHtml('openRevoke(')
        ->call('openRevoke', $redemption->id)
        ->assertSet('showRevokeModal', false)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.grant_not_revocable'));
});

it('does not list the renewals a plan waiver waived as separate grants', function (): void {
    $b = freeTimePaying();
    app(\GraystackIT\MollieBilling\Services\Billing\AdminExtensionService::class)->freePlanPeriods($b, 3);
    app(CouponService::class)->redeemRecurringCouponForRenewal($b->fresh(), 6500, 1);

    expect(CouponRedemption::query()->where('billable_id', $b->getKey())->count())->toBe(2);

    $component = Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()]);

    expect($component->instance()->grants())->toHaveCount(1);
});

it('explains every coupon type on the create form', function (string $type): void {
    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', $type)
        ->assertSee(__('billing::admin.coupons.type_info.'.$type))
        // The (i) next to an option carries its explanation. (The feature suite runs
        // without Flux, so the tooltip itself is only rendered in the browser suite.)
        ->assertSee(__('billing::admin.coupons.create.info.code'))
        ->assertSee(__('billing::admin.coupons.create.info.stackable'));
})->with(array_map(fn (CouponType $t) => $t->value, CouponType::cases()));

it('has an explanation for every option, in both shipped languages', function (string $locale): void {
    app()->setLocale($locale);

    $info = trans('billing::admin.coupons.create.info');
    $types = trans('billing::admin.coupons.type_info');

    expect($info)->toBeArray()->and(count($info))->toBeGreaterThan(20);

    foreach (CouponType::cases() as $type) {
        expect($types)->toHaveKey($type->value);
        expect(mb_strlen((string) $types[$type->value]))->toBeGreaterThan(60);
    }

    foreach ($info as $key => $text) {
        expect(mb_strlen((string) $text))->toBeGreaterThan(20, "info.{$key} is not an explanation");
    }
})->with(['en', 'de']);

it('creates a plan-waiver coupon from the form', function (): void {
    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'plan_waiver')
        ->set('code', 'planfree3')
        ->set('waiver_periods', 3)
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'PLANFREE3')->sole();

    expect($coupon->type)->toBe(CouponType::PlanWaiver)
        ->and((int) $coupon->waiver_periods)->toBe(3);
});

it('refuses a plan-waiver coupon without a number of periods', function (): void {
    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'plan_waiver')
        ->set('code', 'planfree0')
        ->call('save')
        ->assertSet('error', 'Please enter for how many billing periods the plan should be free.');

    expect(Coupon::query()->where('code', 'PLANFREE0')->exists())->toBeFalse();
});

it('stores what happens at the end of a grant created from the form', function (): void {
    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'access_grant')
        ->set('code', 'gift90')
        ->set('grant_plan_code', 'pro')
        ->set('grant_interval', 'monthly')
        ->set('grant_duration_days', 90)
        ->set('grant_on_expiry', 'continue_paid')
        ->call('save')
        ->assertSet('error', null);

    expect(Coupon::query()->where('code', 'GIFT90')->sole()->grant_on_expiry)->toBe('continue_paid');
});

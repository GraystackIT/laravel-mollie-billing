<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Models\Coupon;
use Livewire\Livewire;

/*
 * The coupon form asks what the operator wants to give — a discount, free time or
 * credits — before it asks for one of the seven technical types. The intent steers
 * `type`; `type` stays the property everything else reads, so setting it directly (which
 * is what a deep link or an older test does) derives the intent.
 *
 * The feature suite does not compile Flux tags: these tests prove the component's logic
 * and the text it emits, not how the options are drawn.
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
});

function couponFormIntent(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::admin.coupons.create');
}

it('starts with the three intents, each explained in a sentence', function (): void {
    $form = couponFormIntent()->assertSet('intent', 'discount')->assertSet('type', 'single_payment');

    foreach (['discount', 'free_time', 'credits'] as $intent) {
        $form->assertSee(__('billing::admin.coupons.intent.'.$intent))
            ->assertSee(__('billing::admin.coupons.intent_info.'.$intent));
    }
});

it('offers the kinds that belong to the chosen intent, and only those', function (string $intent, array $offered): void {
    $form = couponFormIntent()->set('intent', $intent);

    foreach (['single_payment', 'recurring', 'trial_extension', 'period_extension', 'plan_waiver', 'access_grant'] as $kind) {
        in_array($kind, $offered, true)
            ? $form->assertSee(__('billing::admin.coupons.kind.'.$kind))
            : $form->assertDontSee(__('billing::admin.coupons.kind.'.$kind));
    }

    // The first kind of the intent is selected, so the form below is never without a type.
    $form->assertSet('type', $offered[0] ?? 'credits');
})->with([
    'discount' => ['discount', ['single_payment', 'recurring']],
    'free time' => ['free_time', ['trial_extension', 'period_extension', 'plan_waiver', 'access_grant']],
    'credits' => ['credits', []],
]);

it('derives the intent when only the type is set', function (string $type, string $intent): void {
    couponFormIntent()
        ->set('type', $type)
        ->assertSet('intent', $intent)
        ->assertSee(__('billing::admin.coupons.type_info.'.$type));
})->with([
    ['single_payment', 'discount'],
    ['recurring', 'discount'],
    ['trial_extension', 'free_time'],
    ['period_extension', 'free_time'],
    ['plan_waiver', 'free_time'],
    ['access_grant', 'free_time'],
    ['credits', 'credits'],
]);

it('keeps a kind that already belongs to the intent when the intent is set again', function (): void {
    couponFormIntent()
        ->set('type', 'plan_waiver')
        ->set('intent', 'free_time')
        ->assertSet('type', 'plan_waiver');
});

// ── Recurring: how long the discount runs ───────────────────────────────────

it('asks a recurring coupon for its number of periods in a field of its own', function (): void {
    couponFormIntent()
        ->assertDontSee(__('billing::admin.coupons.create.discount_periods'))
        ->set('type', 'recurring')
        ->assertSee(__('billing::admin.coupons.create.discount_periods'))
        ->assertSee(__('billing::admin.coupons.create.info.discount_periods'))
        ->assertSee(__('billing::admin.coupons.create.info.valid_until_recurring'))
        // One meaning for every type now.
        ->assertSee(__('billing::admin.coupons.create.info.max_per_billable'))
        ->set('code', 'six4two')
        ->set('discount_value', 20)
        ->set('discount_periods', 6)
        ->set('max_redemptions_per_billable', 2)
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'SIX4TWO')->sole();

    expect([$coupon->discount_periods, $coupon->max_redemptions_per_billable])->toBe([6, 2]);
});

it('saves a recurring coupon limited by a date only, without inventing a count', function (): void {
    couponFormIntent()
        ->set('type', 'recurring')
        ->set('code', 'untildate')
        ->set('discount_value', 20)
        ->set('valid_until', '2030-06-30T23:59')
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'UNTILDATE')->sole();

    expect($coupon->discount_periods)->toBeNull()
        ->and($coupon->max_redemptions_per_billable)->toBe(1);
});

it('refuses a recurring coupon from the form that has neither periods nor a date', function (): void {
    // The per-customer limit is always filled in (default 1) and is not a lifetime.
    couponFormIntent()
        ->set('type', 'recurring')
        ->set('code', 'nolimit')
        ->set('discount_value', 20)
        ->call('save')
        ->assertNotSet('error', null);

    expect(Coupon::query()->where('code', 'NOLIMIT')->exists())->toBeFalse();
});

it('does not store a period count on a coupon that is not recurring', function (): void {
    couponFormIntent()
        ->set('type', 'recurring')
        ->set('discount_periods', 6)
        ->set('type', 'single_payment')
        ->set('code', 'once')
        ->set('discount_value', 20)
        ->call('save')
        ->assertSet('error', null);

    expect(Coupon::query()->where('code', 'ONCE')->sole()->discount_periods)->toBeNull();
});

// ── Giving the product away ─────────────────────────────────────────────────

it('creates a full grant under "free time" and keeps the addon-only grant under "advanced"', function (): void {
    // Under free time a grant is the product: the plan is not optional there.
    couponFormIntent()
        ->set('intent', 'free_time')
        ->set('type', 'access_grant')
        ->assertDontSee(__('billing::admin.coupons.create.grant_plan_none'))
        ->assertSee(__('billing::admin.coupons.create.addon_only_grant'))
        ->assertSet('addonOnlyGrant', false);

    couponFormIntent()
        ->set('addonOnlyGrant', true)
        ->assertSet('type', 'access_grant')
        ->assertSet('intent', 'free_time')
        ->set('code', 'addonsonly')
        ->set('grant_plan_code', 'pro')        // left over from an earlier choice
        ->set('grant_duration_days', 30)
        ->set('grant_addon_codes', ['sso'])
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'ADDONSONLY')->sole();

    expect($coupon->type)->toBe(CouponType::AccessGrant)
        ->and($coupon->grant_plan_code)->toBeNull()
        ->and($coupon->grant_duration_days)->toBeNull()
        ->and($coupon->grant_addon_codes)->toBe(['sso']);
});

it('leaves the addon-only mode when another kind is chosen', function (): void {
    couponFormIntent()
        ->set('addonOnlyGrant', true)
        ->set('type', 'period_extension')
        ->assertSet('addonOnlyGrant', false);
});

it('ships every intent and kind text in both languages', function (string $locale): void {
    app()->setLocale($locale);

    foreach (['discount', 'free_time', 'credits'] as $intent) {
        expect(mb_strlen((string) __('billing::admin.coupons.intent_info.'.$intent)))->toBeGreaterThan(30)
            ->and(__('billing::admin.coupons.intent.'.$intent))->not->toContain('billing::');
    }

    foreach (['single_payment', 'recurring', 'trial_extension', 'period_extension', 'plan_waiver', 'access_grant', 'credits'] as $kind) {
        expect(__('billing::admin.coupons.kind.'.$kind))->not->toContain('billing::');
    }

    foreach (['discount_periods', 'addon_only_grant', 'intent', 'kind'] as $key) {
        expect(mb_strlen((string) __('billing::admin.coupons.create.info.'.$key)))->toBeGreaterThan(20);
    }

    // The recurring tooltips describe the fields as they now are.
    expect(__('billing::admin.coupons.create.info.valid_until_recurring'))
        ->not->toContain(__('billing::admin.coupons.create.max_per_billable'));
})->with(['en', 'de']);

// ── Fields the form did not offer, and the detail page did not show ─────────

it('restricts a discount to plans and intervals from the form', function (): void {
    couponFormIntent()
        ->set('type', 'recurring')
        ->assertSee(__('billing::admin.coupons.create.applicable_plans'))
        ->assertSee(__('billing::admin.coupons.create.info.applicable_plans'))
        ->assertSee(__('billing::admin.coupons.create.info.applicable_intervals'))
        ->set('code', 'proyearly')
        ->set('discount_value', 20)
        ->set('discount_periods', 3)
        ->set('applicable_plans', ['pro'])
        ->set('applicable_intervals', ['monthly'])
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'PROYEARLY')->sole();

    expect($coupon->applicable_plans)->toBe(['pro'])
        ->and($coupon->applicable_intervals)->toBe(['monthly']);

    // Nothing ticked stores no restriction, and the option is not offered where a plan
    // restriction has no meaning.
    couponFormIntent()->set('type', 'single_payment')->set('code', 'anyplan')->set('discount_value', 5)->call('save');
    expect(Coupon::query()->where('code', 'ANYPLAN')->sole()->applicable_plans)->toBeNull();

    couponFormIntent()->set('type', 'credits')->assertDontSee(__('billing::admin.coupons.create.applicable_plans'));
});

it('shows on the coupon page everything the coupon was created with', function (array $attributes, Closure $expected): void {
    $coupon = app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->create(['code' => 'SHOWME'] + $attributes);

    $page = Livewire::test('mollie-billing::admin.coupons.show', ['routeParameters' => ['coupon' => $coupon->id]]);

    foreach ($expected() as $text) {
        $page->assertSee($text);
    }
})->with([
    'plan waiver: its periods' => [
        ['type' => CouponType::PlanWaiver, 'waiver_periods' => 4],
        fn () => [__('billing::admin.coupons.show.waiver_periods'), '4 '.__('billing::admin.billables.subscription.free_time.periods')],
    ],
    'grant: what happens at its end' => [
        ['type' => CouponType::AccessGrant, 'grant_plan_code' => 'pro', 'grant_interval' => 'monthly', 'grant_duration_days' => 30, 'grant_on_expiry' => 'continue_paid'],
        fn () => [__('billing::admin.grants.on_expiry.continue_paid'), '30 '.__('billing::admin.common.days')],
    ],
    'discount: the plans, intervals and usage types it is restricted to' => [
        [
            'type' => CouponType::Recurring, 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_periods' => 2,
            'applicable_plans' => ['pro'], 'applicable_intervals' => ['monthly'], 'applicable_usages' => ['tokens'],
        ],
        fn () => [
            __('billing::admin.coupons.show.applicable_plans'), __('billing::admin.coupons.show.applicable_intervals'),
            __('billing::admin.coupons.show.applicable_usages'), 'tokens', 'monthly',
        ],
    ],
]);

/*
 * The plan / interval / addon / product restrictions are offered for the two discount kinds
 * only. Ticked there and the kind then switched, they stayed in component state, out of
 * sight — and were saved: a credits or free-time code carried a plan restriction nobody
 * could see on the form, and `CouponService::validate()` refused it on every order that
 * names another plan, or none.
 */
it('does not save restrictions the chosen kind does not offer, when they were ticked under another kind', function (string $type, array $fields): void {
    $form = couponFormIntent()
        ->set('type', 'recurring')
        ->set('applicable_plans', ['pro'])
        ->set('applicable_intervals', ['monthly'])
        ->set('applicable_addons', ['sso'])
        ->set('type', $type)
        ->set('code', 'stale-'.$type);

    foreach ($fields as $field => $value) {
        $form->set($field, $value);
    }

    $form->call('save')->assertSet('error', null);

    $coupon = Coupon::query()->where('code', strtoupper('stale-'.$type))->firstOrFail();

    expect($coupon->applicable_plans)->toBeEmpty()
        ->and($coupon->applicable_intervals)->toBeEmpty()
        ->and($coupon->applicable_addons)->toBeEmpty()
        ->and($coupon->applicable_products)->toBeEmpty();
})->with([
    'credits' => ['credits', ['credits_payload' => ['tokens' => 100]]],
    'trial extension' => ['trial_extension', ['trial_extension_days' => 7]],
    'plan waiver' => ['plan_waiver', ['waiver_periods' => 2]],
]);

it('keeps the restrictions for a discount kind, where the form offers them', function (): void {
    couponFormIntent()
        ->set('type', 'single_payment')
        ->set('code', 'kept')
        ->set('discount_value', 10)
        ->set('applicable_plans', ['pro'])
        ->set('applicable_intervals', ['monthly'])
        ->call('save')
        ->assertSet('error', null);

    $coupon = Coupon::query()->where('code', 'KEPT')->firstOrFail();

    expect($coupon->applicable_plans)->toBe(['pro'])
        ->and($coupon->applicable_intervals)->toBe(['monthly']);
});

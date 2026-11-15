<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\CouponInUseException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The parts of the coupon system everything else stands on: limits, the validity
 * window, the code itself, and what editing or revoking a coupon does to redemptions
 * that already exist.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
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

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_basics']);
});

function couponBasicsPaying(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Basics', 'email' => uniqid('cb-').'@x.test', 'billing_country' => 'AT']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => ['sso'],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_basics', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_basics',
        'mollie_mandate_id' => 'mdt_basics',
    ])->save();

    return $b->fresh();
}

function couponBasicsReason(callable $attempt): ?string
{
    try {
        $attempt();
    } catch (InvalidCouponException $e) {
        return $e->reason();
    }

    return null;
}

// ── The validity window ─────────────────────────────────────────────────────

it('opens and closes the validity window on the second, in UTC, whatever the app timezone', function (string $timezone): void {
    config()->set('app.timezone', $timezone);
    $previous = date_default_timezone_get();
    date_default_timezone_set($timezone);

    try {
        $service = app(CouponService::class);
        $billable = couponBasicsPaying();
        $from = Carbon::parse('2026-12-01 00:00:00', 'UTC');
        $until = Carbon::parse('2026-12-31 23:59:59', 'UTC');

        $service->creditsCoupon('WINDOW', ['tokens' => 10])->forceFill([
            'valid_from' => $from,
            'valid_until' => $until,
            'auto_apply_token' => 'window-token',
        ])->save();

        $at = function (Carbon $moment) use ($service, $billable): array {
            Carbon::setTestNow($moment);

            return [
                couponBasicsReason(fn () => $service->validate('WINDOW', $billable, [])),
                $service->resolveByAutoApplyToken('window-token') !== null,
            ];
        };

        expect($at($from->copy()->subSecond()))->toBe(['not_yet_valid', false])
            ->and($at($from->copy()))->toBe([null, true])
            ->and($at($until->copy()))->toBe([null, true])
            ->and($at($until->copy()->addSecond()))->toBe(['expired', false]);

        // Stored as the instant that was given, not shifted by the app's offset.
        expect(Coupon::query()->where('code', 'WINDOW')->first()->valid_until->toIso8601String())
            ->toBe('2026-12-31T23:59:59+00:00');
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($previous);
    }
})->with(['UTC', 'Europe/Vienna', 'America/Los_Angeles']);

it('reads a date typed into the coupon form as UTC', function (): void {
    config()->set('app.timezone', 'Europe/Vienna');

    $coupon = app(CouponService::class)->create([
        'code' => 'TYPED',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 5],
        // What a `datetime-local` input submits.
        'valid_until' => '2026-12-31T23:59',
    ]);

    expect($coupon->fresh()->valid_until->toIso8601String())->toBe('2026-12-31T23:59:00+00:00');
});

// ── The code ────────────────────────────────────────────────────────────────

it('treats a code as case-insensitive and ignores surrounding whitespace', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create(['code' => '  winter26 ', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]);

    expect($coupon->code)->toBe('WINTER26')
        ->and($service->validate(' Winter26', $billable, [])->id)->toBe($coupon->id)
        ->and($service->validateWithoutBillable('WINTER26 ', [])->id)->toBe($coupon->id)
        ->and($service->resolveByAutoApplyToken('winter26')?->id)->toBe($coupon->id);

    // And a second coupon cannot be created under the same code in another case.
    expect(fn () => $service->create(['code' => 'Winter26', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

// ── Limits ──────────────────────────────────────────────────────────────────

it('enforces the campaign cap in redeem() as well as in validate()', function (): void {
    $service = app(CouponService::class);
    $first = couponBasicsPaying();
    $second = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'ONLYONE', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 10],
        'max_redemptions' => 1,
    ]);

    $service->redeem($coupon, $first, []);

    // The second tab validated before the first redeemed: redeem() has to refuse it.
    expect(couponBasicsReason(fn () => $service->redeem($coupon, $second, [])))->toBe('globally_exhausted')
        ->and(couponBasicsReason(fn () => $service->validate('ONLYONE', $second, [])))->toBe('globally_exhausted')
        ->and($coupon->fresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('enforces the per-billable cap in redeem() as well as in validate()', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'TWICE', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 10],
        'max_redemptions_per_billable' => 2,
    ]);

    $service->redeem($coupon, $billable, []);
    $service->redeem($coupon, $billable->fresh(), []);

    expect(couponBasicsReason(fn () => $service->redeem($coupon, $billable->fresh(), [])))->toBe('per_billable_limit_reached')
        ->and(couponBasicsReason(fn () => $service->validate('TWICE', $billable->fresh(), [])))->toBe('per_billable_limit_reached')
        ->and($coupon->fresh()->redemptions_count)->toBe(2);
});

it('counts a recurring coupon once in the campaign however many renewals it discounts', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'REC3', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions' => 1, 'max_redemptions_per_billable' => 3,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 6500, 'interval' => 'monthly', 'discount_amount_net' => 1300]);
    $service->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0);
    $service->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0);

    // Three discounted charges, one grant.
    expect($coupon->fresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->where('discount_amount_net', '>', 0)->count())->toBe(3)
        // The promised three applications are spent: no fourth discount.
        ->and($service->computeMarkerDiscount($billable->fresh(), 6500))->toBe(0)
        ->and($service->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0))->toBeNull();
});

it('does not count a plan waiver\'s waived renewals against the campaign', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'FREEPLAN2', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 2, 'max_redemptions' => 5,
    ]);

    $service->redeem($coupon, $billable, []);
    $service->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0);
    $service->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0);

    expect($coupon->fresh()->redemptions_count)->toBe(1)
        ->and($service->computeMarkerDiscount($billable->fresh(), 6500))->toBe(0);
});

// ── Editing a coupon that is in use ─────────────────────────────────────────

it('refuses to change the type of a coupon that has been redeemed', function (): void {
    // A running recurring marker re-applies "its coupon" at every renewal through
    // redeem(). With the type changed underneath it that call runs the NEW type's
    // effect: a recurring coupon turned into a credits coupon topped the wallet up at
    // every renewal of every customer who held it.
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'RECEDIT', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions_per_billable' => 6,
    ]);
    $service->redeem($coupon, $billable, ['orderAmountNet' => 6500, 'interval' => 'monthly', 'discount_amount_net' => 1300]);

    expect(fn () => $service->update($coupon->fresh(), ['type' => CouponType::Credits, 'credits_payload' => ['tokens' => 500]]))
        ->toThrow(InvalidArgumentException::class);

    expect($coupon->fresh()->type)->toBe(CouponType::Recurring);

    // An unredeemed coupon can still be reshaped.
    $unused = $service->create(['code' => 'UNUSED', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]);
    $service->update($unused, ['type' => CouponType::PeriodExtension, 'grant_duration_days' => 7]);

    expect($unused->fresh()->type)->toBe(CouponType::PeriodExtension);
});

it('leaves running discounts at the value they were granted at when the coupon is edited', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $coupon = $service->create([
        'code' => 'REC20EDIT', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions_per_billable' => 6,
    ]);
    $service->redeem($coupon, $billable, ['orderAmountNet' => 6500, 'interval' => 'monthly', 'discount_amount_net' => 1300]);

    $service->update($coupon->fresh(), ['discount_value' => 80]);

    // What the customer was promised, and what Mollie was priced at.
    expect($service->computeMarkerDiscount($billable->fresh(), 6500))->toBe(1300);
});

it('rejects an edit that would make a redeemed recurring coupon exceed 100%', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'RECCAP', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
        'max_redemptions_per_billable' => 3,
    ]);

    expect(fn () => $service->update($coupon, ['discount_value' => 150]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->update($coupon, ['discount_value' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->update($coupon, ['discount_value' => -5]))->toThrow(InvalidArgumentException::class);
});

it('deletes an unused coupon and refuses to delete a redeemed one, revoked or not', function (): void {
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $unused = $service->create(['code' => 'DELME', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]);
    $service->delete($unused);
    expect(Coupon::query()->where('code', 'DELME')->exists())->toBeFalse();

    $used = $service->create(['code' => 'KEEPME', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]);
    $service->redeem($used, $billable, []);

    expect(fn () => $service->delete($used->fresh()))->toThrow(CouponInUseException::class);
});

// ── One-off codes ───────────────────────────────────────────────────────────

it('does not put a one-off admin code back on offer when it is revoked', function (): void {
    // The code of an admin-given waiver is printed on the invoice it reduced. Revoking
    // freed its only campaign slot, and the dashboard accepts plan-waiver codes — so
    // the tenant could type the revoked code in and take the free periods back.
    $service = app(CouponService::class);
    $billable = couponBasicsPaying();

    $redemption = app(AdminExtensionService::class)->freePlanPeriods($billable, 3);
    $code = (string) $redemption->coupon->code;

    $service->revokeGrant($redemption, 'changed our mind');

    // validate() is the gate every entry point passes; redeem() deliberately does not
    // look at `active` — a payment made with a code is honoured even if the code was
    // switched off while the payment travelled.
    expect(app(PlanWaiver::class)->marker($billable->fresh()))->toBeNull()
        ->and(couponBasicsReason(fn () => $service->validate($code, $billable->fresh(), [])))->toBe('inactive')
        ->and($redemption->coupon->fresh()->active)->toBeFalse();
});

it('still frees the slot of a shared campaign code when one redemption is revoked', function (): void {
    $service = app(CouponService::class);
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Shared', 'email' => 'shared@x.test']);

    $coupon = $service->accessGrantCoupon('GRANT-SHARED', 'pro', 'monthly', [], 30);
    $redemption = $service->redeem($coupon, $billable, []);
    $service->revokeGrant($redemption->fresh(), 'audit');

    expect($coupon->fresh()->active)->toBeTrue()
        ->and($service->validate('GRANT-SHARED', $billable->fresh(), [])->id)->toBe($coupon->id);
});

it('issues a grant from the admin panel as a code that works once', function (): void {
    // "GRANT-…" is created for one tenant. Left unlimited, anybody who learned the
    // code — it is on that tenant's invoices once they buy extras — could redeem a
    // free plan with it at checkout.
    /** @var TestBillable $target */
    $target = TestBillable::create(['name' => 'Target', 'email' => 'target@x.test']);
    /** @var TestBillable $other */
    $other = TestBillable::create(['name' => 'Other', 'email' => 'other@x.test']);

    Livewire::test('mollie-billing::admin.grants.issue', ['routeParameters' => ['billable' => $target->getKey()]])
        ->set('planCode', 'pro')
        ->set('interval', 'monthly')
        ->set('durationDays', 30)
        ->call('issue');

    $coupon = Coupon::query()->where('code', 'like', 'GRANT-%')->first();

    expect($coupon)->not->toBeNull()
        ->and($target->fresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(couponBasicsReason(fn () => app(CouponService::class)->validate((string) $coupon->code, $other, [
            'planCode' => 'pro', 'interval' => 'monthly',
        ])))->toBe('globally_exhausted');
});

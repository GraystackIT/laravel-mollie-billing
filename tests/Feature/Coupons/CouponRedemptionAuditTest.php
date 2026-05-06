<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 200, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.premium', [
        'name' => 'Premium',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
});

function basicMollieBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Audit Test',
        'email' => 'audit@x.test',
        'billing_country' => 'AT',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $billable;
}

it('Path C (no charge) — Coupon-Redemption schreibt discount_amount_net=0; Recurring-Marker ist gesetzt', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC15',
        'name' => 'Recurring 15%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 15,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = basicMollieBillable();

    // Seat sync without a plan change and without a prorata charge:
    // basic -> basic, same setup, seats unchanged -> no prorata, but the coupon marker
    // should still be set for future renewals.
    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'basic',
        'interval' => 'monthly',
        'seats' => 1,
        'coupon_codes' => ['REC15'],
    ]);

    $redemption = CouponRedemption::query()->where('coupon_id', $coupon->id)->latest('id')->first();
    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(0);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('REC15');
});

it('Path A (deferred Prorata-Charge) — KEIN Coupon-Redeem in Phase-1, sondern nur in Phase-2', function (): void {
    // Recurring coupon (single_payment is no longer accepted on plan-change).
    // The deferred-redemption semantics are coupon-type-agnostic.
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'PROR50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = basicMollieBillable();

    // Original plan invoice so that the ProrataComposer finds a reference.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_orig',
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'basic',
            'label' => 'Basic',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
            'period_start' => BillingTime::nowUtc()->subDays(5)->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(25)->toIso8601String(),
        ]],
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
    ]);

    // Plan upgrade basic -> premium: creates a prorata charge -> phase 1 (deferred).
    // The Mollie charge is stubbed (no real Mollie call here).
    \Mollie\Laravel\Facades\Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    \Mollie\Laravel\Facades\Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_prorata']);

    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'premium',
        'interval' => 'monthly',
        'coupon_codes' => ['PROR50'],
    ]);

    // Phase 1: NO redemption may exist yet — that only happens after a successful charge.
    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(0);

    // pending_plan_change must carry the coupon code.
    $pending = $billable->fresh()->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
    expect($pending)->not->toBeNull()
        ->and($pending['coupon_codes'])->toBe(['PROR50']);
});

it('Recurring-Coupon-Redemption schreibt discount_amount_net=0 in Path C — der Discount kommt erst beim Renewal', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = basicMollieBillable();

    // A pure seat increase would trigger prorata (200 net = 1 extra seat × 200), but that
    // is the charge path. Here we want to exercise the no-charge path — i.e. same seat
    // count, only a coupon code input.
    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'basic',
        'interval' => 'monthly',
        'seats' => 1,
        'coupon_codes' => ['REC10'],
    ]);

    $redemption = CouponRedemption::query()->where('coupon_id', $coupon->id)->latest('id')->first();
    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(0);
});

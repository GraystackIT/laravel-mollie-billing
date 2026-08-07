<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Four ways a coupon promised one thing and did another. All four are about money
 * the customer either paid and should not have, or was promised and did not get.
 */
class MoneyCorrectnessFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    MoneyCorrectnessFakeWebhookController::$payments = [];
    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieWebhookController::class, MoneyCorrectnessFakeWebhookController::class);
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 20000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.protrial', [
        'name' => 'Pro Trial',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'trial_days' => 14, 'included_usages' => []],
        ],
    ]);
});

function couponMoneyBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Money Co',
        'email' => uniqid('money-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge(['mollie_customer_id' => 'cst_money'], $overrides))->save();

    return $b->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 6a — a promotion coupon must not be consumed for a full-price charge
// ─────────────────────────────────────────────────────────────────────────────

it('drops an auto-applied coupon when the submitted amount was not priced with it', function (): void {
    app(CouponService::class)->create([
        'code' => 'PROMO25',
        'name' => 'A quarter off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'auto_apply_token' => 'tok25',
    ]);

    $billable = couponMoneyBillable();
    session(['billing.auto_apply_coupon' => 'PROMO25']);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreatePaymentRequest) {
            $property = (new ReflectionObject($request))->getProperty('metadata');
            $captured = $property->getValue($request);
        }

        return (object) ['id' => 'tr_money'];
    });

    // The FULL gross — a form that never saw the session code. Attaching the coupon
    // here charged full price and then redeemed it anyway: mismatch event, a burned
    // per-billable slot and a redemption row claiming a €5 discount nobody received.
    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 2380,
    ]);

    expect($captured)->not->toBeNull()
        ->and($captured['coupon_code'])->toBeNull();
});

it('keeps an auto-applied coupon when the amount does reflect it', function (): void {
    app(CouponService::class)->create([
        'code' => 'PROMO25',
        'name' => 'A quarter off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
    ]);

    $billable = couponMoneyBillable();
    session(['billing.auto_apply_coupon' => 'PROMO25']);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreatePaymentRequest) {
            $captured = (new ReflectionObject($request))->getProperty('metadata')->getValue($request);
        }

        return (object) ['id' => 'tr_money'];
    });

    // 2000 − 500 = 1500 net → 1785 gross. The component now pre-fills the coupon, so
    // this is what a promotion link actually submits.
    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 1785,
    ]);

    expect($captured['coupon_code'])->toBe('PROMO25');
});

it('keeps an auto-applied coupon on a trial signup, where nothing is charged yet', function (): void {
    app(CouponService::class)->create([
        'code' => 'PROMOREC',
        'name' => 'Forever off',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = couponMoneyBillable();
    session(['billing.auto_apply_coupon' => 'PROMOREC']);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreatePaymentRequest) {
            $captured = (new ReflectionObject($request))->getProperty('metadata')->getValue($request);
        }

        return (object) ['id' => 'tr_money'];
    });

    // A trial checkout collects €0 today, so the form's gross is never charged and the
    // coupon can ride along — the guard must not break promotion links on trials.
    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'protrial',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 2380,
    ]);

    expect($captured['type'])->toBe('mandate_only')
        ->and($captured['pending_subscription_coupon_code'])->toBe('PROMOREC');
});

// ─────────────────────────────────────────────────────────────────────────────
// 6b — one recurring marker means one recurring coupon
// ─────────────────────────────────────────────────────────────────────────────

it('refuses a second recurring coupon in the same apply-set', function (): void {
    $service = app(CouponService::class);

    foreach (['REC-A', 'REC-B'] as $code) {
        $service->create([
            'code' => $code,
            'name' => $code,
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 60,
            'max_redemptions_per_billable' => 6,
            'stackable' => true,
        ]);
    }

    $billable = couponMoneyBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_money'],
    ]);

    $first = $service->validate('REC-A', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 2000,
        'allowed_types' => [CouponType::Recurring],
    ]);

    // Two 60% coupons priced cumulatively to €3.20 in the preview and the prorata
    // charge, then the second marker overwrote the first: every renewal charged €8 and
    // both per-billable slots had been consumed. `active_recurring_coupon` is one slot.
    expect(fn () => $service->validate('REC-B', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 800,
        'existingCouponIds' => [$first->id],
        'allowed_types' => [CouponType::Recurring],
    ]))->toThrow(InvalidCouponException::class);
});

it('still allows a single-payment coupon alongside a recurring one', function (): void {
    $service = app(CouponService::class);

    $rec = $service->create([
        'code' => 'REC-A',
        'name' => 'Recurring',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 6,
        'stackable' => true,
    ]);
    $service->create([
        'code' => 'ONCE',
        'name' => 'Once',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'stackable' => true,
    ]);

    $billable = couponMoneyBillable();

    // Different slots, no conflict — the restriction is about the marker, not stacking.
    $second = $service->validate('ONCE', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1500,
        'existingCouponIds' => [$rec->id],
        'allowed_types' => [CouponType::SinglePayment, CouponType::Recurring],
    ]);

    expect($second->code)->toBe('ONCE');
});

// ─────────────────────────────────────────────────────────────────────────────
// 6c — the marker's lifetime belongs to the interval it will run at
// ─────────────────────────────────────────────────────────────────────────────

it('computes the marker lifetime from the target interval, not the current one', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC2',
        'name' => 'Two periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 2,
    ]);

    // On yearly today, switching to monthly. Reading the interval off the billable
    // computed 2 × 365 days — 24 discounted monthly renewals for a 2-period coupon.
    $billable = couponMoneyBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Yearly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);

    $service->applyRecurringMarker($coupon, $billable, 2000, 'monthly');

    $validUntil = BillingTime::toUtc(
        $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['valid_until']
    );

    // 2 monthly periods, not 2 yearly ones.
    expect($validUntil->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(2 * 30 + 1)->toDateString())
        ->and($validUntil->lessThan(BillingTime::nowUtc()->addYear()))->toBeTrue();
});

it('stops the discount after the promised number of applications, whatever the calendar does', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC3',
        'name' => 'Three periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = couponMoneyBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);

    $service->applyRecurringMarker($coupon, $billable, 2000);
    $billable->refresh();

    // The window is `3 × 30 + 1` days, and 30-day months are shorter than the calendar
    // wherever February falls — a fourth charge could land inside it. The window cannot
    // just be shortened (CreateSubscription derives the 100%-coverage deferral from it),
    // so what is enforced is the promise itself: three applications.
    for ($i = 1; $i <= 3; $i++) {
        expect($service->computeMarkerDiscount($billable->fresh(), 2000))->toBe(500);

        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'discount_amount_net' => 500,
            'applied_at' => BillingTime::nowUtc(),
        ]);
    }

    // Fourth: inside the date window, past the promise.
    expect($service->computeMarkerDiscount($billable->fresh(), 2000))->toBe(0)
        ->and($service->markerExpired($billable->fresh()))->toBeTrue();
});

it('does not let a zero-discount grant row eat one of the discounted applications', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC2',
        'name' => 'Two periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 2,
    ]);

    $billable = couponMoneyBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);

    $service->applyRecurringMarker($coupon, $billable, 2000);

    // A trial signup's grant row: it occupies the campaign slot but carries no discount,
    // because the discount arrives with the later charges. Counting it as an application
    // would give trial signups one discounted period fewer than paid ones.
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'discount_amount_net' => 0,
        'applied_at' => BillingTime::nowUtc(),
    ]);

    expect($service->computeMarkerDiscount($billable->fresh(), 2000))->toBe(500);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6d — a trial signup must consume the campaign slot it was promised
// ─────────────────────────────────────────────────────────────────────────────

it('counts the campaign grant for a recurring coupon on a trial signup', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'CAMPAIGN',
        'name' => 'Limited campaign',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = couponMoneyBillable(['mollie_mandate_id' => 'mdt_money']);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_money']);

    MoneyCorrectnessFakeWebhookController::$payments['tr_trial'] = (object) [
        'id' => 'tr_trial',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_money',
        'mandateId' => 'mdt_money',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'protrial',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_coupon_code' => 'CAMPAIGN',
            'pending_subscription_trial_days' => 14,
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_trial'])->assertStatus(200);

    // The trial path wrote only the marker, and every later charge takes the renewal
    // pipeline, which skips the counter by design — so `redemptions_count` stayed 0
    // forever and a one-redemption campaign could be claimed without limit.
    expect($coupon->fresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon');
});

it('refuses the next trial signup once the campaign cap is reached', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'CAPPED',
        'name' => 'Capped',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 6,
    ]);

    // Somebody else already took the only slot.
    $coupon->forceFill(['redemptions_count' => 1])->save();

    $billable = couponMoneyBillable();

    expect(fn () => $service->validate('CAPPED', $billable, [
        'planCode' => 'protrial',
        'interval' => 'monthly',
        'orderAmountNet' => 2000,
        'allowed_types' => [CouponType::Recurring],
    ]))->toThrow(InvalidCouponException::class);
});

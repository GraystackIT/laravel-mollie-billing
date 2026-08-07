<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The mandate-only path handles every €0 checkout: 100%-coupon and trial. It
 * must carry the coupon's recurring discount into the Mollie subscription and
 * must also serve the recovery states StartSubscriptionCheckout admits
 * (PastDue / Expired / Cancelled-after-grace), not only a brand-new billable.
 */
class MandateOnlyCouponFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    MandateOnlyCouponFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, MandateOnlyCouponFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 100]],
        ],
    ]);
});

function mandateOnlyCouponBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'mandate-coupon@x.test',
        'name' => 'Mandate Coupon',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_mc',
    ])->save();

    return $b;
}

function mandateOnlyCouponPayment(TestBillable $billable, string $paymentId, string $couponCode): object
{
    return (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_mc',
        'mandateId' => 'mdt_mc',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_coupon_code' => $couponCode,
        ],
    ];
}

it('defers the Mollie start date past a 100% Recurring coupon instead of charging full price from period two', function (): void {
    app(CouponService::class)->create([
        'code' => 'FREE3M',
        'name' => 'Three months free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = mandateOnlyCouponBillable();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateSubscriptionRequest) {
            $captured = $request;

            return (object) ['id' => 'sub_free3m'];
        }

        return (object) ['id' => 'unexpected'];
    });

    MandateOnlyCouponFakeWebhookController::$nextPayment = mandateOnlyCouponPayment($billable, 'tr_free3m', 'FREE3M');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_free3m'])->assertStatus(200);

    expect($captured)->toBeInstanceOf(CreateSubscriptionRequest::class);

    $reflection = new ReflectionObject($captured);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    $startDateProp = $reflection->getProperty('startDate');
    $startDateProp->setAccessible(true);

    // Mollie rejects a 0-EUR subscription, so the full price is sent — but the
    // first charge is deferred past the coupon's lifetime (3 × 30 + 1 days,
    // + 1 day) instead of one month from now.
    expect($amountProp->getValue($captured)->value)->toBe('11.90');
    expect((string) $startDateProp->getValue($captured))
        ->toBe(BillingTime::nowUtc()->addDays(92)->toDateString());

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('FREE3M');
});

it('activates a 100% coupon checkout for a billable in a recovery state', function (string $status): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'RESCUE',
        'name' => 'Full coverage once',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = mandateOnlyCouponBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::from($status),
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(40),
        'subscription_ends_at' => $status === 'cancelled' ? BillingTime::nowUtc()->subDay() : null,
        'subscription_meta' => [
            'past_due_since' => BillingTime::nowUtc()->subDays(5)->toIso8601String(),
        ],
    ])->save();

    expect($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_rescued'];
        }

        return (object) ['id' => 'unexpected'];
    });

    MandateOnlyCouponFakeWebhookController::$nextPayment = mandateOnlyCouponPayment($billable, 'tr_rescue_'.$status, 'RESCUE');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rescue_'.$status])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->mollie_mandate_id)->toBe('mdt_mc');

    // Audit invoice + redemption exist, so the mandate was not captured for nothing.
    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_rescue_'.$status)->exists())->toBeTrue();
    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);
})->with(['past_due', 'expired', 'cancelled']);

it('creates the Mollie subscription outside the gate transaction and spends the activation claim', function (): void {
    app(CouponService::class)->create([
        'code' => 'RESCUE',
        'name' => 'Full coverage once',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = mandateOnlyCouponBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_locked']);

    $levelInside = null;
    Event::listen(SubscriptionCreated::class, function () use (&$levelInside): void {
        $levelInside ??= DB::transactionLevel();
    });
    $levelOutside = DB::transactionLevel();

    MandateOnlyCouponFakeWebhookController::$nextPayment = mandateOnlyCouponPayment($billable, 'tr_locked', 'RESCUE');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_locked'])->assertStatus(200);

    // Two concurrent mandate-only payments are still serialized — by the short gate
    // transaction (row lock + durable claim), not by wrapping the activation. The
    // Mollie call must NOT run inside a transaction: a rollback after it erased the
    // local subscription id while the subscription existed at Mollie, and the
    // re-delivery then created a second one.
    expect($levelInside)->not->toBeNull()
        ->and($levelInside)->toBe($levelOutside);

    // A successful activation spends the claim the gate took.
    expect($billable->fresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY);
});

it('self-heals and reports when the coupon redemption fails after the subscription was created', function (): void {
    Notification::fake();

    $coupon = app(CouponService::class)->create([
        'code' => 'REC50',
        'name' => 'Half off forever',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = mandateOnlyCouponBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ])->save();

    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    $pricing = app(FirstPaymentCouponPricing::class);
    $priced = $pricing->price($billable->fresh(), 'REC50', 'pro', 'monthly', [], 0);
    expect($priced)->not->toBeNull();

    // Another billable consumes the last global redemption between price() and
    // redeem() — by then the Mollie subscription already runs at the discount.
    $coupon->forceFill(['redemptions_count' => 1])->save();

    try {
        $pricing->redeem($billable->fresh(), $priced, 'pro', 'monthly', 0);
    } finally {
        $prop = (new ReflectionClass(MollieBilling::class))->getProperty('notifyAdminCallback');
        $prop->setValue(null, null);
    }

    // No audit row could be written…
    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(0);

    // …but local pricing now matches what Mollie charges, and admins know.
    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('REC50')
        ->and((int) $marker['base_amount_net'])->toBe(1000);

    expect(app(CouponService::class)->computeMarkerDiscount($billable->fresh(), 1000))->toBe(500);

    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class);
});

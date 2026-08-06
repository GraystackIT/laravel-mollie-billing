<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * A payment without a Mollie subscriptionId is a checkout payment — a first
 * attempt, a Local→Mollie upgrade or a stale earlier attempt. None of them can
 * be a recurring-payment failure, so none of them may push an intact
 * subscription to PastDue (which RequireActiveSubscription turns into a
 * lock-out).
 */
class AbortedCheckoutFakeWebhookController extends MollieWebhookController
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
    AbortedCheckoutFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, AbortedCheckoutFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(MollieBilling::class))->getProperty('afterCheckoutCallback');
    $prop->setValue(null, null);
});

function abortedCheckoutBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'abort@x.test',
        'name' => 'Abort',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

it('keeps a free Local subscription intact when the Local→Mollie upgrade payment is aborted', function (string $status): void {
    Event::fake([PaymentFailed::class]);

    $billable = abortedCheckoutBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
    ])->save();

    $checkoutSuccess = null;
    MollieBilling::afterCheckoutUsing(function (Billable $b, bool $success) use (&$checkoutSuccess): void {
        $checkoutSuccess = $success;
    });

    AbortedCheckoutFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_upgrade_abort_'.$status,
        'status' => $status,
        'amount' => (object) ['value' => '22.61', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'upgrade_from_local' => true,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_upgrade_abort_'.$status])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue();

    $meta = $billable->getBillingSubscriptionMeta();
    expect($meta['payment_failure'] ?? null)->toBeNull()
        ->and($meta['past_due_since'] ?? null)->toBeNull();

    expect($checkoutSuccess)->toBeFalse();
    Event::assertNotDispatched(PaymentFailed::class);
})->with(['failed', 'canceled', 'expired']);

it('does not past_due an already-activated subscription when a stale first-checkout attempt expires', function (): void {
    Event::fake([PaymentFailed::class]);

    $billable = abortedCheckoutBillable();
    // Attempt 2 already paid and activated the subscription; attempt 1's
    // "expired" webhook arrives afterwards.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_from_attempt_2'],
    ])->save();

    AbortedCheckoutFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_attempt_1',
        'status' => 'expired',
        'amount' => (object) ['value' => '22.61', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_attempt_1'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);

    $meta = $billable->getBillingSubscriptionMeta();
    expect($meta['payment_failure'] ?? null)->toBeNull()
        ->and($meta['past_due_since'] ?? null)->toBeNull()
        ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_from_attempt_2');

    Event::assertNotDispatched(PaymentFailed::class);
});

it('still marks the billable past_due when a genuine recurring charge fails', function (): void {
    Event::fake([PaymentFailed::class]);

    $billable = abortedCheckoutBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(30),
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_live'],
    ])->save();

    AbortedCheckoutFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_recurring_failed',
        'status' => 'failed',
        'amount' => (object) ['value' => '22.61', 'currency' => 'EUR'],
        'subscriptionId' => 'sub_live',
        'details' => (object) ['failureReason' => 'insufficient_funds'],
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_recurring_failed'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue);

    $meta = $billable->getBillingSubscriptionMeta();
    expect($meta['payment_failure']['reason'] ?? null)->toBe('insufficient_funds')
        ->and($meta['past_due_since'] ?? null)->not->toBeNull();

    Event::assertDispatched(PaymentFailed::class);
});

it('does not clobber a cancelled subscription inside its grace period', function (): void {
    Event::fake([PaymentFailed::class]);

    $billable = abortedCheckoutBillable();
    $endsAt = BillingTime::nowUtc()->addDays(12);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(18),
        'subscription_ends_at' => $endsAt,
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cancelled'],
    ])->save();

    AbortedCheckoutFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_stale_recurring',
        'status' => 'failed',
        'amount' => (object) ['value' => '22.61', 'currency' => 'EUR'],
        'subscriptionId' => 'sub_cancelled',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_stale_recurring'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue();

    $meta = $billable->getBillingSubscriptionMeta();
    expect($meta['payment_failure'] ?? null)->toBeNull()
        ->and($meta['past_due_since'] ?? null)->toBeNull();

    Event::assertNotDispatched(PaymentFailed::class);
});

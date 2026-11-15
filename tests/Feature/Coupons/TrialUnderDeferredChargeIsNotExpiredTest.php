<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

/*
 * A trial started together with a fully covering recurring coupon ("14 days trial, then
 * three months free") has its first charge deferred to the later of trial end and coupon
 * end — docs/coupons.md, "A trial and a fully covering recurring coupon can collide".
 *
 * ProcessTrialLifecycleJob gives a mandate-holding trial FIRST_CHARGE_GRACE_DAYS after
 * its end for the first charge to arrive, and then declares it past due. Here no charge
 * is coming for months, by design: on day 29 the customer who was promised three free
 * months was flipped to PastDue, lost access, was mailed "your trial has expired", and
 * the auto-cancel clock started.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'trial_days' => 14, 'included_usages' => []],
        ],
    ]);
    Notification::fake();
});

function deferredTrialBillable(int $daysPastTrialEnd, bool $withDeferral): TestBillable
{
    $now = BillingTime::nowUtc();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Deferred trial', 'email' => uniqid('dt-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_deferred_trial',
        'mollie_mandate_id' => 'mdt_deferred_trial',
        'trial_ends_at' => $now->subDays($daysPastTrialEnd),
        'subscription_period_starts_at' => $now->subDays($daysPastTrialEnd + 14),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_deferred_trial', 'seat_count' => 1],
    ])->save();

    if ($withDeferral) {
        $coupons = app(CouponService::class);
        $coupon = $coupons->create([
            'code' => 'FREE3M'.strtoupper(bin2hex(random_bytes(2))),
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 100,
            'max_redemptions_per_billable' => 3,
        ]);

        // As the trial activation left it: marker written at signup, the charge
        // deferred to the coupon's end (CreateSubscription, trial + full coverage).
        $coupons->applyRecurringMarker($coupon, $billable->fresh(), 2900, 'monthly');
        $billable = $billable->fresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $signedUp = $now->subDays($daysPastTrialEnd + 14);
        $meta['active_recurring_coupon']['valid_until'] = $signedUp->addDays(91)->toIso8601String();
        $meta['active_recurring_coupon']['first_applied_at'] = $signedUp->toIso8601String();
        $meta[ChargeDeferral::META_KEY] = $signedUp->addDays(92)->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    return $billable->fresh();
}

it('does not declare a trial past due while its first charge is deferred by a coupon', function (): void {
    $billable = deferredTrialBillable(daysPastTrialEnd: 20, withDeferral: true);

    expect(ChargeDeferral::until($billable))->not->toBeNull();

    ProcessTrialLifecycleJob::dispatchSync();

    $billable = $billable->fresh();

    expect($billable->subscription_status)->not->toBe(SubscriptionStatus::PastDue)
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBeNull();
});

it('still declares it past due once the deferred charge itself is overdue', function (): void {
    // The deferral is over (it only counts while it lies ahead) and the charge it
    // announced has not arrived within the grace window either.
    $billable = deferredTrialBillable(daysPastTrialEnd: 20, withDeferral: true);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeDeferral::META_KEY] = BillingTime::nowUtc()
        ->subDays(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 1)->toIso8601String();
    $meta['active_recurring_coupon']['valid_until'] = BillingTime::nowUtc()
        ->subDays(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 2)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    ProcessTrialLifecycleJob::dispatchSync();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

it('gives the deferred charge its own grace window once the deferral has ended', function (): void {
    // The trial ended long ago, the deferral two days ago: the SEPA debit Mollie
    // created then is still travelling.
    $billable = deferredTrialBillable(daysPastTrialEnd: 20, withDeferral: true);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeDeferral::META_KEY] = BillingTime::nowUtc()->subDays(2)->toIso8601String();
    $meta['active_recurring_coupon']['valid_until'] = BillingTime::nowUtc()->subDays(3)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    ProcessTrialLifecycleJob::dispatchSync();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial);
});

it('keeps expiring an ordinary trial whose first charge never arrived', function (): void {
    $billable = deferredTrialBillable(daysPastTrialEnd: 20, withDeferral: false);

    ProcessTrialLifecycleJob::dispatchSync();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

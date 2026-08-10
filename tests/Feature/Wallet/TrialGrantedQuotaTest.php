<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Support\UsageReason;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/**
 * A trial is credited only its prorated share of the plan quota. Everything that
 * measures a wallet — meters, percentages, threshold warnings — has to measure
 * against that share; against the plan's full period quota the part that was never
 * granted reads as consumption, so a trial minutes old reported half its quota used.
 */
class TrialGrantedQuotaFakeWebhookController extends MollieWebhookController
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
    TrialGrantedQuotaFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, TrialGrantedQuotaFakeWebhookController::class);

    config()->set('mollie-billing.usage_threshold_percent', 80);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1900,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 20, 'SMS' => 25],
                'usage_overage_prices' => ['Tokens' => 12, 'SMS' => 12],
            ],
        ],
    ]);
});

afterEach(function (): void {
    MollieBilling::notifyBillingAdminsUsing(fn (): array => []);
});

function grantedQuotaTrialBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'granted@x.test']);
    $b->forceFill([
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b;
}

function activateGrantedQuotaTrial(TestBillable $billable, string $paymentId): void
{
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial']);

    TrialGrantedQuotaFakeWebhookController::$nextPayment = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);

    $billable->refresh();
}

it('reports no usage on a freshly activated trial', function (): void {
    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_fresh');

    // Monthly Pro, 14-day trial, intervalDays = 30:
    // Tokens: ceil(20 * 14 / 30) = 10, SMS: ceil(25 * 14 / 30) = 12.
    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(10);
    expect((int) $billable->getWallet('SMS')->balanceInt)->toBe(12);

    // The plan still lists the full period quota…
    expect($billable->includedBillingQuota('Tokens'))->toBe(20);

    // …but nothing has been consumed, and the meter's yardstick says so.
    expect($billable->grantedBillingQuota('Tokens'))->toBe(10);
    expect($billable->grantedBillingQuota('SMS'))->toBe(12);
    expect($billable->usedBillingQuota('Tokens'))->toBe(0);
    expect($billable->usedBillingQuota('SMS'))->toBe(0);
});

it('records what the trial was granted', function (): void {
    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_marker');

    $marker = $billable->getBillingSubscriptionMeta()['trial_quota'] ?? null;

    expect($marker)->toBeArray();
    expect($marker['plan_code'])->toBe('pro');
    expect($marker['interval'])->toBe('monthly');
    expect($marker['trial_days'])->toBe(14);
    expect($marker['usages'])->toBe(['Tokens' => 10, 'SMS' => 12]);
});

it('counts real consumption during a trial against the granted quota', function (): void {
    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_usage');

    app(WalletUsageService::class)->debit($billable, 'Tokens', 3);

    expect($billable->usedBillingQuota('Tokens'))->toBe(3);
    expect($billable->remainingBillingQuota('Tokens'))->toBe(7);
});

it('falls back to the catalog trial length for a trial granted before the marker existed', function (): void {
    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_legacy');

    // Trials activated by an earlier version carry no marker at all.
    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta['trial_quota']);
    $billable->forceFill(['subscription_meta' => $meta])->save();

    expect($billable->grantedBillingQuota('Tokens'))->toBe(10);
    expect($billable->usedBillingQuota('Tokens'))->toBe(0);
});

it('ignores a marker that no longer describes the plan the billable is on', function (): void {
    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_stale');

    $meta = $billable->getBillingSubscriptionMeta();
    $meta['trial_quota']['plan_code'] = 'some-other-plan';
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Re-derived, not read from the stale marker.
    expect($billable->grantedBillingQuota('Tokens'))->toBe(10);
});

it('measures a paying subscription against the full period quota', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'paying@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_country' => 'AT',
    ])->save();

    app(WalletUsageService::class)->credit($billable, 'Tokens', 20);

    expect($billable->grantedBillingQuota('Tokens'))->toBe(20);
    expect($billable->usedBillingQuota('Tokens'))->toBe(0);
});

it('warns at the threshold of the trial quota, not of the plan quota', function (): void {
    Notification::fake();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $billable = grantedQuotaTrialBillable();
    activateGrantedQuotaTrial($billable, 'tr_granted_threshold');

    $billable->forceFill(['allows_billing_overage' => true])->save();

    // 8 of the 10 granted units — 80 %. Against the plan's 20 this reads as 40 %
    // and the customer heard nothing until they were deep into overage.
    app(WalletUsageService::class)->debit($billable, 'Tokens', 8);

    Notification::assertSentTimes(UsageThresholdNotification::class, 1);
});

it('prorates a quota to the trial length', function (): void {
    expect(TrialQuota::prorate(20, 14, 'monthly'))->toBe(10);
    expect(TrialQuota::prorate(25, 14, 'monthly'))->toBe(12);
    expect(TrialQuota::prorate(100, 14, 'monthly'))->toBe(47);
    expect(TrialQuota::prorate(1200, 30, 'yearly'))->toBe(99);

    // The fraction belongs to the customer.
    expect(TrialQuota::prorate(1, 1, 'monthly'))->toBe(1);

    // Nothing included, or no trial, grants nothing.
    expect(TrialQuota::prorate(0, 14, 'monthly'))->toBe(0);
    expect(TrialQuota::prorate(20, 0, 'monthly'))->toBe(0);

    expect(TrialQuota::forPlan(['Tokens' => 20, 'SMS' => 25, 'Empty' => 0], 14, 'monthly'))
        ->toBe(['Tokens' => 10, 'SMS' => 12]);
});

it('renders every wallet transaction reason as readable text', function (): void {
    config()->set('mollie-billing-plans.products.starter-pack', [
        'name' => 'Starter Pack',
        'price_net' => 1000,
        'usage_type' => 'Tokens',
        'quantity' => 500,
    ]);

    // Translated reasons.
    expect(UsageReason::label('subscription_trial_start'))->toBe('Trial quota');
    expect(UsageReason::label('subscription_renewal'))->toBe('Subscription renewal');
    expect(UsageReason::label('overage_settlement'))->toBe('Overage paid');

    // Dynamic reason, rendered with the product's name.
    expect(UsageReason::label('one_time_order:starter-pack'))->toBe('Purchase: Starter Pack');

    // An unknown identifier stays readable instead of leaking snake_case.
    expect(UsageReason::label('some_new_internal_reason'))->toBe('Some New Internal Reason');

    // Free text an app or an admin wrote passes through.
    expect(UsageReason::label('Brave Image Search'))->toBe('Brave Image Search');

    // A debit written without one is plain consumption.
    expect(UsageReason::label(null))->toBe('Usage');
    expect(UsageReason::label(''))->toBe('Usage');
});

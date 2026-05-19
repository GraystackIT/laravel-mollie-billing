<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialStarted;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class TrialActivationFakeWebhookController extends MollieWebhookController
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
    TrialActivationFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, TrialActivationFakeWebhookController::class);

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
                'included_usages' => ['Tokens' => 10, 'SMS' => 15],
                'usage_overage_prices' => ['Tokens' => 12, 'SMS' => 12],
            ],
        ],
    ]);
});

function trialWebhookBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'trial-wh@x.test',
        'name' => 'Trial WH',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b;
}

it('activates a trial subscription on a mandate_only paid webhook with trial_days metadata', function (): void {
    Event::fake([TrialStarted::class]);

    $billable = trialWebhookBillable();

    $capturedSubscription = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$capturedSubscription) {
        if ($request instanceof CreateSubscriptionRequest) {
            $capturedSubscription = $request;

            return (object) ['id' => 'sub_trial'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    TrialActivationFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mandate_trial',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_trial_days' => 14,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_trial']);
    $response->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->trial_ends_at)->not->toBeNull();
    expect($billable->trial_ends_at->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(14)->toDateString());

    // No invoice for a trial activation — no money flowed.
    expect(BillingInvoice::query()->count())->toBe(0);

    Event::assertDispatched(TrialStarted::class);

    // Mollie subscription created with startDate = now + 14d
    expect($capturedSubscription)->toBeInstanceOf(CreateSubscriptionRequest::class);
    $reflected = new ReflectionObject($capturedSubscription);
    $startDateProp = $reflected->getProperty('startDate');
    $startDateProp->setAccessible(true);
    $startDate = $startDateProp->getValue($capturedSubscription);
    expect((string) $startDate)->toBe(BillingTime::nowUtc()->addDays(14)->toDateString());
});

it('hydrates the wallet aliquot to the trial length using ceil(included * trialDays / intervalDays)', function (): void {
    $billable = trialWebhookBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial']);

    TrialActivationFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mandate_aliquot',
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

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_aliquot'])->assertStatus(200);

    $billable->refresh();

    // Monthly Pro, 14d trial: included = 10 Tokens, 15 SMS; intervalDays = 30
    // Tokens: ceil(10 * 14 / 30) = ceil(4.66...) = 5
    // SMS:    ceil(15 * 14 / 30) = ceil(7.0)     = 7
    $tokenWallet = $billable->getWallet('Tokens');
    $smsWallet = $billable->getWallet('SMS');

    expect($tokenWallet?->balanceInt ?? 0)->toBe(5);
    expect($smsWallet?->balanceInt ?? 0)->toBe(7);
});

it('is idempotent — a second mandate_only webhook does not reset an already-activated trial', function (): void {
    $billable = trialWebhookBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial']);

    $payload = [
        'id' => 'tr_mandate_first',
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

    TrialActivationFakeWebhookController::$nextPayment = (object) $payload;
    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_first'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    $originalTrialEndsAt = $billable->trial_ends_at?->toIso8601String();

    // Simulate a second mandate_only webhook arriving later (different payment_id,
    // same billable, same metadata). Without the idempotency guard this would
    // re-run the trial activation, forceFill the status back to Trial and
    // re-set trial_ends_at, effectively extending the trial.
    $secondPayload = $payload;
    $secondPayload['id'] = 'tr_mandate_second';
    TrialActivationFakeWebhookController::$nextPayment = (object) $secondPayload;

    // Advance time so a re-set trial_ends_at would observably differ.
    \Illuminate\Support\Carbon::setTestNow(BillingTime::nowUtc()->addMinutes(30));

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_second'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->trial_ends_at?->toIso8601String())->toBe($originalTrialEndsAt);

    \Illuminate\Support\Carbon::setTestNow();
});

it('credits more than 1× quota when the trial exceeds one interval', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 60);

    $billable = trialWebhookBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial']);

    TrialActivationFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mandate_long',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 60,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_long'])->assertStatus(200);

    $billable->refresh();

    // 60d trial / 30d interval = 2× quota
    expect($billable->getWallet('Tokens')?->balanceInt ?? 0)->toBe(20);
    expect($billable->getWallet('SMS')?->balanceInt ?? 0)->toBe(30);
});

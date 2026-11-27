<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * A trial whose first charge failed — or never reported back within the grace days —
 * is PastDue but has never converted. A later recurring payment that is paid (the late
 * SEPA first charge, a charge Mollie retries) recovers it, and that payment is the
 * conversion: the trial's prorated grant record has to go with it.
 *
 * It did not. `paid()` asked only whether the status was `Trial`, so the PastDue row became
 * Active with `trial_ends_at`, the `trial_quota` marker and no `trial_converted_at` — and the
 * next plan change rebalanced a paid full period against the trial's prorated grant.
 */
beforeEach(function (): void {
    config()->set('wallet.cache.ttl', 10 * 365 * 24 * 3600);

    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 1000],
                'usage_overage_prices' => ['Tokens' => 1],
            ],
        ],
    ]);
});

it('converts a trial that went past due on its first charge when a later charge is paid', function (): void {
    $this->travelTo(BillingTime::toUtc('2026-03-02T10:00:00Z'));

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_trial_pd', 'status' => 'active']);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial PastDue GmbH',
        'email' => 'trial-pd@x.test',
        'billing_country' => 'AT',
    ]);

    $trialStart = BillingTime::nowUtc();
    $trialEnd = $trialStart->copy()->addDays(14);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_trial_pd',
        'mollie_mandate_id' => 'mdt_trial_pd',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $trialStart,
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_trial_pd',
            'trial_quota' => TrialQuota::marker('pro', 'monthly', 14, ['Tokens' => 467]),
        ],
    ])->save();
    $billable->refresh();

    app(WalletUsageService::class)->credit($billable, 'Tokens', 467, 'subscription_trial_start');

    $payment = fn (string $id, string $status, string $at): object => (object) [
        'id' => $id,
        'status' => $status,
        'sequenceType' => 'recurring',
        'amount' => (object) ['value' => '12.00', 'currency' => 'EUR'],
        'paidAt' => $status === 'paid' ? $at : null,
        'failedAt' => $status === 'failed' ? $at : null,
        'createdAt' => $at,
        'subscriptionId' => 'sub_trial_pd',
        'countryCode' => 'AT',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    // Trial end: the first charge fails. PastDue, nothing about the trial touched.
    $this->travelTo($trialEnd->copy()->addMinutes(5));
    app(MollieWebhookController::class)->replayDelivery($payment('tr_first_failed', 'failed', $trialEnd->toIso8601String()), $billable->refresh());

    $billable->refresh();
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->getBillingSubscriptionMeta())->toHaveKey('trial_quota');

    Event::fake([TrialConverted::class]);

    // Mollie's retry of that charge is paid three days later.
    $retryAt = $trialEnd->copy()->addDays(3);
    $this->travelTo($retryAt->copy()->addMinutes(5));
    app(MollieWebhookController::class)->replayDelivery($payment('tr_retry_paid', 'paid', $retryAt->toIso8601String()), $billable->refresh());

    $billable->refresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($meta)->not->toHaveKey('trial_quota')
        ->and($meta['trial_converted_at'] ?? null)->not->toBeNull()
        ->and($billable->getBillingTrialEndsAt())->toBeNull()
        ->and(TrialQuota::grantedFromMeta($meta, 'pro', 'monthly'))->toBeNull()
        ->and((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(1000);

    Event::assertDispatched(TrialConverted::class);
});

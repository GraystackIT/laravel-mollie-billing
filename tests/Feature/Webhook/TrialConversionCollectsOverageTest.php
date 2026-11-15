<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * overage consumed during a trial is never collected.
 *
 * A trial billable holds a mandate, so `canChargeBillingOverage()` is true and
 * `debit()` lets the wallet go negative ("an overage that could actually be billed").
 * But the period-end sweep only fires the day before `nextBillingDate()`, which for a
 * trial is mandate-capture + one interval — not the trial end. The trial converts on
 * day 14, the conversion resets the wallet to the full period quota, and the debt is
 * gone: no charge, no snapshot, no carried balance.
 */
beforeEach(function (): void {
    // bavix keeps balances in a 24h array cache and defers the column write to the commit
    // RefreshDatabase never makes, so time travel past the TTL would read a stale 0.
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
                'included_usages' => ['Tokens' => 20],
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);
});

it('charges overage consumed during a trial when the trial converts', function (): void {
    $this->travelTo(BillingTime::toUtc('2026-03-02T10:00:00Z'));

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_trial_overage', 'status' => 'open']);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Overage GmbH',
        'email' => 'trial-overage@x.test',
        'billing_country' => 'AT',
    ]);

    $trialStart = BillingTime::nowUtc();
    $trialEnd = $trialStart->copy()->addDays(14);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_trial_ovg',
        'mollie_mandate_id' => 'mdt_trial_ovg',
        'allows_billing_overage' => true,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $trialStart,
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_trial_ovg',
            'trial_quota' => TrialQuota::marker('pro', 'monthly', 14, ['Tokens' => 10]),
        ],
    ])->save();
    $billable->refresh();

    $wallets = app(WalletUsageService::class);
    $wallets->credit($billable, 'Tokens', 10, 'subscription_trial_start');

    // The package itself says this overage is billable — that is why debit() allows it.
    expect($billable->canChargeBillingOverage('Tokens'))->toBeTrue();

    // 60 used against a grant of 10: 50 units of overage at 12 ct = 6.00 EUR net owed.
    $wallets->debit($billable, 'Tokens', 60);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(-50);

    // Every nightly pass of the trial, up to and including the eve of the trial end.
    for ($day = 1; $day <= 13; $day++) {
        $this->travelTo($trialStart->copy()->addDays($day)->setTime(1, 0));
        PrepareUsageOverageJob::dispatchSync();
    }

    // Sanity: thirteen nightly passes later the debt is untouched and nothing was charged.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(-50);
    expect($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage');

    // Trial end: Mollie collects the first recurring charge and the webhook converts.
    $this->travelTo($trialEnd->copy()->addMinutes(5));

    app(MollieWebhookController::class)->replayDelivery((object) [
        'id' => 'tr_trial_first_charge',
        'status' => 'paid',
        'amount' => (object) ['value' => '12.00', 'currency' => 'EUR'],
        'paidAt' => $trialEnd->toIso8601String(),
        'subscriptionId' => 'sub_trial_ovg',
        'countryCode' => 'AT',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ], $billable->refresh());

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);

    $meta = $billable->getBillingSubscriptionMeta();
    $snapshot = (array) ($meta[ChargeUsageOverageDirectly::META_KEY] ?? []);
    $line = collect((array) ($snapshot['line_items'] ?? []))->firstWhere('type', 'Tokens');

    // Charged before the reset, by the conversion itself: 50 units at 12 ct.
    expect($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($snapshot['payment_id'] ?? null)->toBe('tr_trial_overage')
        ->and((int) ($line['quantity'] ?? 0))->toBe(50)
        ->and((int) ($snapshot['amount_net'] ?? 0))->toBe(600);

    // …and the debt is carried until that payment settles (20 − 50), exactly as a
    // period-end charge is. The settlement credits the 50 back and leaves the full 20.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(-30);
});

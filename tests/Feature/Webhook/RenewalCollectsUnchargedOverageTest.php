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
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * a renewal that settles more than a day "early" forgives the period's overage.
 *
 * The overage sweep fires only on the single night before `nextBillingDate()`, and that
 * date is anchored on the previous renewal's `paidAt`. With SEPA Direct Debit `paidAt`
 * trails Mollie's fixed collection date by a lag that varies (weekends, bank holidays).
 * When this period's lag is more than a day shorter than last period's, the renewal
 * webhook arrives BEFORE the sweep night: no charge is pending, so the non-rollover reset
 * forgives the negative balance, the anchor jumps forward, and the sweep's
 * `renewsTomorrow` window never matches for the period that just ended.
 */
beforeEach(function (): void {
    // bavix keeps balances in a 24h array cache and defers the column write to the commit
    // RefreshDatabase never makes, so time travel past the TTL would read a stale 0.
    config()->set('wallet.cache.ttl', 10 * 365 * 24 * 3600);

    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);
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
                'included_usages' => ['SMS' => 50],
                'usage_overage_prices' => ['SMS' => 15],
            ],
        ],
    ]);
});

it('collects the overage itself when the renewal settles before the sweep night', function (): void {
    // Mollie collects on the 1st. January's debit took five days to settle (paidAt = 6th),
    // so the local anchor is the 6th and the sweep is due on the night of 5 February.
    $this->travelTo(BillingTime::toUtc('2026-01-20T10:00:00Z'));

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_sepa_overage', 'status' => 'open']);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'SEPA Drift GmbH',
        'email' => 'sepa-drift@x.test',
        'billing_country' => 'DE',
    ]);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_sepa',
        'mollie_mandate_id' => 'mdt_sepa',
        'allows_billing_overage' => true,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::toUtc('2026-01-06T09:00:00Z'),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_sepa'],
    ])->save();
    $billable->refresh();

    $wallets = app(WalletUsageService::class);
    $wallets->credit($billable, 'SMS', 50, 'subscription_renewal');
    $wallets->debit($billable, 'SMS', 87);

    // 37 units over quota at 15 ct = 5.55 EUR net owed.
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(-37);

    // The nightly pass runs every night; none of these is "the day before the 6th".
    foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $night) {
        $this->travelTo(BillingTime::toUtc($night.'T01:00:00Z'));
        PrepareUsageOverageJob::dispatchSync();
    }

    // Sanity: the debt is still on the wallet, untouched by those passes.
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(-37);
    expect($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage');

    // February's debit (collected on the 1st) settles in two days instead of five.
    $this->travelTo(BillingTime::toUtc('2026-02-03T08:05:00Z'));

    app(MollieWebhookController::class)->replayDelivery((object) [
        'id' => 'tr_sepa_renewal_feb',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => '2026-02-03T08:00:00Z',
        'subscriptionId' => 'sub_sepa',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ], $billable->refresh());

    // The nights the sweep would have fired on, and a few after.
    foreach (['2026-02-04', '2026-02-05', '2026-02-06', '2026-02-07'] as $night) {
        $this->travelTo(BillingTime::toUtc($night.'T01:00:00Z'));
        PrepareUsageOverageJob::dispatchSync();
    }

    $billable->refresh();

    $meta = $billable->getBillingSubscriptionMeta();
    $snapshot = (array) ($meta[ChargeUsageOverageDirectly::META_KEY] ?? []);
    $line = collect((array) ($snapshot['line_items'] ?? []))->firstWhere('type', 'SMS');

    // The renewal collected for itself: 37 units at 15 ct, one payment, and the later
    // nightly passes found it in flight rather than charging again.
    expect($snapshot['payment_id'] ?? null)->toBe('tr_sepa_overage')
        ->and((int) ($line['quantity'] ?? 0))->toBe(37)
        ->and((int) ($snapshot['amount_net'] ?? 0))->toBe(555);

    // Debt carried until the charge settles: 50 − 37.
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(13);
});

/** A paying billable whose period ended with `$debt` SMS of uncharged overage. */
function uncollectedOverageBillable(int $debt, array $meta = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Uncollected GmbH',
        'email' => uniqid('uncollected-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_unc',
        'mollie_mandate_id' => 'mdt_unc',
        'allows_billing_overage' => true,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth(),
        'subscription_meta' => array_merge(['seat_count' => 1, 'mollie_subscription_id' => 'sub_unc'], $meta),
    ])->save();

    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS'])
        ->forceWithdraw($debt, ['type' => 'SMS', 'reason' => 'usage']);

    return $billable->refresh();
}

function deliverUncollectedRenewal(TestBillable $billable): void
{
    app(MollieWebhookController::class)->replayDelivery((object) [
        'id' => 'tr_unc_renewal',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_unc',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ], $billable);
}

it('does not charge a second time when the sweep already has a charge in flight', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $billable = uncollectedOverageBillable(37, [
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_sweep',
            'line_items' => [['kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'quantity' => 37, 'unit_price_net' => 15, 'total_net' => 555]],
            'created_at' => BillingTime::nowUtc()->subDay()->toIso8601String(),
        ],
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
    ]);

    deliverUncollectedRenewal($billable);

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY]['payment_id'])->toBe('tr_sweep')
        ->and((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(13);
});

it('collects the debt of a cycle that gave up, instead of writing it off', function (): void {
    // The rule this replaces: a cycle that spent its dunning budget was written off by the
    // renewal's reset. Now the renewal starts a new cycle for it. (This fixture is a row as
    // an older release left it — status `failed`, units still on the wallet.)
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_again', 'status' => 'open']);

    $billable = uncollectedOverageBillable(37, [
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_gave_up',
            'line_items' => [['kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'quantity' => 37, 'unit_price_net' => 15, 'total_net' => 555]],
            'created_at' => BillingTime::nowUtc()->subDays(2)->toIso8601String(),
        ],
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_FAILED,
    ]);

    deliverUncollectedRenewal($billable);

    $billable->refresh();
    $snapshot = $billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY];

    // One payment for the 37 units — not for 74 — and the debt carried until it settles.
    expect($snapshot['payment_id'])->toBe('tr_again')
        ->and((int) $snapshot['amount_net'])->toBe(555)
        ->and($snapshot['line_items'])->toHaveCount(1)
        ->and((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(13);
});

it('carries the debt when the charge cannot be created at all', function (): void {
    // Mollie is down at the moment of the renewal. The debt must survive the reset — it is
    // frozen in the snapshot for the reconciliation job, not forgiven.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie is unreachable'));

    $billable = uncollectedOverageBillable(37);

    deliverUncollectedRenewal($billable);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(13)
        ->and(app(ChargeUsageOverageDirectly::class)->paymentMayExist($billable))->toBeTrue();
});

it('never zeroes a collectable debt on a path that cannot charge first', function (): void {
    // `creditPeriodQuota()` on its own — the paid trial conversion in ProrataChargeHandler
    // reaches it without the charge step. The reset must still not give the units away.
    $billable = uncollectedOverageBillable(37);

    app(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class)
        ->creditPeriodQuota($billable, 'pro', 'monthly', 'subscription_renewal');

    expect((int) $billable->refresh()->getWallet('SMS')->refresh()->balanceInt)->toBe(13);
});

it('still forgives a debt nobody could ever collect', function (): void {
    // No overage price on the plan: nothing can bill this balance, so preserving it would
    // only strand the wallet. The ordinary reset applies.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['SMS' => false]);

    $billable = uncollectedOverageBillable(37);

    app(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class)
        ->creditPeriodQuota($billable, 'pro', 'monthly', 'subscription_renewal');

    expect((int) $billable->refresh()->getWallet('SMS')->refresh()->balanceInt)->toBe(50);
});

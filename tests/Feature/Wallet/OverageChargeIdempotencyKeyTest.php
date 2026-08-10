<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * A retry of one overage charge must not buy a second payment.
 *
 * `charge()` persists the snapshot, calls Mollie, then stores the payment id. When the call
 * throws *after* Mollie created the payment — a read timeout on the response is the ordinary
 * case — no id is ever stored, and `RetryUsageOverageChargeJob` comes back 60 seconds later
 * and charges the same debt again. Both settle, each into its own invoice under the webhook's
 * per-payment idempotency, and the customer paid twice.
 *
 * Mollie replays the original response for a repeated `Idempotency-Key` (for one hour, and
 * only when the parameters match), so the fix is a key that is stable across the retries of
 * one dunning cycle and different for the next one.
 */
function idempotencyBillable(int $negativeBalance): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => uniqid('idem-').'@x.test']);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_idem',
        'mollie_customer_id' => 'cst_idem',
        'allows_billing_overage' => true,
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->forceWithdraw($negativeBalance);

    return $b->refresh();
}

/**
 * Records every key handed to the connector, the way the real client would receive it.
 *
 * @param  array<int, string|null>  $keys
 */
function recordIdempotencyKeys(array &$keys, mixed $sendBehaviour): void
{
    // Built by hand rather than through `Mollie::shouldReceive()`, because both setters are
    // fluent — they return the client — and the recorder has to honour that.
    $client = Mockery::mock(\Mollie\Api\MollieApiClient::class);

    $client->shouldReceive('setIdempotencyKey')->andReturnUsing(function ($key) use (&$keys, $client) {
        $keys[] = $key;

        return $client;
    });
    $client->shouldReceive('resetIdempotencyKey')->andReturnUsing(fn () => $client);

    if ($sendBehaviour instanceof Throwable) {
        $client->shouldReceive('send')->andThrow($sendBehaviour);
    } else {
        $client->shouldReceive('send')->andReturn($sendBehaviour);
    }

    Mollie::swap($client);
}

beforeEach(function (): void {
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
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
        ],
    ]);
});

it('sends one key for a charge and its retry', function (): void {
    $keys = [];
    $billable = idempotencyBillable(20);

    // Attempt 1: Mollie created the payment, we never saw the response.
    recordIdempotencyKeys($keys, new RuntimeException('read timeout on the response'));

    try {
        app(ChargeUsageOverageDirectly::class)->handle($billable);
    } catch (\Throwable) {
        // The lost response. The snapshot is on record, without a payment id.
    }

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()['usage_overage'] ?? null;

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['payment_id'])->toBeNull();

    // Attempt 2, 60 seconds later: RetryUsageOverageChargeJob, driven by that snapshot.
    Mollie::clearResolvedInstances();
    recordIdempotencyKeys($keys, (object) ['id' => 'tr_replayed', 'status' => 'open']);

    app(ChargeUsageOverageDirectly::class)->retry($billable->refresh());

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBeNull()
        ->and($keys[0])->toStartWith('usage-overage:')
        // The same key, so Mollie answers the retry with the payment it already created
        // instead of creating a second one for the same debt.
        ->and($keys[1])->toBe($keys[0]);
});

it('sends a different key once the cycle has failed', function (): void {
    $keys = [];
    $billable = idempotencyBillable(20);

    recordIdempotencyKeys($keys, (object) ['id' => 'tr_cycle_1', 'status' => 'open']);
    app(ChargeUsageOverageDirectly::class)->handle($billable);

    // The cycle gave up. Everything after this is a new debt and a legitimately new payment —
    // reusing the key would have Mollie replay the old response and collect nothing, which is
    // silent revenue loss rather than a double charge.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_FAILED;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $billable->getWallet('Tokens')->forceWithdraw(20);

    Mollie::clearResolvedInstances();
    recordIdempotencyKeys($keys, (object) ['id' => 'tr_cycle_2', 'status' => 'open']);

    app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($keys)->toHaveCount(2)
        ->and($keys[1])->not->toBe($keys[0]);
});

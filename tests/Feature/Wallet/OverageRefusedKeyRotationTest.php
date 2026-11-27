<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\KeyedMollieWorld;

/**
 * An overage charge Mollie refused is retried by `RetryUsageOverageChargeJob` at 1, 5 and 15
 * minutes — all inside the hour for which a 4xx binds its idempotency key (measured
 * 26 November 2026). With the cycle's stable key every retry got the refusal replayed, even
 * once the customer had fixed the mandate. After a definite refusal the next attempt carries
 * a new key; after an outage or a lost answer it keeps the old one, so Mollie replays the
 * payment it may hold instead of creating a second.
 */
function refusedOverageBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Refused Overage', 'email' => uniqid('refused-ov-').'@x.test']);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_refused',
        'mollie_customer_id' => 'cst_refused',
        'allows_billing_overage' => true,
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->forceWithdraw(20);

    return $b->refresh();
}

function refusedOverageAttempt(callable $attempt): ?Throwable
{
    try {
        $attempt();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
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

it('retries an overage charge Mollie refused with a new key, at once, and charges once', function (): void {
    $billable = refusedOverageBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    $mollie->refuseWith = 422;
    $mollie->refusalDetail = 'The mandate is invalid';
    expect(refusedOverageAttempt(fn () => $service->handle($billable)))->not->toBeNull();

    // The customer replaced the mandate; the job's first retry comes a minute later.
    $mollie->refuseWith = null;
    expect(refusedOverageAttempt(fn () => $service->retry($billable->refresh())))->toBeNull();

    expect($mollie->payments())->toHaveCount(1)
        ->and($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->not->toBe($mollie->sentKeys[0])
        ->and($mollie->sentKeys[1])->toStartWith((string) $mollie->sentKeys[0])
        ->and($billable->refresh()->getBillingSubscriptionMeta()['usage_overage']['payment_id'])->toBe('tr_1');
});

it('moves the overage key on with every refusal of the ladder', function (): void {
    $billable = refusedOverageBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    $mollie->refuseWith = 422;
    refusedOverageAttempt(fn () => $service->handle($billable));
    refusedOverageAttempt(fn () => $service->retry($billable->refresh()));

    $mollie->refuseWith = null;
    expect(refusedOverageAttempt(fn () => $service->retry($billable->refresh())))->toBeNull();

    expect(array_unique($mollie->sentKeys))->toHaveCount(3)
        ->and($mollie->payments())->toHaveCount(1);
});

it('keeps the overage key after an outage, and charges once', function (): void {
    $billable = refusedOverageBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    $mollie->outagesLeft = 1;
    expect(refusedOverageAttempt(fn () => $service->handle($billable)))->not->toBeNull();
    expect(refusedOverageAttempt(fn () => $service->retry($billable->refresh())))->toBeNull();

    expect($mollie->sentKeys[1])->toBe($mollie->sentKeys[0])
        ->and($mollie->payments())->toHaveCount(1);
});

it('does not charge twice after a lost answer that follows a refusal', function (): void {
    $billable = refusedOverageBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    $mollie->refuseWith = 422;
    refusedOverageAttempt(fn () => $service->handle($billable));

    $mollie->refuseWith = null;
    $mollie->loseNextAnswer = true;
    expect(refusedOverageAttempt(fn () => $service->retry($billable->refresh())))->not->toBeNull();
    expect(refusedOverageAttempt(fn () => $service->retry($billable->refresh())))->toBeNull();

    expect($mollie->sentKeys[2])->toBe($mollie->sentKeys[1])
        ->and($mollie->payments())->toHaveCount(1)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['usage_overage']['payment_id'])->toBe('tr_1');
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\KeyedMollieWorld;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Maintainer's decision (27 November 2026): an overage charge Mollie *definitely refused* (a 4xx
 * per `MollieRefusal::isDefinite()`) does not freeze the debt. Nothing was charged and that is
 * known, so no `payment_unknown_at` is written: the next attempt goes out with a moved-on key
 * through the ordinary retry ladder, without waiting for `CleanupStalePendingOverageChargeJob`
 * to ask Mollie. A timeout, a 5xx or a lost answer still freezes the debt until it has.
 */
function refusalReleaseBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Refusal Release', 'email' => uniqid('refusal-release-').'@x.test']);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_release',
        'mollie_customer_id' => 'cst_release',
        'allows_billing_overage' => true,
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->forceWithdraw(20);

    return $b->refresh();
}

function refusalReleaseAttempt(callable $attempt): ?Throwable
{
    try {
        $attempt();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/** @return array<string, mixed> */
function refusalReleaseSnapshot(TestBillable $billable): array
{
    return (array) ($billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? []);
}

/** The nightly pass, with the queue faked: did its retry pass (3) dispatch the ladder? */
function refusalReleaseNightDispatchesRetry(): bool
{
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();
    app()->call([new PrepareUsageOverageJob, 'handle']);
    $pushed = Queue::pushed(RetryUsageOverageChargeJob::class)->isNotEmpty();
    Queue::swap($realQueue);

    return $pushed;
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

it('releases a refused overage charge at once: no payment_unknown_at, the ladder retries with a new key and charges once', function (): void {
    $billable = refusalReleaseBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    $mollie->refuseWith = 422;
    $mollie->refusalDetail = 'The mandate is invalid';
    expect(refusalReleaseAttempt(fn () => $service->handle($billable)))->not->toBeNull();

    $snapshot = refusalReleaseSnapshot($billable);

    // Known: nothing was charged. The debt is still on record, but not frozen.
    expect($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and($snapshot['payment_id'] ?? null)->toBeNull()
        ->and($snapshot['line_items'] ?? [])->not->toBeEmpty()
        ->and($snapshot[ChargeUsageOverageDirectly::KEY_REFUSALS_KEY]['refusals'] ?? null)->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($service->paymentMayExist($billable))->toBeFalse()
        ->and($service->chargeIsInFlight($billable))->toBeFalse();

    // The nightly retry pass hands it to the ladder — it does not wait for the stale sweep.
    expect(refusalReleaseNightDispatchesRetry())->toBeTrue();

    // The customer replaced the mandate; the ladder's next run charges with a moved-on key.
    $mollie->refuseWith = null;
    (new RetryUsageOverageChargeJob(TestBillable::class, $billable->getKey()))->handle($service);

    expect($mollie->payments())->toHaveCount(1)
        ->and($mollie->sentKeys)->toHaveCount(2)
        ->and($mollie->sentKeys[1])->toBe($mollie->sentKeys[0].':refused-1')
        ->and(refusalReleaseSnapshot($billable)['payment_id'] ?? null)->toBe('tr_1');
});

it('keeps the existing failure path when refusals exhaust the ladder, without payment_unknown_at', function (): void {
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $billable = refusalReleaseBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);
    $mollie->refuseWith = 422;

    $last = refusalReleaseAttempt(fn () => $service->handle($billable));
    $job = new RetryUsageOverageChargeJob(TestBillable::class, $billable->getKey());

    foreach ([1, 2, 3] as $try) {
        $last = refusalReleaseAttempt(fn () => $job->handle($service)) ?? $last;
    }

    // The queue's tries are spent.
    $job->failed($last);

    $snapshot = refusalReleaseSnapshot($billable);

    Notification::assertSentTimes(AdminOverageBillingFailedNotification::class, 1);
    Notification::assertNotSentTo($billable, OverageBillingFailedNotification::class);

    // Create failures never touched the payment method: no dunning, no past due — as before.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and($snapshot[ChargeUsageOverageDirectly::KEY_REFUSALS_KEY]['refusals'] ?? null)->toBe(4)
        ->and(array_unique($mollie->sentKeys))->toHaveCount(4)
        ->and($mollie->payments())->toHaveCount(0);

    // And the next night tries again instead of waiting for a reconciliation nobody needs.
    expect(refusalReleaseNightDispatchesRetry())->toBeTrue();
});

it('still freezes the debt after a 5xx or a lost answer until Mollie has been asked', function (string $failure): void {
    $billable = refusalReleaseBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $service = app(ChargeUsageOverageDirectly::class);

    if ($failure === '503') {
        $mollie->outagesLeft = 1;
    } else {
        $mollie->loseNextAnswer = true;
    }

    expect(refusalReleaseAttempt(fn () => $service->handle($billable)))->not->toBeNull();

    $snapshot = refusalReleaseSnapshot($billable);

    expect($snapshot)->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::KEY_REFUSALS_KEY)
        ->and($service->paymentMayExist($billable))->toBeTrue()
        ->and($service->chargeIsInFlight($billable))->toBeTrue()
        ->and(refusalReleaseNightDispatchesRetry())->toBeFalse();
})->with(['503', 'lost answer']);

it('leaves a refused plan-change charge in the snapshot instead of also handing it back to the wallet', function (): void {
    $billable = refusalReleaseBillable();
    $mollie = (new KeyedMollieWorld)->install();
    $mollie->refuseWith = 422;

    $balanceBefore = (int) $billable->getWallet('Tokens')->balanceInt;
    $lines = [['type' => 'Tokens', 'quantity' => 7, 'unit_price_net' => 5, 'total_net' => 35, 'settles_wallet' => false]];

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, $lines);

    $snapshot = refusalReleaseSnapshot($billable);

    // One record of the debt, not two: the retry charges the snapshot, and a wallet that
    // also held it would be charged again by the next sweep.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe($balanceBefore)
        ->and($snapshot['line_items'][0]['quantity'] ?? null)->toBe(7)
        ->and($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and($billable->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

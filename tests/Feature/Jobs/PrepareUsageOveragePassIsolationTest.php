<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Queue;

/**
 * One unusable row must not silence the passes for every row behind it.
 *
 * `chunkById()` walks ascending ids, so anything thrown inside a chunk closure ends that
 * pass — and, since it escapes `handle()`, the passes after it as well. Passes 1, 2 and 4
 * wrap each row for exactly this reason; pass 3 (retry the overage charges that never
 * reached Mollie) did not, so a single billable whose snapshot could not be read stopped
 * every higher id from being retried at all, silently, for a whole day.
 */
$overageBillable = function (string $email): TestBillable {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Pass3', 'email' => $email, 'billing_country' => 'AT']);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_pass3',
        'mollie_mandate_id' => 'mdt_pass3',
        // A charge that was persisted as pending and never got a payment id: exactly what
        // pass 3 exists to pick up.
        'subscription_meta' => [
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            ChargeUsageOverageDirectly::META_KEY => [
                'line_items' => [['type' => 'tokens', 'quantity' => 10, 'total_net' => 100]],
                'payment_id' => null,
            ],
        ],
    ])->save();

    return $b->refresh();
};

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
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
                'included_usages' => ['tokens' => 1000],
                'usage_overage_prices' => ['tokens' => 2],
            ],
        ],
    ]);
});

it('retries the overage charges behind a row it cannot read', function () use ($overageBillable): void {
    $broken = $overageBillable(uniqid('broken-').'@x.test');
    $healthy = $overageBillable(uniqid('healthy-').'@x.test');

    // Lower id first, so `chunkById()` reaches the unusable row before the healthy one.
    expect($broken->getKey())->toBeLessThan($healthy->getKey());

    // Stands in for whatever makes a single snapshot unreadable — a cast that throws on a
    // value written by an older version, a wallet lookup against a dropped table. The
    // pass has to survive it and carry on with the next row.
    $this->app->bind(ChargeUsageOverageDirectly::class, function ($app) use ($broken) {
        return new class($broken->getKey(), $app) extends ChargeUsageOverageDirectly
        {
            public function __construct(private readonly mixed $brokenKey, $app)
            {
                parent::__construct(
                    $app->make(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class),
                    $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
                    $app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class),
                );
            }

            public function pendingCharge(\GraystackIT\MollieBilling\Contracts\Billable $billable): ?array
            {
                if ($billable->getKey() === $this->brokenKey) {
                    throw new RuntimeException('snapshot unreadable');
                }

                return parent::pendingCharge($billable);
            }
        };
    });

    Queue::fake();

    // Called through the container rather than `dispatchSync()`: with the queue faked,
    // `dispatchSync()` is recorded instead of executed, and the container call is also what
    // resolves the stub above into `handle()`'s signature.
    app()->call([new PrepareUsageOverageJob, 'handle']);

    Queue::assertPushed(
        RetryUsageOverageChargeJob::class,
        fn (RetryUsageOverageChargeJob $job): bool => $job->billableId === $healthy->getKey(),
    );
});

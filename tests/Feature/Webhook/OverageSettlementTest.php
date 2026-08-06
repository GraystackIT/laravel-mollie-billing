<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * A settled overage charge must clear the debt it paid for, and a failed one
 * must actually reach the retry job — including in apps that register a
 * morph map, where getMorphClass() is an alias and not a resolvable class.
 */
class OverageFakeWebhookController extends MollieWebhookController
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
    OverageFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, OverageFakeWebhookController::class);

    // Apps commonly alias their models; the package must cope with it.
    Relation::morphMap(['test-billable' => TestBillable::class]);

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
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);
});

afterEach(function (): void {
    Relation::morphMap([], merge: false);
});

function overageSettlementBillable(int $negativeBalance = 30): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'overage@x.test',
        'name' => 'Overage',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_ov',
        'mollie_mandate_id' => 'mdt_ov',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(20),
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_ov',
            'usage_overage' => ['payment_id' => 'tr_overage', 'line_items' => []],
            'usage_overage_status' => 'pending',
            'usage_overage_attempts' => 1,
        ],
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->forceWithdraw($negativeBalance, ['type' => 'Tokens', 'reason' => 'usage']);

    return $b;
}

/** @return array<int, array<string, mixed>> */
function overageSettlementLineItems(int $quantity = 30): array
{
    return [[
        'kind' => 'overage',
        'code' => 'Tokens',
        'type' => 'Tokens',
        'label' => 'Overage: Tokens',
        'quantity' => $quantity,
        'unit_price' => 12,
        'unit_price_net' => 12,
        'total_net' => $quantity * 12,
    ]];
}

it('credits the paid overage quantities back so the negative balance is settled', function (): void {
    Event::fake([OverageCharged::class]);

    $billable = overageSettlementBillable(30);
    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(-30);

    OverageFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_overage',
        'status' => 'paid',
        'amount' => (object) ['value' => '4.28', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => overageSettlementLineItems(30),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_overage'])->assertStatus(200);

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_overage')->exists())->toBeTrue();

    // The units the customer just paid for are no longer owed.
    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0);

    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    expect($meta)->not->toHaveKey('usage_overage')
        ->and($meta)->not->toHaveKey('usage_overage_status')
        ->and($meta)->not->toHaveKey('usage_overage_attempts');

    Event::assertDispatched(OverageCharged::class);
});

it('keeps consumption that happened after the charge was issued in the balance', function (): void {
    // 30 units were charged, 5 more consumed while the payment was in flight.
    $billable = overageSettlementBillable(35);

    OverageFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_overage_partial',
        'status' => 'paid',
        'amount' => (object) ['value' => '4.28', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => overageSettlementLineItems(30),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_overage_partial'])->assertStatus(200);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(-5);
});

it('dispatches the overage retry with the billable FQCN, not the morph alias', function (): void {
    Queue::fake();

    $billable = overageSettlementBillable();

    expect($billable->getMorphClass())->toBe('test-billable');

    OverageFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_overage_failed',
        'status' => 'failed',
        'amount' => (object) ['value' => '4.28', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => overageSettlementLineItems(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_overage_failed'])->assertStatus(200);

    // RetryUsageOverageChargeJob::handle() returns silently when the class does
    // not exist, so an alias here would disable the whole retry chain.
    Queue::assertPushed(
        RetryUsageOverageChargeJob::class,
        fn (RetryUsageOverageChargeJob $job) => $job->billableClass === TestBillable::class
            && (int) $job->billableId === (int) $billable->getKey()
            && class_exists($job->billableClass),
    );
});

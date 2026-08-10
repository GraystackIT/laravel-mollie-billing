<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/**
 * The window where a lost response used to cost the customer twice.
 *
 * `charge()` writes the snapshot, posts the payment, then stores the id. A throw in between —
 * a read timeout on the response, the ordinary failure — leaves a pending snapshot with no id
 * although Mollie may well be collecting. Locally the two are indistinguishable, and both
 * wrong answers cost money:
 *
 *  - read as *charged*: the debt is silenced forever;
 *  - read as *not charged*: the excess goes back on the wallet, the next nightly sweep charges
 *    it again, and the first payment settles into its own invoice. For a plan-change line the
 *    webhook does not credit the units back (`settles_wallet: false`), so nothing cancels out.
 *
 * Mollie's own payment list decides. Until it has, nobody charges.
 */
function lostPaymentBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Lost Co', 'email' => uniqid('lost-').'@x.test', 'billing_country' => 'AT']);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'mollie_customer_id' => 'cst_lost',
        'mollie_mandate_id' => 'mdt_lost',
        'allows_billing_overage' => true,
    ])->save();

    $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);

    return $b->refresh();
}

/** The line items a plan change hands to `chargeDeferredOverage()`. 20 units × 5 cents. */
function lostPaymentLineItems(): array
{
    return [[
        'kind' => 'overage',
        'type' => 'Tokens',
        'code' => 'Tokens',
        'quantity' => 20,
        'unit_price_net' => 5,
        'total_net' => 100,
        'settles_wallet' => false,
    ]];
}

/** A payment as Mollie's customer-payment list hydrates it. */
function lostPaymentObject(TestBillable $billable, string $id, string $value, ?string $createdAt = null): object
{
    return (object) [
        'id' => $id,
        'status' => 'open',
        'amount' => (object) ['value' => $value, 'currency' => 'EUR'],
        'createdAt' => $createdAt ?? BillingTime::nowUtc()->toIso8601String(),
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
}

beforeEach(function (): void {
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

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
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
        ],
    ]);
});

it('does not hand the debt back to the wallet when the payment may already exist', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andThrow(new RuntimeException('read timeout on the response'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? [];

    // The debt stays in the snapshot, marked, and the wallet is NOT pushed negative again —
    // `adjust()` already cleared it, and restoring it is what the second charge came from.
    expect($snapshot[ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY] ?? null)->not->toBeNull()
        ->and((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(0);
});

it('refuses a fresh charge while a payment may already exist', function (): void {
    $billable = lostPaymentBillable();

    // Counted rather than expected `->once()`, so the failure reads as what it is: how many
    // times this debt was sent to Mollie.
    $sends = 0;

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function () use (&$sends): never {
        $sends++;

        throw new RuntimeException('read timeout on the response');
    });

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    // The wallet is negative again — through ordinary usage, or because a caller re-derived the
    // debt. Without the marker this is a debt like any other and the sweep charges it: a second
    // payment for money that may already be being collected.
    $billable->getWallet('Tokens')->forceWithdraw(20);

    try {
        $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());
    } catch (\Throwable) {
        // Caught so the count below is what the test reports: without the guard the charge is
        // attempted a second time and throws again, which would otherwise mask the real finding.
        $result = null;
    }

    expect($sends)->toBe(1, 'the debt must reach Mollie once, not once per sweep')
        ->and($result?->wasCharged() ?? false)->toBeFalse();
});

it('adopts the payment Mollie turns out to hold', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('read timeout on the response'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    // Age the snapshot past the sweep's staleness gate.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] = BillingTime::nowUtc()->subHours(100)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    // First the customer's payment list, then the status poll for the adopted id.
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($billable): mixed {
        if ($request instanceof \Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest) {
            return [lostPaymentObject($billable, 'tr_lost_1', '1.20', BillingTime::nowUtc()->subHours(100)->toIso8601String())];
        }

        return (object) ['id' => 'tr_lost_1', 'status' => 'open'];
    });

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? [];

    // The id it lost, so every ordinary path owns the charge again — the status poll, the
    // replay of a lost `paid` delivery, the write-off of a `failed` one.
    expect($snapshot['payment_id'] ?? null)->toBe('tr_lost_1')
        ->and($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY);
});

it('clears the marker when Mollie holds no such payment, so the debt stays chargeable', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('connection refused'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] = BillingTime::nowUtc()->subHours(100)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    // Mollie lists the customer's payments and this charge is not among them: the request never
    // arrived. The debt is an ordinary un-charged one again — silencing it would lose the money.
    Mollie::shouldReceive('send')->andReturn([]);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? [];

    expect($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and(app(ChargeUsageOverageDirectly::class)->paymentMayExist($billable->refresh()))->toBeFalse();
});

it('decides nothing while Mollie cannot be reached', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('read timeout on the response'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] = BillingTime::nowUtc()->subHours(100)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie is down'));

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    // An unanswered question is not evidence of absence: the marker survives, so tomorrow's run
    // asks again and nothing charges in the meantime.
    expect(app(ChargeUsageOverageDirectly::class)->paymentMayExist($billable->refresh()))->toBeTrue();
});

it('treats a deleted Mollie customer as proof the payment cannot exist', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('read timeout on the response'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] = BillingTime::nowUtc()->subHours(100)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // 410 Gone: the customer was deleted at Mollie, so no payment of theirs survives either.
    // Leaving the marker would silence this debt — and every later overage for this billable.
    Mollie::shouldReceive('send')->andThrow(new class ('gone', 410) extends \Mollie\Api\Exceptions\ApiException
    {
        public function __construct(string $message, int $code)
        {
            \Exception::__construct($message, $code);
        }
    });

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect(app(ChargeUsageOverageDirectly::class)->paymentMayExist($billable->refresh()))->toBeFalse();
});

it('reconciles a lost answer within the hour, not after three days', function (): void {
    $billable = lostPaymentBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('read timeout on the response'));

    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable, lostPaymentLineItems());

    // Two hours old: past the reconciliation clock, nowhere near the 72-hour staleness gate the
    // other branches wait out. Those 72 hours buy patience for a delivery that is still coming;
    // here nothing is coming, and until the question is answered this billable's overage cannot
    // be charged at all — so waiting three days would freeze the money for nothing.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] = BillingTime::nowUtc()->subHours(2)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($billable): mixed {
        if ($request instanceof \Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest) {
            return [lostPaymentObject($billable, 'tr_quick_1', '1.20', BillingTime::nowUtc()->subHours(2)->toIso8601String())];
        }

        return (object) ['id' => 'tr_quick_1', 'status' => 'open'];
    });

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? [];

    expect($snapshot['payment_id'] ?? null)->toBe('tr_quick_1');
});

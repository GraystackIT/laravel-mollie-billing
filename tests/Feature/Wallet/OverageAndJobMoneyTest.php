<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * Four ways the unattended jobs got money wrong. None of them is visible to anyone
 * at the time: they happen at 02:00, or days later when a SEPA payment settles.
 */
class JobMoneyFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    // The overage charge carries a stable idempotency key, so a retry cannot buy a second
    // payment for the same debt — see MollieCall::send() and ChargeUsageOverageDirectly.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();

    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    JobMoneyFakeWebhookController::$payments = [];
    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieWebhookController::class, JobMoneyFakeWebhookController::class);

    // Non-rollover: the reset case, which is where the debt used to be forgiven.
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    foreach (['starter' => 1000, 'pro' => 3000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $code === 'pro' ? 3 : 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => $price,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 100],
                    'usage_overage_prices' => ['Tokens' => 10],
                ],
                'yearly' => [
                    'base_price_net' => $price * 10,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 1200],
                    'usage_overage_prices' => ['Tokens' => 10],
                ],
            ],
        ]);
    }
});

function jobMoneyBillable(array $overrides = [], array $metaOverrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Job Money Co',
        'email' => uniqid('jobmoney-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_job',
        'mollie_mandate_id' => 'mdt_job',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(29),
        'subscription_meta' => array_merge(['mollie_subscription_id' => 'sub_job'], $metaOverrides),
    ], $overrides))->save();

    return $b->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 7a — the renewal must not forgive a debt whose charge is still settling
// ─────────────────────────────────────────────────────────────────────────────

it('keeps the overage debt through a renewal while its charge is unsettled', function (): void {
    $billable = jobMoneyBillable([], [
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_overage',
            'line_items' => [[
                'kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 37, 'unit_price_net' => 10, 'total_net' => 370,
            ]],
            'created_at' => BillingTime::nowUtc()->toIso8601String(),
        ],
    ]);

    $wallets = app(WalletUsageService::class);
    $wallets->credit($billable, 'Tokens', 100, 'setup');
    $billable->recordBillingUsage('Tokens', 137);

    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(-37);

    // The renewal lands first — with SEPA that is the normal order, since Mollie
    // creates the renewal about two days ahead while the overage charge is created the
    // day before it.
    $wallets->resetAndCredit($billable, 'Tokens', 100, 'subscription_renewal', preserveDebt: true);

    // Quota minus the debt still owed. Forgiving it here and then crediting the charged
    // quantity when the overage settles handed out 37 free units every cycle.
    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(63);

    // Now the overage settles.
    $wallets->credit($billable, 'Tokens', 37, 'overage_settlement');

    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(100);
});

it('still forgives the balance on an ordinary renewal with no charge in flight', function (): void {
    $billable = jobMoneyBillable();

    $wallets = app(WalletUsageService::class);
    $wallets->credit($billable, 'Tokens', 100, 'setup');
    $billable->recordBillingUsage('Tokens', 137);

    // No pending charge: the debt is not owed to anyone, so the period reset clears it
    // as it always did.
    $wallets->resetAndCredit($billable, 'Tokens', 100, 'subscription_renewal');

    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(100);
});

it('reads the pending-charge marker to decide, per usage type', function (): void {
    $handler = new class extends GraystackIT\MollieBilling\Services\Webhook\SubscriptionPaymentHandler
    {
        public function __construct() {}

        public function probe(GraystackIT\MollieBilling\Contracts\Billable $b, string $type): bool
        {
            return $this->hasUnsettledOverageFor($b, $type);
        }
    };

    $pending = jobMoneyBillable([], [
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'quantity' => 5]],
        ],
    ]);

    expect($handler->probe($pending, 'Tokens'))->toBeTrue()
        // A type the charge did not cover keeps its ordinary reset.
        ->and($handler->probe($pending, 'Sms'))->toBeFalse();

    // A cycle that gave up is not "in flight": that debt was written off with the
    // past-due transition, and preserving it would strand the wallet negative forever.
    $failed = jobMoneyBillable([], [
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_FAILED,
        ChargeUsageOverageDirectly::META_KEY => [
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'quantity' => 5]],
        ],
    ]);

    expect($handler->probe($failed, 'Tokens'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7b — a superseded patch retry must not overwrite the current plan
// ─────────────────────────────────────────────────────────────────────────────

it('skips a patch retry whose intent the billable has moved past', function (): void {
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    // Local state says pro; the queued retry still carries the intent for starter.
    $billable = jobMoneyBillable(['subscription_plan_code' => 'pro']);

    $intentData = [
        'billable_type' => TestBillable::class,
        'billable_id' => $billable->getKey(),
        'current_plan' => 'pro',
        'new_plan' => 'starter',
        'current_interval' => 'monthly',
        'new_interval' => 'monthly',
        'current_seats' => 1,
        'new_seats' => 1,
        'current_addons' => [],
        'new_addons' => [],
        'force_reset_start_date' => false,
    ];

    // The marker as the failing PATCH wrote it — the same intent, so clearMarker()'s
    // signature guard recognises it as this chain's own.
    $billable->forceFill(['subscription_meta' => array_merge(
        $billable->getBillingSubscriptionMeta(),
        ['pending_subscription_patch' => ['intent' => $intentData, 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()]],
    )])->save();

    (new RetrySubscriptionPatchJob($intentData, BillingTime::nowUtc()->toIso8601String()))
        ->handle(app(MollieSubscriptionPatcher::class));

    // Mollie must not be PATCHed back to the superseded plan. It priced from the stored
    // intent, then cleared its own marker because the signature matched — leaving local
    // plan pro, Mollie collecting starter's price every period, and nothing for the
    // hourly sweep to find.
    expect(SpyMollieSubscriptionPatcher::$calls)->toBeEmpty()
        ->and($billable->refresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey('pending_subscription_patch');
});

it('still applies a patch retry that matches the current state', function (): void {
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    $billable = jobMoneyBillable(['subscription_plan_code' => 'pro']);

    $intentData = [
        'billable_type' => TestBillable::class,
        'billable_id' => $billable->getKey(),
        'current_plan' => 'starter',
        'new_plan' => 'pro',
        'current_interval' => 'monthly',
        'new_interval' => 'monthly',
        'current_seats' => 1,
        'new_seats' => 1,
        'current_addons' => [],
        'new_addons' => [],
        'force_reset_start_date' => false,
    ];

    (new RetrySubscriptionPatchJob($intentData, BillingTime::nowUtc()->toIso8601String()))
        ->handle(app(MollieSubscriptionPatcher::class));

    expect(SpyMollieSubscriptionPatcher::$calls)->not->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7c — a spent dunning cycle must not bill the next one's first failure
// ─────────────────────────────────────────────────────────────────────────────

it('starts a fresh dunning cycle after a give-up instead of inheriting the spent one', function (): void {
    $billable = jobMoneyBillable([], [
        'mollie_subscription_id' => 'sub_job',
        // What giveUp() leaves behind: the snapshot is kept for auditability.
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_FAILED,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_old_p3',
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'quantity' => 10, 'unit_price_net' => 10, 'total_net' => 100]],
            'created_at' => BillingTime::nowUtc()->subDays(35)->toIso8601String(),
        ],
        ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY => [
            'cycle' => BillingTime::nowUtc()->subDays(35)->toIso8601String(),
            'payment_ids' => ['tr_old_p1', 'tr_old_p2', 'tr_old_p3'],
        ],
    ]);

    app(WalletUsageService::class)->credit($billable, 'Tokens', 100, 'setup');
    $billable->recordBillingUsage('Tokens', 110);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_new_p1']);

    app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    // A new cycle needs a new identity. Inheriting `created_at` kept the cycle key
    // identical, so the old three payment ids still counted — and the new period's
    // FIRST failure exceeded the budget: past_due plus customer and admin dunning mail
    // after a single attempt, with no retry ladder at all.
    expect($meta[ChargeUsageOverageDirectly::META_KEY]['created_at'])
        ->not->toBe(BillingTime::nowUtc()->subDays(35)->toIso8601String())
        ->and($meta)->not->toHaveKey(ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY)
        ->and($meta['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('keeps accumulating within one cycle', function (): void {
    $billable = jobMoneyBillable([], [
        'mollie_subscription_id' => 'sub_job',
        // Still pending — p1 failed, p2 is being created. This inheritance is the
        // point: p1 → p2 → p3 must share one budget.
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_p1',
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'quantity' => 10, 'unit_price_net' => 10, 'total_net' => 100]],
            'created_at' => BillingTime::nowUtc()->subHours(2)->toIso8601String(),
        ],
        ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY => [
            'cycle' => BillingTime::nowUtc()->subHours(2)->toIso8601String(),
            'payment_ids' => ['tr_p1'],
        ],
    ]);

    app(WalletUsageService::class)->credit($billable, 'Tokens', 100, 'setup');
    $billable->recordBillingUsage('Tokens', 110);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_p2']);

    app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta[ChargeUsageOverageDirectly::META_KEY]['created_at'])
        ->toBe(BillingTime::nowUtc()->subHours(2)->toIso8601String())
        ->and($meta[ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY]['payment_ids'])->toBe(['tr_p1']);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7d — the past-due auto-cancel must cancel at Mollie too
// ─────────────────────────────────────────────────────────────────────────────

it('cancels the Mollie subscription when auto-cancelling a past-due billable', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $billable = jobMoneyBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_interval' => SubscriptionInterval::Yearly,
    ], [
        'mollie_subscription_id' => 'sub_job',
        'past_due_since' => BillingTime::nowUtc()->subDays(40)->toIso8601String(),
        'scheduled_change' => ['new_plan' => 'pro'],
    ]);
    $billable->forceFill(['scheduled_change_at' => BillingTime::nowUtc()->addDays(5)])->save();

    $cancelled = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$cancelled) {
        $cancelled[] = $request::class;

        return (object) ['id' => 'sub_job', 'status' => 'canceled'];
    });

    app(PrepareUsageOverageJob::class)->handle(
        app(ChargeUsageOverageDirectly::class),
        app(WalletUsageService::class),
    );

    $billable->refresh();

    // The Mollie subscription was left running before. For a yearly subscriber that
    // meant the full yearly price collected at the anniversary, booked against a
    // billable with no access — the local row said Cancelled, then Expired.
    expect($cancelled)->not->toBeEmpty()
        ->and($cancelled[0])->toBe(\Mollie\Api\Http\Requests\CancelSubscriptionRequest::class)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        // CancelSubscription's hygiene comes along: the scheduled change is dropped.
        ->and($billable->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
});

// ─────────────────────────────────────────────────────────────────────────────
// 7f — the one job that creates payments needs a timeout and an overlap guard
// ─────────────────────────────────────────────────────────────────────────────

it('bounds the overage retry job and serialises it per billable', function (): void {
    $job = new RetryUsageOverageChargeJob(TestBillable::class, 42);

    // Without a timeout, a hung CreatePaymentRequest outlived the queue's visibility
    // window and a second worker created a second recurring payment — both collected,
    // both booked an invoice, and the settlement credited the quantity twice.
    expect($job->timeout)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeLessThan(300);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

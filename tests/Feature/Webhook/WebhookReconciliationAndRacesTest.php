<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Three ways the webhook's bookkeeping disagreed with reality: an invoice priced at a
 * VAT rate the money was never charged at, a meta write that reverted whatever committed
 * beside it, and side effects that ran at most once because the invoice guard swallows
 * the retry.
 */
class RaceFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    RaceFakeWebhookController::$payments = [];
    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieWebhookController::class, RaceFakeWebhookController::class);
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 2000,
            'seat_price_net' => 500,
            'included_usages' => ['Tokens' => 100],
            'usage_overage_prices' => ['Tokens' => 10],
        ]],
    ]);
});

function raceBillable(array $overrides = [], array $meta = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Race Co',
        'email' => uniqid('race-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_race',
        'mollie_mandate_id' => 'mdt_race',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(29),
        'subscription_meta' => array_merge(['mollie_subscription_id' => 'sub_race', 'seat_count' => 1], $meta),
    ], $overrides))->save();

    return $b->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 9a — an overage invoice must match the money that cleared
// ─────────────────────────────────────────────────────────────────────────────

it('books an overage invoice against the amount actually collected', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    // The charge went out at DE 19 %: 10.00 net → 11.90 gross, with the rate snapshotted.
    $billable = raceBillable([], [
        'mollie_subscription_id' => 'sub_race',
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_ov',
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 100, 'unit_price_net' => 10, 'total_net' => 1000]],
            'amount_net' => 1000,
            'amount_gross' => 1190,
            'vat_rate' => 19.0,
            'created_at' => BillingTime::nowUtc()->subDays(2)->toIso8601String(),
        ],
    ]);

    // Meanwhile the customer validated a VAT number, so the live classification is now
    // reverse charge — days later, because SEPA.
    $billable->forceFill(['vat_number' => 'ATU69934418', 'billing_country' => 'AT'])->save();

    RaceFakeWebhookController::$payments['tr_ov'] = (object) [
        'id' => 'tr_ov',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 100, 'unit_price_net' => 10, 'total_net' => 1000]],
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_ov'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_ov')->sole();

    // 11.90 was collected, so 11.90 has to be on the document. Booking the recomputed
    // 10.00/0/10.00 left 1.90 on no invoice at all — under-declared output VAT and
    // `refunded_net` headroom short of the cash.
    expect((int) $invoice->amount_gross)->toBe(1190);
});

// ─────────────────────────────────────────────────────────────────────────────
// 9b — concurrent meta writes must not revert each other
// ─────────────────────────────────────────────────────────────────────────────

it('re-reads the meta under a lock instead of writing a caller snapshot back', function (): void {
    // What `mutateBillingMeta` guarantees, tested directly. The renewal handler used to
    // read the meta off its own model instance and write the whole blob back at the end,
    // so anything committed in between was reverted.
    //
    // Note on scope: that specific race is NOT reachable in-process today, because
    // `WalletUsageService` re-reads the billable under a lock during the wallet work that
    // runs in the middle of the handler — so the snapshot happens to be fresh by the time
    // it is written. The fix is therefore structural: it stops depending on an unrelated
    // service's refresh, and it takes the lock itself so a genuinely concurrent process
    // cannot interleave between the read and the write.
    $billable = raceBillable();

    $handler = new class
    {
        use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;

        public function run($billable, callable $mutator, array $attrs = []): void
        {
            $this->mutateBillingMeta($billable, $mutator, $attrs);
        }
    };

    // A stale caller instance, exactly like a handler that read the row minutes ago.
    $stale = TestBillable::query()->find($billable->getKey());

    // Something else commits.
    $other = TestBillable::query()->find($billable->getKey());
    $otherMeta = $other->getBillingSubscriptionMeta();
    $otherMeta['written_elsewhere'] = 'must survive';
    $other->forceFill(['subscription_meta' => $otherMeta])->save();

    // The stale instance now mutates only the key it owns.
    $handler->run($stale, static function (array $meta): array {
        $meta['seat_count'] = 7;

        return $meta;
    });

    $after = $billable->refresh()->getBillingSubscriptionMeta();

    expect($after['seat_count'])->toBe(7)
        ->and($after)->toHaveKey('written_elsewhere')
        ->and($after['written_elsewhere'])->toBe('must survive')
        ->and($after['mollie_subscription_id'])->toBe('sub_race');
});

it('leaves the caller instance clean so a later save cannot resurrect its snapshot', function (): void {
    $billable = raceBillable();

    $handler = new class
    {
        use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;

        public function run($billable, callable $mutator): void
        {
            $this->mutateBillingMeta($billable, $mutator);
        }
    };

    $handler->run($billable, static function (array $meta): array {
        $meta['seat_count'] = 9;

        return $meta;
    });

    // Another commit lands after the mutation.
    $other = TestBillable::query()->find($billable->getKey());
    $meta = $other->getBillingSubscriptionMeta();
    $meta['later'] = 'yes';
    $other->forceFill(['subscription_meta' => $meta])->save();

    // A save() on the caller's instance — handlers do this for unrelated columns — must
    // not carry its own meta snapshot back over the row. That is what syncOriginal() in
    // the trait is for.
    $billable->forceFill(['tax_country_payment' => 'DE'])->save();

    $after = TestBillable::query()->find($billable->getKey())->getBillingSubscriptionMeta();

    expect($after)->toHaveKey('later')
        ->and($after['seat_count'])->toBe(9);
});

it('leaves the overage markers cleared when a renewal runs in the same second', function (): void {
    $billable = raceBillable([], [
        'mollie_subscription_id' => 'sub_race',
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_ov2',
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 10, 'unit_price_net' => 10, 'total_net' => 100]],
            'vat_rate' => 19.0,
            'created_at' => BillingTime::nowUtc()->toIso8601String(),
        ],
    ]);

    app(WalletUsageService::class)->credit($billable, 'Tokens', 100, 'setup');

    // The overage settles first and clears its markers.
    RaceFakeWebhookController::$payments['tr_ov2'] = (object) [
        'id' => 'tr_ov2',
        'status' => 'paid',
        'amount' => (object) ['value' => '1.19', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 10, 'unit_price_net' => 10, 'total_net' => 100]],
        ],
    ];
    $this->postJson(route('billing.webhook'), ['id' => 'tr_ov2'])->assertStatus(200);

    expect($billable->refresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey(ChargeUsageOverageDirectly::META_KEY);

    // Then the renewal — which reads and writes the same column. It must not bring the
    // cleared keys back: PrepareUsageOverageJob would then treat the paid charge as still
    // in flight and skip this billable's overage collection indefinitely.
    RaceFakeWebhookController::$payments['tr_renew2'] = (object) [
        'id' => 'tr_renew2',
        'status' => 'paid',
        'amount' => (object) ['value' => '23.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_race',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
    $this->postJson(route('billing.webhook'), ['id' => 'tr_renew2'])->assertStatus(200);

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey(ChargeUsageOverageDirectly::META_KEY)
        ->and($meta)->not->toHaveKey('usage_overage_status');
});

// ─────────────────────────────────────────────────────────────────────────────
// 9c — a throwing listener must not lock a paying customer out
// ─────────────────────────────────────────────────────────────────────────────

it('converts the trial before firing TrialConverted, so a throwing listener cannot strand it', function (): void {
    $billable = raceBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->subMinute(),
    ]);

    // Events are the documented extension seam, so an app listener with a dead
    // dependency is an ordinary failure mode.
    Event::listen(TrialConverted::class, function (): void {
        throw new RuntimeException('listener down');
    });

    RaceFakeWebhookController::$payments['tr_conv'] = (object) [
        'id' => 'tr_conv',
        'status' => 'paid',
        'amount' => (object) ['value' => '23.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_race',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    // The listener still breaks the delivery — that is Laravel's contract — but the
    // conversion is already committed.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_conv']);

    $billable->refresh();

    // Fired before the save, this left status Trial with a past `trial_ends_at`, which
    // `hasAccessibleBillingSubscription()` refuses — a customer who had just paid was
    // locked out for a whole period, and the re-delivery could not repair it because the
    // invoice guard short-circuits.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue();
});

it('wraps the post-invoice wallet work that a re-delivery would never re-run', function (): void {
    // Guards the shape rather than the runtime: these calls sit after the invoice
    // exists, so an uncaught throw is unrecoverable — the re-delivery exits at the
    // invoice guard. A future edit that unwraps one reintroduces a silent money loss.
    $oneTime = file_get_contents(__DIR__.'/../../../src/Services/Webhook/OneTimeOrderHandler.php');
    $upgrade = file_get_contents(__DIR__.'/../../../src/Services/Webhook/LocalToMollieUpgradeHandler.php');
    $single = file_get_contents(__DIR__.'/../../../src/Services/Webhook/SingleChargeHandler.php');

    foreach ([
        'OneTimeOrderHandler' => [$oneTime, 'walletService->credit('],
        'LocalToMollieUpgradeHandler' => [$upgrade, 'walletAdjuster->adjust('],
        'SingleChargeHandler' => [$single, 'settleOverageWallets('],
    ] as $name => [$source, $needle]) {
        $at = strpos($source, $needle);
        expect($at)->toBeInt();

        // A `try {` within the preceding few lines.
        $before = substr($source, max(0, $at - 400), min(400, $at));
        expect(str_contains($before, 'try {'))->toBeTrue("{$name}: {$needle} is not wrapped");
    }
});

it('books the collected amount even when the classification flipped to zero-rated', function (): void {
    // The charge went out at DE 19 %, then the customer's supply became zero-rated
    // before the SEPA payment settled. createForPayment() forces every line to 0 in that
    // case — deliberately, a webhook must not re-introduce tax the classification ruled
    // out — so pinning the rate cannot help and the reconciliation has to close the gap.
    $billable = raceBillable([], [
        'mollie_subscription_id' => 'sub_race',
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_ov_rc',
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 100, 'unit_price_net' => 10, 'total_net' => 1000]],
            'vat_rate' => 19.0,
            'created_at' => BillingTime::nowUtc()->subDays(2)->toIso8601String(),
        ],
    ]);

    // Reverse charge, properly: a persisted VIES-accepted validation is what makes the
    // supply zero-rated — setting `vat_number` alone would not.
    $billable->forceFill(['billing_country' => 'AT', 'vat_number' => 'ATU12345678'])->save();

    $validation = new GraystackIT\MollieBilling\Models\BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'ATU12345678';
    $validation->country_code = 'AT';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();

    $billable->refresh();

    RaceFakeWebhookController::$payments['tr_ov_rc'] = (object) [
        'id' => 'tr_ov_rc',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens',
                'quantity' => 100, 'unit_price_net' => 10, 'total_net' => 1000]],
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_ov_rc'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_ov_rc')->sole();

    // 11.90 cleared, so 11.90 is on the document — as net, since no VAT applies. Without
    // the reconciliation 1.90 sat on no invoice and `refunded_net` headroom was short of
    // the cash, so a full refund would have been rejected.
    expect((int) $invoice->amount_gross)->toBe(1190)
        ->and(collect($invoice->line_items)->firstWhere('kind', 'adjustment'))->not->toBeNull();
});

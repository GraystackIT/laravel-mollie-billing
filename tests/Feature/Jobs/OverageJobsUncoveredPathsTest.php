<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\PruneProcessedWebhooksJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Paths through the money jobs that no test walked. Each of these either moves money, or
 * decides whether money that moved is recognised.
 */
class UncoveredPathsWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 1000, 'seat_price_net' => 500,
            'included_usages' => ['Tokens' => 100],
            'usage_overage_prices' => ['Tokens' => 10],
        ]],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A billable with an overage snapshot. `$snapshot` overrides the defaults.
 *
 * @param  array<string, mixed>  $snapshot
 */
function uncoveredPathsBillable(array $snapshot = [], ?string $status = ChargeUsageOverageDirectly::STATUS_PENDING): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Uncovered Co',
        'email' => uniqid('uncovered-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $meta = ['mollie_subscription_id' => 'sub_unc', 'seat_count' => 1];

    if ($status !== null) {
        $meta['usage_overage_status'] = $status;
        $meta[ChargeUsageOverageDirectly::META_KEY] = array_merge([
            'payment_id' => 'tr_cycle_3',
            'line_items' => [['kind' => 'overage', 'code' => 'Tokens', 'type' => 'Tokens', 'quantity' => 37, 'unit_price_net' => 10, 'total_net' => 370]],
            'amount_net' => 370,
            'amount_gross' => 440,
            'vat_amount' => 70,
            'vat_rate' => 19.0,
            'currency' => 'EUR',
            'created_at' => BillingTime::nowUtc()->subDays(15)->toIso8601String(),
        ], $snapshot);
    }

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'mollie_customer_id' => 'cst_unc',
        'mollie_mandate_id' => 'mdt_unc',
        'subscription_meta' => $meta,
    ])->save();

    return $billable->refresh();
}

// ── CleanupStalePendingOverageChargeJob ─────────────────────────────────────

it('does not write off a retry payment because the dunning cycle around it is old', function (): void {
    // The snapshot's `created_at` is the CYCLE's start — it is inherited from payment to
    // payment so the dunning budget stays attached. With SEPA each failure takes days to
    // arrive: p1 day 0, fails day 6; p2 fails day 12; p3 is created on day 13. On day 15
    // the cycle is past the 14-day hard limit, and a payment that is two days old was
    // written off as "pending for over 14 days". Status `failed` frees the slot, the next
    // sweep charges the same units again, and then p3 settles after all: paid twice.
    $billable = uncoveredPathsBillable();

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'tr_cycle_3',
        'status' => 'pending',
        'createdAt' => BillingTime::nowUtc()->subDays(2)->toIso8601String(),
    ]);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($meta[ChargeUsageOverageDirectly::META_KEY]['payment_id'])->toBe('tr_cycle_3');
});

it('still ends the cycle of a payment that has itself been open past the hard limit', function (): void {
    $billable = uncoveredPathsBillable();

    // The status poll, then the cancellation that makes it safe to free the slot.
    Mollie::shouldReceive('send')->twice()->andReturn((object) [
        'id' => 'tr_cycle_3',
        'status' => 'open',
        'createdAt' => BillingTime::nowUtc()->subDays(15)->toIso8601String(),
    ]);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($billable->refresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);
});

it('does not adopt somebody else\'s payment for a charge whose answer was lost', function (string $case): void {
    $billable = uncoveredPathsBillable([
        'payment_id' => null,
        ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY => BillingTime::nowUtc()->subHours(3)->toIso8601String(),
        'created_at' => BillingTime::nowUtc()->subHours(3)->toIso8601String(),
    ]);

    $candidate = match ($case) {
        // Last month's overage happened to cost the same.
        'an earlier cycle of the same amount' => ['tr_old', '4.40', 'overage', (string) $billable->getKey(), BillingTime::nowUtc()->subDays(30)],
        'a different amount' => ['tr_other_amount', '9.90', 'overage', (string) $billable->getKey(), BillingTime::nowUtc()->subHours(3)],
        'another billable on the same customer' => ['tr_other_billable', '4.40', 'overage', '999999', BillingTime::nowUtc()->subHours(3)],
        'a payment that is not an overage charge' => ['tr_renewal', '4.40', null, (string) $billable->getKey(), BillingTime::nowUtc()->subHours(3)],
    };

    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use ($candidate): array {
        expect($request)->toBeInstanceOf(GetPaginatedCustomerPaymentsRequest::class);

        return [(object) [
            'id' => $candidate[0],
            'status' => 'paid',
            'amount' => (object) ['value' => $candidate[1], 'currency' => 'EUR'],
            'createdAt' => $candidate[4]->toIso8601String(),
            'metadata' => (object) array_filter(['type' => $candidate[2], 'billable_id' => $candidate[3]]),
        ]];
    });

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY];

    // Not adopted — and the marker is gone, so the debt is an ordinary uncharged one.
    expect($snapshot['payment_id'] ?? null)->toBeNull()
        ->and($snapshot)->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY);
})->with([
    'an earlier cycle of the same amount',
    'a different amount',
    'another billable on the same customer',
    'a payment that is not an overage charge',
]);

it('drops the unknown-payment marker when there is no Mollie customer left to ask about', function (): void {
    $billable = uncoveredPathsBillable([
        'payment_id' => null,
        ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY => BillingTime::nowUtc()->subHours(3)->toIso8601String(),
        'created_at' => BillingTime::nowUtc()->subHours(3)->toIso8601String(),
    ]);
    $billable->forceFill(['mollie_customer_id' => null])->save();

    Mollie::shouldReceive('send')->never();

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect(app(ChargeUsageOverageDirectly::class)->paymentMayExist($billable->refresh()))->toBeFalse();
});

// ── RetryUsageOverageChargeJob ──────────────────────────────────────────────

it('does not charge again when the payment it would retry was invoiced after all', function (): void {
    // The failure webhook and a late `paid` for the same payment can cross. The invoice is
    // the fact; the retry must find it and stand down, clearing the markers it leaves.
    $billable = uncoveredPathsBillable(['payment_id' => 'tr_paid_after_all']);

    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-UNC-1',
        'mollie_payment_id' => 'tr_paid_after_all',
        'invoice_kind' => InvoiceKind::Overage->value,
        'status' => InvoiceStatus::Paid->value,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 370,
        'amount_vat' => 70,
        'amount_gross' => 440,
        'line_items' => [],
    ])->save();

    Mollie::shouldReceive('send')->never();

    (new RetryUsageOverageChargeJob(TestBillable::class, $billable->getKey()))
        ->handle(app(ChargeUsageOverageDirectly::class));

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('usage_overage')
        ->and($meta)->not->toHaveKey('usage_overage_status')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('clears a pending marker that has nothing behind it', function (): void {
    // `safeCharge()` sets the status to pending when a charge throws before it could write
    // a snapshot. If the wallets owe nothing by the time the retry runs, the marker has to
    // go — left in place it silences every later overage for this billable.
    $billable = uncoveredPathsBillable([], null);
    $billable->forceFill(['subscription_meta' => array_merge(
        $billable->getBillingSubscriptionMeta(),
        ['usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING],
    )])->save();

    Mollie::shouldReceive('send')->never();

    (new RetryUsageOverageChargeJob(TestBillable::class, $billable->getKey()))
        ->handle(app(ChargeUsageOverageDirectly::class));

    expect($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage_status');
});

it('does nothing for a billable that no longer exists', function (): void {
    Mollie::shouldReceive('send')->never();

    (new RetryUsageOverageChargeJob(TestBillable::class, 987654))->handle(app(ChargeUsageOverageDirectly::class));
    (new RetryUsageOverageChargeJob('App\\Models\\Gone', 1))->handle(app(ChargeUsageOverageDirectly::class));
    (new RetryUsageOverageChargeJob(TestBillable::class, 987654))->failed(new RuntimeException('gone'));

    Notification::assertNothingSent();
});

// ── SyncSeatsJob ────────────────────────────────────────────────────────────

/** A SyncSeats double that records the target and writes it, as the real one would. */
function uncoveredPathsSeatDouble(array &$targets): SyncSeats
{
    $double = Mockery::mock(SyncSeats::class);
    $double->shouldReceive('handle')->andReturnUsing(function (TestBillable $billable, int $target) use (&$targets): void {
        $targets[] = $target;
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = $target;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    });

    return $double;
}

it('adds two seats for two delta jobs, and removes them again one by one', function (): void {
    $billable = uncoveredPathsBillable([], null);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['seat_count'] = 5;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $targets = [];
    $double = uncoveredPathsSeatDouble($targets);

    foreach ([1, 1, -1, -1, -1] as $delta) {
        SyncSeatsJob::byDelta(TestBillable::class, $billable->getKey(), $delta)->handle($double);
    }

    // Each job resolves its target from the row the one before it wrote.
    expect($targets)->toBe([6, 7, 6, 5, 4]);
});

it('never resolves a seat delta below zero, and keeps identical deltas apart', function (): void {
    $billable = uncoveredPathsBillable([], null);

    $targets = [];
    SyncSeatsJob::byDelta(TestBillable::class, $billable->getKey(), -5)->handle(uncoveredPathsSeatDouble($targets));

    expect($targets)->toBe([0]);

    // Two "+1"s are two seats: their unique ids differ. Two identical absolute targets are
    // one change: theirs do not.
    $first = SyncSeatsJob::byDelta(TestBillable::class, 7, 1);
    $second = SyncSeatsJob::byDelta(TestBillable::class, 7, 1);

    expect($first->uniqueId())->not->toBe($second->uniqueId())
        ->and((new SyncSeatsJob(TestBillable::class, 7, 3))->uniqueId())
        ->toBe((new SyncSeatsJob(TestBillable::class, 7, 3))->uniqueId());
});

it('passes an absolute seat target through untouched and ignores a missing billable', function (): void {
    $billable = uncoveredPathsBillable([], null);

    $targets = [];
    $double = uncoveredPathsSeatDouble($targets);

    (new SyncSeatsJob(TestBillable::class, $billable->getKey(), 9))->handle($double);
    (new SyncSeatsJob(TestBillable::class, 987654, 9))->handle($double);

    expect($targets)->toBe([9]);
});

// ── PruneProcessedWebhooksJob ───────────────────────────────────────────────

it('prunes webhook records older than 180 days and nothing younger', function (): void {
    foreach ([['tr_old', 181], ['tr_edge', 179], ['tr_new', 1]] as [$id, $daysAgo]) {
        BillingProcessedWebhook::query()->create([
            'mollie_payment_id' => $id,
            'event_signature' => BillingProcessedWebhook::outcomeSignature($id, 'paid'),
            'received_at' => BillingTime::nowUtc()->subDays($daysAgo),
            'processed_at' => BillingTime::nowUtc()->subDays($daysAgo),
        ]);
    }

    (new PruneProcessedWebhooksJob)->handle();

    expect(BillingProcessedWebhook::query()->pluck('mollie_payment_id')->sort()->values()->all())
        ->toBe(['tr_edge', 'tr_new']);
});

it('does not book a renewal a second time when it is re-delivered after its record was pruned', function (): void {
    // The dedup table is the first layer; pruning removes it for anything older than half
    // a year. What stops an old payment from being booked again then is the invoice guard
    // — and the renewal handler's refusal to move the period backwards.
    config()->set('wallet.cache.ttl', 20 * 365 * 24 * 3600);
    $this->app->bind(MollieWebhookController::class, UncoveredPathsWebhookController::class);
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'obj_unc', 'status' => 'active']);

    Carbon::setTestNow('2026-02-01T09:05:00Z');

    $billable = uncoveredPathsBillable([], null);
    $billable->forceFill(['subscription_period_starts_at' => Carbon::parse('2026-01-01T09:00:00Z')])->save();

    UncoveredPathsWebhookController::$nextPayment = (object) [
        'id' => 'tr_pruned_renewal',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => '2026-02-01T09:00:00Z',
        'subscriptionId' => 'sub_unc',
        'countryCode' => 'DE',
        'metadata' => ['billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey()],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_pruned_renewal'])->assertStatus(200);

    expect(BillingProcessedWebhook::query()->count())->toBe(1);

    // Seven months on: the record is pruned, six more periods have been booked…
    Carbon::setTestNow('2026-09-01T03:00:00Z');
    $billable->refresh()->forceFill(['subscription_period_starts_at' => Carbon::parse('2026-08-01T09:00:00Z')])->save();
    (new PruneProcessedWebhooksJob)->handle();

    expect(BillingProcessedWebhook::query()->count())->toBe(0);

    // …and Mollie (or an operator's replay) presents the February payment again.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_pruned_renewal'])->assertStatus(200);

    $billable->refresh();
    $wallet = $billable->getWallet('Tokens');
    $credits = DB::table('transactions')->where('wallet_id', $wallet->getKey())->where('meta', 'like', '%period_credit%')->count();

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_pruned_renewal')->count())->toBe(1)
        ->and($credits)->toBe(1)
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-08-01T09:00:00+00:00');
});

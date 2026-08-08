<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Regressions for the job-layer defects an audit pass turned up.
 *
 * Each of these is a mechanism the unattended paths depend on and which no test entered:
 * a background job that has to fail loudly rather than silently, a sweep that must survive
 * one unusable row, an anchor that has to agree with the date Mollie will charge on.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
});

function jobAuditBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Audit Ltd',
        'email' => uniqid('audit-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'mollie_customer_id' => 'cst_audit',
        'mollie_mandate_id' => 'mdt_audit',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_audit', 'seat_count' => 1],
    ], $overrides))->save();

    return $billable->refresh();
}

it('subtracts one interval with the same clamping it adds one', function (): void {
    // Month arithmetic is not invertible with plain Carbon, and the anchor is derived
    // backwards from a known charge date. Every one of these used to read forward to a date
    // one to three days past what Mollie would charge on.
    foreach ([
        ['2026-03-29', '2026-02-28'],
        ['2026-03-30', '2026-02-28'],
        ['2026-03-31', '2026-02-28'],
        ['2026-05-31', '2026-04-30'],
        ['2026-01-31', '2025-12-31'],
    ] as [$anchor, $expected]) {
        expect(BillingTime::subInterval(BillingTime::toUtc($anchor), 'monthly')->toDateString())
            ->toBe($expected, "one month before {$anchor}");
    }

    // And the round trip lands back on the anchor rather than past it.
    foreach (['2026-03-29', '2026-03-30', '2026-03-31', '2026-05-31'] as $anchor) {
        $back = BillingTime::subInterval(BillingTime::toUtc($anchor), 'monthly');

        expect(BillingTime::addInterval($back, 'monthly')->toDateString())
            ->toBeLessThanOrEqual($anchor, "round trip from {$anchor} overshoots");
    }

    expect(BillingTime::subInterval(BillingTime::toUtc('2024-02-29'), 'yearly')->toDateString())
        ->toBe('2023-02-28');
});

it('keeps sweeping after a billable whose markers cannot be read', function (): void {
    // chunkById walks ascending ids, and this sweep is the only reader for
    // pending_subscription_cancel, the coupon-expiry PATCH and every refund-retry line. One
    // marker holding an array where a string belongs used to abort it for every higher id.
    $broken = jobAuditBillable([
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_broken',
            'pending_subscription_cancel' => [
                'mollie_customer_id' => ['cst_broken'],   // an array — "Array to string conversion"
                'mollie_subscription_id' => 'sub_broken',
                'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ],
    ]);

    $good = jobAuditBillable([
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_good',
            'pending_subscription_cancel' => [
                'mollie_customer_id' => 'cst_good',
                'mollie_subscription_id' => 'sub_good',
                'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ],
    ]);

    expect($broken->getKey())->toBeLessThan($good->getKey());

    \Illuminate\Support\Facades\Queue::fake();

    app()->call([new SweepPendingRetriesJob, 'handle']);

    // The good row's retry was dispatched even though the row before it was unusable.
    \Illuminate\Support\Facades\Queue::assertPushed(
        \GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob::class,
    );
});

it('writes off an overage charge that never got a payment id', function (): void {
    // Pending with no payment id means CreatePaymentRequest itself kept failing — a dead
    // mandate. That had no terminal path: no dunning, no write-off, and safeCharge() skipped
    // every later period because the status was still pending. Silence, forever.
    $billable = jobAuditBillable([
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_audit',
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            ChargeUsageOverageDirectly::META_KEY => [
                'payment_id' => '',
                'amount_net' => 370,
                'created_at' => BillingTime::nowUtc()->subDays(CleanupStalePendingOverageChargeJob::HARD_LIMIT_DAYS + 1)->toIso8601String(),
            ],
        ],
    ]);

    app()->call([new CleanupStalePendingOverageChargeJob, 'handle']);

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);
});

it('leaves a fresh payment-less overage snapshot alone', function (): void {
    // The write-off is gated on the hard limit, not on "no payment id" — a snapshot written
    // minutes ago belongs to a charge that may still be starting.
    $billable = jobAuditBillable([
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_audit',
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            ChargeUsageOverageDirectly::META_KEY => [
                'payment_id' => '',
                'amount_net' => 370,
                'created_at' => BillingTime::nowUtc()->subDays(1)->toIso8601String(),
            ],
        ],
    ]);

    app()->call([new CleanupStalePendingOverageChargeJob, 'handle']);

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('does not hold a transaction across the seat sync payment', function (): void {
    // CLAUDE.md's rule is general, and this job entered UpdateSubscription through a
    // transaction of its own: a throw after the prorata payment rolled back the seat count
    // and the invoice while the money stood at Mollie, with tries = 1 and no marker.
    $billable = jobAuditBillable();

    \GraystackIT\MollieBilling\Models\BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_audit_seed',
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'AT', 'currency' => 'EUR',
        'amount_net' => 2900, 'amount_vat' => 580, 'amount_gross' => 3480,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(10),
        'period_end' => BillingTime::nowUtc()->addDays(20),
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 20.0,
            'vat_amount' => 580, 'amount_gross' => 3480,
            'period_start' => BillingTime::nowUtc()->subDays(10)->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(20)->toIso8601String(),
        ]],
    ]);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof CreatePaymentRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'tr_audit_seats', 'status' => 'open', 'subscriptionId' => 'sub_audit'];
    });

    $baseline = DB::transactionLevel();

    app()->call([new SyncSeatsJob($billable::class, $billable->getKey(), 3), 'handle']);

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline]);
});

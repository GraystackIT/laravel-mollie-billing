<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

const RC_LINE_ONE = [
    'kind' => 'addon',
    'code' => 'extra',
    'parent_invoice_id' => 77,
    'parent_line_item_index' => 0,
    'amount_net' => -500,
];

const RC_LINE_TWO = [
    'kind' => 'seats',
    'code' => 'seats',
    'parent_invoice_id' => 77,
    'parent_line_item_index' => 1,
    'amount_net' => -200,
];

function refundConcurrencyBillable(array $meta): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Concurrency '.uniqid(),
        'email' => uniqid('rc-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $meta,
    ])->save();

    return $b->refresh();
}

/** Commit a meta write behind the given instance's back, the way a sibling job would. */
function commitBehindInstanceBack(TestBillable $billable, array $meta): void
{
    DB::table($billable->getTable())
        ->where($billable->getKeyName(), $billable->getKey())
        ->update(['subscription_meta' => json_encode($meta)]);
}

/**
 * @param  array<string, mixed>  $line
 */
function refundJobFor(TestBillable $billable, array $line): RetryRefundLineJob
{
    return new RetryRefundLineJob(
        billableClass: TestBillable::class,
        billableId: $billable->getKey(),
        lineData: $line,
        firstAttemptAt: BillingTime::nowUtc()->subHour()->toIso8601String(),
    );
}

it('does not resurrect a sibling line that another refund job already removed', function (): void {
    $b = refundConcurrencyBillable([
        'pending_refund_retries' => [
            ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
            ['line' => RC_LINE_TWO, 'error' => 'Mollie 422'],
        ],
    ]);

    // The sweep dispatched one job per line. The job for line two wins the race and
    // commits `[line one]`; this job still holds the snapshot it was dispatched with.
    commitBehindInstanceBack($b, ['pending_refund_retries' => [
        ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
    ]]);

    expect($b->getBillingSubscriptionMeta()['pending_refund_retries'])->toHaveCount(2);

    $job = refundJobFor($b, RC_LINE_ONE);
    (fn () => $this->removeFromPendingRetries($b))->call($job);

    // Line two was refunded — writing back this job's stale snapshot would put it back
    // on the marker, the next hourly sweep would dispatch it again, and createRefund()
    // has no per-line guard: the parent invoice still has refundable headroom, so the
    // customer would be paid out twice.
    expect($b->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'])->toBe([]);
});

it('keeps the caller instance in sync with what it committed', function (): void {
    $b = refundConcurrencyBillable([
        'pending_refund_retries' => [
            ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
            ['line' => RC_LINE_TWO, 'error' => 'Mollie 422'],
        ],
    ]);

    $job = refundJobFor($b, RC_LINE_ONE);
    (fn () => $this->removeFromPendingRetries($b))->call($job);

    // createRefund() re-appends its failures through this very instance, so a stale
    // in-memory copy here is the second way the removed line comes back.
    $entries = $b->getBillingSubscriptionMeta()['pending_refund_retries'];
    expect($entries)->toHaveCount(1);
    expect($entries[0]['line']['parent_line_item_index'])->toBe(1);
});

it('dead-letters without dropping a sibling entry committed in the meantime', function (): void {
    $b = refundConcurrencyBillable([
        'pending_refund_retries' => [
            ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
        ],
    ]);

    // A sibling appends a newly failed line while this chain is on its way out.
    commitBehindInstanceBack($b, ['pending_refund_retries' => [
        ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
        ['line' => RC_LINE_TWO, 'error' => 'Mollie 422'],
    ]]);

    refundJobFor($b, RC_LINE_ONE)->failed(new RuntimeException('gave up'));

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($meta['pending_refund_retries_dead_letter'])->toHaveCount(1);
    expect($meta['pending_refund_retries'])->toHaveCount(1);
    expect($meta['pending_refund_retries'][0]['line']['parent_line_item_index'])->toBe(1);
});

it('serialises refund retries per billable so sibling lines cannot interleave', function (): void {
    $one = refundJobFor(refundConcurrencyBillable([]), RC_LINE_ONE);

    $middleware = $one->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);

    // Keyed on the billable, not the line: uniqueId() already covers a second chain for
    // the same line — this is what stops two *different* lines of one billable from
    // interleaving their read-modify-write of subscription_meta, inside
    // InvoiceService::createRefund() as well as here.
    expect($middleware[0]->key)->toContain((string) $one->billableId);
    expect($middleware[0]->releaseAfter)->toBeGreaterThan(0);
});

it('re-reads the row under lock when clearing a cancel marker too', function (): void {
    $b = refundConcurrencyBillable([
        'pending_subscription_cancel' => [
            'mollie_customer_id' => 'cst_x',
            'mollie_subscription_id' => 'sub_x',
        ],
        'pending_refund_retries' => [
            ['line' => RC_LINE_ONE, 'error' => 'Mollie 422'],
        ],
    ]);

    // A refund sibling drains its entry while the cancel chain is finishing.
    commitBehindInstanceBack($b, [
        'pending_subscription_cancel' => [
            'mollie_customer_id' => 'cst_x',
            'mollie_subscription_id' => 'sub_x',
        ],
        'pending_refund_retries' => [],
    ]);

    $job = new RetrySubscriptionCancelJob(
        TestBillable::class,
        $b->getKey(),
        'cst_x',
        'sub_x',
        BillingTime::nowUtc()->subHours(30)->toIso8601String(),
    );

    // Past the hard limit: giveUp() → clearMarker().
    $job->handle();

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('pending_subscription_cancel');
    // The refund entry stays drained — the cancel job must not write its own snapshot
    // of the rest of the column back over it.
    expect($meta['pending_refund_retries'])->toBe([]);
});

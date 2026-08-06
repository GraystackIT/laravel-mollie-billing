<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty('notifyAdminCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function refundRetryBillable(array $pending): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Refund '.uniqid(),
        'email' => uniqid('rr-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => ['pending_refund_retries' => $pending],
    ])->save();

    return $b->refresh();
}

const RR_LINE = [
    'kind' => 'addon',
    'code' => 'extra',
    'parent_invoice_id' => 41,
    'parent_line_item_index' => 0,
    'amount_net' => -500,
];

/**
 * InvoiceService double that reproduces the real contract: a rejected line is
 * re-appended to pending_refund_retries and null is returned — no exception.
 */
function bindRejectingInvoiceService(): void
{
    app()->bind(InvoiceService::class, fn () => new class extends InvoiceService
    {
        public function __construct() {}

        public function createRefund(
            \GraystackIT\MollieBilling\Contracts\Billable $billable,
            array $refundLines,
            ?string $description = null,
        ): ?\GraystackIT\MollieBilling\Models\BillingInvoice {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['pending_refund_retries'] = array_merge(
                (array) ($meta['pending_refund_retries'] ?? []),
                [['line' => RR_LINE, 'error' => 'Mollie 422 rejected the refund']],
            );
            $billable->forceFill(['subscription_meta' => $meta])->save();

            return null;
        }
    });
}

it('throws when createRefund re-appends the line, so the queue backs off', function (): void {
    bindRejectingInvoiceService();

    $b = refundRetryBillable([['line' => RR_LINE, 'error' => 'earlier failure']]);

    $job = new RetryRefundLineJob(
        billableClass: TestBillable::class,
        billableId: $b->getKey(),
        lineData: RR_LINE,
        firstAttemptAt: BillingTime::nowUtc()->subHour()->toIso8601String(),
    );

    expect(fn () => $job->handle(app(InvoiceService::class)))
        ->toThrow(RuntimeException::class);

    // The marker survives with exactly one entry: the stale one was replaced by the
    // fresh failure, not deleted by the success path's signature filter.
    $entries = $b->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'];
    expect($entries)->toHaveCount(1);
    expect($entries[0]['error'])->toBe('Mollie 422 rejected the refund');
});

it('clears the marker when createRefund reports nothing left to refund', function (): void {
    app()->bind(InvoiceService::class, fn () => new class extends InvoiceService
    {
        public function __construct() {}

        public function createRefund(
            \GraystackIT\MollieBilling\Contracts\Billable $billable,
            array $refundLines,
            ?string $description = null,
        ): ?\GraystackIT\MollieBilling\Models\BillingInvoice {
            return null; // coupon-covered / nothing effective — no re-append
        }
    });

    $b = refundRetryBillable([['line' => RR_LINE, 'error' => 'earlier failure']]);

    $job = new RetryRefundLineJob(
        billableClass: TestBillable::class,
        billableId: $b->getKey(),
        lineData: RR_LINE,
        firstAttemptAt: BillingTime::nowUtc()->subHour()->toIso8601String(),
    );

    $job->handle(app(InvoiceService::class));

    expect($b->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'])->toBe([]);
});

it('dead-letters and notifies when the retry budget is exhausted', function (): void {
    Notification::fake();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [new AnonymousNotifiable]);

    $b = refundRetryBillable([['line' => RR_LINE, 'error' => 'earlier failure']]);

    $job = new RetryRefundLineJob(
        billableClass: TestBillable::class,
        billableId: $b->getKey(),
        lineData: RR_LINE,
        firstAttemptAt: BillingTime::nowUtc()->subHours(2)->toIso8601String(),
    );

    // failed() is what the queue calls once `tries` runs out — the ladder exhausts
    // after ~4 days, well before the 7-day check inside handle() can fire.
    $job->failed(new RuntimeException('gave up'));

    $meta = $b->fresh()->getBillingSubscriptionMeta();
    expect($meta['pending_refund_retries_dead_letter'])->toHaveCount(1);
    expect($meta['pending_refund_retries'])->toBe([]);

    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
});

it('is unique per billable and line so a sweep cannot start a second refund chain', function (): void {
    $jobA = new RetryRefundLineJob(TestBillable::class, 7, RR_LINE, BillingTime::nowUtc()->toIso8601String());
    $jobB = new RetryRefundLineJob(TestBillable::class, 7, RR_LINE, BillingTime::nowUtc()->toIso8601String());
    $other = new RetryRefundLineJob(
        TestBillable::class,
        7,
        array_merge(RR_LINE, ['parent_line_item_index' => 1]),
        BillingTime::nowUtc()->toIso8601String(),
    );

    expect($jobA)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
    expect($jobA->uniqueId())->toBe($jobB->uniqueId());
    expect($jobA->uniqueId())->not->toBe($other->uniqueId());
});

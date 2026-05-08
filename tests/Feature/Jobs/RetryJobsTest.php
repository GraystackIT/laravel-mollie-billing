<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function makeJobBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Job Test',
        'email' => 'job@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_job',
        'mollie_mandate_id' => 'mdt_job',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_job'],
    ])->save();

    return $billable->refresh();
}

it('RetrySubscriptionPatchJob notifies admin after 24h hard limit', function (): void {
    Notification::fake();

    config()->set('mollie-billing.notify_admin', ['admin@example.test']);
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [
        new \Illuminate\Notifications\AnonymousNotifiable,
    ]);

    $billable = makeJobBillable();

    $job = new RetrySubscriptionPatchJob(
        intentData: [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'current_plan' => 'starter', 'new_plan' => 'starter',
            'current_interval' => 'monthly', 'new_interval' => 'monthly',
            'current_seats' => 1, 'new_seats' => 1,
            'current_addons' => [], 'new_addons' => [],
        ],
        firstAttemptAt: now()->subHours(25)->toIso8601String(),
    );

    $job->handle(app(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class));

    Notification::assertSentTo(
        new \Illuminate\Notifications\AnonymousNotifiable,
        AdminPlanChangeFailedNotification::class,
    );
});

it('RetryRefundLineJob moves to dead_letter after 7 days', function (): void {
    Notification::fake();

    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [
        new \Illuminate\Notifications\AnonymousNotifiable,
    ]);

    $billable = makeJobBillable();

    $job = new RetryRefundLineJob(
        billableClass: $billable->getMorphClass(),
        billableId: $billable->getKey(),
        lineData: [
            'kind' => 'addon',
            'code' => 'foo',
            'parent_invoice_id' => 1,
            'parent_line_item_index' => 0,
            'amount_net' => -500,
        ],
        firstAttemptAt: now()->subDays(8)->toIso8601String(),
    );

    $job->handle(app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class));

    $billable->refresh();
    $deadLetter = $billable->getBillingSubscriptionMeta()['pending_refund_retries_dead_letter'] ?? [];
    expect($deadLetter)->toHaveCount(1);

    Notification::assertSentTo(
        new \Illuminate\Notifications\AnonymousNotifiable,
        AdminPlanChangeFailedNotification::class,
    );
});

it('CleanupStalePendingProrataChangeJob clears pending state when Mollie says failed', function (): void {
    $billable = makeJobBillable();

    $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), [
        'pending_prorata_change' => [
            'charge_payment_id' => 'tr_stale',
            'created_at' => now()->subDays(2)->toIso8601String(),
            'refund_lines' => [],
            'intent' => [],
        ],
    ])])->save();

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof GetPaymentRequest) {
            $p = new \stdClass;
            $p->status = 'failed';
            return $p;
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    (new CleanupStalePendingProrataChangeJob)->handle();

    $billable->refresh();
    expect($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();
    expect($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->not->toBeNull();
});

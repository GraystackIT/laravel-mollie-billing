<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A cancel the package sent to Mollie drops the 60-second snapshot `MollieSubscriptionGate`
 * keeps of that subscription.
 *
 * The checkout asks the gate whether Mollie still runs a subscription, and sends the customer
 * to the dashboard when it does. A past-due dashboard had just cached "active" (it asks the
 * gate for its "charge now" banner); after an immediate cancel — the operator's, or a cancel at
 * period end that had no access to keep — the customer the subscription gate sent to the
 * checkout was sent straight back to the dashboard for a minute: a circle, with the
 * subscription already gone at Mollie (docs/decisions.md, "A cancel forgets what the gate
 * remembered of the subscription").
 */
beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
});

function cancelSnapshotBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Snapshot Co', 'email' => 'snapshot@x.test', 'billing_country' => 'AT']);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_snap',
        'mollie_mandate_id' => 'mdt_snap',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_snap', 'past_due_since' => BillingTime::nowUtc()->toIso8601String()],
    ])->save();

    return $billable->refresh();
}

/** Mollie holding one subscription whose status a cancel flips; `$cancelFails` refuses the cancel. */
function cancelSnapshotMollie(bool &$cancelFails): void
{
    $status = 'active';

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$status, &$cancelFails) {
        if ($request instanceof GetSubscriptionRequest) {
            return (object) ['id' => 'sub_snap', 'status' => $status, 'startDate' => now('UTC')->addDays(5)->toDateString()];
        }

        if ($request instanceof CancelSubscriptionRequest) {
            if ($cancelFails) {
                throw new RuntimeException('Service Unavailable (503)');
            }

            $status = 'canceled';

            return (object) ['id' => 'sub_snap', 'status' => 'canceled'];
        }

        throw new LogicException('Unexpected: '.get_class($request));
    });
}

it('lets the checkout in right after an immediate cancel the gate had seen as live', function (): void {
    $cancelFails = false;
    cancelSnapshotMollie($cancelFails);
    $billable = cancelSnapshotBillable();
    $gate = app(MollieSubscriptionGate::class);

    // The past-due dashboard asked a moment ago.
    expect($gate->hasLiveMollieSubscription($billable))->toBeTrue();

    app(CancelSubscription::class)->handle($billable, immediately: true);

    expect($gate->hasLiveMollieSubscription($billable->refresh()))->toBeFalse();
});

it('lets the checkout in once the hourly retry has delivered a cancel Mollie first refused', function (): void {
    $cancelFails = true;
    cancelSnapshotMollie($cancelFails);
    $billable = cancelSnapshotBillable();
    $gate = app(MollieSubscriptionGate::class);

    app(CancelSubscription::class)->handle($billable, immediately: true);

    // Still collecting at Mollie, and the gate says so.
    expect($gate->hasLiveMollieSubscription($billable->refresh()))->toBeTrue();

    $cancelFails = false;
    (new RetrySubscriptionCancelJob(TestBillable::class, $billable->getKey(), 'cst_snap', 'sub_snap', BillingTime::nowUtc()->toIso8601String()))->handle();

    expect($gate->hasLiveMollieSubscription($billable->refresh()))->toBeFalse();
});

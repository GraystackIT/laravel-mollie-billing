<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty('notifyAdminCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

/**
 * Delegates to the shared factory — the Response double it needs is fiddly enough that
 * two copies had already started to drift.
 */
function cancelApiException(string $message, int $code): ApiException
{
    return \GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory::withStatus($code, $message);
}

function cancelMarkerBillable(?array $marker = null): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Cancel '.uniqid(),
        'email' => uniqid('cx-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_cancel',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $marker === null ? [] : ['pending_subscription_cancel' => $marker],
    ])->save();

    return $b->refresh();
}

it('is dispatched by the sweep for a pending_subscription_cancel marker', function (): void {
    Bus::fake([RetrySubscriptionCancelJob::class]);

    $b = cancelMarkerBillable([
        'mollie_customer_id' => 'cst_cancel',
        'mollie_subscription_id' => 'sub_stuck',
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
        'last_error' => 'Mollie 503',
    ]);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertDispatched(
        RetrySubscriptionCancelJob::class,
        fn (RetrySubscriptionCancelJob $job) => $job->billableId === $b->getKey()
            && $job->mollieSubscriptionId === 'sub_stuck'
            && $job->mollieCustomerId === 'cst_cancel',
    );
});

it('clears the marker once Mollie accepts the cancellation', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof CancelSubscriptionRequest)
        ->andReturn((object) ['id' => 'sub_stuck', 'status' => 'canceled']);

    $b = cancelMarkerBillable([
        'mollie_customer_id' => 'cst_cancel',
        'mollie_subscription_id' => 'sub_stuck',
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    ]);

    (new RetrySubscriptionCancelJob(
        TestBillable::class,
        $b->getKey(),
        'cst_cancel',
        'sub_stuck',
        BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    ))->handle();

    expect($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_cancel');
});

it('treats a 404 from Mollie as already cancelled', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof CancelSubscriptionRequest)
        ->andThrow(cancelApiException('No such subscription', 404));

    $b = cancelMarkerBillable([
        'mollie_customer_id' => 'cst_cancel',
        'mollie_subscription_id' => 'sub_gone',
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    ]);

    (new RetrySubscriptionCancelJob(
        TestBillable::class,
        $b->getKey(),
        'cst_cancel',
        'sub_gone',
        BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    ))->handle();

    expect($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_cancel');
});

it('rethrows a non-terminal Mollie error so the queue backs off', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof CancelSubscriptionRequest)
        ->andThrow(cancelApiException('Service unavailable', 503));

    $b = cancelMarkerBillable([
        'mollie_customer_id' => 'cst_cancel',
        'mollie_subscription_id' => 'sub_retry',
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    ]);

    $job = new RetrySubscriptionCancelJob(
        TestBillable::class,
        $b->getKey(),
        'cst_cancel',
        'sub_retry',
        BillingTime::nowUtc()->subMinutes(10)->toIso8601String(),
    );

    expect(fn () => $job->handle())->toThrow(ApiException::class);
    expect($b->fresh()->getBillingSubscriptionMeta())->toHaveKey('pending_subscription_cancel');
});

it('surfaces a permanent failure to admins and drops the marker', function (): void {
    Notification::fake();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [new AnonymousNotifiable]);

    $b = cancelMarkerBillable([
        'mollie_customer_id' => 'cst_cancel',
        'mollie_subscription_id' => 'sub_dead',
        'first_attempt_at' => BillingTime::nowUtc()->subHours(30)->toIso8601String(),
    ]);

    (new RetrySubscriptionCancelJob(
        TestBillable::class,
        $b->getKey(),
        'cst_cancel',
        'sub_dead',
        BillingTime::nowUtc()->subHours(30)->toIso8601String(),
    ))->handle();

    // The marker must go, or the hourly sweep would restart the ladder and re-notify
    // an admin every hour forever.
    expect($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_cancel');

    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
});

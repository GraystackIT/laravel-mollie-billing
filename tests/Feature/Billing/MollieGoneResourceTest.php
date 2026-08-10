<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReferenceCleared;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Laravel\Facades\Mollie;

/**
 * Mollie answers **410 Gone**, not 404, for a resource that existed and was removed.
 *
 * Measured against the sandbox, not assumed: a revoked mandate, a deleted customer, that
 * customer's payment list, and a subscription whose customer was deleted all come back 410,
 * while a plain unknown id comes back 404. The two are different questions — "never
 * existed" versus "existed and is gone" — and the package treated only the first as
 * definitive.
 */
function goneException(int $code = 410): ApiException
{
    return new class('gone', $code) extends ApiException
    {
        public function __construct(string $message, int $code)
        {
            \Exception::__construct($message, $code);
        }
    };
}

function goneBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gone Co', 'email' => uniqid('gone-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'mollie_customer_id' => 'cst_deleted',
        'mollie_mandate_id' => 'mdt_deleted',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_orphan'],
    ])->save();

    return $billable->refresh();
}

it('forgets a subscription id whose customer was deleted at Mollie', function (): void {
    // The gate cleared the stored id on 404 only. Deleting the customer at Mollie — an
    // operator tidying the dashboard, a GDPR erasure — makes every later lookup 410, so the
    // id stayed on the billable forever while `hasLiveMollieSubscription()` answered false:
    // unreachable and un-cancellable, which is exactly the state the gate exists to avoid.
    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(410));

    expect(app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($billable))->toBeFalse()
        ->and($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');
});

it('announces the cleared reference so an app can react', function (): void {
    // Clearing the id is a state change like any other, and the package's seam for those is
    // an event — a log line reaches nobody. Afterwards `hasLiveMollieSubscription()` answers
    // false, which is indistinguishable from a billable that never had a subscription, so
    // without this an app could not tell "gone at Mollie" from "never started".
    Event::fake([MollieSubscriptionReferenceCleared::class]);

    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(410));

    app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($billable);

    Event::assertDispatched(MollieSubscriptionReferenceCleared::class, fn (MollieSubscriptionReferenceCleared $e): bool => $e->billable->getKey() === $billable->getKey()
        && $e->mollieSubscriptionId === 'sub_orphan'
        && $e->reason === 'gone');
});

it('reports a 404 as not_found rather than gone', function (): void {
    // The two codes answer different questions and the audit row says which one it was:
    // 404 is "never existed" (a bad id we wrote), 410 is "existed and was removed" (a
    // deletion at Mollie). Collapsing them would lose the only signal that separates our
    // own bug from an operator's action.
    Event::fake([MollieSubscriptionReferenceCleared::class]);

    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(404));

    app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($billable);

    Event::assertDispatched(MollieSubscriptionReferenceCleared::class, fn (MollieSubscriptionReferenceCleared $e): bool => $e->reason === 'not_found');
});

it('keeps the id when Mollie is merely unreachable', function (): void {
    // The other half: a 503 or a timeout says nothing about the subscription, and dropping
    // the id on one bad night would orphan a live subscription nobody can cancel.
    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(503));

    expect(app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($billable))->toBeFalse()
        ->and($billable->refresh()->getBillingSubscriptionMeta())->toHaveKey('mollie_subscription_id');
});

it('lets an orphan be cleaned up when its Mollie customer is gone', function (): void {
    // `mollieMayHavePaid()` answers "maybe" for anything it cannot ask, and refuses the
    // cleanup — right for a timeout, wrong for a deleted customer, which is a definitive
    // "there are no payments here". The orphan could never be cleaned up and every nightly
    // sweep logged the same warning about it.
    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(410));

    $method = new ReflectionMethod(\GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob::class, 'mollieMayHavePaid');
    $method->setAccessible(true);

    expect($method->invoke(new \GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob, $billable))->toBeFalse();
});

it('still refuses the cleanup when Mollie cannot be reached', function (): void {
    $billable = goneBillable();

    Mollie::shouldReceive('send')->andThrow(goneException(503));

    $method = new ReflectionMethod(\GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob::class, 'mollieMayHavePaid');
    $method->setAccessible(true);

    expect($method->invoke(new \GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob, $billable))->toBeTrue();
});

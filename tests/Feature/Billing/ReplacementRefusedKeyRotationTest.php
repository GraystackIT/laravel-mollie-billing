<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReferenceCleared;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReplaced;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionReplacementJob;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * The create of a replacement subscription (MollieSubscriptionReplacer, key
 * `subscription-replace:…`) is retried by RetrySubscriptionReplacementJob at 1, 5, 15, 30 and
 * 60 minutes — inside the hour for which a 4xx binds its key (measured 26 November 2026).
 * After a definite refusal the next attempt carries a new key; after an outage or a lost
 * answer it keeps the old one.
 */

beforeEach(function (): void {
    longRunBoot();
    config()->set('mollie-billing.billable_model', TestBillable::class);
    Event::fake([PaymentAmountMismatch::class, MollieSubscriptionReplaced::class, MollieSubscriptionReferenceCleared::class]);
});

afterEach(fn () => Carbon::setTestNow());

/** The 31 January signup under "drift": its first renewal replaces the subscription. */
function refusedReplacementWorld(): LongRunWorld
{
    $world = longRunStart('monthly', '2027-01-31 10:00:00', '34.51');
    $world->cadence = 'drift';

    return $world;
}

/** @return list<string> */
function refusedReplacementKeys(LongRunWorld $world): array
{
    return array_values(array_filter(
        $world->idempotencyKeys,
        static fn ($key): bool => is_string($key) && str_starts_with($key, 'subscription-replace:'),
    ));
}

function refusedReplacementRetry(LongRunWorld $world): void
{
    try {
        (new RetrySubscriptionReplacementJob(TestBillable::class, $world->billable->getKey()))
            ->handle(app(MollieSubscriptionReplacer::class));
    } catch (Throwable) {
        // A worker's queue backs off and runs it again; the marker stays.
    }
}

it('retries a replacement Mollie refused with a new key, at once, and creates one subscription', function (): void {
    $world = refusedReplacementWorld();
    $refusing = true;
    $world->intercept = function (object $request) use (&$refusing) {
        if ($refusing && $request instanceof CreateSubscriptionRequest) {
            throw longRunMollieRefusal('The mandate is invalid');
        }

        return null;
    };

    longRunAssertRenewal($world, longRunRenew($world), '34.51');
    expect($world->billable->fresh()->getBillingSubscriptionMeta())->toHaveKey(MollieSubscriptionReplacer::META_KEY);

    // The mandate is fixed; the job's first retry, a minute later.
    $refusing = false;
    refusedReplacementRetry($world);

    $keys = refusedReplacementKeys($world);

    expect($keys)->toHaveCount(2)
        ->and($keys[1])->not->toBe($keys[0])
        ->and($keys[1])->toStartWith($keys[0])
        ->and($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY);
});

it('keeps the replacement key after an outage, and creates one subscription', function (): void {
    $world = refusedReplacementWorld();
    $outages = 1;
    $world->intercept = function (object $request) use (&$outages) {
        if ($outages > 0 && $request instanceof CreateSubscriptionRequest) {
            $outages--;

            throw MollieApiExceptionFactory::withStatus(503, 'Error executing API call (503: Service Unavailable)');
        }

        return null;
    };

    longRunAssertRenewal($world, longRunRenew($world), '34.51');
    refusedReplacementRetry($world);

    $keys = refusedReplacementKeys($world);

    expect($keys)->toHaveCount(2)
        ->and($keys[1])->toBe($keys[0])
        ->and($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2');
});

it('does not create a second replacement after a lost answer that follows a refusal', function (): void {
    $world = refusedReplacementWorld();
    $refusing = true;
    $world->intercept = function (object $request) use (&$refusing) {
        if ($refusing && $request instanceof CreateSubscriptionRequest) {
            throw longRunMollieRefusal('The mandate is invalid');
        }

        return null;
    };

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    $refusing = false;
    $world->loseAnswer[CreateSubscriptionRequest::class] = 1;
    refusedReplacementRetry($world);

    // Mollie created it under the new key; the answer was lost.
    expect($world->subscriptionsCreated)->toBe(2);

    refusedReplacementRetry($world);

    $keys = refusedReplacementKeys($world);

    expect($keys[2])->toBe($keys[1])
        ->and($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY);
});

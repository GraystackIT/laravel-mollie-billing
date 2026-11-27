<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PreviousSubscriptionStillRunningException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A resubscribe leaves the customer with exactly one subscription at Mollie.
 *
 * Two ways it did not (docs/decisions.md, "A resume leaves one subscription at Mollie"):
 *
 *  - the cancel it undoes never reached Mollie (`pending_subscription_cancel`): the old
 *    subscription was still collecting, and the resume created a second one beside it —
 *    both charging on the same day once the cancel's retries ran out;
 *  - two resumes at once (a second tab, a second operator): both read `cancelled` before
 *    either had written, and both created a subscription.
 */
beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function resubOneActiveBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'One Sub Co',
        'email' => 'one-sub@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_one',
        'mollie_mandate_id' => 'mdt_one',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_old', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

/**
 * Mollie as one closure: `$cancelFails` decides whether a cancel is refused (503), every
 * request is recorded by class base name, and `$onCreate` runs inside the create call.
 *
 * @param  array<int, string>  $sent
 */
function resubOneMollie(array &$sent, bool &$cancelFails, ?Closure $onCreate = null): void
{
    $created = 0;

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent, &$cancelFails, &$created, $onCreate) {
        $sent[] = class_basename($request);

        if ($request instanceof CancelSubscriptionRequest) {
            if ($cancelFails) {
                throw new RuntimeException('Service Unavailable (503)');
            }

            return (object) ['id' => 'sub_old', 'status' => 'canceled'];
        }

        if ($request instanceof CreateSubscriptionRequest) {
            $created++;

            if ($onCreate !== null) {
                $onCreate();
            }

            return (object) ['id' => 'sub_new_'.$created];
        }

        throw new LogicException('Unexpected: '.get_class($request));
    });
}

it('refuses a resume while the cancel it undoes has not reached Mollie, and creates nothing', function (): void {
    $sent = [];
    $cancelFails = true;
    resubOneMollie($sent, $cancelFails);

    $billable = resubOneActiveBillable();
    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();
    expect($billable->getBillingSubscriptionMeta()['pending_subscription_cancel']['mollie_subscription_id'] ?? null)->toBe('sub_old');

    expect(fn () => app(ResubscribeSubscription::class)->handle($billable->refresh()))
        ->toThrow(PreviousSubscriptionStillRunningException::class);

    $billable->refresh();
    expect($sent)->not->toContain('CreateSubscriptionRequest')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_old')
        ->and($billable->getBillingSubscriptionMeta()['pending_subscription_cancel']['mollie_subscription_id'] ?? null)->toBe('sub_old', 'the marker stays for the sweep')
        // Untouched, so the same click can be tried again.
        ->and($billable->getBillingSubscriptionMeta())->toHaveKey('cancelled_from');
});

it('finishes the pending cancel first once Mollie answers, then creates the one new subscription', function (): void {
    $sent = [];
    $cancelFails = true;
    resubOneMollie($sent, $cancelFails);

    $billable = resubOneActiveBillable();
    app(CancelSubscription::class)->handle($billable, immediately: false);

    $cancelFails = false;
    $sent = [];
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $billable->refresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($sent)->toBe(['CancelSubscriptionRequest', 'CreateSubscriptionRequest'], 'the old one is cancelled before the new one exists')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_new_1')
        ->and($meta)->not->toHaveKey('pending_subscription_cancel');
});

it('refuses a second resume that arrives while the first is creating the subscription', function (): void {
    $sent = [];
    $cancelFails = false;
    $second = null;
    $billableId = null;

    resubOneMollie($sent, $cancelFails, function () use (&$second, &$billableId): void {
        if ($second !== null) {
            return;
        }

        $second = 'ran';

        // Another tab: its own request, its own copy of the row — still `cancelled`, since
        // the first resume has not written anything yet.
        try {
            app(ResubscribeSubscription::class)->handle(TestBillable::query()->findOrFail($billableId));
        } catch (Throwable $e) {
            $second = $e;
        }
    });

    $billable = resubOneActiveBillable();
    $billableId = $billable->getKey();
    app(CancelSubscription::class)->handle($billable, immediately: false);

    $sent = [];
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    expect($second)->toBeInstanceOf(SubscriptionChangeInProgressException::class)
        ->and(array_count_values($sent)['CreateSubscriptionRequest'] ?? 0)->toBe(1, 'one subscription created at Mollie')
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('refuses a resume of a row that is no longer cancelled once the first resume has finished', function (): void {
    $sent = [];
    $cancelFails = false;
    resubOneMollie($sent, $cancelFails);

    $billable = resubOneActiveBillable();
    app(CancelSubscription::class)->handle($billable, immediately: false);

    // Both tabs loaded the row while it was cancelled.
    $stale = TestBillable::query()->findOrFail($billable->getKey());
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $sent = [];
    expect(fn () => app(ResubscribeSubscription::class)->handle($stale))
        ->toThrow(RuntimeException::class, 'not in a cancelled state');

    expect($sent)->toBe([], 'the stale copy sent nothing to Mollie');
});

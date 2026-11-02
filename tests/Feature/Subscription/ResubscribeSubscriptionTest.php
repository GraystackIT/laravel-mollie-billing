<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionResumed;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function resubMollieBillable(string $interval = 'monthly'): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Resub Co',
        'email' => 'resub@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_resub',
        'mollie_mandate_id' => 'mdt_resub',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_resub', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

/** Reads the private startDate off a captured CreateSubscriptionRequest. */
function capturedStartDate(object $request): string
{
    $prop = (new ReflectionObject($request))->getProperty('startDate');
    $prop->setAccessible(true);

    return (string) $prop->getValue($request);
}

/**
 * One `send` expectation for the whole flow — cancel first, then create. A second
 * `shouldReceive('send')` would not replace the first, so both requests have to be served
 * by the same closure. Returns a box whose `request` key holds the CreateSubscriptionRequest.
 */
function captureResubscribeCreate(): object
{
    $box = new stdClass();
    $box->request = null;

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($box) {
        if ($request instanceof CancelSubscriptionRequest) {
            return (object) ['id' => 'sub_resub', 'status' => 'canceled'];
        }
        if ($request instanceof CreateSubscriptionRequest) {
            $box->request = $request;

            return (object) ['id' => 'sub_resub_new'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    return $box;
}

it('anchors the first Mollie charge to the end of the already-paid period', function (): void {
    $billable = resubMollieBillable();
    $periodEnd = $billable->nextBillingDate();

    // Cancel on day 1, resubscribe on day 29 of a monthly period.
    $box = captureResubscribeCreate();
    app(CancelSubscription::class)->handle($billable, immediately: false);
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $captured = $box->request;
    expect($captured)->toBeInstanceOf(CreateSubscriptionRequest::class);
    // Day 30, not day 59: the days between the paid period end and a restarted
    // `now + 1 interval` cadence would otherwise go unbilled.
    expect(capturedStartDate($captured))->toBe($periodEnd->toDateString());

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    // The local period must agree with Mollie's schedule.
    expect($billable->nextBillingDate()->toDateString())->toBe($periodEnd->toDateString());
});

it('restores a cancelled trial as a trial on its original end date', function (): void {
    Event::fake([SubscriptionResumed::class]);

    $billable = resubMollieBillable();
    $trialEnd = BillingTime::nowUtc()->addDays(13);
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => $trialEnd,
    ])->save();

    $box = captureResubscribeCreate();
    app(CancelSubscription::class)->handle($billable, immediately: false);
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $billable->refresh();
    $captured = $box->request;
    expect($captured)->toBeInstanceOf(CreateSubscriptionRequest::class);

    // Not Active: nothing has been paid, so a paid-looking subscription with the first
    // charge a full interval away would be free access.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->trial_ends_at->toDateString())->toBe($trialEnd->toDateString());
    expect(capturedStartDate($captured))->toBe($trialEnd->toDateString());

    Event::assertDispatched(SubscriptionResumed::class);
});

it('keeps a duration-limited local grant expiring after a resume', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Grant Co', 'email' => 'grant-resub@x.test']);

    $grantEnd = BillingTime::nowUtc()->addDays(27);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
        'subscription_ends_at' => $grantEnd,
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    app(CancelSubscription::class)->handle($billable->refresh(), immediately: false);
    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    // Nulling this made the grant perpetual, and the only automatic expiry pass is
    // Cancelled-based, so nothing could ever reclaim it.
    expect($billable->subscription_ends_at)->not->toBeNull();
    expect($billable->subscription_ends_at->toDateString())->toBe($grantEnd->toDateString());
});

it('clears ends_at again when the local plan had no expiry before the cancel', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Free Co', 'email' => 'free-resub@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
        'subscription_ends_at' => null,
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    app(CancelSubscription::class)->handle($billable->refresh(), immediately: false);
    expect($billable->refresh()->subscription_ends_at)->not->toBeNull();

    app(ResubscribeSubscription::class)->handle($billable->refresh());

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_ends_at)->toBeNull();
});

it('refuses to resubscribe once the grace period has ended', function (): void {
    $billable = resubMollieBillable();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ])->save();

    expect(fn () => app(ResubscribeSubscription::class)->handle($billable->refresh()))
        ->toThrow(\RuntimeException::class);
});

it('starts a fresh dunning clock when a free plan is resumed', function (): void {
    // A free plan with metered usage does go past due — the overage charge is real money
    // even when the plan is not. Resuming inside the grace period kept `past_due_since`
    // from that old episode, so the first failed overage charge afterwards was measured
    // against a months-old date and the nightly pass auto-cancelled the same night, with
    // no dunning window at all. Every other path back into a live state clears it; this
    // branch wrote the meta through untouched.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Dunning Co', 'email' => 'dunning@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => now()->addDays(10),
        'subscription_meta' => [
            'seat_count' => 1,
            'past_due_since' => now()->subMonths(4)->toIso8601String(),
            'payment_failure' => ['count' => 3, 'last_error' => 'insufficient funds'],
        ],
    ])->save();

    app(\GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription::class)->handle($billable->refresh());

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($meta)->not->toHaveKey('past_due_since')
        ->and($meta)->not->toHaveKey('payment_failure');
});

it('refuses to resume a subscription while its country mismatch is still open', function (): void {
    // The mismatch flow cancels the subscription and parks the customer on the correction
    // modal. The dashboard hides "reactivate" while the mismatch is open, but the action is
    // a plain Livewire method, and the service resumed it — a fresh Mollie subscription on
    // the unreviewed VAT data, with `subscription_ends_at` cleared, while the still-open
    // mismatch kept CountryMatchService from ever cancelling it again.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Mismatch Co', 'email' => uniqid('mm-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(20),
        'mollie_customer_id' => 'cst_mm',
        'mollie_mandate_id' => 'mdt_mm',
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    \GraystackIT\MollieBilling\Models\BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'status' => \GraystackIT\MollieBilling\Enums\CountryMismatchStatus::Pending,
    ]);

    expect(fn () => app(ResubscribeSubscription::class)->handle($billable->refresh()))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException::class);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});

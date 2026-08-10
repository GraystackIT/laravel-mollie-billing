<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ApplyScheduledChangesJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Three transitions that skipped hygiene their siblings perform. What survives such a
 * gap is never inert — it is a clock, a schedule or an id that the next unattended
 * pass acts on.
 */
beforeEach(function (): void {
    SpyMollieSubscriptionPatcher::$calls = [];
    SpyMollieSubscriptionPatcher::$cancelSucceeds = true;
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    foreach (['pro' => 3000, 'enterprise' => 9000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $code === 'enterprise' ? 4 : 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => $price, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => $price * 10, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ]);
    }
});

function hygieneBillable(array $overrides = [], array $meta = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Hygiene Co',
        'email' => uniqid('hygiene-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_hy',
        'mollie_mandate_id' => 'mdt_hy',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'subscription_meta' => array_merge(['mollie_subscription_id' => 'sub_hy', 'seat_count' => 1], $meta),
    ], $overrides))->save();

    return $b->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 15a — a fresh subscription starts a fresh dunning clock
// ─────────────────────────────────────────────────────────────────────────────

it('clears the dunning episode when a local subscription is activated', function (): void {
    $billable = hygieneBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ], [
        // What a dunning episode months ago left behind.
        'past_due_since' => BillingTime::nowUtc()->subMonths(6)->toIso8601String(),
        'payment_failure' => ['attempts' => 3],
    ]);

    app(ActivateLocalSubscription::class)->handle($billable, planCode: 'free', interval: 'monthly');

    // Kept, ONE later failed renewal was measured against the six-month-old date and
    // the nightly pass auto-cancelled the same night — a zero-day dunning window,
    // before Mollie had even retried the payment.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('past_due_since')
        ->and($meta)->not->toHaveKey('payment_failure')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('keeps the hygiene lists in one place so a new transition cannot forget one', function (): void {
    // The regression this guards: four transitions each maintained their own unset
    // list and they had drifted apart.
    $fresh = SubscriptionMetaHygiene::forFreshSubscription([
        'past_due_since' => 'x',
        'payment_failure' => ['a'],
        'scheduled_change' => ['b'],
        'next_charge_date_override' => 'y',
        'active_recurring_coupon' => ['c'],
        'cancelled_from' => 'pro',
        // Identity and the cancel record must survive: dropping either orphans a live
        // Mollie subscription that nothing can target afterwards.
        'mollie_subscription_id' => 'sub_keep',
        'pending_subscription_cancel' => ['mollie_subscription_id' => 'sub_keep'],
    ]);

    expect($fresh)->toBe([
        'mollie_subscription_id' => 'sub_keep',
        'pending_subscription_cancel' => ['mollie_subscription_id' => 'sub_keep'],
    ]);

    // The narrow variant leaves everything that is not the dunning episode.
    $dunning = SubscriptionMetaHygiene::withoutDunningEpisode([
        'past_due_since' => 'x',
        'payment_failure' => ['a'],
        'active_recurring_coupon' => ['c'],
        'scheduled_change' => ['b'],
    ]);

    expect($dunning)->toBe([
        'active_recurring_coupon' => ['c'],
        'scheduled_change' => ['b'],
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 15b — the free downgrade gets the same hygiene as its sibling
// ─────────────────────────────────────────────────────────────────────────────

it('recovers a past-due account when it downgrades to free', function (): void {
    $billable = hygieneBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
    ], [
        'mollie_subscription_id' => 'sub_hy',
        'past_due_since' => BillingTime::nowUtc()->subDays(3)->toIso8601String(),
        'payment_failure' => ['attempts' => 2],
    ]);

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // The status used to stay PastDue while the source flipped to Local — which
    // `hasAccessibleBillingSubscription()` refuses, and no renewal will ever arrive to
    // recover a Local subscription. The stale clock then auto-cancelled and expired it.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('payment_failure');
});

it('drops a scheduled change when downgrading to free', function (): void {
    $billable = hygieneBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]);

    expect($billable->refresh()->scheduled_change_at)->not->toBeNull();

    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // Surviving, the daily dispatcher applied an upgrade the validator refuses for a
    // Local plan and re-threw without clearing the column — failing every night forever.
    expect($billable->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override');
});

it('drops a scheduled change that its own validation permanently refuses', function (): void {
    $billable = hygieneBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]);

    // Now on a Local free plan, where an upgrade needs the Mollie path. Reached by
    // hand here because the downgrade above (correctly) clears the change.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
        'scheduled_change_at' => BillingTime::nowUtc()->subMinute(),
    ])->save();

    app(ScheduleSubscriptionChange::class)->apply($billable->fresh());

    // Re-thrown, `scheduled_change_at` was never cleared and ApplyScheduledChangesJob
    // re-failed on this billable every single day.
    expect($billable->refresh()->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
});

// ─────────────────────────────────────────────────────────────────────────────
// 15c — a failed Mollie cancel must not orphan a live subscription
// ─────────────────────────────────────────────────────────────────────────────

it('keeps the subscription id when the free-downgrade cancel fails at Mollie', function (): void {
    SpyMollieSubscriptionPatcher::$cancelSucceeds = false;

    $billable = hygieneBillable();

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    // Dropping the id while the subscription is still live at Mollie left it charging
    // the old plan's price every period, with nothing able to target it any more.
    expect($meta['mollie_subscription_id'])->toBe('sub_hy')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local);
});

it('marks, queues and reports a failed cancel from the real patcher', function (): void {
    Queue::fake();
    Notification::fake();

    // The real patcher this time — the spy cannot exercise the marker it writes.
    $this->app->forgetInstance(MollieSubscriptionPatcher::class);
    $this->app->bind(MollieSubscriptionPatcher::class, fn ($app) => new MollieSubscriptionPatcher(
        $app->make(GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class),
        $app->make(GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
    ));

    $billable = hygieneBillable();
    GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    Mollie\Laravel\Facades\Mollie::shouldReceive('send')
        ->andThrow(new RuntimeException('Mollie unreachable'));

    $cancelled = app(MollieSubscriptionPatcher::class)->cancelForFreeDowngrade($billable);

    expect($cancelled)->toBeFalse();

    $marker = $billable->refresh()->getBillingSubscriptionMeta()['pending_subscription_cancel'] ?? null;

    expect($marker)->not->toBeNull()
        ->and($marker['mollie_subscription_id'])->toBe('sub_hy');

    // The marker alone is only read by the hourly sweep, and every period until then is
    // a period the customer is charged for a plan they no longer have.
    Queue::assertPushed(RetrySubscriptionCancelJob::class);
    Notification::assertSentTo(
        $billable,
        GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification::class,
    );

    GraystackIT\MollieBilling\MollieBilling::flushCallbacks();
});

it('treats an already-gone subscription as cancelled', function (): void {
    $this->app->forgetInstance(MollieSubscriptionPatcher::class);
    $this->app->bind(MollieSubscriptionPatcher::class, fn ($app) => new MollieSubscriptionPatcher(
        $app->make(GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class),
        $app->make(GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
    ));

    $billable = hygieneBillable();

    Mollie\Laravel\Facades\Mollie::shouldReceive('send')->andThrow(
        GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory::withStatus(404, 'No such subscription'),
    );

    // 404/410 is the outcome we wanted, not a failure to retry forever.
    expect(app(MollieSubscriptionPatcher::class)->cancelForFreeDowngrade($billable))->toBeTrue()
        ->and($billable->refresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey('pending_subscription_cancel');
});

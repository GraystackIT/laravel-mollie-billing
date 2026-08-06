<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A lingering `pending_prorata_change` makes ValidateSubscriptionChange refuse every
 * further plan change, so this job has to resolve it — but never by discarding a
 * payment that was actually collected.
 */
beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);

    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [staleProrataAdmin()]);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))
        ->getProperty('notifyAdminCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function staleProrataAdmin(): TestBillable
{
    return TestBillable::firstOrCreate(
        ['email' => 'ops@example.test'],
        ['name' => 'Ops', 'billing_country' => 'AT'],
    );
}

function staleProrataBillable(int $ageDays, array $pendingOverrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Stale '.uniqid(),
        'email' => uniqid('stale-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => [
            'pending_plan_change' => ['plan_code' => 'business', 'interval' => 'monthly'],
            'pending_prorata_change' => array_merge([
                'charge_payment_id' => 'tr_stale',
                'created_at' => BillingTime::nowUtc()->subDays($ageDays)->toIso8601String(),
                'charge_lines' => [['kind' => 'plan', 'total_net' => 1000]],
            ], $pendingOverrides),
        ],
    ])->save();

    return $b->refresh();
}

it('records an orphan and alerts when the charge was paid but no webhook arrived', function (): void {
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'paid', 'id' => 'tr_stale']);

    $b = staleProrataBillable(2);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    // Pending cleared so the customer can change plan again…
    expect($meta['pending_prorata_change'] ?? null)->toBeNull()
        // …and the money is on record instead of vanishing.
        ->and($meta['orphaned_prorata_payment']['payment_id'] ?? null)->toBe('tr_stale')
        ->and($meta['orphaned_prorata_payment']['prorata_charge_lines'] ?? null)->not->toBeNull();

    Notification::assertSentTo(staleProrataAdmin(), AdminPlanChangeFailedNotification::class);
});

it('acts on a paid charge regardless of how young the entry is', function (): void {
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'paid', 'id' => 'tr_stale']);

    // Deliberately past the hard limit: the old order deleted on age BEFORE asking
    // Mollie, so a collected payment was discarded with an alert that only said
    // "deleted after 7 days without webhook".
    $b = staleProrataBillable(9);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect($b->fresh()->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null)
        ->toBe('tr_stale');
});

it('keeps waiting while Mollie still reports the charge as unsettled', function (): void {
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'pending', 'id' => 'tr_stale']);

    // SEPA direct debit legitimately sits in `pending` for several business days.
    $b = staleProrataBillable(9);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect($b->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)
        ->toBe('tr_stale');

    Notification::assertNothingSent();
});

it('clears the pending state and records the reason on a terminal failure', function (): void {
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'failed', 'id' => 'tr_stale']);

    $b = staleProrataBillable(2);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($meta['pending_prorata_change'] ?? null)->toBeNull()
        ->and($meta['plan_change_failed_reason'] ?? null)->toBe('Charge failed')
        ->and($meta['orphaned_prorata_payment'] ?? null)->toBeNull();
});

it('gives up only once Mollie has been unreachable past the hard limit', function (): void {
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andThrow(new RuntimeException('Mollie down'));

    $young = staleProrataBillable(2);
    $old = staleProrataBillable(9);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    // The young one is retried on the next run rather than thrown away.
    expect($young->fresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->not->toBeNull()
        ->and($old->fresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();

    Notification::assertSentTo(staleProrataAdmin(), AdminPlanChangeFailedNotification::class);
});

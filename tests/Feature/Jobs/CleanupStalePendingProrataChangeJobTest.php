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
            'prorata_pending_payment_id' => 'tr_stale',
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

    // Pending cleared so the customer can change plan again — all of it: the validator
    // refuses a change while `pending_plan_change` is there, and so does the portal.
    expect($meta['pending_prorata_change'] ?? null)->toBeNull()
        ->and($meta['pending_plan_change'] ?? null)->toBeNull()
        ->and($meta['prorata_pending_payment_id'] ?? null)->toBeNull()
        // …and the plan page names it as unfinished — not as applied, and not as a failed
        // payment to try again: the payment was collected.
        ->and($meta['plan_change_unfinished']['payment'] ?? null)->toBe('paid')
        ->and($meta['plan_change_failed_at'] ?? null)->toBeNull()
        // …and the money is on record instead of vanishing.
        ->and($meta['orphaned_prorata_payment']['payment_id'] ?? null)->toBe('tr_stale')
        ->and($meta['orphaned_prorata_payment']['prorata_charge_lines'] ?? null)->not->toBeNull();

    Notification::assertSentTo(
        staleProrataAdmin(),
        AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'no invoice exists'),
    );
});

it('says the payment is booked when its invoice exists, and stays quiet when the webhook already told the operators', function (bool $reported): void {
    // A webhook run that died part-way and could not be resumed: the invoice is there,
    // the change is not. The alert used to claim "no invoice exists", and came on top of
    // the one ProrataChargeHandler::reportInterruptedRun() had already sent.
    Notification::fake();

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'paid', 'id' => 'tr_stale']);

    $b = staleProrataBillable(2, $reported
        ? ['progress' => ['done' => ['invoiced', 'document'], 'interrupted_reported_at' => '2026-01-01T00:00:00+00:00']]
        : ['progress' => ['done' => ['invoiced', 'document']]]);

    \GraystackIT\MollieBilling\Models\BillingInvoice::query()->forceCreate([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'mollie_payment_id' => 'tr_stale',
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [],
        'refunded_net' => 0,
        'serial_number' => 'IN-STALE-1',
    ]);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($meta['pending_prorata_change'] ?? null)->toBeNull()
        ->and($meta['pending_plan_change'] ?? null)->toBeNull()
        ->and($meta['orphaned_prorata_payment']['reason'] ?? null)->toBe('charge paid and booked, but its plan change was never completed');

    if ($reported) {
        Notification::assertNothingSent();
    } else {
        Notification::assertSentTo(
            staleProrataAdmin(),
            AdminPlanChangeFailedNotification::class,
            fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'its invoice exists')
                && $n->context['completed_steps'] === ['invoiced', 'document'],
        );
    }
})->with(['webhook already told them' => [true], 'nobody told them yet' => [false]]);

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

it('leaves a run the webhook may still finish alone, invoice or not, and whether Mollie answers or not', function (bool $mollieAnswers): void {
    // The run notes its context before its invoice; between the two, and while Mollie may
    // still re-deliver, the change is the webhook's to finish.
    Notification::fake();

    $lookup = Mollie::shouldReceive('send')->withArgs(fn ($req) => $req instanceof GetPaymentRequest);
    $mollieAnswers
        ? $lookup->andReturn((object) ['status' => 'paid', 'id' => 'tr_stale'])
        : $lookup->andThrow(new RuntimeException('Mollie down'));

    $b = staleProrataBillable(9, ['progress' => [
        'anchor' => null,
        'started_at' => BillingTime::nowUtc()->subHour()->toIso8601String(),
        'done' => [],
    ]]);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    $meta = $b->fresh()->getBillingSubscriptionMeta();
    expect($meta['pending_prorata_change']['charge_payment_id'] ?? null)->toBe('tr_stale')
        ->and($meta['pending_plan_change'] ?? null)->not->toBeNull()
        ->and($meta['orphaned_prorata_payment'] ?? null)->toBeNull();
    Notification::assertNothingSent();
})->with(['Mollie answers paid' => [true], 'Mollie unreachable past the hard limit' => [false]]);

it('drops only the cleaned payment\'s change, never one that took its place meanwhile', function (): void {
    // The customer withdrew the change while the job asked Mollie, and started another.
    Notification::fake();

    $b = staleProrataBillable(2);

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturnUsing(function () use ($b) {
            $b->refresh()->forceFill(['subscription_meta' => [
                'pending_plan_change' => ['plan_code' => 'enterprise', 'interval' => 'monthly'],
                'prorata_pending_payment_id' => 'tr_other',
                'pending_prorata_change' => [
                    'charge_payment_id' => 'tr_other',
                    'created_at' => BillingTime::nowUtc()->toIso8601String(),
                ],
            ]])->save();

            return (object) ['status' => 'paid', 'id' => 'tr_stale'];
        });

    CleanupStalePendingProrataChangeJob::dispatchSync();

    $meta = $b->fresh()->getBillingSubscriptionMeta();
    expect($meta['pending_prorata_change']['charge_payment_id'] ?? null)->toBe('tr_other')
        ->and($meta['prorata_pending_payment_id'] ?? null)->toBe('tr_other')
        ->and($meta['pending_plan_change']['plan_code'] ?? null)->toBe('enterprise')
        // Not reported from here either: a change is only replaced after it was withdrawn,
        // and the withdrawal records a payment it could not cancel itself
        // (UpdateSubscription::cancelPendingPlanChange()); a paid one it refuses to withdraw.
        ->and($meta['orphaned_prorata_payment'] ?? null)->toBeNull();
    Notification::assertNothingSent();
});

it('does not report a change the webhook finished while Mollie was asked', function (): void {
    Notification::fake();
    $b = staleProrataBillable(2);

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturnUsing(function () use ($b) {
            // The webhook's run completes and clears the waiting change.
            $b->refresh()->forceFill(['subscription_meta' => ['seat_count' => 1]])->save();

            return (object) ['status' => 'paid', 'id' => 'tr_stale'];
        });

    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect($b->fresh()->getBillingSubscriptionMeta())->toBe(['seat_count' => 1]);
    Notification::assertNothingSent();
});

it('does not alert a second time when Mollie is unreachable for a run the webhook already reported', function (): void {
    Notification::fake();
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andThrow(new RuntimeException('Mollie down'));

    $b = staleProrataBillable(9, ['progress' => [
        'anchor' => null,
        'started_at' => BillingTime::nowUtc()->subHour()->toIso8601String(),
        'done' => ['invoiced'],
        'interrupted_reported_at' => BillingTime::nowUtc()->subHour()->toIso8601String(),
    ]]);

    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect($b->fresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();
    Notification::assertNothingSent();
});

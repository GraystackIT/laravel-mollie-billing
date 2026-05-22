<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\CheckoutAbandoned;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config(['mollie-billing.cleanup.enabled' => true]);
    config(['mollie-billing.cleanup.threshold_minutes' => 60]);

    // Reset the static closure between tests — registering it once leaks
    // into other test files because the Manager singleton holds onto it.
    $reflection = new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class);
    $prop = $reflection->getProperty('cleanupOrphanedBillableCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

afterEach(function (): void {
    $reflection = new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class);
    $prop = $reflection->getProperty('cleanupOrphanedBillableCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

it('deletes orphaned billables that exceed the age threshold', function (): void {
    Event::fake([CheckoutAbandoned::class]);

    $orphan = TestBillable::create([
        'name' => 'Stale Co',
        'email' => 'stale@example.test',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    $fresh = TestBillable::create([
        'name' => 'Fresh Co',
        'email' => 'fresh@example.test',
    ]);

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($orphan->getKey()))->toBeNull();
    expect(TestBillable::find($fresh->getKey()))->not->toBeNull();

    Event::assertDispatched(CheckoutAbandoned::class, fn ($e) => $e->billable->is($orphan));
});

it('keeps billables that already have an active subscription', function (): void {
    Event::fake([CheckoutAbandoned::class]);

    $active = TestBillable::create([
        'name' => 'Active Co',
        'email' => 'active@example.test',
    ]);
    $active->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => 'monthly',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($active->getKey()))->not->toBeNull();
    Event::assertNotDispatched(CheckoutAbandoned::class);
});

it('keeps local subscriptions even when subscription_status is new', function (): void {
    // Defensive: a Local activation flips status, but if anything ever lands
    // a row with source=local + status=new we still must not nuke it.
    $local = TestBillable::create([
        'name' => 'Local Co',
        'email' => 'local@example.test',
    ]);
    $local->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::New,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($local->getKey()))->not->toBeNull();
});

it('only deletes when Mollie reports a terminal failure for a pending payment', function (): void {
    Event::fake([CheckoutAbandoned::class]);

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'open']);

    $waiting = TestBillable::create([
        'name' => 'Waiting Co',
        'email' => 'waiting@example.test',
    ]);
    $waiting->forceFill([
        'subscription_meta' => ['pending_first_payment_id' => 'tr_open'],
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($waiting->getKey()))->not->toBeNull();
    Event::assertNotDispatched(CheckoutAbandoned::class);
});

it('deletes when Mollie reports the pending payment as canceled', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaymentRequest)
        ->andReturn((object) ['status' => 'canceled']);

    $orphan = TestBillable::create([
        'name' => 'Cancelled Co',
        'email' => 'cancelled@example.test',
    ]);
    $orphan->forceFill([
        'subscription_meta' => ['pending_first_payment_id' => 'tr_canceled'],
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($orphan->getKey()))->toBeNull();
});

it('runs the cleanupOrphanedBillableUsing closure when registered', function (): void {
    $captured = null;
    MollieBilling::cleanupOrphanedBillableUsing(function ($billable) use (&$captured): void {
        $captured = $billable;
        $billable->delete();
    });

    $orphan = TestBillable::create([
        'name' => 'Hooked Co',
        'email' => 'hooked@example.test',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect($captured)->not->toBeNull();
    expect($captured->is($orphan))->toBeTrue();
});

it('revokes the Mollie mandate when one was captured before abandonment', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);

    $orphan = TestBillable::create([
        'name' => 'Mandate Co',
        'email' => 'mandate@example.test',
        'mollie_customer_id' => 'cst_xyz',
        'mollie_mandate_id' => 'mdt_xyz',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    Bus::assertDispatched(RevokeMollieMandateJob::class, fn ($job) => $job->mollieCustomerId === 'cst_xyz' && $job->mandateId === 'mdt_xyz');
});

it('skips event, mandate revocation and delete when the cleanup closure returns false', function (): void {
    Event::fake([CheckoutAbandoned::class]);
    Bus::fake([RevokeMollieMandateJob::class]);

    MollieBilling::cleanupOrphanedBillableUsing(fn ($billable): bool => false);

    $protected = TestBillable::create([
        'name' => 'Protected Co',
        'email' => 'protected@example.test',
        'mollie_customer_id' => 'cst_protected',
        'mollie_mandate_id' => 'mdt_protected',
    ]);
    $protected->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($protected->getKey()))->not->toBeNull();
    Event::assertNotDispatched(CheckoutAbandoned::class);
    Bus::assertNotDispatched(RevokeMollieMandateJob::class);
});

it('treats a void-returning cleanup closure as a successful cleanup', function (): void {
    Event::fake([CheckoutAbandoned::class]);

    MollieBilling::cleanupOrphanedBillableUsing(function ($billable): void {
        $billable->delete();
    });

    $orphan = TestBillable::create([
        'name' => 'Void Co',
        'email' => 'void@example.test',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($orphan->getKey()))->toBeNull();
    Event::assertDispatched(CheckoutAbandoned::class);
});

it('respects the threshold_minutes config', function (): void {
    config(['mollie-billing.cleanup.threshold_minutes' => 240]);

    $orphan = TestBillable::create([
        'name' => 'Recent Co',
        'email' => 'recent@example.test',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    // 2h old, threshold 4h → should still be there.
    expect(TestBillable::find($orphan->getKey()))->not->toBeNull();
});

it('is a no-op when cleanup is disabled', function (): void {
    config(['mollie-billing.cleanup.enabled' => false]);

    $orphan = TestBillable::create([
        'name' => 'Disabled Co',
        'email' => 'disabled@example.test',
    ]);
    $orphan->forceFill([
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ])->save();

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($orphan->getKey()))->not->toBeNull();
});

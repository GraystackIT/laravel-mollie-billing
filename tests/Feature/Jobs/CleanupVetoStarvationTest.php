<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.cleanup.enabled', true);
    config()->set('mollie-billing.cleanup.threshold_minutes', 60);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(MollieBilling::class))->getProperty('cleanupOrphanedBillableCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function orphanCandidate(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Orphan '.uniqid(),
        'email' => uniqid('or-').'@x.test',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::New,
        'created_at' => BillingTime::nowUtc()->subDay(),
    ])->save();

    return $b->refresh();
}

it('records the app veto and drops the row out of the candidate query', function (): void {
    MollieBilling::cleanupOrphanedBillableUsing(fn (): bool => false);

    $b = orphanCandidate();

    expect(CleanupOrphanedBillablesJob::candidateCount())->toBe(1);

    (new CleanupOrphanedBillablesJob)->handle();

    // Still there — a veto must not delete anything.
    expect($b->fresh())->not->toBeNull();
    expect($b->fresh()->getBillingSubscriptionMeta())
        ->toHaveKey(CleanupOrphanedBillablesJob::VETOED_KEY);

    // …and never re-examined. A veto left no trace, so every run re-polled Mollie for it,
    // and because chunkById always restarts from the lowest id, a backlog of vetoed rows
    // consumed the whole 60s budget before newer orphans were ever reached.
    expect(CleanupOrphanedBillablesJob::candidateCount())->toBe(0);
});

it('keeps unvetoed candidates in the query', function (): void {
    MollieBilling::cleanupOrphanedBillableUsing(fn (): bool => false);

    $vetoed = orphanCandidate();
    (new CleanupOrphanedBillablesJob)->handle();

    $fresh = orphanCandidate();

    $ids = CleanupOrphanedBillablesJob::candidateQuery()->pluck('id')->all();

    expect($ids)->toContain($fresh->getKey());
    expect($ids)->not->toContain($vetoed->getKey());
});

it('re-admits a row whose veto marker was removed', function (): void {
    MollieBilling::cleanupOrphanedBillableUsing(fn (): bool => false);

    $b = orphanCandidate();
    (new CleanupOrphanedBillablesJob)->handle();

    $meta = $b->fresh()->getBillingSubscriptionMeta();
    unset($meta[CleanupOrphanedBillablesJob::VETOED_KEY]);
    $b->forceFill(['subscription_meta' => $meta])->save();

    expect(CleanupOrphanedBillablesJob::candidateCount())->toBe(1);
});

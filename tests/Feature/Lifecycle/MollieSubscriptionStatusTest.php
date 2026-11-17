<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReferenceCleared;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use Illuminate\Support\Facades\Cache;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;

require_once __DIR__.'/Support.php';

/*
 * What the package does with each status Mollie can report for a subscription.
 *
 * `active`, `pending` and `suspended` are subscriptions Mollie still holds — the billable
 * keeps its reference and a charge can still come. `canceled` and `completed` are over:
 * the reference is dropped (and announced), because a billable pointing at a subscription
 * that will never charge again can neither be cancelled nor patched.
 */

beforeEach(function (): void {
    longRunBoot();
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

it('reads every Mollie subscription status the way the lifecycle needs it', function (MollieSubscriptionStatus $status, bool $live): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $cleared = lifecycleListen(MollieSubscriptionReferenceCleared::class);

    $world->intercept = fn (object $request): mixed => $request instanceof GetSubscriptionRequest
        ? (object) ['id' => 'sub_longrun', 'status' => $status->value, 'startDate' => '2027-02-15']
        : null;

    $gate = app(MollieSubscriptionGate::class);
    $snapshot = $gate->snapshot($world->billable->fresh());
    Cache::flush();

    expect($gate->hasLiveMollieSubscription($world->billable->fresh()))->toBe($live);

    if ($live) {
        expect($snapshot?->status)->toBe($status)
            ->and($snapshot->startDate->toDateString())->toBe('2027-02-15')
            ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_longrun')
            ->and($cleared)->toHaveCount(0);

        return;
    }

    expect($snapshot)->toBeNull()
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id')
        ->and($cleared)->toHaveCount(1)
        ->and($cleared[0]->reason)->toBe($status->value);

    // Every value has a label in both languages.
    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect($status->label())->not->toContain('billing::');
    }
})->with([
    'pending' => [MollieSubscriptionStatus::Pending, true],
    'active' => [MollieSubscriptionStatus::Active, true],
    'suspended' => [MollieSubscriptionStatus::Suspended, true],
    'canceled' => [MollieSubscriptionStatus::Canceled, false],
    'completed' => [MollieSubscriptionStatus::Completed, false],
]);

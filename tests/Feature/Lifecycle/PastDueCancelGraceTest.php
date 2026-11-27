<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;

require_once __DIR__.'/Support.php';

/*
 * A cancellation "at period end" keeps the access the customer has, until the end of
 * what was paid for. Past due because the money for this period never arrived — a first
 * charge after the trial that failed, a renewal charged back — there is no access to keep
 * and no paid period to run out: the graceful cancel used to hand out the rest of the
 * period anyway (`nextBillingDate()`, a year on a yearly plan), as access, and a resume on
 * top of it made it an Active paid plan whose first charge was that far away.
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
});

afterEach(fn () => Carbon::setTestNow());

it('gives no grace to a trial whose first charge failed, and nothing to resume', function (): void {
    // Trial from 10 January, 14 days: Mollie's first charge on 24 January fails.
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial);

    $charge = longRunCreateCharge($world, status: 'failed');
    longRunDeliver($world, $charge, '2027-01-24 08:00:00');

    $billable = $world->billable->fresh();
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2027-01-26 09:00:00', 'UTC'));
    $billable->cancelBillingSubscription(false);

    $billable = $world->billable->fresh();

    // Was: 10 February — the mandate capture plus one interval nobody paid for.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->lessThanOrEqualTo(BillingTime::nowUtc()))->toBeTrue()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();

    // Was: Active, first charge on 10 February, dunning keys cleared.
    expect(fn () => $world->billable->fresh()->resubscribeBillingPlan())->toThrow(\RuntimeException::class);
    expect($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
});

it('gives no grace to a period whose payment was charged back', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    longRunRenew($world);

    // The state ChargebackHandler::markPastDue() leaves behind for the 15 February renewal.
    Carbon::setTestNow(Carbon::parse('2027-02-20 09:00:00', 'UTC'));
    $billable = $world->billable->fresh();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => $billable->getBillingSubscriptionMeta() + [
            'past_due_since' => BillingTime::nowUtc()->toIso8601String(),
            'payment_failure' => ['reason' => 'chargeback', 'invoice_id' => 1, 'at' => BillingTime::nowUtc()->toIso8601String()],
        ],
    ])->save();

    $world->billable->fresh()->cancelBillingSubscription(false);

    $billable = $world->billable->fresh();

    // Was: 15 March, the end of the period the bank took the money back for.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->lessThanOrEqualTo(BillingTime::nowUtc()))->toBeTrue()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
    expect(fn () => $world->billable->fresh()->resubscribeBillingPlan())->toThrow(\RuntimeException::class);
});

it('still runs a paid period out when the subscription is past due only for an overage debt', function (): void {
    // The customer has paid the plan and keeps access while the debt stands — that access
    // is what a graceful cancel keeps, to the end of the paid period.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    $billable = $world->billable->fresh();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => $billable->getBillingSubscriptionMeta() + [
            ChargeUsageOverageDirectly::PAST_DUE_KEY => BillingTime::nowUtc()->toIso8601String(),
        ],
    ])->save();
    expect($world->billable->fresh()->hasAccessibleBillingSubscription())->toBeTrue();

    $world->billable->fresh()->cancelBillingSubscription(false);

    $billable = $world->billable->fresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe('2027-02-15')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue();
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

// ProcessTrialLifecycleJob leaves a lapsed trial alone when a mandate exists, "while its
// first charge settles" — the webhook is said to own both outcomes of that charge. That is
// right for charge day and the SEPA settlement window. It has no upper bound.
//
// `hasAccessibleBillingSubscription()` answers true for Trial unconditionally, and nothing
// else in the package looks at a Trial row again. So whenever the webhook never arrives —
// Mollie produced no payment at all (subscription cancelled or suspended at Mollie, mandate
// revoked: Mollie only calls the webhook for payments), or the `failed` delivery was lost
// past Mollie's retry window — the billable keeps full access to a paid plan indefinitely
// without ever having paid.

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    Notification::fake();
});

function firstChargeGraceBillable(int $daysPastTrialEnd): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial', 'email' => 'tr@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_trial',
        'mollie_mandate_id' => 'mdt_trial',
        // No invoice, no payment_failure marker.
        'trial_ends_at' => BillingTime::nowUtc()->subDays($daysPastTrialEnd),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysPastTrialEnd + 14),
    ])->save();

    return $billable->refresh();
}

it('does not grant access forever to a trial whose first charge never arrived', function (): void {
    $billable = firstChargeGraceBillable(60);

    ProcessTrialLifecycleJob::dispatchSync();

    $billable = $billable->fresh();

    // The same transition a mandate-less lapsed trial takes, dunning clock included —
    // without `past_due_since` the auto-cancel pass would never pick the row up.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->not->toBeNull();
});

it('expires on the first day past the grace window and not before', function (): void {
    $inside = firstChargeGraceBillable(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS - 1);
    $outside = firstChargeGraceBillable(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 1);

    ProcessTrialLifecycleJob::dispatchSync();

    // A SEPA debit that is merely still travelling is left alone.
    expect($inside->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($outside->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

it('words the trial-expired mail by what actually happened', function (bool $mandate, string $key): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial', 'email' => 'mail@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->subDays(20),
        'mollie_mandate_id' => $mandate ? 'mdt_trial' : null,
    ])->save();

    $notification = new \GraystackIT\MollieBilling\Notifications\TrialExpiredNotification($billable->fresh());
    $lines = $notification->toMail($billable)->introLines;

    $date = $billable->fresh()->trial_ends_at->format('Y-m-d');
    expect($lines)->toContain(__('billing::notifications.trial_expired.'.$key, ['date' => $date]))
        ->and(__('billing::notifications.trial_expired.'.$key))->not->toBe('billing::notifications.trial_expired.'.$key);

    app()->setLocale('de');
    expect(__('billing::notifications.trial_expired.'.$key))->not->toBe('billing::notifications.trial_expired.'.$key);
})->with([
    'no payment method was ever added' => [false, 'body'],
    'a mandate exists but the first charge never arrived' => [true, 'body_with_mandate'],
]);

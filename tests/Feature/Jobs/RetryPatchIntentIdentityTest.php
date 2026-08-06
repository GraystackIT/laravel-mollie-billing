<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty('notifyAdminCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function patchIntentBillable(?array $marker = null): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Patch '.uniqid(),
        'email' => uniqid('pi-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $marker === null ? [] : ['pending_subscription_patch' => $marker],
    ])->save();

    return $b->refresh();
}

/**
 * @return array<string, mixed>
 */
function patchIntentData(TestBillable $b, string $newPlan, int $newSeats = 1): array
{
    return [
        'billable_type' => TestBillable::class,
        'billable_id' => $b->getKey(),
        'current_plan' => 'starter',
        'new_plan' => $newPlan,
        'current_interval' => 'monthly',
        'new_interval' => 'monthly',
        'current_seats' => 1,
        'new_seats' => $newSeats,
        'current_addons' => [],
        'new_addons' => [],
        'force_reset_start_date' => false,
    ];
}

/** Patcher double: the PATCH itself is not what these tests are about. */
function bindRecordingPatcher(): object
{
    $patcher = new class extends MollieSubscriptionPatcher
    {
        /** @var list<string> */
        public array $patched = [];

        public function __construct() {}

        public function updateForIntent(\GraystackIT\MollieBilling\Contracts\Billable $billable, PlanChangeIntent $intent): void
        {
            $this->patched[] = $intent->newPlan;
        }
    };

    app()->instance(MollieSubscriptionPatcher::class, $patcher);

    return $patcher;
}

it('keys uniqueness on the intent, so two pending PATCHes cannot collapse into one chain', function (): void {
    $b = patchIntentBillable();

    $a = new RetrySubscriptionPatchJob(patchIntentData($b, 'pro'), BillingTime::nowUtc()->toIso8601String());
    $again = new RetrySubscriptionPatchJob(patchIntentData($b, 'pro'), BillingTime::nowUtc()->subHour()->toIso8601String());
    $other = new RetrySubscriptionPatchJob(patchIntentData($b, 'enterprise'), BillingTime::nowUtc()->toIso8601String());
    $seats = new RetrySubscriptionPatchJob(patchIntentData($b, 'pro', 4), BillingTime::nowUtc()->toIso8601String());

    // Same intent → same chain, whatever the first-attempt stamp says.
    expect($a->uniqueId())->toBe($again->uniqueId());

    // Different target amount → its own chain. Keyed on the billable alone, plan
    // change B's dispatch was silently dropped while chain A held the lock.
    expect($a->uniqueId())->not->toBe($other->uniqueId());
    expect($a->uniqueId())->not->toBe($seats->uniqueId());
});

it('leaves a newer intent marker in place instead of deleting it after its own PATCH', function (): void {
    $b = patchIntentBillable();
    $patcher = bindRecordingPatcher();

    $intentA = patchIntentData($b, 'pro');
    $intentB = patchIntentData($b, 'enterprise');

    // Mollie recovered while chain A was backing off, but by then the customer had
    // changed plan again and queuePatchRetry() had overwritten the marker with B.
    $b->forceFill(['subscription_meta' => [
        'pending_subscription_patch' => [
            'intent' => $intentB,
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            'last_error' => 'Mollie 503',
        ],
    ]])->save();

    (new RetrySubscriptionPatchJob($intentA, BillingTime::nowUtc()->subMinutes(5)->toIso8601String()))
        ->handle($patcher);

    expect($patcher->patched)->toBe(['pro']);

    // B's marker must survive: it is the only record that Mollie still charges the
    // pre-B amount, and the sweep is the only thing left that can retry it.
    $marker = $b->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;
    expect($marker)->not->toBeNull();
    expect($marker['intent']['new_plan'])->toBe('enterprise');
});

it('clears the marker it does own', function (): void {
    $b = patchIntentBillable();
    $patcher = bindRecordingPatcher();

    $intent = patchIntentData($b, 'pro');

    $b->forceFill(['subscription_meta' => [
        'pending_subscription_patch' => [
            'intent' => $intent,
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            'last_error' => 'Mollie 503',
        ],
    ]])->save();

    (new RetrySubscriptionPatchJob($intent, BillingTime::nowUtc()->subMinutes(5)->toIso8601String()))
        ->handle($patcher);

    expect($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch');
});

it('still notifies the admins when giving up, without dropping a newer intent marker', function (): void {
    Notification::fake();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [new AnonymousNotifiable]);

    $b = patchIntentBillable();
    $patcher = bindRecordingPatcher();

    $b->forceFill(['subscription_meta' => [
        'pending_subscription_patch' => [
            'intent' => patchIntentData($b, 'enterprise'),
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
        ],
    ]])->save();

    // Past the 24h hard limit for intent A.
    (new RetrySubscriptionPatchJob(
        patchIntentData($b, 'pro'),
        BillingTime::nowUtc()->subHours(30)->toIso8601String(),
    ))->handle($patcher);

    expect($patcher->patched)->toBe([]);
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);

    expect($b->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch']['intent']['new_plan'] ?? null)
        ->toBe('enterprise');
});

it('treats the flat coupon-expiry marker as one identity across sweeps', function (): void {
    $b = patchIntentBillable();

    $flat = [
        'reason' => 'recurring_coupon_expiry_reset',
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addons' => [],
        'extra_seats' => 0,
        'billable_type' => TestBillable::class,
        'billable_id' => $b->getKey(),
    ];

    // The sweep re-dispatches the same marker every hour; a changed `last_error` must
    // not fork a second chain for what is the same reset.
    $first = new RetrySubscriptionPatchJob(
        $flat + ['first_attempt_at' => '2026-01-01T00:00:00+00:00', 'last_error' => 'Mollie 503'],
        '2026-01-01T00:00:00+00:00',
    );
    $second = new RetrySubscriptionPatchJob(
        $flat + ['first_attempt_at' => '2026-01-01T01:00:00+00:00', 'last_error' => 'Mollie 504'],
        '2026-01-01T01:00:00+00:00',
    );

    expect($first->uniqueId())->toBe($second->uniqueId());
});

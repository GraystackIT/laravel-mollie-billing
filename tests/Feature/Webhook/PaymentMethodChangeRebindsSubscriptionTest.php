<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Services\Webhook\MandateOnlyPaymentHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A change of payment method moves the running subscription onto the new mandate before
 * the old one is revoked.
 *
 * `CreateSubscription` binds the Mollie subscription to the mandate of its checkout (on
 * purpose: see its comment). The billing-data page's "change payment method" captures a
 * new mandate, `MandateUpdated` → `RevokePreviousMandate` revoked the old one — and nothing
 * moved the subscription. Mollie cancels the subscriptions of a revoked mandate, or cannot
 * charge them: no renewal arrives (and no webhook says so), or the next charge fails and a
 * customer who had just supplied a working payment method is sent into dunning.
 */
function rebindSubscriber(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Card Swap Co', 'email' => uniqid('swap-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_swap',
        'mollie_mandate_id' => 'mdt_old',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_swap', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

/** The €0 authorisation of a payment-method change, as the webhook fetched it. */
function paymentMethodChange(TestBillable $billable): object
{
    return (object) [
        'id' => 'tr_swap',
        'status' => 'paid',
        'customerId' => 'cst_swap',
        'mandateId' => 'mdt_new',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => (object) [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
}

function rebindRequestProperty(object $request, string $name): mixed
{
    $property = (new ReflectionObject($request))->getProperty($name);
    $property->setAccessible(true);

    return $property->getValue($request);
}

it('moves the running subscription onto the new mandate before the old one is revoked', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();

    $patches = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$patches) {
        if ($request instanceof UpdateSubscriptionRequest) {
            $patches[] = $request;
        }

        return (object) ['id' => 'sub_swap', 'status' => 'active'];
    });

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable, ['type' => 'mandate_only']);

    expect($patches)->toHaveCount(1)
        ->and(rebindRequestProperty($patches[0], 'subscriptionId'))->toBe('sub_swap')
        ->and(rebindRequestProperty($patches[0], 'mandateId'))->toBe('mdt_new')
        // A mandate PATCH never sends a start date: Mollie refuses one on a charged subscription.
        ->and(rebindRequestProperty($patches[0], 'startDate'))->toBeNull()
        ->and($billable->fresh()->mollie_mandate_id)->toBe('mdt_new');

    Bus::assertDispatched(RevokeMollieMandateJob::class, fn ($job): bool => $job->mandateId === 'mdt_old');
});

it('keeps the old mandate when the subscription could not be moved off it', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof UpdateSubscriptionRequest) {
            throw new RuntimeException('Mollie is unavailable');
        }

        return (object) [];
    });

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable, ['type' => 'mandate_only']);

    // A subscription still bound to a mandate that is revoked is cancelled or uncollectable;
    // an old mandate left valid next to the new one costs nothing.
    Bus::assertNotDispatched(RevokeMollieMandateJob::class);
    expect($billable->fresh()->mollie_mandate_id)->toBe('mdt_new');
});

it('revokes the old mandate at once when no subscription is running on it', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_meta' => [],
    ])->save();

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes) {
        $classes[] = $request::class;

        return (object) [];
    });

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable->fresh(), ['type' => 'mandate_only']);

    expect($classes)->not->toContain(UpdateSubscriptionRequest::class);
    Bus::assertDispatched(RevokeMollieMandateJob::class, fn ($job): bool => $job->mandateId === 'mdt_old');
});

/*
 * An unpaid renewal is not an ended subscription. Past due without access (the renewal
 * failed, not only overage owed) is exactly when the customer is told to review their
 * payment method — and Mollie still runs (or has suspended) the subscription on the old
 * mandate. Revoking it at once cost them the subscription they were trying to save.
 */
it('moves a past-due subscription Mollie still runs onto the new mandate before the revoke', function (string $status): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_swap',
            'seat_count' => 1,
            'past_due_since' => now()->subDays(2)->toIso8601String(),
        ],
    ])->save();
    $billable = $billable->fresh();
    expect($billable->hasAccessibleBillingSubscription())->toBeFalse();

    $patches = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$patches, $status) {
        if ($request instanceof UpdateSubscriptionRequest) {
            $patches[] = $request;
        }

        return (object) ['id' => 'sub_swap', 'status' => $status];
    });

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable, ['type' => 'mandate_only']);

    expect($patches)->toHaveCount(1)
        ->and(rebindRequestProperty($patches[0], 'subscriptionId'))->toBe('sub_swap')
        ->and(rebindRequestProperty($patches[0], 'mandateId'))->toBe('mdt_new');

    Bus::assertDispatched(RevokeMollieMandateJob::class, fn ($job): bool => $job->mandateId === 'mdt_old');
})->with(['active', 'suspended']);

it('revokes at once when the past-due subscription is over at Mollie', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_swap',
            'seat_count' => 1,
            'past_due_since' => now()->subDays(2)->toIso8601String(),
        ],
    ])->save();

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes) {
        $classes[] = $request::class;

        return (object) ['id' => 'sub_swap', 'status' => 'canceled'];
    });

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable->fresh(), ['type' => 'mandate_only']);

    expect($classes)->not->toContain(UpdateSubscriptionRequest::class);
    Bus::assertDispatched(RevokeMollieMandateJob::class, fn ($job): bool => $job->mandateId === 'mdt_old');
});

it('keeps the old mandate of a past-due subscription when Mollie cannot be asked', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);
    $billable = rebindSubscriber();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_swap',
            'seat_count' => 1,
            'past_due_since' => now()->subDays(2)->toIso8601String(),
        ],
    ])->save();

    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie is unavailable'));

    app(MandateOnlyPaymentHandler::class)->paid(paymentMethodChange($billable), $billable->fresh(), ['type' => 'mandate_only']);

    // A failed lookup is no answer: the subscription may still run on the old mandate.
    Bus::assertNotDispatched(RevokeMollieMandateJob::class);
    expect($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_swap');
});

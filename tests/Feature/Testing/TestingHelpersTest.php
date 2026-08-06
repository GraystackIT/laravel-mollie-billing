<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\BillableStateHelper;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

afterEach(function (): void {
    MollieBilling::resetFake();
});

function helperBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Helper '.uniqid(),
        'email' => uniqid('hp-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ], $overrides))->save();

    return $b->refresh();
}

it('expireTrial produces the state the trial job actually produces', function (): void {
    $b = helperBillable(['subscription_status' => SubscriptionStatus::Trial]);

    BillableStateHelper::expireTrial($b);

    // ProcessTrialLifecycleJob::runExpiryPass() sets PastDue; Expired is only ever
    // reached from Cancelled, so a test built on Expired asserted an impossible state.
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
    expect($b->fresh()->subscription_status)->not->toBe(SubscriptionStatus::Expired);
});

it('expireTrial matches what the job does to the same row', function (): void {
    $withoutMandate = helperBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    ProcessTrialLifecycleJob::dispatchSync();

    $viaHelper = helperBillable(['subscription_status' => SubscriptionStatus::Trial]);
    BillableStateHelper::expireTrial($viaHelper);

    expect($viaHelper->fresh()->subscription_status)
        ->toBe($withoutMandate->fresh()->subscription_status);
});

it('the overage simulation puts next billing inside the window the job charges', function (): void {
    $b = helperBillable([
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth(),
    ]);

    $sim = new LifecycleSimulator;
    $ref = new ReflectionMethod($sim, 'force');
    $ref->setAccessible(true);

    // Reproduce just the period rewrite the flow performs.
    $ref->invoke($sim, $b, [
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth()->addDay(),
    ]);

    $next = $b->refresh()->nextBillingDate();

    $tomorrowStart = BillingTime::nowUtc()->addDay()->startOfDay();
    $tomorrowEnd = BillingTime::nowUtc()->addDay()->endOfDay();

    // Pass 1 Case A requires exactly this; `subMonth()->addDay()->subDay()` cancelled
    // out to `subMonth()`, which put nextBillingDate() on *today*.
    expect($next?->betweenIncluded($tomorrowStart, $tomorrowEnd))->toBeTrue();
});

it('the overage simulation handles yearly billables too', function (): void {
    $b = helperBillable(['subscription_interval' => SubscriptionInterval::Yearly]);

    $sim = new LifecycleSimulator;
    $ref = new ReflectionMethod($sim, 'force');
    $ref->setAccessible(true);
    $ref->invoke($sim, $b, [
        'subscription_period_starts_at' => BillingTime::nowUtc()->subYear()->addDay(),
    ]);

    $next = $b->refresh()->nextBillingDate();

    expect($next?->betweenIncluded(
        BillingTime::nowUtc()->addDay()->startOfDay(),
        BillingTime::nowUtc()->addDay()->endOfDay(),
    ))->toBeTrue();
});

it('the renewal simulation refuses a billable without a mollie subscription id', function (): void {
    $b = helperBillable([
        'mollie_customer_id' => 'cst_r',
        'mollie_mandate_id' => 'mdt_r',
        'subscription_meta' => [],
    ]);

    // Previously it charged the sandbox customer and then routed the payment to
    // FirstPaymentHandler, which returned immediately — money moved, nothing booked.
    expect(fn () => (new LifecycleSimulator)->renewal($b))
        ->toThrow(RuntimeException::class, 'mollie_subscription_id');
});

it('the fake swaps in Mollie mock client so no checkout reaches the network', function (): void {
    MollieBilling::fake();

    expect(Mollie::getFacadeRoot())->toBeInstanceOf(MockMollieClient::class);
});

it('the fake honours ipReturns', function (): void {
    $fake = MollieBilling::fake();

    $fake->ipReturns('RU');

    // Previously $ipOverride was stored and never read: the manager stayed on
    // NullDriver, so "blocked from RU" passed only because the country was null.
    expect(MollieBilling::ipGeolocation()->getCountry('93.180.0.1'))->toBe('RU');
});

it('the fake honours registered VAT numbers through the real VAT service', function (): void {
    $fake = MollieBilling::fake();

    $fake->vatNumberValidates('ATU12345678');

    $vat = app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class);

    expect($vat->validateVatNumber('ATU12345678'))->toBeTrue();
    expect($vat->validateVatNumber('ATU99999999'))->toBeFalse();
});

it('the fake exposes the same allow-list through vies()', function (): void {
    $fake = MollieBilling::fake();

    $fake->vies()->validates('DE123456789');

    expect($fake->validVatNumbers())->toContain('DE123456789');

    $vat = app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class);
    expect($vat->validateVatNumber('DE123456789'))->toBeTrue();
});

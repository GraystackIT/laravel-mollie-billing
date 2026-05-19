<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Mockery as M;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Api\Http\Response as MollieResponse;
use Mollie\Laravel\Facades\Mollie;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\StreamInterface as PsrStream;

function makePastDueWithMollieSub(string $subId = 'sub_test_123', string $customerId = 'cst_test_123'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'mollie_customer_id' => $customerId,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => $subId, 'past_due_since' => now()->toIso8601String()],
    ])->save();

    return $billable->refresh();
}

beforeEach(function (): void {
    Cache::flush();
});

it('returns null snapshot when there is no Mollie subscription id', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'No-Sub', 'email' => 'no@example.test']);

    expect(app(MollieSubscriptionGate::class)->snapshot($billable))->toBeNull();
});

it('detects a live Mollie subscription and reports hasLiveMollieSubscription = true', function (): void {
    $billable = makePastDueWithMollieSub('sub_live_1');

    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) {
        expect($request)->toBeInstanceOf(GetSubscriptionRequest::class);
        return (object) [
            'id' => 'sub_live_1',
            'status' => 'active',
            'startDate' => now('UTC')->addDays(15)->toDateString(),
        ];
    });

    $gate = app(MollieSubscriptionGate::class);

    expect($gate->hasLiveMollieSubscription($billable))->toBeTrue();

    $snapshot = $gate->snapshot($billable);
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->status->value)->toBe('active')
        ->and($snapshot->startDate)->not->toBeNull()
        ->and($snapshot->startDate->isFuture())->toBeTrue();
});

it('clears stale mollie_subscription_id when Mollie reports canceled', function (): void {
    $billable = makePastDueWithMollieSub('sub_canceled');

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_canceled',
        'status' => 'canceled',
    ]);

    $gate = app(MollieSubscriptionGate::class);

    expect($gate->hasLiveMollieSubscription($billable))->toBeFalse();

    $billable->refresh();
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');
});

it('clears stale mollie_subscription_id on 404 from Mollie', function (): void {
    $billable = makePastDueWithMollieSub('sub_gone');

    $stream = M::mock(PsrStream::class);
    $stream->shouldReceive('__toString')->andReturn('');
    $psrRequest = M::mock(PsrRequest::class);
    $psrRequest->shouldReceive('getBody')->andReturn($stream);
    $response = M::mock(MollieResponse::class);
    $response->shouldReceive('json')->andReturn((object) []);
    $response->shouldReceive('getPsrRequest')->andReturn($psrRequest);

    Mollie::shouldReceive('send')->once()->andThrow(new ApiException($response, 'Not found', 404));

    $gate = app(MollieSubscriptionGate::class);

    expect($gate->hasLiveMollieSubscription($billable))->toBeFalse();

    $billable->refresh();
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');
});

it('caches the snapshot for 60 seconds to avoid repeated Mollie calls', function (): void {
    $billable = makePastDueWithMollieSub('sub_cached');

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_cached',
        'status' => 'active',
        'startDate' => now('UTC')->addDay()->toDateString(),
    ]);

    $gate = app(MollieSubscriptionGate::class);

    $gate->snapshot($billable);
    $gate->snapshot($billable);
    $gate->snapshot($billable);
});

it('forceImmediateCharge sends an UpdateSubscriptionRequest with startDate today and busts the cache', function (): void {
    $billable = makePastDueWithMollieSub('sub_force');

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_force',
        'status' => 'active',
        'startDate' => now('UTC')->addMonth()->toDateString(),
    ]);

    $gate = app(MollieSubscriptionGate::class);
    $gate->snapshot($billable);

    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) {
        expect($request)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class);
        return (object) ['id' => 'sub_force', 'status' => 'active'];
    });

    expect($gate->forceImmediateCharge($billable))->toBeTrue();

    // Cache busted: next snapshot triggers another GET.
    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_force',
        'status' => 'active',
        'startDate' => now('UTC')->toDateString(),
    ]);
    $gate->snapshot($billable);
});

it('redirects checkout to dashboard when local PastDue but Mollie sub is still active', function (): void {
    $billable = makePastDueWithMollieSub('sub_gate_redirect');

    MollieBilling::resolveBillableUsing(fn () => $billable);

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_gate_redirect',
        'status' => 'active',
        'startDate' => now('UTC')->addDays(5)->toDateString(),
    ]);

    $response = $this->get(route(BillingRoute::checkout()));

    $response->assertRedirect(route(BillingRoute::name('index')));
});

it('lets the checkout gate clear a stale mollie_subscription_id when Mollie reports canceled', function (): void {
    $billable = makePastDueWithMollieSub('sub_gate_canceled');

    Mollie::shouldReceive('send')->once()->andReturn((object) [
        'id' => 'sub_gate_canceled',
        'status' => 'canceled',
    ]);

    // Gate-Aufruf direkt — er liefert false und räumt die ID auf. Die volle
    // Render-Pfad-Integration wird vom Redirect-Test oben abgedeckt; dieser
    // Test fokussiert auf den Cleanup-Pfad ohne View-Rendering.
    $gate = app(MollieSubscriptionGate::class);
    expect($gate->hasLiveMollieSubscription($billable))->toBeFalse();

    $billable->refresh();
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');
});

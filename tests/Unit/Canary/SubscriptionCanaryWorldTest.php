<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\SubscriptionCanaryWorld;

/**
 * The subscription canary's reading of its world (tests/SandboxBrowser/Support/SubscriptionCanaryWorld):
 * when it is dead and has to be rebuilt, and how a `times` subscription is allowed to end.
 */
function subscriptionCanaryLiving(): array
{
    return [
        'daily' => ['id' => 'sub_d', 'times' => 10, 'status' => 'active', 'charges' => 3],
        'monthly' => ['id' => 'sub_m', 'times' => null, 'status' => 'active', 'charges' => 1],
        'pinned' => ['id' => 'sub_p', 'times' => null, 'status' => 'active', 'charges' => 1],
    ];
}

it('finds a living world alive', function (): void {
    expect(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'valid', 'http' => null], subscriptionCanaryLiving()))->toBe([])
        // a mandate still pending is not dead — Mollie may yet validate it
        ->and(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'pending'], subscriptionCanaryLiving()))->toBe([]);
});

it('finds the world of 15 November 2026 dead: its mandate answered 410 and its subscriptions went pending', function (): void {
    $subscriptions = [
        'daily' => ['id' => 'sub_d', 'times' => 10, 'status' => 'canceled', 'charges' => 10],
        'monthly' => ['id' => 'sub_m', 'times' => null, 'status' => 'pending', 'charges' => 1],
        'month_end' => ['id' => 'sub_e', 'times' => null, 'status' => 'pending', 'charges' => 0],
        'pinned' => ['id' => 'sub_p', 'times' => null, 'status' => 'pending', 'charges' => 1],
    ];

    expect(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => null, 'http' => 410], $subscriptions))
        ->toBe(['mandate mdt_1 is gone (410)']);
});

it('finds the world dead when the mandate is invalid or 404', function (): void {
    expect(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'invalid'], []))->toBe(['mandate mdt_1 is invalid'])
        ->and(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'http' => 404], []))->toBe(['mandate mdt_1 is gone (404)']);
});

it('finds the world dead when an open-ended subscription ended or is gone', function (): void {
    $subscriptions = subscriptionCanaryLiving();
    $subscriptions['monthly']['status'] = 'canceled';
    $subscriptions['pinned'] = ['id' => 'sub_p', 'times' => null, 'http' => 410];

    expect(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'valid'], $subscriptions))->toBe([
        'subscription monthly (sub_m) is canceled',
        'subscription pinned (sub_p) is gone (410)',
    ]);
});

it('lets the times subscription end after its last charge, but not before', function (): void {
    $ended = subscriptionCanaryLiving();
    $ended['daily'] = ['id' => 'sub_d', 'times' => 10, 'status' => 'canceled', 'charges' => 10];

    $early = subscriptionCanaryLiving();
    $early['daily'] = ['id' => 'sub_d', 'times' => 10, 'status' => 'suspended', 'charges' => 4];

    expect(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'valid'], $ended))->toBe([])
        ->and(SubscriptionCanaryWorld::deathReasons(['id' => 'mdt_1', 'status' => 'valid'], $early))
        ->toBe(['subscription daily (sub_d) is suspended after 4 of 10 charges']);
});

it('takes canceled at the last charge as the end of a times subscription — measured 25 November 2026', function (): void {
    expect(SubscriptionCanaryWorld::timesEnd('daily', 10, 10, 'canceled', '2026-11-25T00:16:33+00:00', '2026-11-25T00:16:33+00:00'))
        ->toBe(['ended' => 'canceled at its last charge', 'problem' => null])
        ->and(SubscriptionCanaryWorld::timesEnd('daily', 10, 10, 'completed', null, '2026-11-25T00:16:33+00:00'))
        ->toBe(['ended' => 'completed', 'problem' => null])
        ->and(SubscriptionCanaryWorld::timesEnd('daily', 10, 10, 'canceled', '2026-11-26T09:00:00+00:00', '2026-11-25T00:16:33+00:00'))
        ->toBe(['ended' => 'canceled at 2026-11-26T09:00:00+00:00', 'problem' => null]);
});

it('reports a times subscription that goes on charging', function (): void {
    expect(SubscriptionCanaryWorld::timesEnd('daily', 10, 10, 'active', null, '2026-11-25T00:16:33+00:00'))
        ->toBe(['ended' => null, 'problem' => 'daily: 10 charges made and the subscription is active, not ended'])
        ->and(SubscriptionCanaryWorld::timesEnd('daily', 10, 11, 'canceled', null, null))
        ->toBe(['ended' => null, 'problem' => 'daily: 11 charges made, the subscription was created with times 10'])
        ->and(SubscriptionCanaryWorld::timesEnd('daily', 10, 4, 'active', null, null))
        ->toBe(['ended' => null, 'problem' => null]);
});

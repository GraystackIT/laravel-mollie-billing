<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * `plan_change_failed_at` and `plan_change_unfinished` describe the last attempt at a
 * change, and the plan and addon screens read them as "how did the change I am watching
 * end" — the plan page's toast and callout are decided off `planChangeFailed` /
 * `planChangeUnfinished`, the addon page's off the stamp itself (the toasts are Flux's,
 * which the feature suite cannot see). Nothing ever took the markers off: a change that went through after one whose charge was
 * declined was announced as "the payment failed, please try again" — the customer had
 * paid, the change was in force, and the screen invited a third attempt. Accepting a new
 * change now supersedes the outcome of the previous one.
 */

beforeEach(function (): void {
    World::boot();
});

function staleOutcomeScreen(string $screen, TestBillable $billable): Testable
{
    // Captured by value: the resolver outlives this test.
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    return Livewire::test('mollie-billing::'.$screen);
}

it('announces a paid upgrade as done, not as the declined attempt before it', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    // The first attempt: the prorata charge is declined.
    World::change($billable, ['plan_code' => 'enterprise'], 'failed');
    expect($billable->refresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->not->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');

    // Ten minutes later the customer follows the advice and tries again.
    test()->travel(10)->minutes();
    World::change($billable, ['plan_code' => 'enterprise'], null);
    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->not->toBeNull();

    $screen = staleOutcomeScreen('plan-change', $billable);
    expect($screen->get('wasPending'))->toBeTrue();

    World::settle($billable, 'paid');
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise');

    // The next poll.
    $screen->call('$refresh');

    // What the toast (failed → danger, unfinished → warning, else "plan changed") and the
    // callout are decided off, at the render that sees the change resolved.
    expect($screen->get('wasPending'))->toBeFalse()
        ->and($screen->viewData('planChangeFailed'))->toBeNull()
        ->and($screen->viewData('planChangeUnfinished'))->toBeNull();
});

it('still announces a declined retry as declined', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    World::change($billable, ['plan_code' => 'enterprise'], 'failed');
    test()->travel(10)->minutes();
    World::change($billable, ['plan_code' => 'enterprise'], null);

    $screen = staleOutcomeScreen('plan-change', $billable);
    World::settle($billable, 'failed');
    $screen->call('$refresh');

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($screen->viewData('planChangeFailed'))->not->toBeNull();
});

it('does not name a later change unfinished because an earlier one was given up', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    // An earlier change the clean-up gave up with its payment collected.
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['plan_change_unfinished'] = ['at' => BillingTime::nowUtc()->subDay()->toIso8601String(), 'payment' => 'paid'];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    World::change($billable, ['plan_code' => 'enterprise'], null);

    $screen = staleOutcomeScreen('plan-change', $billable);
    World::settle($billable, 'paid');
    $screen->call('$refresh');

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($screen->viewData('planChangeUnfinished'))->toBeNull()
        ->and($screen->viewData('planChangeFailed'))->toBeNull();
});

it('announces a paid addon as enabled, not as the declined attempt before it', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    $addons = staleOutcomeScreen('addons', $billable);
    $addons->call('enableAddon', 'storage');
    World::settle($billable, 'failed');
    $addons->call('pollForChange');
    expect($billable->refresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->not->toBeNull();

    test()->travel(5)->minutes();

    $addons = staleOutcomeScreen('addons', $billable);
    $addons->call('enableAddon', 'storage');
    World::settle($billable, 'paid');
    $addons->call('pollForChange');

    // pollForChange() toasts "payment failed" whenever this stamp is under 30 minutes old.
    expect($addons->get('polling'))->toBeFalse()
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toContain('storage')
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->toBeNull();
});

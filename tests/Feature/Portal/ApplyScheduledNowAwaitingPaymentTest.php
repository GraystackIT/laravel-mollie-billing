<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Livewire;

/*
 * "Apply now" on a scheduled upgrade, mid-period: the upgrade carries a prorata charge and
 * waits for it like any immediate upgrade — the plan is not switched. The screen checked
 * only that the scheduled change was gone and announced "Plan changed." The customer read
 * that their plan had changed while the page beside the toast said the upgrade was waiting
 * for its payment; when that payment was then declined the same page said it had failed.
 * An upgrade booked on the plan screen directly says nothing at that point and lets the
 * waiting notice speak; "Apply now" now does the same.
 *
 * The toast is Flux's, and the feature suite runs without Flux (its provider is not booted,
 * so `\Flux` does not exist and `InteractsWithFluxUi::toast()` does nothing). These tests put
 * a recorder in Flux's place — `toast()` and `modal()->close()`, the only two calls the
 * package makes — instead of booting Flux: Flux's real facade would outlive this test's
 * application through its global alias and fail every later test in the process.
 * tests/Browser/ChangesInFlightTest drives the same in Chrome against the real Flux.
 */

final class ApplyNowFluxToastRecorder
{
    /** @var list<array{text: string, variant: ?string}> */
    public static array $toasts = [];

    public static function toast(string $text, mixed ...$options): void
    {
        self::$toasts[] = ['text' => $text, 'variant' => $options['variant'] ?? null];
    }

    public static function modal(string $name): object
    {
        return new class
        {
            public function close(): void {}

            public function show(): void {}
        };
    }
}

beforeEach(function (): void {
    World::boot();

    if (! class_exists(\Flux::class)) {
        class_alias(ApplyNowFluxToastRecorder::class, 'Flux');
    }

    ApplyNowFluxToastRecorder::$toasts = [];
});

function applyNowAwaitingPaymentScreen(TestBillable $billable): \Livewire\Features\SupportTesting\Testable
{
    // Captured by value: the resolver outlives this test.
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    return Livewire::test('mollie-billing::plan-change');
}

/** @return list<string> */
function applyNowToastTexts(): array
{
    return array_column(ApplyNowFluxToastRecorder::$toasts, 'text');
}

it('does not announce an upgrade applied now as changed while it waits for its payment', function (): void {
    $billable = World::subscription('none', 'starter', 2, []);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    $screen = applyNowAwaitingPaymentScreen($billable->refresh());
    $screen->call('applyScheduledNow');

    $billable->refresh();

    // The change waits for its charge; nothing is switched yet.
    expect($billable->getBillingSubscriptionMeta()['pending_plan_change']['plan_code'] ?? null)->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($screen->viewData('pendingPlanChange')['plan_code'] ?? null)->toBe('pro')
        // The poll that sees the charge settle is the one that announces the outcome.
        ->and($screen->get('wasPending'))->toBeTrue();

    expect(applyNowToastTexts())
        ->not->toContain(__('billing::portal.flash.plan_changed'))
        ->not->toContain(__('billing::portal.flash.scheduled_change_not_yet'))
        ->not->toContain(__('billing::portal.flash.error'));

    // The charge clears; the next poll announces the change — once.
    World::settle($billable, 'paid');
    $screen->call('$refresh');

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(applyNowToastTexts())->toBe([__('billing::portal.flash.plan_changed')]);
});

it('still announces a scheduled change applied now that needs no payment as changed', function (): void {
    // Pro → Free mid-period: nothing to collect, applied at once.
    $billable = World::subscription('none', 'pro', 2, []);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'free', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    $screen = applyNowAwaitingPaymentScreen($billable->refresh());
    $screen->call('applyScheduledNow');

    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('free')
        ->and($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->toBeNull()
        ->and(applyNowToastTexts())->toBe([__('billing::portal.flash.plan_changed')]);
});

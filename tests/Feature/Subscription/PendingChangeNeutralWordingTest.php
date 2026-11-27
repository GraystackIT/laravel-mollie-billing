<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Livewire;

/*
 * A plan change that waits for its payment is not always an upgrade.
 *
 * Any switch whose pro-rata charge exceeds the credit for the rest of the period waits for
 * that charge — Pro → Starter with eight extra seats is a downgrade (Starter's base price
 * is below Pro's, `isUpgrade` is false) and still costs more than the Pro month it
 * replaces. The plan screen announced every one of them as "An upgrade to the Starter plan is awaiting payment confirmation", and the toast
 * for a declined charge (shared with the add-on screen) as "Your plan upgrade could not be
 * completed". Decision 7 of 26 November 2026: neutral wording, "The change to :plan is
 * awaiting your payment".
 */
beforeEach(function (): void {
    World::boot();
});

function neutralWordingPlanScreen(TestBillable $billable): \Livewire\Features\SupportTesting\Testable
{
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    return Livewire::test('mollie-billing::plan-change');
}

it('announces a downgrade that waits for its payment as a change, not as an upgrade', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    $billable = World::subscription('none', 'pro', 2);

    // Pro → Starter with 10 seats: a downgrade by base price (20 € against 50 €), and a
    // charge the change has to wait for (20 € + 8 × 5 € against the rest of the Pro month).
    $change = World::change($billable, ['plan_code' => 'starter', 'seats' => 10], settle: null);

    expect($change['preview']['isUpgrade'])->toBeFalse()
        ->and($change['preview']['isDowngrade'])->toBeTrue()
        ->and($change['charged'])->not->toBeNull()
        ->and($billable->refresh()->getBillingSubscriptionMeta()['pending_plan_change']['plan_code'] ?? null)->toBe('starter');

    neutralWordingPlanScreen($billable)
        ->assertSee($expected)
        ->assertDontSee('Upgrade auf den Starter-Plan')
        ->assertDontSee('upgrade to the Starter plan');
})->with([
    'en' => ['en', 'The change to Starter is awaiting your payment.'],
    'de' => ['de', 'Der Wechsel zu Starter wartet auf deine Zahlung.'],
]);

it('reports a declined charge for that change without calling it an upgrade', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'starter', 'seats' => 10], settle: 'failed');

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro');

    neutralWordingPlanScreen($billable)->assertSee($expected);
})->with([
    'en' => ['en', 'Your change could not be completed because the payment failed.'],
    'de' => ['de', 'Deine Änderung konnte nicht durchgeführt werden, da die Zahlung fehlgeschlagen ist.'],
]);

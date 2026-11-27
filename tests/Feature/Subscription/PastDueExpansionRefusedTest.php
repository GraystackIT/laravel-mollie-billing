<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\ValidateSubscriptionChange;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Livewire;

/**
 * While a subscription is past due, seats and paid addons cannot be ADDED.
 *
 * The period has not been paid and its end has passed, so an addition there prorates with
 * a factor of 0: it used to be accepted, free for the unpaid period, with Mollie PATCHed
 * to the higher amount. The customer settles the open payment first. Shrinking, a free
 * addon, and a plan or interval change (the past-due reset: a new, fully charged period)
 * stay possible.
 */
beforeEach(fn () => World::boot());

/** Pro, three seats (one in use), storage — and the renewal of 1 Feb has failed. */
function pastDueExpansionBillable(): TestBillable
{
    return World::failRenewal(World::subscription('none', 'pro', 3, ['storage'], used: 1));
}

dataset('past-due expansions', [
    'more seats' => [['seats' => 4]],
    'a paid addon' => [['addons' => ['storage', 'sso']]],
    'a per-seat addon' => [['addons' => ['storage', 'crm']]],
    'seats and an addon' => [['seats' => 5, 'addons' => ['storage', 'sso']]],
]);

it('refuses the expansion, immediately and scheduled, and changes nothing', function (array $payload, string $applyAt) {
    $billable = pastDueExpansionBillable();
    $meta = $billable->getBillingSubscriptionMeta();
    $held = World::$heldValue;

    expect(fn () => app(UpdateSubscription::class)->update($billable, $payload + ['apply_at' => $applyAt]))
        ->toThrow(PastDueExpansionNotAllowedException::class, __('billing::portal.error_past_due_expansion'));

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->toBe($meta)
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage'])
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and(World::$payments)->toBe([])
        ->and(World::$heldValue)->toBe($held);
})->with('past-due expansions')->with(['immediate', 'end_of_period']);

it('reports the refusal in the preview as a blocking error', function (array $payload) {
    $billable = pastDueExpansionBillable();

    $errors = app(PreviewService::class)->previewUpdate($billable, $payload)['errors'];

    expect(array_column($errors, 'type'))->toContain('past_due_expansion')
        ->and(collect($errors)->firstWhere('type', 'past_due_expansion')['message'])->toBe(__('billing::portal.error_past_due_expansion'));
})->with('past-due expansions');

it('refuses through the seat and addon services the portal calls', function (): void {
    $billable = pastDueExpansionBillable();

    expect(fn () => $billable->syncBillingSeats(4))->toThrow(PastDueExpansionNotAllowedException::class)
        ->and(fn () => $billable->enableBillingAddon('sso'))->toThrow(PastDueExpansionNotAllowedException::class);

    expect($billable->refresh()->getBillingSeatCount())->toBe(3)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage']);
});

it('speaks to the customer in their language', function (): void {
    $billable = pastDueExpansionBillable();

    app()->setLocale('de');

    // The screen says it in the customer's language; the exception message is the English
    // one the log reads (ExceptionMessage, docs/decisions.md).
    try {
        app(UpdateSubscription::class)->update($billable, ['seats' => 4]);
        $this->fail('The seat expansion was not refused.');
    } catch (PastDueExpansionNotAllowedException $e) {
        expect(\GraystackIT\MollieBilling\Support\ExceptionMessage::for($e))->toBe(__('billing::portal.error_past_due_expansion'))
            ->and(\GraystackIT\MollieBilling\Support\ExceptionMessage::for($e))->toContain('offene Zahlung')
            ->and($e->getMessage())->toBe(app('translator')->get('billing::portal.error_past_due_expansion', [], 'en'));
    }
});

it('still lets a past-due customer shrink: fewer seats, an addon off', function (): void {
    $billable = pastDueExpansionBillable();

    $preview = app(PreviewService::class)->previewUpdate($billable, ['seats' => 2]);
    expect(array_column($preview['errors'], 'type'))->not->toContain('past_due_expansion');

    app(UpdateSubscription::class)->update($billable, ['seats' => 2]);
    app(UpdateSubscription::class)->update($billable, ['addons' => []]);

    $billable->refresh();

    // Nothing was paid for the lapsed period, so nothing is credited; Mollie holds the
    // smaller amount for whenever the subscription is recovered: 50.00 + 20 %.
    expect($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::$heldValue)->toBe('60.00');
});

it('still lets a past-due customer enable an addon that bills nothing', function (): void {
    $billable = pastDueExpansionBillable();

    expect(array_column(app(PreviewService::class)->previewUpdate($billable, ['addons' => ['storage', 'badge']])['errors'], 'type'))
        ->not->toContain('past_due_expansion');

    app(UpdateSubscription::class)->update($billable, ['addons' => ['storage', 'badge']]);

    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe(['storage', 'badge'])
        ->and(World::$payments)->toBe([]);
});

it('does not mistake a derived seat count for an expansion', function (): void {
    // More members assigned than seats exist. Switching an addon OFF derives the seat
    // count from that — the customer asked for nothing more, and is not refused.
    $billable = World::failRenewal(World::subscription('none', 'pro', 3, ['storage'], used: 5));

    app(UpdateSubscription::class)->update($billable, ['addons' => []]);

    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('leaves the past-due reset alone: a plan change may carry more seats and addons', function (): void {
    $billable = pastDueExpansionBillable();

    expect(array_column(app(PreviewService::class)->previewUpdate($billable, [
        'plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 5, 'addons' => ['storage', 'sso'],
    ])['errors'], 'type'))->not->toContain('past_due_expansion');

    $report = World::change($billable, [
        'plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 5, 'addons' => ['storage', 'sso'], 'apply_at' => 'immediate',
    ]);
    $billable->refresh();

    // A fresh period, charged in full: 200.00 + 3 × 5.00 + 9.00 + 15.00.
    expect(World::sum($report['booked']))->toBe(23900)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSeatCount())->toBe(5)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage', 'sso']);
});

it('allows the same expansion again once the subscription is no longer past due', function (): void {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);

    $report = World::change($billable, ['seats' => 4]);

    expect($report['booked'])->toBe([['seats', null, 339]])
        ->and($billable->refresh()->getBillingSeatCount())->toBe(4);
});

it('never applies to a Local subscription, which cannot be past due', function (): void {
    // The route a gifted tenant takes to buy extras starts from a Local subscription.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gifted', 'email' => uniqid('past-due-local-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => 'monthly',
        'subscription_period_starts_at' => now(),
        'subscription_meta' => ['seat_count' => 2],
    ])->save();
    $billable->refresh();

    expect($billable->isBillingPastDue())->toBeFalse();

    // No refusal of this kind for seats or a paid addon on top of a gift.
    app(ValidateSubscriptionChange::class)->assertNoPastDueExpansion($billable, false, true, 2, 5, ['sso', 'crm'], 'monthly');

    expect(array_column(app(PreviewService::class)->previewUpdate($billable, ['seats' => 5, 'addons' => ['sso']])['errors'], 'type'))
        ->not->toContain('past_due_expansion');
});

it('tells the customer on the seats and addons screens', function (): void {
    $billable = pastDueExpansionBillable();
    MollieBilling::authUsing(fn (): bool => true);
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::seats')->assertSee(__('billing::portal.error_past_due_expansion'));
    Livewire::test('mollie-billing::addons')->assertSee(__('billing::portal.error_past_due_expansion'));
});

it('does not show the hint on those screens to a customer who is not past due', function (): void {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    MollieBilling::authUsing(fn (): bool => true);
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::seats')->assertDontSee(__('billing::portal.error_past_due_expansion'));
    Livewire::test('mollie-billing::addons')->assertDontSee(__('billing::portal.error_past_due_expansion'));
});

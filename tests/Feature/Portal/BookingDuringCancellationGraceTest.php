<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\TranslatableException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ExceptionMessage;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Livewire;

/*
 * Decision 5 (26 November 2026): a subscription cancelled at the period end keeps its
 * access until `subscription_ends_at` — the grace period. Nothing new that costs money is
 * booked into it: a paid addon or an extra seat would be charged pro rata for a period
 * that ends anyway. The service refuses with `SubscriptionEndingBookingNotAllowedException`,
 * whose sentence names the end date and the way back (withdraw the cancellation), and the
 * addons and seats screens do not offer the booking at all. Giving an addon or a seat back
 * stays possible, and so does an addon that bills nothing. (The link to the dashboard is a
 * Flux button, which only the browser suite renders: `BookingDuringCancellationGraceTest`.)
 */

beforeEach(function (): void {
    World::boot();
    MollieBilling::authUsing(fn (): bool => true);
});

/** A paid Mollie subscription on `pro` with `storage`, cancelled at the period end. */
function graceCancelledSubscriber(): TestBillable
{
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => $billable->nextBillingDate(),
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function graceEndDate(TestBillable $billable): string
{
    return (string) BillingTime::billingDay($billable->getBillingSubscriptionEndsAt())?->translatedFormat('d. M Y');
}

it('refuses a paid addon in the grace period with the end date and the way back, before anything reaches Mollie', function (string $locale): void {
    app()->setLocale($locale);
    $billable = graceCancelledSubscriber();
    $sent = count(World::$sent);

    $expected = __('billing::portal.error_subscription_ending_addons', ['date' => graceEndDate($billable)]);
    expect($expected)->not->toContain('billing::');

    try {
        $billable->enableBillingAddon('sso');
        $this->fail('the addon was booked into the grace period');
    } catch (SubscriptionEndingBookingNotAllowedException $e) {
        // What a screen shows, in the reader's language; the log keeps English.
        expect(ExceptionMessage::for($e))->toBe($expected)
            ->and($e->getMessage())->toBe(__('billing::portal.error_subscription_ending_addons', ['date' => SubscriptionEndingBookingNotAllowedException::endDate($billable, 'en')], 'en'))
            // Still the cancelled-subscription refusal every screen already catches.
            ->and($e)->toBeInstanceOf(SubscriptionEndedChangeNotAllowedException::class)
            ->and($e)->toBeInstanceOf(TranslatableException::class);
    }

    expect(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toBe(['storage'])
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_prorata_change');
})->with(['en', 'de']);

it('refuses an extra seat in the grace period the same way', function (string $locale): void {
    app()->setLocale($locale);
    $billable = graceCancelledSubscriber();
    $sent = count(World::$sent);

    try {
        $billable->syncBillingSeats(4);
        $this->fail('the seat was booked into the grace period');
    } catch (SubscriptionEndingBookingNotAllowedException $e) {
        expect(ExceptionMessage::for($e))->toBe(__('billing::portal.error_subscription_ending_seats', ['date' => graceEndDate($billable)]));
    }

    expect(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3);
})->with(['en', 'de']);

it('keeps the general refusal for a plan change in the grace period', function (): void {
    $billable = graceCancelledSubscriber();

    try {
        app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
            ->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
        $this->fail('the plan change was accepted');
    } catch (SubscriptionEndedChangeNotAllowedException $e) {
        expect($e)->not->toBeInstanceOf(SubscriptionEndingBookingNotAllowedException::class)
            ->and(ExceptionMessage::for($e))->toBe(__('billing::portal.error_subscription_ended'));
    }
});

it('still lets the grace period switch an addon off and book one that bills nothing', function (): void {
    $billable = graceCancelledSubscriber();

    $billable->disableBillingAddon('storage');
    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe([]);

    $billable->enableBillingAddon('badge');
    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe(['badge']);
});

it('the addons screen offers no paid booking in the grace period, says until when and links to withdrawing the cancellation', function (string $locale): void {
    app()->setLocale($locale);
    $billable = graceCancelledSubscriber();

    $hint = __('billing::portal.error_subscription_ending_addons', ['date' => graceEndDate($billable)]);
    expect($hint)->not->toContain('billing::');

    Livewire::test('mollie-billing::addons')
        ->assertSee($hint)
        ->assertSee(__('billing::portal.grace_withdraw_cancellation'))
        // A paid addon: no booking, no confirm dialog.
        ->assertDontSeeHtml("enableAddon('sso')")
        ->assertDontSeeHtml('enable-addon-sso')
        // Giving one back stays.
        ->assertSeeHtml("disableAddon('storage')")
        // An addon that bills nothing is no charge for a period that ends.
        ->assertSeeHtml("enableAddon('badge')");
})->with(['en', 'de']);

it('the addons screen keeps offering paid addons to a running subscription', function (): void {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::addons')
        ->assertDontSee(__('billing::portal.grace_withdraw_cancellation'))
        ->assertSeeHtml("enableAddon('sso')");
});

it('a stale addons page clicked in the grace period books nothing', function (): void {
    $billable = graceCancelledSubscriber();
    $sent = count(World::$sent);

    Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'sso')
        ->assertSet('polling', false);

    expect(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toBe(['storage']);
});

it('the seats screen in the grace period does not count above the booked seats and says why', function (string $locale): void {
    app()->setLocale($locale);
    $billable = graceCancelledSubscriber();
    $hint = __('billing::portal.error_subscription_ending_seats', ['date' => graceEndDate($billable)]);
    expect($hint)->not->toContain('billing::');

    Livewire::test('mollie-billing::seats')
        ->assertSee($hint)
        ->call('increment')
        ->assertSet('seatCount', 3)
        // Fewer seats stays possible.
        ->call('decrement')
        ->assertSet('seatCount', 2);
})->with(['en', 'de']);

it('a seat count typed past the booked seats in the grace period is refused with the hint', function (): void {
    $billable = graceCancelledSubscriber();
    $sent = count(World::$sent);

    Livewire::test('mollie-billing::seats')
        ->set('seatCount', 4)
        ->call('syncSeats')
        ->assertSet('flash', __('billing::portal.error_subscription_ending_seats', ['date' => graceEndDate($billable)]))
        ->assertSet('flashSuccess', false);

    expect(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3);
});

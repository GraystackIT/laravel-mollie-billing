<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Decision 5 (26 November 2026): a subscription cancelled at the period end keeps its
 * access until it ends, and nothing that costs money is booked into that grace period. The
 * addons screen offers no paid addon and the seats screen counts no seat above what is
 * booked; both say until when the subscription runs and link to the dashboard, where the
 * cancellation is withdrawn. Switching an addon off stays possible. Once the cancellation
 * is withdrawn, the addon can be booked again.
 *
 * The refusal itself, and the stale-page click, are in the feature suite
 * (`tests/Feature/Portal/BookingDuringCancellationGraceTest.php`).
 */
class BookingDuringCancellationGraceTest extends BrowserTestCase
{
    private const ENABLE_PRIORITY = '[wire\\:click="enableAddon(\'priority-support\')"]';

    private const DISABLE_SOFTDRINKS = '[wire\\:click="disableAddon(\'softdrinks\')"]';

    /** Pro, monthly, with Softdrinks and one extra seat, cancelled at the period end. */
    private function cancelledInGrace(): TestBillable
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly', ['active_addon_codes' => ['softdrinks']]);
        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => $billable->nextBillingDate(),
            'subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 4]),
        ])->save();

        DB::table('fake_mollie_subscriptions')
            ->where('id', (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])
            ->update(['status' => 'canceled', 'canceled_at' => now()]);

        return $billable->refresh();
    }

    private function assertNothingCharged(): void
    {
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment reached Mollie');
    }

    public function test_the_addons_screen_offers_no_paid_addon_in_the_grace_period_and_leads_to_withdrawing_the_cancellation(): void
    {
        $billable = $this->cancelledInGrace();
        $hint = (string) __('billing::portal.error_subscription_ending_addons', [
            'date' => SubscriptionEndingBookingNotAllowedException::endDate($billable),
        ]);

        $this->browse(function (Browser $browser) use ($billable, $hint): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Priority Support', 15)->pause(400);

            $browser->assertSeeIn('[dusk="addons-grace-blocked"]', $hint);
            $this->assertSame(0, $this->domCount($browser, self::ENABLE_PRIORITY), 'no booking for a paid addon');
            $this->assertSame(0, $this->domCount($browser, '[data-modal="enable-addon-priority-support"], [name="enable-addon-priority-support"]'), 'no confirm dialog either');
            $this->assertTrue((bool) $browser->script('const b = document.querySelector(\'[dusk="addon-enable-blocked-priority-support"]\'); return b !== null && (b.disabled || b.hasAttribute("disabled"));')[0], 'the book button is shown, disabled');
            $this->assertSame(1, $this->domCount($browser, self::DISABLE_SOFTDRINKS), 'switching off stays');
            $this->shot($browser, 'Addons in der Kulanzzeit gesperrt');

            // The link goes to the dashboard, where the cancellation is withdrawn.
            $browser->click('[dusk="addons-grace-blocked"] a');
            $this->waitForPathContains($browser, '/billing');
            $browser->waitFor('[wire\\:click="resubscribe"]', 15)->pause(400);
            $this->shot($browser, 'Übersicht mit Kündigung zurücknehmen');
        });

        $this->assertNothingCharged();
        $this->assertSame(['softdrinks'], $billable->fresh()->getActiveBillingAddonCodes());
    }

    public function test_an_addon_can_still_be_switched_off_in_the_grace_period(): void
    {
        $billable = $this->cancelledInGrace();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 15)->pause(400);

            $this->confirmInModal($browser, 'disable-addon-softdrinks', self::DISABLE_SOFTDRINKS);

            $browser->waitUsing(15, 200, fn (): bool => $billable->fresh()->getActiveBillingAddonCodes() === [], 'Waited for Softdrinks to be switched off.');
            $browser->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($browser, 'Addon in der Kulanzzeit abbestellt');
        });

        $fresh = $billable->fresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $fresh->getBillingSubscriptionStatus());
        $this->assertTrue($fresh->getBillingSubscriptionEndsAt()?->isFuture() ?? false, 'the end date is not touched');
    }

    public function test_the_seats_screen_counts_no_seat_above_the_booked_ones_in_the_grace_period(): void
    {
        $billable = $this->cancelledInGrace();
        $hint = (string) __('billing::portal.error_subscription_ending_seats', [
            'date' => SubscriptionEndingBookingNotAllowedException::endDate($billable),
        ]);

        $this->browse(function (Browser $browser) use ($billable, $hint): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);

            $browser->assertSeeIn('[dusk="seats-grace-blocked"]', $hint);
            $this->assertTrue((bool) $browser->script('const b = document.querySelector(\'[wire\\\\:click="increment"]\'); return b.disabled || b.hasAttribute("disabled");')[0], 'no seat more');
            $this->assertFalse((bool) $browser->script('const b = document.querySelector(\'[wire\\\\:click="decrement"]\'); return b.disabled || b.hasAttribute("disabled");')[0], 'a seat fewer stays possible');
            $this->assertSame(1, $this->domCount($browser, '[dusk="seats-grace-blocked"] a'), 'the way back is linked');
            $this->shot($browser, 'Plätze in der Kulanzzeit gesperrt');
        });

        $this->assertNothingCharged();
        $this->assertSame(4, $billable->fresh()->getBillingSeatCount());
    }
}

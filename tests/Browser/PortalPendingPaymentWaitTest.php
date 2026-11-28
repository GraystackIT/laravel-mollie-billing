<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The checkout's wait notice on the portal screens that start a first payment. The customer
 * goes to Mollie, comes Back, and asks for something else: Mollie never cancels an open
 * first payment (measured 26 November 2026 — the fake's default), so the changed order
 * waits. Each screen says until when the earlier payment stays open, in the billable's
 * timezone and the app's language, and links to it — instead of a generic error.
 *
 *  - plan screen: a free-plan customer confirms Pro, comes back and confirms Business;
 *  - addons: a gifted tenant enables Softdrinks, comes back and enables Priority Support;
 *  - seats: a gifted tenant buys one seat, comes back and buys two.
 *
 * Nothing new is created beside the payable first payment. See docs/decisions.md, "The
 * wait notice is the same on every screen that starts a first payment".
 */
class PortalPendingPaymentWaitTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const CONFIRM_AND_PAY = '[wire\\:click="confirmAndPay"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const ENABLE_SOFTDRINKS = '[wire\\:click="enableAddon(\'softdrinks\')"]';

    private const ENABLE_PRIORITY = '[wire\\:click="enableAddon(\'priority-support\')"]';

    private const INCREMENT = '[wire\\:click="increment"]';

    private const SYNC_SEATS = '[wire\\:click="syncSeats"]';

    public function test_the_plan_screen_shows_until_when_the_earlier_upgrade_payment_stays_open_and_links_to_it(): void
    {
        WorkbenchSetting::set('config.app.locale', json_encode('de'));
        WorkbenchSetting::set('config.mollie-billing.billing_timezone', json_encode('Europe/Vienna'));

        $billable = $this->couponBillableIn('free');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $this->ppwConfirmUpgradeTo($browser, 'pro');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);
            $earlierCheckout = (string) $browser->driver->getCurrentURL();

            // Back, and another plan: Business instead of Pro.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/plan');
            $browser->visit('/billing/plan');
            $this->ppwConfirmUpgradeTo($browser, 'business');

            $browser->waitFor('@pending-other-order', 15)
                ->assertSeeIn('@pending-other-order', __('billing::checkout.pending_other_order_heading', [], 'de'))
                ->assertSeeIn('@pending-other-order-until', $this->ppwExpiry('Europe/Vienna', 'H:i'))
                ->assertSeeIn('@pending-other-order-text', 'Uhr offen')
                ->assertSeeIn('@pending-other-order-text', __('billing::checkout.pending_other_order_finish_or_wait', [], 'de'))
                ->assertSeeIn('@pending-other-order-resume', __('billing::checkout.pending_other_order_resume', [], 'de'));
            $this->assertSame($earlierCheckout, (string) $browser->attribute('@pending-other-order-resume', 'href'));
            $this->assertStringContainsString('/billing/plan', (string) $browser->driver->getCurrentURL());
            $this->shot($browser, 'Planwechsel wartet: Hinweis mit Uhrzeit und Link');

            // "Zur früheren Zahlung" leads to the earlier payment, still payable. The notice
            // scrolled itself into view, clear of the sticky header.
            $browser->pause(600);
            $this->clickWire($browser, '@pending-other-order-resume');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);
            $this->assertSame($earlierCheckout, (string) $browser->driver->getCurrentURL());
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'nothing new created beside a payable first payment');
        $this->assertSame('open', $this->latestFakePayment()['status']);
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame('coupon-free', $billable->refresh()->getBillingSubscriptionPlanCode());
    }

    public function test_the_addons_screen_shows_the_wait_notice_when_a_gifted_tenant_comes_back_for_another_addon(): void
    {
        WorkbenchSetting::set('config.app.locale', json_encode('en'));
        WorkbenchSetting::set('config.mollie-billing.billing_timezone', json_encode('Europe/Vienna'));

        $billable = $this->couponBillableIn('gifted');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);
            $earlierCheckout = (string) $browser->driver->getCurrentURL();

            // Back, and another addon: Priority Support instead of Softdrinks.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/addons');
            $browser->visit('/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_PRIORITY, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-priority-support', self::ENABLE_PRIORITY);

            $browser->waitFor('@pending-other-order', 15)
                ->assertSeeIn('@pending-other-order', __('billing::checkout.pending_other_order_heading', [], 'en'))
                ->assertSeeIn('@pending-other-order-until', $this->ppwExpiry('Europe/Vienna', 'g:i A'))
                ->assertSeeIn('@pending-other-order-text', __('billing::checkout.pending_other_order_finish_or_wait', [], 'en'))
                ->assertSeeIn('@pending-other-order-resume', __('billing::checkout.pending_other_order_resume', [], 'en'));
            $this->assertSame($earlierCheckout, (string) $browser->attribute('@pending-other-order-resume', 'href'));
            $this->assertStringContainsString('/billing/addons', (string) $browser->driver->getCurrentURL());
            $this->shot($browser, 'Add-ons: changed order waits for the earlier payment');
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'nothing new created beside a payable first payment');
        $this->assertSame('open', $this->latestFakePayment()['status']);
        $this->assertSame([], $billable->refresh()->getActiveBillingAddonCodes());
    }

    public function test_the_seats_screen_shows_the_wait_notice_when_a_gifted_tenant_comes_back_for_a_different_seat_count(): void
    {
        WorkbenchSetting::set('config.app.locale', json_encode('en'));
        WorkbenchSetting::set('config.mollie-billing.billing_timezone', json_encode('Europe/Vienna'));

        $billable = $this->couponBillableIn('gifted');
        $seatsBefore = $billable->getBillingSeatCount();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $this->ppwBuySeats($browser, 1);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);
            $earlierCheckout = (string) $browser->driver->getCurrentURL();

            // Back, and another seat count: two instead of one.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/seats');
            $browser->visit('/billing/seats');
            $this->ppwBuySeats($browser, 2);

            $browser->waitFor('@pending-other-order', 15)
                ->assertSeeIn('@pending-other-order-until', $this->ppwExpiry('Europe/Vienna', 'g:i A'))
                ->assertSeeIn('@pending-other-order-text', __('billing::checkout.pending_other_order_finish_or_wait', [], 'en'));
            $this->assertSame($earlierCheckout, (string) $browser->attribute('@pending-other-order-resume', 'href'));
            $this->assertStringContainsString('/billing/seats', (string) $browser->driver->getCurrentURL());
            // The notice, not the generic error.
            $browser->assertDontSee(__('billing::portal.flash.error', [], 'en'));
            $this->shot($browser, 'Seats: changed order waits for the earlier payment');
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'nothing new created beside a payable first payment');
        $this->assertSame('open', $this->latestFakePayment()['status']);
        $this->assertSame($seatsBefore, $billable->refresh()->getBillingSeatCount());
    }

    /** Until when the latest fake payment stays open — 15 minutes after creation — as the notice writes it. */
    private function ppwExpiry(string $timezone, string $format): string
    {
        return CarbonImmutable::parse((string) $this->latestFakePayment()['created_at'], 'UTC')
            ->addMinutes(15)
            ->setTimezone($timezone)
            ->format($format);
    }

    /** On the plan page of a free-plan customer: the plan, Apply, and "Confirm and pay". */
    private function ppwConfirmUpgradeTo(Browser $browser, string $plan): void
    {
        $preview = '[wire\\:click="previewFor(\''.$plan.'\')"]';

        $browser->waitFor($preview, 15)->pause(400);
        $this->clickWire($browser, $preview);
        $browser->waitFor(self::APPLY, 15)->pause(400);
        $this->clickWire($browser, self::APPLY);
        $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
        $browser->pause(400);

        // Inside a dialog WebDriver does not consider interactable: click it in the page.
        $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
    }

    /** On the seats page: add `$extra` seats and save. */
    private function ppwBuySeats(Browser $browser, int $extra): void
    {
        $browser->waitFor(self::INCREMENT, 15)->pause(400);

        for ($i = 0; $i < $extra; $i++) {
            $this->clickWire($browser, self::INCREMENT);
            $browser->pause(600);
        }

        $this->clickWire($browser, self::SYNC_SEATS);
    }
}

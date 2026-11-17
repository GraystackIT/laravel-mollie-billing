<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Support\PastDueBookingRace;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The four screens that book something new each catch `PastDueExpansionNotAllowedException`
 * and say `error_past_due_booking` instead of "something went wrong": the addons and the
 * seats screen for a tenant on a gift, the products page, and the checkout.
 *
 * In a real past-due state the click is not there to make — the pages redirect, or the
 * screen does not offer the action. What remains is the race: the customer has the page
 * open, a renewal fails, then they click. The workbench switch
 * (`PastDueBookingRace`) makes the booking service answer exactly that, so the screen's own
 * `catch` is what is under test. Each case also checks that the generic error is not shown
 * and that nothing reached Mollie.
 */
class PastDueBookingRefusalTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WorkbenchSetting::set(PastDueBookingRace::SETTING, '1');
    }

    private function message(): string
    {
        return (string) __('billing::portal.error_past_due_booking');
    }

    private function giftedTenant(): \GraystackIT\MollieBilling\Testing\TestBillable
    {
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country' => 'DE',
            'tax_country_user' => 'DE',
        ]);

        $coupon = $this->coupons()->accessGrantCoupon('GRANT-PD'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', [], 90);
        $this->coupons()->redeem($coupon, $billable, []);

        return $billable->fresh();
    }

    private function assertNothingBooked(): void
    {
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment reached Mollie');
        $this->assertSame(0, BillingInvoice::query()->where('amount_gross', '>', 0)->count(), 'nothing was invoiced');
    }

    public function test_the_addons_screen_says_the_open_payment_comes_first_for_a_gifted_tenant(): void
    {
        $billable = $this->giftedTenant();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 15)->pause(400);

            $this->confirmInModal($browser, 'enable-addon-softdrinks', '[wire\\:click="enableAddon(\'softdrinks\')"]');

            $browser->waitForText($this->message(), 10)
                ->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($browser, 'Addons: offene Zahlung zuerst');
        });

        $this->assertSame([], $billable->fresh()->getActiveBillingAddonCodes());
        $this->assertNothingBooked();
    }

    public function test_the_seats_screen_says_the_open_payment_comes_first_for_a_gifted_tenant(): void
    {
        $billable = $this->giftedTenant();
        $seatsBefore = $billable->getBillingSeatCount();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);

            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');

            $browser->waitForText($this->message(), 10)
                ->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($browser, 'Sitze: offene Zahlung zuerst');
        });

        $this->assertSame($seatsBefore, $billable->fresh()->getBillingSeatCount());
        $this->assertNothingBooked();
    }

    public function test_the_products_page_says_the_open_payment_comes_first(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);

            $this->confirmInModal($browser, 'purchase-token-pack-500', '[wire\\:click="purchase(\'token-pack-500\')"]');

            $browser->waitForText($this->message(), 10)
                ->assertDontSee(__('billing::portal.products.error'));
            $this->shot($browser, 'Produkte: offene Zahlung zuerst');
        });

        $this->assertNothingBooked();
    }

    public function test_the_checkout_says_the_open_payment_comes_first(): void
    {
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');

            $browser->waitForText('Starter', 15)->pause(400);
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitForText('Softdrinks', 10)->pause(300);
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $browser->waitForText($this->message(), 10)
                ->assertDontSee(__('billing::checkout.error_payment_creation'));
            $this->shot($browser, 'Checkout: offene Zahlung zuerst');
        });

        $this->assertNull($billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertNothingBooked();
    }
}

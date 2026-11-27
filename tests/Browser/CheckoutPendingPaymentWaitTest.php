<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Back from Mollie with a changed order, as it goes against the real API: Mollie never
 * cancels an open first payment (measured 26 November 2026, 422 / `isCancelable: false` —
 * the fake's default), so the changed order waits for the earlier payment to expire.
 *
 * The checkout says until when — the payment's `expiresAt`, in the billable's timezone and
 * the app's language — and offers the earlier payment's own checkout link to finish it.
 * Nothing new is created beside the payable first payment. See docs/decisions.md, "A changed
 * order waits for the earlier payment, and says until when".
 */
class CheckoutPendingPaymentWaitTest extends BrowserTestCase
{
    private const SUBMIT = '[wire\\:click="submit"]';

    private const NEXT = '[wire\\:click="next"]';

    private const BACK = '[wire\\:click="back"]';

    public function test_back_from_mollie_with_a_changed_order_shows_until_when_the_earlier_payment_stays_open_and_links_to_it(): void
    {
        WorkbenchSetting::set('config.app.locale', json_encode('de'));
        WorkbenchSetting::set('config.mollie-billing.billing_timezone', json_encode('Europe/Vienna'));

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->pwWalkToConfirm($browser, $billable, 'starter');
            $this->clickWire($browser, self::SUBMIT);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 11.78');
            $earlierCheckout = (string) $browser->driver->getCurrentURL();

            // The browser's Back button, then another order: Business instead of Starter.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/checkout');
            $this->pwBackToPlanStep($browser);
            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);
            $this->clickWire($browser, self::SUBMIT);

            // The wait, with the time the earlier payment stays open (its expiresAt,
            // 15 minutes after creation, in Vienna time) and the way back to it.
            $payment = $this->latestFakePayment();
            $expected = CarbonImmutable::parse((string) $payment['created_at'], 'UTC')
                ->addMinutes(15)
                ->setTimezone('Europe/Vienna')
                ->format('H:i');

            $browser->waitFor('@pending-other-order', 15)
                ->assertSeeIn('@pending-other-order', __('billing::checkout.pending_other_order_heading', [], 'de'))
                ->assertSeeIn('@pending-other-order-until', $expected)
                ->assertSeeIn('@pending-other-order-text', 'Uhr offen')
                ->assertSeeIn('@pending-other-order-text', __('billing::checkout.pending_other_order_finish_or_wait', [], 'de'))
                ->assertSeeIn('@pending-other-order-resume', __('billing::checkout.pending_other_order_resume', [], 'de'));
            $this->assertSame($earlierCheckout, (string) $browser->attribute('@pending-other-order-resume', 'href'));
            $this->assertStringContainsString('/billing/checkout', (string) $browser->driver->getCurrentURL());
            $this->shot($browser, 'Geänderte Bestellung wartet: Hinweis mit Uhrzeit und Link');

            // "Zur früheren Zahlung" leads to the earlier payment, still payable.
            $browser->click('@pending-other-order-resume');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 11.78')->assertSee('Subscription starter');
            $this->shot($browser, 'Mollie: frühere Zahlung');
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'nothing new created beside a payable first payment');
        $this->assertSame('open', $this->latestFakePayment()['status']);
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertFalse($billable->refresh()->hasAccessibleBillingSubscription());
    }

    /** Open the checkout (billing step locked), pick the plan, walk through to confirm. */
    private function pwWalkToConfirm(Browser $browser, TestBillable $billable, string $plan): void
    {
        $this->actAs($browser, $billable, '/billing/checkout');
        $this->pwBackToPlanStep($browser);
        $browser->click('[wire\\:click*="\''.$plan.'\'"]')->pause(600);
        $this->clickWire($browser, self::NEXT);
        $browser->pause(700);

        if ($this->domCount($browser, self::SUBMIT) === 0) {
            $this->clickWire($browser, self::NEXT);
        }

        $browser->waitFor(self::SUBMIT, 15)->pause(400);
    }

    /** Click the step's Back button until the plan cards are on screen. */
    private function pwBackToPlanStep(Browser $browser): void
    {
        $plans = '[wire\\:click*="plan_code"]';

        $browser->waitUsing(15, 200, fn (): bool => $this->domCount($browser, $plans) > 0
            || $this->domCount($browser, self::BACK) > 0, 'Waited for the checkout to render.');

        for ($i = 0; $i < 4 && $this->domCount($browser, $plans) === 0; $i++) {
            $this->clickWire($browser, self::BACK);
            $browser->pause(700);
        }

        $browser->waitFor($plans, 15)->pause(300);
    }
}

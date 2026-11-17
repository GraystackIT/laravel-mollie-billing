<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Tests\Browser\Matrix\CheckoutMatrixWorld;
use Laravel\Dusk\Browser;

/**
 * `usage_types.{type}.visible = false` in Chrome: the type is left off the plan cards a
 * customer chooses from — the checkout's plan step and the plan-change screen — and is
 * still there wherever the customer reads what was used or what is billed: the usage
 * meters on the dashboard and the usage page, and the plan-change preview.
 *
 * The workbench catalog sells Tokens and SMS on every plan. Each test hides SMS for both
 * processes through the config channel, so no other test's catalog moves.
 */
class HiddenUsageTypeTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;

    /** Hide SMS, declared in lower case: the lookup is case-insensitive. */
    private function hideSms(): void
    {
        $this->cmConfig('mollie-billing-plans.usage_types', [
            'Tokens' => ['rollover' => true],
            'sms' => ['rollover' => false, 'visible' => false],
        ]);
    }

    public function test_the_checkout_plan_cards_leave_a_hidden_usage_type_out(): void
    {
        $this->hideSms();
        $billable = $this->cmCustomer('DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');
            $browser->waitForText('Business', 15)->pause(400);
            $this->shot($browser, 'Checkout-Plankarten ohne versteckte Verbrauchsart');

            // The visible type is stated on the cards (Starter 50, Pro 100) …
            $browser->assertSee(__('billing::checkout.usage_included', ['count' => '50', 'type' => 'Tokens']))
                ->assertSee(__('billing::checkout.usage_included', ['count' => '100', 'type' => 'Tokens']));

            // … the hidden one nowhere: neither its quota nor its overage price.
            $browser->assertDontSee('SMS');
        });
    }

    public function test_the_plan_change_cards_leave_a_hidden_usage_type_out_and_the_preview_keeps_it(): void
    {
        $this->hideSms();
        // Pro includes 50 SMS a month, Starter 20. 45 used ten days in is more than the
        // prorated share, so a downgrade leaves overage the preview has to name.
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $billable->recordBillingUsage('SMS', 45);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Business', 15)->pause(400);
            $this->shot($browser, 'Planwechsel-Karten ohne versteckte Verbrauchsart');

            $browser->assertSee(__('billing::portal.usage_included_count', ['count' => '50', 'type' => 'Tokens']))
                ->assertSee(__('billing::portal.usage_included_count', ['count' => '100', 'type' => 'Tokens']))
                ->assertDontSee('SMS');

            // The preview settles real consumption and names the hidden type.
            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');
            $browser->waitForText(__('billing::portal.usage').' · SMS', 10)->pause(400);
            $this->shot($browser, 'Vorschau nennt die versteckte Verbrauchsart');
        });
    }

    public function test_the_usage_meters_still_show_a_hidden_usage_type(): void
    {
        $this->hideSms();
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $billable->recordBillingUsage('SMS', 5);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="usage-meter-SMS"]', 15)->pause(400);
            $this->shot($browser, 'Dashboard: Verbrauchsanzeige mit versteckter Art');

            $this->assertStringContainsString('SMS', $browser->text('[dusk="usage-meter-SMS"]'));
            $this->assertSame(1, $this->domCount($browser, '[dusk="usage-meter-Tokens"]'));

            $browser->visit('/billing/usage')->waitFor('[dusk="usage-meter-SMS"]', 15)->pause(400);
            $this->assertStringContainsString('SMS', $browser->text('[dusk="usage-meter-SMS"]'));
        });
    }
}

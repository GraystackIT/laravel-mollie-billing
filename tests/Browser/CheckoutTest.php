<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The founding scenario: a customer with no subscription walks through the
 * four-step checkout, genuinely leaves for the (fake) Mollie hosted page, pays,
 * and comes back activated — webhook, invoice, wallet and portal included.
 */
class CheckoutTest extends BrowserTestCase
{
    public function test_completes_a_paid_monthly_subscription_checkout_end_to_end(): void
    {
        $this->browse(function (Browser $browser): void {
            // Anonymous signup: no billable exists yet — the checkout creates it
            // through MollieBilling::createBillableUsing() on submit.
            $browser->visit('/');
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/billing/checkout');

            // Step 1 of 3 — billing details.
            $browser->waitFor('input[name="company_name"]', 10);
            $browser->type('company_name', 'Acme GmbH')
                ->type('billing_street', 'Musterweg 12')
                ->type('billing_postal_code', '10115')
                ->type('billing_city', 'Berlin')
                ->select('billing_country', 'DE')
                ->pause(600);
            $this->shot($browser, 'Rechnungsdaten');
            $this->clickWire($browser, '[wire\\:click="next"]');

            // Step 2 of 4 — plan selection.
            $browser->waitForText('Starter', 10)->pause(400);
            $this->shot($browser, 'Plan wählen');
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');

            // Step 3 of 4 — extras: appears because Starter allows an addon.
            $browser->waitForText('Softdrinks', 10)->pause(300);
            $this->shot($browser, 'Extras (Addons und Seats)');
            $this->clickWire($browser, '[wire\\:click="next"]');

            // Step 4 of 4 — summary, then off to Mollie.
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->shot($browser, 'Zusammenfassung');
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $browser->waitForLocation('/fake-mollie/checkout/tr_wb_1', 15);
            $this->shot($browser, 'Mollie-Checkout (fake)');
            $browser->click('@fake-mollie-pay');

            // Back in the app: the webhook already ran before the redirect.
            $browser->waitForText(__('billing::portal.return.title'), 15);
            $this->shot($browser, 'Return-Seite nach Zahlung');

            $browser->visit('/billing')
                ->waitForText('Starter', 10);
            $this->shot($browser, 'Dashboard mit aktivem Abo');
        });

        // The signup minted the billable — the only one in this fresh world.
        $billable = TestBillable::sole();

        expect($billable->name)->toBe('Acme GmbH')
            ->and($billable->getBillingStreet())->toBe('Musterweg 12')
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
            ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter')
            ->and($billable->mollie_mandate_id)->not->toBeNull()
            ->and(BillingInvoice::query()->count())->toBe(1)
            ->and((int) BillingInvoice::query()->first()->amount_gross)->toBe(1178); // 990 net + 19% DE VAT
    }

    public function test_existing_customer_checkout_locks_the_billing_step_and_starts_at_the_plan(): void
    {
        // Address already on file: the billing step is locked (changing it
        // retroactively would change the VAT treatment of issued documents) and
        // the stepper opens directly on the plan.
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');

            $browser->waitForText('Business', 10)->pause(400);
            $this->shot($browser, 'Startet direkt beim Plan-Schritt');

            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');

            // Business allows both addons — enable one on the way through.
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->jsClick($browser, '[value="priority-support"]')->pause(400);
            $this->shot($browser, 'Addon im Extras-Schritt aktiviert');
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->shot($browser, 'Zusammenfassung mit Addon');
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Business', 10);
            $this->shot($browser, 'Dashboard: Business mit Priority Support');
        });

        $billable->refresh();

        expect($billable->getBillingSubscriptionPlanCode())->toBe('business')
            ->and($billable->getActiveBillingAddonCodes())->toBe(['priority-support'])
            // 9900 plan + 990 addon, each with per-line 19% VAT.
            ->and((int) BillingInvoice::query()->first()->amount_gross)->toBe(11781 + 1178);
    }

    public function test_a_failed_payment_returns_the_customer_with_the_failure_view_and_activates_nothing(): void
    {
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');

            $browser->waitForText('Starter', 10);
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitForText('Softdrinks', 10);
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $this->settleAtFakeMollie($browser, 'failed');

            $browser->waitForText(__('billing::portal.return.failed_title'), 15);
            $this->shot($browser, 'Return-Seite nach fehlgeschlagener Zahlung');
        });

        $billable->refresh();

        expect($billable->subscription_status)->not->toBe(SubscriptionStatus::Active)
            ->and(BillingInvoice::query()->count())->toBe(0);
    }
}

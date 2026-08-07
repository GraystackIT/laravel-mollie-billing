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
            $browser->click('[wire\\:click="next"]');

            // Step 2 of 4 — plan selection.
            $browser->waitForText('Starter', 10)->pause(400);
            $this->shot($browser, 'Plan wählen');
            $browser->click('[wire\\:click*="\'starter\'"]')
                ->pause(600)
                ->click('[wire\\:click="next"]');

            // Step 3 of 4 — extras: appears because Starter allows an addon.
            $browser->waitForText('Softdrinks', 10)->pause(300);
            $this->shot($browser, 'Extras (Addons und Seats)');
            $browser->click('[wire\\:click="next"]');

            // Step 4 of 4 — summary, then off to Mollie.
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->shot($browser, 'Zusammenfassung');
            $browser->click('[wire\\:click="submit"]');

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
}

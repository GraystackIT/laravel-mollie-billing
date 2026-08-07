<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * Trials ride the mandate-only flow: a €0.00 authorisation captures the payment
 * method, the Mollie subscription starts at trial end, no money moves today.
 */
class TrialTest extends BrowserTestCase
{
    public function test_trial_checkout_captures_the_mandate_for_zero_euro_and_starts_the_trial(): void
    {
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');

            // Pro carries trial_days = 14.
            $browser->waitForText('Pro', 10);
            $browser->click('[wire\\:click*="\'pro\'"]')
                ->pause(600);
            $this->shot($browser, 'Pro mit Trial-Hinweis gewählt');
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitForText('Softdrinks', 10);
            $this->clickWire($browser, '[wire\\:click="next"]');

            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->shot($browser, 'Zusammenfassung: heute 0,00 €');
            $this->clickWire($browser, '[wire\\:click="submit"]');

            // The €0.00 authorisation — visible on the fake hosted page.
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Pro', 10);
            $this->shot($browser, 'Dashboard im Trial');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($billable->trial_ends_at)->not->toBeNull()
            ->and($billable->trial_ends_at->isAfter(now()->addDays(13)))->toBeTrue()
            ->and($billable->mollie_mandate_id)->not->toBeNull()
            // Trial activation books no money — no invoice until conversion.
            ->and(BillingInvoice::query()->count())->toBe(0);
    }

    public function test_the_trial_ending_notice_and_the_expiry_are_drivable_via_the_simulator(): void
    {
        // A trial without a mandate: the customer never added a card, so expiry
        // must flip them to PastDue and the portal must steer towards checkout.
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'subscription_plan_code' => 'pro',
            'subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly,
            'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(5),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 10);
            $this->shot($browser, 'Dashboard im laufenden Trial');

            $this->simulator()->trialExpired($billable->refresh());

            $browser->refresh()->pause(800);
            $this->shot($browser, 'Nach Trial-Ablauf ohne Zahlungsmittel');
        });

        expect(TestBillable::find($billable->getKey())->subscription_status)
            ->toBe(SubscriptionStatus::PastDue);
    }
}

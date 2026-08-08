<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The billing-details page and the invoice archive: the two screens a customer
 * visits for tax and paperwork reasons rather than to buy something.
 */
class BillingDataTest extends BrowserTestCase
{
    public function test_saves_the_billing_address(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('input[name="company_name"]', 10)->pause(400);
            $this->shot($browser, 'Rechnungsdaten');

            $browser->clear('billing_city')
                ->type('billing_city', 'Hamburg')
                ->clear('billing_postal_code')
                ->type('billing_postal_code', '20095')
                ->pause(400);

            $this->clickWire($browser, '[wire\\:click="save"]');
            $browser->pause(1200);
            $this->shot($browser, 'Adresse gespeichert');
        });

        expect(TestBillable::sole()->getBillingCity())->toBe('Hamburg')
            ->and(TestBillable::sole()->getBillingPostalCode())->toBe('20095');
    }

    public function test_a_valid_eu_vat_number_switches_the_customer_to_reverse_charge(): void
    {
        // The seller sits in AT (see WorkbenchServiceProvider), so a VIES-valid
        // DE business is a cross-border B2B supply: 0% VAT under Art. 196.
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('input[name="vat_number"]', 10)->pause(400);

            // DE129273398 is on the fake VIES allow-list.
            // Wait for the VERDICT, not for a stopwatch: the input is debounced by
            // 500ms and then asks VIES, so a fixed pause raced the answer under load
            // and the save went out with an unvalidated number.
            // The page's verdict is an ICON, not a sentence: a green check for
            // valid, a red cross for rejected. Waiting for it beats a stopwatch —
            // the input is debounced 500ms and then asks VIES, and a fixed pause
            // raced the answer under load, saving an unvalidated number.
            $browser->type('vat_number', 'DE129273398');
            $this->waitForPresence($browser, '.text-emerald-700, .dark\\:text-emerald-400', 15);
            $browser->pause(400);
            $this->shot($browser, 'UID validiert — Reverse Charge');

            $this->clickWire($browser, '[wire\\:click="save"]');
            $browser->pause(1200);
            $this->shot($browser, 'Nach dem Speichern');
        });

        $billable = TestBillable::sole();

        expect($billable->vat_number)->toBe('DE129273398')
            ->and($billable->currentVatValidation())->not->toBeNull();

        // The next renewal is invoiced at 0% — that is what reverse charge means.
        $this->renewSubscription($billable);

        $invoice = BillingInvoice::query()->latest('id')->first();

        expect((int) $invoice->amount_vat)->toBe(0)
            ->and((int) $invoice->amount_net)->toBe((int) $invoice->amount_gross);
    }

    public function test_an_unknown_vat_number_is_rejected_and_keeps_vat_on_the_invoice(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('input[name="vat_number"]', 10)->pause(400);

            // Not on the allow-list: the fake VIES answers `valid: false`, exactly
            // as the real service reports an unknown number.
            // Same reasoning: wait for the rejection to be on screen.
            $browser->type('vat_number', 'DE999999999');
            $this->waitForPresence($browser, '.text-red-600, .dark\\:text-red-400', 15);
            $browser->pause(400);
            $this->shot($browser, 'UID abgelehnt');

            $this->clickWire($browser, '[wire\\:click="save"]');
            $browser->pause(1200);
            $this->shot($browser, 'Speichern mit ungültiger UID');
        });

        // Whatever the form did, the tax treatment must not have flipped.
        $this->renewSubscription(TestBillable::sole());

        expect((int) BillingInvoice::query()->latest('id')->first()->amount_vat)->toBeGreaterThan(0);
    }

    public function test_the_invoice_archive_lists_documents_and_serves_their_pdf(): void
    {
        $billable = $this->makeActiveSubscriber();

        // Two renewals, so the list has something to show and to sum.
        $this->renewSubscription($billable);
        $this->renewSubscription($billable->refresh());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitForText('€11.78', 10)->pause(400);
            $this->shot($browser, 'Rechnungsarchiv mit Summen');

            // Each row links at the download route. Deliberately NOT followed:
            // Chrome downloads a PDF rather than navigating, so driving it proves
            // something about Chrome, not about the package. What the package owes
            // is the link and the file — both asserted below.
            $invoice = BillingInvoice::query()->latest('id')->first();

            expect($this->domCount($browser, 'a[href$="/invoices/'.$invoice->getKey().'/download"]'))
                ->toBeGreaterThan(0);
        });

        $invoice = BillingInvoice::query()->latest('id')->first();

        expect(BillingInvoice::query()->count())->toBe(2)
            ->and($invoice->hasPdf())->toBeTrue()
            ->and(\Illuminate\Support\Facades\Storage::disk($invoice->pdf_disk)->exists($invoice->pdf_path))
            ->toBeTrue();
    }

    /**
     * A VIES-valid business in a *different* EU country: a cross-border supply, where reverse
     * charge really does apply — so the message says so and the prices are shown net.
     *
     * Its counterpart (a valid number from the seller's own country, which is verified but
     * NOT reverse-charged) is pinned in `tests/Unit/Support/ValidatesVatNumberTraitTest.php`
     * instead: the workbench's fake VIES does not answer for an AT number inside the Dusk
     * server process, and the assertion is about which message the trait picks, not about the
     * browser.
     */
    public function test_a_cross_border_vat_number_does_promise_reverse_charge(): void
    {
        $billable = $this->makeBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');

            $this->fillBillingStep($browser, 'DE', 'DE129273398');
            $this->waitForPresence($browser, '.text-emerald-700, .dark\\:text-emerald-400', 15);

            $browser->assertSee(__('billing::checkout.vat_verified'));

            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Starter', 10)
                ->assertSee(__('billing::checkout.prices_net'));

            $this->shot($browser, 'Grenzüberschreitend: Reverse Charge, Preise netto');
        });
    }
}

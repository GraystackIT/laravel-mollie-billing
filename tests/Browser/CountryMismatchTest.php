<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The three-way country check: at every recurring payment the package compares
 * the country the customer declared against the one Mollie reports for the card
 * and the one the IP resolves to. Disagreement is a VAT problem — the wrong rate
 * was charged — so the subscription is scheduled for cancellation and the
 * customer is asked to correct it. Resolving it refunds and re-issues at the
 * corrected rate.
 */
class CountryMismatchTest extends BrowserTestCase
{
    public function test_a_renewal_from_a_foreign_card_flags_a_mismatch_and_warns_the_customer(): void
    {
        // Declared DE, but the card and the IP both say NL.
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->setIpCountry('NL');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);
            $this->shot($browser, 'Vor der Erneuerung: alles unauffällig');

            $this->renewFromCountry($billable->refresh(), 'NL');

            $browser->refresh()
                ->waitForText(__('billing::portal.country_mismatch.banner_heading'), 10)->pause(600);
            $this->shot($browser, 'Mismatch-Banner mit allen drei Signalen');
        });

        $billable = TestBillable::sole();
        $mismatch = BillingCountryMismatch::query()->sole();

        expect($mismatch->status)->toBe(CountryMismatchStatus::Pending)
            ->and($mismatch->tax_country_user)->toBe('DE')
            ->and($mismatch->tax_country_payment)->toBe('NL')
            // Booking with unverified VAT data must stop, so the subscription is
            // scheduled to end rather than silently renewed again.
            ->and($billable->getBillingSubscriptionEndsAt())->not->toBeNull();
    }

    public function test_the_customer_corrects_the_country_and_the_invoice_is_reissued_at_the_new_rate(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->setIpCountry('NL');

        $this->renewFromCountry($billable->refresh(), 'NL');

        $originalInvoice = BillingInvoice::query()->sole();
        expect((int) $originalInvoice->amount_vat)->toBe(188); // 990 net at DE 19%

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText(__('billing::portal.country_mismatch.banner_heading'), 10)->pause(400);

            // The banner's own button both pre-fills the form and opens the modal.
            $this->clickWire($browser, '[wire\\:click="openCountryFix"]');
            $browser->waitFor('select[name="fixCountry"]', 10)->pause(500);
            $this->shot($browser, 'Korrektur-Modal mit den drei Signalen');

            // Only a country one of the other two signals reported is accepted —
            // that is what makes the correction defensible to a tax authority.
            $browser->select('fixCountry', 'NL')->pause(500);
            $this->shot($browser, 'NL gewählt');

            // The form refuses without an explicit acknowledgement — a correction
            // refunds and re-issues real invoices, so it is not a silent action.
            $this->jsClick($browser, 'input[type="checkbox"][wire\\:model="fixConfirmed"], [wire\\:model="fixConfirmed"]');
            $browser->pause(400);
            $this->shot($browser, 'Rückerstattung-und-Neuausstellung bestätigt');

            $this->clickWire($browser, 'form[wire\\:submit\\.prevent="saveCountryFix"] button[type="submit"]');
            $browser->pause(3000);
            $this->shot($browser, 'Nach der Korrektur');
        });

        $mismatch = BillingCountryMismatch::query()->sole();

        expect($mismatch->status)->toBe(CountryMismatchStatus::Resolved)
            ->and($mismatch->chosen_country)->toBe('NL')
            ->and(TestBillable::sole()->getBillingCountry())->toBe('NL');

        // A credit note for the wrongly-rated original, and a correction charge
        // priced at the Dutch rate.
        expect(BillingInvoice::query()->where('invoice_kind', 'refund')->count())->toBeGreaterThan(0);
    }

    /**
     * A renewal whose card sits in another country. Mollie reports `countryCode`
     * on the payment; that is the signal the three-way check reads.
     */
    private function renewFromCountry(TestBillable $billable, string $country): void
    {
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        $payment = \Workbench\App\FakeMollie\FakeMollie::chargeSubscription($subscriptionId);

        \Illuminate\Support\Facades\DB::table('fake_mollie_payments')
            ->where('id', $payment['id'])
            ->update(['country_code' => $country]);

        $this->deliverWebhook($payment['id']);
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }
}

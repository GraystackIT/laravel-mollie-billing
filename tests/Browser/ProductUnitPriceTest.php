<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CheckoutMatrixWorld;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * `products.{code}.show_unit_price` in Chrome: a pack with a usage type and a quantity
 * above 1 shows what one unit costs, divided from the price shown next to it — gross to a
 * consumer, net under reverse charge. Every other product shows none. The pack size is
 * written in the active locale ("1.000" in German, not "1,000") and named by the usage
 * type's display name, not its code.
 *
 * 1000 SMS at €83.30 net: €99.13 gross at 19 % → €0.0991 a unit; €0.0833 net.
 */
class ProductUnitPriceTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;

    private function catalog(): void
    {
        $this->cmConfig('mollie-billing-plans.products', [
            'sms-1000' => [
                'name' => 'SMS 1000',
                'price_net' => 8330,
                'usage_type' => 'SMS',
                'quantity' => 1000,
                'show_unit_price' => true,
            ],
            // Same shape without the flag: no unit price. Declared in lower case so the
            // label shows it comes from the usage type's display name, not the raw code.
            'tokens-500' => [
                'name' => 'Tokens 500',
                'price_net' => 4900,
                'usage_type' => 'tokens',
                'quantity' => 500,
            ],
            // Flag set, but a single unit: the unit price would only repeat the price.
            'sms-single' => [
                'name' => 'SMS Single',
                'price_net' => 100,
                'usage_type' => 'SMS',
                'quantity' => 1,
                'show_unit_price' => true,
            ],
            // Flag set, but no usage type: there is no unit to price.
            'consulting' => [
                'name' => 'Consulting Hour',
                'price_net' => 5000,
                'show_unit_price' => true,
            ],
        ]);
    }

    private function reverseChargeCustomer(): TestBillable
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['vat_number' => 'DE129273398']);

        BillingVatValidation::query()->create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'vat_number' => 'DE129273398',
            'country_code' => 'DE',
            'valid' => true,
            'vies_response' => [],
            'checked_at' => now(),
        ]);

        return $billable->refresh();
    }

    /** @return list<string> the codes whose card carries a unit price */
    private function unitPriceCodes(Browser $browser): array
    {
        return (array) $browser->script(
            'return Array.from(document.querySelectorAll(\'[dusk^="product-unit-price-"]\')).map(el => el.getAttribute("dusk").replace("product-unit-price-", ""));'
        )[0];
    }

    public function test_a_consumer_sees_the_gross_unit_price_only_where_it_is_enabled(): void
    {
        $this->catalog();
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('SMS 1000', 15)->pause(400);
            $this->shot($browser, 'Produkte mit Stückpreis (brutto)');

            // The pack size, named by the usage type's display name ("tokens" → "Tokens").
            $browser->assertSee('1,000 SMS')
                ->assertSee('500 Tokens')
                ->assertDontSee('500 tokens');

            $browser->assertSeeIn('@product-price-sms-1000', '€99.13')
                ->assertSeeIn('@product-unit-price-sms-1000', '€0.0991 per SMS')
                ->assertSee(__('billing::portal.prices_incl_vat'));

            expect($this->unitPriceCodes($browser))->toBe(['sms-1000']);
        });
    }

    public function test_under_reverse_charge_the_unit_price_is_net_like_the_price_beside_it(): void
    {
        $this->catalog();
        $billable = $this->reverseChargeCustomer();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('SMS 1000', 15)->pause(400);
            $this->shot($browser, 'Produkte mit Stückpreis (netto, Reverse Charge)');

            $browser->assertSeeIn('@product-price-sms-1000', '€83.30')
                ->assertSeeIn('@product-unit-price-sms-1000', '€0.0833 per SMS')
                ->assertSee(__('billing::portal.prices_excl_vat'));

            expect($this->unitPriceCodes($browser))->toBe(['sms-1000']);
        });
    }

    public function test_the_pack_size_and_the_unit_price_follow_the_german_locale(): void
    {
        $this->catalog();
        $this->cmConfig('app.locale', 'de');
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('SMS 1000', 15)->pause(400);
            $this->shot($browser, 'Produkte mit Stückpreis auf Deutsch');

            $browser->assertSee('1.000 SMS')
                ->assertDontSee('1,000 SMS');

            $browser->assertSeeIn('@product-unit-price-sms-1000', '€0,0991 pro SMS')
                ->assertSee(__('billing::portal.prices_incl_vat', [], 'de'));
        });
    }
}

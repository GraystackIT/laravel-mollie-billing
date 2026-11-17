<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Every option of the checkout area shown working in Chrome, one at a time: each catalog
 * field where a customer reads it, each action of the checkout steps, the billing-data
 * page, the products page, the return page in every outcome, the blocked page, and each
 * configuration key that steers something visible.
 */
class CheckoutOptionsMatrixTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;

    private function money(int $minor): string
    {
        return BillingMoney::format($minor);
    }

    /** Open the checkout's plan step for a customer with an address on file. */
    private function planStep(Browser $browser, TestBillable $billable, string $query = ''): Browser
    {
        $this->actAs($browser, $billable, '/billing/checkout'.$query);

        return $browser->waitForText('Matrix Team', 15)->pause(400);
    }

    /** An anonymous visitor on the checkout's first step. */
    private function anonymousCheckout(Browser $browser, string $query = ''): Browser
    {
        $browser->visit('/');
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/billing/checkout'.$query)->waitFor('input[name="company_name"]', 15)->pause(400);
    }

    // ── Catalog fields, where a customer reads them ────────────────────────────

    public function test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable);
            $this->shot($browser, 'Plan-Karten mit allen Katalogfeldern');

            // plans.name, plans.description
            $browser->assertSee('Matrix Team');
            $this->assertSame('Seats, addons and a per-seat addon.', trim($browser->text('[dusk="plan-description-mx-team"]')));

            // plans.intervals.base_price_net — gross for a German consumer: 29.50 + 19 %.
            $this->assertShownAmount($browser, 'plan-price-mx-team', 3511, 'monthly price on the card');

            // plans.included_seats, plans.intervals.seat_price_net
            $browser->assertSee(trans_choice('billing::checkout.seats_included', 2, ['count' => 2]));
            $browser->assertSee(__('billing::checkout.quota_extra_price', [
                'currency' => BillingMoney::symbol(), 'price' => BillingMoney::numberFromMinor(660), 'unit' => __('billing::checkout.seat_unit'),
            ]));

            // plans.intervals.included_usages, usage_overage_prices (0.07 + 19 % = 0.08)
            $browser->assertSee(__('billing::checkout.usage_included', ['count' => '10', 'type' => 'Tokens']));
            $browser->assertSee(__('billing::checkout.quota_extra_price', [
                'currency' => BillingMoney::symbol(), 'price' => BillingMoney::numberFromMinor(8), 'unit' => 'Tokens',
            ]));

            // plans.feature_keys, features.name, features.description, features.visible
            $browser->assertSee('Matrix Reports')->assertDontSee('Matrix Internal Flag');
            $this->assertGreaterThan(0, $this->domCount($browser, '[title="Shown on the plan card."]'), 'the feature description is on the card');
            $this->assertSame(0, $this->domCount($browser, '[title="Never shown."]'), 'a hidden feature leaves no trace');

            // plans.intervals.trial_days
            $browser->assertSee(__('billing::checkout.trial_days', ['days' => 7]));

            // plans.allowed_addons, addons.name, addons.intervals.price_net
            $browser->assertSee('Matrix CRM');
            $browser->assertSee(__('billing::checkout.addon_price', [
                'currency' => BillingMoney::symbol(), 'price' => BillingMoney::numberFromMinor(1190), 'interval' => __('billing::checkout.per_month'),
            ]));

            // plans.hidden — the workbench's hidden plan is not offered.
            $browser->assertDontSee('Ops Team');
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click*="\'ops-team\'"]'));

            // enum SubscriptionInterval: switching to yearly re-prices the card (299.50 + 19 %).
            $this->jsClick($browser, 'ui-radio[value="yearly"]');
            $browser->pause(900);
            $this->assertShownAmount($browser, 'plan-price-mx-team', 35641, 'yearly price on the card');
            $this->shot($browser, 'Jährlich');

            // A plan sold monthly only is not offered a yearly price.
            $this->assertShownAmount($browser, 'plan-price-mx-trial', 0, 'a plan not sold yearly prices at nothing — and cannot be picked');
        });
    }

    public function test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable, '?plan=mx-team');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix CRM', 10)->pause(400);

            // allowed_addons: exactly the two this plan allows.
            $this->assertSame(1, $this->domCount($browser, '[value="softdrinks"]'));
            $this->assertSame(1, $this->domCount($browser, '[value="mx-crm"]'));
            $this->assertSame(0, $this->domCount($browser, '[value="priority-support"]'));

            // addons.seat_price_net and addons.included_seats, in words (3.33 + 20 % = 4.00).
            $browser->assertSee(__('billing::checkout.addon_seat_price', ['price' => $this->money(400)]));
            $browser->assertSee(trans_choice('billing::checkout.addon_included_seats', 1, ['count' => 1]));

            // plans.seat_price_net: the seat field is there, with its price (5.55 + 20 %).
            $this->assertSame(1, $this->domCount($browser, 'input[type="number"]'));
            $browser->assertSee($this->money(666));
            $this->shot($browser, 'Extras: Addons, Per-Seat-Preis, Sitze');

            $this->jsClick($browser, '[value="mx-crm"]');
            $browser->script('const i = document.querySelector(\'input[type="number"]\'); i.value = "1"; i.dispatchEvent(new Event("input", {bubbles: true})); i.dispatchEvent(new Event("change", {bubbles: true}));');
            $browser->pause(900);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(500);

            // 29.50 + 1 seat 5.55 + CRM 10.00 + (3 seats − 1 free) × 3.33 = 51.71 net.
            $this->assertShownAmount($browser, 'confirm-net', 5171, 'net with the per-seat addon');
            $this->assertShownAmount($browser, 'confirm-total', self::cmGross([2950, 555, 1000, 666], 20.0), 'total with VAT per line');
        });
    }

    public function test_the_extras_step_prints_an_addons_description(): void
    {
        $this->cmCatalog();
        $this->cmConfig('mollie-billing-plans.addons.mx-crm.description', 'Customer records for the whole team.');
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable, '?plan=mx-team');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix CRM', 10)->pause(400);

            $this->assertSame('Customer records for the whole team.', trim($browser->text('[dusk="addon-description-mx-crm"]')));
            $this->shot($browser, 'Extras: Addon mit Beschreibung');
        });
    }

    public function test_a_dated_code_that_ends_before_the_trial_does_is_refused_at_the_field(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        // Seven days of trial; the code is over in three.
        $this->coupons()->create(['code' => 'SHORT', 'name' => 'Short', 'active' => true, 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'valid_until' => now()->addDays(3)]);
        // … and one that outlives the trial is taken.
        $this->coupons()->create(['code' => 'LONG', 'name' => 'Long', 'active' => true, 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'valid_until' => now()->addDays(30)]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');

            $this->cmApplyCoupon($browser, 'SHORT');
            $browser->assertSee(__('billing::checkout.coupon_ends_before_first_charge'));
            $this->assertSame(0, $this->domCount($browser, '[dusk="confirm-discount"]'), 'nothing comes off the first charge');
            // 19.50 + 20 %.
            $this->assertShownAmount($browser, 'confirm-total', 2340, 'the first charge is the full price');
            $this->shot($browser, 'Trial: Code endet vor der ersten Abbuchung');

            $this->cmApplyCoupon($browser, 'LONG');
            $browser->assertDontSee(__('billing::checkout.coupon_ends_before_first_charge'));
            // 19.50 − 2.93 = 16.57 net, + 20 % per line: 23.40 − 3.52.
            $this->assertShownAmount($browser, 'confirm-total', self::cmGross([1950, -293], 20.0), 'a code that outlives the trial discounts the first charge');
        });
    }

    public function test_a_plan_without_seat_price_or_addons_has_no_extras_step(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable, '?plan=mx-trial');
            $browser->assertSee(__('billing::checkout.step_counter', ['current' => 1, 'total' => 2]), true);

            $this->clickWire($browser, '[wire\\:click="next"]');

            // Straight to the confirm step: no addon list, no seat field.
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->assertSame(0, $this->domCount($browser, 'input[type="number"]'));
            $browser->assertSee(__('billing::checkout.headline_confirm'));
        });
    }

    public function test_an_addons_features_are_listed_on_the_addons_page_and_a_hidden_one_is_not(): void
    {
        $this->cmCatalog();
        $this->cmConfig('mollie-billing-plans.addons.mx-crm.feature_keys', ['mx-reports', 'mx-internal']);
        $billable = $this->makeActiveSubscriber('mx-team', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Matrix CRM', 15)->pause(400);
            $this->shot($browser, 'Addons mit Features');

            // addons.feature_keys → the visible feature by its name; the hidden one nowhere.
            $browser->assertSee('Matrix Reports')->assertDontSee('Matrix Internal Flag');
        });

        // … while both unlock once the addon is active.
        $billable->forceFill(['active_addon_codes' => ['mx-crm']])->save();
        $this->assertTrue($billable->refresh()->hasPlanFeature('mx-internal'));
    }

    public function test_a_rollover_usage_type_keeps_unused_units_across_the_renewal_and_another_is_reset(): void
    {
        // Workbench catalog: Tokens roll over, SMS do not. Starter includes 50 / 20.
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $billable->recordBillingUsage('Tokens', 20);
        $billable->recordBillingUsage('SMS', 5);

        $this->renewSubscription($billable);

        // 30 unused + 50 new Tokens; SMS back at 20.
        $this->assertSame(80, $billable->refresh()->remainingBillingQuota('Tokens'));
        $this->assertSame(20, $billable->remainingBillingQuota('SMS'));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-Tokens"]', 15)->pause(600);
            $this->shot($browser, 'Nutzung nach der Verlängerung');

            // The customer can see the 30 units that were carried over — and none for SMS.
            $carried = __('billing::portal.usage_carried_over', ['count' => '30']);
            $this->assertStringContainsString($carried, $browser->text('[dusk="usage-meter-Tokens"]'));
            $this->assertStringNotContainsString(
                trim(explode('30', $carried)[1] ?? $carried),
                $browser->text('[dusk="usage-meter-SMS"]'),
                'a type that does not roll over carries nothing',
            );
        });
    }

    // ── The checkout's own actions ─────────────────────────────────────────────

    public function test_next_and_back_walk_the_steps_and_the_stepper_follows(): void
    {
        $this->cmCatalog();

        $this->browse(function (Browser $browser): void {
            $this->anonymousCheckout($browser, '?plan=mx-team');

            // stepHeadline / stepDescription / displayStep / totalSteps / timelineSteps
            $browser->assertSee(__('billing::checkout.step_counter', ['current' => 1, 'total' => 4]), true)
                ->assertSee(__('billing::checkout.headline_billing'))
                ->assertSee(__('billing::checkout.description_billing'))
                ->assertSee(__('billing::checkout.step_billing_details'))
                ->assertSee(__('billing::checkout.step_plan'))
                ->assertSee(__('billing::checkout.step_addons'))
                ->assertSee(__('billing::checkout.step_confirm'));

            // next() validates the step it leaves: nothing typed, nothing moves.
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->pause(900)->assertSee(__('billing::checkout.headline_billing'));
            $this->shot($browser, 'Pflichtfelder fehlen');

            $this->fillBillingStep($browser, 'AT');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText(__('billing::checkout.headline_plan'), 10)
                ->assertSee(__('billing::checkout.step_counter', ['current' => 2, 'total' => 4]), true);

            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText(__('billing::checkout.headline_addons'), 10)
                ->assertSee(__('billing::checkout.step_counter', ['current' => 3, 'total' => 4]), true);

            // back() returns to the plan step with the selection intact.
            $this->clickWire($browser, '[wire\\:click="back"]');
            $browser->waitForText(__('billing::checkout.headline_plan'), 10);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText(__('billing::checkout.headline_addons'), 10);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)
                ->assertSee(__('billing::checkout.headline_confirm'))
                ->assertSee(__('billing::checkout.description_confirm'))
                ->assertSee(__('billing::checkout.step_counter', ['current' => 4, 'total' => 4]), true);

            // The address typed in step 1 is what the confirm step shows.
            $browser->assertSee('Musterweg 12');
            $this->shot($browser, 'Zusammenfassung nach vor und zurück');
        });
    }

    public function test_an_app_provided_step_comes_first_is_counted_and_is_validated(): void
    {
        $this->cmCatalog();
        WorkbenchSetting::set('checkout_custom_step', '1');

        $this->browse(function (Browser $browser): void {
            $browser->visit('/');
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/billing/checkout?plan=mx-team')->waitForText('Accept the workbench terms', 15)->pause(400);

            // customStepCount: one more step, in front.
            $browser->assertSee(__('billing::checkout.step_counter', ['current' => 1, 'total' => 5]), true)
                ->assertSee('An app-provided step, before the package steps.')
                ->assertSee('Terms');

            // Its validator holds the customer on it …
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[dusk="wb-terms-error"]', 10)->assertSee('Please accept the workbench terms.');
            $this->shot($browser, 'Eigener Schritt: Validierung');

            // … until it is satisfied.
            $browser->click('[dusk="wb-terms"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('input[name="company_name"]', 10)
                ->assertSee(__('billing::checkout.step_counter', ['current' => 2, 'total' => 5]), true);
        });
    }

    public function test_changing_the_country_reprices_and_a_vat_number_is_checked_as_it_is_typed(): void
    {
        $this->cmCatalog();

        $this->browse(function (Browser $browser): void {
            $this->anonymousCheckout($browser, '?plan=mx-team');

            $vatSays = fn (string $key) => $browser->waitForText(__('billing::checkout.'.$key), 15);

            // Germany, consumer: 19 % on the plan cards.
            $this->fillBillingStep($browser, 'DE');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix Team', 10)->pause(500);
            $this->assertShownAmount($browser, 'plan-price-mx-team', 3511, 'DE consumer: 19 %');

            // Back, and to Austria: 20 %.
            $this->clickWire($browser, '[wire\\:click="back"]');
            $browser->waitFor('input[name="company_name"]', 10)->select('billing_country', 'AT')->pause(900);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix Team', 10)->pause(500);
            $this->assertShownAmount($browser, 'plan-price-mx-team', 3540, 'AT consumer: 20 %');

            // Back again: an unknown VAT number is refused at the field and blocks the step.
            $this->clickWire($browser, '[wire\\:click="back"]');
            $browser->waitFor('input[name="company_name"]', 10)->select('billing_country', 'DE')->pause(600)
                ->type('vat_number', 'DE000000000')
                ->waitForText(__('billing::checkout.vies_validation_failed'), 20);
            $this->shot($browser, 'USt-IdNr. nicht gültig');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->pause(1200)->assertSee(__('billing::checkout.headline_billing'));

            // A number for another country than the one chosen is refused too.
            $browser->type('vat_number', 'ATU69934418')
                ->waitForText(__('billing::checkout.vat_country_mismatch'), 20);

            // A VIES-valid German number, Austrian seller: verified, and prices turn net.
            $browser->type('vat_number', 'DE129273398');
            $vatSays('vat_verified');
            $this->shot($browser, 'USt-IdNr. bestätigt');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix Team', 10)->pause(500)
                ->assertSee(__('billing::checkout.prices_net'));
            $this->assertShownAmount($browser, 'plan-price-mx-team', 2950, 'reverse charge: net price');

            // A domestic business is verified and still pays VAT.
            $this->clickWire($browser, '[wire\\:click="back"]');
            $browser->waitFor('input[name="company_name"]', 10)->select('billing_country', 'AT')
                ->waitForText(__('billing::checkout.vat_country_mismatch'), 20)->pause(800)
                ->type('vat_number', 'ATU69934418');
            $vatSays('vat_verified_domestic');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Matrix Team', 10)->pause(500);
            $this->assertShownAmount($browser, 'plan-price-mx-team', 3540, 'domestic business: 20 %');
        });
    }

    public function test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('DE');
        $this->coupons()->create(['code' => 'TEN', 'name' => 'Ten percent', 'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 10]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-team', 'monthly', ['softdrinks']);

            // No coupon: net, VAT (per line) and total.
            $this->assertShownAmount($browser, 'confirm-net', 3440, 'net');
            $this->assertShownAmount($browser, 'confirm-vat', self::cmGross([2950, 490], 19.0) - 3440, 'VAT');
            $this->assertShownAmount($browser, 'confirm-total', self::cmGross([2950, 490], 19.0), 'total');
            $browser->assertSee(__('billing::checkout.vat_with_rate', ['rate' => '19']));

            $this->cmApplyCoupon($browser, 'TEN');
            $this->assertShownAmount($browser, 'confirm-subtotal', 3440, 'subtotal');
            $this->assertShownAmount($browser, 'confirm-discount', 344, 'discount');
            $this->assertShownAmount($browser, 'confirm-net', 3096, 'net after the coupon');
            $this->assertShownAmount($browser, 'confirm-total', self::cmGross([2950, 490, -344], 19.0), 'total after the coupon');
            $this->shot($browser, 'Zusammenfassung mit Coupon');

            $this->clickWire($browser, '[wire\\:click="removeCoupon"]');
            $browser->pause(1200);
            $this->assertSame(0, $this->domCount($browser, '[dusk="confirm-discount"]'), 'the discount line is gone');
            $this->assertShownAmount($browser, 'confirm-total', self::cmGross([2950, 490], 19.0), 'total after removing the coupon');

            // An empty field is answered at the field.
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->pause(900)->assertSee(__('billing::checkout.coupon_empty'));
        });
    }

    public function test_a_trial_checkout_names_its_days_and_the_date_of_the_first_charge(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');
        $this->coupons()->create(['code' => 'PLUS9', 'name' => 'Nine more days', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 9]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');

            $browser->assertSee(__('billing::checkout.trial_summary_heading', ['days' => 7]))
                ->assertSee(now('UTC')->addDays(7)->translatedFormat('d. M Y'));
            $this->shot($browser, 'Trial: 7 Tage');

            $this->cmApplyCoupon($browser, 'PLUS9');

            $browser->assertSee(__('billing::checkout.trial_summary_heading', ['days' => 16]))
                ->assertSee(now('UTC')->addDays(16)->translatedFormat('d. M Y'));
            $this->shot($browser, 'Trial mit Verlängerung: 16 Tage');
        });
    }

    public function test_a_returning_customer_is_offered_no_trial(): void
    {
        $this->cmCatalog();
        $billable = $this->cmReturningCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable);
            $browser->assertDontSee(__('billing::checkout.trial_days', ['days' => 7]));

            $browser->visit('/billing/checkout?plan=mx-trial')->waitFor('[wire\\:click="next"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400)
                ->assertDontSee(__('billing::checkout.trial_summary_heading', ['days' => 7]))
                ->assertSee(__('billing::checkout.redirect_notice'));
            $this->assertShownAmount($browser, 'confirm-total', 2340, 'full price, due now');
        });
    }

    // ── Billing data ───────────────────────────────────────────────────────────

    public function test_billing_data_warns_when_the_address_country_differs_from_the_payment_country(): void
    {
        // Address and card are both German.
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $warning = __('billing::portal.billing_data.payment_country_mismatch_inline', ['declared' => 'AT', 'payment' => 'DE']);

        $this->browse(function (Browser $browser) use ($billable, $warning): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(400);
            $browser->assertDontSee($warning)
                ->assertDontSee(__('billing::portal.billing_data.country_dirty_inline'));

            // countries(): the selector offers the EU, and nothing that cannot be invoiced.
            $offered = (array) $browser->script('return [...document.querySelectorAll(\'[name="billing_country"] option\')].map(o => o.value)')[0];
            $this->assertContains('AT', $offered);
            $this->assertContains('FR', $offered);
            $this->assertNotContains('US', $offered);

            // paymentCountryMismatch(): another country than the card's warns at once.
            $browser->select('billing_country', 'AT')->pause(1500);
            $browser->assertSee($warning)
                ->assertSee(__('billing::portal.billing_data.country_dirty_inline'));
            $this->shot($browser, 'Land weicht vom Zahlungsland ab');

            // save()
            $this->clickWire($browser, '[wire\\:click="save"]');
            $browser->pause(1800);
        });

        $this->assertSame('AT', $billable->refresh()->billing_country);
        $this->assertSame('AT', $billable->tax_country_user);
        $this->assertSame('DE', $billable->tax_country_payment);
    }

    public function test_billing_data_marks_a_verified_cross_border_business_as_reverse_charge(): void
    {
        // German address, VIES-valid German number, Austrian seller.
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['vat_number' => 'DE129273398']);
        \GraystackIT\MollieBilling\Models\BillingVatValidation::query()->create([
            'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
            'vat_number' => 'DE129273398', 'country_code' => 'DE', 'valid' => true, 'vies_response' => [], 'checked_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(600)
                ->assertSee(__('billing::portal.billing_data.badge_reverse_charge'));
            $this->shot($browser, 'Rechnungsdaten: Reverse Charge');
        });

        // A consumer at the same address is not.
        $consumer = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($consumer): void {
            $this->actAs($browser, $consumer, '/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(600)
                ->assertDontSee(__('billing::portal.billing_data.badge_reverse_charge'));
        });
    }

    public function test_billing_data_shows_the_mandate_and_a_new_payment_method_replaces_it(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $oldMandate = $billable->mollie_mandate_id;

        $this->browse(function (Browser $browser) use ($billable, $oldMandate): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(400);

            // mandateInfo(): the mandate on file is on the page, with its method and state.
            $browser->assertSee(__('billing::portal.payment_method.method.creditcard'))
                ->assertSee(__('billing::portal.payment_method.status.valid'));
            $this->shot($browser, 'Zahlungsmittel');

            // changePaymentMethod(): a €0 authorisation at Mollie.
            $this->confirmInModal($browser, 'change-payment-method', '[wire\\:click="changePaymentMethod"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $this->assertSame(0, self::cmMinor($this->latestFakePayment()['amount_value']));
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');

            // Back on the page, which notices the new mandate (checkForMandateUpdate()).
            $this->waitForPathContains($browser, '/billing/billing-data');
            $browser->waitUntilMissingText(__('billing::portal.payment_method.awaiting_update'), 20)
                ->assertSee(__('billing::portal.payment_method.status.valid'));
            $this->shot($browser, 'Neues Zahlungsmittel');
        });

        $billable->refresh();
        $this->assertNotSame($oldMandate, $billable->mollie_mandate_id, 'the mandate was replaced');
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, 'the subscription is untouched');
        $this->assertSame(0, BillingInvoice::query()->count(), 'a mandate change books nothing');
    }

    public function test_billing_data_stops_waiting_for_a_payment_method_change_that_is_never_confirmed(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $oldMandate = $billable->mollie_mandate_id;

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(400);
            $this->confirmInModal($browser, 'change-payment-method', '[wire\\:click="changePaymentMethod"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');

            // The customer comes back without having paid.
            $browser->visit('/billing/billing-data?payment_method_updated=1')
                ->waitForText(__('billing::portal.payment_method.awaiting_update'), 15);
            $polls = fn (): int => (int) $browser->script('return [...document.querySelectorAll("*")].filter(e => [...e.attributes].some(a => a.name.startsWith("wire:poll"))).length')[0];
            $this->assertSame(1, $polls(), 'the page polls while it waits');

            // 45 ticks of 2 s later it gives up, says so, and no longer polls.
            $browser->waitForText(__('billing::portal.payment_method.update_timeout'), 150)
                ->assertDontSee(__('billing::portal.payment_method.awaiting_update'));
            $this->assertSame(0, $polls(), 'a page that gave up no longer polls');
            $this->shot($browser, 'Zahlungsmittel: Bestätigung blieb aus');

            // recheckMandateUpdate(): nothing new yet — back to waiting.
            $this->clickWire($browser, '[wire\\:click="recheckMandateUpdate"]');
            $browser->waitForText(__('billing::portal.payment_method.awaiting_update'), 10);
            $this->assertSame(1, $polls(), 'checking again resumes polling');

            // The payment settles after all: the next tick notices the new mandate.
            $payment = $this->latestFakePayment();
            \Workbench\App\FakeMollie\FakeMollie::settle($payment['id'], 'paid', 'DE');
            $this->deliverWebhook($payment['id']);
            $browser->waitUntilMissingText(__('billing::portal.payment_method.awaiting_update'), 20)
                ->assertDontSee(__('billing::portal.payment_method.update_timeout'));
            $this->assertSame(0, $polls());
        });

        $this->assertNotSame($oldMandate, $billable->refresh()->mollie_mandate_id);
    }

    // ── Products ───────────────────────────────────────────────────────────────

    public function test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->coupons()->create(['code' => 'P15', 'name' => 'Fifteen', 'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 15]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);
            $browser->script("window.Flux.modal('purchase-token-pack-500').show()");
            $input = 'input[wire\\:model="couponInputs.token-pack-500"]';
            $browser->waitFor($input, 10)->pause(300)->type($input, 'P15');
            $this->clickWire($browser, '[wire\\:click="applyCoupon(\'token-pack-500\')"]');
            $browser->pause(1200);

            // 49.00 − 7.35 = 41.65, + 19 %.
            $this->assertShownAmount($browser, 'product-total-token-pack-500', self::cmGross([4900, -735], 19.0), 'amount to pay with the coupon');
            $this->shot($browser, 'Produkt mit Coupon');

            $this->clickWire($browser, '[wire\\:click*="removeCoupon"]');
            $browser->pause(1200);
            $this->assertSame(0, $this->domCount($browser, '[dusk="product-total-token-pack-500"]'), 'no discounted amount once the coupon is removed');
            $this->assertSame(1, $this->domCount($browser, $input), 'the field is back');

            $this->clickWire($browser, '[wire\\:click="purchase(\'token-pack-500\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
            $browser->assertSee(__('billing::portal.return.body_products'));
        });

        $this->assertSame(5831, self::cmMinor($this->latestFakePayment()['amount_value']));
        $this->assertSame(5831, (int) BillingInvoice::query()->where('invoice_kind', InvoiceKind::OneTimeOrder)->sole()->amount_gross);
    }

    // ── Return page, every outcome ─────────────────────────────────────────────

    /** @return array<string, array{0:string}> */
    public static function failedOutcomes(): array
    {
        return ['failed' => ['failed'], 'canceled' => ['canceled'], 'expired' => ['expired']];
    }

    #[DataProvider('failedOutcomes')]
    public function test_the_return_page_names_a_payment_that_did_not_go_through_and_offers_the_way_back(string $outcome): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable, $outcome): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, $outcome, 'AT');

            $browser->waitForText(__('billing::portal.return.failed_title'), 20)
                ->assertSee(__('billing::portal.return.failed_body_'.$outcome))
                ->assertSee(__('billing::portal.return.try_again'));
            $this->shot($browser, 'Return-Seite: '.$outcome);

            // retryUrl(): back to the checkout.
            $browser->clickLink(__('billing::portal.return.try_again'));
            $this->waitForPathContains($browser, '/billing/checkout');
        });

        $billable->refresh();
        $this->assertNotSame(SubscriptionStatus::Trial, $billable->subscription_status);
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertNull($billable->getPendingFirstPaymentId(), 'the failed payment is no longer pending');
    }

    public function test_the_return_page_waits_while_the_payment_is_still_open_and_activates_when_it_settles(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-team');
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');

            // The customer comes back before paying: the page waits (shouldPoll()).
            $browser->visit('/billing/return')->waitForText(__('billing::portal.return.processing_title'), 15);
            $browser->assertSee(__('billing::portal.return.processing_body'))
                ->assertDontSee(__('billing::portal.return.title'))
                ->assertDontSee(__('billing::portal.return.failed_title'));
            $polls = fn (): int => (int) $browser->script('return [...document.querySelectorAll("*")].filter(e => [...e.attributes].some(a => a.name.startsWith("wire:poll") && a.value === "checkStatus")).length')[0];
            $this->assertSame(1, $polls(), 'the page polls checkStatus while the payment is open');
            $this->shot($browser, 'Return-Seite: Zahlung noch offen');

            // The payment settles in the background; the next poll (checkStatus()) shows it.
            $payment = $this->latestFakePayment();
            \Workbench\App\FakeMollie\FakeMollie::settle($payment['id'], 'paid', 'AT');
            $this->deliverWebhook($payment['id']);

            $browser->waitForText(__('billing::portal.return.title'), 20)
                ->assertSee(__('billing::portal.return.to_dashboard'));
            $this->assertSame(0, $polls(), 'and stops once the subscription is active');
            $this->shot($browser, 'Return-Seite: aktiviert');

            // returnUrl(): on to the dashboard.
            $browser->clickLink(__('billing::portal.return.to_dashboard'));
            $browser->waitForText('Matrix Team', 15);
        });

        $this->assertSame(SubscriptionStatus::Active, $billable->refresh()->subscription_status);
    }

    public function test_the_return_page_gives_up_waiting_after_its_tick_budget_and_check_again_resumes(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-team');
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');

            // Never paid: 5 ticks at 2 s, then 5 s each (pollInterval()), 22 in all.
            $browser->visit('/billing/return')->waitForText(__('billing::portal.return.processing_title'), 15);
            $browser->waitForText(__('billing::portal.return.timeout_title'), 150)
                ->assertSee(__('billing::portal.return.check_again'));
            $polls = fn (): int => (int) $browser->script('return [...document.querySelectorAll("*")].filter(e => [...e.attributes].some(a => a.name.startsWith("wire:poll"))).length')[0];
            $this->assertSame(0, $polls(), 'a timed-out page no longer polls');
            $this->shot($browser, 'Return-Seite: Zeit abgelaufen');

            // retry(): back to waiting, polling fast again.
            $this->clickWire($browser, '[wire\\:click="retry"]');
            $browser->waitForText(__('billing::portal.return.processing_title'), 10);
            $this->assertSame(1, (int) $browser->script('return document.querySelectorAll(\'[wire\\\\:poll\\\\.2s="checkStatus"]\').length')[0], 'polling resumed at the fast interval');
        });

        $this->assertFalse($billable->refresh()->hasAccessibleBillingSubscription());
    }

    // ── Screens ────────────────────────────────────────────────────────────────

    public function test_the_dashboard_states_the_plan_the_checkout_booked(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-team', 'yearly', ['softdrinks']);
            $this->cmSubmitAndPay($browser, 'AT');

            $browser->visit('/billing')->waitForText('Matrix Team', 15)->pause(500);
            $browser->assertSee('Softdrinks');
            $this->shot($browser, 'Dashboard nach der Buchung');
        });

        $this->assertSame('yearly', $billable->refresh()->getBillingSubscriptionInterval());
    }

    public function test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout(): void
    {
        $this->cmConfig('mollie-billing.ip_block', ['enabled' => true, 'mode' => 'blocklist', 'countries' => ['RU'], 'block_unknown' => false]);

        $this->browse(function (Browser $browser): void {
            // Allowed country: the checkout opens.
            $this->cmVisitorFrom('DE');
            $browser->visit('/');
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit('/billing/checkout')->waitFor('input[name="company_name"]', 15);

            // Listed country: the blocked page, naming the country.
            $this->cmVisitorFrom('RU');
            $browser->visit('/billing/checkout');
            $this->waitForPathContains($browser, '/billing/blocked');
            $browser->assertSee(__('billing::portal.blocked.heading'))
                ->assertSee(__('billing::portal.blocked.detected_country', ['code' => 'RU']));
            $this->shot($browser, 'Gesperrtes Land');
        });

        // Allowlist mode turns the list around; an unknown country follows block_unknown.
        $this->cmConfig('mollie-billing.ip_block', ['enabled' => true, 'mode' => 'allowlist', 'countries' => ['RU'], 'block_unknown' => true]);

        $this->browse(function (Browser $browser): void {
            $this->cmVisitorFrom('RU');
            $browser->visit('/billing/checkout')->waitFor('input[name="company_name"]', 15);

            $this->cmVisitorFrom(null);
            $browser->visit('/billing/checkout');
            $this->waitForPathContains($browser, '/billing/blocked');
            $browser->assertSee(__('billing::portal.blocked.body_unknown_country'));
        });
    }

    // ── Configuration that steers something visible ────────────────────────────

    public function test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step(): void
    {
        $this->cmConfig('mollie-billing.checkout_back_url', '/?from=checkout-back');
        $this->cmConfig('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => ['CH'], 'exclude' => ['MT']]);
        $this->cmConfig('mollie-billing.additional_countries', ['CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland']]);
        $this->cmConfig('mollie-billing.default_billing_country', 'NL');
        $this->cmVisitorFrom(null);

        $this->browse(function (Browser $browser): void {
            $this->anonymousCheckout($browser);

            // checkout_back_url
            $this->assertGreaterThan(0, $this->domCount($browser, 'a[href="/?from=checkout-back"]'), 'the back link leads where the config says');

            // checkout_countries.* / additional_countries: what the selector offers.
            $options = fn (): array => (array) $browser->script('return [...document.querySelectorAll(\'select[name="billing_country"] option, [name="billing_country"] option\')].map(o => o.value)')[0];
            $offered = $options();
            $this->assertContains('CH', $offered, 'an additional country is offered');
            $this->assertContains('DE', $offered, 'the EU region is offered');
            $this->assertNotContains('MT', $offered, 'an excluded country is not');
            $this->assertNotContains('GB', $offered);

            // default_billing_country: preselected when the IP says nothing.
            $this->assertSame('NL', $browser->value('[name="billing_country"]'));
            $this->shot($browser, 'Erster Schritt: Länder, Vorauswahl');
        });

        // ip_geolocation.driver: with a country for the visitor's IP, that one is preselected.
        $this->cmVisitorFrom('FR');

        $this->browse(function (Browser $browser): void {
            $this->anonymousCheckout($browser);
            $this->assertSame('FR', $browser->value('[name="billing_country"]'));
        });
    }

    public function test_config_currency_symbol_and_vat_rate_override_change_the_prices_a_customer_reads(): void
    {
        $this->cmCatalog();
        $this->cmConfig('mollie-billing.currency_symbol', 'Fr.');
        $this->cmConfig('mollie-billing.vat_rate_overrides', ['DE' => 7.0]);
        $billable = $this->cmCustomer('DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->planStep($browser, $billable);

            $shown = $browser->text('[dusk="plan-price-mx-team"]');
            $this->assertStringContainsString('Fr.', $shown, 'currency_symbol');
            $this->assertStringNotContainsString('€', $shown);

            // 29.50 + 7 % = 31.57 instead of 19 %.
            $this->assertShownAmount($browser, 'plan-price-mx-team', 3157, 'vat_rate_overrides');
            $this->shot($browser, 'Eigenes Währungssymbol, überschriebener Satz');
        });
    }

    public function test_config_seller_country_decides_whether_a_business_customer_sees_net_prices(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('RC');

        $this->browse(function (Browser $browser) use ($billable): void {
            // Seller AT (workbench default), customer a German business: reverse charge.
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');
            $browser->assertSee(__('billing::checkout.reverse_charge'));
            $this->assertShownAmount($browser, 'confirm-total', 1950, 'cross-border: net');
        });

        // The same customer of a GERMAN seller is a domestic one: VAT is charged.
        $this->cmConfig('mollie-billing.vat.seller_country', 'DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');
            $browser->assertDontSee(__('billing::checkout.reverse_charge'));
            $this->assertShownAmount($browser, 'confirm-total', 2321, 'domestic: 19 %');
        });

        // No seller country at all, and the strict switch on: no reverse charge either.
        $this->cmConfig('mollie-billing.vat.seller_country', null);
        $this->cmConfig('mollie-billing.invoices.seller.address.country', null);
        $this->cmConfig('mollie-billing.vat.require_seller_country', true);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-trial');
            $browser->assertDontSee(__('billing::checkout.reverse_charge'));
            $this->assertShownAmount($browser, 'confirm-total', 2321, 'unknown seller, strict: VAT is charged');
        });
    }

    public function test_config_redirect_after_return_is_where_a_free_booking_lands(): void
    {
        $this->cmCatalog();
        $this->cmConfig('mollie-billing.redirect_after_return', 'wb.home');
        $billable = $this->cmCustomer('AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cmWalkToConfirm($browser, $billable, 'mx-free');
            $this->assertShownAmount($browser, 'confirm-total', 0, 'a free plan costs nothing');
            $this->clickWire($browser, '[wire\\:click="submit"]');

            // Not the billing dashboard: the app's own route.
            $browser->waitUsing(15, 200, fn (): bool => parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH) === '/');
        });

        $this->assertSame(SubscriptionSource::Local, $billable->refresh()->subscription_source);
        $this->assertSame('mx-free', $billable->subscription_plan_code);
        $this->assertSame(0, DB::table('fake_mollie_payments')->count());
    }

    public function test_config_yearly_savings_badge_can_be_switched_off(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Business', 15)->pause(500);

            // Workbench catalog: 12 × 9.90 against 99.00 a year — 17 % saved.
            $browser->assertSee(__('billing::portal.save_up_to', ['percent' => 17]));
            $this->shot($browser, 'Jahresersparnis sichtbar');
        });

        $this->cmConfig('mollie-billing.show_yearly_savings', false);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Business', 15)->pause(500);
            $browser->assertDontSee(__('billing::portal.save_up_to', ['percent' => 17]));
        });
    }

    public function test_config_local_one_time_orders_and_country_fallback_on_the_products_page(): void
    {
        $this->cmCatalog();

        // A customer on a free (local) plan, with no billing country on file.
        $billable = $this->makeBillable([
            'billing_country' => null,
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(3),
        ]);

        // local_subscription.allow_one_time_orders = false (default): nothing to buy.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);
            $browser->assertSee(__('billing::portal.free_plan_no_products'));
            $this->assertGreaterThan(0, $this->domCount($browser, 'button[disabled]'), 'the buy buttons are disabled on a free plan');
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="purchase(\'token-pack-500\')"]'), 'and the page carries no purchase dialog');
            $this->shot($browser, 'Gratis-Plan: Produkte gesperrt');
        });

        $this->cmConfig('mollie-billing.local_subscription.allow_one_time_orders', true);
        $this->cmConfig('mollie-billing.checkout_country_fallback', 'AT');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);

            $browser->assertDontSee(__('billing::portal.free_plan_no_products'));

            // checkout_country_fallback: no country on file → priced at the fallback's 20 %.
            $this->assertShownAmount($browser, 'product-price-token-pack-500', 5880, 'price at the fallback country');

            $this->confirmInModal($browser, 'purchase-token-pack-500', '[wire\\:click="purchase(\'token-pack-500\')"]');
            $this->settleAtFakeMollie($browser, 'paid', 'AT');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $this->assertSame(5880, self::cmMinor($this->latestFakePayment()['amount_value']));
        $invoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::OneTimeOrder)->sole();
        $this->assertSame(5880, (int) $invoice->amount_gross);
        $this->assertSame('AT', $invoice->country);
        $this->assertSame('AT', $billable->refresh()->billing_country, 'the fallback country is now on file');
    }
}

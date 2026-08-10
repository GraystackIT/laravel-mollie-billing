<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * Coupons at the point of sale: a percentage off the first payment, and the
 * 100% coupon that removes money from the flow entirely — Mollie then only
 * captures the mandate for period two.
 */
class CouponCheckoutTest extends BrowserTestCase
{
    private function walkToConfirm(Browser $browser, TestBillable $billable, string $plan = 'starter'): void
    {
        $this->actAs($browser, $billable, '/billing/checkout');

        $browser->waitForText('Starter', 10);
        $browser->click('[wire\\:click*="\''.$plan.'\'"]')->pause(600);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitForText('Softdrinks', 10);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
    }

    public function test_a_percentage_coupon_discounts_the_first_payment(): void
    {
        $this->coupons()->create([
            'code' => 'WELCOME50',
            'name' => 'Half off the first payment',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 50,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $browser->type('coupon_input', 'WELCOME50');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('WELCOME50', 10)->pause(400);
            $this->shot($browser, 'Coupon angewendet: Summe halbiert');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        $billable->refresh();
        $invoice = BillingInvoice::query()->sole();

        // 990 net − 495 coupon = 495 net → 589 gross at 19%.
        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and((int) $invoice->amount_gross)->toBe(589)
            ->and(collect($invoice->line_items)->firstWhere('kind', 'coupon'))->not->toBeNull();
    }

    public function test_a_hundred_percent_coupon_rides_the_mandate_only_flow(): void
    {
        $this->coupons()->create([
            'code' => 'FREEBIE',
            'name' => 'First payment on the house',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 100,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $browser->type('coupon_input', 'FREEBIE');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('FREEBIE', 10)->pause(400);
            $this->shot($browser, '100%-Coupon: Total 0,00 €');

            $this->clickWire($browser, '[wire\\:click="submit"]');

            // Even €0.00 goes to Mollie — the mandate for period two.
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Starter', 10);
            $this->shot($browser, 'Aktiv ohne Zahlung — Mandat sitzt');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->mollie_mandate_id)->not->toBeNull()
            ->and((int) $this->latestFakePayment()['amount_value'] * 100)->toBe(0);
    }

    /**
     * A trial extension buys days, not money — so nothing about it shows up in the
     * total, and the only way to know it worked is to read the trial the checkout
     * promises and the trial the activation actually grants.
     */
    public function test_a_trial_extension_coupon_lengthens_the_trial_it_is_applied_to(): void
    {
        $this->coupons()->create([
            'code' => 'PLUS7',
            'name' => 'Seven more days to try',
            'type' => CouponType::TrialExtension,
            'trial_extension_days' => 7,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            // Pro carries trial_days = 14; the coupon takes it to 21.
            $this->walkToConfirm($browser, $billable, 'pro');

            $browser->assertSee('14-day free trial');

            $browser->type('coupon_input', 'PLUS7');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('PLUS7', 10)->pause(400);

            // The promise the customer reads has to be the sum, not the plan's own
            // length, and the summary must not price the coupon as "−0,00 €".
            $browser->assertSee('21-day free trial')
                ->assertSee('+7 days of trial');
            $this->shot($browser, 'Trial-Verlängerung: 14 + 7 Tage');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Pro', 10);
            $this->shot($browser, 'Dashboard: Trial läuft 21 Tage');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($billable->trial_ends_at->toDateString())->toBe(now()->addDays(21)->toDateString())
            // Free days are still free: no invoice, and the redemption is on record.
            ->and(BillingInvoice::query()->count())->toBe(0)
            ->and(CouponRedemption::query()->count())->toBe(1);
    }

    /**
     * The same code on a plan without a trial. It is refused at the point of entry,
     * because the alternative — carrying it to submit — would apply a trial
     * extension to a paid period.
     */
    public function test_a_trial_extension_coupon_is_refused_on_a_plan_without_a_trial(): void
    {
        $this->coupons()->create([
            'code' => 'PLUS7',
            'name' => 'Seven more days to try',
            'type' => CouponType::TrialExtension,
            'trial_extension_days' => 7,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            // Starter has no trial_days.
            $this->walkToConfirm($browser, $billable, 'starter');

            $browser->type('coupon_input', 'PLUS7');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('does not offer one', 10);
            $this->shot($browser, 'Trial-Coupon ohne Trial: abgelehnt');
        });

        expect(CouponRedemption::query()->count())->toBe(0);
    }
}

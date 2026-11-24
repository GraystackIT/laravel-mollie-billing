<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
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
     * A first-payment code on a plan with a trial discounts the charge at the trial's end:
     * the confirm step shows that charge discounted, Mollie's subscription is created at the
     * discounted amount, the trial-end invoice carries the coupon line, and the next charge
     * is the full price again.
     *
     * Pro: 29.00 net monthly, 19 % DE. 25 % off: 21.75 net → 25.88 gross.
     */
    public function test_a_partial_first_payment_coupon_discounts_the_charge_at_the_trial_end(): void
    {
        $this->coupons()->create([
            'code' => 'TRIAL25',
            'name' => 'A quarter off the first charge',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 25,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable, 'pro');

            $browser->type('coupon_input', 'TRIAL25');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('TRIAL25', 10)->pause(400);

            $browser->assertSee('14-day free trial');
            $this->assertShownAmount($browser, 'confirm-total', 2588, 'total of the trial-end charge');
            $this->shot($browser, '25 % auf Pro mit Testphase: Bestätigung');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Pro', 10);
            $this->shot($browser, '25 %: Dashboard in der Testphase');
        });

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $subscription = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and(BillingInvoice::query()->count())->toBe(0)
            ->and($subscription->amount_value)->toBe('25.88')
            ->and((string) $subscription->start_date)->toBe(now()->addDays(14)->toDateString());

        // The charge at the trial's end: discounted, and the last one that is.
        $this->travelSharedClockTo(now()->addDays(14)->setTime(9, 0));
        $paymentId = $this->renewSubscription($billable->refresh());
        $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->sole();

        expect((int) $invoice->amount_gross)->toBe(2588)
            ->and(collect($invoice->line_items)->firstWhere('kind', 'coupon'))->not->toBeNull()
            ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

        // …and Mollie collects the full price from the next charge on: the same renewal
        // PATCHed the subscription back. Left at 25.88, every later charge stayed discounted.
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        expect(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('amount_value'))->toBe('34.51');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing')->waitForText('Pro', 10);
            $this->shot($browser, '25 %: Dashboard nach der ersten Abbuchung');

            $browser->visit('/billing/invoices')->waitForText('Pro', 10)->pause(800);
            $this->shot($browser, '25 %: Rechnung der ersten Abbuchung');
        });
    }

    /**
     * At 100 % the charge at the trial's end costs nothing, and the customer is shown just
     * that: first charge on the trial's last day, the discount line, 0.00. Mollie cannot hold
     * an amount of 0, so behind the screen it keeps the full price and starts one period
     * later — once the trial is over, that full-price charge is the next one shown.
     */
    public function test_a_full_first_payment_coupon_makes_the_charge_at_the_trial_end_free(): void
    {
        $this->coupons()->create([
            'code' => 'TRIAL100',
            'name' => 'First paid month free',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 100,
        ]);

        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        $trialEnd = now()->addDays(14);
        $mollieStart = $trialEnd->copy()->addMonthNoOverflow();

        $this->browse(function (Browser $browser) use ($billable, $trialEnd): void {
            $this->walkToConfirm($browser, $billable, 'pro');

            $browser->type('coupon_input', 'TRIAL100');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('TRIAL100', 10)->pause(400);

            $browser->assertSee('14-day free trial');
            $this->assertShownAmount($browser, 'confirm-discount', 2900, 'the whole trial-end charge');
            $this->assertShownAmount($browser, 'confirm-total', 0, 'nothing is charged at the trial end');
            $browser->assertSeeIn('@confirm-first-charge', $trialEnd->translatedFormat('d. M Y'))
                ->assertSeeIn('@confirm-first-charge', 'fully covered by your coupon');
            $this->shot($browser, '100 % auf Pro mit Testphase: Bestätigung');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $browser->visit('/billing')->waitForText('Pro', 10)->pause(400);

            // The dashboard names the same charge: the trial's end, with the code, at 0.00.
            $browser->assertSeeIn('[data-next-charge-date]', $trialEnd->translatedFormat('d. M Y'))
                ->assertSeeIn('[data-next-charge]', 'TRIAL100');
            expect(trim($browser->text('[data-next-charge-total]')))->toBe(\GraystackIT\MollieBilling\Support\BillingMoney::format(0));
            $this->shot($browser, '100 %: Dashboard in der Testphase');
        });

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $subscription = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($subscription->amount_value)->toBe('34.51')
            ->and((string) $subscription->start_date)->toBe($mollieStart->toDateString());

        // The day after the trial: nothing was collected, the subscription runs, and the next
        // charge is the regular one at full price.
        $this->travelSharedClockTo($trialEnd->copy()->addDay()->setTime(9, 0));
        \GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob::dispatchSync();

        expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and(BillingInvoice::query()->count())->toBe(0);

        $this->browse(function (Browser $browser) use ($billable, $mollieStart): void {
            $this->actAs($browser, $billable, '/billing')->waitForText('Pro', 10)->pause(400);

            $browser->assertSeeIn('[data-next-charge-date]', $mollieStart->translatedFormat('d. M Y'));
            expect(trim($browser->text('[data-next-charge-total]')))->toBe(\GraystackIT\MollieBilling\Support\BillingMoney::format(3451));
            $this->shot($browser, '100 %: Dashboard nach dem Ende der Testphase');
        });
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

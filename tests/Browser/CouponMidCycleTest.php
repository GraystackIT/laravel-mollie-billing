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
 * Codes redeemed mid-cycle, from the portal.
 *
 * A subscriber who receives a code weeks after signing up has two places to enter it: the
 * dashboard's redeem box, which handles the types that act on the account itself, and the
 * plan-change / seats / addons flows, where a discount attaches to the change being made.
 * Which box a code belongs in is not obvious from the code, so the wrong box has to say so
 * instead of failing quietly — a customer who reads "could not be applied" assumes the code
 * is worthless.
 *
 * What each type does is asserted where the customer would look for it: the wallet meter for
 * credits, the next charge date for a period extension, the trial banner for a trial
 * extension, the plan-change summary for a discount.
 */
class CouponMidCycleTest extends BrowserTestCase
{
    private function redeemOnDashboard(Browser $browser, string $code): void
    {
        $browser->waitFor('[wire\\:click="redeemCoupon"]', 10);
        $browser->type('couponCode', $code);
        $this->clickWire($browser, '[wire\\:click="redeemCoupon"]');
        $browser->pause(800);
    }

    /**
     * Credits land in the wallet, and the meter is where the customer checks. The redeem box
     * itself only says "applied" — if the meter did not move, the code did nothing that
     * matters.
     */
    public function test_a_credits_code_fills_the_wallet_the_meter_shows(): void
    {
        $this->coupons()->create([
            'code' => 'BONUS500',
            'name' => 'Five hundred extra tokens',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 500],
        ]);

        $billable = $this->makeActiveSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');

            // Starter includes 50 Tokens, seeded at signup.
            $browser->waitForText('Tokens', 10);
            $this->shot($browser, 'Wallet vor dem Code: 50 Tokens');

            // No purchased credits yet, so the meter shows the plan quota alone.
            $browser->assertSee(__('billing::portal.usage_plan_remaining', ['remaining' => '50', 'total' => '50']))
                ->assertDontSee(__('billing::portal.usage_purchased_remaining', ['remaining' => '500', 'total' => '500']));

            $this->redeemOnDashboard($browser, 'BONUS500');
            $browser->waitForText(__('billing::portal.coupon_redeem_success', ['code' => 'BONUS500']), 10);
            $this->shot($browser, 'Credits eingelöst: Meter zeigt 500 gekaufte');

            // Without a page reload. The meter is a child component and keeps its own state
            // across a parent re-render, so with a fixed key it kept rendering the balance it
            // first mounted with — success message on screen, credits nowhere to be seen.
            $browser->waitForText(
                __('billing::portal.usage_purchased_remaining', ['remaining' => '500', 'total' => '500']),
                10,
            );
        });

        expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(550)
            ->and(CouponRedemption::query()->count())->toBe(1)
            // Credits are not money: nothing invoiced.
            ->and(BillingInvoice::query()->count())->toBe(0);
    }

    /**
     * A period extension moves the next charge. The dashboard prints that date, and it is
     * the only place the customer can tell the code worked at all.
     */
    public function test_a_period_extension_code_moves_the_next_charge_date_on_screen(): void
    {
        $this->coupons()->create([
            'code' => 'PLUS30',
            'name' => 'Thirty days on us',
            'type' => CouponType::PeriodExtension,
            'grant_duration_days' => 30,
        ]);

        $billable = $this->makeActiveSubscriber('starter');
        $before = $billable->nextBillingDate();

        $this->browse(function (Browser $browser) use ($billable, $before): void {
            $this->actAs($browser, $billable, '/billing');

            $browser->waitForText('Starter', 10)
                ->assertSee($before->translatedFormat('d. M Y'));
            $this->shot($browser, 'Nächste Abbuchung vor dem Code');

            $this->redeemOnDashboard($browser, 'PLUS30');
            $browser->waitForText(__('billing::portal.coupon_redeem_success', ['code' => 'PLUS30']), 10);

            // The dashboard has to be showing the *new* date, not the one it rendered before.
            $browser->assertSee($before->copy()->addDays(30)->translatedFormat('d. M Y'))
                ->assertDontSee($before->translatedFormat('d. M Y'));
            $this->shot($browser, 'Nächste Abbuchung 30 Tage später');
        });

        $billable->refresh();

        expect($billable->nextBillingDate()->toDateString())
            ->toBe($before->copy()->addDays(30)->toDateString())
            ->and(CouponRedemption::query()->count())->toBe(1);
    }

    /**
     * A trial extension during the trial. Same code that the checkout accepts, entered a
     * week later.
     */
    public function test_a_trial_extension_code_lengthens_a_running_trial_on_screen(): void
    {
        $this->coupons()->create([
            'code' => 'PLUS7',
            'name' => 'Seven more days',
            'type' => CouponType::TrialExtension,
            'trial_extension_days' => 7,
        ]);

        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(5),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');

            $browser->waitForText('Pro', 10)
                ->assertSee(now()->addDays(5)->translatedFormat('d. M Y'));
            $this->shot($browser, 'Trial endet in 5 Tagen');

            $this->redeemOnDashboard($browser, 'PLUS7');
            $browser->waitForText(__('billing::portal.coupon_redeem_success', ['code' => 'PLUS7']), 10);

            $browser->assertSee(now()->addDays(12)->translatedFormat('d. M Y'));
            $this->shot($browser, 'Trial endet 7 Tage später');
        });

        $billable->refresh();

        expect($billable->trial_ends_at->toDateString())->toBe(now()->addDays(12)->toDateString())
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    }

    /**
     * The two types that belong in an action flow, and the one that belongs nowhere here.
     *
     * A discount has nothing to attach to on the dashboard — there is no order — so it is
     * pointed at the flow where there will be one, rather than being redeemed against
     * nothing or refused as invalid. An access grant would replace the subscription the
     * customer already has, so it is refused outright.
     */
    public function test_the_dashboard_points_discount_codes_at_the_flow_that_can_use_them(): void
    {
        $this->coupons()->create([
            'code' => 'ONCE20',
            'name' => 'Twenty percent, once',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
        ]);

        $this->coupons()->create([
            'code' => 'ALWAYS20',
            'name' => 'Twenty percent, recurring',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'max_redemptions_per_billable' => 6,
        ]);

        $this->coupons()->create([
            'code' => 'PARTNER',
            'name' => 'Partner access',
            'type' => CouponType::AccessGrant,
            'grant_plan_code' => 'starter',
            'grant_interval' => 'monthly',
            'grant_duration_days' => 90,
        ]);

        $billable = $this->makeActiveSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Starter', 10);

            foreach (['ONCE20', 'ALWAYS20'] as $code) {
                $this->redeemOnDashboard($browser, $code);
                $browser->waitForText(__('billing::portal.coupon_redeem_use_in_action'), 10);
            }
            $this->shot($browser, 'Rabattcode am Dashboard: Hinweis auf den Aktionsfluss');

            $this->redeemOnDashboard($browser, 'PARTNER');
            $browser->waitForText(__('billing::checkout.coupon_type_not_allowed_in_context'), 10);
            $this->shot($browser, 'Access-Grant bei laufendem Abo: abgelehnt');
        });

        // Pointed elsewhere, not spent.
        expect(CouponRedemption::query()->count())->toBe(0);
    }

    /**
     * The refusals a subscriber can hit. Same reasons as at checkout — the dashboard maps
     * them through the same translation table — plus the one that only exists once an
     * account has a history: the per-billable limit.
     */
    public function test_every_mid_cycle_refusal_names_its_own_reason(): void
    {
        $this->coupons()->create([
            'code' => 'LASTYEAR',
            'name' => 'Expired',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 100],
            'valid_until' => now()->subDay(),
        ]);

        $this->coupons()->create([
            'code' => 'SLEEPING',
            'name' => 'Deactivated',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 100],
            'active' => false,
        ]);

        $onceOnly = $this->coupons()->create([
            'code' => 'ONEPERUSER',
            'name' => 'One per account',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 100],
            'max_redemptions_per_billable' => 1,
        ]);

        $billable = $this->makeActiveSubscriber('starter');

        // Already spent by this account — the limit only exists once there is a history.
        $this->coupons()->redeem($onceOnly, $billable->fresh(), ['orderAmountNet' => 990]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Starter', 10);

            foreach ([
                'NOSUCHCODE' => 'coupon_not_found',
                'LASTYEAR' => 'coupon_expired',
                'SLEEPING' => 'coupon_inactive',
                'ONEPERUSER' => 'coupon_per_billable_limit_reached',
            ] as $code => $key) {
                $this->redeemOnDashboard($browser, $code);
                $browser->waitForText(__('billing::checkout.'.$key), 10);
            }

            $this->shot($browser, 'Mitten im Zyklus: jede Ablehnung mit eigenem Grund');
        });

        // The one legitimate redemption from the setup, and nothing added by four refusals.
        expect(CouponRedemption::query()->count())->toBe(1);
    }

    /**
     * A recurring discount attached to a plan change on a paying subscriber: the pro-rata
     * breakdown has to carry the coupon line, and the total on screen has to be the total the
     * composer produced — that number is what leaves the customer's account today.
     */
    public function test_a_recurring_code_discounts_a_plan_change_it_is_entered_on(): void
    {
        $this->coupons()->create([
            'code' => 'ALWAYS25',
            'name' => 'A quarter off, every month',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 25,
            'max_redemptions_per_billable' => 6,
        ]);

        $billable = $this->makeActiveSubscriber('starter');

        // A renewal that paid for the running period, so there is something to pro-rate
        // against — `currentPeriodLines()` composes the credit from a real invoice line.
        $this->renewSubscription($billable);
        $billable->refresh();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');

            $browser->waitForText('Pro', 15);
            $this->clickWire($browser, '[wire\:click*="\'pro\'"]');
            $browser->pause(1200);

            $browser->waitFor('input[wire\:model="couponInput"]', 10);
            $browser->type('couponInput', 'ALWAYS25');
            $this->clickWire($browser, '[wire\:click="applyCoupon"]');
            $browser->waitForText('ALWAYS25', 10)->pause(900);
            $this->shot($browser, 'Plan-Wechsel mit Recurring-Code');

            // What the composer says will be charged today, computed the way the view sums it:
            // charge lines (a coupon line is charge-direction with a negative amount) plus any
            // usage overage. Read from the service rather than hardcoded, so the assertion is
            // "the screen agrees with the books" and not "the screen agrees with my arithmetic".
            $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
                ->previewUpdate($billable->fresh(), ['plan_code' => 'pro', 'coupon_codes' => ['ALWAYS25']]);

            $chargeGross = 0;
            $refundGross = 0;
            foreach ((array) ($preview['prorataLines'] ?? []) as $line) {
                if (($line['direction'] ?? 'charge') === 'refund') {
                    $refundGross += (int) $line['amount_gross'];
                } else {
                    $chargeGross += (int) $line['amount_gross'];
                }
            }

            $isSidegrade = ($chargeGross + $refundGross) === 0 && $chargeGross > 0;
            $expected = ($isSidegrade ? 0 : max(0, $chargeGross))
                + (int) ($preview['usageOverageChargeGross'] ?? 0);

            $this->assertShownAmount($browser, 'prorata-total', $expected, 'what is charged today');

            // And the coupon is visible as its own line, not silently folded into the plan.
            expect(collect($preview['prorataLines'] ?? [])->firstWhere('kind', 'coupon'))->not->toBeNull();
        });

        // The recurring price the discount will keep applying to every renewal.
        $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
            ->previewUpdate($billable->fresh(), ['plan_code' => 'pro', 'coupon_codes' => ['ALWAYS25']]);

        expect((int) $preview['couponDiscountNet'])->toBe(725)   // round(2900 × 25 %)
            ->and(max(0, (int) $preview['newPriceNet'] - (int) $preview['couponDiscountNet']))->toBe(2175);
    }

    /**
     * The other confirmation screen: a free-plan account upgrading to a paid one. It prints
     * the recurring price directly rather than a pro-rata breakdown, and a code entered there
     * has to be reflected in all three figures the customer reads.
     */
    public function test_a_local_upgrade_shows_the_discounted_recurring_price(): void
    {
        $this->coupons()->create([
            'code' => 'ALWAYS25',
            'name' => 'A quarter off, every month',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 25,
            'max_redemptions_per_billable' => 6,
        ]);

        // No Mollie subscription behind it — the local→Mollie path.
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'starter',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(10),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');

            $browser->waitForText('Pro', 15);
            $this->clickWire($browser, '[wire\:click="previewFor(\'pro\')"]');
            $browser->pause(1200);

            $browser->waitFor('input[wire\:model="couponInput"]', 10);
            $browser->type('couponInput', 'ALWAYS25');
            $this->clickWire($browser, '[wire\:click="applyCoupon"]');
            $browser->waitForText('ALWAYS25', 10)->pause(900);

            // The local→Mollie path asks for a confirmation of its own before it sends the
            // customer to a checkout, and that is the screen carrying the recurring price.
            $this->clickWire($browser, '[x-on\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $this->shot($browser, 'Local-Upgrade mit Recurring-Code');

            // 2900 net − round(2900 × 25 %) = 2175 net → 413 VAT at 19 % → 2588 gross.
            $this->assertShownAmount($browser, 'upgrade-net', 2175, 'discounted recurring net');
            $this->assertShownAmount($browser, 'upgrade-vat', 413, 'VAT on the discounted net');
            $this->assertShownAmount($browser, 'upgrade-total', 2588, 'discounted recurring gross');
        });
    }
}

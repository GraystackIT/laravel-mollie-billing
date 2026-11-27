<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The dashboard's own actions: cancel into the grace period, resubscribe out of
 * it, redeem a coupon on the spot.
 */
class PortalDashboardTest extends BrowserTestCase
{
    public function test_cancel_keeps_access_until_period_end_and_resubscribe_recovers(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);
            $this->shot($browser, 'Aktives Abo');

            // The cancel button opens a confirm modal; the danger button inside
            // is the actual action.
            $browser->press(__('billing::portal.cancel_subscription'));
            $browser->whenAvailable('dialog', function (Browser $modal): void {
                $this->shot($modal, 'Kündigungs-Bestätigung');
            });
            $this->clickWire($browser, '[wire\\:click="cancelSubscription"]');

            $browser->waitForText(__('billing::portal.resubscribe'), 10)->pause(400);
            $this->shot($browser, 'Gekündigt — Zugriff bis Periodenende');

            $this->clickWire($browser, '[wire\\:click="resubscribe"]');
            $browser->waitUntilMissingText(__('billing::portal.resubscribe'), 10)->pause(400);
            $this->shot($browser, 'Resubscribe: wieder aktiv');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->getBillingSubscriptionEndsAt())->toBeNull();
    }

    /**
     * Decision 9 (26 November 2026): once cancelled, the "Next billing" tile names no
     * charge and no amount any more — it says when the subscription ends and offers to
     * withdraw the cancellation right there. Withdrawn, the tile is back to the date.
     */
    public function test_the_next_billing_tile_of_a_cancelled_subscription_says_when_it_ends_and_withdraws_the_cancellation(): void
    {
        $billable = $this->makeActiveSubscriber();
        $nextBilling = BillingTime::billingDay($billable->nextBillingDate())->translatedFormat('d. M Y');
        $endsText = (string) __('billing::portal.next_billing_subscription_ends_on', ['date' => $nextBilling]);

        $this->browse(function (Browser $browser) use ($billable, $nextBilling, $endsText): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);
            $this->assertSame(0, $this->domCount($browser, '[dusk="next-billing-ends"]'), 'an active subscription has a billing date');
            $browser->assertSee($nextBilling);

            $browser->press(__('billing::portal.cancel_subscription'));
            $browser->whenAvailable('dialog', fn () => null);
            $this->clickWire($browser, '[wire\\:click="cancelSubscription"]');

            $this->waitForPresence($browser, '[dusk="next-billing-ends"]', 10);
            $browser->pause(400)->assertSeeIn('[dusk="next-billing-ends"]', $endsText);
            $browser->assertSeeIn('[dusk="next-billing-withdraw-cancellation"]', __('billing::portal.grace_withdraw_cancellation'));
            $this->shot($browser, 'Gekündigt — Kachel nennt das Abo-Ende');

            $this->clickWire($browser, '[dusk="next-billing-withdraw-cancellation"]');
            $this->waitForAbsence($browser, '[dusk="next-billing-ends"]', 10);
            $browser->waitForText(__('billing::portal.flash.resubscribed'), 10)->pause(400);
            $this->assertSame(0, $this->domCount($browser, '[dusk="next-billing-withdraw-cancellation"]'));
            $browser->assertSee($nextBilling);
            $this->shot($browser, 'Kündigung über die Kachel zurückgenommen');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->getBillingSubscriptionEndsAt())->toBeNull();
    }

    public function test_redeems_a_credits_coupon_from_the_dashboard(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->coupons()->creditsCoupon('TOPUP250', ['Tokens' => 250]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);

            $browser->type('couponCode', 'TOPUP250');
            $this->clickWire($browser, '[wire\\:click="redeemCoupon"]');
            $browser->waitForText('TOPUP250', 10)->pause(600);
            $this->shot($browser, 'Credits-Coupon eingelöst: 300 Tokens');
        });

        // 50 included + 250 from the coupon.
        expect(TestBillable::sole()->remainingBillingQuota('Tokens'))->toBe(300)
            ->and(TestBillable::sole()->purchasedBillingCredits('Tokens'))->toBe(250);
    }

    public function test_the_dashboard_applies_a_recurring_discount_and_says_from_when(): void
    {
        // One field for every code: a recurring discount is applied to the running
        // subscription on the spot (RedeemCouponFromPortal) instead of sending the
        // customer to a plan change they did not want.
        $billable = $this->makeActiveSubscriber();

        $this->coupons()->create([
            'code' => 'LOYAL20',
            'name' => '20% loyalty discount',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'discount_periods' => 12,
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);

            $browser->type('couponCode', 'LOYAL20');
            $this->clickWire($browser, '[wire\\:click="redeemCoupon"]');
            $browser->waitForText(__('billing::portal.coupon_recurring_applied_periods', [
                'code' => 'LOYAL20',
                'from' => \GraystackIT\MollieBilling\Support\BillingTime::display($billable->nextBillingDate(), $billable)->translatedFormat('d. M Y'),
                'count' => 12,
            ]), 10)->pause(400);
            $this->shot($browser, 'Rabatt-Coupon: am Dashboard angewendet');
        });

        expect($billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)
            ->toBe('LOYAL20');
    }
}

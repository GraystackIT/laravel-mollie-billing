<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * Everything a subscriber can buy on top of the plan: addons, extra seats and
 * one-time products. All three price a pro-rata charge against the running
 * period and settle through the same webhook the renewals use.
 */
class AddonsSeatsProductsTest extends BrowserTestCase
{
    /** Phase 2 of a prorata charge: Mollie's webhook, delivered like Mollie would. */
    private function settleLatestRecurringCharge(Browser $browser): void
    {
        $browser->waitUsing(15, 200, function (): bool {
            return $this->latestFakePayment()['sequence_type'] === 'recurring';
        }, 'Waited for the prorata charge to appear at Mollie.');

        $this->deliverWebhook($this->latestFakePayment()['id']);
    }

    public function test_enables_and_disables_an_addon(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 10)->pause(400);
            $this->shot($browser, 'Addon-Übersicht');

            // The action lives inside a confirm modal that shows the prorata price.
            $this->confirmInModal(
                $browser,
                'enable-addon-softdrinks',
                '[wire\\:click="enableAddon(\'softdrinks\')"]',
            );
            $this->settleLatestRecurringCharge($browser);

            // Presence, not visibility: the action sits inside a confirm modal
            // that is in the DOM but hidden until opened.
            $browser->visit('/billing/addons');
            $this->waitForPresence($browser, '[wire\\:click="disableAddon(\'softdrinks\')"]');
            $browser->pause(400);
            $this->shot($browser, 'Addon aktiv');

            expect(TestBillable::sole()->getActiveBillingAddonCodes())->toBe(['softdrinks']);

            $this->confirmInModal(
                $browser,
                'disable-addon-softdrinks',
                '[wire\\:click="disableAddon(\'softdrinks\')"]',
            );
            $this->waitForAbsence($browser, '[wire\\:click="disableAddon(\'softdrinks\')"]');
            $browser->pause(400);
            $this->shot($browser, 'Addon wieder deaktiviert');
        });

        expect(TestBillable::sole()->getActiveBillingAddonCodes())->toBe([]);
    }

    public function test_adds_extra_seats_and_charges_them_prorata(): void
    {
        // Pro includes 3 seats at 9.90 each beyond that.
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 10)->pause(400);
            $this->shot($browser, 'Seats: 3 inklusive');

            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->shot($browser, 'Zwei zusätzliche Seats gewählt');

            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $this->settleLatestRecurringCharge($browser);

            $browser->visit('/billing')->waitForText('5', 10)->pause(400);
            $this->shot($browser, 'Dashboard: 5 Seats');
        });

        expect(TestBillable::sole()->getExtraBillingSeats())->toBe(2)
            ->and(TestBillable::sole()->getAvailableBillingSeats())->toBe(5);
    }

    public function test_buys_a_one_time_product_and_credits_the_wallet(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 10)->pause(400);
            $this->shot($browser, 'Produktkatalog');

            $this->confirmInModal(
                $browser,
                'purchase-token-pack-500',
                '[wire\\:click="purchase(\'token-pack-500\')"]',
            );

            // A product purchase is a fresh interactive payment: hosted page again.
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
            $this->shot($browser, 'Return-Seite nach Produktkauf');

            $browser->visit('/billing/usage')->waitForText('Tokens', 10)->pause(600);
            $this->shot($browser, 'Usage-Historie nach Top-Up');
        });

        // 50 included + 500 purchased.
        expect(TestBillable::sole()->remainingBillingQuota('Tokens'))->toBe(550)
            ->and(TestBillable::sole()->purchasedBillingCredits('Tokens'))->toBe(500)
            ->and(BillingInvoice::query()->where('invoice_kind', 'one_time_order')->count())->toBe(1);
    }

    public function test_a_once_only_product_cannot_be_bought_twice(): void
    {
        $billable = $this->makeActiveSubscriber();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('Onboarding Session', 10)->pause(400);

            $this->confirmInModal(
                $browser,
                'purchase-onboarding-session',
                '[wire\\:click="purchase(\'onboarding-session\')"]',
            );
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            // Back on the catalog the product is gone — it is marked onetimeonly
            // and this billable has paid for it.
            $browser->visit('/billing/products')
                ->waitForText('500 Token Pack', 10)->pause(400);
            $this->shot($browser, 'Einmal-Produkt nach Kauf nicht mehr angeboten');

            // The card is gone entirely, so its purchase action is too. Checked in
            // the DOM because the action lives inside a (hidden) confirm modal.
            expect($this->domCount($browser, '[wire\\:click="purchase(\'onboarding-session\')"]'))->toBe(0)
                ->and($this->domCount($browser, '[wire\\:click="purchase(\'token-pack-500\')"]'))->toBe(1);
        });

        expect(BillingInvoice::query()->where('invoice_kind', 'one_time_order')->count())->toBe(1);
    }

    /**
     * The scenario the plan waiver exists for, driven end to end: a tenant who was GIVEN
     * their plan buys a paid addon. They pay for the addon only, at Mollie; the plan stays
     * free until the gift ends; the renewal charges the addon again and waives the plan.
     */
    public function test_a_gifted_tenant_buys_a_paid_addon_and_keeps_the_free_plan(): void
    {
        $billable = $this->makeBillable();

        $coupons = $this->coupons();
        $coupon = $coupons->accessGrantCoupon('GRANT-BROWSER', 'pro', 'monthly', [], 90);
        $coupons->redeem($coupon, $billable, []);

        $giftEnds = TestBillable::sole()->getBillingSubscriptionEndsAt();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 10)->pause(400);

            // A gift is not a dead end any more.
            $browser->assertDontSee(__('billing::portal.access_grant_no_paid_extras'));
            $this->shot($browser, 'Geschenkter Plan: Addons sind kaufbar');

            $this->confirmInModal(
                $browser,
                'enable-addon-softdrinks',
                '[wire\\:click="enableAddon(\'softdrinks\')"]',
            );

            // Only the addon is paid: 4.90 + 19 % = 5.83 — not the 29.00 plan.
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing/addons');
            $this->waitForPresence($browser, '[wire\\:click="disableAddon(\'softdrinks\')"]');
            $browser->pause(400);
            $this->shot($browser, 'Addon aktiv, Plan weiterhin geschenkt');
        });

        $billable = TestBillable::sole();
        $first = BillingInvoice::query()->oldest('id')->first();
        $waiver = app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)->marker($billable);

        expect($billable->getBillingSubscriptionSource())->toBe('mollie')
            ->and($billable->getActiveBillingAddonCodes())->toBe(['softdrinks'])
            ->and((int) $first->amount_net)->toBe(490)
            ->and((int) $first->amount_gross)->toBe(583)
            ->and($waiver)->not->toBeNull()
            ->and(\Carbon\Carbon::parse((string) $waiver['valid_until'])->toDateString())->toBe($giftEnds->toDateString());

        // The renewal Mollie generates from the subscription it now holds: again the
        // addon only, with the plan shown and taken off.
        $this->renewSubscription($billable);

        $renewal = BillingInvoice::query()->latest('id')->first();
        $lines = collect($renewal->line_items);

        expect((int) $renewal->amount_net)->toBe(490)
            ->and((int) $lines->firstWhere('kind', 'plan')['total_net'])->toBe(2900)
            ->and((int) $lines->firstWhere('kind', 'coupon')['total_net'])->toBe(-2900)
            ->and($lines->firstWhere('kind', 'adjustment'))->toBeNull();
    }
}

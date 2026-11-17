<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The dashboard's remaining buttons — the ones a customer only sees in one particular
 * state: ending a trial early, asking Mollie to charge now while a first charge is still
 * scheduled, and reactivating after a country mismatch was resolved.
 *
 * (Cancel / resubscribe: PortalDashboardTest. Cancelling a scheduled change:
 * PlanChangeTest. Correcting the country: CountryMismatchTest. The code field:
 * CouponDashboardRedeemMatrixTest.)
 */
class CouponDashboardActionsMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    public function test_a_customer_ends_their_trial_early(): void
    {
        $billable = $this->couponBillableIn('trial');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText(__('billing::portal.end_trial'), 15)->pause(400);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'trial, before it is ended');

            $this->confirmInModal($browser, 'end-trial', '[wire\\:click="endTrial"]');
            $browser->waitForText(__('billing::portal.flash.trial_ended'), 15)->pause(500);
            $this->shot($browser, 'Trial vorzeitig beendet');

            $this->assertNextChargeMatchesBooks($browser, $billable, 'after the trial was ended');
        });

        $billable = $billable->fresh();

        // Nothing was paid, so there is no grace period: access ends now, and Mollie has
        // nothing left to charge at the trial's end.
        expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
            ->and($billable->hasAccessibleBillingSubscription())->toBeFalse()
            ->and(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('status'))->toBe('canceled')
            ->and(DB::table('fake_mollie_payments')->count())->toBe(0);
    }

    public function test_a_customer_whose_first_charge_is_still_scheduled_asks_for_it_now(): void
    {
        // Past due although nothing failed: the trial ran out a few days before Mollie's
        // scheduled first charge. The dashboard names that date and offers to bring it forward.
        $billable = $this->couponBillableIn('past_due');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $scheduled = now()->addDays(5);

        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update([
            'start_date' => $scheduled->toDateString(),
            'next_payment_date' => $scheduled->toDateString(),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[wire\\:click="forceImmediateMollieCharge"]', 15)->pause(400);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'past due with a scheduled first charge');
            $this->shot($browser, 'Erste Abbuchung steht noch aus');

            $this->clickWire($browser, '[wire\\:click="forceImmediateMollieCharge"]');
            $browser->waitForText(__('billing::portal.flash.force_charge_scheduled'), 15)->pause(400);
            $this->shot($browser, 'Jetzt abbuchen angefordert');
        });

        // Mollie's start date is today — and only the date moved.
        $row = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        expect(substr((string) $row->start_date, 0, 10))->toBe(now('UTC')->toDateString())
            ->and((string) $row->amount_value)->toBe('34.51')
            ->and((string) $row->status)->toBe('active');
    }

    public function test_a_customer_reactivates_after_their_country_mismatch_was_resolved(): void
    {
        // The mismatch cancelled the subscription; it has since been resolved, and the
        // period that was paid for is still running.
        $billable = $this->couponBillableIn('cancelled');
        $graceEnd = $billable->getBillingSubscriptionEndsAt();

        BillingCountryMismatch::query()->create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'tax_country_user' => 'DE',
            'tax_country_payment' => 'NL',
            'status' => 'resolved',
            'resolved_at' => now()->subHour(),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[wire\\:click="reactivateAfterMismatch"]', 15)->pause(400);
            $this->shot($browser, 'Mismatch gelöst: Reaktivieren angeboten');

            $this->clickWire($browser, '[wire\\:click="reactivateAfterMismatch"]');
            $browser->waitForText(__('billing::portal.flash.resubscribed'), 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'after reactivating');
            $this->shot($browser, 'Wieder aktiv');
        });

        $billable = $billable->fresh();

        // A new Mollie subscription at the full price, first charge where the paid period ends.
        expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
            ->and($billable->getBillingSubscriptionEndsAt())->toBeNull()
            ->and($this->mollieSubscriptionAmount($billable))->toBe('34.51')
            ->and($this->mollieNextPaymentDate($billable))->toBe($graceEnd->toDateString())
            ->and(DB::table('fake_mollie_subscriptions')->count())->toBe(2);
    }
}

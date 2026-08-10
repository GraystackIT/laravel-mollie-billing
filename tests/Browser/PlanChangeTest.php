<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * Plan changes in all three shapes the package offers: pay the pro-rata
 * difference now, schedule the downgrade for the period boundary (and cancel
 * that again), and switch the billing interval.
 */
class PlanChangeTest extends BrowserTestCase
{
    public function test_an_immediate_upgrade_charges_the_prorata_difference(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Business', 10)->pause(400);
            $this->shot($browser, 'Planübersicht');

            $this->clickWire($browser, '[wire\\:click="previewFor(\'business\')"]');
            $browser->waitForText(__('billing::portal.apply_immediately'), 10)->pause(400);
            $this->shot($browser, 'Upgrade-Vorschau mit Prorata');

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');

            // Phase 1 creates the recurring prorata charge (instantly paid at the
            // fake); phase 2 is the webhook, which the test delivers like Mollie
            // would.
            $browser->waitUsing(10, 200, function (): bool {
                return $this->latestFakePayment()['sequence_type'] === 'recurring';
            });
            $this->deliverWebhook($this->latestFakePayment()['id']);

            $browser->visit('/billing')->waitForText('Business', 10);
            $this->shot($browser, 'Dashboard nach Upgrade');
        });

        $billable->refresh();

        expect($billable->getBillingSubscriptionPlanCode())->toBe('business');
    }

    public function test_a_downgrade_is_scheduled_for_the_period_end_and_can_be_cancelled(): void
    {
        $billable = $this->makeActiveSubscriber('business', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Starter', 10)->pause(400);

            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');
            $browser->waitForText(__('billing::portal.preview_seats_drop_extra'), 10)->pause(400);
            $this->shot($browser, 'Downgrade blockiert: zu viele Seats');

            // Business includes 10 seats, Starter one — the preview refuses to
            // apply until the extra seats are given up, and offers exactly that.
            $this->clickWire($browser, '[wire\\:click="toggleDropExtraSeats"]');
            $browser->waitForText(__('billing::portal.preview_seats_extra_dropped'), 10)->pause(400);
            $this->shot($browser, 'Extra-Seats aufgegeben — Downgrade möglich');

            // Alpine toggle first, then the one apply button submits applyAt.
            $browser->press(__('billing::portal.schedule_end_of_period'));
            $browser->pause(300);
            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $browser->waitForText(__('billing::portal.cancel_scheduled_change'), 10)->pause(400);
            $this->shot($browser, 'Downgrade zum Periodenende geplant');

            expect(TestBillable::sole()->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)
                ->toBe('starter');

            $this->clickWire($browser, '[wire\\:click="cancelScheduledChange"]');
            $browser->waitUntilMissingText(__('billing::portal.cancel_scheduled_change'), 10)->pause(400);
            $this->shot($browser, 'Geplante Änderung storniert');
        });

        $billable->refresh();

        expect($billable->getBillingSubscriptionPlanCode())->toBe('business')
            ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
    }

    public function test_switching_the_interval_to_yearly_charges_the_difference_now(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitForText('Starter', 10)->pause(400);

            // The interval toggle re-prices the plan cards to yearly…
            $browser->clickAtXPath("//*[contains(text(),'Yearly')]")->pause(800);
            $this->shot($browser, 'Jahrespreise');

            // …and previewing the OWN plan in the other interval is the switch.
            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');
            $browser->waitFor('[x-on\\:click*="applyChange"]', 10)->pause(400);
            $this->shot($browser, 'Intervallwechsel-Vorschau');

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');

            $browser->waitUsing(10, 200, function (): bool {
                return $this->latestFakePayment()['sequence_type'] === 'recurring';
            });
            $this->deliverWebhook($this->latestFakePayment()['id']);

            $browser->visit('/billing')->waitForText(__('billing::portal.interval_yearly'), 10);
            $this->shot($browser, 'Dashboard: jährliche Abrechnung');
        });

        $billable->refresh();

        expect($billable->getBillingSubscriptionInterval())->toBe('yearly');
    }
}

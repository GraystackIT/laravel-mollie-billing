<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Changes that are still on their way when the customer — or a background job — does the
 * next thing (docs/decisions.md, "Plan, seat and addon changes review round"):
 *
 *  - Plan screen: Business scheduled for the period end on a Pro subscription (Mollie told
 *    at once), then "Apply now" from the menu, its prorata charge left open — withdrawn by
 *    the customer, or declined at Mollie. Either way Mollie is back on Pro, and the next
 *    renewal is collected at Pro's price with nothing to adjust or refund.
 *  - Seats screen, then a graceful cancel on the dashboard while the seat charge is open:
 *    the charge is cancelled at Mollie (or, where Mollie cannot cancel it, returned in full
 *    when it settles), and the seat goes with it.
 *  - Addons screen, then a graceful cancel while the addon charge is open: the addon is off
 *    on the addons screen, at the application's feature gate and on the dashboard.
 *  - A seat added by `SyncSeatsJob` while the customer's addon change holds the change lock:
 *    the job waits its turn on the queue and the seat is billed, no failed job.
 *
 * Workbench catalog, 19 % VAT for the German customer: Pro 29.00 → 34.51 gross, Business
 * 99.00 → 117.81, an extra Pro seat 9.90, Softdrinks 4.90.
 */
class ChangesInFlightTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const END_OF_PERIOD = '[\\@click*="end_of_period"]';

    private const CANCEL_SCHEDULED = '[wire\\:click="cancelScheduledChange"]';

    private const APPLY_NOW = '[wire\\:click="applyScheduledNow"]';

    private const APPLY_NOW_MENU = 'ui-dropdown:has([wire\\:click="applyScheduledNow"]) > button';

    private const CANCEL_PENDING = '[wire\\:click="cancelPendingChange"]';

    private const POLL = '[wire\\:poll\\.5s]';

    private const INCREMENT = '[wire\\:click="increment"]';

    private const SYNC_SEATS = '[wire\\:click="syncSeats"]';

    private const ENABLE_SOFTDRINKS = '[wire\\:click="enableAddon(\'softdrinks\')"]';

    private const DISABLE_SOFTDRINKS = '[wire\\:click="disableAddon(\'softdrinks\')"]';

    private const ADDON_POLL = '[wire\\:poll\\.3s="pollForChange"]';

    private const CANCEL_SUBSCRIPTION = '[wire\\:click="cancelSubscription"]';

    private const PRO_GROSS = '34.51';

    private const BUSINESS_GROSS = '117.81';

    /** Pro with two extra seats: (29.00 + 2 × 9.90) + 19 %. */
    private const PRO_FIVE_SEATS_GROSS = '58.07';

    /** Pro with three extra seats: (29.00 + 3 × 9.90) + 19 %. */
    private const PRO_SIX_SEATS_GROSS = '69.85';

    /** Pro, one extra seat and Softdrinks: (29.00 + 9.90 + 4.90) + 19 %. */
    private const PRO_SEAT_SOFTDRINKS_GROSS = '52.12';

    // ── 15. Scheduled upgrade, "Apply now", withdrawn ───────────────────────

    public function test_a_scheduled_upgrade_applied_now_and_withdrawn_while_its_payment_is_open_leaves_pro_at_mollie(): void
    {
        WorkbenchSetting::set('payments_cancelable', '1');

        $billable = $this->changesSubscriber(seats: 3);
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable));
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $this->scheduleBusinessForThePeriodEnd($browser, $billable);
            $paymentId = $this->applyScheduledChangeNowAwaitingPayment($browser, $billable);

            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.pending_change_cancelled'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->assertDontSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->shot($browser, 'Wartende Änderung zurückgezogen');

            $this->assertSame(0, $this->domCount($browser, self::POLL), 'nothing left to poll for');
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_SCHEDULED), 'the scheduled change does not come back');
            $this->assertSame('Pro', $this->headerPlan($browser));

            // Past one poll's request, nothing announces the withdrawn change as made.
            $this->refreshComponent($browser);
            $texts = array_column($this->toastsSeen($browser), 'text');
            $this->assertContains(__('billing::portal.flash.pending_change_cancelled'), $texts);
            $this->assertNotContains(__('billing::portal.flash.plan_changed'), $texts, 'no "plan changed" at any point — the plan never changed');
            $this->assertNotContains(__('billing::portal.flash.plan_change_failed'), $texts);
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        foreach (['scheduled_change', 'pending_plan_change', 'pending_prorata_change', 'prorata_pending_payment_id', 'orphaned_prorata_payment', 'plan_change_failed_at'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertNull($billable->scheduled_change_at);
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie collects Pro again');
        $this->assertSame('canceled', FakeMollie::payment($paymentId)['status'], 'the prorata charge is cancelled at Mollie');
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

        // Mollie's webhook for the cancelled charge changes nothing.
        $this->assertSame(200, $this->deliverWebhook($paymentId));
        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('plan_change_failed_at', $billable->fresh()->getBillingSubscriptionMeta());

        // The next renewal: Pro's price, nothing to adjust, nothing to give back.
        $renewal = $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));

        $this->assertSame(3451, (int) $renewal->amount_gross);
        $this->assertNotContains('adjustment', array_column((array) $renewal->line_items, 'kind'));
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count(), 'nothing refunded');
        $this->assertSame(0, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count(), 'no credit note');
        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable));

        $this->browse(function (Browser $browser) use ($billable, $renewal): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$renewal->getKey().'"]', 15)->pause(300);
            $this->assertShownAmount($browser, 'invoice-'.$renewal->getKey().'-gross', 3451, 'the renewal on the invoice list');
            $this->shot($browser, 'Verlängerung zum Pro-Preis');
        });
    }

    // ── 16. Scheduled upgrade, "Apply now", declined ────────────────────────

    public function test_a_scheduled_upgrade_applied_now_whose_payment_is_declined_reads_failed_and_leaves_pro_at_mollie(): void
    {
        $billable = $this->changesSubscriber(seats: 3);
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $this->scheduleBusinessForThePeriodEnd($browser, $billable);
            $paymentId = $this->applyScheduledChangeNowAwaitingPayment($browser, $billable);

            // Declined at Mollie; its webhook arrives while the page is open.
            FakeMollie::settle($paymentId, 'failed', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            // The page's own poll finds it.
            $browser->waitForText(__('billing::portal.flash.plan_change_failed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Zahlung abgelehnt: Wechsel fehlgeschlagen');

            $toasts = $this->toastsSeen($browser);
            $this->assertContains(['text' => __('billing::portal.flash.plan_change_failed'), 'variant' => 'danger'], $toasts);
            $this->assertNotContains(__('billing::portal.flash.plan_changed'), array_column($toasts, 'text'), 'never "plan changed"');
            $this->assertSame('Pro', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_SCHEDULED), 'no scheduled change is left');
            $this->assertSame(0, $this->domCount($browser, self::POLL));

            // A fresh visit says the same: failed, still Pro, nothing pending or scheduled.
            $browser->refresh();
            $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $browser->assertSee(__('billing::portal.flash.plan_change_failed'));
            $browser->assertDontSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_SCHEDULED));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));
            $this->assertSame('Pro', $this->headerPlan($browser));
            $this->shot($browser, 'Nach dem Neuladen');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        foreach (['scheduled_change', 'pending_plan_change', 'pending_prorata_change', 'prorata_pending_payment_id'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertNull($billable->scheduled_change_at);
        $this->assertNotEmpty($meta['plan_change_failed_at'] ?? null);
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie holds Pro, not Business');
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

        $renewal = $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));
        $this->assertSame(3451, (int) $renewal->amount_gross);
        $this->assertNotContains('adjustment', array_column((array) $renewal->line_items, 'kind'));
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count());
    }

    // ── 17. Seats, then cancel, while the seat charge is open ───────────────

    public function test_a_graceful_cancel_while_a_seat_charge_is_open_cancels_the_charge_and_takes_the_seat_back(): void
    {
        WorkbenchSetting::set('payments_cancelable', '1');

        $billable = $this->changesSubscriber(seats: 5);
        $this->assertSame(self::PRO_FIVE_SEATS_GROSS, $this->mollieSubscriptionAmount($billable));
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $paymentId = $this->addOneSeatAwaitingPayment($browser, $billable);
            $this->cancelOnTheDashboardBeforeTheCharge($browser, $billable);
        });

        $billable->refresh();

        $this->assertSame('canceled', FakeMollie::payment($paymentId)['status'], 'the seat charge is cancelled at Mollie');
        $this->assertSeatTakenBack($billable);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $billable->getBillingSubscriptionMeta(), 'nothing can arrive for it');

        // The webhook for the cancelled charge changes nothing.
        $this->assertSame(200, $this->deliverWebhook($paymentId));
        $this->assertSame(5, $billable->fresh()->getBillingSeatCount());
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->assertSeatsScreenShows($browser, $billable, 5);
        });
    }

    public function test_a_graceful_cancel_while_an_uncancelable_seat_charge_is_open_returns_it_in_full_when_it_settles(): void
    {
        $billable = $this->changesSubscriber(seats: 5);
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $paymentId = $this->addOneSeatAwaitingPayment($browser, $billable);
            $this->cancelOnTheDashboardBeforeTheCharge($browser, $billable);
        });

        $billable->refresh();

        // Mollie cannot cancel it: still open, remembered to be returned should it settle.
        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame($paymentId, $billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null);
        $this->assertSame('not_cancelable', $billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null);
        $this->assertSeatTakenBack($billable);

        // It settles after all.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $billable->refresh();
        $charge = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->sole();
        $creditNotes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get();

        $this->assertCount(1, $creditNotes, 'one credit note');
        $creditNote = $creditNotes->sole();
        $this->assertSame((int) $charge->amount_gross, abs((int) $creditNote->amount_gross), 'credited in full');
        $this->assertSame(
            (float) FakeMollie::payment($paymentId)['amount_value'],
            (float) DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->sum('amount_value'),
            'returned in full at Mollie',
        );
        $this->assertSame(5, $billable->getBillingSeatCount(), 'the seat stays gone');
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $billable->getBillingSubscriptionMeta());
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());

        // A second delivery returns nothing twice.
        $this->assertSame(200, $this->deliverWebhook($paymentId));
        $this->assertSame(1, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count());
        $this->assertSame(1, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count());

        $this->browse(function (Browser $browser) use ($billable, $charge, $creditNote): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$creditNote->getKey().'"]', 15)->pause(300);
            $this->assertSame(1, $this->domCount($browser, '[dusk="invoice-row-'.$charge->getKey().'"]'), 'the charge is listed');
            $browser->assertSeeIn('[dusk="invoice-row-'.$creditNote->getKey().'"]', InvoiceKind::Refund->label());
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->getKey().'-gross', (int) $charge->amount_gross, 'the credit note returns the whole charge');
            $this->shot($browser, 'Gutschrift über die ganze Sitzplatz-Zahlung');

            $this->assertSeatsScreenShows($browser, $billable, 5);
        });
    }

    // ── 18. Addons, then cancel, while the addon charge is open ─────────────

    public function test_a_graceful_cancel_while_an_addon_charge_is_open_switches_the_addon_off_everywhere(): void
    {
        $billable = $this->changesSubscriber(seats: 3);
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $this->setRecurringOutcome('open');
            $this->actAs($browser, $billable, '/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);

            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $browser->waitUsing(15, 200, fn (): bool => $this->pendingChargeId($billable) !== '',
                'the addon charge never started waiting for its payment');
            $this->waitForPresence($browser, self::ADDON_POLL, 15);
            $this->setRecurringOutcome('paid');
            $paymentId = $this->pendingChargeId($billable);

            $browser->assertSee(__('billing::portal.flash.plan_change_pending_payment'));
            $this->shot($browser, 'Addon wartet auf Zahlung');

            // An addon change is written at once; only its charge waits. The application
            // already lets the customer in.
            $this->assertContains('softdrinks', $billable->fresh()->getActiveBillingAddonCodes());
            $browser->visit('/_wb/feature/softdrinks')->waitForText('FEATURE-SOFTDRINKS-OK', 10);

            // The dashboard, and cancel at the period end.
            $browser->visit('/billing')->waitForText(__('billing::portal.cancel_subscription'), 15)->pause(300);
            $browser->assertSee('Softdrinks');
            $browser->press(__('billing::portal.cancel_subscription'));
            $browser->waitFor(self::CANCEL_SUBSCRIPTION, 10)->pause(300);
            $this->clickWire($browser, self::CANCEL_SUBSCRIPTION);
            $browser->waitForText(__('billing::portal.resubscribe'), 15)->pause(400);
            $browser->assertSee(__('billing::portal.flash.cancelled'));
            $this->shot($browser, 'Gekündigt, Addon-Zahlung offen');

            // The dashboard names no addon on the cancelled subscription.
            $browser->assertDontSee('Softdrinks');
            $this->assertSame([], $this->dashboardAddonBadges($browser));

            // The addons screen: Softdrinks is off.
            $browser->visit('/billing/addons')->waitForText('Softdrinks', 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, '[dusk="addon-active-softdrinks"]'), 'not marked active');
            $this->assertSame(0, $this->domCount($browser, self::DISABLE_SOFTDRINKS), 'nothing to switch off');
            $this->assertSame(0, $this->domCount($browser, self::ADDON_POLL), 'nothing waits');
            $browser->assertDontSee(__('billing::portal.flash.plan_change_pending_payment'));
            $this->shot($browser, 'Addon-Seite nach der Kündigung');

            // The application's gate refuses it and says why.
            $browser->visit('/_wb/feature/softdrinks');
            $this->waitForPathContains($browser, '/billing');
            $browser->waitForText(__('billing::portal.upgrade_required'), 15);
            $browser->assertDontSee('FEATURE-SOFTDRINKS-OK');
            $this->shot($browser, 'Feature-Sperre greift');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $this->assertTrue($billable->getBillingSubscriptionEndsAt()?->isFuture() ?? false, 'graceful: access to the period end');
        $this->assertSame([], $billable->getActiveBillingAddonCodes());
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertSame('not_cancelable', $meta['orphaned_prorata_payment']['reason'] ?? null);
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie was put back before the cancel');
        $this->assertSame('canceled', (string) DB::table('fake_mollie_subscriptions')->where('id', (string) $meta['mollie_subscription_id'])->value('status'));

        // The charge settles after all: returned in full, the addon stays off.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $this->assertSame([], $billable->fresh()->getActiveBillingAddonCodes());
        $this->assertSame(
            (float) FakeMollie::payment($paymentId)['amount_value'],
            (float) DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->sum('amount_value'),
        );
    }

    // ── 19. SyncSeatsJob while the customer's addon change holds the lock ──

    public function test_a_seat_added_by_the_job_while_an_addon_change_holds_the_lock_is_billed_after_it(): void
    {
        $this->useTheDatabaseQueueHere();

        $billable = $this->changesSubscriber(seats: 3);
        $addonPayment = '';

        $this->browse(function (Browser $browser) use ($billable, &$addonPayment): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->recordPage($browser);

            // The addon change takes the change lock and reaches Mollie, which takes its time.
            WorkbenchSetting::set('hold_mollie_request', 'CreatePaymentRequest');
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $browser->waitUsing(15, 100, fn (): bool => WorkbenchSetting::get('mollie_request_held') === 'CreatePaymentRequest',
                'the addon change never reached Mollie');

            $probe = Cache::lock('billing:subscription-change:'.$billable->getKey(), 5);
            $this->assertFalse($probe->get(), 'the addon change holds the change lock');

            // A member is invited in that window.
            dispatch(SyncSeatsJob::byDelta(TestBillable::class, $billable->getKey(), 1));
            $this->assertSame(1, DB::table('jobs')->count());
            $this->workOneQueuedJob();

            // Refused by the lock, put back on the queue — not failed, not lost.
            $this->assertSame(0, DB::table('failed_jobs')->count(), 'no failed job');
            $this->assertSame(1, DB::table('jobs')->count(), 'the seat change waits on the queue');
            $this->assertGreaterThan(now()->addSeconds(30)->getTimestamp(), (int) DB::table('jobs')->value('available_at'), 'a minute on');
            $this->assertSame(3, $billable->fresh()->getBillingSeatCount(), 'nothing written yet');

            // Mollie answers; the addon change finishes.
            WorkbenchSetting::forget('hold_mollie_request');
            $browser->waitUsing(15, 200, fn (): bool => $this->pendingChargeId($billable) !== '',
                'the addon change never finished');
            $addonPayment = $this->pendingChargeId($billable);
            $this->assertSame(200, $this->deliverWebhook($addonPayment));

            $browser->waitUsing(15, 200, fn (): bool => in_array(
                __('billing::portal.addons_flash.enabled', ['addon' => 'Softdrinks']),
                array_column($this->toastsSeen($browser), 'text'),
                true,
            ), 'the addons page never confirmed the addon');
            $this->waitForPresence($browser, self::DISABLE_SOFTDRINKS, 15);
            $this->shot($browser, 'Addon aktiv, Sitzplatz-Job wartet');
        });

        // The minute is up: the job runs again, gets the lock and bills the seat.
        DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);
        $this->workOneQueuedJob();

        $billable->refresh();
        $seatPayment = $this->pendingChargeId($billable);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'no failed job');
        $this->assertSame(4, $billable->getBillingSeatCount());
        $this->assertNotSame('', $seatPayment, 'the seat is charged');
        $this->assertNotSame($addonPayment, $seatPayment);
        $this->assertSame(200, $this->deliverWebhook($seatPayment));

        $billable->refresh();
        $this->assertSame(4, $billable->getBillingSeatCount());
        $this->assertSame(['softdrinks'], $billable->getActiveBillingAddonCodes());
        $this->assertSame(self::PRO_SEAT_SOFTDRINKS_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie collects the seat and the addon');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $addonPayment)->count());
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $seatPayment)->count());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->assertSeatsScreenShows($browser, $billable, 4);

            $browser->visit('/billing')->waitFor('[data-next-charge]', 15)->pause(300);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'seat and addon after both changes');
            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $this->nextChargeOnScreen($browser)['state']);
            $this->shot($browser, 'Dashboard: Sitzplatz und Addon in der nächsten Abbuchung');
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A Pro subscriber whose running period was paid by a booked renewal, with Mollie on
     * exactly what the configuration costs.
     */
    protected function changesSubscriber(int $seats): TestBillable
    {
        $billable = $this->opsSubscriber('pro', $seats);
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['used_seats' => 1])])->save();

        app(PlanWaiver::class)->syncMollie($billable->fresh());
        DB::table('fake_mollie_subscriptions')
            ->where('id', (string) $billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])
            ->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));

        return $billable->refresh();
    }

    /** Business, chosen for the period end on the plan screen. Mollie is told at once. */
    protected function scheduleBusinessForThePeriodEnd(Browser $browser, TestBillable $billable): void
    {
        $this->actAs($browser, $billable, '/billing/plan');
        $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
        $this->clickWire($browser, self::PREVIEW_BUSINESS);
        $browser->waitFor(self::APPLY, 15)->pause(500);
        $this->jsClick($browser, self::END_OF_PERIOD);
        $browser->assertSeeIn(self::APPLY, __('billing::portal.schedule_end_of_period'));
        $this->clickWire($browser, self::APPLY);
        $browser->waitForText(__('billing::portal.cancel_scheduled_change'), 15)->pause(400);
        $this->shot($browser, 'Business zum Periodenende geplant');

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertSame('business', $meta['scheduled_change']['plan_code'] ?? null);
        $this->assertTrue((bool) ($meta['scheduled_change']['mollie_prepatched'] ?? false), 'announced to Mollie');
        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie holds Business for the next charge');
        $this->assertSame('Pro', $this->headerPlan($browser));
    }

    /**
     * "Apply now" from the menu beside the scheduled change, with the prorata charge left
     * open at Mollie. Returns that charge's id; the page is being recorded.
     */
    protected function applyScheduledChangeNowAwaitingPayment(Browser $browser, TestBillable $billable): string
    {
        $this->recordPage($browser);
        $this->setRecurringOutcome('open');

        $browser->click(self::APPLY_NOW_MENU);
        $browser->waitFor(self::APPLY_NOW, 10)->pause(200);
        $browser->assertSeeIn(self::APPLY_NOW, __('billing::portal.apply_now'));
        $this->shot($browser, 'Menü: Jetzt anwenden');
        $this->clickWire($browser, self::APPLY_NOW);

        $browser->waitUsing(15, 200, fn (): bool => $this->pendingChargeId($billable) !== '',
            'the applied change never started waiting for its payment');
        $browser->waitFor(self::CANCEL_PENDING, 15)->pause(600);
        $this->setRecurringOutcome('paid');

        $paymentId = $this->pendingChargeId($billable);
        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame('recurring', FakeMollie::payment($paymentId)['sequence_type']);
        $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null);
        $this->assertArrayNotHasKey('scheduled_change', $meta, 'the scheduled change became the waiting one');
        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::PRO_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie is put back on Pro while the charge waits');

        $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
        $this->assertSame(1, $this->domCount($browser, self::POLL), 'the waiting change is polled for');
        $this->assertSame(0, $this->domCount($browser, self::CANCEL_SCHEDULED), 'no longer scheduled');
        $this->assertSame('Pro', $this->headerPlan($browser), 'not switched before the payment');
        $this->assertNotContains(
            __('billing::portal.flash.plan_changed'),
            array_column($this->toastsSeen($browser), 'text'),
            '"Apply now" must not announce a change that waits for its payment',
        );
        $this->shot($browser, 'Jetzt angewendet: wartet auf Zahlung');

        return $paymentId;
    }

    /** One more seat on the seats screen, its prorata charge left open at Mollie. */
    protected function addOneSeatAwaitingPayment(Browser $browser, TestBillable $billable): string
    {
        $this->actAs($browser, $billable, '/billing/seats');
        $browser->waitFor(self::INCREMENT, 15)->pause(400);
        $this->assertSame('5', trim($browser->text('[dusk="seats-current"]')));

        $this->clickWire($browser, self::INCREMENT);
        $browser->waitUsing(10, 100, fn (): bool => trim($browser->text('[dusk="seats-current"]')) === '6', 'the stepper never showed 6');

        $this->setRecurringOutcome('open');
        $this->clickWire($browser, self::SYNC_SEATS);
        $browser->waitUsing(15, 200, fn (): bool => $this->pendingChargeId($billable) !== '',
            'the seat charge never started waiting for its payment');
        $browser->waitForText(__('billing::portal.seats_flash.synced'), 15)->pause(300);
        $this->setRecurringOutcome('paid');
        $this->shot($browser, 'Sechster Sitzplatz, Zahlung offen');

        $paymentId = $this->pendingChargeId($billable);

        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame(6, $billable->fresh()->getBillingSeatCount(), 'a seat change is written at once');
        $this->assertSame(self::PRO_SIX_SEATS_GROSS, $this->mollieSubscriptionAmount($billable), 'and reaches Mollie at once');

        return $paymentId;
    }

    /** The dashboard while the seat charge is open: it bills the sixth seat; then cancel. */
    protected function cancelOnTheDashboardBeforeTheCharge(Browser $browser, TestBillable $billable): void
    {
        $browser->visit('/billing')->waitForText(__('billing::portal.cancel_subscription'), 15)->pause(300);
        $this->assertNextChargeMatchesBooks($browser, $billable, 'before the cancel');
        $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $this->nextChargeOnScreen($browser)['state']);
        $this->assertTrue($this->opsSeesMoney($browser, 6985), 'the next charge bills six seats');

        $browser->press(__('billing::portal.cancel_subscription'));
        $browser->waitFor(self::CANCEL_SUBSCRIPTION, 10)->pause(300);
        $this->clickWire($browser, self::CANCEL_SUBSCRIPTION);
        $browser->waitForText(__('billing::portal.resubscribe'), 15)->pause(400);
        $browser->assertSee(__('billing::portal.flash.cancelled'));
        $this->shot($browser, 'Gekündigt, Sitzplatz-Zahlung offen');

        $shown = $this->nextChargeOnScreen($browser);
        $this->assertSame(NextChargeSummary::STATE_ENDING, $shown['state'], 'nothing further is charged');
        $this->assertNull($shown['gross']);
        $this->assertFalse($this->opsSeesMoney($browser, 6985), 'six seats are billed nowhere on the page');
        $this->assertNextChargeMatchesBooks($browser, $billable, 'after the cancel');
    }

    protected function assertSeatTakenBack(TestBillable $billable): void
    {
        $billable = $billable->fresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $this->assertTrue($billable->getBillingSubscriptionEndsAt()?->isFuture() ?? false, 'graceful: access to the period end');
        $this->assertSame(5, $billable->getBillingSeatCount(), 'the unpaid seat goes with the cancel');
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertSame(self::PRO_FIVE_SEATS_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie put back to five seats before it was cancelled');
        $this->assertSame('canceled', (string) DB::table('fake_mollie_subscriptions')->where('id', (string) $meta['mollie_subscription_id'])->value('status'));
    }

    protected function assertSeatsScreenShows(Browser $browser, TestBillable $billable, int $seats): void
    {
        $this->actAs($browser, $billable, '/billing/seats');
        $browser->waitFor('[dusk="seats-current"]', 15)->pause(300);
        $this->assertSame((string) $seats, trim($browser->text('[dusk="seats-current"]')), 'seats on the seats screen');
        $this->shot($browser, "Sitzplätze: {$seats}");
    }

    protected function pendingChargeId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /** @return list<string> the addon badges on the dashboard's subscription card */
    protected function dashboardAddonBadges(Browser $browser): array
    {
        return (array) $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const card = sub ? sub.closest('[data-flux-card]') : null;
            return card ? [...card.querySelectorAll('[data-flux-badge]')].map((b) => b.textContent.trim()).filter((t) => t === 'Softdrinks' || t === 'Priority Support') : [];
        JS, json_encode(__('billing::portal.addons'))))[0];
    }

    /**
     * This process — and only this one — on Laravel's database queue, so a job that puts
     * itself back is seen doing so (on `sync` the copy would run inside the run it follows).
     */
    protected function useTheDatabaseQueueHere(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'workbench',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 300,
                'after_commit' => false,
            ],
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'workbench',
            'queue.failed.table' => 'failed_jobs',
        ]);
    }

    /** What one queue worker tick does: the next job that is due, if any. */
    protected function workOneQueuedJob(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--queue' => 'default']);
    }

    /**
     * Capture, from now on, every toast the page dispatches (Flux's `toast-show`) and the
     * method calls of every Livewire request.
     */
    protected function recordPage(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__wbToasts = [];
            window.__wbCommits = [];
            document.addEventListener('toast-show', (e) => {
                const d = Array.isArray(e.detail) ? e.detail[0] : e.detail;
                window.__wbToasts.push({ text: d?.slots?.text ?? null, variant: d?.dataset?.variant ?? null });
            });
            window.Livewire.hook('commit', ({ commit }) => {
                window.__wbCommits.push((commit.calls || []).map((c) => c.method));
            });
        JS);
    }

    /** @return list<array{text: ?string, variant: ?string}> */
    protected function toastsSeen(Browser $browser): array
    {
        return (array) $browser->script('return window.__wbToasts || [];')[0];
    }

    /** @return list<list<string>> */
    protected function livewireCalls(Browser $browser): array
    {
        return (array) $browser->script('return window.__wbCommits || [];')[0];
    }

    /** Send the request a poll sends ($refresh) and wait for its answer. */
    protected function refreshComponent(Browser $browser): void
    {
        $before = count($this->livewireCalls($browser));

        $browser->script(<<<'JS'
            const el = document.querySelector('[wire\\:id]');
            window.Livewire.find(el.getAttribute('wire:id')).$refresh();
        JS);

        $browser->waitUsing(10, 100, fn (): bool => count($this->livewireCalls($browser)) > $before, 'the refresh was never sent')
            ->pause(600);
    }

    /** The plan named in the page header's "current plan" card. */
    protected function headerPlan(Browser $browser): ?string
    {
        return $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const heading = sub ? sub.parentElement.querySelector('[data-flux-heading]') : null;
            return heading ? heading.textContent.trim() : null;
        JS, json_encode(__('billing::portal.current_plan'))))[0];
    }
}

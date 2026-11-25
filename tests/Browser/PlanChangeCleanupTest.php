<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Dusk\Browser;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\MollieApiClient;
use Mollie\Laravel\Facades\Mollie;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\FakeMollie\FakeMollieHttpAdapter;

/**
 * What the customer reads after the nightly clean-up (`CleanupStalePendingProrataChangeJob`)
 * gave up a change that waited for its prorata payment.
 *
 *  - Plan page, payment collected and booked, its run reported as not resumable: the warning
 *    callout `plan-change-unfinished` ("payment received … do not start again"), never the
 *    danger callout "payment failed, try again", no pending notice, no cancel button — on a
 *    fresh visit, after a reload, and gone after a week.
 *  - Plan page, payment collected but the webhook never came: the same callout; the operators
 *    are told once; a late delivery returns the money and is never announced as a change.
 *  - Plan page, Mollie unreachable for seven days: "status still being checked" — on the page
 *    open at the time (with its toast) and on the next visit; inside the seven days nothing
 *    is dropped; a payment that settles afterwards is returned in full.
 *  - Plan page, payment declined and the webhook never came: "failed", not "unfinished".
 *  - Addons page, an addon charge collected unseen and dropped: what the polling page shows.
 *
 * The clean-up and every webhook delivery run in this process, against the shared world;
 * the browser sees the result through the page's own poll or a fresh visit.
 *
 * Workbench catalog, 19 % VAT: Starter 9.90 → 11.78 gross, Business 99.00 → 117.81 gross.
 */
class PlanChangeCleanupTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const CANCEL_PENDING = '[wire\\:click="cancelPendingChange"]';

    private const POLL = '[wire\\:poll\\.5s]';

    private const UNFINISHED = '[dusk="plan-change-unfinished"]';

    private const ENABLE_SOFTDRINKS = '[wire\\:click="enableAddon(\'softdrinks\')"]';

    private const DISABLE_SOFTDRINKS = '[wire\\:click="disableAddon(\'softdrinks\')"]';

    private const ADDON_POLL = '[wire\\:poll\\.3s="pollForChange"]';

    private const STARTER_GROSS = '11.78';

    /** The adapter this process's Mollie calls go through once `mollieDown()` armed it. */
    private ?FakeMollieHttpAdapter $outage = null;

    protected function tearDown(): void
    {
        // A static: it would outlive this test's application.
        MollieBilling::notifyAdminUsing(fn (): array => []);
        $this->outage = null;

        parent::tearDown();
    }

    // ── 1. Paid, booked, reported — then dropped by the clean-up ───────────

    public function test_a_booked_change_dropped_after_its_run_was_reported_reads_unfinished_paid_on_the_next_visit(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        // Collected; the run books the charge and dies; it was then reported as one that
        // will not be resumed (ProrataChargeHandler::reportInterruptedRun()), a day ago.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->crashRunOnceAfter('invoiced');
        $this->assertSame(500, $this->deliverWebhook($paymentId), 'the interrupted run answers 500');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'the charge invoice is written');

        $this->seedPending($billable, function (array $pending): array {
            $pending['created_at'] = now('UTC')->subHours(30)->toIso8601String();
            $pending['progress']['interrupted_reported_at'] = now('UTC')->subHours(20)->toIso8601String();

            return $pending;
        });

        $progressBefore = $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['progress'];
        $this->assertSame(['invoiced'], $progressBefore['done'] ?? null);

        (new CleanupStalePendingProrataChangeJob)->handle();

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertArrayNotHasKey('plan_change_failed_at', $meta, 'not a failed payment');
        $this->assertSame('paid', $meta['plan_change_unfinished']['payment'] ?? null);
        $this->assertNotEmpty($meta['plan_change_unfinished']['at'] ?? null);
        $this->assertSame($paymentId, $meta['orphaned_prorata_payment']['payment_id'] ?? null);
        $this->assertSame('charge paid and booked, but its plan change was never completed', $meta['orphaned_prorata_payment']['reason'] ?? null);
        $this->assertSame('business', $meta['orphaned_prorata_payment']['pending_plan_change']['plan_code'] ?? null, 'the intended change is kept for the operator');
        $this->assertSame($progressBefore, $meta['orphaned_prorata_payment']['progress'] ?? null, 'the run\'s record is kept for the operator');
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie never PATCHed');
        $this->assertSame('paid', FakeMollie::payment($paymentId)['status']);
        $this->assertSame(0, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count(), 'nothing refunded automatically');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'booked once');
        Notification::assertNothingSent();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::UNFINISHED, 15)->pause(400);
            $this->shot($browser, 'Aufgegeben nach Bericht: Zahlung eingegangen');

            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_paid'));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_unfinished_unknown'));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $browser->assertDontSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(1, $this->domCount($browser, self::UNFINISHED));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING), 'nothing left to cancel');
            $this->assertSame(0, $this->domCount($browser, self::POLL), 'nothing left to poll for');
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->assertSame([], $this->visibleToasts($browser), 'a fresh visit announces nothing');
            // The customer is no longer locked out of the plan screen.
            $this->assertSame(1, $this->domCount($browser, self::PREVIEW_BUSINESS));

            // A reload says the same.
            $browser->refresh();
            $browser->waitFor(self::UNFINISHED, 15);
            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_paid'));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));

            // Shown for a week.
            $this->seedMeta($billable, function (array $meta): array {
                $meta['plan_change_unfinished']['at'] = now('UTC')->subDays(8)->toIso8601String();

                return $meta;
            });

            $browser->refresh();
            $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED), 'gone after a week');
            $browser->assertDontSee(__('billing::portal.flash.plan_change_unfinished_paid'));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $this->shot($browser, 'Nach einer Woche: kein Hinweis mehr');
        });
    }

    // ── 1b. Paid, its webhook never came ────────────────────────────────────

    public function test_a_paid_change_whose_webhook_never_came_is_carried_out_by_the_cleanup_and_a_late_delivery_changes_nothing(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->backdatePendingChange($billable, hours: 25);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            // The page the customer left open since booking polls on.
            $this->openPlanPageWithPendingChange($browser, $billable);

            // The clean-up delivers the lost webhook through its own path: the change is
            // booked and carried out, exactly as the delivery would have.
            (new CleanupStalePendingProrataChangeJob)->handle();

            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->waitUsing(15, 200, fn (): bool => $this->headerPlan($browser) === 'Business',
                'the header never showed the new plan');
            $this->shot($browser, 'Webhook nie angekommen: Aufräumjob führt den Wechsel aus');

            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']],
                $this->toastsSeen($browser),
                'one toast, "plan changed" — no "unfinished", no "failed"',
            );
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
            $this->assertSame(0, $this->domCount($browser, self::POLL));

            // A second night and Mollie's late webhook change nothing.
            (new CleanupStalePendingProrataChangeJob)->handle();
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $browser->refresh();
            $browser->waitUsing(15, 200, fn (): bool => $this->headerPlan($browser) === 'Business',
                'the plan did not stay switched');
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
            $this->assertSame([], $this->visibleToasts($browser));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
        foreach (['pending_plan_change', 'pending_prorata_change', 'prorata_pending_payment_id', 'orphaned_prorata_payment', 'plan_change_unfinished', 'plan_change_failed_at'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'booked once');
        $this->assertSame(0.0, (float) DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->sum('amount_value'), 'nothing returned');
        Notification::assertNothingSentTo(new AnonymousNotifiable);
    }

    // ── 2. Mollie unreachable for seven days ────────────────────────────────

    public function test_a_change_dropped_because_mollie_stayed_unreachable_for_seven_days_reads_unfinished_unknown(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        $this->backdatePendingChange($billable, hours: 8 * 24);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $this->mollieDown();
            (new CleanupStalePendingProrataChangeJob)->handle();
            $this->mollieUp();

            $meta = $billable->fresh()->getBillingSubscriptionMeta();
            $this->assertArrayNotHasKey('pending_prorata_change', $meta);
            $this->assertArrayNotHasKey('pending_plan_change', $meta);
            $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
            $this->assertArrayNotHasKey('plan_change_failed_at', $meta, 'not reported as failed');
            $this->assertSame('unknown', $meta['plan_change_unfinished']['payment'] ?? null);
            $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);

            Notification::assertSentTimes(AdminPlanChangeFailedNotification::class, 1);
            $this->assertCount(1, Notification::sent(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class,
                fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'unreachable')
                    && ($n->context['payment_id'] ?? null) === $paymentId));

            // The open page's poll finds the change gone and says why.
            $browser->waitFor(self::UNFINISHED, 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_unknown'));
            $this->shot($browser, 'Mollie unerreichbar: Status wird geprüft');

            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_change_unfinished_unknown'), 'variant' => 'warning']],
                $this->toastsSeen($browser),
            );
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::POLL));

            // The next visit: the callout, no toast, nothing pending.
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::UNFINISHED, 15)->pause(400);
            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_unknown'));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_unfinished_paid'));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $browser->assertDontSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));
            $this->assertSame([], $this->visibleToasts($browser));
            $this->shot($browser, 'Nächster Besuch: Status wird geprüft');
        });

        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable));
        $this->assertSame('open', FakeMollie::payment($paymentId)['status'], 'nothing was asked of Mollie');
    }

    public function test_mollie_unreachable_for_less_than_seven_days_keeps_the_change_waiting(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        $this->backdatePendingChange($billable, hours: 3 * 24);
        $before = $billable->fresh()->getBillingSubscriptionMeta();

        $this->browse(function (Browser $browser) use ($billable, $before): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $this->mollieDown();
            (new CleanupStalePendingProrataChangeJob)->handle();
            $this->mollieUp();

            $this->assertEquals($before, $billable->fresh()->getBillingSubscriptionMeta(), 'nothing dropped, nothing marked');

            $this->waitForPollAfter($browser, count($this->livewireCalls($browser)));
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING));
            $this->assertSame(1, $this->domCount($browser, self::POLL));
            $this->assertSame([], $this->toastsSeen($browser));
            $this->shot($browser, 'Mollie drei Tage unerreichbar: Änderung wartet weiter');
        });

        Notification::assertNothingSent();
        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
    }

    public function test_the_payment_of_a_change_dropped_as_unknown_is_returned_when_it_settles_later(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        $this->backdatePendingChange($billable, hours: 8 * 24);

        $this->mollieDown();
        (new CleanupStalePendingProrataChangeJob)->handle();
        $this->mollieUp();
        $this->assertSame('unknown', $billable->fresh()->getBillingSubscriptionMeta()['plan_change_unfinished']['payment'] ?? null);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::UNFINISHED, 15)->pause(400);
            $this->recordPage($browser);

            // Mollie is back; the payment settles and its webhook arrives.
            FakeMollie::settle($paymentId, 'paid', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $this->refreshComponent($browser);
            $this->assertSame('Starter', $this->headerPlan($browser), 'the dropped change is not carried out');
            $this->assertSame([], $this->toastsSeen($browser), 'nothing announced');
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));
            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_unknown'));
            $this->shot($browser, 'Zahlung kam später: zurückerstattet, Plan unverändert');
        });

        $billable->refresh();

        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable));
        $this->assertSame(
            (float) FakeMollie::payment($paymentId)['amount_value'],
            (float) DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->sum('amount_value'),
            'returned in full',
        );
    }

    // ── Declined, its webhook never came ────────────────────────────────────

    public function test_a_declined_change_resolved_by_the_cleanup_reads_failed_not_unfinished(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        FakeMollie::settle($paymentId, 'failed', 'DE');
        $this->backdatePendingChange($billable, hours: 25);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            (new CleanupStalePendingProrataChangeJob)->handle();

            $browser->waitForText(__('billing::portal.flash.plan_change_failed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Zahlung abgelehnt: Aufräumjob meldet Fehlschlag');

            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED), 'a failed payment is not "unfinished"');
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_change_failed'), 'variant' => 'danger']],
                $this->toastsSeen($browser),
            );
            $this->assertSame('Starter', $this->headerPlan($browser));
        });

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('plan_change_unfinished', $meta);
        $this->assertNotEmpty($meta['plan_change_failed_at'] ?? null);
        $this->assertSame('Charge failed', $meta['plan_change_failed_reason'] ?? null);
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable));
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
    }

    // ── 3. The addons page ──────────────────────────────────────────────────

    public function test_the_addons_page_after_the_cleanup_dropped_an_addon_charge_paid_unseen(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $amountBefore = (string) $this->mollieSubscriptionAmount($billable);
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, $amountBefore, &$paymentId): void {
            $paymentId = $this->enableSoftdrinksAwaitingPayment($browser, $billable);

            // While the payment is open, the page polls and says it waits.
            $browser->assertSee(__('billing::portal.flash.plan_change_pending_payment'));
            $this->assertSame(1, $this->domCount($browser, self::ADDON_POLL));
            $this->shot($browser, 'Addon wartet auf Zahlung');

            // An addon change is applied at once; only the charge waits.
            $this->assertContains('softdrinks', $billable->fresh()->getActiveBillingAddonCodes());
            $this->assertNotSame($amountBefore, (string) $this->mollieSubscriptionAmount($billable), 'Mollie already PATCHed');
            $this->assertArrayNotHasKey('pending_plan_change', $billable->fresh()->getBillingSubscriptionMeta());

            // Collected; the webhook never arrives. A day on, the clean-up drops it.
            FakeMollie::settle($paymentId, 'paid', 'DE');
            $this->backdatePendingChange($billable, hours: 25);
            (new CleanupStalePendingProrataChangeJob)->handle();

            // No endless spinner: the poll stops and the waiting callout goes.
            $this->waitForAbsence($browser, self::ADDON_POLL, 15);
            $browser->waitUntilMissingText(__('billing::portal.flash.plan_change_pending_payment'), 15)->pause(400);
            $this->shot($browser, 'Aufräumjob: Addon-Seite nach dem Abfragen');

            // What it says today: the addon is enabled — which it is.
            $this->assertSame(
                [['text' => __('billing::portal.addons_flash.enabled', ['addon' => 'Softdrinks']), 'variant' => 'success']],
                $this->toastsSeen($browser),
            );
            $texts = array_column($this->toastsSeen($browser), 'text');
            $this->assertNotContains(__('billing::portal.flash.plan_change_failed'), $texts);
            $this->waitForPresence($browser, self::DISABLE_SOFTDRINKS, 10);

            // The plan page names nothing: there was no plan change to leave unfinished.
            $browser->visit('/billing/plan');
            $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
            $this->assertSame(0, $this->domCount($browser, self::CANCEL_PENDING));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $this->shot($browser, 'Planseite nach verworfener Addon-Zahlung');

            // A fresh visit to the addons page: nothing waits, nothing polls.
            $browser->visit('/billing/addons');
            $this->waitForPresence($browser, self::DISABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::ADDON_POLL));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_pending_payment'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        // The clean-up delivered the lost webhook: the charge is booked, nothing is orphaned.
        $this->assertContains('softdrinks', $billable->getActiveBillingAddonCodes());
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('plan_change_unfinished', $meta, 'only a plan change is named unfinished');
        $this->assertArrayNotHasKey('plan_change_failed_at', $meta);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'collected and invoiced');
        $this->assertSame('paid', FakeMollie::payment($paymentId)['status']);
        Notification::assertNotSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
    }

    public function test_a_late_webhook_for_an_addon_charge_the_cleanup_dropped_does_not_refund_an_addon_that_stays_on(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $paymentId = '';

        $this->browse(function (Browser $browser) use ($billable, &$paymentId): void {
            $paymentId = $this->enableSoftdrinksAwaitingPayment($browser, $billable);
        });

        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->backdatePendingChange($billable, hours: 25);
        (new CleanupStalePendingProrataChangeJob)->handle();
        // Delivered from the clean-up: booked, not orphaned.
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $billable->fresh()->getBillingSubscriptionMeta());
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

        // Mollie's retry of the webhook finally gets through.
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $billable->refresh();
        $refunded = (float) DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->sum('amount_value');
        $addonOn = in_array('softdrinks', $billable->getActiveBillingAddonCodes(), true);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 15)->pause(400);
            $this->shot($browser, 'Später Webhook für verworfene Addon-Zahlung');
        });

        $this->assertTrue($addonOn, 'the addon stays on');
        $this->assertSame(0.0, $refunded, 'an addon that stays on is not given back for free');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Book Starter → Business on the plan screen with the prorata charge left open at
     * Mollie, as a slow payment method leaves it. Returns that payment's id.
     */
    protected function bookUpgradeAwaitingPayment(TestBillable $billable): string
    {
        $this->setRecurringOutcome('open');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $this->clickWire($browser, self::PREVIEW_BUSINESS);
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $browser->assertSeeIn(self::APPLY, __('billing::portal.apply_immediately'));
            $this->clickWire($browser, self::APPLY);

            $browser->waitUsing(15, 200, fn (): bool => $this->pendingPaymentId($billable) !== '',
                'the upgrade never started waiting for its payment');
            $this->waitForPresence($browser, self::CANCEL_PENDING, 15);
        });

        $this->setRecurringOutcome('paid');

        $paymentId = $this->pendingPaymentId($billable);
        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame('recurring', FakeMollie::payment($paymentId)['sequence_type']);
        $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null);
        $this->assertSame($paymentId, $meta['prorata_pending_payment_id'] ?? null);
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable));

        return $paymentId;
    }

    /**
     * Switch Softdrinks on from the addons page with its prorata charge left open at Mollie,
     * and start recording the page. Returns the charge's payment id.
     */
    protected function enableSoftdrinksAwaitingPayment(Browser $browser, TestBillable $billable): string
    {
        $this->setRecurringOutcome('open');

        $this->actAs($browser, $billable, '/billing/addons');
        $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
        $browser->pause(400);
        $this->recordPage($browser);

        $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
        $browser->waitUsing(15, 200, fn (): bool => $this->pendingPaymentId($billable) !== '',
            'the addon charge never started waiting for its payment');
        $this->waitForPresence($browser, self::ADDON_POLL, 15);
        $browser->pause(400);

        $this->setRecurringOutcome('paid');

        $paymentId = $this->pendingPaymentId($billable);
        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame('recurring', FakeMollie::payment($paymentId)['sequence_type']);

        return $paymentId;
    }

    protected function pendingPaymentId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /** Make the change look `$hours` old to the nightly clean-up. */
    protected function backdatePendingChange(TestBillable $billable, int $hours): void
    {
        $this->seedPending($billable, function (array $pending) use ($hours): array {
            $pending['created_at'] = now('UTC')->subHours($hours)->toIso8601String();

            return $pending;
        });
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    protected function seedPending(TestBillable $billable, \Closure $change): void
    {
        $this->seedMeta($billable, function (array $meta) use ($change): array {
            $meta['pending_prorata_change'] = $change($meta['pending_prorata_change']);

            return $meta;
        });
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    protected function seedMeta(TestBillable $billable, \Closure $change): void
    {
        $fresh = $billable->fresh();
        $fresh->forceFill(['subscription_meta' => $change($fresh->getBillingSubscriptionMeta())])->save();
    }

    /** Admin notifications captured in this process, where the clean-up and webhooks run. */
    protected function operatorsTold(): void
    {
        Notification::fake();
        MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    }

    /**
     * Let the next prorata run in this process die once, right after `$step` — the way
     * tests/Feature/Webhook/ProrataChargeResumeTest.php interrupts it.
     */
    protected function crashRunOnceAfter(string $step): void
    {
        $handler = new class(
            app(WebhookSupport::class),
            app(InvoiceService::class),
            app(MollieSubscriptionPatcher::class),
            app(WalletPlanChangeAdjuster::class),
            app(CouponService::class),
        ) extends ProrataChargeHandler {
            public ?string $crashAfter = null;

            protected function markStep(Billable $billable, string $paymentId, array &$done, string $step, array $extra = []): void
            {
                parent::markStep($billable, $paymentId, $done, $step, $extra);

                if ($step === $this->crashAfter) {
                    $this->crashAfter = null;

                    throw new \RuntimeException("simulated crash after {$step}");
                }
            }
        };

        $handler->crashAfter = $step;
        app()->instance(ProrataChargeHandler::class, $handler);
    }

    /**
     * Every Mollie call from this process fails as an outage would, until `mollieUp()`. The
     * server process is untouched — the clean-up runs here.
     */
    protected function mollieDown(): void
    {
        $this->outage = new class extends FakeMollieHttpAdapter {
            public bool $down = true;

            public function sendRequest(PendingRequest $pendingRequest): Response
            {
                if ($this->down) {
                    throw new \RuntimeException('Fake Mollie: unreachable');
                }

                return parent::sendRequest($pendingRequest);
            }
        };

        $client = new MollieApiClient($this->outage);
        $client->setApiKey('test_workbenchworkbenchworkbenchworkbench');

        Mollie::swap($client);
    }

    protected function mollieUp(): void
    {
        if ($this->outage !== null) {
            $this->outage->down = false;
        }
    }

    /**
     * Land on the plan screen with the change pending — a fresh visit, so the component
     * mounts knowing it is pending and its poll runs — and start recording.
     */
    protected function openPlanPageWithPendingChange(Browser $browser, TestBillable $billable): void
    {
        $this->actAs($browser, $billable, '/billing/plan');
        $browser->waitFor(self::CANCEL_PENDING, 15)->pause(400);

        $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
        $this->assertSame(1, $this->domCount($browser, self::POLL), 'a pending change is polled for');
        $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
        $this->assertSame('Starter', $this->headerPlan($browser));

        $this->recordPage($browser);
    }

    /**
     * Capture, from now on, every toast the page dispatches (Flux's `toast-show`) and the
     * method calls of every Livewire request — a poll is a request with `$refresh` only.
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

    /** @return list<string> the text of every toast on screen right now */
    protected function visibleToasts(Browser $browser): array
    {
        return array_values(array_filter(array_map('trim', (array) $browser->script(<<<'JS'
            return [...document.querySelectorAll('[data-flux-toast-dialog]')]
                .filter((el) => el.offsetParent !== null || el.getClientRects().length > 0)
                .map((el) => el.innerText);
        JS)[0])));
    }

    /** Wait until the page has polled at least once after the first `$since` requests. */
    protected function waitForPollAfter(Browser $browser, int $since): void
    {
        $browser->waitUsing(15, 200, function () use ($browser, $since): bool {
            foreach (array_slice($this->livewireCalls($browser), $since) as $calls) {
                if (array_diff((array) $calls, ['$refresh']) === []) {
                    return true;
                }
            }

            return false;
        }, 'the page did not poll');

        // The poll's answer, not only its request.
        $browser->pause(600);
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

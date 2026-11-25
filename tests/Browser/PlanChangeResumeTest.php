<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * An upgrade whose prorata webhook run is interrupted, resumed, or reported as not
 * resumable — seen from the plan screen the customer keeps open while it happens.
 *
 * `ProrataChargeHandler::paid()` records each step it completes under
 * `pending_prorata_change.progress.done`; a re-delivery resumes after the last one. The
 * screen polls (`wire:poll.5s`) while `pending_plan_change` stands and announces the
 * pending → gone transition; "Cancel pending change" goes through
 * `UpdateSubscription::tryCancelPendingPlanChange()`, which must refuse
 * (`AlreadyPaid`) once the run has booked the payment.
 *
 *  - the cancel arrives while the run sits between its switch and its PATCH — in flight,
 *    and after a run that died there; the next delivery still PATCHes Mollie;
 *  - a run that died early answers 500, the page keeps waiting, the re-delivery finishes it
 *    and the poll announces it once; a throwing `PaymentSucceeded` listener kills nothing;
 *  - an interrupted run left alone by the nightly clean-up inside its resume window;
 *  - a run reported as not resumable (`renewal_booked`): the page keeps waiting, a cancel is
 *    refused, and the clean-up later names it unfinished — never "plan changed".
 *
 * The webhook is delivered in this process (FakeMollie::deliverWebhook), so the run is
 * interrupted here: the handler is swapped for one that dies once right after a step, the
 * way tests/Feature/Webhook/ProrataChargeResumeTest.php does it.
 *
 * Workbench catalog, 19 % VAT: Starter 9.90 → 11.78 gross, Business 99.00 → 117.81 gross.
 */
class PlanChangeResumeTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const CANCEL_PENDING = '[wire\\:click="cancelPendingChange"]';

    private const POLL = '[wire\\:poll\\.5s]';

    private const UNFINISHED = '[dusk="plan-change-unfinished"]';

    private const STARTER_GROSS = '11.78';

    private const BUSINESS_GROSS = '117.81';

    /** The swapped-in handler; null until a test arms it. */
    private ?ProrataChargeHandler $runHandler = null;

    private int $planChangedEvents = 0;

    /** A failure inside a hook that runs in the middle of a webhook run, kept for later. */
    private ?\Throwable $hookFailure = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planChangedEvents = 0;
        $this->hookFailure = null;
        $this->runHandler = null;

        Event::listen(PlanChanged::class, function (): void {
            $this->planChangedEvents++;
        });
    }

    protected function tearDown(): void
    {
        // A static: it would outlive this test's application.
        MollieBilling::notifyAdminUsing(fn (): array => []);

        parent::tearDown();
    }

    // ── 1. Cancel while the run sits between STEP_SWITCHED and STEP_PATCHED ──

    public function test_a_cancel_clicked_while_the_run_is_between_its_switch_and_its_patch_is_refused_and_the_run_still_patches(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $seen = [];

        $this->browse(function (Browser $browser) use ($billable, $paymentId, &$seen): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->holdPoll($browser);
            $this->shot($browser, 'Upgrade wartet, Seite bleibt offen');

            // The customer clicks while the run is between its switch and its PATCH: the
            // plan is switched locally, Mollie still collects the old amount.
            $this->runHandler()->afterStep['events'] = function (TestBillable $b) use ($browser, $paymentId, &$seen): void {
                try {
                    $meta = $b->fresh()->getBillingSubscriptionMeta();
                    $seen = [
                        'plan' => $b->fresh()->getBillingSubscriptionPlanCode(),
                        'done' => $meta['pending_prorata_change']['progress']['done'] ?? [],
                        'pending_plan_change' => array_key_exists('pending_plan_change', $meta),
                        'amount' => $this->mollieSubscriptionAmount($b),
                    ];

                    $this->clickWire($browser, self::CANCEL_PENDING);
                    $this->waitForToast($browser, __('billing::portal.flash.pending_change_already_paid'));
                    $seen['visible'] = $this->visibleToasts($browser);
                    $this->shot($browser, 'Abbruch mitten im Lauf: bereits bezahlt');

                    $seen['meta_after_click'] = $b->fresh()->getBillingSubscriptionMeta();
                } catch (\Throwable $e) {
                    $this->hookFailure = $e;
                }
            };

            $status = $this->deliverWebhook($paymentId);
            $this->rethrowHookFailure();

            $this->assertSame(200, $status, 'the run finished after the refused cancel');
            $this->assertSame('business', $seen['plan'], 'the click came after the switch');
            $this->assertContains('switched', $seen['done']);
            $this->assertNotContains('patched', $seen['done'], 'the click came before the PATCH');
            $this->assertFalse($seen['pending_plan_change'], 'the switch had removed pending_plan_change');
            $this->assertSame(self::STARTER_GROSS, $seen['amount'], 'Mollie not yet PATCHed when the customer clicked');

            // The refused cancel touched nothing the run still needed.
            $this->assertSame($paymentId, $seen['meta_after_click']['pending_prorata_change']['charge_payment_id'] ?? null, 'the waiting change survives the click');
            $this->assertArrayNotHasKey('orphaned_prorata_payment', $seen['meta_after_click']);

            $toasts = $this->toastsSeen($browser);
            $this->assertContains(['text' => __('billing::portal.flash.pending_change_already_paid'), 'variant' => 'warning'], $toasts);
            $this->assertNotContains(__('billing::portal.flash.pending_change_cancelled'), array_column($toasts, 'text'));
            $this->assertSame([['cancelPendingChange']], $this->livewireCalls($browser), 'the click was the only request');
            $this->assertSame('Business', $this->headerPlan($browser), 'the switch is in force');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable), 'the run PATCHed Mollie to Business');
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);
        $this->assertSame('paid', FakeMollie::payment($paymentId)['status'], 'nothing cancelled at Mollie');
        $this->assertSame(0, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count(), 'nothing refunded');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
        $this->assertSame(1, $this->planChangedEvents);

        $this->assertRefusalWasShown($seen['visible']);
    }

    public function test_a_cancel_after_a_run_died_past_its_switch_is_refused_and_the_next_delivery_patches_mollie(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $visible = [];

        $this->browse(function (Browser $browser) use ($billable, $paymentId, &$visible): void {
            // The old page: rendered while the change was waiting, its poll not yet due.
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->holdPoll($browser);

            // The run switches the plan and dies before announcing it and patching Mollie.
            $this->crashRunOnceAfter('switched');
            $this->assertSame(500, $this->deliverWebhook($paymentId), 'the interrupted run answers 500');

            $meta = $billable->fresh()->getBillingSubscriptionMeta();
            $done = $meta['pending_prorata_change']['progress']['done'] ?? [];
            $this->assertContains('switched', $done);
            $this->assertNotContains('events', $done);
            $this->assertNotContains('patched', $done);
            $this->assertArrayNotHasKey('pending_plan_change', $meta, 'the switch removed it');
            $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
            $this->assertSame('business', $billable->fresh()->getBillingSubscriptionPlanCode());
            $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'the charge is booked');
            $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable), 'not PATCHed yet');
            $subscriptionRowBefore = $this->fakeSubscriptionRow($billable);

            // The stale notice is still on screen; the customer clicks it.
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->clickWire($browser, self::CANCEL_PENDING);

            $this->waitForToast($browser, __('billing::portal.flash.pending_change_already_paid'));
            $visible = $this->visibleToasts($browser);
            $this->shot($browser, 'Abbruch nach Umstellung: bereits bezahlt');

            $toasts = $this->toastsSeen($browser);
            $this->assertContains(['text' => __('billing::portal.flash.pending_change_already_paid'), 'variant' => 'warning'], $toasts);
            $this->assertNotContains(__('billing::portal.flash.pending_change_cancelled'), array_column($toasts, 'text'));
            $this->assertSame([['cancelPendingChange']], $this->livewireCalls($browser));
            $this->assertSame('Business', $this->headerPlan($browser));

            // The waiting change is not cleared, and nothing went to Mollie.
            $after = $billable->fresh()->getBillingSubscriptionMeta();
            $this->assertSame($meta['pending_prorata_change'], $after['pending_prorata_change'] ?? null, 'the waiting change and its progress are untouched');
            $this->assertArrayNotHasKey('orphaned_prorata_payment', $after);
            $this->assertEquals($subscriptionRowBefore, $this->fakeSubscriptionRow($billable), 'the refused cancel sent no PATCH');
            $this->assertSame('paid', FakeMollie::payment($paymentId)['status']);
        });

        // Mollie re-delivers; the run picks up after its switch.
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable), 'the re-delivery PATCHed Mollie to Business');
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'booked once');
        $this->assertSame(1, $this->planChangedEvents, 'announced once, by the resumed run');
        $this->assertSame(0, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count());

        $this->assertRefusalWasShown($visible);
    }

    // ── 2. An interrupted run resumed while the page polls ──────────────────

    public function test_a_run_that_died_early_keeps_the_page_waiting_and_the_redelivery_is_announced_once(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->shot($browser, 'Upgrade wartet auf Zahlung');

            FakeMollie::settle($paymentId, 'paid', 'DE');

            // The first delivery books the charge and dies.
            $this->crashRunOnceAfter('invoiced');
            $this->assertSame(500, $this->deliverWebhook($paymentId));

            $meta = $billable->fresh()->getBillingSubscriptionMeta();
            $this->assertSame(['invoiced'], $meta['pending_prorata_change']['progress']['done'] ?? null);
            $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null, 'still waiting');
            $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
            $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

            // The page polls on and keeps waiting.
            $this->waitForPollAfter($browser, count($this->livewireCalls($browser)));
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING));
            $this->assertSame(1, $this->domCount($browser, self::POLL));
            $this->assertSame([], $this->toastsSeen($browser), 'nothing announced for a run that died');
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->shot($browser, 'Lauf abgebrochen: Seite wartet weiter');

            // Mollie delivers again; the poll picks the finished change up.
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $browser->waitForText(__('billing::portal.flash.plan_changed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Wiederaufgenommen: Plan geändert');

            $this->assertSame('Business', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::POLL), 'nothing left to poll for');

            // Past another poll interval and one more refresh: still announced exactly once.
            $this->waitPastOnePollInterval($browser);
            $this->refreshComponent($browser);
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']],
                $this->toastsSeen($browser),
                'exactly one toast: plan changed',
            );
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'the resumed run did not book it again');
        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable));
        $this->assertSame(1, $this->planChangedEvents);
    }

    public function test_a_throwing_payment_succeeded_listener_does_not_interrupt_the_run(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $thrown = 0;
        Event::listen(PaymentSucceeded::class, function () use (&$thrown): void {
            if ($thrown === 0) {
                $thrown++;

                throw new \RuntimeException('The app listener is down');
            }
        });

        $this->browse(function (Browser $browser) use ($billable, $paymentId, &$thrown): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            FakeMollie::settle($paymentId, 'paid', 'DE');

            // An app listener is isolated: one delivery, one finished run.
            $this->assertSame(200, $this->deliverWebhook($paymentId));
            $this->assertSame(1, $thrown, 'the listener did throw');

            $browser->waitForText(__('billing::portal.flash.plan_changed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Listener fällt aus: Plan trotzdem geändert');

            $this->assertSame('Business', $this->headerPlan($browser));
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']],
                $this->toastsSeen($browser),
            );
        });

        $billable->refresh();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_prorata_change', $billable->getBillingSubscriptionMeta());
        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable));
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
    }

    public function test_the_nightly_cleanup_leaves_an_interrupted_run_inside_its_resume_window_to_the_webhook(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        // A slow payment: the change is more than a day old when its webhook first arrives.
        $this->backdatePendingChange($billable, hours: 30);
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $this->crashRunOnceAfter('announced');
            $this->assertSame(500, $this->deliverWebhook($paymentId));

            // The run started minutes ago: the clean-up leaves it for Mollie's re-delivery.
            (new CleanupStalePendingProrataChangeJob)->handle();

            $meta = $billable->fresh()->getBillingSubscriptionMeta();
            $this->assertSame($paymentId, $meta['pending_prorata_change']['charge_payment_id'] ?? null, 'not dropped');
            $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null);
            $this->assertArrayNotHasKey('plan_change_unfinished', $meta);
            $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);

            $this->waitForPollAfter($browser, count($this->livewireCalls($browser)));
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED), 'not named unfinished');
            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING), 'still waiting');
            $this->assertSame([], $this->toastsSeen($browser));
            $this->shot($browser, 'Aufräumjob lässt den Lauf in Ruhe');

            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $browser->waitForText(__('billing::portal.flash.plan_changed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->assertSame('Business', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
        });

        $billable->refresh();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_prorata_change', $billable->getBillingSubscriptionMeta());
        $this->assertSame(self::BUSINESS_GROSS, $this->mollieSubscriptionAmount($billable));
    }

    // ── 3. A run reported as not resumable ──────────────────────────────────

    public function test_a_run_not_resumed_because_a_renewal_was_booked_keeps_the_page_waiting_and_refuses_a_cancel(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $this->dieAfterRefundsThenRenew($billable, $paymentId);

            // Mollie re-delivers. The period the run was decided in is over: reported, not resumed.
            $calls = count($this->livewireCalls($browser));
            $this->assertSame(200, $this->deliverWebhook($paymentId), 'a reported run is answered, not retried');

            $meta = $billable->fresh()->getBillingSubscriptionMeta();
            $this->assertNotEmpty($meta['pending_prorata_change']['progress']['interrupted_reported_at'] ?? null, 'reported');
            $this->assertNotContains('switched', $meta['pending_prorata_change']['progress']['done'] ?? []);
            $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null, 'the change still waits');
            $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
            Notification::assertSentTimes(AdminPlanChangeFailedNotification::class, 1);
            $this->assertCount(1, Notification::sent(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class,
                fn (AdminPlanChangeFailedNotification $n): bool => ($n->context['why'] ?? null) === 'renewal_booked'
                    && ($n->context['payment_id'] ?? null) === $paymentId));

            // The page polls on and still shows the change as waiting — nothing announced.
            $this->waitForPollAfter($browser, $calls);
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(1, $this->domCount($browser, self::POLL));
            $this->assertSame([], $this->toastsSeen($browser), 'no success, no plan changed');
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->shot($browser, 'Nicht fortgesetzt: Änderung wartet weiter');

            // A cancel is refused: the money is in.
            $this->clickWire($browser, self::CANCEL_PENDING);
            $browser->waitForText(__('billing::portal.flash.pending_change_already_paid'), 15);
            $this->shot($browser, 'Abbruch abgelehnt: bereits bezahlt');

            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING), 'the notice stays');
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));

            $this->waitForPollAfterLastAction($browser);
            $this->assertSame(
                [['text' => __('billing::portal.flash.pending_change_already_paid'), 'variant' => 'warning']],
                $this->toastsSeen($browser),
                'only the refusal — no cancelled, no plan changed',
            );
            $this->assertSame('Starter', $this->headerPlan($browser));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame($paymentId, $meta['pending_prorata_change']['charge_payment_id'] ?? null, 'the refused cancel cleared nothing');
        $this->assertSame($paymentId, $meta['prorata_pending_payment_id'] ?? null);
        $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta);
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable), 'Mollie never PATCHed');
        $this->assertSame('paid', FakeMollie::payment($paymentId)['status']);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
        $this->assertSame(0, $this->planChangedEvents);
        Notification::assertSentTimes(AdminPlanChangeFailedNotification::class, 1);
    }

    public function test_a_run_reported_as_not_resumable_is_named_unfinished_by_the_cleanup_never_plan_changed(): void
    {
        $this->operatorsTold();

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $this->dieAfterRefundsThenRenew($billable, $paymentId);
            $this->assertSame(200, $this->deliverWebhook($paymentId));
            $this->assertNotEmpty($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['progress']['interrupted_reported_at'] ?? null);

            // A day on, the clean-up drops it: reported runs are not waited for.
            $this->backdatePendingChange($billable, hours: 25);
            (new CleanupStalePendingProrataChangeJob)->handle();

            $browser->waitFor(self::UNFINISHED, 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->assertSeeIn(self::UNFINISHED, __('billing::portal.flash.plan_change_unfinished_paid'));
            $this->shot($browser, 'Aufgegeben: Planwechsel unvollendet');

            $texts = array_column($this->toastsSeen($browser), 'text');
            $this->assertContains(__('billing::portal.flash.plan_change_unfinished_paid'), $texts);
            $this->assertNotContains(__('billing::portal.flash.plan_changed'), $texts);
            $this->assertNotContains(__('billing::portal.flash.plan_change_failed'), $texts);
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::POLL));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertSame('paid', $meta['plan_change_unfinished']['payment'] ?? null);
        $this->assertSame($paymentId, $meta['orphaned_prorata_payment']['payment_id'] ?? null);
        $this->assertSame('charge paid and booked, but its plan change was never completed', $meta['orphaned_prorata_payment']['reason'] ?? null);
        $this->assertSame(self::STARTER_GROSS, $this->mollieSubscriptionAmount($billable));
        // Told once, by the webhook; the clean-up does not tell them again.
        Notification::assertSentTimes(AdminPlanChangeFailedNotification::class, 1);
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

    protected function pendingPaymentId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /**
     * Settle the charge, let its first delivery die right after the refunds step, then let
     * a renewal of the old plan be booked before Mollie delivers again.
     */
    protected function dieAfterRefundsThenRenew(TestBillable $billable, string $paymentId): void
    {
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $this->crashRunOnceAfter('refunds');
        $this->assertSame(500, $this->deliverWebhook($paymentId));

        $progress = $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['progress'] ?? [];
        $this->assertContains('refunds', $progress['done'] ?? []);
        $this->assertNotContains('switched', $progress['done'] ?? []);

        $anchor = $billable->fresh()->getBillingPeriodStartsAt()?->toIso8601String();
        $this->assertSame($progress['anchor'] ?? null, $anchor, 'the run was decided in the running period');

        $this->renewSubscription($billable->fresh());

        $this->assertNotSame($anchor, $billable->fresh()->getBillingPeriodStartsAt()?->toIso8601String(), 'the renewal moved the period');
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
    }

    /** Make the change look `$hours` old to the nightly clean-up. */
    protected function backdatePendingChange(TestBillable $billable, int $hours): void
    {
        $fresh = $billable->fresh();
        $meta = $fresh->getBillingSubscriptionMeta();
        $meta['pending_prorata_change']['created_at'] = now('UTC')->subHours($hours)->toIso8601String();
        $fresh->forceFill(['subscription_meta' => $meta])->save();
    }

    /** Admin notifications captured in this process, where the webhook runs. */
    protected function operatorsTold(): void
    {
        Notification::fake();
        MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    }

    /**
     * The prorata handler this process's webhook deliveries use, swapped for one that can
     * die right after a recorded step (`crashAfter`) or run something from outside right
     * after one (`afterStep`).
     */
    protected function runHandler(): ProrataChargeHandler
    {
        if ($this->runHandler !== null) {
            return $this->runHandler;
        }

        $this->runHandler = new class(
            app(WebhookSupport::class),
            app(InvoiceService::class),
            app(MollieSubscriptionPatcher::class),
            app(WalletPlanChangeAdjuster::class),
            app(CouponService::class),
        ) extends ProrataChargeHandler {
            public ?string $crashAfter = null;

            /** @var array<string, \Closure> */
            public array $afterStep = [];

            protected function markStep(Billable $billable, string $paymentId, array &$done, string $step, array $extra = []): void
            {
                parent::markStep($billable, $paymentId, $done, $step, $extra);

                if (isset($this->afterStep[$step])) {
                    $hook = $this->afterStep[$step];
                    unset($this->afterStep[$step]);
                    $hook($billable);
                }

                if ($step === $this->crashAfter) {
                    $this->crashAfter = null;

                    throw new \RuntimeException("simulated crash after {$step}");
                }
            }
        };

        app()->instance(ProrataChargeHandler::class, $this->runHandler);

        return $this->runHandler;
    }

    /**
     * Let the next prorata run die once, right after `$step`. The switch is written with
     * its progress in one save; the first thing after it that can die is announcing it
     * (UndercollectionCharge::configurationLeft(), before any event is dispatched).
     */
    protected function crashRunOnceAfter(string $step): void
    {
        if ($step !== 'switched') {
            $this->runHandler()->crashAfter = $step;

            return;
        }

        $crashing = new class(
            app(VatCalculationService::class),
            app(InvoiceService::class),
            app(WebhookSupport::class),
        ) extends UndercollectionCharge {
            public bool $armed = true;

            public function configurationLeft(Billable $billable, string $planCode): void
            {
                if ($this->armed) {
                    $this->armed = false;

                    throw new \RuntimeException('simulated crash after switched');
                }

                parent::configurationLeft($billable, $planCode);
            }
        };

        app()->instance(UndercollectionCharge::class, $crashing);
    }

    protected function rethrowHookFailure(): void
    {
        if ($this->hookFailure !== null) {
            $failure = $this->hookFailure;
            $this->hookFailure = null;

            throw $failure;
        }
    }

    /** @return array<string, mixed> the fake Mollie subscription row */
    protected function fakeSubscriptionRow(TestBillable $billable): array
    {
        $id = (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        return (array) DB::table('fake_mollie_subscriptions')->where('id', $id)->first();
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

    /** Wait until the page has dispatched a toast with this text, then for it to render. */
    protected function waitForToast(Browser $browser, string $text): void
    {
        $browser->waitUsing(15, 100, fn (): bool => in_array($text, array_column($this->toastsSeen($browser), 'text'), true),
            "the page never dispatched the toast [{$text}]");

        $browser->pause(400);
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

    /**
     * The refusal has to reach the customer, not only be dispatched. Checked last, so every
     * assertion about the books has run before it.
     *
     * @param  list<string>  $visible  what the toast region showed right after the click
     */
    protected function assertRefusalWasShown(array $visible): void
    {
        $refusal = __('billing::portal.flash.pending_change_already_paid');

        // Past the switch the same render used to toast "plan changed" too, and the portal's
        // single <flux:toast /> showed only that one: the refusal was dispatched, never seen.
        $this->assertContains($refusal, $visible, 'visible after the click: '.json_encode($visible));
    }

    /** @param list<string> $calls */
    private static function isPoll(array $calls): bool
    {
        return array_diff($calls, ['$refresh']) === [];
    }

    /** Wait until the page has polled at least once after the first `$since` requests. */
    protected function waitForPollAfter(Browser $browser, int $since): void
    {
        $browser->waitUsing(15, 200, function () use ($browser, $since): bool {
            foreach (array_slice($this->livewireCalls($browser), $since) as $calls) {
                if (self::isPoll((array) $calls)) {
                    return true;
                }
            }

            return false;
        }, 'the page did not poll');

        // The poll's answer, not only its request.
        $browser->pause(600);
    }

    /** Wait until the page has polled at least once after its last action. */
    protected function waitForPollAfterLastAction(Browser $browser): void
    {
        $commits = $this->livewireCalls($browser);
        $last = 0;

        foreach ($commits as $i => $calls) {
            if (! self::isPoll((array) $calls)) {
                $last = $i + 1;
            }
        }

        $this->waitForPollAfter($browser, $last);
    }

    /** Let one poll interval (5s) run out on the page's own clock. */
    protected function waitPastOnePollInterval(Browser $browser): void
    {
        $browser->script('window.__wbPollWindowOver = false; setTimeout(() => { window.__wbPollWindowOver = true; }, 5500);');
        $browser->waitUsing(10, 250, fn (): bool => (bool) $browser->script('return window.__wbPollWindowOver === true;')[0],
            'the poll interval never ran out');
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

    /**
     * Keep the page from polling — Livewire pauses a poll whose directive is off its
     * element — so the next click is the first request the server sees, against the page
     * as it was rendered.
     */
    protected function holdPoll(Browser $browser): void
    {
        $browser->script(<<<'JS'
            document.querySelectorAll('[wire\\:poll\\.5s]').forEach((el) => el.removeAttribute('wire:poll.5s'));
            window.__wbCommits = [];
        JS);

        $this->assertSame(0, $this->domCount($browser, self::POLL));
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

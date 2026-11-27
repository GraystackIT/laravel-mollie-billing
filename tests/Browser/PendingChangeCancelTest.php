<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * "Ausstehende Änderung abbrechen" — the customer withdrawing an upgrade that waits for its
 * prorata payment, in every state that payment and its webhook can be in when the click
 * arrives (`UpdateSubscription::tryCancelPendingPlanChange()` → PendingChangeCancelOutcome):
 *
 *  - still open at Mollie                → withdrawn, "cancelled" toast, nothing applied later;
 *  - already collected, webhook not yet  → refused, "already paid" warning, the notice stays;
 *  - webhook already applied the change  → nothing pending, "plan changed", no "cancelled";
 *  - webhook already declined it         → nothing pending, "failed", no "cancelled";
 *  - given up by the nightly clean-up    → "unfinished" toast and callout, never "plan changed".
 *
 * Every toast is captured as it is dispatched (Flux's `toast-show` event), not only read off
 * the screen: a toast lives five seconds, and "no plan-changed toast ever appeared" is a
 * question about everything the page announced, not about one moment.
 */
class PendingChangeCancelTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const CANCEL_PENDING = '[wire\\:click="cancelPendingChange"]';

    private const POLL = '[wire\\:poll\\.5s]';

    // ── 1. The payment has settled, its webhook has not arrived ─────────────

    public function test_a_cancel_after_the_payment_settled_is_refused_and_the_change_stays_pending(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $amountBefore = $this->mollieSubscriptionAmount($billable);
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        // Mollie has collected the money; the webhook is still on its way.
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->shot($browser, 'Upgrade wartet auf Zahlung');

            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.pending_change_already_paid'), 15);
            $this->shot($browser, 'Abbruch abgelehnt: Zahlung bereits eingegangen');

            // The notice — and its button — stand: the change is still on its way.
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING), 'the pending notice stays');
            $this->assertSame(1, $this->domCount($browser, self::POLL), 'the page keeps polling for the webhook');

            // At least one poll after the refusal, so a "plan changed" a re-render might
            // add would have been dispatched by now.
            $this->waitForPollAfterLastCall($browser);

            $toasts = $this->toastsSeen($browser);
            $this->assertContains(['text' => __('billing::portal.flash.pending_change_already_paid'), 'variant' => 'warning'], $toasts);
            $this->assertNotContains(__('billing::portal.flash.plan_changed'), array_column($toasts, 'text'));
            $this->assertNotContains(__('billing::portal.flash.pending_change_cancelled'), array_column($toasts, 'text'));
            $this->assertSame('Starter', $this->headerPlan($browser));
        });

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null, 'pending_plan_change survives the refused cancel');
        $this->assertSame($paymentId, $meta['pending_prorata_change']['charge_payment_id'] ?? null);
        $this->assertSame($paymentId, $meta['prorata_pending_payment_id'] ?? null);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta, 'a settled payment is not an orphan');
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame('paid', FakeMollie::payment($paymentId)['status'], 'the payment is left alone at Mollie');
        $this->assertSame(0, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count(), 'nothing refunded');
        $this->assertSame($amountBefore, $this->mollieSubscriptionAmount($billable), 'no PATCH before the webhook');
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'not booked before the webhook');
    }

    public function test_after_a_refused_cancel_the_arriving_webhook_applies_the_change_and_the_open_page_announces_it(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->clickWire($browser, self::CANCEL_PENDING);
            $browser->waitForText(__('billing::portal.flash.pending_change_already_paid'), 15);

            // The webhook arrives while the page is open; its poll picks the change up.
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $browser->waitForText(__('billing::portal.flash.plan_changed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Webhook angekommen: Plan geändert');

            $this->assertSame('Business', $this->headerPlan($browser));
            $this->assertSame(0, $this->domCount($browser, self::POLL), 'nothing left to poll for');
            $this->assertNotContains(__('billing::portal.flash.pending_change_cancelled'), array_column($this->toastsSeen($browser), 'text'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count(), 'the payment is booked once');
        // Business 99.00 + 19 % — the subscription now collects the new plan.
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
    }

    // ── 2. The payment is still open ────────────────────────────────────────

    public function test_a_cancel_while_the_payment_is_open_withdraws_the_change_and_nothing_announces_it_later(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $amountBefore = $this->mollieSubscriptionAmount($billable);
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $browser->assertSeeIn(self::CANCEL_PENDING, __('billing::portal.cancel_pending_change'));

            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.pending_change_cancelled'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->assertDontSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->shot($browser, 'Ausstehende Änderung abgebrochen');

            // Nothing waits any more, so the page stops polling…
            $this->assertSame(0, $this->domCount($browser, self::POLL), 'the poll goes with the pending change');

            // …and past one poll interval, plus the very request the poll makes, nothing
            // announces the withdrawn change as made.
            $this->waitPastOnePollInterval($browser);
            $this->refreshComponent($browser);

            $toasts = $this->toastsSeen($browser);
            $this->assertSame(
                [['text' => __('billing::portal.flash.pending_change_cancelled'), 'variant' => 'success']],
                $toasts,
                'exactly one toast: the cancel',
            );
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->shot($browser, 'Nach einem Poll-Intervall: kein Plan-geändert');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame($amountBefore, $this->mollieSubscriptionAmount($billable), 'Mollie still collects the old plan');
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());

        // The test Mollie reports no `isCancelable`, so the payment cannot be cancelled
        // there (PendingChangeCancelOutcome::Cleared): it is remembered, to be returned
        // should it settle after all.
        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame($paymentId, $meta['orphaned_prorata_payment']['payment_id'] ?? null);
        $this->assertSame('not_cancelable', $meta['orphaned_prorata_payment']['reason'] ?? null);
        $this->assertSame('business', $meta['orphaned_prorata_payment']['pending_plan_change']['plan_code'] ?? null);
    }

    public function test_a_cancel_after_the_payment_expired_unseen_withdraws_the_change_without_an_orphan(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        // Dead at Mollie, its webhook not yet delivered.
        FakeMollie::settle($paymentId, 'expired', 'DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.pending_change_cancelled'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Abgelaufene Zahlung: Änderung abgebrochen');

            $this->refreshComponent($browser);
            $texts = array_column($this->toastsSeen($browser), 'text');
            $this->assertNotContains(__('billing::portal.flash.plan_changed'), $texts);
            $this->assertNotContains(__('billing::portal.flash.plan_change_failed'), $texts);
        });

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('prorata_pending_payment_id', $meta);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta, 'nothing can arrive for an expired payment');
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());

        // The late webhook for it changes nothing.
        $this->deliverWebhook($paymentId);
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
    }

    // ── 3. The webhook got there first ──────────────────────────────────────

    public function test_a_cancel_after_the_webhook_applied_the_change_says_plan_changed_not_cancelled(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->assertSame('Starter', $this->headerPlan($browser));

            // The page must not get to poll before the click: the click has to be what
            // finds the change gone. Livewire pauses a poll whose directive is off the
            // element — the state of a page whose next tick is still seconds away.
            $this->holdPoll($browser);

            FakeMollie::settle($paymentId, 'paid', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paymentId));
            $this->assertSame('business', $billable->fresh()->getBillingSubscriptionPlanCode(), 'the webhook applied the change');

            // The page still shows the stale notice; the customer clicks it.
            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.plan_changed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Abbruch nach Webhook: Plan geändert');

            $this->assertSame([['cancelPendingChange']], $this->livewireCalls($browser), 'the click was the only request — no poll came first');
            $this->assertSame('Business', $this->headerPlan($browser));

            $toasts = $this->toastsSeen($browser);
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']],
                $toasts,
            );
            $browser->assertDontSee(__('billing::portal.flash.pending_change_cancelled'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode(), 'the plan stays switched');
        $this->assertArrayNotHasKey('pending_plan_change', $meta);
        $this->assertArrayNotHasKey('pending_prorata_change', $meta);
        $this->assertArrayNotHasKey('orphaned_prorata_payment', $meta, 'NothingPending touches nothing');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
        $this->assertSame(0, DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->count());
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
    }

    public function test_a_cancel_after_the_webhook_declined_the_payment_says_failed_not_cancelled(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $amountBefore = $this->mollieSubscriptionAmount($billable);
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->openPlanPageWithPendingChange($browser, $billable);
            $this->holdPoll($browser);

            FakeMollie::settle($paymentId, 'failed', 'DE');
            $this->deliverWebhook($paymentId);
            $this->assertNotNull($billable->fresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null, 'the decline is recorded');

            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText(__('billing::portal.flash.plan_change_failed'), 15);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->shot($browser, 'Abbruch nach abgelehnter Zahlung');

            $this->assertSame([['cancelPendingChange']], $this->livewireCalls($browser));
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_change_failed'), 'variant' => 'danger']],
                $this->toastsSeen($browser),
            );
            $this->assertSame('Starter', $this->headerPlan($browser));
        });

        $billable->refresh();

        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('pending_plan_change', $billable->getBillingSubscriptionMeta());
        $this->assertArrayNotHasKey('pending_prorata_change', $billable->getBillingSubscriptionMeta());
        $this->assertSame($amountBefore, $this->mollieSubscriptionAmount($billable));
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
    }

    // ── The clean-up gave the change up ─────────────────────────────────────

    public function test_a_change_paid_whose_webhook_never_came_is_carried_out_by_the_cleanup_and_announced_as_changed(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);

        // Collected, and its webhook never came: a day later the nightly clean-up delivers
        // it through the webhook's own path, and the change is carried out.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $meta = $billable->fresh()->getBillingSubscriptionMeta();
        $meta['pending_prorata_change']['created_at'] = now()->subHours(25)->toIso8601String();
        $billable->fresh()->forceFill(['subscription_meta' => $meta])->save();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            (new CleanupStalePendingProrataChangeJob)->handle();

            // The open page's poll finds the change done — and says so, once.
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->waitUsing(15, 200, fn (): bool => $this->headerPlan($browser) === 'Business',
                'the header never showed the new plan');
            $this->shot($browser, 'Aufräumjob holt den Webhook nach: Plan geändert');

            $toasts = $this->toastsSeen($browser);
            $this->assertSame([['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']], $toasts);
            $this->assertSame(0, $this->domCount($browser, '[dusk="plan-change-unfinished"]'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        foreach (['pending_plan_change', 'pending_prorata_change', 'prorata_pending_payment_id', 'plan_change_unfinished', 'orphaned_prorata_payment'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count());
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
    }

    // ── 4. German ───────────────────────────────────────────────────────────

    public function test_the_refused_cancel_reads_in_german_on_the_german_portal(): void
    {
        $this->serverConfig('app.locale', 'de');
        app()->setLocale('de');

        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $paymentId = $this->bookUpgradeAwaitingPayment($billable);
        FakeMollie::settle($paymentId, 'paid', 'DE');

        $alreadyPaid = 'Die Zahlung für diese Änderung ist bereits eingegangen – sie kann nicht mehr abgebrochen werden.';
        $this->assertSame($alreadyPaid, __('billing::portal.flash.pending_change_already_paid'));

        $this->browse(function (Browser $browser) use ($billable, $alreadyPaid): void {
            $this->openPlanPageWithPendingChange($browser, $billable);

            $browser->assertSeeIn(self::CANCEL_PENDING, 'Ausstehende Änderung abbrechen')
                ->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']));
            $this->assertSame('Starter', $this->headerPlan($browser, 'Aktueller Plan'));
            $this->shot($browser, 'Deutsch: Upgrade wartet auf Zahlung');

            $this->clickWire($browser, self::CANCEL_PENDING);

            $browser->waitForText($alreadyPaid, 15);
            $this->shot($browser, 'Deutsch: Abbruch abgelehnt');

            $this->assertSame(1, $this->domCount($browser, self::CANCEL_PENDING), 'the notice stays');
            $browser->assertSee(__('billing::portal.pending_plan_change_notice', ['plan' => 'Business']))
                ->assertDontSee('Die ausstehende Planänderung wurde abgebrochen.')
                ->assertDontSee('The payment for this change has already gone through');

            $this->waitForPollAfterLastCall($browser);
            $texts = array_column($this->toastsSeen($browser), 'text');
            $this->assertSame([$alreadyPaid], $texts);
            $this->assertNotContains('Plan geändert.', $texts);
        });

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        $this->assertSame('business', $meta['pending_plan_change']['plan_code'] ?? null);
        $this->assertSame($paymentId, $meta['prorata_pending_payment_id'] ?? null);
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Book the upgrade Starter → Business on the plan screen with the prorata charge left
     * open at Mollie, the way a slow payment method leaves it. Returns that payment's id.
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

        return $paymentId;
    }

    protected function pendingPaymentId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /**
     * Land on the plan screen with the change pending — a fresh visit, so the component
     * mounts knowing it is pending and its poll runs — and start recording.
     */
    protected function openPlanPageWithPendingChange(Browser $browser, TestBillable $billable): void
    {
        $this->actAs($browser, $billable, '/billing/plan');
        $browser->waitFor(self::CANCEL_PENDING, 15)->pause(400);

        $this->assertSame(1, $this->domCount($browser, self::POLL), 'a pending change is polled for');

        $this->recordPage($browser);
    }

    /**
     * Capture, from now on, every toast the page dispatches (Flux's `toast-show`, which
     * `Flux::toast()` sends) and the method calls of every Livewire request — a poll is a
     * request without one.
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

    /** @return list<list<string>> the method calls of each Livewire request since recording began */
    protected function livewireCalls(Browser $browser): array
    {
        return (array) $browser->script('return window.__wbCommits || [];')[0];
    }

    /**
     * Wait until the page has polled at least once after its last action. A poll is a
     * request whose only call is `$refresh` (Livewire 4 sends the poll's default action as
     * an ordinary call); an action is any request with another method in it.
     */
    protected function waitForPollAfterLastCall(Browser $browser): void
    {
        $browser->waitUsing(15, 200, function () use ($browser): bool {
            $commits = $this->livewireCalls($browser);
            $isPoll = static fn (array $calls): bool => array_diff($calls, ['$refresh']) === [];
            $lastAction = null;

            foreach ($commits as $i => $calls) {
                if (! $isPoll($calls)) {
                    $lastAction = $i;
                }
            }

            foreach ($commits as $i => $calls) {
                if ($isPoll($calls) && ($lastAction === null || $i > $lastAction)) {
                    return true;
                }
            }

            return false;
        }, 'the page did not poll after its last action');
    }

    /**
     * Let one poll interval (5s) run out on the page's own clock. Waited for as a
     * condition rather than slept, so it never waits longer than the interval.
     */
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
     * Keep the page from polling: Livewire pauses a poll whose directive is no longer on
     * its element. The poll is the page's own way of noticing a change; holding it makes
     * the next click the first request the server sees.
     */
    protected function holdPoll(Browser $browser): void
    {
        $browser->script(<<<'JS'
            document.querySelectorAll('[wire\\:poll\\.5s]').forEach((el) => el.removeAttribute('wire:poll.5s'));
        JS);

        $this->assertSame(0, $this->domCount($browser, self::POLL));
        $this->assertSame([], $this->livewireCalls($browser), 'no request has left the page yet');
    }

    /** The plan named in the page header's "current plan" card. */
    protected function headerPlan(Browser $browser, ?string $label = null): ?string
    {
        $label ??= __('billing::portal.current_plan');

        return $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const heading = sub ? sub.parentElement.querySelector('[data-flux-heading]') : null;
            return heading ? heading.textContent.trim() : null;
        JS, json_encode($label)))[0];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionReplacementJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Notifications\UndercollectionChargedNotification;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Renewals that do not go the straight way, seen from both sides of the counter — the
 * customer's portal and the operator's admin panel — and checked against the books and the
 * test Mollie at every step:
 *
 *  - a trial whose first charge failed, recovered by the next cycle's paid charge: that
 *    charge is the conversion (docs/decisions.md, "Renewal review round": a trial recovered
 *    from past due never converted);
 *  - a downgrade to the free plan while the anchor-pin replacement of the 28 February
 *    renewal waits between its cancel and its create (same round: the paid subscription
 *    came back);
 *  - the balance of an undercollected period whose create-payment answer was lost (same
 *    round: it was read as "refused");
 *  - a failed renewal recovered by a paid one in the admin's past-due list, with and without
 *    an overage debt standing beside it (`ChargeUsageOverageDirectly::statusAfterRecovery()`).
 */
class RenewalDeepTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const APPLY = '[x-on\\:click*="applyChange"]';

    // ── 11. A trial whose first charge failed, converted by the next paid charge ──

    public function test_a_trial_whose_first_charge_failed_is_converted_by_the_next_cycles_paid_charge(): void
    {
        $this->travelSharedClockTo('2027-03-01 10:00:00');
        // The admin tiles are cached in a store both processes share; this scenario reads one
        // tile twice within minutes and needs the figure of the moment both times.
        $this->serverConfig('mollie-billing.admin_kpi_cache_ttl', 0);

        $billable = $this->makeBillable([
            'name' => 'Trial Recovery GmbH',
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);

        // Testuser starts a 14-day Pro trial: a €0.00 authorisation captures the mandate.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout');
            $browser->waitForText('Pro', 15);
            $browser->click('[wire\\:click*="\'pro\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Softdrinks', 15);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);

            $browser->visit('/billing')->waitForText(__('billing::portal.trial_banner', ['date' => '15. Mar 2027']), 15);
            $this->shot($browser, 'Trial läuft bis 15. März');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $subscriptionId = (string) ($meta['mollie_subscription_id'] ?? '');
        $customerId = (string) $billable->getMollieCustomerId();

        $this->assertSame(SubscriptionStatus::Trial, $billable->getBillingSubscriptionStatus());
        $this->assertSame('2027-03-15', $billable->getBillingTrialEndsAt()?->utc()->toDateString());
        $this->assertArrayHasKey('trial_quota', $meta, 'the trial was granted a prorated share');
        $this->assertNotSame('', $subscriptionId);
        $this->assertSame('2027-03-15', substr((string) DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('next_payment_date'), 0, 10), 'Mollie charges first at the trial end');
        // The trial's share of Pro: ceil(100 × 14 / 30) Tokens, ceil(50 × 14 / 30) SMS.
        $this->assertSame(47, (int) $billable->getWallet('Tokens')->balanceInt);
        $this->assertSame(24, (int) $billable->getWallet('SMS')->balanceInt);
        $this->assertSame(0, BillingInvoice::query()->count(), 'a trial books no money');

        // The conversion mail belongs to the paid charge; captured here, where the webhooks run.
        Notification::fake();
        MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

        // ── The trial-end charge fails.
        $this->travelSharedClockTo('2027-03-15 10:30:00');
        $this->setRecurringOutcome('failed');
        $failed = FakeMollie::chargeSubscription($subscriptionId);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(200, $this->deliverWebhook($failed['id']));

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $this->assertNotNull($billable->getBillingTrialEndsAt(), 'not converted by a failed charge');
        $this->assertArrayHasKey('trial_quota', $billable->getBillingSubscriptionMeta());
        $this->assertArrayNotHasKey('trial_converted_at', $billable->getBillingSubscriptionMeta());
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(47, (int) $billable->getWallet('Tokens')->balanceInt, 'a failed charge credits no quota');
        Notification::assertNotSentTo($billable, TrialConvertedNotification::class);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText(__('billing::portal.past_due_banner'), 15);
            $this->assertSame(NextChargeSummary::STATE_PAST_DUE, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->shot($browser, 'Erste Abbuchung nach dem Trial fehlgeschlagen');

            Cache::flush();
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(600);
            $this->assertSame('0.0%', $this->opsTileValue($browser, __('billing::admin.dashboard.trial_conversion')), 'nothing converted yet');
            $this->shot($browser, 'Admin: Trial → Paid 0 %');
        });

        // ── A cycle later the recurring charge is paid.
        $this->travelSharedClockTo('2027-04-15 10:30:00');
        $this->setRecurringOutcome('paid');
        $paid = FakeMollie::chargeSubscription($subscriptionId);
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(200, $this->deliverWebhook($paid['id']));

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $invoice = BillingInvoice::query()->where('mollie_payment_id', $paid['id'])->sole();

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertNull($billable->getBillingTrialEndsAt(), 'the trial is over');
        $this->assertArrayNotHasKey('trial_quota', $meta, 'the trial grant no longer describes the period');
        $this->assertArrayNotHasKey('past_due_since', $meta);
        $this->assertStringStartsWith('2027-04-15', (string) ($meta['trial_converted_at'] ?? ''));
        $this->assertSame(3451, (int) $invoice->amount_gross, 'Pro 29.00 + 19 %');
        $this->assertSame(1, BillingInvoice::query()->count(), 'one invoice: the paid charge');
        $this->assertSame(1, DB::table('fake_mollie_payments')->where('customer_id', $customerId)->where('sequence_type', 'recurring')->where('status', 'paid')->count(), 'one charge collected');
        // Tokens roll over (the trial's 47 unused + the period's 100), SMS is reset to 50.
        $this->assertSame(147, (int) $billable->getWallet('Tokens')->balanceInt);
        $this->assertSame(50, (int) $billable->getWallet('SMS')->balanceInt);
        Notification::assertSentToTimes($billable, TrialConvertedNotification::class, 1);

        // Delivered again: nothing twice.
        $this->assertSame(200, $this->deliverWebhook($paid['id']));
        $this->assertSame(1, BillingInvoice::query()->count());
        Notification::assertSentToTimes($billable, TrialConvertedNotification::class, 1);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);

            $text = $this->opsPageText($browser);
            $this->assertSame(SubscriptionStatus::Active->label(), $this->statusBadgeOnScreen($browser), 'the badge says active');
            $this->assertStringNotContainsString(__('billing::portal.trial_ends'), $text);
            $this->assertStringNotContainsString(__('billing::portal.past_due_banner'), $text);
            $this->assertStringNotContainsString(__('billing::portal.end_trial'), $text);
            // No "trial ends" date anywhere — not in a banner, not as the next billing date.
            $this->assertStringNotContainsString('15. Mar 2027', $text);
            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->assertSame('15. May 2027', trim((string) $browser->text('[data-next-charge-date]')));
            $this->shot($browser, 'Nach bezahlter Abbuchung: aktiv, kein Trial mehr');

            // The meters measure against the full period now, not the trial's share.
            $browser->visit('/billing/usage')->waitFor('[dusk="usage-meter-Tokens"]', 15)->pause(400);
            $this->assertStringContainsString(
                __('billing::portal.usage_plan_remaining', ['remaining' => '100', 'total' => '100']),
                $browser->text('[dusk="usage-meter-Tokens"]'),
            );
            $this->assertStringContainsString(
                __('billing::portal.usage_carried_over', ['count' => '47']),
                $browser->text('[dusk="usage-meter-Tokens-carried-over"]'),
            );
            $this->shot($browser, 'Verbrauch nach der Umwandlung');

            Cache::flush();
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(600);
            $this->assertSame('100.0%', $this->opsTileValue($browser, __('billing::admin.dashboard.trial_conversion')), 'the conversion is counted');
            $this->shot($browser, 'Admin: Trial → Paid 100 %');
        });

        // ── A plan change rebalances against the period's full quota.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'business\')"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'business\')"]');
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $browser->assertSeeIn(self::APPLY, __('billing::portal.apply_immediately'));
            $this->shot($browser, 'Upgrade auf Business nach der Umwandlung');
            $this->clickWire($browser, self::APPLY);

            $browser->waitUsing(15, 200, fn (): bool => (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '') !== '',
                'the upgrade never created its charge');
        });

        $chargeId = (string) $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
        $this->assertSame(200, $this->deliverWebhook($chargeId));
        $billable->refresh();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        // Rollover: what Pro's 100 left (47) on top of Business's 1000. Measured against the
        // trial's 47, the whole Pro quota (100) would have been carried over: 1100.
        $this->assertSame(1047, (int) $billable->getWallet('Tokens')->balanceInt);
        $this->assertSame(500, (int) $billable->getWallet('SMS')->balanceInt);
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $chargeId)->count());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-Tokens"]', 15)->pause(400);
            $this->assertStringContainsString(
                __('billing::portal.usage_plan_remaining', ['remaining' => '1,000', 'total' => '1,000']),
                $browser->text('[dusk="usage-meter-Tokens"]'),
            );
            $this->assertStringContainsString(
                __('billing::portal.usage_carried_over', ['count' => '47']),
                $browser->text('[dusk="usage-meter-Tokens-carried-over"]'),
            );
            $this->shot($browser, 'Business: 1000 plus 47 übertragen');
        });
    }

    // ── 12. Down to the free plan while the anchor replacement waits ─────────

    public function test_a_free_downgrade_while_the_anchor_replacement_waits_leaves_no_paid_subscription_behind(): void
    {
        $this->travelSharedClockTo('2026-12-31 10:00:00');
        $this->serverConfig('mollie-billing-plans.plans.changes-free.hidden', false);
        config(['mollie-billing-plans.plans.changes-free.hidden' => false]);

        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'name' => 'Month End GmbH',
            'subscription_period_starts_at' => now(),
        ]);
        $customerId = (string) $billable->getMollieCustomerId();
        $oldId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // What CreateSubscription leaves at Mollie for a signup on the 31st.
        DB::table('fake_mollie_subscriptions')->where('id', $oldId)->update([
            'amount_value' => '34.51', 'start_date' => '2027-01-31', 'next_payment_date' => '2027-01-31',
        ]);

        $this->travelSharedClockTo('2027-01-31 09:05:00');
        $this->assertSame(200, $this->deliverWebhook(FakeMollie::chargeSubscription($oldId)['id']));

        // 28 February: Mollie would count on to 28 March; the package pins the 31st, which a
        // charged subscription only takes by being replaced — and the create half is down.
        $this->travelSharedClockTo('2027-02-28 09:05:00');
        WorkbenchSetting::set('fail_create_subscription', '1');
        $february = FakeMollie::chargeSubscription($oldId);
        $this->assertSame(200, $this->deliverWebhook($february['id']));

        $marker = $billable->fresh()->getBillingSubscriptionMeta()[MollieSubscriptionReplacer::META_KEY] ?? null;
        $this->assertIsArray($marker, 'the replacement waits');
        $this->assertTrue((bool) ($marker['old_cancelled'] ?? false), 'between its cancel and its create');
        $this->assertNotNull(DB::table('fake_mollie_subscriptions')->where('id', $oldId)->value('canceled_at'));
        $this->assertSame(0, $this->activeFakeSubscriptions($customerId));
        $februaryInvoice = BillingInvoice::query()->where('mollie_payment_id', $february['id'])->sole();

        // The customer moves to the free plan on the plan screen.
        $shownRefund = 0;
        $this->browse(function (Browser $browser) use ($billable, &$shownRefund): void {
            $target = '[wire\\:click="previewFor(\'changes-free\')"]';
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor($target, 15)->pause(400);
            $this->clickWire($browser, $target);
            $browser->waitFor(self::APPLY, 15)->pause(600);
            $browser->assertSeeIn(self::APPLY, __('billing::portal.apply_immediately'));

            $shownRefund = $this->domCount($browser, '[dusk="prorata-refund-separate"]') > 0
                ? (int) $browser->attribute('[dusk="prorata-refund-separate"]', 'data-refund-minor')
                : 0;
            $this->shot($browser, 'Wechsel auf Free, während der Ersatz wartet');

            $this->clickWire($browser, self::APPLY);
            $browser->waitForText(__('billing::portal.flash.plan_changed'), 20);
            $this->shot($browser, 'Free-Plan aktiv');
            $browser->assertDontSee(__('billing::portal.flash.error'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertArrayNotHasKey(MollieSubscriptionReplacer::META_KEY, $meta, 'the free downgrade drops the waiting replacement');
        $this->assertNull($meta['mollie_subscription_id'] ?? null);

        // What the screen said comes back is what came back, once — at Mollie and in the books.
        $refunded = $this->refundedAtFakeMollie($february['id']);
        $notes = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->get();
        $this->assertGreaterThan(0, $shownRefund, 'the unused share of February is returned');
        $this->assertSame($shownRefund, $refunded);
        $this->assertSame($shownRefund, (int) abs((int) $notes->sum('amount_gross')));
        $this->assertSame(
            [(int) $februaryInvoice->id],
            array_values(array_unique(array_map('intval', array_filter(array_column((array) $notes->sole()->line_items, 'parent_invoice_id'))))),
            'the credit note is against the February renewal',
        );

        // Mollie answers again, and the hourly sweep comes round.
        WorkbenchSetting::forget('fail_create_subscription');
        $this->travelSharedClockTo('2027-02-28 11:10:00');
        (new RetrySubscriptionReplacementJob(TestBillable::class, $billable->getKey()))->handle(app(MollieSubscriptionReplacer::class));
        app()->call([new SweepPendingRetriesJob, 'handle']);

        $this->assertSame(0, $this->activeFakeSubscriptions($customerId), 'nothing charges a customer on the free plan');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->count(), 'no paid subscription was created again');
        $this->assertArrayNotHasKey(MollieSubscriptionReplacer::META_KEY, $billable->fresh()->getBillingSubscriptionMeta());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText(__('billing::portal.free_plan_recurring_charge'), 15)->pause(400);

            $this->assertSame('Free', $this->currentPlanOnScreen($browser));
            $this->assertSame(NextChargeSummary::STATE_FREE, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->assertSame(0, $this->domCount($browser, '[data-next-charge-date]'), 'no next charge');
            $this->assertStringNotContainsString('31. Mar 2027', $this->opsPageText($browser));
            $this->shot($browser, 'Dashboard: Free, keine nächste Abbuchung');

            // The way up again: the plan screen's upgrade reaches Mollie's payment page.
            $browser->visit('/billing/plan')->waitFor('[wire\\:click="previewFor(\'pro\')"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'pro\')"]');
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $this->clickWire($browser, self::APPLY);
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);
            $this->shot($browser, 'Upgrade von Free bestätigen');
            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
            $this->shot($browser, 'Upgrade bezahlt');
        });

        $billable->refresh();
        $active = DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->whereNull('canceled_at')->where('status', 'active')->get();

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
        $this->assertCount(1, $active, 'exactly one subscription charges the customer');
        $this->assertSame((string) $active->sole()->id, (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id']);
    }

    // ── 13. A balance payment whose create answer was lost ───────────────────

    public function test_a_balance_payment_whose_create_answer_was_lost_is_taken_over_and_shown_paid(): void
    {
        // Pro with four seats (one beyond the three included): 38.90 + 19 % = 46.29.
        $billable = $this->opsSubscriber('pro', 4);
        $billable->forceFill(['name' => 'Balance GmbH', 'subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['used_seats' => 1])])->save();
        app(PlanWaiver::class)->syncMollie($billable->fresh());
        $subscriptionId = (string) $billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'];
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));

        // Mollie prepares the next debit for four seats …
        $prepared = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first(['amount_value', 'metadata']);
        $this->assertSame('46.29', (string) $prepared->amount_value);

        // … then a fifth is added on the seats page and paid for the rest of the period.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->shot($browser, 'Fünfter Sitz gewählt');
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $browser->waitUsing(15, 200, fn (): bool => (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '') !== ''
                || $billable->fresh()->getBillingSeatCount() === 5, 'the seat was never booked');
        });

        $seatCharge = (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
        if ($seatCharge !== '') {
            $this->assertSame(200, $this->deliverWebhook($seatCharge));
        }
        $this->assertSame(5, $billable->fresh()->getBillingSeatCount());

        // The debit Mollie had prepared before is what gets collected — and the answer to the
        // balance payment that follows is lost on its way back.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['metadata' => $prepared->metadata]);
        $before = DB::table('fake_mollie_payments')->pluck('id')->all();

        Notification::fake();
        MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);
        MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
        WorkbenchSetting::set('lose_create_payment_answer', UndercollectionCharge::METADATA_KEY);

        $renewal = $this->opsRenew($billable, (string) $prepared->amount_value);
        WorkbenchSetting::forget('lose_create_payment_answer');
        $billable->refresh();

        $balances = DB::table('fake_mollie_payments')->whereNotIn('id', $before)->where('id', '!=', $renewal->mollie_payment_id)->get();
        $this->assertCount(1, $balances, 'one balance payment at Mollie');
        $balance = $balances->sole();
        $this->assertSame('1', WorkbenchSetting::get('lost_create_payment_answers'), 'the answer to the balance payment was lost');
        // One seat 9.90 + 19 %.
        $this->assertSame('11.78', (string) $balance->amount_value);

        $case = UndercollectionCharge::cases($billable)[0] ?? null;
        $this->assertNotNull($case);
        $this->assertSame(UndercollectionCharge::STATUS_PENDING, $case['status'], 'taken over, not "not possible"');
        $this->assertSame((string) $balance->id, $case['payment_id']);
        Notification::assertSentTo($billable, UndercollectionChargedNotification::class);
        Notification::assertNothingSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
        Notification::assertSentTimes(AdminPlanChangeFailedNotification::class, 0);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="balance-charge-pending"]', 15)
                ->assertSeeIn('[dusk="balance-charge-pending"]', __('billing::portal.balance_charge.pending', ['amount' => BillingMoney::format(1178)]))
                ->assertSeeIn('[dusk="balance-charge-pending"]', __('billing::notifications.undercollection_charged.reasons.renewal_created_before_change'));
            $this->shot($browser, 'Dashboard: Nachbelastung unterwegs');

            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'subscription');
            $browser->waitFor('[dusk="balance-charge-row-pending"]', 15)
                ->assertSeeIn('[dusk="balance-charge-row-pending"]', __('billing::admin.balance_charges.status.pending'))
                ->assertSeeIn('[dusk="balance-charge-row-pending"]', BillingMoney::format(1178));
            $this->assertSame(0, $this->domCount($browser, '[dusk="balance-charge-row-not_possible"]'));
            $this->shot($browser, 'Admin: Nachbelastung unterwegs');
        });

        // Mollie collects it; the webhook books it — once.
        $this->assertSame('paid', FakeMollie::payment((string) $balance->id)['status']);
        $this->assertSame(200, $this->deliverWebhook((string) $balance->id));
        $this->assertSame(200, $this->deliverWebhook((string) $balance->id));

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $balance->id)->sole();
        $case = UndercollectionCharge::cases($billable->fresh())[0];

        $this->assertSame(1178, (int) $invoice->amount_gross);
        $this->assertNotNull($invoice->period_start, 'booked for the period it completes, not from fallback lines');
        $this->assertSame($renewal->period_start?->toDateString(), $invoice->period_start?->toDateString());
        // The case's own line (the fifth seat), not the fallback "balance for a billing
        // period" a payment without a recorded case is booked with.
        $this->assertSame(['seats'], array_column((array) $invoice->line_items, 'kind'));
        $this->assertSame(990, (int) ($invoice->line_items[0]['amount_net'] ?? 0));
        $this->assertSame(UndercollectionCharge::STATUS_PAID, $case['status']);
        $this->assertSame(1, DB::table('fake_mollie_payments')->whereNotIn('id', $before)->where('id', '!=', $renewal->mollie_payment_id)->count(), 'never charged twice');
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count(), 'nothing returned');

        $this->browse(function (Browser $browser) use ($billable, $invoice, $renewal): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-'.$invoice->id.'-balance"]', 15)
                ->assertSeeIn('[dusk="invoice-'.$invoice->id.'-balance"]', __('billing::portal.balance_charge.invoice_label'))
                ->assertSeeIn('[dusk="invoice-'.$invoice->id.'-balance"]', (string) $renewal->serial_number);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', 1178);
            $this->shot($browser, 'Rechnungsliste: Nachbelastung');

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(800);
            $this->assertSame(0, $this->domCount($browser, '[dusk^="balance-charge-"]'), 'nothing left to announce');

            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'subscription');
            $browser->waitFor('[dusk="balance-charge-row-paid"]', 15)
                ->assertSeeIn('[dusk="balance-charge-row-paid"]', __('billing::admin.balance_charges.status.paid'))
                ->assertSeeIn('[dusk="balance-charge-row-paid"]', BillingMoney::format(1178));
            $this->assertSame(0, $this->domCount($browser, '[dusk="balance-charge-row-not_possible"]'));
            $this->assertSame(0, $this->domCount($browser, '[dusk="balance-charge-row-pending"]'));
            $this->shot($browser, 'Admin: Nachbelastung bezahlt');
        });
    }

    // ── 14. A failed renewal recovered by a paid one, seen in the past-due list ──

    public function test_a_failed_renewal_recovered_by_a_paid_one_leaves_the_admin_past_due_list(): void
    {
        $billable = $this->opsSubscriber('starter');
        $billable->forceFill(['name' => 'Recovering GmbH'])->save();
        $this->makeBillable(['name' => 'Still Late GmbH', 'subscription_status' => SubscriptionStatus::PastDue]);

        Notification::fake();
        MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

        $this->setRecurringOutcome('failed');
        $failed = $this->renewSubscription($billable->fresh());
        $billable->refresh();

        $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $this->assertArrayHasKey('past_due_since', $billable->getBillingSubscriptionMeta());
        $this->assertSame(0, BillingInvoice::query()->where('mollie_payment_id', $failed)->count());
        Notification::assertSentTo($billable, SubscriptionPaymentFailedNotification::class);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText(__('billing::portal.past_due_banner'), 15);
            $this->assertSame(NextChargeSummary::STATE_PAST_DUE, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->shot($browser, 'Kunde: Erneuerung fehlgeschlagen');

            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/past-due')->waitForText('Recovering GmbH', 15)->pause(400);
            $browser->assertSee('Still Late GmbH');
            $this->shot($browser, 'Admin: in der Past-Due-Liste');

            // The next renewal is paid while the operator looks at the list.
            $this->setRecurringOutcome('paid');
            $this->renewSubscription($billable->fresh());

            $browser->refresh()->waitForText('Still Late GmbH', 15)->pause(400);
            $browser->assertDontSee('Recovering GmbH');
            $this->shot($browser, 'Admin: aus der Liste verschwunden');

            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Starter', 15)->pause(500);
            $text = $this->opsPageText($browser);
            $this->assertStringNotContainsString(__('billing::portal.past_due_banner'), $text);
            $this->assertSame(0, $this->domCount($browser, '[dusk="overage-debt-banner"]'));
            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->shot($browser, 'Kunde: wieder aktiv');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertArrayNotHasKey('past_due_since', $meta);
        $this->assertArrayNotHasKey('payment_failure', $meta);
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count(), 'only the paid renewal is booked');
        $this->assertSame(1178, (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->sole()->amount_gross);
        $this->assertSame(
            ['failed' => 1, 'paid' => 1],
            DB::table('fake_mollie_payments')->where('customer_id', $billable->getMollieCustomerId())->get()->countBy('status')->sortKeys()->all(),
        );
    }

    public function test_a_paid_renewal_keeps_the_debt_only_past_due_while_an_overage_debt_stands(): void
    {
        $this->setRecurringOutcome('failed');

        $billable = $this->opsSubscriber('starter');
        $billable->forceFill(['name' => 'Debt Standing GmbH'])->save();

        // An overage debt whose cycle gave up: 25 SMS over Starter's 20 at 18 ct = 4.50 net,
        // 5.36 gross. Three payments, three refusals.
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 45);
        app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->deliverWebhook($this->latestFakePayment()['id']);
        }

        // … and the renewal fails as well.
        $this->renewSubscription($billable->fresh());
        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $this->assertSame(ChargeUsageOverageDirectly::STATUS_FAILED, $meta['usage_overage_status'] ?? null);
        $this->assertArrayHasKey(ChargeUsageOverageDirectly::PAST_DUE_KEY, $meta);
        $this->assertArrayHasKey('past_due_since', $meta, 'the renewal is unpaid too');
        $this->assertFalse(ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="overage-debt-banner"]', 15)->pause(400);
            $this->assertStringContainsString(
                $this->opsNormaliseMoney(BillingMoney::format(536)),
                $this->opsNormaliseMoney($browser->text('[dusk="overage-debt-banner"]')),
            );
            $this->shot($browser, 'Kunde: Erneuerung und Nutzung offen');

            // With a renewal unpaid the application is closed.
            $browser->visit('/_wb/app')->pause(800);
            $browser->assertDontSee('APP-AREA-OK');

            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/past-due')->waitForText('Debt Standing GmbH', 15)->pause(400);
            $this->shot($browser, 'Admin: in der Past-Due-Liste');
        });

        $this->setRecurringOutcome('paid');
        $paid = $this->renewSubscription($billable->fresh());
        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        // The renewal's own dunning is over; the debt's lock stands (statusAfterRecovery).
        $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $this->assertArrayNotHasKey('past_due_since', $meta);
        $this->assertArrayNotHasKey('payment_failure', $meta);
        $this->assertArrayHasKey(ChargeUsageOverageDirectly::PAST_DUE_KEY, $meta);
        $this->assertTrue(ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable));
        $this->assertSame(1178, (int) BillingInvoice::query()->where('mollie_payment_id', $paid)->sole()->amount_gross);
        $this->assertSame(0, BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Overage)->count(), 'the debt is not paid');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="overage-debt-banner"]', 15)->pause(400);
            $text = $this->opsPageText($browser);

            $this->assertStringContainsString(
                $this->opsNormaliseMoney(__('billing::portal.overage_debt_banner', ['amount' => BillingMoney::format(536)])),
                $this->opsNormaliseMoney($text),
            );
            $this->assertStringNotContainsString(__('billing::portal.past_due_banner'), $text);
            // The period is paid: nothing to bring forward.
            $this->assertStringNotContainsString(__('billing::portal.force_charge_now'), $text);
            $this->assertNotSame(NextChargeSummary::STATE_PAST_DUE, $browser->attribute('[data-next-charge]', 'data-next-charge'));
            $this->shot($browser, 'Kunde: nur noch die Nutzungsschuld');

            // Only the debt is owed: the application is open again.
            $browser->visit('/_wb/app')->waitForText('APP-AREA-OK', 15);

            // Past due for the debt is still past due for the operator.
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/past-due')->waitForText('Debt Standing GmbH', 15)->pause(400);
            $this->shot($browser, 'Admin: bleibt wegen der Schuld gelistet');
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        foreach (['recurring_outcome', 'fail_create_subscription', 'lose_create_payment_answer', 'lost_create_payment_answers'] as $key) {
            WorkbenchSetting::forget($key);
        }

        MollieBilling::notifyBillingAdminsUsing(fn (): array => []);
        MollieBilling::notifyAdminUsing(fn (): array => []);

        parent::tearDown();
    }

    private function activeFakeSubscriptions(string $customerId): int
    {
        return DB::table('fake_mollie_subscriptions')
            ->where('customer_id', $customerId)
            ->whereNull('canceled_at')
            ->where('status', 'active')
            ->count();
    }

    private function refundedAtFakeMollie(string $paymentId): int
    {
        $sum = 0;
        foreach (DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->get() as $refund) {
            $sum += BillingMoney::minorFromMollieAmount((string) $refund->amount_value, 'EUR');
        }

        return $sum;
    }

    /** The status badge beside the plan in the dashboard's "current plan" card. */
    private function statusBadgeOnScreen(Browser $browser): ?string
    {
        return $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const badge = sub ? sub.parentElement.querySelector('[data-flux-badge]') : null;
            return badge ? badge.textContent.trim() : null;
        JS, json_encode(__('billing::portal.current_plan'))))[0];
    }

    /** The plan named in the dashboard's "current plan" card. */
    private function currentPlanOnScreen(Browser $browser): ?string
    {
        return $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const heading = sub ? sub.parentElement.querySelector('[data-flux-heading]') : null;
            return heading ? heading.textContent.trim() : null;
        JS, json_encode(__('billing::portal.current_plan'))))[0];
    }
}

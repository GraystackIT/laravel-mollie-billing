<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The end of a subscription and the way back, off the straight path — portal and admin
 * panel side by side, checked against the books and the test Mollie at every step:
 *
 *  - a yearly trial whose first charge failed, cancelled "at period end" by an operator: there
 *    is no paid period to run out, so it ends now (docs/decisions.md, "A cancel at period end
 *    keeps only the access there is");
 *  - a free plan cancelled on the 20th, resumed after the boundary its grace crossed, then
 *    renewed by the nightly pass (`ResubscribeSubscription` turns the lapsed period over);
 *  - an unlimited free plan cancelled in two tabs at once ("A second cancel at period end
 *    changes nothing") and resumed without an end date;
 *  - a cancel Mollie did not acknowledge (503), and the resume that must not create a second
 *    subscription beside the one still collecting ("A resume leaves one subscription at
 *    Mollie");
 *  - the admin tab of a cancelled row, and its Resubscribe clicked twice, or by two
 *    operators at once.
 *
 * Mail is read from the workbench mailbox: `admin_mailbox` gives the server an operator,
 * `billing_admins_mailbox` makes the billable's own address its billing admin.
 */
class LifecycleDeepTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const CANCEL = '[wire\\:click="cancelSubscription"]';

    private const RESUBSCRIBE = '[wire\\:click="resubscribe"]';

    private const ADMIN_CANCEL_AT_END = '[wire\\:click="openCancel(false)"]';

    private const ADMIN_CANCEL_NOW = '[wire\\:click="openCancel(true)"]';

    private const ADMIN_CANCEL_SUBMIT = 'form[wire\\:submit="confirmCancel"] button[type="submit"]';

    private const ADDRESS = [
        'billing_street' => 'Musterweg 12',
        'billing_postal_code' => '10115',
        'billing_city' => 'Berlin',
        'billing_country' => 'DE',
        'tax_country_user' => 'DE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        WorkbenchSetting::set('mailbox', '1');
        WorkbenchSetting::set('billing_admins_mailbox', '1');
        WorkbenchSetting::set('admin_mailbox', 'operator@workbench.test');
    }

    protected function tearDown(): void
    {
        foreach (['recurring_outcome', 'fail_cancel_subscription', 'hold_mollie_request', 'mollie_request_held'] as $key) {
            WorkbenchSetting::forget($key);
        }

        parent::tearDown();
    }

    // ── 30. A past-due trial cancelled "at period end" by an operator ────────

    public function test_an_operator_cancel_at_period_end_of_a_trial_whose_first_charge_failed_ends_it_now(): void
    {
        $this->travelSharedClockTo('2027-03-01 10:00:00');
        $billable = $this->makeBillable(['name' => 'Failed Trial GmbH'] + self::ADDRESS);

        // A 14-day Pro trial, yearly, with the Softdrinks addon: a €0.00 authorisation.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout?plan=pro&interval=yearly');
            $browser->waitFor('[wire\\:click="next"]', 15)->pause(500);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Softdrinks', 15)->pause(300);
            $this->jsClick($browser, '[value="softdrinks"]');
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 15)->pause(400);
            $this->shot($browser, 'Pro jährlich mit Softdrinks, Trial');
            $this->clickWire($browser, '[wire\\:click="submit"]');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);

            $browser->visit('/billing')->waitForText(__('billing::portal.trial_banner', ['date' => '15. Mar 2027']), 15);
            $browser->visit('/_wb/feature/softdrinks')->waitForText('FEATURE-SOFTDRINKS-OK', 10);
        });

        $billable->refresh();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $customerId = (string) $billable->getMollieCustomerId();

        $this->assertSame(SubscriptionStatus::Trial, $billable->getBillingSubscriptionStatus());
        $this->assertSame('yearly', $billable->getBillingSubscriptionInterval());
        $this->assertSame(['softdrinks'], $billable->getActiveBillingAddonCodes());
        $this->assertNotSame('', $subscriptionId);

        // ── The trial-end charge fails.
        $this->travelSharedClockTo('2027-03-15 10:30:00');
        $this->setRecurringOutcome('failed');
        $failed = FakeMollie::chargeSubscription($subscriptionId);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(200, $this->deliverWebhook($failed['id']));
        $this->assertSame(SubscriptionStatus::PastDue, $billable->fresh()->getBillingSubscriptionStatus());

        $paymentsBefore = DB::table('fake_mollie_payments')->count();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="past-due-banner"]', 15);
            $this->shot($browser, 'Erste Abbuchung nach dem Trial fehlgeschlagen');

            // ── The operator cancels "at period end", with a note.
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            $this->assertSame(1, $this->domCount($browser, self::ADMIN_CANCEL_AT_END), 'offered for past due');
            $this->clickWire($browser, self::ADMIN_CANCEL_AT_END);
            $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(400)
                ->assertSee(__('billing::admin.billables.subscription.cancel_at_period_end_confirm'))
                ->type('cancelNote', 'Deep: first charge after the trial failed, customer asked to stop');
            $this->clickWire($browser, self::ADMIN_CANCEL_SUBMIT);

            // Read back from what was written: there was nothing to run out, so it ended now.
            $browser->waitForText(__('billing::admin.billables.subscription.flash.cancelled_now'), 15)->pause(500);
            $browser->assertDontSee(__('billing::admin.billables.subscription.flash.cancelled_grace'));
            $this->shot($browser, 'Admin: Kündigung zum Periodenende endet sofort');

            $endsShown = Carbon::parse((string) $browser->attribute('[dusk="sub-ends"] time', 'datetime'));
            $this->assertSame('2027-03-15', $endsShown->utc()->toDateString(), 'the tab shows an end of today, not next year');
            $this->assertTrue($endsShown->lessThanOrEqualTo(now()), 'the end is not ahead');
            $browser->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Cancelled->label());

            // Nothing left to resume, nothing left to cancel.
            $this->assertSame(0, $this->domCount($browser, self::RESUBSCRIBE), 'no resubscribe for a subscription that has ended');
            $this->assertSame(0, $this->domCount($browser, self::ADMIN_CANCEL_AT_END));
            $this->assertSame(0, $this->domCount($browser, self::ADMIN_CANCEL_NOW));

            // Reloaded from scratch, not just the component's state after the action.
            $this->openSubscriptionTab($browser, $billable);
            $this->assertSame('2027-03-15', Carbon::parse((string) $browser->attribute('[dusk="sub-ends"] time', 'datetime'))->utc()->toDateString());

            // ── The customer: no plan access, nothing to resume.
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="subscription-status"]', 15)->pause(400);
            $browser->assertSeeIn('[dusk="subscription-status"]', SubscriptionStatus::Cancelled->label());
            $this->assertSame(0, $this->domCount($browser, self::RESUBSCRIBE), 'no Resubscribe on the dashboard');
            $this->assertSame(0, $this->domCount($browser, '[dusk="cancelled-banner"]'), 'no "will end on" — it has ended');
            $this->assertSame(0, $this->domCount($browser, '[dusk="past-due-banner"]'));
            // What is charged next: nothing — it ended today, not in a year. (The "Next
            // billing" tile beside it still reads the yearly period end, 01. Mar 2028: an open
            // decision, see the report — not asserted here.)
            $shown = $this->nextChargeOnScreen($browser);
            $this->assertSame(NextChargeSummary::STATE_ENDING, $shown['state']);
            $this->assertStringContainsString(__('billing::portal.next_charge.ending_on', ['date' => '15. Mar 2027']), $shown['text']);
            $this->shot($browser, 'Kunde: gekündigt, kein Zugriff');

            // The subscription gate (`billing.active`) sends the customer to the checkout.
            $browser->visit('/_wb/app');
            $this->waitForPathContains($browser, '/billing/checkout');
            $browser->assertDontSee('APP-AREA-OK');
            $this->shot($browser, 'Abo-Gate: zum Checkout');
        });

        $ended = $billable->fresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $ended->getBillingSubscriptionStatus());
        $this->assertSame('2027-03-15', $ended->getBillingSubscriptionEndsAt()?->utc()->toDateString(), 'today, not a year ahead');
        $this->assertFalse($ended->getBillingSubscriptionEndsAt()->isFuture());
        $this->assertFalse($ended->hasAccessibleBillingSubscription());

        // The feature gate (`billing.feature`) is a catalog question and never looks at the
        // status (docs/decisions.md, "Access stays for a customer who owes only overage"):
        // the ended row loses its features when the nightly pass expires it and clears the
        // plan and its addons. That pass runs the same night.
        $this->travelSharedClockTo('2027-03-16 02:00:00');
        PrepareUsageOverageJob::dispatchSync();
        $this->assertSame(SubscriptionStatus::Expired, $billable->fresh()->getBillingSubscriptionStatus());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/_wb/feature/softdrinks');
            $this->waitForPathContains($browser, '/billing');
            $browser->waitForText(__('billing::portal.upgrade_required'), 10)
                ->assertDontSee('FEATURE-SOFTDRINKS-OK');
            $this->assertSame(0, $this->domCount($browser, self::RESUBSCRIBE), 'still nothing to resume');
            $this->shot($browser, 'Feature-Gate nach dem Ablauf: kein Softdrinks');

            $browser->visit('/_wb/app');
            $this->waitForPathContains($browser, '/billing/checkout');
            $browser->assertDontSee('APP-AREA-OK');
        });

        $billable->refresh();

        $this->assertSame(SubscriptionStatus::Expired, $billable->getBillingSubscriptionStatus(), 'ended on 15 March, expired by the pass');
        $this->assertNull($billable->getBillingSubscriptionPlanCode());
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertArrayNotHasKey('pending_subscription_cancel', $billable->getBillingSubscriptionMeta());
        $this->assertNotNull(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('canceled_at'), 'cancelled at Mollie');
        $this->assertSame(0, $this->activeFakeSubscriptions($customerId));
        $this->assertSame($paymentsBefore, DB::table('fake_mollie_payments')->count(), 'the cancel charged nothing');
        $this->assertSame(0, BillingInvoice::query()->count(), 'nothing was ever paid, nothing booked');
        $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class), 'the customer is told once');
        $this->assertCount(0, $this->mailbox(AdminPlanChangeFailedNotification::class));
    }

    // ── 31. A free plan cancelled, resumed across its boundary ───────────────

    public function test_a_free_plan_cancelled_on_the_20th_and_resumed_after_the_boundary_renews_and_takes_changes(): void
    {
        $this->travelSharedClockTo('2027-03-01 09:00:00');
        $this->serverConfig('mollie-billing-plans.plans.changes-free.hidden', false);
        $this->serverConfig('mollie-billing-plans.plans.coupon-free.hidden', false);

        $billable = $this->makeBillable(['name' => 'Free Boundary GmbH'] + self::ADDRESS);

        // ── 1 March: the free plan, through the checkout. Nothing to pay.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/checkout?plan=changes-free&interval=monthly');
            $browser->waitFor('[wire\\:click="next"]', 15)->pause(500);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->pause(700);

            if ($this->domCount($browser, '[wire\\:click="submit"]') === 0) {
                $this->clickWire($browser, '[wire\\:click="next"]');
            }

            $browser->waitFor('[wire\\:click="submit"]', 15)->pause(500);
            $this->assertShownAmount($browser, 'confirm-total', 0, 'the free plan costs nothing');
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->waitForPathContains($browser, '/billing', 20);
            $browser->pause(800);
        });

        $billable->refresh();
        $this->assertSame('changes-free', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertNull($billable->getBillingSubscriptionEndsAt());
        $quota = (int) $billable->grantedBillingQuota('Tokens');
        $this->assertGreaterThan(0, $quota);
        $this->assertSame($quota, $this->tokens($billable), 'the first period\'s quota');

        // ── 20 March: cancelled on the dashboard.
        $this->travelSharedClockTo('2027-03-20 10:00:00');
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Free', 15)->pause(400);
            $this->confirmInModal($browser, 'cancel-subscription', self::CANCEL);
            $browser->waitForText(__('billing::portal.flash.cancelled'), 15)
                ->waitForText(__('billing::portal.cancelled_banner', ['date' => '19. Apr 2027']), 10);
            $this->shot($browser, 'Free-Plan am 20. gekündigt, läuft bis 19. April');
        });

        $this->assertSame('2027-04-19', $billable->fresh()->getBillingSubscriptionEndsAt()?->utc()->toDateString());

        // ── The 1 April boundary passes. The nightly pass does not renew a cancelled row.
        $this->travelSharedClockTo('2027-03-31 02:00:00');
        PrepareUsageOverageJob::dispatchSync();
        $this->travelSharedClockTo('2027-04-02 02:00:00');
        PrepareUsageOverageJob::dispatchSync();

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $this->assertSame('2027-03-01', $billable->getBillingPeriodStartsAt()?->utc()->toDateString(), 'not renewed while cancelled');
        $this->assertSame($quota, $this->tokens($billable), 'no refill while cancelled');

        // ── 2 April: resumed on the dashboard.
        $this->travelSharedClockTo('2027-04-02 09:00:00');
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->waitForPresence($browser, self::RESUBSCRIBE, 15);
            $browser->pause(300);
            $this->clickWire($browser, self::RESUBSCRIBE);
            $browser->waitForText(__('billing::portal.flash.resubscribed'), 15);
            $this->waitForAbsence($browser, self::RESUBSCRIBE, 10);
            $this->assertSame(0, $this->domCount($browser, '[dusk="cancelled-banner"]'));
            $browser->assertSee(__('billing::portal.free_plan_recurring_charge'));
            $this->shot($browser, 'Am 2. April wieder aufgenommen');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertNull($billable->getBillingSubscriptionEndsAt(), 'an unlimited free plan comes back unlimited');
        $this->assertSame('2027-04-01', $billable->getBillingPeriodStartsAt()?->utc()->toDateString(), 'the resume turned the lapsed period over');
        $this->assertSame('2027-05-01', $billable->nextBillingDate()?->utc()->toDateString());
        $this->assertSame(2 * $quota, $this->tokens($billable), 'April\'s quota on top (Tokens roll over)');

        // ── The next boundary: the nightly pass renews it, as any running free plan.
        $this->travelSharedClockTo('2027-04-30 02:00:00');
        PrepareUsageOverageJob::dispatchSync();
        $this->travelSharedClockTo('2027-05-01 09:00:00');

        $billable->refresh();
        $this->assertSame('2027-05-01', $billable->getBillingPeriodStartsAt()?->utc()->toDateString(), 'renewed on 1 May');
        $this->assertTrue($billable->nextBillingDate()->isFuture(), 'the next billing date is ahead');
        $this->assertSame('2027-06-01', $billable->nextBillingDate()?->utc()->toDateString());
        $this->assertSame(3 * $quota, $this->tokens($billable), 'May\'s quota refilled');

        $this->browse(function (Browser $browser) use ($billable, $quota): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Free', 15)->pause(400);
            $shown = $this->nextChargeOnScreen($browser);
            $this->assertSame(NextChargeSummary::STATE_FREE, $shown['state'], 'a running free plan');
            $browser->assertSee('01. May 2027');
            $this->assertSame(0, $this->domCount($browser, '[dusk="cancelled-banner"]'));
            $this->shot($browser, 'Dashboard: am 1. Mai erneuert');

            // The usage page shows the refilled quota and what rolled over.
            $browser->visit('/billing/usage')->waitFor('[dusk="usage-meter-Tokens"]', 15)->pause(400);
            $this->assertStringContainsString(
                __('billing::portal.usage_plan_remaining', ['remaining' => number_format($quota), 'total' => number_format($quota)]),
                $browser->text('[dusk="usage-meter-Tokens"]'),
            );
            $this->assertStringContainsString(
                __('billing::portal.usage_carried_over', ['count' => number_format(2 * $quota)]),
                $browser->text('[dusk="usage-meter-Tokens"]'),
            );
            $this->shot($browser, 'Verbrauch: Kontingent aufgefüllt');

            // A change on the plan page is accepted — not "has not renewed yet".
            $target = '[wire\\:click="previewFor(\'coupon-free\')"]';
            $browser->visit('/billing/plan')->waitFor($target, 15)->pause(400);
            $this->clickWire($browser, $target);
            $browser->waitFor('[x-on\\:click*="applyChange"]', 15)->pause(600);
            $this->shot($browser, 'Wechsel auf Free Forever');
            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $browser->waitForText(__('billing::portal.flash.plan_changed'), 20);
            $this->assertStringNotContainsString('has not renewed yet', $this->opsPageText($browser));
            $browser->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($browser, 'Planwechsel angenommen');
        });

        $billable->refresh();
        $this->assertSame('coupon-free', $billable->getBillingSubscriptionPlanCode(), 'the change was applied');
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'nothing of this went to Mollie');
        $this->assertSame(0, DB::table('fake_mollie_subscriptions')->count());
        $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class));
    }

    // ── 32. An unlimited free plan cancelled in two tabs ─────────────────────

    public function test_a_free_plan_cancelled_in_two_tabs_is_cancelled_once_and_resumes_without_an_end_date(): void
    {
        $billable = $this->freeSubscriber();

        $this->browse(function (Browser $first, Browser $second) use ($billable): void {
            $this->actAs($first, $billable, '/billing');
            $this->actAs($second, $billable, '/billing');
            $first->waitForText('Free', 15)->pause(400);
            $second->waitForText('Free', 15)->pause(400);

            // The first tab cancels.
            $this->confirmInModal($first, 'cancel-subscription', self::CANCEL);
            $first->waitForText(__('billing::portal.flash.cancelled'), 15);
            $this->waitForPresence($first, self::RESUBSCRIBE, 10);
            $this->shot($first, 'Tab 1: gekündigt');

            $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt();
            $this->assertNotNull($endsAt);
            $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class));

            // The second tab still shows the running plan, and its Cancel is clicked.
            $this->assertSame(0, $this->domCount($second, self::RESUBSCRIBE), 'the second tab is stale');
            $this->confirmInModal($second, 'cancel-subscription', self::CANCEL);

            // A no-op: the page says what is true — cancelled — and nothing failed.
            $second->waitForText(__('billing::portal.flash.cancelled'), 15)
                ->waitFor('[dusk="cancelled-banner"]', 10);
            $second->assertDontSee(__('billing::portal.flash.error'));
            $this->waitForPresence($second, self::RESUBSCRIBE, 10);
            $this->shot($second, 'Tab 2: zweite Kündigung ändert nichts');

            $this->assertEquals($endsAt, $billable->fresh()->getBillingSubscriptionEndsAt(), 'the end date did not move');
            $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class), 'one cancellation mail, not two');

            // Resumed from the second tab.
            $this->clickWire($second, self::RESUBSCRIBE);
            $second->waitForText(__('billing::portal.flash.resubscribed'), 15);
            $this->waitForAbsence($second, self::RESUBSCRIBE, 10);
            $this->assertSame(0, $this->domCount($second, '[dusk="cancelled-banner"]'), 'no "will end on"');
            $second->assertDontSee(__('billing::portal.access_grant_valid_until'))
                ->assertSee(__('billing::portal.free_plan_recurring_charge'));
            $second->assertSeeIn('[dusk="subscription-status"]', SubscriptionStatus::Active->label());
            $this->shot($second, 'Tab 2: wieder aufgenommen, ohne Enddatum');

            // The first tab, still showing Resubscribe, is told there is nothing to resume.
            $this->clickWire($first, self::RESUBSCRIBE);
            $first->waitForText(__('billing::portal.flash.resubscribe_not_cancelled'), 15);
            $first->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($first, 'Tab 1: nichts mehr zu reaktivieren');

            // Reloaded: no end date anywhere.
            $first->refresh()->waitFor('[dusk="subscription-status"]', 15)->pause(300);
            $this->assertSame(0, $this->domCount($first, '[dusk="cancelled-banner"]'));
            $first->assertDontSee(__('billing::portal.access_grant_valid_until'));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertNull($billable->getBillingSubscriptionEndsAt(), 'unlimited again');
        $this->assertArrayNotHasKey('cancelled_from', $meta);
        $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class));
        $this->assertSame(1, BillingAuditEntry::model()::query()->where('event', 'subscription_cancelled')->count(), 'one cancellation in the audit trail');

        // A month on, the expiry pass leaves it alone — the 30-day grace was not kept.
        $this->travelSharedClockTo(now()->addDays(35));
        PrepareUsageOverageJob::dispatchSync();
        $this->assertSame(SubscriptionStatus::Active, $billable->fresh()->getBillingSubscriptionStatus());
        $this->assertSame('changes-free', $billable->fresh()->getBillingSubscriptionPlanCode());
    }

    // ── 33. The cancel Mollie did not acknowledge ────────────────────────────

    public function test_a_cancel_mollie_refuses_is_finished_before_a_resume_creates_the_one_new_subscription(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['name' => 'Cancel Outage GmbH']);
        $customerId = (string) $billable->getMollieCustomerId();
        $oldId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $periodEnd = $billable->nextBillingDate();
        $periodEndShown = (string) BillingTime::billingDay($periodEnd)?->translatedFormat('d. M Y');

        WorkbenchSetting::set('fail_cancel_subscription', '1');

        $this->browse(function (Browser $browser) use ($billable, $periodEndShown): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Starter', 15)->pause(400);
            $this->confirmInModal($browser, 'cancel-subscription', self::CANCEL);

            // The customer's side is done: cancelled, to the end of the paid period.
            $browser->waitForText(__('billing::portal.flash.cancelled'), 15)
                ->waitForText(__('billing::portal.cancelled_banner', ['date' => $periodEndShown]), 10);
            $browser->assertDontSee(__('billing::portal.flash.error'));
            $this->shot($browser, 'Gekündigt — Mollie hat die Kündigung nicht angenommen');
        });

        $billable->refresh();
        $marker = $billable->getBillingSubscriptionMeta()['pending_subscription_cancel'] ?? null;

        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $this->assertSame($periodEnd->utc()->toDateString(), $billable->getBillingSubscriptionEndsAt()?->utc()->toDateString());
        $this->assertIsArray($marker, 'the cancel waits for the sweep');
        $this->assertSame($oldId, $marker['mollie_subscription_id'] ?? null);
        $this->assertSame('1', WorkbenchSetting::get('failed_cancel_subscriptions'));
        $this->assertSame(1, $this->activeFakeSubscriptions($customerId), 'still collecting at Mollie');

        // The operator is told; the customer gets the cancellation once.
        $operatorMail = $this->mailbox(AdminPlanChangeFailedNotification::class);
        $this->assertCount(1, $operatorMail);
        $this->assertStringContainsString('operator@workbench.test', (string) $operatorMail->first()->recipient);
        $this->assertCount(1, $this->mailbox(SubscriptionCancelledNotification::class));

        // ── Resubscribe while the old one still runs: refused, said as what it is.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->waitForPresence($browser, self::RESUBSCRIBE, 15);
            $browser->pause(300);
            $this->clickWire($browser, self::RESUBSCRIBE);

            $browser->waitForText(__('billing::portal.flash.resubscribe_previous_still_running'), 15);
            $browser->assertDontSee(__('billing::portal.flash.error'))
                ->assertDontSee(__('billing::portal.flash.resubscribed'));
            $this->waitForPresence($browser, self::RESUBSCRIBE, 10);
            $browser->assertPresent('[dusk="cancelled-banner"]');
            $this->shot($browser, 'Reaktivieren abgelehnt: altes Abo läuft noch bei Mollie');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus(), 'nothing written');
        $this->assertSame($oldId, $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null);
        $this->assertArrayHasKey('pending_subscription_cancel', $billable->getBillingSubscriptionMeta());
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->count(), 'no second subscription was created');
        $this->assertSame('2', WorkbenchSetting::get('failed_cancel_subscriptions'), 'the resume tried the cancel once more');

        // ── Mollie answers again; the hourly sweep comes round.
        WorkbenchSetting::forget('fail_cancel_subscription');
        app()->call([new SweepPendingRetriesJob, 'handle']);

        $billable->refresh();
        $this->assertNotNull(DB::table('fake_mollie_subscriptions')->where('id', $oldId)->value('canceled_at'), 'the sweep cancelled it at Mollie');
        $this->assertArrayNotHasKey('pending_subscription_cancel', $billable->getBillingSubscriptionMeta());
        $this->assertSame(0, $this->activeFakeSubscriptions($customerId));

        // ── Resubscribe now works, and leaves exactly one running subscription.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->waitForPresence($browser, self::RESUBSCRIBE, 15);
            $browser->pause(300);
            $this->clickWire($browser, self::RESUBSCRIBE);
            $browser->waitForText(__('billing::portal.flash.resubscribed'), 15);
            $this->waitForAbsence($browser, self::RESUBSCRIBE, 10);
            $this->assertSame(0, $this->domCount($browser, '[dusk="cancelled-banner"]'));
            $browser->assertSeeIn('[dusk="subscription-status"]', SubscriptionStatus::Active->label());
            $this->shot($browser, 'Reaktiviert: ein Abo bei Mollie');
        });

        $billable->refresh();
        $newId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertNull($billable->getBillingSubscriptionEndsAt());
        $this->assertNotSame($oldId, $newId);
        $this->assertSame(1, $this->activeFakeSubscriptions($customerId), 'exactly one subscription collects');
        $this->assertSame($newId, (string) DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->whereNull('canceled_at')->value('id'));
        $this->assertSame($periodEnd->utc()->toDateString(), $this->mollieNextPaymentDate($billable), 'its first charge at the paid period\'s end');
        $this->assertSame(0, BillingInvoice::query()->count(), 'nothing charged on the way');
        $this->assertCount(1, $this->mailbox(AdminPlanChangeFailedNotification::class), 'the operator was not told twice');
    }

    // ── 34. The admin tab of a cancelled row ─────────────────────────────────

    public function test_the_admin_tab_of_a_cancelled_row_offers_only_resubscribe_and_a_double_click_creates_one_subscription(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['name' => 'Double Click GmbH']);
        $customerId = (string) $billable->getMollieCustomerId();
        $oldId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        app(CancelSubscription::class)->handle($billable, false, 'customer asked');

        $this->assertSame(SubscriptionStatus::Cancelled, $billable->fresh()->getBillingSubscriptionStatus());
        $this->assertSame(0, $this->activeFakeSubscriptions($customerId));

        $this->browse(function (Browser $first, Browser $second) use ($billable, $customerId): void {
            $this->loginAdmin($first);
            $this->openSubscriptionTab($first, $billable);
            $this->loginAdmin($second);
            $this->openSubscriptionTab($second, $billable);

            $first->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Cancelled->label());
            $this->assertSame(1, $this->domCount($first, self::RESUBSCRIBE), 'Resubscribe is offered');
            $this->assertSame(0, $this->domCount($first, self::ADMIN_CANCEL_AT_END), 'no second cancel at period end');
            $this->assertSame(0, $this->domCount($first, self::ADMIN_CANCEL_NOW), 'no force cancel');
            $this->shot($first, 'Admin: gekündigt, nur Reaktivieren');

            // A double click — two clicks before the first answer is back, both confirmed.
            $first->script('window.confirm = () => true;');
            $first->script(sprintf(
                'const b = document.querySelector(%1$s); b.click(); b.click();',
                json_encode(self::RESUBSCRIBE),
            ));

            $first->waitForText(__('billing::admin.billables.subscription.flash.resubscribed'), 20)->pause(800);
            $first->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Active->label());
            $this->assertNoServerError($first);
            $this->shot($first, 'Doppelklick: einmal reaktiviert');
            $this->assertSame(1, $this->activeFakeSubscriptions($customerId), 'the double click created one subscription');

            // The second window still shows the cancelled row; its click is refused.
            $this->assertSame(1, $this->domCount($second, self::RESUBSCRIBE), 'the second window is stale');
            $second->script('window.confirm = () => true;');
            $second->script(sprintf('document.querySelector(%s).click();', json_encode(self::RESUBSCRIBE)));
            $second->waitForText(__('billing::admin.billables.subscription.flash.resubscribe_not_cancelled'), 20)->pause(400);
            $second->assertDontSee(__('billing::admin.billables.subscription.flash.resubscribed'));
            $this->assertNoServerError($second);
            $this->shot($second, 'Zweites Fenster: abgelehnt, nichts angelegt');

            // Reloaded: active, the cancel actions back, Resubscribe gone.
            $this->openSubscriptionTab($second, $billable);
            $second->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Active->label());
            $this->assertSame(0, $this->domCount($second, self::RESUBSCRIBE));
            $this->assertSame(1, $this->domCount($second, self::ADMIN_CANCEL_AT_END));
        });

        $billable->refresh();
        $newId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertNull($billable->getBillingSubscriptionEndsAt());
        $this->assertNotSame($oldId, $newId);
        $this->assertSame(1, $this->activeFakeSubscriptions($customerId), 'one running subscription at Mollie');
        $this->assertSame(2, DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->count(), 'the old one and exactly one new one');
        $this->assertSame(1, BillingAuditEntry::model()::query()->where('event', 'subscription_resumed')->count(), 'resumed once');
    }

    public function test_two_operators_resubscribing_at_once_create_one_subscription_and_the_second_is_refused(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['name' => 'Two Operators GmbH']);
        $customerId = (string) $billable->getMollieCustomerId();
        app(CancelSubscription::class)->handle($billable, false, 'customer asked');

        $this->browse(function (Browser $browser) use ($billable, $customerId): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            $this->assertSame(1, $this->domCount($browser, self::RESUBSCRIBE));

            // The first operator's resume reaches Mollie, which takes its time …
            WorkbenchSetting::set('hold_mollie_request', 'CreateSubscriptionRequest');
            $browser->script('window.confirm = () => true;');
            $browser->script(sprintf('document.querySelector(%s).click();', json_encode(self::RESUBSCRIBE)));
            $browser->waitUsing(15, 100, fn (): bool => WorkbenchSetting::get('mollie_request_held') === 'CreateSubscriptionRequest',
                'the resume never reached Mollie');

            // … and a second operator resumes the same row in that moment: the row still
            // reads cancelled, the first has written nothing yet.
            $this->assertSame(SubscriptionStatus::Cancelled, $billable->fresh()->getBillingSubscriptionStatus());
            $refused = null;

            try {
                app(ResubscribeSubscription::class)->handle(TestBillable::query()->findOrFail($billable->getKey()));
            } catch (\Throwable $e) {
                $refused = $e;
            }

            $this->assertInstanceOf(SubscriptionChangeInProgressException::class, $refused, 'the second resume is refused');
            $this->assertSame(1, DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->count(), 'nothing created by the second');

            // Mollie answers; the first finishes.
            WorkbenchSetting::forget('hold_mollie_request');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.resubscribed'), 20)->pause(400);
            $browser->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Active->label());
            $this->assertNoServerError($browser);
            $this->shot($browser, 'Erster Operator: reaktiviert');
        });

        $billable->refresh();

        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertSame(1, $this->activeFakeSubscriptions($customerId), 'one running subscription at Mollie');
        $this->assertSame(2, DB::table('fake_mollie_subscriptions')->where('customer_id', $customerId)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** An unlimited free plan, as the checkout leaves it. */
    private function freeSubscriber(): TestBillable
    {
        $billable = $this->makeBillable(['name' => 'Two Tabs GmbH'] + self::ADDRESS);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'changes-free',
            'subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => now()->subDays(5),
            'subscription_ends_at' => null,
            'subscription_meta' => ['seat_count' => 1],
        ])->save();

        return $billable->refresh();
    }

    private function activeFakeSubscriptions(string $customerId): int
    {
        return DB::table('fake_mollie_subscriptions')
            ->where('customer_id', $customerId)
            ->whereNull('canceled_at')
            ->where('status', 'active')
            ->count();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function mailbox(string $notification): \Illuminate\Support\Collection
    {
        return DB::table('workbench_mailbox')->where('notification', $notification)->get();
    }

    private function tokens(TestBillable $billable): int
    {
        return (int) ($billable->fresh()->getWallet('Tokens')?->balanceInt ?? 0);
    }

    /** Livewire renders a failed request (a 500) into an overlay; none may be on the page. */
    private function assertNoServerError(Browser $browser): void
    {
        $this->assertSame(0, $this->domCount($browser, '#livewire-error'), 'no Livewire error overlay');
        $this->assertStringNotContainsString('Server Error', $this->opsPageText($browser));
        $this->assertStringNotContainsString(__('billing::admin.common.error_generic', ['code' => 0]), $this->opsPageText($browser));
    }
}

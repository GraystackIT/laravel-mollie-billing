<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * The change screens beyond the money matrix (ChangeMatrixTest): what each
 * `plan_change_mode` offers, the scheduled change through its whole life, the states a
 * subscription can be in, every refusal with the hint the customer reads, the declined
 * payment, and the payment that arrives for a change that no longer exists.
 */
class ChangeScreensMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_PRO = '[wire\\:click="previewFor(\'pro\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    // ── plan_change_mode ────────────────────────────────────────────────────

    /** @return array<string, array{0: PlanChangeMode}> */
    public static function modes(): array
    {
        return [
            'immediate' => [PlanChangeMode::Immediate],
            'end_of_period' => [PlanChangeMode::EndOfPeriod],
            'user_choice' => [PlanChangeMode::UserChoice],
        ];
    }

    #[DataProvider('modes')]
    public function test_the_mode_offers_the_timing_it_allows_and_the_change_runs_then(PlanChangeMode $mode): void
    {
        $this->serverConfig('mollie-billing.plan_change_mode', $mode->value);
        config(['mollie-billing.plan_change_mode' => $mode]);

        $billable = $this->paidSubscriber('business');

        $this->browse(function (Browser $browser) use ($billable, $mode): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->clickWire($browser, self::PREVIEW_PRO);
            $browser->waitFor(self::APPLY, 15)->pause(500);

            // The timing switch exists only where the customer has a choice.
            $switch = $this->domCount($browser, '[\\@click*="end_of_period"]');
            $this->assertSame($mode === PlanChangeMode::UserChoice ? 1 : 0, $switch, 'timing switch under '.$mode->value);

            $label = $mode === PlanChangeMode::EndOfPeriod
                ? __('billing::portal.schedule_end_of_period')
                : __('billing::portal.apply_immediately');
            $browser->assertSeeIn(self::APPLY, $label);
            $this->shot($browser, 'Modus '.$mode->value.': angebotener Zeitpunkt');

            $this->clickWire($browser, self::APPLY);
            $browser->pause(1500);
        });

        $this->settlePending($billable);
        $billable->refresh();

        if ($mode === PlanChangeMode::EndOfPeriod) {
            $this->assertSame('pro', $billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null);
            $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());

            return;
        }

        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        // Pro 29.00 + 19 %.
        $this->assertSame('34.51', (string) $this->mollieSubscriptionAmount($billable));
    }

    // ── A scheduled change, through its life ────────────────────────────────

    public function test_a_change_is_scheduled_withdrawn_scheduled_again_and_applied_now(): void
    {
        $billable = $this->paidSubscriber('business');

        $this->browse(function (Browser $browser) use ($billable): void {
            $schedule = function () use ($browser): void {
                $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
                $this->clickWire($browser, self::PREVIEW_PRO);
                $browser->waitFor(self::APPLY, 15)->pause(500);
                $this->jsClick($browser, '[\\@click*="end_of_period"]');
                $this->clickWire($browser, self::APPLY);
                $browser->waitForText(__('billing::portal.cancel_scheduled_change'), 15)->pause(400);
            };

            $this->actAs($browser, $billable, '/billing/plan');
            $schedule();
            $this->shot($browser, 'Geplant');

            $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null);
            $this->assertSame('business', $billable->fresh()->getBillingSubscriptionPlanCode());

            // Withdrawn: nothing is left behind, and Mollie collects the old amount again.
            $this->clickWire($browser, '[wire\\:click="cancelScheduledChange"]');
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->assertNull($billable->fresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
            $this->assertSame('117.81', (string) $this->mollieSubscriptionAmount($billable));

            $schedule();

            // "Apply now" sits in the menu beside the cancel button.
            $this->wireCall($browser, '[wire\\:click="cancelScheduledChange"]', 'applyScheduledNow');
            $browser->pause(1200);
            $this->shot($browser, 'Sofort angewendet');
        });

        $this->settlePending($billable);
        $billable->refresh();

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
        $this->assertSame('34.51', (string) $this->mollieSubscriptionAmount($billable));

        $renewal = $this->opsRenew($billable, '34.51');
        $this->assertSame(3451, (int) $renewal->amount_gross);
        $this->assertNotContains('adjustment', array_column((array) $renewal->line_items, 'kind'));
    }

    // ── The states a subscription can be in ─────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function states(): array
    {
        return [
            'trial' => ['trial'],
            'past_due' => ['past_due'],
            'cancelled' => ['cancelled'],
            'gifted' => ['gifted'],
        ];
    }

    #[DataProvider('states')]
    public function test_adding_a_seat_in_this_state_does_what_the_screen_says(string $state): void
    {
        $billable = $this->couponBillableIn($state);
        $this->setIpCountry('DE');
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 3, 'used_seats' => 1])])->save();
        $payments = DB::table('fake_mollie_payments')->count();
        $invoices = BillingInvoice::query()->count();

        $this->browse(function (Browser $browser) use ($billable, $state): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(900);

            if ($state === 'trial') {
                // A trial pays nothing for what it adds within its plan.
                $this->assertSame(0, $this->domCount($browser, '[dusk="seats-due-now"]'), 'no amount due now during a trial');
            }

            $this->shot($browser, 'Zustand '.$state.': vor dem Bestätigen');
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $browser->pause(1500);
            $this->shot($browser, 'Zustand '.$state.': danach');

            if ($state === 'past_due') {
                $browser->assertSee(__('billing::portal.error_past_due_expansion'));
            }
        });

        $billable->refresh();

        match ($state) {
            // Applied at once, nothing collected, nothing booked.
            'trial' => $this->assertSame(
                [4, $payments, $invoices],
                [$billable->getBillingSeatCount(), DB::table('fake_mollie_payments')->count(), BillingInvoice::query()->count()],
            ),
            // Refused: the open payment comes first.
            'past_due' => $this->assertSame(
                [3, $payments, SubscriptionStatus::PastDue],
                [$billable->getBillingSeatCount(), DB::table('fake_mollie_payments')->count(), $billable->getBillingSubscriptionStatus()],
            ),
            // A gifted plan has no Mollie subscription: the seat is a purchase the customer
            // completes at Mollie, and nothing changes until it is paid.
            'gifted' => $this->assertSame(
                [3, $payments + 1, 'first'],
                [$billable->getBillingSeatCount(), DB::table('fake_mollie_payments')->count(), (string) $this->latestFakePayment()['sequence_type']],
            ),
            // Still running until its end date: the seat is added and charged for the rest.
            'cancelled' => $this->assertSame(
                [SubscriptionStatus::Cancelled, true],
                [$billable->getBillingSubscriptionStatus(), in_array($billable->getBillingSeatCount(), [3, 4], true)],
            ),
        };
    }

    // ── Refusals, each with the hint the customer reads ─────────────────────

    public function test_the_seat_ceiling_is_named_and_nothing_is_sent(): void
    {
        $this->serverConfig('mollie-billing.max_seats', 5);
        $billable = $this->paidSubscriber('pro', 4);
        $payments = DB::table('fake_mollie_payments')->count();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->wireSet($browser, '[wire\\:click="increment"]', ['seatCount' => 6]);
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $browser->waitForText(__('billing::portal.seats_flash.too_many', ['max' => 5]), 10);
            $this->shot($browser, 'Sitz-Obergrenze');
        });

        $this->assertSame(4, $billable->fresh()->getBillingSeatCount());
        $this->assertSame($payments, DB::table('fake_mollie_payments')->count());
    }

    public function test_a_plan_that_sells_no_seats_offers_no_seat_change(): void
    {
        $billable = $this->paidSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->pause(1500);
            $this->shot($browser, 'Plan ohne Sitzverkauf');

            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="increment"]'));
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="syncSeats"]'));
        });
    }

    public function test_an_interval_change_under_a_waiver_is_refused_with_its_reason(): void
    {
        $billable = $this->paidSubscriber('pro', 4, [], function (TestBillable $billable): void {
            $grant = $this->coupons()->accessGrantCoupon('WVR'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', [], 90);
            app(PlanWaiver::class)->grant($billable, $grant, 'pro', 'monthly', [], now()->addDays(75), null, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
        });
        $held = (string) $this->mollieSubscriptionAmount($billable);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click^="previewFor"]', 15)->pause(400);
            $this->wireSet($browser, '[wire\\:click^="previewFor"]', ['selectedInterval' => 'yearly']);
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(300);
            $this->clickWire($browser, self::PREVIEW_PRO);
            $browser->waitForText(__('billing::portal.error_interval_change_under_waiver'), 15);
            $this->shot($browser, 'Intervallwechsel unter Planerlass');

            // Nothing to click: the apply button is the disabled one.
            $this->assertSame(0, $this->domCount($browser, self::APPLY));
        });

        $this->assertSame('monthly', (string) $billable->fresh()->getBillingSubscriptionInterval());
        $this->assertSame($held, (string) $this->mollieSubscriptionAmount($billable));
    }

    public function test_a_paid_addon_is_refused_while_a_payment_is_outstanding(): void
    {
        $billable = $this->couponBillableIn('past_due');
        $this->setIpCountry('DE');
        $payments = DB::table('fake_mollie_payments')->count();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $action = '[wire\\:click="enableAddon(\'softdrinks\')"]';
            $this->waitForPresence($browser, $action, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', $action);
            $browser->waitForText(__('billing::portal.error_past_due_expansion'), 10);
            $this->shot($browser, 'Addon im Zahlungsverzug');
        });

        $this->assertNotContains('softdrinks', $billable->fresh()->getActiveBillingAddonCodes());
        $this->assertSame($payments, DB::table('fake_mollie_payments')->count());
    }

    public function test_seats_are_locked_while_a_plan_change_is_scheduled(): void
    {
        $billable = $this->paidSubscriber('business', 12);
        app(UpdateSubscription::class)->update($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'end_of_period', 'seats' => 12]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitForText(__('billing::portal.pending_change_blocks_modifications'), 15);
            $this->shot($browser, 'Sitze gesperrt: Wechsel geplant');

            $disabled = $browser->script('return document.querySelector(\'[wire\\\\:click="syncSeats"]\').disabled === true;')[0];
            $this->assertTrue((bool) $disabled, 'the update button is disabled while a change is scheduled');
        });
    }

    public function test_a_second_discount_code_is_refused_on_the_seat_form_with_its_reason(): void
    {
        $billable = $this->paidSubscriber('pro', 4, [], function (TestBillable $billable): void {
            $this->opsApplyRecurringCoupon($billable, 2900 + 990);
        });
        $this->coupons()->create([
            'code' => 'SECOND20', 'name' => 'Second', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
            'max_redemptions_per_billable' => 3,
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(600);
            $this->wireSet($browser, '[wire\\:click="increment"]', ['couponInput' => 'SECOND20']);
            $this->wireCall($browser, '[wire\\:click="increment"]', 'applyCoupon');
            $browser->pause(800);

            $error = (string) $this->wireGet($browser, '[wire\\:click="increment"]', 'couponError');
            $this->assertNotSame('', $error, 'the form says why the code was not taken');
            $browser->assertSee($error);
            $this->assertSame([], (array) $this->wireGet($browser, '[wire\\:click="increment"]', 'appliedCouponCodes'));
            $this->shot($browser, 'Zweiter Rabattcode abgelehnt');
        });
    }

    // ── A code entered with the change ──────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function forms(): array
    {
        return ['seats' => ['seats'], 'addons' => ['addons'], 'plan' => ['plan']];
    }

    #[DataProvider('forms')]
    public function test_a_code_on_this_form_lowers_what_is_due_now_can_be_removed_and_is_what_is_collected(string $form): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        $this->coupons()->create([
            'code' => 'FORM20', 'name' => 'Form', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
            'max_redemptions_per_billable' => 3,
        ]);
        $payments = array_column(DB::table('fake_mollie_payments')->get(['id'])->all(), 'id');
        $shown = 0;

        $this->browse(function (Browser $browser) use ($billable, $form, &$shown): void {
            [$url, $anchor, $dusk, $apply, $remove, $input] = match ($form) {
                'seats' => ['/billing/seats', '[wire\\:click="increment"]', 'seats-due-now', ['applyCoupon'], ['removeCoupon', 'FORM20'], 'couponInput'],
                'addons' => ['/billing/addons', '[wire\\:click="enableAddon(\'softdrinks\')"]', 'addon-due-now-softdrinks', ['applyCoupon', 'softdrinks'], ['removeCoupon', 'softdrinks', 'FORM20'], 'couponInputs.softdrinks'],
                'plan' => ['/billing/plan', '[wire\\:click^="previewFor"]', 'prorata-total', ['applyCoupon'], ['removeCoupon', 'FORM20'], 'couponInput'],
            };

            $this->actAs($browser, $billable, $url);
            $this->waitForPresence($browser, $anchor, 15);
            $browser->pause(400);

            if ($form === 'seats') {
                $this->clickWire($browser, $anchor);
            }
            if ($form === 'plan') {
                $this->clickWire($browser, '[wire\\:click="previewFor(\'business\')"]');
            }
            $this->waitForPresence($browser, '[dusk="'.$dusk.'"]', 15);
            $browser->pause(500);

            $read = fn (): int => (int) preg_replace('/\D/', '', (string) $browser->script('return document.querySelector(\'[dusk="'.$dusk.'"]\').textContent;')[0]);
            $codes = fn (): array => $form === 'addons'
                ? (array) (((array) $this->wireGet($browser, $anchor, 'appliedCouponCodes'))['softdrinks'] ?? [])
                : (array) $this->wireGet($browser, $anchor, 'appliedCouponCodes');

            $full = $read();
            $this->assertGreaterThan(0, $full);

            $this->wireSetAny($browser, $anchor, [$input => 'FORM20']);
            $this->wireCall($browser, $anchor, ...$apply);
            $browser->pause(700);
            $discounted = $read();
            $this->assertSame(['FORM20'], $codes(), $form.': the code is on the form');
            $this->assertLessThan($full, $discounted, $form.': the code lowers the amount due now');
            // One code that does not stack: the field for a second one is gone.
            $this->assertSame(0, $this->domCount($browser, 'input[wire\\:model="'.$input.'"]'), $form.': no field for a second code');
            $this->shot($browser, 'Code auf dem Formular '.$form);

            $this->wireCall($browser, $anchor, ...$remove);
            $browser->pause(700);
            $this->assertSame([], $codes());
            $this->assertSame($full, $read(), $form.': removed, the amount is the full one again');

            $this->wireSetAny($browser, $anchor, [$input => 'FORM20']);
            $this->wireCall($browser, $anchor, ...$apply);
            $browser->pause(700);
            $shown = $read();

            match ($form) {
                'seats' => $this->clickWire($browser, '[wire\\:click="syncSeats"]'),
                'addons' => $this->confirmInModal($browser, 'enable-addon-softdrinks', $anchor),
                'plan' => $this->clickWire($browser, self::APPLY),
            };
            $browser->pause(1500);
        });

        $new = DB::table('fake_mollie_payments')->whereNotIn('id', $payments)->get();
        $this->assertCount(1, $new, $form.': one payment');
        $this->assertSame($shown, BillingMoney::minorFromMollieAmount((string) $new[0]->amount_value, 'EUR'), $form.': shown vs. collected');

        $this->settlePending($billable);
        $this->assertSame('FORM20', $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null);
    }

    // ── The addon screen announces the outcome it polled for ────────────────

    public function test_the_addon_screen_announces_the_addon_once_its_payment_has_arrived(): void
    {
        $billable = $this->paidSubscriber('pro', 4);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $action = '[wire\\:click="enableAddon(\'softdrinks\')"]';
            $this->waitForPresence($browser, $action, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', $action);
            $browser->pause(800);

            // The screen polls while the payment is open …
            $this->assertSame(1, $this->domCount($browser, '[wire\\:poll\\.3s="pollForChange"]'));

            $this->settlePending($billable);

            // … and says so when it has arrived, then stops.
            $browser->waitForText(__('billing::portal.addons_flash.enabled', ['addon' => 'Softdrinks']), 15);
            $this->shot($browser, 'Addon aktiviert: Meldung nach dem Abfragen');
            $this->waitForAbsence($browser, '[wire\\:poll\\.3s="pollForChange"]', 10);
        });

        $this->assertContains('softdrinks', $billable->fresh()->getActiveBillingAddonCodes());
    }

    // ── From a free plan: ask, correct the address, pay, withdraw ───────────

    public function test_a_free_plan_upgrade_asks_first_and_takes_an_address_correction_before_it_charges(): void
    {
        $billable = $this->couponBillableIn('free');
        $this->setIpCountry('DE');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->clickWire($browser, self::PREVIEW_PRO);
            $browser->pause(1200);
            $this->wireCall($browser, '[wire\\:model\\.live="selectedInterval"]', 'applyChange', 'immediate');

            // Asked first: nothing has been sent to Mollie.
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $this->assertSame(0, DB::table('fake_mollie_payments')->count());
            // Pro 29.00 + 19 %.
            $this->assertShownAmount($browser, 'upgrade-total', 3451);
            $this->shot($browser, 'Upgrade: Rückfrage');

            // Back, and nothing happened.
            $this->clickWire($browser, '[wire\\:click="backToPlanSelection"]');
            $this->waitForAbsence($browser, '[dusk="upgrade-total"]', 10);
            $this->assertSame(0, DB::table('fake_mollie_payments')->count());

            // The plan is still the selected one; asking again brings the question back.
            $this->wireCall($browser, '[wire\\:model\\.live="selectedInterval"]', 'applyChange', 'immediate');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);

            // The address on the confirmation can be corrected in place: to Austria, 20 %.
            $this->clickWire($browser, '[wire\\:click="openEditBillingModal"]');
            $browser->waitFor('[wire\\:click="saveBillingDetails"]', 10)->pause(400);
            $this->assertSame('Berlin', (string) $this->wireGet($browser, '[wire\\:click="confirmAndPay"]', 'billing_city'));
            $this->wireSet($browser, '[wire\\:click="confirmAndPay"]', ['billing_city' => 'Wien', 'billing_postal_code' => '1010', 'billing_country' => 'AT']);
            $this->clickWire($browser, '[wire\\:click="saveBillingDetails"]');
            $browser->waitForText(__('billing::portal.flash.billing_details_saved'), 10)->pause(600);
            $this->assertShownAmount($browser, 'upgrade-total', 3480, 'the total follows the corrected country');
            $this->shot($browser, 'Upgrade: Adresse korrigiert');

            $this->clickWire($browser, '[wire\\:click="confirmAndPay"]');
            $browser->pause(2500);
        });

        $billable->refresh();
        $payment = $this->latestFakePayment();

        $this->assertSame('AT', (string) $billable->billing_country);
        $this->assertSame('Wien', (string) $billable->billing_city);
        $this->assertSame('34.80', (string) $payment['amount_value']);
        $this->assertSame('coupon-free', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame('first', (string) $payment['sequence_type'], 'a first payment the customer completes at Mollie');
    }

    // ── A declined prorata payment ──────────────────────────────────────────

    public function test_a_declined_payment_takes_the_addon_back_and_the_plan_screen_says_so(): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        $held = (string) $this->mollieSubscriptionAmount($billable);
        $invoices = BillingInvoice::query()->count();

        $this->setRecurringOutcome('failed');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $action = '[wire\\:click="enableAddon(\'softdrinks\')"]';
            $this->waitForPresence($browser, $action, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', $action);
            $browser->pause(1200);

            $this->settlePending($billable);
            $this->assertNotNull(
                $billable->fresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null,
                'the declined payment is recorded: '.json_encode(array_keys($billable->fresh()->getBillingSubscriptionMeta())),
            );

            $browser->visit('/billing/plan')->waitForText(__('billing::portal.flash.plan_change_failed'), 15);
            $this->shot($browser, 'Zahlung abgelehnt: Hinweis');
        });

        $this->setRecurringOutcome('paid');
        $billable->refresh();

        $this->assertNotContains('softdrinks', $billable->getActiveBillingAddonCodes());
        $this->assertNull($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null);
        $this->assertSame($held, (string) $this->mollieSubscriptionAmount($billable));
        $this->assertSame($invoices, BillingInvoice::query()->count());
    }

    // ── A payment that arrives for a change that no longer exists ───────────

    public function test_a_payment_for_a_withdrawn_change_is_returned_in_full_and_both_documents_are_listed(): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        $refunds = DB::table('fake_mollie_refunds')->count();

        // The charge stays open at Mollie (a slow payment method) …
        $this->setRecurringOutcome('open');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $target = '[wire\\:click="previewFor(\'business\')"]';
            $browser->waitFor($target, 15)->pause(400);
            $this->clickWire($browser, $target);
            $browser->waitFor(self::APPLY, 15)->pause(500);
            $this->clickWire($browser, self::APPLY);
            $browser->pause(1500);
        });

        $this->setRecurringOutcome('paid');
        $paymentId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
        $this->assertNotSame('', $paymentId, 'the upgrade is waiting for its payment');

        // … the customer withdraws the change on the plan screen, and then the money
        // arrives after all.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="cancelPendingChange"]', 15)->pause(400);
            $this->shot($browser, 'Wechsel wartet auf Zahlung');
            $this->clickWire($browser, '[wire\\:click="cancelPendingChange"]');
            // The notice and its button go with the pending change.
            $this->waitForAbsence($browser, '[wire\\:click="cancelPendingChange"]', 15);
            $this->shot($browser, 'Wechsel zurückgezogen');
        });

        $this->assertNull($billable->fresh()->getBillingSubscriptionMeta()['pending_plan_change'] ?? null);
        $this->assertNull($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null);

        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->deliverWebhook($paymentId);

        $billable->refresh();
        $payment = FakeMollie::payment($paymentId);
        $gross = BillingMoney::minorFromMollieAmount((string) $payment['amount_value'], 'EUR');

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
        $note = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->latest('id')->firstOrFail();
        $refunded = 0;
        foreach (DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->get() as $refund) {
            $refunded += BillingMoney::minorFromMollieAmount((string) $refund->amount_value, 'EUR');
        }

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame($gross, (int) $invoice->amount_gross);
        $this->assertSame($gross, abs((int) $note->amount_gross));
        $this->assertSame($gross, $refunded);
        $this->assertSame($refunds + 1, DB::table('fake_mollie_refunds')->count());

        // Delivered again: nothing twice.
        $this->deliverWebhook($paymentId);
        $this->assertSame($refunds + 1, DB::table('fake_mollie_refunds')->count());

        $this->browse(function (Browser $browser) use ($billable, $invoice, $note): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross, 'the payment that was not applied');
            $this->assertShownAmount($browser, 'invoice-'.$note->id.'-gross', (int) $note->amount_gross, 'its credit note');
            $this->shot($browser, 'Nicht angewendete Zahlung: Rechnung und Gutschrift');
        });
    }

    // ── The balance charged after a renewal that was too low ────────────────

    /** @return array<string, array{0: string}> */
    public static function balanceOutcomes(): array
    {
        return ['paid' => ['paid'], 'failed' => ['failed'], 'no_mandate' => ['no_mandate']];
    }

    #[DataProvider('balanceOutcomes')]
    public function test_a_balance_charge_is_shown_to_the_customer_and_the_operator_through_its_life(string $outcome): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // Mollie prepares the next debit for four seats …
        $prepared = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first(['amount_value', 'metadata']);

        // … then a fifth is added and paid for the rest of the period …
        app(UpdateSubscription::class)->update($billable, ['seats' => 5]);
        $this->settlePending($billable);

        if ($outcome === 'no_mandate') {
            $billable->forceFill(['mollie_mandate_id' => null])->save();
        }

        // … and the debit that was prepared before that is what gets collected.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['metadata' => $prepared->metadata]);
        $payments = array_column(DB::table('fake_mollie_payments')->get(['id'])->all(), 'id');
        $renewal = $this->opsRenew($billable, (string) $prepared->amount_value);
        $billable->refresh();

        $case = \GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::cases($billable)[0] ?? null;
        $this->assertNotNull($case, 'the short renewal is recognised');
        $balance = DB::table('fake_mollie_payments')->whereNotIn('id', $payments)->where('id', '!=', $renewal->mollie_payment_id)->first();

        if ($outcome === 'no_mandate') {
            $this->assertNull($balance, 'nothing can be charged without a mandate');
            $this->assertSame('not_possible', $case['status']);

            $this->browse(function (Browser $browser) use ($billable): void {
                $this->loginAdmin($browser);
                $this->opsOpenBillableTab($browser, $billable, 'subscription');
                $browser->waitFor('[dusk="balance-charge-row-not_possible"]', 15)
                    ->assertSeeIn('[dusk="balance-charge-row-not_possible"]', __('billing::admin.balance_charges.status.not_possible'))
                    ->assertSeeIn('[dusk="balance-charge-row-not_possible"]', __('billing::admin.balance_charges.obstacle.no_mandate'));
                $this->shot($browser, 'Admin: Nachbelastung nicht möglich');
            });

            return;
        }

        // One extra seat 9.90 + 19 %.
        $this->assertNotNull($balance);
        $this->assertSame('11.78', (string) $balance->amount_value);
        $this->assertSame('pending', $case['status']);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('[dusk="balance-charge-pending"]', 15)
                ->assertSeeIn('[dusk="balance-charge-pending"]', __('billing::portal.balance_charge.pending', ['amount' => BillingMoney::format(1178)]))
                ->assertSeeIn('[dusk="balance-charge-pending"]', __('billing::notifications.undercollection_charged.reasons.renewal_created_before_change'));
            $this->shot($browser, 'Dashboard: Nachbelastung offen');

            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'subscription');
            $browser->waitFor('[dusk="balance-charge-row-pending"]', 15)
                ->assertSeeIn('[dusk="balance-charge-row-pending"]', __('billing::admin.balance_charges.status.pending'))
                ->assertSeeIn('[dusk="balance-charge-row-pending"]', BillingMoney::format(1178));
        });

        if ($outcome === 'failed') {
            FakeMollie::settle((string) $balance->id, 'failed', 'DE');
        }
        $this->deliverWebhook((string) $balance->id);
        $billable->refresh();

        if ($outcome === 'failed') {
            $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus(), 'a declined balance goes into the ordinary dunning');

            $this->browse(function (Browser $browser) use ($billable): void {
                $this->actAs($browser, $billable, '/billing');
                $browser->waitFor('[dusk="balance-charge-failed"]', 15)
                    ->assertSeeIn('[dusk="balance-charge-failed"]', __('billing::portal.balance_charge.failed', ['amount' => BillingMoney::format(1178)]));
                $this->shot($browser, 'Dashboard: Nachbelastung abgelehnt');

                $this->loginAdmin($browser);
                $this->opsOpenBillableTab($browser, $billable, 'subscription');
                $browser->waitFor('[dusk="balance-charge-row-failed"]', 15)
                    ->assertSeeIn('[dusk="balance-charge-row-failed"]', __('billing::admin.balance_charges.status.failed'));
                $this->shot($browser, 'Admin: Nachbelastung abgelehnt');
            });

            return;
        }

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $balance->id)->firstOrFail();
        $this->assertSame(1178, (int) $invoice->amount_gross);

        $this->browse(function (Browser $browser) use ($billable, $invoice, $renewal): void {
            // Paid: an invoice of its own, named, with its reason — and no hint left.
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-'.$invoice->id.'-balance"]', 15)
                ->assertSeeIn('[dusk="invoice-'.$invoice->id.'-balance"]', __('billing::portal.balance_charge.invoice_label'))
                ->assertSeeIn('[dusk="invoice-'.$invoice->id.'-balance"]', (string) $renewal->serial_number);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', 1178);
            $this->shot($browser, 'Rechnungsliste: Nachbelastung');

            $browser->visit('/billing')->pause(1500);
            $this->assertSame(0, $this->domCount($browser, '[dusk^="balance-charge-"]'));

            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'subscription');
            $browser->waitFor('[dusk="balance-charge-row-paid"]', 15)
                ->assertSeeIn('[dusk="balance-charge-row-paid"]', __('billing::admin.balance_charges.status.paid'));
            $this->shot($browser, 'Admin: Nachbelastung bezahlt');
        });
    }

    // ── A scheduled change that cannot be applied ───────────────────────────

    public function test_a_scheduled_change_shows_each_attempt_and_then_why_it_was_given_up(): void
    {
        $billable = $this->paidSubscriber('business', 12);
        app(UpdateSubscription::class)->update($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
        $billable->refresh()->forceFill(['scheduled_change_at' => now()->subMinute()])->save();

        // Mollie cannot be reached on three nights running.
        $real = app(UpdateSubscription::class);
        app()->bind(UpdateSubscription::class, fn () => new class
        {
            public function update(mixed ...$arguments): never
            {
                throw new \RuntimeException('Mollie is unreachable');
            }
        });

        try {
            foreach ([1, 2] as $night) {
                app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable->fresh());

                $this->browse(function (Browser $browser) use ($billable, $night): void {
                    $text = __('billing::portal.scheduled_change_retry', ['attempt' => $night + 1, 'max' => 3]);

                    $this->actAs($browser, $billable, '/billing');
                    $browser->waitFor('[dusk="scheduled-change-retry"]', 15)->assertSeeIn('[dusk="scheduled-change-retry"]', $text);
                    $browser->visit('/billing/plan')->waitFor('[dusk="scheduled-change-retry"]', 15)->assertSeeIn('[dusk="scheduled-change-retry"]', $text);
                    $this->shot($browser, 'Geplanter Wechsel: Versuch '.($night + 1).' von 3');

                    $this->loginAdmin($browser);
                    $anchor = '[dusk="scheduled-change-attempts-'.$billable->getKey().'"]';
                    $browser->visit('/billing/admin/scheduled-changes')->waitFor($anchor, 15)
                        ->assertSeeIn($anchor, __('billing::admin.scheduled_changes.attempts', ['attempts' => $night, 'max' => 3]))
                        ->assertSeeIn($anchor, 'Mollie is unreachable');
                    $this->shot($browser, 'Admin: geplanter Wechsel, Versuch '.$night);
                });
            }

            app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable->fresh());
        } finally {
            app()->instance(UpdateSubscription::class, $real);
        }

        $billable->refresh();
        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null, 'given up after the third attempt');
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        // What had been announced to Mollie is taken back: Business with twelve seats,
        // 99.00 + 2 × 7.90 = 114.80, + 19 %.
        $this->assertSame('136.61', (string) $this->mollieSubscriptionAmount($billable));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15)
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::portal.scheduled_change_dropped', ['plan' => 'Pro']))
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::notifications.scheduled_change_not_applied.reasons.attempts_exhausted'));
            $this->assertSame(0, $this->domCount($browser, '[dusk="scheduled-change-retry"]'));
            $this->shot($browser, 'Geplanter Wechsel: verworfen');

            $browser->visit('/billing')->waitFor('[dusk="scheduled-change-dropped"]', 15);
        });
    }

    public function test_a_scheduled_change_the_rules_refuse_is_dropped_at_once_and_says_why(): void
    {
        // Two more seats at the period end — and by then the subscription is past due.
        $billable = $this->paidSubscriber('pro', 4);
        app(UpdateSubscription::class)->update($billable, ['seats' => 6, 'apply_at' => 'end_of_period']);
        $billable->refresh();
        $this->assertSame(6, $billable->getBillingSubscriptionMeta()['scheduled_change']['seats'] ?? null);

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['past_due_since'] = now()->subDay()->toIso8601String();
        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
            'scheduled_change_at' => now()->subMinute(),
        ])->save();

        app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable->fresh());
        $billable->refresh();

        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
        $this->assertSame(4, $billable->getBillingSeatCount());

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15)
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::portal.scheduled_change_dropped_generic'))
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::notifications.scheduled_change_not_applied.reasons.payment_outstanding'));
            $this->shot($browser, 'Geplanter Wechsel: dauerhaft abgelehnt');
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The attempt of the first night gets as far as creating the prorata payment and then
     * fails (an app listener throws). The payment is in flight — a slow method — while the
     * stored change is tried again and finally given up:
     *
     *  - no further payment is created on the following nights, and the operator's list
     *    names the one that exists;
     *  - when the change is given up, the customer is told that the payment on its way
     *    comes back, and nothing is left to cancel or to apply;
     *  - the payment arrives: returned in full, the plan stays, both documents are listed,
     *    and the notice stops announcing a refund that has happened.
     */
    public function test_a_scheduled_change_given_up_after_its_payment_was_created_returns_the_payment_and_says_so(): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        app(UpdateSubscription::class)->update($billable, ['plan_code' => 'business', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
        $billable->refresh()->forceFill(['scheduled_change_at' => now()->subMinute()])->save();

        $payments = DB::table('fake_mollie_payments')->count();
        $refunds = DB::table('fake_mollie_refunds')->count();
        $schedule = app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class);

        // Night one: the payment is created and stays open; then the app's listener throws.
        $this->setRecurringOutcome('open');
        $throw = true;
        \Illuminate\Support\Facades\Event::listen(\GraystackIT\MollieBilling\Events\PlanChangePending::class, function () use (&$throw): void {
            if ($throw) {
                $throw = false;

                throw new \RuntimeException('The app listener is down');
            }
        });

        $schedule->apply($billable->fresh());
        $paymentId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');

        $this->assertNotSame('', $paymentId, 'the first attempt created the payment');
        $this->assertSame($payments + 1, DB::table('fake_mollie_payments')->count());
        $this->assertSame(1, $billable->fresh()->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null);

        // Business for what is left of the period that began yesterday, + 19 %. Seats: the
        // change lands on the ten Business includes, so no seat line.
        $start = \Carbon\Carbon::now('UTC')->subDay()->startOfDay();
        $total = (int) $start->diffInDays($start->copy()->addMonth());
        $net = (int) round(9900 * ($total - 1) / $total);
        $gross = $net + (int) round($net * 0.19);
        $this->assertSame($gross, BillingMoney::minorFromMollieAmount((string) FakeMollie::payment($paymentId)['amount_value'], 'EUR'), 'what the first attempt asked Mollie to collect');

        // Night two: tried again, refused because that payment is still in flight.
        $schedule->apply($billable->fresh());

        $this->assertSame($payments + 1, DB::table('fake_mollie_payments')->count(), 'no second payment');
        $this->assertSame(2, $billable->fresh()->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null);

        $this->browse(function (Browser $browser) use ($billable, $paymentId): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-retry"]', 15)
                ->assertSeeIn('[dusk="scheduled-change-retry"]', __('billing::portal.scheduled_change_retry', ['attempt' => 3, 'max' => 3]));
            $this->shot($browser, 'Geplanter Wechsel: Zahlung unterwegs, Versuch 3 von 3');

            $this->loginAdmin($browser);
            $attempts = '[dusk="scheduled-change-attempts-'.$billable->getKey().'"]';
            $payment = '[dusk="scheduled-change-payment-'.$billable->getKey().'"]';
            $browser->visit('/billing/admin/scheduled-changes')->waitFor($payment, 15)
                ->assertSeeIn($attempts, __('billing::admin.scheduled_changes.attempts', ['attempts' => 2, 'max' => 3]))
                ->assertSeeIn($payment, __('billing::admin.scheduled_changes.payment_in_flight', ['payment' => $paymentId]));
            $this->shot($browser, 'Admin: geplanter Wechsel, Zahlung unterwegs');
        });

        // Night three: given up.
        $schedule->apply($billable->fresh());
        $this->setRecurringOutcome('paid');
        $billable->refresh();

        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null, 'given up after the third attempt');
        $this->assertNull($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null, 'nothing waits for the payment any more');
        $this->assertNull($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null);
        $this->assertSame($paymentId, $billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null);
        $this->assertSame($payments + 1, DB::table('fake_mollie_payments')->count());
        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        // Pro with four seats again: 29.00 + 9.90, + 19 %.
        $this->assertSame('46.29', (string) $this->mollieSubscriptionAmount($billable));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15)
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::portal.scheduled_change_dropped', ['plan' => 'Business']))
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::notifications.scheduled_change_not_applied.reasons.attempts_exhausted'))
                ->assertSeeIn('[dusk="scheduled-change-dropped-payment"]', __('billing::portal.scheduled_change_dropped_payment'));
            // Nothing is waiting for a payment, so there is nothing to withdraw.
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="cancelPendingChange"]'));
            $this->assertSame(0, $this->domCount($browser, '[dusk="scheduled-change-retry"]'));
            $this->shot($browser, 'Geplanter Wechsel verworfen: Zahlung wird erstattet');

            $browser->visit('/billing')->waitFor('[dusk="scheduled-change-dropped-payment"]', 15);
        });

        // The money arrives after all — and Mollie reports it twice.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $this->deliverWebhook($paymentId);
        $this->deliverWebhook($paymentId);
        $billable->refresh();

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
        $notes = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->where('id', '>', $invoice->id)->get();
        $refunded = 0;
        foreach (DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->get() as $refund) {
            $refunded += BillingMoney::minorFromMollieAmount((string) $refund->amount_value, 'EUR');
        }

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode(), 'the plan stays');
        $this->assertSame('46.29', (string) $this->mollieSubscriptionAmount($billable));
        $this->assertSame($gross, (int) $invoice->amount_gross, 'the payment is booked for what it was');
        $this->assertCount(1, $notes, 'one credit note');
        $this->assertSame(-$gross, (int) $notes->first()->amount_gross);
        $this->assertSame($gross, $refunded, 'and returned in full');
        $this->assertSame($refunds + 1, DB::table('fake_mollie_refunds')->count(), 'once');
        $this->assertNull($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null);

        $this->browse(function (Browser $browser) use ($billable, $invoice, $notes): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15);
            // Returned: the notice no longer announces it.
            $this->assertSame(0, $this->domCount($browser, '[dusk="scheduled-change-dropped-payment"]'));

            $browser->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross, 'the payment that was not applied');
            $this->assertShownAmount($browser, 'invoice-'.$notes->first()->id.'-gross', (int) $notes->first()->amount_gross, 'its credit note');
            $this->shot($browser, 'Verworfener Wechsel: Zahlung erstattet');
        });
    }

    /**
     * The same, but the payment has already been collected at Mollie when the change is
     * given up — its webhook has not arrived yet. Dropped means dropped: the customer has
     * been told the change is off, so the payment is returned when its webhook comes, not
     * applied.
     */
    public function test_a_scheduled_change_given_up_after_its_payment_was_collected_returns_it_instead_of_applying_it(): void
    {
        $billable = $this->paidSubscriber('pro', 4);
        app(UpdateSubscription::class)->update($billable, ['plan_code' => 'business', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
        $billable->refresh()->forceFill(['scheduled_change_at' => now()->subMinute()])->save();

        $refunds = DB::table('fake_mollie_refunds')->count();
        $schedule = app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class);

        $this->setRecurringOutcome('open');
        $throw = true;
        \Illuminate\Support\Facades\Event::listen(\GraystackIT\MollieBilling\Events\PlanChangePending::class, function () use (&$throw): void {
            if ($throw) {
                $throw = false;

                throw new \RuntimeException('The app listener is down');
            }
        });

        $schedule->apply($billable->fresh());
        $schedule->apply($billable->fresh());
        $this->setRecurringOutcome('paid');
        $paymentId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
        $this->assertNotSame('', $paymentId);

        // Collected at Mollie; the webhook is still on its way when the third night gives up.
        FakeMollie::settle($paymentId, 'paid', 'DE');
        $schedule->apply($billable->fresh());
        $billable->refresh();

        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
        $this->assertNull($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null);
        $this->assertSame('already_settled', $billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15)
                ->assertSeeIn('[dusk="scheduled-change-dropped"]', __('billing::portal.scheduled_change_dropped', ['plan' => 'Business']))
                ->assertSeeIn('[dusk="scheduled-change-dropped-payment"]', __('billing::portal.scheduled_change_dropped_payment'));
            $this->shot($browser, 'Verworfen, Zahlung schon eingezogen');
        });

        // The webhook arrives — twice.
        $this->deliverWebhook($paymentId);
        $this->deliverWebhook($paymentId);
        $billable->refresh();

        $gross = BillingMoney::minorFromMollieAmount((string) FakeMollie::payment($paymentId)['amount_value'], 'EUR');
        $refunded = 0;
        foreach (DB::table('fake_mollie_refunds')->where('payment_id', $paymentId)->get() as $refund) {
            $refunded += BillingMoney::minorFromMollieAmount((string) $refund->amount_value, 'EUR');
        }

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode(), 'not applied');
        $this->assertSame('46.29', (string) $this->mollieSubscriptionAmount($billable));
        $this->assertSame($gross, $refunded, 'returned in full');
        $this->assertSame($refunds + 1, DB::table('fake_mollie_refunds')->count(), 'once');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[dusk="scheduled-change-dropped"]', 15);
            $this->assertSame(0, $this->domCount($browser, '[dusk="scheduled-change-dropped-payment"]'));
            $this->shot($browser, 'Verworfen, Zahlung erstattet');
        });
    }

    /**
     * An active subscriber whose running period was just paid by a renewal at what the
     * test Mollie holds for the configuration.
     *
     * @param  list<string>  $addons
     */
    protected function paidSubscriber(string $plan, int $seats = 0, array $addons = [], ?\Closure $discount = null): TestBillable
    {
        $billable = $this->opsSubscriber($plan, $seats, $addons);
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['used_seats' => 1])])->save();

        if ($discount !== null) {
            $discount($billable->fresh());
        }

        app(PlanWaiver::class)->syncMollie($billable->fresh());
        // The renewal that pays the running period was due yesterday: the package anchors
        // the period to the day Mollie scheduled the charge for.
        DB::table('fake_mollie_subscriptions')
            ->where('id', (string) $billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])
            ->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));

        return $billable->refresh();
    }

    protected function settlePending(TestBillable $billable): void
    {
        $pending = $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null;

        if (is_string($pending) && $pending !== '') {
            $this->deliverWebhook($pending);
        }
    }

    /** `wireSet()` for an anchor that sits in a closed dialog: present, not visible. */
    protected function wireSetAny(Browser $browser, string $anchor, array $properties): Browser
    {
        $this->waitForPresence($browser, $anchor, 15);

        $browser->script(sprintf(
            '(async () => { const el = document.querySelector(%s); const w = window.Livewire.find(el.closest("[wire\\\\:id]").getAttribute("wire:id")); for (const [k, v] of Object.entries(%s)) { await w.set(k, v); } window.__changeWireDone = true; })(); window.__changeWireDone = false;',
            json_encode($anchor),
            json_encode((object) $properties),
        ));

        return $browser->waitUsing(15, 100, fn (): bool => (bool) $browser->script('return window.__changeWireDone === true;')[0])
            ->pause(300);
    }

    /** Call a method on the Livewire component that contains `$anchor`. */
    protected function wireCall(Browser $browser, string $anchor, string $method, mixed ...$arguments): Browser
    {
        $browser->script(sprintf(
            '(async () => { const el = document.querySelector(%s); const w = window.Livewire.find(el.closest("[wire\\\\:id]").getAttribute("wire:id")); await w.call(%s, ...%s); window.__changeWireDone = true; })(); window.__changeWireDone = false;',
            json_encode($anchor),
            json_encode($method),
            json_encode(array_values($arguments)),
        ));

        return $browser->waitUsing(15, 100, fn (): bool => (bool) $browser->script('return window.__changeWireDone === true;')[0])
            ->pause(300);
    }

    /** Read a property of the Livewire component that contains `$anchor`. */
    protected function wireGet(Browser $browser, string $anchor, string $property): mixed
    {
        return $browser->script(sprintf(
            'const el = document.querySelector(%s); return window.Livewire.find(el.closest("[wire\\\\:id]").getAttribute("wire:id")).get(%s);',
            json_encode($anchor),
            json_encode($property),
        ))[0];
    }
}

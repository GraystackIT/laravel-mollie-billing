<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * A billing day on the 31st, held through the screens.
 *
 *  - A code that covers everything for two periods, typed into the dashboard of a
 *    subscription that charges on the 31st. Its periods are the subscription's own
 *    (decided 17 November 2026): 31 January and 28 February are free, Mollie collects
 *    again on 31 March — the dashboard, Mollie's start date and the invoiced periods name
 *    the same days, and the cycle stays on the 31st. In 30-day blocks it was 3 April, and
 *    the customer was charged on the 3rd from then on.
 *  - An upgrade from the free plan on 31 January, paid on the plan screen: first charged
 *    on 28 February, and on the 31st from March. Until now pinned by a feature test only
 *    (RenewalMonthEndCadenceTest).
 *
 * Mollie's own rule after a short month is not known before March 2027; the fake Mollie
 * plays both (`cadence_after_month_end`). Pro 29.00 + 19 % = 34.51 every month.
 */
class CouponWindowMatrixTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;
    use CouponWorkbench;

    /** @return array<string, array{0: string, 1: string}> plan, first charge */
    public static function checkoutPlans(): array
    {
        return [
            // Signed up on 31 January: free 31 Jan, 28 Feb, 31 Mar — first charge 30 April.
            'without a trial' => ['mx-team', '2027-04-30'],
            // Seven-day trial to 7 February, then three free periods: 7 May.
            'after a seven-day trial' => ['mx-trial', '2027-05-07'],
        ];
    }

    /**
     * The checkout's confirm step names the day money is first collected under a code that
     * covers three periods in full — and that is the day Mollie is given and the dashboard
     * shows afterwards.
     */
    #[DataProvider('checkoutPlans')]
    public function test_the_checkout_names_the_first_charge_of_a_code_over_several_periods(string $plan, string $firstCharge): void
    {
        $this->travelSharedClockTo('2027-01-31 10:00:00');
        $this->cmCatalog();
        $billable = $this->cmCustomer('DE');
        $this->coupons()->create([
            'code' => 'FREE-THREE', 'name' => 'Three periods free', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 3,
        ]);
        $shown = Carbon::parse($firstCharge, 'UTC')->translatedFormat('d. M Y');

        $this->browse(function (Browser $browser) use ($billable, $plan, $shown): void {
            $this->cmWalkToConfirm($browser, $billable, $plan);
            $this->cmApplyCoupon($browser, 'FREE-THREE');

            $this->assertShownAmount($browser, 'confirm-total', 0, 'nothing is due today');
            $notice = (string) $browser->script('return document.querySelector(\'[dusk="confirm-first-charge"]\')?.textContent ?? ""')[0];
            $this->assertStringContainsString($shown, $notice, 'the confirm step names the first charge');
            $this->shot($browser, 'Bestätigung: erste Abbuchung '.$shown);

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable = $billable->fresh();
        $this->assertSame($firstCharge, $this->cmSubscriptionStartDate($billable), 'Mollie\'s start date');

        $this->browse(function (Browser $browser) use ($billable, $firstCharge): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Matrix', 15)->pause(500);
            $this->assertNextChargeOnScreen($browser, $firstCharge, 'the dashboard after the checkout');
        });
    }

    /** The plan screen's "first charge on …" for an upgrade under a code over two periods. */
    public function test_the_plan_screen_names_the_first_charge_of_a_code_over_several_periods(): void
    {
        $this->travelSharedClockTo('2027-01-21 10:00:00');
        $this->cmCatalog();
        $billable = $this->cmCustomer('DE', [
            'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Local,
            'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now(),
        ]);
        $this->coupons()->create([
            'code' => 'FREE-TWO-UP', 'name' => 'Two periods free', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 2,
        ]);

        // Upgraded on 31 January: the upgrade's own month and February are free.
        $this->travelSharedClockTo('2027-01-31 10:00:00');
        $shown = Carbon::parse('2027-03-31', 'UTC')->translatedFormat('d. M Y');

        $this->browse(function (Browser $browser) use ($billable, $shown): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'mx-team\')"]', 15);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'mx-team\')"]');
            $browser->pause(1200)->waitFor('input[wire\\:model="couponInput"]', 10);
            $browser->type('couponInput', 'FREE-TWO-UP');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('FREE-TWO-UP', 10)->pause(900);

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);

            $notice = (string) $browser->script('return document.querySelector(\'[dusk="upgrade-first-charge"]\')?.textContent ?? ""')[0];
            $this->assertStringContainsString($shown, $notice, 'the plan screen names the first charge');
            $this->shot($browser, 'Upgrade: erste Abbuchung '.$shown);

            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $this->assertSame('2027-03-31', $this->cmSubscriptionStartDate($billable->fresh()), 'Mollie\'s start date');
    }

    /** @return array<string, array{0: string}> */
    public static function cadences(): array
    {
        return [
            'Mollie counts on from the February charge (drift)' => ['drift'],
            'Mollie counts from its start date (clamp)' => ['clamp'],
        ];
    }

    #[DataProvider('cadences')]
    public function test_a_code_for_two_free_periods_on_a_cycle_on_the_31st_returns_on_the_31st(string $cadence): void
    {
        $this->travelSharedClockTo('2026-12-31 10:00:00');
        WorkbenchSetting::set('cadence_after_month_end', $cadence);

        $billable = $this->makeActiveSubscriber('pro', 'monthly', ['subscription_period_starts_at' => now()]);
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // What CreateSubscription leaves at Mollie for a signup on 31 December.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update([
            'amount_value' => '34.51',
            'start_date' => '2027-01-31',
            'next_payment_date' => '2027-01-31',
        ]);

        $this->coupons()->create([
            'code' => 'FREE-TWO', 'name' => 'Two periods free', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 2,
        ]);

        // Typed in on 10 January: the charges of 31 January and 28 February are free.
        $this->travelSharedClockTo('2027-01-10 12:00:00');

        $this->browse(function (Browser $browser) use ($billable, $cadence): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'FREE-TWO');
            $browser->waitForText('FREE-TWO', 15)->pause(800);

            // The sentence names the day money is next collected, and the block below says the same.
            $this->assertStringContainsString(__('billing::portal.coupon_recurring_applied_free', [
                'code' => 'FREE-TWO',
                'until' => Carbon::parse('2027-03-31', 'UTC')->translatedFormat('d. M Y'),
            ]), (string) $browser->script('return document.body.innerText;')[0], "{$cadence}: the confirmation");
            $this->assertNextChargeOnScreen($browser, '2027-03-31', "{$cadence}: after the code");
            $this->shot($browser, 'Zwei Perioden frei: nächste Abbuchung 31. März');
        });

        $billable = $billable->fresh();
        $this->assertSame('2027-03-31', $this->mollieNextPaymentDate($billable), "{$cadence}: Mollie's start date");
        $this->assertSame('34.51', $this->mollieSubscriptionAmount($billable), "{$cadence}: Mollie holds the full price");
        $this->assertSame('2027-03-31', ChargeDeferral::until($billable)?->toDateString());

        // The free periods turn over on the 31st and its February stand-in, nobody charged.
        foreach (['2027-01-31', '2027-02-01', '2027-02-28', '2027-03-01', '2027-03-30'] as $night) {
            $this->travelSharedClockTo($night.' 02:00:00');
            app()->call([new PrepareUsageOverageJob, 'handle']);
        }

        $billable = $billable->fresh();
        $this->assertSame(['2027-02-28', '2027-03-31'], [
            $billable->getBillingPeriodStartsAt()?->utc()->toDateString(),
            $billable->nextBillingDate()?->utc()->toDateString(),
        ], "{$cadence}: the second free period runs from 28 February to 31 March");
        $this->assertSame(0, BillingInvoice::query()->where('billable_id', $billable->getKey())->where('amount_gross', '>', 0)->count(), 'a free period was charged');

        // charge day => the next one, on the 31st where the month has it.
        $walk = ['2027-03-31' => '2027-04-30', '2027-04-30' => '2027-05-31', '2027-05-31' => '2027-06-30'];

        foreach ($walk as $day => $next) {
            // Where the package moves the next charge of a charged subscription it replaces
            // it (Mollie refuses a new start date then): charge the one the row names.
            $subscriptionId = $this->currentSubscriptionId($billable);

            $this->travelSharedClockTo($day.' 09:05:00');
            $this->assertSame($day, $this->mollieNext($subscriptionId), "{$cadence}: Mollie charges on {$day}");

            $payment = FakeMollie::chargeSubscription($subscriptionId);
            $this->assertSame(200, $this->deliverWebhook($payment['id']));

            $billable = $billable->fresh();
            $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();

            $this->assertSame(3451, (int) $invoice->amount_gross, "{$cadence} {$day}: the full price after the code");
            $this->assertSame([$day, $next], [$invoice->period_start->toDateString(), $invoice->period_end->toDateString()], "{$cadence} {$day}: the invoiced period ends on the next charge");
            $this->assertSame($next, $billable->nextBillingDate()?->utc()->toDateString(), "{$cadence} {$day}: the package's next charge");
            $this->assertSame($next, $this->mollieNext($this->currentSubscriptionId($billable)), "{$cadence} {$day}: Mollie's next charge");

            $this->browse(function (Browser $browser) use ($billable, $next, $cadence, $day): void {
                $this->actAs($browser, $billable, '/billing');
                $browser->waitForText('Pro', 15)->pause(500);
                $this->assertNextChargeOnScreen($browser, $next, "{$cadence}, renewed on {$day}");
            });
        }
    }

    #[DataProvider('cadences')]
    public function test_an_upgrade_from_the_free_plan_on_31_january_stays_on_the_31st(string $cadence): void
    {
        $this->travelSharedClockTo('2027-01-21 10:00:00');
        WorkbenchSetting::set('cadence_after_month_end', $cadence);
        $billable = $this->couponBillableIn('free');
        $this->setIpCountry('DE');

        $this->travelSharedClockTo('2027-01-31 10:00:00');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $target = '[wire\\:click="previewFor(\'pro\')"]';
            $browser->waitFor($target, 15)->pause(400);
            $this->clickWire($browser, $target);
            $browser->pause(1200);

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $this->assertShownAmount($browser, 'upgrade-total', 3451, 'Pro for its first month');

            // Inside a dialog WebDriver does not consider interactable: click it in the page.
            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
            $this->settleAtFakeMollie($browser, 'paid', 'DE');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable = $billable->fresh();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        $this->assertNotSame('', $subscriptionId, 'the upgrade created a Mollie subscription');
        // The upgrade's own month runs to February's last day; Mollie charges there first.
        $this->assertSame('2027-02-28', $this->mollieNext($subscriptionId), "{$cadence}: Mollie's start date");
        $this->assertSame('2027-02-28', $billable->nextBillingDate()?->utc()->toDateString());
        $this->assertDashboard($billable, '2027-02-28', "{$cadence}: after the upgrade on 31 January");

        $walk = ['2027-02-28' => '2027-03-31', '2027-03-31' => '2027-04-30', '2027-04-30' => '2027-05-31'];

        foreach ($walk as $day => $next) {
            // Where the package moves the next charge of a charged subscription it replaces
            // it (Mollie refuses a new start date then): charge the one the row names.
            $subscriptionId = $this->currentSubscriptionId($billable);

            $this->travelSharedClockTo($day.' 09:05:00');
            $this->assertSame($day, $this->mollieNext($subscriptionId), "{$cadence}: Mollie charges on {$day}");

            $payment = FakeMollie::chargeSubscription($subscriptionId);
            $this->assertSame(200, $this->deliverWebhook($payment['id']));

            $billable = $billable->fresh();
            $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();

            $this->assertSame(3451, (int) $invoice->amount_gross, "{$cadence} {$day}: Pro for a month");
            $this->assertSame([$day, $next], [$invoice->period_start->toDateString(), $invoice->period_end->toDateString()], "{$cadence} {$day}: the invoiced period ends on the next charge");
            $this->assertSame($next, $billable->nextBillingDate()?->utc()->toDateString(), "{$cadence} {$day}: the package's next charge");
            $this->assertSame($next, $this->mollieNext($this->currentSubscriptionId($billable)), "{$cadence} {$day}: Mollie's next charge");

            $this->assertDashboard($billable, $next, "{$cadence}, renewed on {$day}");
        }
    }

    private function currentSubscriptionId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
    }

    private function assertDashboard(TestBillable $billable, string $date, string $step): void
    {
        $this->browse(function (Browser $browser) use ($billable, $date, $step): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);
            $this->assertNextChargeOnScreen($browser, $date, $step);
            $this->shot($browser, $step.': nächste Abbuchung '.$date);
        });
    }

    private function assertNextChargeOnScreen(Browser $browser, string $date, string $step): void
    {
        $shown = $this->nextChargeOnScreen($browser);

        $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state'], "{$step}: next-charge block");
        $this->assertSame(Carbon::parse($date, 'UTC')->translatedFormat('d. M Y'), $shown['date'], "{$step}: next charge on screen");
    }

    private function mollieNext(string $subscriptionId): ?string
    {
        $row = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        return $row !== null ? substr((string) $row->next_payment_date, 0, 10) : null;
    }
}

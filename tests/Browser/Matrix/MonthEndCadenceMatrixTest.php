<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * A subscription whose day is the 31st, walked over February on the shared clock.
 *
 * Signed up on 31 December 2026, so Mollie's start date is 31 January. The anchor day is
 * the 31st, clamped to the length of each month: 31 Jan → 28 Feb → 31 Mar → 30 Apr →
 * 31 May. Mollie's own rule after February is not known before March 2027 — it may count
 * on from 28 February (28 Mar, "drift") or from its start date (31 Mar, "clamp"); the fake
 * Mollie plays either (`cadence_after_month_end`). Whichever it is, the package keeps the
 * charge on the anchor day, and every period ends exactly where Mollie charges: the
 * dashboard's next charge, the invoice's period and Mollie's next payment date are the same
 * day at every step.
 *
 * Pro 29.00 + 19 % = 34.51 every month.
 */
class MonthEndCadenceMatrixTest extends BrowserTestCase
{
    /** @return array<string, array{0: string}> */
    public static function cadences(): array
    {
        return [
            'Mollie counts on from the February charge (drift)' => ['drift'],
            'Mollie counts from its start date (clamp)' => ['clamp'],
        ];
    }

    #[DataProvider('cadences')]
    public function test_a_cycle_on_the_31st_keeps_its_day_over_february(string $cadence): void
    {
        $this->travelSharedClockTo('2026-12-31 10:00:00');
        WorkbenchSetting::set('cadence_after_month_end', $cadence);

        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'subscription_period_starts_at' => now(),
        ]);
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // What CreateSubscription leaves at Mollie for a signup on the 31st.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update([
            'amount_value' => '34.51',
            'start_date' => '2027-01-31',
            'next_payment_date' => '2027-01-31',
        ]);

        $this->assertDashboard($billable, '2027-01-31', 'after the signup on 31 Dec');

        // charge day => [period end, next charge Mollie holds afterwards]
        $walk = [
            '2027-01-31' => '2027-02-28',
            '2027-02-28' => '2027-03-31',
            '2027-03-31' => '2027-04-30',
            '2027-04-30' => '2027-05-31',
            '2027-05-31' => '2027-06-30',
        ];

        $replaced = [];

        foreach ($walk as $day => $next) {
            // Mollie refuses a new start date on a charged subscription, so where the
            // package has to move the next charge it replaces the subscription
            // (MollieSubscriptionReplacer): the one to charge is the one the row names.
            $subscriptionId = (string) $billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'];

            $this->travelSharedClockTo($day.' 09:05:00');
            $this->assertSame($day, $this->mollieNext($subscriptionId), "{$cadence}: Mollie charges on {$day}");

            $payment = FakeMollie::chargeSubscription($subscriptionId);
            $this->assertSame(200, $this->deliverWebhook($payment['id']));

            $billable = $billable->fresh();
            $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();

            $this->assertSame(3451, (int) $invoice->amount_gross, "{$cadence} {$day}: Pro for a month");
            $this->assertSame([$day, $next], [$invoice->period_start->toDateString(), $invoice->period_end->toDateString()], "{$cadence} {$day}: the invoiced period ends on the next charge");
            $this->assertSame($next, $billable->nextBillingDate()?->utc()->toDateString(), "{$cadence} {$day}: the package's next charge");
            $current = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
            $this->assertSame($next, $this->mollieNext($current), "{$cadence} {$day}: Mollie's next charge");

            if ($current !== $subscriptionId) {
                $replaced[] = $day;
                $this->assertNotNull(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('canceled_at'), "{$cadence} {$day}: the replaced subscription is cancelled");
            }

            $this->assertDashboard($billable, $next, "{$cadence}, renewed on {$day}");
        }

        // Under "clamp" Mollie returns to the 31st by itself: nothing replaced. Under
        // "drift" after every month shorter than the 31st — the charges of 28 Feb and 30 Apr.
        $this->assertSame($cadence === 'clamp' ? [] : ['2027-02-28', '2027-04-30'], $replaced, "{$cadence}: replacements");
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->where('customer_id', $billable->fresh()->getMollieCustomerId())->whereNull('canceled_at')->count(), "{$cadence}: one subscription charges");
    }

    /** The dashboard names `$date` as the next charge, and the period that runs up to it. */
    private function assertDashboard(TestBillable $billable, string $date, string $step): void
    {
        $this->browse(function (Browser $browser) use ($billable, $date, $step): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);

            $shown = (array) $browser->script(<<<'JS'
                const box = document.querySelector('[data-next-charge]');
                const pick = (sel) => { const n = box ? box.querySelector(sel) : null; return n ? n.textContent.trim() : null; };
                return {state: box ? box.getAttribute('data-next-charge') : null, date: pick('[data-next-charge-date]')};
            JS)[0];

            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state'], "{$step}: next-charge block");
            $this->assertSame(Carbon::parse($date, 'UTC')->translatedFormat('d. M Y'), $shown['date'], "{$step}: next charge on screen");
            $browser->assertSee(Carbon::parse($date, 'UTC')->translatedFormat('d. M Y'));
            $this->shot($browser, $step.': nächste Abbuchung '.$date);
        });
    }

    private function mollieNext(string $subscriptionId): ?string
    {
        $row = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        return $row !== null ? substr((string) $row->next_payment_date, 0, 10) : null;
    }
}

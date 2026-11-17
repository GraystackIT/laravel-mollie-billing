<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A gifted plan with paid extras, walked month by month to the end of the gift — on the
 * shared clock, so every renewal happens on its own day and every portal page is rendered
 * on that day too.
 *
 * The tenant is given Pro for 90 days on 10 January 2027 at 10:00 UTC: the gift ends on
 * 10 April. On the day of the gift they buy Softdrinks; on 25 January one extra seat.
 * Mollie charges on the 10th of each month.
 *
 * Prices (workbench catalog, German consumer, 19 %): Pro 29.00, three seats included and
 * 9.90 for each further one, Softdrinks 4.90.
 *
 * - Softdrinks for a month, the plan waived: 4.90 net → 0.93 VAT → **5.83**.
 * - The seat on 25 January: the period 10 Jan – 10 Feb has 31 days, 16 of them are left
 *   (25 Jan → 10 Feb, whole days). 9.90 × 16/31 = 5.1097 → 5.11 net → 0.97 VAT → **6.08**.
 * - A renewal while the gift runs: Pro 29.00 + Softdrinks 4.90 + seat 9.90 = 43.80, the
 *   plan taken off → 14.80 net → 2.81 VAT → **17.61**.
 * - A renewal at the full price: 43.80 net; VAT per line 5.51 + 0.93 + 1.88 = 8.32 →
 *   **52.12**.
 *
 * What the docs promise (docs/coupons.md, "A gifted plan with paid extras"; the decision
 * "What happens when a gift ends is decided when it is given"): a renewal is plan-free when
 * it falls before the gift's end — 10 February and 10 March are, 10 April is not.
 * `end`: the renewal that buys the last covered period (10 March) cancels the subscription
 * to the end of that period, 10 April; nothing is charged for the plan, the extras end with
 * it. `continue_paid`: Mollie is moved to the full price for 10 April, and that renewal and
 * every one after it is charged in full.
 */
class GiftLongRunMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    /** The VAT situation of the run: `DE` consumer 19 %, or `RC` Dutch business, reverse charge. */
    private string $vatCase = 'DE';

    /**
     * Each ending, for a German consumer on UTC; a Dutch business with a VIES-valid number
     * (reverse charge, 0 %); and a German consumer whose portal is set to New York with
     * Mollie charging at the hour it really does (00:28 UTC — the evening before, in New
     * York).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function endings(): array
    {
        $cases = [];

        foreach ([PlanWaiver::ON_EXPIRY_END, PlanWaiver::ON_EXPIRY_CONTINUE_PAID] as $onExpiry) {
            $cases["{$onExpiry} × DE × UTC"] = [$onExpiry, 'DE', 'UTC'];
            $cases["{$onExpiry} × reverse charge × UTC"] = [$onExpiry, 'RC', 'UTC'];
            $cases["{$onExpiry} × DE × New York, charged 00:28 UTC"] = [$onExpiry, 'DE', 'America/New_York'];
        }

        return $cases;
    }

    #[DataProvider('endings')]
    public function test_a_gift_with_a_paid_addon_and_a_paid_seat_runs_to_its_end(string $onExpiry, string $vatCase, string $timezone): void
    {
        $this->vatCase = $vatCase;

        // ── 10 January: the gift, and Softdrinks bought on top ──────────────────
        $this->travelSharedClockTo('2027-01-10 10:00:00');

        if ($timezone !== 'UTC') {
            $this->serverConfig('mollie-billing.billing_timezone', $timezone);
            \Workbench\App\Support\WorkbenchSetting::set('recurring_charge_time', '00:28');
        }

        $billable = $this->giftee();
        $coupon = $this->coupons()->accessGrantCoupon('GIFT-LR-'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', [], 90, $onExpiry, 1);
        $this->coupons()->redeem($coupon, $billable, []);
        $billable = $billable->fresh();

        $this->assertSame('2027-04-10', $billable->getBillingSubscriptionEndsAt()?->toDateString(), 'the gift ends on 10 April');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 15)->pause(400);

            $this->confirmInModal($browser, 'enable-addon-softdrinks', '[wire\\:click="enableAddon(\'softdrinks\')"]');
            $this->settleAtFakeMollie($browser, 'paid', $this->country());
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(500);
            $this->assertPortal($browser, $billable, '2027-02-10', $this->gross(583, 490), waived: true, step: '10 Jan, Softdrinks bought');
            $this->shot($browser, '10. Jan: Geschenk + Softdrinks');
        });

        $billable = $billable->fresh();
        $purchase = $this->invoiceNumber(1);
        $this->assertSame([490, $this->gross(583, 490)], [(int) $purchase->amount_net, (int) $purchase->amount_gross], 'the purchase is the addon only');
        $this->assertSame(-2900, $this->waivedOn($purchase), 'the purchase shows the plan and takes it off');
        $this->assertSame($this->held(583, 490), $this->latestFakePayment()['amount_value']);
        $this->assertSame($this->held(583, 490), $this->mollieSubscriptionAmount($billable));
        $this->assertSame('2027-02-10', $this->mollieNextPaymentDate($billable));
        $this->assertNotNull(app(PlanWaiver::class)->marker($billable), 'the gift lives on as a waiver');

        // ── 25 January: one extra seat ─────────────────────────────────────────
        $this->travelSharedClockTo('2027-01-25 10:00:00');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->shot($browser, '25. Jan: ein Sitz dazu');
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');

            $browser->waitUsing(15, 200, fn (): bool => $this->latestFakePayment()['sequence_type'] === 'recurring',
                'Waited for the prorata charge of the seat to appear at Mollie.');
        });

        $this->deliverWebhook($this->latestFakePayment()['id']);

        $billable = $billable->fresh();
        $seat = $this->invoiceNumber(2);
        $this->assertSame([511, $this->gross(608, 511)], [(int) $seat->amount_net, (int) $seat->amount_gross], 'the seat for 16 of 31 days');
        $this->assertSame($this->held(608, 511), $this->latestFakePayment()['amount_value']);
        $this->assertSame(1, $billable->getExtraBillingSeats());
        $this->assertSame($this->held(1761, 1480), $this->mollieSubscriptionAmount($billable), 'Mollie holds the extras for 10 February');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);
            $this->assertPortal($browser, $billable, '2027-02-10', $this->gross(1761, 1480), waived: true, step: '25 Jan, seat bought');
            $this->shot($browser, '25. Jan: nächste Abbuchung nur die Extras');
        });

        // ── 10 February and 10 March: renewals inside the gift ─────────────────
        foreach (['2027-02-10' => '2027-03-10', '2027-03-10' => '2027-04-10'] as $day => $next) {
            $this->travelSharedClockTo($day.' 09:05:00');
            $this->assertSame($day, $this->mollieNextPaymentDate($billable), "Mollie's schedule on {$day}");

            $this->renew($billable);

            $billable = $billable->fresh();
            $renewal = BillingInvoice::query()->latest('id')->first();

            $this->assertSame([1480, $this->gross(1761, 1480)], [(int) $renewal->amount_net, (int) $renewal->amount_gross], "renewal {$day}: the extras only");
            $this->assertSame($this->held(1761, 1480), $this->latestFakePayment()['amount_value'], "renewal {$day}: collected");
            $this->assertSame(-2900, $this->waivedOn($renewal), "renewal {$day}: the plan is shown and taken off");
            $this->assertSame([$day, $next], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()], "renewal {$day}: period");
            $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'adjustment'), "renewal {$day}: no adjustment line");

            if ($day === '2027-02-10') {
                $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());

                $this->browse(function (Browser $browser) use ($billable): void {
                    $this->actAs($browser, $billable, '/billing');
                    $browser->waitForText('Pro', 15)->pause(500);
                    $this->assertPortal($browser, $billable, '2027-03-10', $this->gross(1761, 1480), waived: true, step: '10 Feb, renewed');
                    $this->shot($browser, '10. Feb: verlängert, Plan frei');
                });
            }
        }

        // ── 10 March: the renewal that buys the last period the gift covers ─────
        if ($onExpiry === PlanWaiver::ON_EXPIRY_END) {
            $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus(), '10 Mar: the gift ends the subscription');
            $this->assertSame('2027-04-10', $billable->getBillingSubscriptionEndsAt()?->toDateString(), '10 Mar: to the end of the period just paid');
            $this->assertSame(0, DB::table('fake_mollie_subscriptions')->whereNull('canceled_at')->where('status', 'active')->count(), '10 Mar: Mollie keeps nothing that could charge the plan');

            $this->browse(function (Browser $browser) use ($billable): void {
                $this->actAs($browser, $billable, '/billing');
                $browser->waitForText('Pro', 15)->pause(500);
                $shown = $this->nextChargeOnScreen($browser);

                $this->assertSame(NextChargeSummary::STATE_ENDING, $shown['state'], '10 Mar: next-charge block');
                $this->assertStringContainsString(__('billing::portal.next_charge.ending_on', ['date' => $this->onScreen('2027-04-10 12:00:00', $billable)]), $shown['text']);
                $browser->visit('/_wb/app')->waitForText('APP-AREA-OK', 10);
                $this->shot($browser, '10. Mär: letzte bezahlte Periode, Abo endet am 10. Apr');
            });

            // 10 April: nothing to collect. The nightly pass after the end expires it.
            $this->travelSharedClockTo('2027-04-10 23:30:00');
            PrepareUsageOverageJob::dispatchSync();
            $billable = $billable->fresh();

            $this->assertSame(SubscriptionStatus::Expired, $billable->getBillingSubscriptionStatus(), '10 Apr: expired with the gift');
            $this->assertFalse($billable->hasAccessibleBillingSubscription(), '10 Apr: the extras end with it');
            $this->assertSame(4, DB::table('fake_mollie_payments')->count(), 'nothing was collected after 10 March');

            $this->browse(function (Browser $browser) use ($billable): void {
                $this->actAs($browser, $billable, '/billing');
                $browser->pause(800);
                $this->assertNotSame(NextChargeSummary::STATE_SCHEDULED, $this->nextChargeOnScreen($browser)['state'], '10 Apr: no charge announced');
                $this->shot($browser, '10. Apr: Geschenk vorbei, Abo abgelaufen');

                $browser->visit('/_wb/app')->pause(800);
                $this->assertStringNotContainsString('APP-AREA-OK', $browser->driver->getPageSource(), '10 Apr: the application is locked');
            });

            $this->assertInvoicesPage($billable, [$this->gross(583, 490), $this->gross(608, 511), $this->gross(1761, 1480), $this->gross(1761, 1480)]);

            return;
        }

        // continue_paid: Mollie already holds the full price for 10 April.
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertSame($this->held(5212, 4380), $this->mollieSubscriptionAmount($billable), '10 Mar: Mollie moved to the full price');
        $this->assertSame('2027-04-10', $this->mollieNextPaymentDate($billable));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);
            $this->assertPortal($browser, $billable, '2027-04-10', $this->gross(5212, 4380), waived: false, step: '10 Mar, last free period');
            $this->shot($browser, '10. Mär: ab 10. Apr voller Preis');
        });

        // ── 10 April and 10 May: the plan is charged ──────────────────────────
        foreach (['2027-04-10' => '2027-05-10', '2027-05-10' => '2027-06-10'] as $day => $next) {
            $this->travelSharedClockTo($day.' 09:05:00');
            $this->renew($billable);

            $billable = $billable->fresh();
            $renewal = BillingInvoice::query()->latest('id')->first();

            $this->assertSame([4380, $this->gross(5212, 4380)], [(int) $renewal->amount_net, (int) $renewal->amount_gross], "renewal {$day}: the full price");
            $this->assertSame($this->held(5212, 4380), $this->latestFakePayment()['amount_value'], "renewal {$day}: collected");
            $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'coupon'), "renewal {$day}: nothing waived");
            $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'adjustment'), "renewal {$day}: no adjustment line");
            $this->assertSame([$day, $next], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()], "renewal {$day}: period");
            $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
            $this->assertNull(app(PlanWaiver::class)->marker($billable), "renewal {$day}: the waiver is gone");

            $this->browse(function (Browser $browser) use ($billable, $day, $next): void {
                $this->actAs($browser, $billable, '/billing');
                $browser->waitForText('Pro', 15)->pause(500);
                $this->assertPortal($browser, $billable, $next, $this->gross(5212, 4380), waived: false, step: "{$day}, paid renewal");
                $browser->assertDontSee(__('billing::portal.plan_waiver_notice_continue_paid', ['date' => $this->onScreen('2027-04-10 10:00:00', $billable)]));
                $this->shot($browser, "{$day}: voller Preis");
            });
        }

        $this->assertInvoicesPage($billable, [$this->gross(583, 490), $this->gross(608, 511), $this->gross(1761, 1480), $this->gross(1761, 1480), $this->gross(5212, 4380), $this->gross(5212, 4380)]);
    }

    /**
     * The shared clock is per test: whatever a test travelled to, the next one — and the
     * server it talks to — starts on the machine's time again. And while a test has
     * travelled, the server's "now" is the travelled one.
     */
    public function test_the_shared_clock_reaches_the_server_and_is_reset_for_every_test(): void
    {
        $real = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->browse(function (Browser $browser) use ($real): void {
            $clock = $this->serverClock($browser);
            $this->assertFalse($clock['travelled'], 'a test starts on the machine clock');
            $this->assertLessThan(300, abs(Carbon::parse($clock['now'])->getTimestamp() - $real->getTimestamp()));

            $this->travelSharedClockTo('2031-06-15 08:00:00');

            $clock = $this->serverClock($browser);
            $this->assertTrue($clock['travelled']);
            $this->assertSame('2031-06-15', substr($clock['now'], 0, 10));
            $this->assertLessThan(300, Carbon::parse($clock['now'])->getTimestamp() - Carbon::parse('2031-06-15 08:00:00', 'UTC')->getTimestamp(), 'the clock runs on from the instant travelled to');
            $this->assertSame('2031-06-15', now()->toDateString(), 'and the test process travelled with it');
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * What the dashboard says about the next charge, held against figures this test
     * computed — not against what the package computes.
     */
    private function assertPortal(Browser $browser, TestBillable $billable, string $date, int $gross, bool $waived, string $step): void
    {
        $billable = $billable->fresh();
        $shown = $this->nextChargeOnScreen($browser);
        $normalise = static fn (?string $value): string => str_replace([' ', "\u{00a0}", "\u{202f}"], '', (string) $value);

        $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state'], "{$step}: next-charge block");
        $this->assertSame($this->onScreen($date.' 09:00:00', $billable), $shown['date'], "{$step}: date of the next charge");
        $this->assertSame($normalise(BillingMoney::format($gross)), $normalise($shown['gross']), "{$step}: amount of the next charge");

        $freeUntil = __('billing::portal.next_charge.plan_free_until', ['date' => $this->onScreen('2027-04-10 10:00:00', $billable)]);

        if ($waived) {
            $this->assertStringContainsString($freeUntil, $shown['text'], "{$step}: the plan is free until the gift ends");
            $this->assertStringContainsString($normalise(BillingMoney::format(2900)), $normalise($shown['text']), "{$step}: what is taken off");
            $browser->assertSee(__('billing::portal.plan_waiver_notice_'.app(PlanWaiver::class)->marker($billable)['on_expiry'], ['date' => $this->onScreen('2027-04-10 10:00:00', $billable)]));
        } else {
            $this->assertStringNotContainsString($freeUntil, $shown['text'], "{$step}: nothing is free any more");
        }

        // And the books agree with the screen: the amount Mollie holds is the one shown.
        $this->assertNextChargeMatchesBooks($browser, $billable, $step);
    }

    /**
     * A billing day as the portal prints it: the calendar day Mollie charges on, the same
     * in every timezone (a day is not an instant — BillingTime::billingDay()).
     */
    private function onScreen(string $utc, TestBillable $billable): string
    {
        return Carbon::parse($utc, 'UTC')->translatedFormat('d. M Y');
    }

    /** The tenant who is given the plan, with the billing details of the run's VAT case. */
    private function giftee(): TestBillable
    {
        if ($this->vatCase !== 'RC') {
            return $this->couponBillableIn('none');
        }

        $billable = $this->makeBillable([
            'billing_street' => 'Keizersgracht 1',
            'billing_postal_code' => '1015 CJ',
            'billing_city' => 'Amsterdam',
            'billing_country' => 'NL',
            'tax_country_user' => 'NL',
            'tax_country_ip' => 'NL',
            'vat_number' => 'NL863726392B01',
        ]);

        \GraystackIT\MollieBilling\Models\BillingVatValidation::query()->create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'vat_number' => 'NL863726392B01',
            'country_code' => 'NL',
            'valid' => true,
            'checked_at' => now(),
        ]);

        $this->setIpCountry('NL');

        return $billable->refresh();
    }

    private function country(): string
    {
        return $this->vatCase === 'RC' ? 'NL' : 'DE';
    }

    /** Gross of a document: with 19 % German VAT as computed in the class comment, or the net under reverse charge. */
    private function gross(int $germanGross, int $net): int
    {
        return $this->vatCase === 'RC' ? $net : $germanGross;
    }

    /** The same as the amount Mollie holds or collects, e.g. "17.61". */
    private function held(int $germanGross, int $net): string
    {
        return number_format($this->gross($germanGross, $net) / 100, 2, '.', '');
    }

    /** Mollie's scheduler: charge on the scheduled day, the card country of the run, the webhook. */
    private function renew(TestBillable $billable): void
    {
        $subscriptionId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $payment = \Workbench\App\FakeMollie\FakeMollie::chargeSubscription($subscriptionId);

        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update(['country_code' => $this->country()]);

        $this->deliverWebhook($payment['id']);
    }

    private function serverConfig(string $key, mixed $value): void
    {
        \Workbench\App\Support\WorkbenchSetting::set('config.'.$key, json_encode($value));
        config([$key => $value]);
    }

    private function invoiceNumber(int $n): BillingInvoice
    {
        return BillingInvoice::query()->orderBy('id')->skip($n - 1)->firstOrFail();
    }

    private function waivedOn(BillingInvoice $invoice): int
    {
        return (int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net');
    }

    /**
     * The customer's invoice list shows exactly the documents the run produced, in this
     * order, each with the gross this test expects.
     *
     * @param  array<int, int>  $grosses
     */
    private function assertInvoicesPage(TestBillable $billable, array $grosses): void
    {
        $invoices = BillingInvoice::query()->orderBy('id')->get();

        $this->assertSame($grosses, $invoices->map(fn (BillingInvoice $i): int => (int) $i->amount_gross)->all(), 'the invoices of the run');

        $this->browse(function (Browser $browser) use ($billable, $invoices): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoices->first()->id.'"]', 15)->pause(400);

            foreach ($invoices as $invoice) {
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross, 'invoice '.$invoice->id.' on the invoices page');
            }

            $this->shot($browser, 'Rechnungen des ganzen Laufs');
        });
    }

    /** @return array{now: string, travelled: bool} */
    private function serverClock(Browser $browser): array
    {
        $browser->visit('/_wb/clock');

        return (array) json_decode((string) $browser->script('return document.body.innerText;')[0], true);
    }
}

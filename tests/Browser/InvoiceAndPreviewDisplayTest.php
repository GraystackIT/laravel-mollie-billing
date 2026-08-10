<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Laravel\Dusk\Browser;

/**
 * What the numbers on screen say, against what the books hold.
 *
 * Every figure here is asserted through `BillingMoney::format()` on the persisted value
 * rather than against a literal, so the test fails when the display and the ledger disagree
 * — which is the only failure mode a browser can catch and a feature test cannot. A wrong
 * amount in the database is a bug the feature suite finds; a right amount rendered wrongly,
 * rounded twice, or left stale after a Livewire update only shows up here.
 *
 * `BillingDataTest` already covers that the archive lists documents and serves their PDFs.
 * This is about the amounts in those rows, the summary tiles above them, and the previews the
 * customer approves before any of it happens.
 */
class InvoiceAndPreviewDisplayTest extends BrowserTestCase
{
    /**
     * Every row, every column: net, VAT and gross for a renewal, a pro-rata charge and a
     * credit note — the credit note negative, since a customer reading "€2.38" where money
     * came back has no way to tell a refund from a charge.
     */
    public function test_the_invoice_archive_prints_the_amounts_the_books_hold(): void
    {
        $billable = $this->makeActiveSubscriber('starter');

        // A renewal, and a second one so the tiles have something to sum.
        $this->renewSubscription($billable);
        $this->renewSubscription($billable->refresh());

        // And a partial refund of the first, which is where a sign error would show.
        $first = BillingInvoice::query()->orderBy('id')->first();
        app(RefundInvoiceService::class)->refund($first, [
            'amount_net' => 300,
            'reason_code' => RefundReasonCode::Goodwill,
            'notify_user' => false,
        ]);

        $invoices = BillingInvoice::query()->orderBy('id')->get();

        expect($invoices)->toHaveCount(3)
            ->and($invoices->where('invoice_kind', InvoiceKind::Refund))->toHaveCount(1);

        $this->browse(function (Browser $browser) use ($billable, $invoices): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="stats-paid"]', 15)->pause(400);

            foreach ($invoices as $invoice) {
                $browser->assertPresent('[dusk="invoice-row-'.$invoice->id.'"]');

                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-net', (int) $invoice->amount_net,
                    'net of invoice '.$invoice->serial_number);
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-vat', (int) $invoice->amount_vat,
                    'VAT of invoice '.$invoice->serial_number);
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross,
                    'gross of invoice '.$invoice->serial_number);

                // Net + VAT = gross has to hold on screen, not only in the row it came from:
                // three independently formatted numbers that do not add up are what a customer
                // photographs and sends to their accountant.
                expect((int) $invoice->amount_net + (int) $invoice->amount_vat)
                    ->toBe((int) $invoice->amount_gross, 'invoice '.$invoice->serial_number.' does not add up');
            }

            // A credit note is rendered as the negative it is.
            $credit = $invoices->firstWhere('invoice_kind', InvoiceKind::Refund);
            expect((int) $credit->amount_gross)->toBeLessThan(0)
                ->and($browser->text('[dusk="invoice-'.$credit->id.'-gross"]'))->toContain('-');

            $this->shot($browser, 'Rechnungsarchiv: jede Zeile stimmt mit den Büchern');

            // The tiles are the same money aggregated: charges positive, credits as an
            // absolute figure, spent as the balance of the two.
            $paid = (int) $invoices->where('amount_gross', '>', 0)->sum('amount_gross');
            $credits = abs((int) $invoices->where('amount_gross', '<', 0)->sum('amount_gross'));

            $this->assertShownAmount($browser, 'stats-paid', $paid, 'total paid tile');
            $this->assertShownAmount($browser, 'stats-credits', $credits, 'credits tile');
            $this->assertShownAmount($browser, 'stats-spent', $paid - $credits, 'spent tile');
        });
    }

    /**
     * A reverse-charged invoice: net equals gross and the VAT column is a zero, not a blank
     * and not 19 % of anything. This is the case a B2B customer forwards to their tax
     * advisor, so it has to be unambiguous on screen.
     */
    public function test_a_reverse_charged_invoice_shows_no_vat(): void
    {
        // The seller sits in AT, so a VIES-valid DE business is a cross-border B2B supply at
        // 0 % under Art. 196. The number has to be validated the way a customer validates it
        // — through the form — because it is the stored validation that turns the VAT off.
        $billable = $this->makeActiveSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/billing-data');
            $browser->waitFor('input[name="vat_number"]', 15)->pause(400);

            // DE129273398 is on the fake VIES allow-list. Wait for the verdict icon rather
            // than a stopwatch — the input is debounced and then asks VIES.
            $browser->type('vat_number', 'DE129273398');
            $this->waitForPresence($browser, '.text-emerald-700, .dark\\:text-emerald-400', 15);
            $this->clickWire($browser, '[wire\\:click="save"]');

            // Wait for what the assertion below actually depends on, not for a stopwatch: it is
            // the *persisted* VIES validation that turns the VAT off, and `save()` writes the
            // billable and that validation in one transaction. A blank `pause()` here moved on
            // regardless — including when the write had been rolled back by a SQLite lock, which
            // then showed up as an invoice carrying 19 % and read like a VAT bug.
            $browser->waitUsing(15, 200, function () use ($billable): bool {
                return $billable->fresh()?->currentVatValidation() !== null;
            }, 'Waited for the VIES validation to be persisted by the billing-data save.');
        });

        $this->renewSubscription($billable->refresh(), '9.90');

        $invoice = BillingInvoice::query()->latest('id')->firstOrFail();

        expect((int) $invoice->amount_vat)->toBe(0)
            ->and((int) $invoice->amount_net)->toBe((int) $invoice->amount_gross);

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(300);

            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-net', (int) $invoice->amount_net);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-vat', 0, 'VAT column on a reverse charge');
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross);

            $this->shot($browser, 'Reverse Charge: keine USt. in der Liste');
        });
    }

    /**
     * The pro-rata preview on a downgrade. The customer is being told what comes back and
     * what is charged, and both have to be the composer's own figures — a preview that
     * disagrees with the executor is worse than no preview.
     */
    public function test_the_plan_change_preview_matches_what_will_be_booked(): void
    {
        $billable = $this->makeActiveSubscriber('business');

        // A renewal so the period has a paid invoice to credit against.
        $this->renewSubscription($billable, '117.81');
        $billable->refresh();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');

            $browser->waitForText('Starter', 15);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');
            $browser->waitFor('[dusk="prorata-total"]', 15)->pause(600);

            $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
                ->previewUpdate($billable->fresh(), ['plan_code' => 'starter']);

            $chargeGross = 0;
            $refundGross = 0;
            foreach ((array) ($preview['prorataLines'] ?? []) as $line) {
                if (($line['direction'] ?? 'charge') === 'refund') {
                    $refundGross += (int) $line['amount_gross'];
                } else {
                    $chargeGross += (int) $line['amount_gross'];
                }
            }

            // A downgrade produces both: the new plan for the remaining days, and a credit for
            // the days of the old one that were paid and will not be used.
            expect($chargeGross)->toBeGreaterThan(0)
                ->and($refundGross)->toBeLessThan(0);

            $isSidegrade = ($chargeGross + $refundGross) === 0;
            $expected = ($isSidegrade ? 0 : max(0, $chargeGross))
                + (int) ($preview['usageOverageChargeGross'] ?? 0);

            $this->assertShownAmount($browser, 'prorata-total', $expected, 'the pro-rata total on screen');

            // The credit is stated separately rather than netted into that total — the charge
            // is what leaves the card today, the refund arrives on its own.
            $browser->assertSee(__('billing::portal.prorata_refund_separate', [
                'amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(abs($refundGross)),
            ]));

            $this->shot($browser, 'Plan-Wechsel-Vorschau: Zahlen wie die Buchung');
        });
    }

    /**
     * The seat preview. Same rule, different screen: what is added up on the right has to be
     * the price the seats actually cost.
     */
    public function test_the_seat_preview_prices_the_seats_it_will_charge(): void
    {
        $billable = $this->makeActiveSubscriber('pro');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');

            $browser->waitFor('[wire\:click="increment"]', 15)->pause(400);

            // Pro includes 3 seats; two more at the plan's seat price.
            $this->clickWire($browser, '[wire\:click="increment"]');
            $browser->pause(700);
            $this->clickWire($browser, '[wire\:click="increment"]');
            $browser->waitFor('[dusk="seats-extra-cost"]', 10)->pause(400);

            $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
            $extra = 5 - (int) $catalog->includedSeats('pro');
            $seatNet = (int) $catalog->seatPriceNet('pro', 'monthly');

            // A B2C customer is quoted gross here — deliberately, since that is what leaves
            // their account — so the per-seat price, the line total and the sum have to be the
            // same kind of money. Mixing them showed "2 extra × €11.78" next to a total of
            // €19.80, a multiplication the customer can see does not work.
            $seatGross = (int) app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class)
                ->calculate('DE', $seatNet, $billable->fresh())['gross'];

            expect($browser->text('[dusk="seats-extra-cost"]'))->not->toBeEmpty();

            $this->assertShownAmount($browser, 'seats-extra-cost', $extra * $seatGross, 'extra seat cost');
            $this->assertShownAmount($browser, 'seats-total', $extra * $seatGross, 'seat total');

            // The per-unit price on the same line, so the arithmetic on screen closes.
            $browser->assertSee(\GraystackIT\MollieBilling\Support\BillingMoney::format($seatGross));

            $this->shot($browser, 'Sitzplatz-Vorschau: 2 zusätzliche Plätze');
        });
    }

    /**
     * The usage list. Meters and the history table are the only place a customer can check a
     * metered bill, so a stale meter is a billing dispute.
     */
    public function test_the_usage_page_shows_the_balance_the_wallet_holds(): void
    {
        $billable = $this->makeActiveSubscriber('starter');

        // Spend 30 of the 50 included tokens.
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
            ->debit($billable, 'Tokens', 30);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');

            $browser->waitForText('Tokens', 15)->pause(400);

            // 20 of 50 left, and the SMS wallet untouched at 20 of 20.
            $browser->assertSee(__('billing::portal.usage_plan_remaining', ['remaining' => '20', 'total' => '50']))
                ->assertSee(__('billing::portal.usage_plan_remaining', ['remaining' => '20', 'total' => '20']));

            $this->shot($browser, 'Verbrauchsseite: Stand wie im Wallet');
        });

        expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(20);
    }
}

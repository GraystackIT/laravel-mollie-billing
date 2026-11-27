<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceArchiveService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * A country correction off the straight way — the invoices a mismatch corrects are not
 * always one fresh renewal:
 *
 *  - an invoice already partly refunded by line before the mismatch (35);
 *  - an overage invoice in the linked set, resolved by an operator (36);
 *  - the correction's reissue refunded in full from the admin invoice tab (37);
 *  - two linked invoices, so two corrections whose "re-charge pending" callout has to
 *    go once both are booked — and stay gone (38);
 *  - the invoice list's ZIP export over a range holding an invoice, its correction credit
 *    note and the reissue (39).
 *
 * The rule behind every figure (docs/decisions.md, "A country correction re-issues what
 * remained paid, and moves no units"): the correction refunds what is left of each linked
 * invoice at the old rate, charges the same remainder again at the new rate, and never
 * moves wallet units. Every scenario reads the screen AND the books and the fake Mollie.
 *
 * Countries throughout: declared DE (19 %), card and IP NL (21 %).
 */
class CountryCorrectionDeepTest extends BrowserTestCase
{
    use OperationsWorkbench;

    private const LINE_SELECT = 'select[wire\\:model\\.live="refundLine"]';

    private const AMOUNT = 'input[wire\\:model="refundAmount"]';

    private const SUBMIT = 'form[wire\\:submit="refund"] button[type="submit"]';

    private const REFUND_FULL = '[wire\\:click="refundFull"]';

    private const RECHARGE_PENDING = '[dusk="country-recharge-pending"]';

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }

    // ── 35. A partly refunded invoice in the linked set ─────────────────────

    /**
     * Pro + Softdrinks, DE: 29.00 + 4.90 = 33.90 net, VAT 5.51 + 0.93, paid 40.34.
     *
     * The operator refunds the Softdrinks line (4.90 net, 5.83 gross). A renewal from a
     * Dutch card then flags the mismatch, and the customer resolves it to NL. The first
     * invoice has 29.00 net left: refunded 34.51 at 19 %, re-charged 29.00 + 21 % = 35.09.
     * The renewal is refunded whole (40.34) and re-charged at 33.90 + 21 % = 41.02.
     * Re-charged at full value, the first invoice would have cost 41.02 — the Softdrinks
     * goodwill collected a second time over the mandate.
     */
    public function test_a_partly_refunded_invoice_is_reissued_at_what_remained_paid_not_at_its_original_value(): void
    {
        $billable = $this->opsSubscriber('pro', 0, ['softdrinks'], 'DE');
        $first = $this->opsRenew($billable, '40.34');

        $this->assertSame(3390, (int) $first->amount_net, 'first renewal net');
        $this->assertSame(4034, (int) $first->amount_gross, 'first renewal gross');

        $addonLine = $this->ccRefundableLine($first, 490);

        // The operator refunds the addon line from the invoice tab.
        $this->browse(function (Browser $browser) use ($billable, $first, $addonLine): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->clickWire($browser, '[wire\\:click="openRefund('.$first->id.')"]');
            $browser->waitFor(self::AMOUNT, 10)->pause(400);

            $browser->select(self::LINE_SELECT, (string) $addonLine['index']);
            $browser->waitUsing(10, 200, fn (): bool => $browser->inputValue(self::AMOUNT) === BillingMoney::numberFromMinor(490));
            $this->shot($browser, 'Refund the Softdrinks line');

            $this->clickWire($browser, self::SUBMIT);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);
            $browser->assertSee(__('billing::admin.billables.invoices.refunded_suffix'));
            $this->shot($browser, 'Addon line refunded');
        });

        $goodwill = $this->ccCreditNotes($billable)->sole();
        $this->assertSame(-490, (int) $goodwill->amount_net);
        $this->assertSame(-583, (int) $goodwill->amount_gross);
        $this->assertSame(2900, $first->fresh()->remainingRefundableNet());

        // A renewal from a Dutch card, the IP Dutch too: the mismatch is flagged.
        $renewal = $this->ccRenewFromForeignCard($billable, '40.34', 'NL');
        $mismatch = $this->ccPendingMismatch();

        $this->assertSame(
            [(int) $first->id, (int) $renewal->id],
            $mismatch->invoices()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'both invoices are linked to the mismatch',
        );

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->ccOpenCountryFix($browser, $billable);

            // The modal names what the correction will refund: 34.51 left of the first
            // invoice and 40.34 of the renewal — not the 80.68 the two once collected.
            $this->assertSame(
                $this->opsNormaliseMoney(__('billing::portal.country_mismatch.confirm_note', [
                    'count' => 2,
                    'total' => BillingMoney::format(3451 + 4034),
                ])),
                $this->opsNormaliseMoney($browser->text('[dusk="country-fix-confirm-note"]')),
                'the confirmation names the money the refunds will move',
            );

            $this->ccSubmitCountryFix($browser, 'NL');

            $browser->waitFor(self::RECHARGE_PENDING, 15);
            $this->shot($browser, 'Resolved: re-charge pending');
        });

        $this->assertSame(CountryMismatchStatus::Resolved, $mismatch->fresh()->status);
        $this->assertSame('NL', TestBillable::query()->find($billable->getKey())->getBillingCountry());

        // At Mollie: the goodwill, then what was left of each invoice — nothing twice.
        $this->assertSame(['5.83', '34.51', '40.34'], $this->ccRefundAmounts(), 'refunds at Mollie');

        $corrections = $this->ccCorrectionPayments();
        $this->assertCount(2, $corrections, 'one correction charge per linked invoice');
        $this->assertSame('35.09', $corrections[(int) $first->id]['amount_value'], 'the first invoice is charged again for what remained paid');
        $this->assertSame('41.02', $corrections[(int) $renewal->id]['amount_value'], 'the renewal is charged again in full');

        foreach ($corrections as $payment) {
            $this->assertSame(200, $this->deliverWebhook($payment['id']));
        }

        $reissueOfFirst = BillingInvoice::query()->where('mollie_payment_id', $corrections[(int) $first->id]['id'])->sole();
        $reissueOfRenewal = BillingInvoice::query()->where('mollie_payment_id', $corrections[(int) $renewal->id]['id'])->sole();

        $this->assertSame(2900, (int) $reissueOfFirst->amount_net, 'reissue net: the remainder');
        $this->assertSame(609, (int) $reissueOfFirst->amount_vat, 'reissue VAT: 21 % of the remainder');
        $this->assertSame(3509, (int) $reissueOfFirst->amount_gross, 'reissue gross: the remainder at the new rate');
        $this->assertNotSame((int) $reissueOfRenewal->amount_gross, (int) $reissueOfFirst->amount_gross, 'not the full original at the new rate');
        $this->assertSame('NL', (string) $reissueOfFirst->country);

        // The reduction is on the document, naming the Softdrinks line it reduces.
        $reductions = array_values(array_filter((array) $reissueOfFirst->line_items, fn (array $line): bool => ($line['kind'] ?? null) === 'refunded'));
        $this->assertCount(1, $reductions, 'one reduction line');
        $this->assertSame(-490, (int) $reductions[0]['amount_net']);

        $this->assertSame(3390, (int) $reissueOfRenewal->amount_net);
        $this->assertSame(712, (int) $reissueOfRenewal->amount_vat);
        $this->assertSame(4102, (int) $reissueOfRenewal->amount_gross);

        // No other payment was created: the two corrections are everything charged since.
        $this->assertSame(2, DB::table('fake_mollie_payments')->where('metadata', 'like', '%country_correction%')->count());

        $this->browse(function (Browser $browser) use ($billable, $reissueOfFirst, $reissueOfRenewal): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$reissueOfFirst->id.'"]', 15)->pause(300);
            $this->assertShownAmount($browser, 'invoice-'.$reissueOfFirst->id.'-gross', 3509, 'reissue of the partly refunded invoice');
            $this->assertShownAmount($browser, 'invoice-'.$reissueOfFirst->id.'-vat', 609);
            $this->assertShownAmount($browser, 'invoice-'.$reissueOfRenewal->id.'-gross', 4102, 'reissue of the renewal');
            $this->shot($browser, 'Invoices: both reissues');

            $browser->visit('/billing')->waitForText(__('billing::portal.current_plan'), 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'no re-charge pending any more');
        });
    }

    // ── 36. An overage invoice in the linked set, resolved by the operator ──

    /**
     * Starter, DE. 25 Tokens past the quota at 12 ct: overage 3.00 net, 3.57 gross, paid.
     * A renewal from a Dutch card (9.90 net, 11.78) flags the mismatch; an operator resolves
     * it to NL with a justification. Both are refunded (3.57, 11.78) and re-charged at 21 %
     * (3.63, 11.98). The usage was consumed and is charged again, so the Tokens on the
     * dashboard are exactly what they were — before the resolve, after it, and after the
     * corrections are booked.
     */
    public function test_an_overage_invoice_in_the_correction_moves_no_units_and_its_reissue_is_listed(): void
    {
        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $overage = $this->opsOverageInvoice($billable, 'Tokens', 25);

        $this->assertSame(InvoiceKind::Overage, $overage->invoice_kind);
        $this->assertSame(300, (int) $overage->amount_net);
        $this->assertSame(357, (int) $overage->amount_gross);

        $renewal = $this->ccRenewFromForeignCard($billable, '11.78', 'NL');
        $mismatch = $this->ccPendingMismatch();

        $this->assertEqualsCanonicalizing(
            [(int) $overage->id, (int) $renewal->id],
            $mismatch->invoices()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'the overage invoice is in the linked set',
        );

        $tokensBefore = $this->ccTokens($billable);
        $meters = [];

        $this->browse(function (Browser $browser) use ($billable, &$meters): void {
            $this->actAs($browser, $billable);
            $browser->waitForText(__('billing::portal.country_mismatch.banner_heading'), 15)
                ->waitFor('[dusk="usage-meter-Tokens-plan"]', 15)->pause(300);
            $meters['before'] = $this->ccMeters($browser);
            $this->shot($browser, 'Dashboard before the resolve');
        });

        $this->browse(function (Browser $browser) use ($mismatch): void {
            $this->ccResolveInAdmin($browser, $mismatch, 'NL', 'Customer moved to Rotterdam, ticket 4711');
        });

        $this->assertSame(CountryMismatchStatus::Resolved, $mismatch->fresh()->status);
        $this->assertSame($tokensBefore, $this->ccTokens($billable), 'the resolve moved no units');

        // The overage invoice was refunded money-only: its correction credit note carries no units.
        $overageCredit = $this->ccCreditNotes($billable)
            ->first(fn (BillingInvoice $note): bool => (int) ($note->line_items[0]['parent_invoice_id'] ?? 0) === (int) $overage->id);
        $this->assertNotNull($overageCredit, 'the overage invoice has a correction credit note');
        $this->assertSame(-357, (int) $overageCredit->amount_gross);
        $this->assertSame([], (array) ($overageCredit->line_items[0]['wallet_credits'] ?? []), 'no units handed back');
        $this->assertSame(RefundReasonCode::BillingError, $overageCredit->refund_reason_code);

        $this->assertEqualsCanonicalizing(['3.57', '11.78'], $this->ccRefundAmounts(), 'refunds at Mollie');

        $corrections = $this->ccCorrectionPayments();
        $this->assertCount(2, $corrections);
        $this->assertSame('3.63', $corrections[(int) $overage->id]['amount_value'], 'the overage charged again at 21 %');
        $this->assertSame('11.98', $corrections[(int) $renewal->id]['amount_value'], 'the renewal charged again at 21 %');

        $this->browse(function (Browser $browser) use ($billable, &$meters): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="usage-meter-Tokens-plan"]', 15)->pause(300);
            $meters['after_resolve'] = $this->ccMeters($browser);
            $this->shot($browser, 'Dashboard after the resolve');
        });

        foreach ($corrections as $payment) {
            $this->assertSame(200, $this->deliverWebhook($payment['id']));
        }

        $reissue = BillingInvoice::query()->where('mollie_payment_id', $corrections[(int) $overage->id]['id'])->sole();
        $this->assertSame(300, (int) $reissue->amount_net);
        $this->assertSame(363, (int) $reissue->amount_gross);
        $this->assertSame((int) $mismatch->id, (int) $reissue->mismatch_id);
        $this->assertSame($tokensBefore, $this->ccTokens($billable), 'booking the reissue moved no units');

        $this->browse(function (Browser $browser) use ($billable, $reissue, &$meters): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="usage-meter-Tokens-plan"]', 15)->pause(300);
            $meters['after_booking'] = $this->ccMeters($browser);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'both corrections booked');
            $this->shot($browser, 'Dashboard after the corrections were booked');

            // The reissue is listed with the customer …
            $browser->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$reissue->id.'"]', 15)->pause(300);
            $this->assertShownAmount($browser, 'invoice-'.$reissue->id.'-gross', 363, 'overage reissue in the portal');
            $this->shot($browser, 'Portal invoices: the overage reissue');

            // … and with the operator.
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $browser->waitForText((string) $reissue->serial_number, 15);
            $this->assertTrue($this->opsSeesMoney($browser, 363), 'reissue gross in the admin tab');
            $this->shot($browser, 'Admin invoices tab: the overage reissue');
        });

        $this->assertNotSame('', $meters['before']['Tokens'], 'the Tokens meter is on the dashboard');
        $this->assertSame($meters['before'], $meters['after_resolve'], 'usage meters unchanged by the resolve');
        $this->assertSame($meters['before'], $meters['after_booking'], 'usage meters unchanged by the reissue');
    }

    // ── 37. The reissue refunded in full ────────────────────────────────────

    /**
     * Pro + Softdrinks resolved to NL: the reissue is 33.90 net, VAT 6.09 + 1.03 = 7.12,
     * 41.02 gross. "Refund all" on it pays back exactly that — VAT at the reissue's own
     * rate, not 0 % (a reissue whose lines read as credits refunded only the net).
     */
    public function test_a_paid_reissue_refunded_in_full_credits_its_own_vat_and_gross(): void
    {
        $billable = $this->opsSubscriber('pro', 0, ['softdrinks'], 'DE');
        $original = $this->ccRenewFromForeignCard($billable, '40.34', 'NL');
        $this->ccPendingMismatch();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->ccOpenCountryFix($browser, $billable);
            $this->ccSubmitCountryFix($browser, 'NL');
        });

        $correction = $this->ccCorrectionPayments()[(int) $original->id];
        $this->assertSame('41.02', $correction['amount_value']);
        $this->assertSame(200, $this->deliverWebhook($correction['id']));

        $reissue = BillingInvoice::query()->where('mollie_payment_id', $correction['id'])->sole();
        $this->assertSame(3390, (int) $reissue->amount_net);
        $this->assertSame(712, (int) $reissue->amount_vat);
        $this->assertSame(4102, (int) $reissue->amount_gross);

        $notesBefore = $this->ccCreditNotes($billable)->pluck('id')->all();

        $this->browse(function (Browser $browser) use ($billable, $reissue): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $browser->waitForText((string) $reissue->serial_number, 15);

            // The original is closed by the correction; the reissue is refundable in full.
            $this->clickWire($browser, '[wire\\:click="openRefund('.$reissue->id.')"]');
            $browser->waitFor(self::AMOUNT, 10)->pause(400);
            $browser->assertDontSee(__('billing::admin.billables.invoices.modal.partially_refunded'));
            $this->assertStringContainsString(
                $this->opsNormaliseMoney(__('billing::admin.billables.invoices.modal.refund_full', ['amount' => BillingMoney::format(3390)])),
                $this->opsNormaliseMoney($this->opsPageText($browser)),
                'refund all offers the reissue net',
            );
            $this->shot($browser, 'Refund modal on the reissue');

            $this->clickWire($browser, self::REFUND_FULL);
            $browser->pause(300);
            $confirm = $browser->driver->switchTo()->alert()->getText();
            $this->assertStringContainsString($this->opsNormaliseMoney(BillingMoney::format(3390)), $this->opsNormaliseMoney($confirm));
            $browser->acceptDialog();

            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);
            $this->shot($browser, 'Reissue refunded in full');
        });

        $creditNote = $this->ccCreditNotes($billable)->whereNotIn('id', $notesBefore)->sole();

        $this->assertSame(-3390, (int) $creditNote->amount_net);
        $this->assertSame(-712, (int) $creditNote->amount_vat, 'credit note VAT = the reissue VAT');
        $this->assertSame(-4102, (int) $creditNote->amount_gross, 'credit note gross = the reissue gross');
        $this->assertSame('NL', (string) $creditNote->country);
        $this->assertTrue($reissue->fresh()->isFullyRefunded());

        $refund = DB::table('fake_mollie_refunds')->where('payment_id', $correction['id'])->get();
        $this->assertCount(1, $refund, 'one refund against the correction payment');
        $this->assertSame('41.02', (string) $refund->first()->amount_value);

        $this->browse(function (Browser $browser) use ($creditNote): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/invoices')->waitForText((string) $creditNote->serial_number, 15)->pause(400);
            $this->clickWire($browser, 'button[wire\\:click="openDetail('.$creditNote->id.')"]');
            $browser->waitFor('[dusk="invoice-detail-vat"]', 15)->pause(300);

            $this->assertShownAmount($browser, 'invoice-detail-net', 3390, 'credit note net in the detail');
            $this->assertShownAmount($browser, 'invoice-detail-vat', 712, 'credit note VAT in the detail');
            $this->assertShownAmount($browser, 'invoice-detail-gross', 4102, 'credit note gross in the detail');
            $this->shot($browser, 'Credit note of the reissue');
        });
    }

    // ── 38. Two corrections: the callout goes once both are booked ──────────

    public function test_the_recharge_pending_callout_goes_once_both_corrections_are_booked_and_stays_gone(): void
    {
        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $first = $this->opsRenew($billable, '11.78');
        $second = $this->ccRenewFromForeignCard($billable, '11.78', 'NL');
        $this->ccPendingMismatch();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->ccOpenCountryFix($browser, $billable);
            $this->ccSubmitCountryFix($browser, 'NL');

            // Shown at once, and still after a reload.
            $browser->waitFor(self::RECHARGE_PENDING, 15);
            $browser->refresh()->waitFor(self::RECHARGE_PENDING, 15);
            $this->shot($browser, 'Two corrections pending');
        });

        $corrections = $this->ccCorrectionPayments();
        $this->assertCount(2, $corrections);
        $this->assertSame('11.98', $corrections[(int) $first->id]['amount_value']);
        $this->assertSame('11.98', $corrections[(int) $second->id]['amount_value']);
        $this->assertCount(2, $this->ccPendingCorrections($billable));

        // The first one is booked; one is still on its way.
        $this->assertSame(200, $this->deliverWebhook($corrections[(int) $first->id]['id']));
        $this->assertCount(1, $this->ccPendingCorrections($billable));

        $this->browse(function (Browser $browser): void {
            $browser->refresh()->waitFor(self::RECHARGE_PENDING, 15);
            $this->shot($browser, 'One correction still pending');
        });

        // The second one too.
        $this->assertSame(200, $this->deliverWebhook($corrections[(int) $second->id]['id']));
        $this->assertSame([], $this->ccPendingCorrections($billable));

        $this->browse(function (Browser $browser): void {
            $browser->refresh()->waitForText(__('billing::portal.current_plan'), 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'gone once both are booked');
            $browser->assertDontSee(__('billing::portal.country_mismatch.recharge_pending'));
            $this->shot($browser, 'Both booked: callout gone');

            // A reload, and away and Back, do not bring it back.
            $browser->refresh()->waitForText(__('billing::portal.current_plan'), 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'still gone after a reload');

            $browser->visit('/billing/invoices');
            $this->waitForPathContains($browser, '/billing/invoices');
            $browser->back();
            $browser->waitUsing(15, 100, fn (): bool => ! str_contains((string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH), 'invoices'));
            $browser->waitForText(__('billing::portal.current_plan'), 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'still gone after Back');
        });

        // Mollie delivers the first webhook again: nothing booked twice, nothing pending again.
        $this->assertSame(200, $this->deliverWebhook($corrections[(int) $first->id]['id']));
        $this->assertSame([], $this->ccPendingCorrections($billable));

        $this->browse(function (Browser $browser): void {
            $browser->refresh()->waitForText(__('billing::portal.current_plan'), 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::RECHARGE_PENDING), 'still gone after a duplicate webhook');
        });

        foreach ($corrections as $originalId => $payment) {
            $reissues = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->get();
            $this->assertCount(1, $reissues, 'one reissue for invoice '.$originalId);
            $this->assertSame(1198, (int) $reissues->first()->amount_gross);
        }

        $this->assertSame(2, DB::table('fake_mollie_payments')->where('metadata', 'like', '%country_correction%')->count(), 'no correction charged twice');
    }

    // ── 39. The ZIP export holds the invoice, its credit note and the reissue ─

    public function test_the_invoice_export_holds_the_invoice_its_correction_credit_note_and_the_reissue(): void
    {
        // Another tenant's invoice of last month: outside the range, not in the archive.
        $other = $this->opsSubscriber('starter', 0, [], 'DE');
        $outside = $this->opsRenew($other, '11.78');
        $lastMonth = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->startOfMonth()->subMonthNoOverflow()->addDays(4)->setTime(10, 0);
        DB::table('billing_invoices')->where('id', $outside->id)->update(['created_at' => $lastMonth->format('Y-m-d H:i:s')]);

        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $original = $this->ccRenewFromForeignCard($billable, '11.78', 'NL');
        $this->ccPendingMismatch();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->ccOpenCountryFix($browser, $billable);
            $this->ccSubmitCountryFix($browser, 'NL');
        });

        $correction = $this->ccCorrectionPayments()[(int) $original->id];
        $this->assertSame(200, $this->deliverWebhook($correction['id']));

        $reissue = BillingInvoice::query()->where('mollie_payment_id', $correction['id'])->sole();
        $creditNote = $this->ccCreditNotes($billable)->sole();

        $this->assertSame(CountryMatchService::CORRECTION_REFUND_REASON, $creditNote->refund_reason_text);
        $this->assertSame(-1178, (int) $creditNote->amount_gross);
        $this->assertSame(1198, (int) $reissue->amount_gross);

        $expected = [
            $original->fresh()->pdfFilename(),
            $creditNote->pdfFilename(),
            $reissue->pdfFilename(),
        ];
        sort($expected);

        $this->assertCount(3, array_unique($expected), 'three distinct serial-based names');

        $this->browse(function (Browser $browser) use ($original, $creditNote, $reissue, $outside, $expected): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/invoices')->waitForText((string) $reissue->serial_number, 15)->pause(500);

            $browser->click('[dusk="invoice-preset-this_month"]')->pause(900);
            $browser->assertSee((string) $original->serial_number)
                ->assertSee((string) $creditNote->serial_number)
                ->assertSee((string) $reissue->serial_number)
                ->assertDontSee((string) $outside->serial_number);
            $this->shot($browser, 'This month: invoice, credit note, reissue');

            $href = (string) $browser->attribute('[dusk="invoice-export"]', 'href');
            $download = $this->ccFetchBody($browser, $href);

            $this->assertSame(200, $download['status']);
            $this->assertStringContainsString('zip', $download['type']);

            $entries = $this->ccZipEntries(base64_decode($download['body'], true) ?: '');

            $names = array_keys($entries);
            sort($names);

            $this->assertNotContains(InvoiceArchiveService::MISSING_FILE, $names, 'nothing reported missing');
            $this->assertSame($expected, $names, 'exactly the three documents, under their serial-based names');

            foreach ($entries as $name => $head) {
                $this->assertStringStartsWith('%PDF', $head, $name.' is a PDF');
            }
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Mollie charges the subscription from a card issued in `$country`, and the IP says the
     * same: the declared DE now matches neither signal, and the webhook flags the mismatch.
     * Returns the renewal invoice — still booked at the declared country's rate.
     */
    private function ccRenewFromForeignCard(TestBillable $billable, string $gross, string $country): BillingInvoice
    {
        $billable = $billable->fresh();
        $billable->forceFill(['tax_country_ip' => $country])->save();
        $this->setIpCountry($country);

        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $payment = FakeMollie::chargeSubscription($subscriptionId, $gross);

        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update(['country_code' => $country]);

        $this->assertSame(200, $this->deliverWebhook($payment['id']));

        return BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->firstOrFail();
    }

    private function ccPendingMismatch(): BillingCountryMismatch
    {
        $mismatch = BillingCountryMismatch::query()->sole();

        $this->assertSame(CountryMismatchStatus::Pending, $mismatch->status);

        return $mismatch;
    }

    /** The dashboard's banner button: pre-fills the form and opens the modal. */
    private function ccOpenCountryFix(Browser $browser, TestBillable $billable): void
    {
        $this->actAs($browser, $billable);
        $browser->waitForText(__('billing::portal.country_mismatch.banner_heading'), 15)->pause(400);

        $this->clickWire($browser, '[wire\\:click="openCountryFix"]');
        $browser->waitFor('select[name="fixCountry"]', 10)->pause(500);
        $this->shot($browser, 'Country fix modal');
    }

    private function ccSubmitCountryFix(Browser $browser, string $country): void
    {
        $browser->select('fixCountry', $country)->pause(400);
        $this->jsClick($browser, 'input[type="checkbox"][wire\\:model="fixConfirmed"], [wire\\:model="fixConfirmed"]');

        $this->clickWire($browser, 'form[wire\\:submit\\.prevent="saveCountryFix"] button[type="submit"]');
        $browser->waitForText(__('billing::portal.country_mismatch.resolved_flash'), 30)->pause(500);
        $this->shot($browser, 'Country corrected to '.$country);
    }

    private function ccResolveInAdmin(Browser $browser, BillingCountryMismatch $mismatch, string $country, string $justification): void
    {
        $this->loginAdmin($browser);
        $browser->visit('/billing/admin/mismatches')->waitForText('Acme GmbH', 15)->pause(500);

        $this->clickWire($browser, '[wire\\:click="openResolve('.$mismatch->id.')"]');
        $browser->waitFor('textarea[wire\\:model="resolvingJustification"]', 10)->pause(500);

        $browser->assertSee(trans_choice('billing::admin.mismatches.modal.invoice_count', 2, ['count' => 2]));

        $this->jsClick($browser, 'ui-radio[value="'.$country.'"], input[type="radio"][value="'.$country.'"], [data-flux-radio][value="'.$country.'"]');
        $browser->pause(600);

        $browser->type('textarea[wire\\:model="resolvingJustification"]', $justification);
        $this->shot($browser, 'Operator resolves to '.$country);

        $this->clickWire($browser, '[wire\\:click="confirmResolve"]');
        $browser->waitForText(__('billing::admin.mismatches.modal.resolved'), 30)->pause(800);
        $this->shot($browser, 'Resolved by the operator');
    }

    /**
     * The correction charges at the fake Mollie, keyed by the invoice each one corrects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ccCorrectionPayments(): array
    {
        $payments = [];

        foreach (DB::table('fake_mollie_payments')->where('metadata', 'like', '%country_correction%')->orderBy('id')->get() as $row) {
            $metadata = (array) json_decode((string) $row->metadata, true);
            $payments[(int) ($metadata['original_invoice_id'] ?? 0)] = (array) $row;
        }

        return $payments;
    }

    /** @return list<string> */
    private function ccRefundAmounts(): array
    {
        return DB::table('fake_mollie_refunds')->orderBy('created_at')->orderBy('id')
            ->pluck('amount_value')->map(fn ($value): string => (string) $value)->all();
    }

    /** @return array<string, mixed> */
    private function ccPendingCorrections(TestBillable $billable): array
    {
        return (array) (TestBillable::query()->find($billable->getKey())->getBillingSubscriptionMeta()['country_corrections'] ?? []);
    }

    /** @return Collection<int, BillingInvoice> */
    private function ccCreditNotes(TestBillable $billable): Collection
    {
        return BillingInvoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Refund)
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function ccRefundableLine(BillingInvoice $invoice, int $net): array
    {
        foreach (app(InvoiceService::class)->refundableLines($invoice->fresh(), true) as $line) {
            if ($line['net'] === $net) {
                return $line;
            }
        }

        $this->fail('No refundable line of '.$net);
    }

    private function ccTokens(TestBillable $billable): int
    {
        return (int) (TestBillable::query()->find($billable->getKey())->getWallet('Tokens')?->balanceInt ?? 0);
    }

    /** @return array<string, string> every usage meter's plan line on the page, by usage type */
    private function ccMeters(Browser $browser): array
    {
        $meters = [];

        foreach (['Tokens', 'SMS'] as $type) {
            $meters[$type] = $this->domCount($browser, '[dusk="usage-meter-'.$type.'-plan"]') > 0
                ? $browser->text('[dusk="usage-meter-'.$type.'-plan"]')
                : '';
        }

        return $meters;
    }

    /**
     * Download a URL with the browser's own session and return its whole body, base64 —
     * the archive is what the operator's browser received, not what this process builds.
     *
     * @return array{status:int, type:string, body:string}
     */
    private function ccFetchBody(Browser $browser, string $url): array
    {
        $script = <<<'JS'
            const done = arguments[arguments.length - 1];
            fetch(arguments[0], {credentials: 'same-origin'})
                .then(async (response) => {
                    const bytes = new Uint8Array(await response.arrayBuffer());
                    let binary = '';
                    for (let i = 0; i < bytes.length; i += 0x8000) {
                        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
                    }
                    done({status: response.status, type: response.headers.get('content-type') || '', body: btoa(binary)});
                })
                .catch((error) => done({status: 0, type: String(error), body: ''}));
        JS;

        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $browser->driver->manage()->timeouts()->setScriptTimeout(60);

        /** @var array{status:int, type:string, body:string} $result */
        $result = $browser->driver->executeAsyncScript($script, [$path]);

        return $result;
    }

    /** @return array<string, string> entry name => its first bytes */
    private function ccZipEntries(string $bytes): array
    {
        $this->assertStringStartsWith('PK', $bytes, 'a ZIP archive');

        $path = tempnam(sys_get_temp_dir(), 'cc-export-');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the archive opens');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = substr((string) $zip->getFromIndex($i), 0, 8);
        }

        $zip->close();
        @unlink($path);

        return $entries;
    }
}

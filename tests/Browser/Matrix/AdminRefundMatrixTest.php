<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Refunds from the admin panel, as an operator makes them.
 *
 * Every case builds an invoice, opens the billable's invoice tab, issues the refund
 * through the modal, and then compares three numbers that have to be one: what the
 * screen offered ("Refund all …" / the amount typed), what the test Mollie was asked to
 * pay back, and what the credit note books.
 *
 * Dimensions, each value at least once and the money ones in pairs:
 *   reason  × mode (full / partial)                      — all 14 pairs
 *   invoice lines (plan, seats, addon, addon_seats, overage units)
 *   discount (none, recurring coupon, plan waiver) × VAT (DE 19 %, AT 20 %, reverse charge)
 *
 * The figures in the provider are worked out by hand, not through the package.
 */
class AdminRefundMatrixTest extends BrowserTestCase
{
    use OperationsWorkbench;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function refundCases(): array
    {
        // world        => [plan, seats, addons, discount, vat, gross paid, invoice net, vat rate]
        $worlds = [
            'starter/none/DE' => ['starter', 0, [], 'none', 'DE', '11.78', 990, 19],
            'starter/coupon/AT' => ['starter', 0, [], 'coupon', 'AT', '9.50', 792, 20],
            'starter/none/RC' => ['starter', 0, [], 'none', 'RC', '9.90', 990, 0],
            'seats/none/AT' => ['pro', 5, [], 'none', 'AT', '58.56', 4880, 20],
            'seats/coupon/DE' => ['pro', 5, [], 'coupon', 'DE', '46.46', 3904, 19],
            'addon/none/RC' => ['pro', 0, ['softdrinks'], 'none', 'RC', '33.90', 3390, 0],
            'addon/waiver/DE' => ['pro', 0, ['softdrinks'], 'waiver', 'DE', '5.83', 490, 19],
            'addon/waiver/AT' => ['pro', 0, ['softdrinks'], 'waiver', 'AT', '5.88', 490, 20],
            'addon/waiver/RC' => ['pro', 0, ['softdrinks'], 'waiver', 'RC', '4.90', 490, 0],
            'addon-seats/none/AT' => ['ops-team', 4, ['ops-crm'], 'none', 'AT', '108.00', 9000, 20],
            'addon-seats/coupon/RC' => ['ops-team', 4, ['ops-crm'], 'coupon', 'RC', '72.00', 7200, 0],
            'overage/none/DE' => ['starter', 0, [], 'overage', 'DE', null, 300, 19],
            'overage/none/AT' => ['starter', 0, [], 'overage', 'AT', null, 300, 20],
        ];

        // reason, mode, world, partial net (minor units; null = refund all)
        $cases = [
            ['service_outage', 'full', 'starter/none/DE', null],
            ['service_outage', 'partial', 'starter/coupon/AT', 300],
            ['billing_error', 'full', 'addon/waiver/AT', null],
            ['billing_error', 'partial', 'seats/none/AT', 1980],       // the seat line
            ['goodwill', 'full', 'overage/none/DE', null],
            ['goodwill', 'partial', 'seats/coupon/DE', 1584],          // the seat line, less 20 %
            ['chargeback', 'full', 'addon/none/RC', null],
            ['chargeback', 'partial', 'overage/none/AT', 120],         // ten units at 12 ct
            ['cancellation', 'full', 'addon/waiver/RC', null],
            ['cancellation', 'partial', 'addon/waiver/DE', 245],       // half the addon
            ['plan_downgrade', 'full', 'addon-seats/none/AT', null],
            ['plan_downgrade', 'partial', 'starter/none/RC', 495],
            ['other', 'full', 'starter/none/DE', null],
            ['other', 'partial', 'addon-seats/coupon/RC', 960],        // the addon-seat line, less 20 %
        ];

        $out = [];

        foreach ($cases as [$reason, $mode, $world, $partialNet]) {
            [$plan, $seats, $addons, $discount, $vat, $gross, $net, $rate] = $worlds[$world];

            $out[$reason.' · '.$mode.' · '.$world] = [[
                'reason' => $reason,
                'partial_net' => $partialNet,
                'plan' => $plan,
                'seats' => $seats,
                'addons' => $addons,
                'discount' => $discount,
                'vat' => $vat,
                'gross' => $gross,
                'net' => $net,
                'rate' => $rate,
            ]];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('refundCases')]
    public function test_refund_from_the_invoice_tab(array $case): void
    {
        $invoice = $this->invoiceFor($case);
        $billable = $invoice->billable;

        // The invoice the case was built for is the one the books hold.
        expect((int) $invoice->amount_net)->toBe($case['net'], 'invoice net')
            ->and((int) $invoice->remainingRefundableNet())->toBe($case['net'], 'refundable net');

        $refundNet = $case['partial_net'] ?? $case['net'];
        $refundGross = $refundNet + (int) round($refundNet * $case['rate'] / 100);
        $reason = RefundReasonCode::from($case['reason']);

        $this->browse(function (Browser $browser) use ($billable, $invoice, $case, $refundNet, $reason): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor('select[wire\\:model="refundReason"]', 10)->pause(400);

            // The modal names what can be refunded, in the screen's own money format.
            $offer = __('billing::admin.billables.invoices.modal.refund_full', ['amount' => BillingMoney::format($case['net'])]);
            expect($this->opsNormaliseMoney($this->opsPageText($browser)))->toContain($this->opsNormaliseMoney($offer));

            // Every reason is on offer under its own label.
            $browser->select('select[wire\\:model="refundReason"]', $reason->value);
            expect($browser->script('const s = document.querySelector(\'select[wire\\\\:model="refundReason"]\'); return s.options[s.selectedIndex].textContent.trim();')[0])
                ->toBe($reason->label());

            if ($reason === RefundReasonCode::Other) {
                $browser->type('input[wire\\:model="refundText"]', 'Agreed by phone, ticket 4711');
            }

            $this->shot($browser, 'Refund modal');

            if ($case['partial_net'] === null) {
                $this->clickWire($browser, '[wire\\:click="refundFull"]');
                $browser->pause(300);

                // The confirmation repeats the amount that is about to leave.
                $confirm = $browser->driver->switchTo()->alert()->getText();
                expect($this->opsNormaliseMoney($confirm))->toContain($this->opsNormaliseMoney(BillingMoney::format($case['net'])));
                $browser->acceptDialog();
            } else {
                // Typed the way the hint shows it — the locale's own decimal mark.
                $browser->type('input[wire\\:model="refundAmount"]', BillingMoney::numberFromMinor($refundNet));
                $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');
            }

            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);
            $this->shot($browser, 'Refunded');
        });

        $creditNote = BillingInvoice::query()
            ->where('invoice_kind', InvoiceKind::Refund)
            ->where('billable_id', $billable->getKey())
            ->sole();

        // What the screen offered is what the credit note books …
        expect((int) $creditNote->amount_net)->toBe(-$refundNet, 'credit note net')
            ->and((int) $creditNote->amount_gross)->toBe(-$refundGross, 'credit note gross')
            ->and((int) $invoice->refresh()->effectiveRefundedNet())->toBe($refundNet, 'refunded net on the invoice')
            ->and($creditNote->refund_reason_code)->toBe($reason);

        // … and what the test Mollie was asked to pay back, to the cent.
        $refund = DB::table('fake_mollie_refunds')->where('payment_id', $invoice->mollie_payment_id)->get();

        expect($refund)->toHaveCount(1)
            ->and((string) $refund->first()->amount_value)->toBe(BillingMoney::mollieAmount($refundGross));

        $this->browse(function (Browser $browser) use ($billable, $creditNote, $invoice, $case, $refundNet, $reason): void {
            // The tab now lists the credit note with the booked amounts …
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            expect($this->opsSeesMoney($browser, (int) $creditNote->amount_gross))->toBeTrue('credit note gross in the invoice tab');

            if ($case['partial_net'] === null) {
                $browser->assertSee(__('billing::admin.billables.invoices.fully_refunded'));
                // Nothing left to refund: the action is gone.
                expect($this->domCount($browser, '[wire\\:click="openRefund('.$invoice->id.')"]'))->toBe(0);
            } else {
                $browser->assertSee(__('billing::admin.billables.invoices.refunded_suffix'));
            }

            // … the refunds list shows it under its reason …
            $browser->visit('/billing/admin/refunds')->waitForText($creditNote->serial_number, 15)->pause(400);
            expect($this->opsSeesMoney($browser, (int) $creditNote->amount_gross))->toBeTrue('credit note gross in the refunds list');
            $this->clickWire($browser, '[wire\\:click="openDetail('.$creditNote->id.')"]');
            $browser->waitForText($reason->label(), 15)->pause(300);
            $this->shot($browser, 'Refund detail');

            // … and the customer sees the same credit note.
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$creditNote->id.'"]', 15)->pause(300);
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->id.'-net', (int) $creditNote->amount_net);
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->id.'-gross', (int) $creditNote->amount_gross);
            $this->shot($browser, 'Credit note in the portal');
        });
    }

    public function test_a_refund_beyond_what_is_left_is_refused_on_screen(): void
    {
        $invoice = $this->invoiceFor(['plan' => 'starter', 'seats' => 0, 'addons' => [], 'discount' => 'none', 'vat' => 'DE', 'gross' => '11.78']);
        $billable = $invoice->billable;

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            // First 4.00 of 9.90 …
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor('input[wire\\:model="refundAmount"]', 10)->pause(400)
                ->type('input[wire\\:model="refundAmount"]', BillingMoney::numberFromMinor(400));
            $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);

            // … then 6.00, which is ten cents more than is left.
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor('input[wire\\:model="refundAmount"]', 10)->pause(400);

            // The modal says what happened so far and what is left.
            $browser->assertSee(__('billing::admin.billables.invoices.modal.partially_refunded'));
            expect($this->opsSeesMoney($browser, 590))->toBeTrue('remaining 5.90 shown');

            $browser->type('input[wire\\:model="refundAmount"]', BillingMoney::numberFromMinor(600));
            $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');

            $refused = __('billing::admin.billables.invoices.flash.exceeds_remaining', ['amount' => BillingMoney::format(590)]);
            $browser->waitForText($refused, 15);
            $this->shot($browser, 'Refused: more than is left');

            // Nonsense is refused as well, each with its own message.
            foreach ([
                'abc' => __('billing::admin.billables.invoices.flash.invalid_amount', ['example' => BillingMoney::number(10.5)]),
                '0' => __('billing::admin.billables.invoices.flash.amount_positive'),
            ] as $input => $message) {
                $browser->type('input[wire\\:model="refundAmount"]', (string) $input);
                $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');
                $browser->waitForText($message, 15);
            }
        });

        // One refund reached Mollie, for the 4.00 (+19 %).
        $refunds = DB::table('fake_mollie_refunds')->get();

        expect($refunds)->toHaveCount(1)
            ->and((string) $refunds->first()->amount_value)->toBe('4.76')
            ->and((int) $invoice->refresh()->effectiveRefundedNet())->toBe(400);
    }

    /**
     * Refunds by line, one case per VAT treatment and per kind of line.
     *
     * Worked out by hand (workbench catalog: Pro 29.00 base, 3 seats included, 9.90 per
     * further seat; Softdrinks 4.90):
     *
     *   seat line · AT 20 %   Pro, 5 seats → seats 2 × 9.90 = 19.80. Refund 12.00 → VAT 2.40,
     *                         gross 14.40; 7.80 left on the line.
     *   plan line · DE 19 %   Pro + Softdrinks → 29.00 + 4.90 = 33.90 net, VAT 5.51 + 0.93 =
     *                         6.44, paid 40.34. Refund 20.00 of the plan → VAT 3.80, gross
     *                         23.80; 9.00 left on the line.
     *   addon line · RC 0 %   Pro + Softdrinks, NL with a valid VAT number → 33.90 paid.
     *                         Refund the whole addon 4.90 → VAT 0, gross 4.90; nothing left.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function lineRefundCases(): array
    {
        return [
            'seat line · AT 20 %' => [[
                'plan' => 'pro', 'seats' => 5, 'addons' => [], 'vat' => 'AT', 'gross' => '58.56',
                'invoice_net' => 4880, 'line_net' => 1980, 'refund_net' => 1200,
                'refund_vat' => 240, 'refund_gross' => 1440, 'mollie' => '14.40', 'line_left' => 780,
            ]],
            'plan line · DE 19 %' => [[
                'plan' => 'pro', 'seats' => 0, 'addons' => ['softdrinks'], 'vat' => 'DE', 'gross' => '40.34',
                'invoice_net' => 3390, 'line_net' => 2900, 'refund_net' => 2000,
                'refund_vat' => 380, 'refund_gross' => 2380, 'mollie' => '23.80', 'line_left' => 900,
            ]],
            'addon line · reverse charge' => [[
                'plan' => 'pro', 'seats' => 0, 'addons' => ['softdrinks'], 'vat' => 'RC', 'gross' => '33.90',
                'invoice_net' => 3390, 'line_net' => 490, 'refund_net' => 490,
                'refund_vat' => 0, 'refund_gross' => 490, 'mollie' => '4.90', 'line_left' => 0,
            ]],
        ];
    }

    /**
     * The operator picks a line, the amount is pre-filled with what is left of it and can
     * be lowered, and the credit note reverses that line only, at its rate. Afterwards the
     * line offers its remainder — and one cent more is refused on screen — or, used up, is
     * no longer offered at all.
     *
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('lineRefundCases')]
    public function test_a_refund_by_line_is_booked_against_that_line(array $case): void
    {
        $invoice = $this->invoiceFor(['plan' => $case['plan'], 'seats' => $case['seats'], 'addons' => $case['addons'], 'discount' => 'none', 'vat' => $case['vat'], 'gross' => $case['gross']]);
        $billable = $invoice->billable;

        // The invoice holds the lines worked out above; the case's line is found by its net.
        $lineIndex = null;
        foreach ((array) $invoice->line_items as $index => $line) {
            if ((int) ($line['amount_net'] ?? 0) === $case['line_net']) {
                $lineIndex = (int) $index;
            }
        }

        expect((int) $invoice->amount_net)->toBe($case['invoice_net'], 'invoice net')
            ->and($lineIndex)->not->toBeNull('the line of '.$case['line_net']);

        $lineSelect = 'select[wire\\:model\\.live="refundLine"]';
        $amountField = 'input[wire\\:model="refundAmount"]';

        $this->browse(function (Browser $browser) use ($billable, $invoice, $case, $lineIndex, $lineSelect, $amountField): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor($lineSelect, 10)->pause(400);

            // Choosing the line fills in all of it …
            $browser->select($lineSelect, (string) $lineIndex);
            $browser->waitUsing(10, 200, fn () => $browser->inputValue($amountField) === BillingMoney::numberFromMinor($case['line_net']));
            $this->shot($browser, 'Refund by line: line chosen');

            // … and the operator books what the case refunds.
            $browser->type($amountField, BillingMoney::numberFromMinor($case['refund_net']));
            $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);

            // Again: the line offers what is left, and one cent more is refused …
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor($lineSelect, 10)->pause(400);

            if ($case['line_left'] > 0) {
                $browser->select($lineSelect, (string) $lineIndex);
                $browser->waitUsing(10, 200, fn () => $browser->inputValue($amountField) === BillingMoney::numberFromMinor($case['line_left']));

                $browser->type($amountField, BillingMoney::numberFromMinor($case['line_left'] + 1));
                $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');
                $browser->waitForText(__('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => BillingMoney::format($case['line_left'])]), 15);
                $this->shot($browser, 'Refund by line: more than the line has left');
            } else {
                // … or, used up, the line is no longer on offer.
                expect($this->domCount($browser, $lineSelect.' option[value="'.$lineIndex.'"]'))->toBe(0, 'used-up line still offered');
            }
        });

        $creditNote = BillingInvoice::query()
            ->where('invoice_kind', InvoiceKind::Refund)
            ->where('billable_id', $billable->getKey())
            ->sole();
        $lines = array_values((array) $creditNote->line_items);

        expect((int) $creditNote->amount_net)->toBe(-$case['refund_net'], 'credit note net')
            ->and((int) $creditNote->amount_vat)->toBe(-$case['refund_vat'], 'credit note VAT')
            ->and((int) $creditNote->amount_gross)->toBe(-$case['refund_gross'], 'credit note gross')
            ->and($lines)->toHaveCount(1)
            ->and((int) $lines[0]['parent_line_item_index'])->toBe($lineIndex)
            ->and((int) $invoice->refresh()->remainingRefundableNet())->toBe($case['invoice_net'] - $case['refund_net'], 'left on the invoice');

        // One refund at the test Mollie, for the gross worked out above.
        $refunds = DB::table('fake_mollie_refunds')->where('payment_id', $invoice->mollie_payment_id)->get();

        expect($refunds)->toHaveCount(1)
            ->and((string) $refunds->first()->amount_value)->toBe($case['mollie']);
    }

    /**
     * A line of a saldo-zero plan-switch invoice, refunded from the panel.
     *
     * The switch collected no payment of its own: it was settled out of the renewal that
     * paid for the period. Its refund therefore goes out through the renewal's payment, and
     * the credit note reverses the switch's own charge line.
     *
     * By hand, AT 20 %: renewal Pro 29.00 (VAT 5.80, paid 34.80). Halfway through, an
     * equal-price switch: Business charged 14.50 (VAT 2.90), Pro credited 14.50 — written
     * through `createPlanSwitchInvoice()`, as a plan change writes it. The renewal has
     * 29.00 − 14.50 = 14.50 left, the switch 14.50. Refund 10.00 of the Business line →
     * VAT 2.00, gross 12.00 at the renewal's payment; 4.50 left on the switch, the renewal
     * untouched, together 19.00 = 29.00 − 10.00.
     */
    public function test_a_line_of_a_settled_plan_switch_invoice_is_refunded_through_the_payment_that_holds_the_money(): void
    {
        $root = $this->invoiceFor(['plan' => 'pro', 'seats' => 0, 'addons' => [], 'discount' => 'none', 'vat' => 'AT', 'gross' => '34.80']);
        $billable = $root->billable;

        $planIndex = null;
        foreach ((array) $root->line_items as $index => $line) {
            if ((int) ($line['amount_net'] ?? 0) === 2900) {
                $planIndex = (int) $index;
            }
        }

        expect((int) $root->amount_net)->toBe(2900)
            ->and($planIndex)->not->toBeNull('the 29.00 plan line');

        $switch = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->createPlanSwitchInvoice(
            $billable,
            [\GraystackIT\MollieBilling\Support\ProrataLine::fromArray([
                'kind' => 'plan', 'code' => 'business', 'label' => 'Business', 'quantity' => 1,
                'amount_net' => 1450, 'vat_rate' => 20.0, 'vat_amount' => 290, 'amount_gross' => 1740,
                'direction' => 'charge',
            ])],
            [\GraystackIT\MollieBilling\Support\ProrataLine::fromArray([
                'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
                'amount_net' => -1450, 'vat_rate' => 20.0, 'vat_amount' => -290, 'amount_gross' => -1740,
                'parent_invoice_id' => $root->id, 'parent_line_item_index' => $planIndex,
                'direction' => 'refund',
            ])],
        );

        // The shape a plan change leaves behind: no payment, settled out of the renewal.
        expect($switch->mollie_payment_id)->toBeNull()
            ->and((int) $switch->settled_by_invoice_id)->toBe((int) $root->id)
            ->and((int) $switch->amount_net)->toBe(0)
            ->and($switch->remainingRefundableNet())->toBe(1450)
            ->and($root->fresh()->remainingRefundableNet())->toBe(1450);

        $lineSelect = 'select[wire\\:model\\.live="refundLine"]';
        $amountField = 'input[wire\\:model="refundAmount"]';
        $submit = 'form[wire\\:submit="refund"] button[type="submit"]';

        $this->browse(function (Browser $browser) use ($billable, $switch, $lineSelect, $amountField, $submit): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            // The switch's row offers the refund …
            $this->clickWire($browser, '[wire\\:click="openRefund('.$switch->id.')"]');
            $browser->waitFor($lineSelect, 10)->pause(400);

            // … with its own charge line only — the credit against the renewal is not its line.
            expect($this->domCount($browser, $lineSelect.' option[value="0"]'))->toBe(1, 'Business line offered')
                ->and($this->domCount($browser, $lineSelect.' option[value="1"]'))->toBe(0, 'credit line not offered');

            $browser->select($lineSelect, '0');
            $browser->waitUsing(10, 200, fn () => $browser->inputValue($amountField) === BillingMoney::numberFromMinor(1450));
            $this->shot($browser, 'Plan switch: Business line chosen');

            $browser->type($amountField, BillingMoney::numberFromMinor(1000));
            $this->clickWire($browser, $submit);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);

            // Again: 4.50 left on the line, one cent more refused.
            $this->clickWire($browser, '[wire\\:click="openRefund('.$switch->id.')"]');
            $browser->waitFor($lineSelect, 10)->pause(400);
            $browser->select($lineSelect, '0');
            $browser->waitUsing(10, 200, fn () => $browser->inputValue($amountField) === BillingMoney::numberFromMinor(450));
            $browser->type($amountField, BillingMoney::numberFromMinor(451));
            $this->clickWire($browser, $submit);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => BillingMoney::format(450)]), 15);
            $this->shot($browser, 'Plan switch: more than the line has left');
        });

        $creditNote = BillingInvoice::query()
            ->where('invoice_kind', InvoiceKind::Refund)
            ->where('billable_id', $billable->getKey())
            ->sole();
        $lines = array_values((array) $creditNote->line_items);

        // Booked on the switch …
        expect((int) $creditNote->amount_net)->toBe(-1000)
            ->and((int) $creditNote->amount_vat)->toBe(-200)
            ->and((int) $creditNote->amount_gross)->toBe(-1200)
            ->and($lines)->toHaveCount(1)
            ->and((int) $lines[0]['parent_invoice_id'])->toBe((int) $switch->id)
            ->and((int) $lines[0]['parent_line_item_index'])->toBe(0)
            ->and($switch->fresh()->remainingRefundableNet())->toBe(450)
            ->and($root->fresh()->remainingRefundableNet())->toBe(1450);

        // … paid out through the renewal's payment, once, for 12.00.
        $refunds = DB::table('fake_mollie_refunds')->get();

        expect($refunds)->toHaveCount(1)
            ->and((string) $refunds->first()->payment_id)->toBe((string) $root->mollie_payment_id)
            ->and((string) $refunds->first()->amount_value)->toBe('12.00');
    }

    public function test_reason_other_demands_a_text_in_the_operators_language(): void
    {
        $this->serverConfig('mollie-billing.admin_locale', 'de');

        $invoice = $this->invoiceFor(['plan' => 'starter', 'seats' => 0, 'addons' => [], 'discount' => 'none', 'vat' => 'DE', 'gross' => '11.78']);
        $billable = $invoice->billable;

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            app()->setLocale('de');
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor('select[wire\\:model="refundReason"]', 10)->pause(400)
                ->select('select[wire\\:model="refundReason"]', 'other')
                ->type('input[wire\\:model="refundAmount"]', BillingMoney::numberFromMinor(100));
            $this->clickWire($browser, 'form[wire\\:submit="refund"] button[type="submit"]');

            $browser->waitForText(__('billing::admin.billables.invoices.flash.reason_text_required'), 15);
            $this->shot($browser, 'Sonstiges verlangt einen Text');
        });

        app()->setLocale('en');

        expect(DB::table('fake_mollie_refunds')->count())->toBe(0);
    }

    public function test_pdf_download_and_regeneration(): void
    {
        $invoice = $this->invoiceFor(['plan' => 'ops-team', 'seats' => 4, 'addons' => ['ops-crm'], 'discount' => 'none', 'vat' => 'AT', 'gross' => '108.00']);
        $billable = $invoice->billable;
        $disk = Storage::disk((string) config('mollie-billing.invoices.disk'));

        expect($invoice->hasPdf())->toBeTrue();
        $path = (string) $invoice->pdf_path;

        $this->browse(function (Browser $browser) use ($billable, $invoice, $disk, $path): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            // The download link the row offers answers with a PDF.
            $href = (string) $browser->attribute('a[href*="/invoices/'.$invoice->id.'"]', 'href');
            $download = $this->opsFetch($browser, $href);

            expect($download['status'])->toBe(200)
                ->and($download['type'])->toContain('pdf')
                ->and($download['head'])->toStartWith('%PDF')
                ->and($download['length'])->toBe($disk->size($path));

            // The file is lost; "regenerate" writes it again.
            $disk->delete($path);
            expect($disk->exists($path))->toBeFalse();

            $this->clickWire($browser, '[wire\\:click="regeneratePdf('.$invoice->id.')"]');
            $browser->waitForText(__('billing::admin.billables.invoices.flash.pdf_regenerated'), 20)->pause(400);
            $this->shot($browser, 'PDF regenerated');
        });

        $invoice->refresh();

        expect($invoice->hasPdf())->toBeTrue()
            ->and($disk->exists((string) $invoice->pdf_path))->toBeTrue();

        // A customer cannot use the admin route, and gets their own.
        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15);

            $own = $this->opsFetch($browser, route('billing.invoice.download', ['invoice' => $invoice]));
            expect($own['status'])->toBe(200)->and($own['head'])->toStartWith('%PDF');

            $admin = $this->opsFetch($browser, route('billing.admin.invoice.download', ['invoice' => $invoice]));
            expect($admin['status'])->not->toBe(200);
        });
    }

    /**
     * The invoice a case refunds: a renewal in the given world, or an overage charge.
     *
     * @param  array<string, mixed>  $case
     */
    private function invoiceFor(array $case): BillingInvoice
    {
        $billable = $this->opsSubscriber($case['plan'], $case['seats'], $case['addons'], $case['vat']);

        if ($case['discount'] === 'overage') {
            // 25 Tokens past the quota at Starter's 12 ct.
            return $this->opsOverageInvoice($billable, 'Tokens', 25);
        }

        if ($case['discount'] === 'coupon') {
            $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);

            $this->opsApplyRecurringCoupon($billable, \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
                $catalog, $billable, $case['plan'], 'monthly', $billable->getBillingSeatCount(), $case['addons'],
            ));
        }

        if ($case['discount'] === 'waiver') {
            $this->opsApplyPlanWaiver($billable);
        }

        return $this->opsRenew($billable, $case['gross']);
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }
}

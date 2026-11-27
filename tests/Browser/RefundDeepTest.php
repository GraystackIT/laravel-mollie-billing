<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Refunds off the straight way, with the operator's panel and the customer's portal side by
 * side:
 *
 *  - a refund by line from the admin modal, then more than the line has left (25);
 *  - Mollie refusing a refund the operator issued (26);
 *  - a full refund of an overage invoice returning its units, and a stale second
 *    "refund all" from another window (27);
 *  - a renewal Mollie collected after the customer cancelled — returned in full (28);
 *  - a chargeback on a renewal, and its reversal (29).
 *
 * Every scenario checks what is on screen AND what the books and the fake Mollie hold —
 * a refund that reads right and paid twice, or paid once and reads wrong, is the bug.
 * Notifications are read from the workbench mailbox (`mailbox` = 1), which records what
 * either process sent: the operator's refund runs in the Dusk server, the webhooks here.
 */
class RefundDeepTest extends BrowserTestCase
{
    use OperationsWorkbench;

    private const LINE_SELECT = 'select[wire\\:model\\.live="refundLine"]';

    private const AMOUNT = 'input[wire\\:model="refundAmount"]';

    private const SUBMIT = 'form[wire\\:submit="refund"] button[type="submit"]';

    private const REFUND_FULL = '[wire\\:click="refundFull"]';

    protected function setUp(): void
    {
        parent::setUp();

        WorkbenchSetting::set('mailbox', '1');
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }

    // ── 25. A refund by line, then more than the line has left ──────────────

    /**
     * By hand, DE 19 %: Pro 29.00 + Softdrinks 4.90 = 33.90 net, VAT 5.51 + 0.93, paid 40.34.
     * 20.00 of the plan line → VAT 3.80, gross 23.80; 9.00 left on the line, 13.90 on the
     * invoice. 9.01 on the line is refused with the line's 9.00, and nothing moves.
     */
    public function test_a_refund_by_line_reduces_what_the_modal_offers_and_refuses_more_than_the_line_has_left(): void
    {
        $billable = $this->opsSubscriber('pro', 0, ['softdrinks'], 'DE');
        $invoice = $this->opsRenew($billable, '40.34');

        $planLine = $this->refundableLine($invoice, 2900);

        $this->assertSame(3390, (int) $invoice->amount_net, 'renewal net');
        $this->assertSame(4034, (int) $invoice->amount_gross, 'renewal gross');

        $this->browse(function (Browser $browser) use ($billable, $invoice, $planLine): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->openRefundModal($browser, $invoice);

            // Fresh invoice: everything is on offer, nothing said about earlier refunds.
            $browser->assertDontSee(__('billing::admin.billables.invoices.modal.partially_refunded'));
            $this->assertModalOffersInFull($browser, 3390);

            $browser->select(self::LINE_SELECT, (string) $planLine['index']);
            $browser->waitUsing(10, 200, fn (): bool => $browser->inputValue(self::AMOUNT) === BillingMoney::numberFromMinor(2900));
            $browser->type(self::AMOUNT, BillingMoney::numberFromMinor(2000));
            $this->shot($browser, 'Refund 20.00 of the plan line');

            $this->clickWire($browser, self::SUBMIT);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);

            $creditNote = $this->creditNotesOf($billable)->sole();

            // The tab lists the credit note with its negative amounts, and the invoice as
            // partially refunded.
            $browser->waitForText((string) $creditNote->serial_number, 15);
            $this->assertTrue($this->opsSeesMoney($browser, -2380), 'credit note gross -23.80 in the tab');
            $this->assertTrue($this->opsSeesMoney($browser, -2000), 'credit note net -20.00 in the tab');
            $browser->assertSee(__('billing::admin.billables.invoices.refunded_suffix'));
            $this->shot($browser, 'Credit note listed');

            // Reopened: what was refunded, what is left of the invoice and of the line.
            $this->openRefundModal($browser, $invoice);
            $browser->assertSee(__('billing::admin.billables.invoices.modal.partially_refunded'));
            $this->assertPageContains($browser, __('billing::admin.billables.invoices.modal.partial_summary', [
                'refunded' => BillingMoney::format(2000),
                'total' => BillingMoney::format(3390),
                'remaining' => BillingMoney::format(1390),
            ]), 'partial summary');
            $this->assertPageContains($browser, __('billing::admin.billables.invoices.modal.refund_full', ['amount' => BillingMoney::format(1390)]), 'refund-all button offers the rest');
            $this->assertPageContains($browser, __('billing::admin.billables.invoices.modal.line_option', [
                'label' => $planLine['label'],
                'amount' => BillingMoney::format(900),
            ]), 'plan line offers its remainder');

            // More than the line has left: refused, naming the line's remainder.
            $browser->select(self::LINE_SELECT, (string) $planLine['index']);
            $browser->waitUsing(10, 200, fn (): bool => $browser->inputValue(self::AMOUNT) === BillingMoney::numberFromMinor(900));
            $browser->type(self::AMOUNT, BillingMoney::numberFromMinor(901));
            $this->clickWire($browser, self::SUBMIT);

            $browser->waitForText(__('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => BillingMoney::format(900)]), 15);
            $browser->assertDontSee(__('billing::admin.billables.invoices.flash.refund_processed'));
            $this->shot($browser, 'Refused: more than the line has left');

            // Even though the invoice as a whole still has 13.90: it is the line that caps it.
            $browser->type(self::AMOUNT, BillingMoney::numberFromMinor(1000));
            $this->clickWire($browser, self::SUBMIT);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => BillingMoney::format(900)]), 15);

            // The page after a reload still lists one credit note.
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->assertSame(1, substr_count($this->opsPageText($browser), (string) $creditNote->serial_number), 'one credit note row');
        });

        $creditNote = $this->creditNotesOf($billable)->sole();
        $lines = array_values((array) $creditNote->line_items);

        $this->assertSame(-2000, (int) $creditNote->amount_net);
        $this->assertSame(-380, (int) $creditNote->amount_vat);
        $this->assertSame(-2380, (int) $creditNote->amount_gross);
        $this->assertCount(1, $lines);
        $this->assertSame($planLine['index'], (int) $lines[0]['parent_line_item_index']);
        $this->assertSame(1390, $invoice->fresh()->remainingRefundableNet());
        $this->assertSame(900, $this->refundableLine($invoice, 2900)['remaining_net']);

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds, 'one refund reached Mollie');
        $this->assertSame('23.80', (string) $refunds->first()->amount_value);
        $this->assertSame((string) $invoice->mollie_payment_id, (string) $refunds->first()->payment_id);
    }

    // ── 26. Mollie refuses the refund ────────────────────────────────────────

    public function test_a_refund_mollie_refuses_books_nothing_tells_the_operator_and_can_be_retried_once(): void
    {
        WorkbenchSetting::set('admin_mailbox', 'operator@workbench.test');

        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $invoice = $this->opsRenew($billable, '11.78');

        WorkbenchSetting::set('refuse_refund', '1');

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');

            $this->openRefundModal($browser, $invoice);
            $this->refundAllConfirmed($browser, 990);

            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_failed'), 20)->pause(400);
            $browser->assertDontSee(__('billing::admin.billables.invoices.flash.refund_processed'));
            $this->shot($browser, 'Mollie refused the refund');

            // Nothing booked: the invoice reads as untouched, and offers all of itself again.
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $browser->assertDontSee(__('billing::admin.billables.invoices.fully_refunded'))
                ->assertDontSee(__('billing::admin.billables.invoices.refunded_suffix'));
            $this->assertSame(1, $this->domCount($browser, '[wire\\:click="openRefund('.$invoice->id.')"]'), 'refund still offered');

            $this->openRefundModal($browser, $invoice);
            $browser->assertDontSee(__('billing::admin.billables.invoices.modal.partially_refunded'));
            $this->assertModalOffersInFull($browser, 990);
            $this->shot($browser, 'Still fully refundable');
        });

        $this->assertSame('1', WorkbenchSetting::get('refused_refunds'), 'the refusal was reached');
        $this->assertCount(0, $this->creditNotesOf($billable), 'no credit note');
        $this->assertSame(0, $invoice->fresh()->effectiveRefundedNet());
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count());

        // The operator was told — once, at the address the app names.
        $mail = $this->mailbox(AdminRefundFailedNotification::class);
        $this->assertCount(1, $mail, 'one AdminRefundFailedNotification');
        $this->assertStringContainsString('operator@workbench.test', (string) $mail->first()->recipient);

        // Mollie takes it again: the retry from the same panel pays once.
        WorkbenchSetting::forget('refuse_refund');

        // ...at once. Mollie binds a key to a refusal for an hour (measured 26 November 2026,
        // tests/Sandbox/MollieIdempotencyContractTest; the fake does the same), so the retry
        // carries a new one: the refused attempt was counted and moved the key on
        // (MollieRefusal). The same key would have got the 422 again, whatever changed.

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->openRefundModal($browser, $invoice);
            $this->refundAllConfirmed($browser, 990);

            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);
            $browser->assertSee(__('billing::admin.billables.invoices.fully_refunded'));
            $this->shot($browser, 'Retried: refunded');
        });

        $creditNote = $this->creditNotesOf($billable)->sole();
        $this->assertSame(-1178, (int) $creditNote->amount_gross);
        $this->assertTrue($invoice->fresh()->isFullyRefunded());

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds);
        $this->assertSame('11.78', (string) $refunds->first()->amount_value);
        $this->assertCount(1, $this->mailbox(AdminRefundFailedNotification::class), 'no second failure mail');
    }

    // ── 27. Full refund of an overage invoice, and a stale second click ─────

    public function test_a_full_refund_of_an_overage_invoice_returns_its_units_once_even_from_a_stale_second_window(): void
    {
        $billable = $this->opsSubscriber('starter', 0, [], 'DE');

        // 25 Tokens past the quota at Starter's 12 ct: 3.00 net, 3.57 gross.
        $invoice = $this->opsOverageInvoice($billable, 'Tokens', 25);

        $this->assertSame(InvoiceKind::Overage, $invoice->invoice_kind);
        $this->assertSame(300, (int) $invoice->amount_net);

        $before = $this->tokens($billable);

        $this->browse(function (Browser $customer) use ($billable, $before): void {
            $this->actAs($customer, $billable, '/billing/usage');
            $customer->waitFor('[dusk="usage-meter-Tokens-plan"]', 15)->pause(300);
            $this->assertSame($this->meterText($billable, $before), $customer->text('[dusk="usage-meter-Tokens-plan"]'));
            $this->shot($customer, 'Usage before the refund');
        });

        $this->browse(function (Browser $first, Browser $second) use ($billable, $invoice): void {
            // Two operators, each with the refund modal open on the same invoice.
            foreach ([$first, $second] as $operator) {
                $this->loginAdmin($operator);
                $this->opsOpenBillableTab($operator, $billable, 'invoices');
                $this->openRefundModal($operator, $invoice);
                $this->assertModalOffersInFull($operator, 300);
            }

            $this->refundAllConfirmed($first, 300);
            $first->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(600);
            $first->assertSee(__('billing::admin.billables.invoices.fully_refunded'));
            $this->shot($first, 'First operator: refunded in full');

            // The second window still shows the button for the full amount — and is told.
            $this->refundAllConfirmed($second, 300);
            $second->waitForText(__('billing::admin.billables.invoices.flash.already_refunded'), 20)->pause(400);
            $second->assertDontSee(__('billing::admin.billables.invoices.flash.refund_processed'));
            $this->shot($second, 'Second operator: already refunded');
        });

        $after = $this->tokens($billable);

        $this->assertSame($before + 25, $after, 'the wallet got back exactly the invoiced units');

        $creditNote = $this->creditNotesOf($billable)->sole();
        $this->assertSame(-300, (int) $creditNote->amount_net);
        $this->assertSame(-357, (int) $creditNote->amount_gross);
        $this->assertSame(['Tokens' => 25], (array) ($creditNote->line_items[0]['wallet_credits'] ?? []));
        $this->assertTrue($invoice->fresh()->isFullyRefunded());

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds, 'one refund at Mollie');
        $this->assertSame('3.57', (string) $refunds->first()->amount_value);

        $this->browse(function (Browser $customer) use ($billable, $after, $creditNote): void {
            $this->actAs($customer, $billable, '/billing/usage');
            $customer->waitFor('[dusk="usage-meter-Tokens-plan"]', 15)->pause(300);
            $this->assertSame($this->meterText($billable, $after), $customer->text('[dusk="usage-meter-Tokens-plan"]'));
            $this->shot($customer, 'Usage after the refund: 25 Tokens back');

            $customer->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$creditNote->id.'"]', 15);
            $this->assertShownAmount($customer, 'invoice-'.$creditNote->id.'-gross', -357);
        });
    }

    // ── 28. A renewal collected after the customer cancelled ────────────────

    /**
     * The customer cancels at period end on the dashboard; a renewal Mollie had already
     * created (a SEPA debit created on the cycle day settles days later) is collected
     * anyway. The package books it, returns it in full, and tells the customer — the
     * subscription stays cancelled.
     *
     * Pro + Softdrinks, DE: 33.90 net, paid 40.34 — two lines, so the return has to be the
     * whole payment, not the plan line's share.
     */
    public function test_a_renewal_collected_after_the_customer_cancelled_is_returned_in_full_and_the_subscription_stays_cancelled(): void
    {
        $billable = $this->opsSubscriber('pro', 0, ['softdrinks'], 'DE');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $periodStartBefore = $billable->getBillingPeriodStartsAt()?->toIso8601String();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="subscription-status"]', 15);
            $this->assertSame(SubscriptionStatus::Active->label(), $browser->text('[dusk="subscription-status"]'));

            $browser->press(__('billing::portal.cancel_subscription'));
            $browser->whenAvailable('dialog', fn () => null);
            $this->clickWire($browser, '[wire\\:click="cancelSubscription"]');
            $browser->waitFor('[dusk="cancelled-banner"]', 15)->pause(400);
            $this->shot($browser, 'Cancelled at period end');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $endsAt = $billable->getBillingSubscriptionEndsAt()?->toIso8601String();
        $this->assertNotNull($endsAt, 'cancelled with an end date');

        // Mollie collects the renewal it had already created on the cycle day.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['next_payment_date' => now()->toDateString()]);
        $payment = FakeMollie::chargeSubscription($subscriptionId, '40.34');
        $this->assertSame(200, $this->deliverWebhook($payment['id']));

        $renewal = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();
        $creditNote = $this->creditNotesOf($billable)->sole();

        $this->assertSame(4034, (int) $renewal->amount_gross);
        $this->assertSame(-4034, (int) $creditNote->amount_gross, 'credit note of the same amount');
        $this->assertSame(-(int) $renewal->amount_net, (int) $creditNote->amount_net, 'net back to zero');
        $this->assertSame(-(int) $renewal->amount_vat, (int) $creditNote->amount_vat, 'VAT back to zero');
        $this->assertTrue($renewal->fresh()->isFullyRefunded());

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds, 'one refund at Mollie');
        $this->assertSame('40.34', (string) $refunds->first()->amount_value);
        $this->assertSame($payment['id'], (string) $refunds->first()->payment_id);

        // A re-delivery (Mollie calls again once the refund exists) changes nothing.
        $this->assertSame(200, $this->deliverWebhook($payment['id']));
        $this->assertCount(1, $this->creditNotesOf($billable));
        $this->assertSame(1, DB::table('fake_mollie_refunds')->count());

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus(), 'still cancelled');
        $this->assertSame($endsAt, $billable->getBillingSubscriptionEndsAt()?->toIso8601String(), 'end date untouched');
        $this->assertSame($periodStartBefore, $billable->getBillingPeriodStartsAt()?->toIso8601String(), 'no new period booked');
        $this->assertNotNull(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('canceled_at'), 'cancelled at Mollie');

        // The customer was told, once.
        $mail = $this->mailbox(UnwantedPaymentRefundedNotification::class);
        $this->assertCount(1, $mail, 'one UnwantedPaymentRefundedNotification');
        $this->assertStringContainsString((string) $billable->email, (string) $mail->first()->recipient);

        $this->browse(function (Browser $browser) use ($billable, $renewal, $creditNote): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$renewal->id.'"]', 15)
                ->waitFor('[dusk="invoice-row-'.$creditNote->id.'"]', 15);
            $this->assertShownAmount($browser, 'invoice-'.$renewal->id.'-gross', 4034);
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->id.'-gross', -4034);
            $this->assertStringContainsString('-', $browser->text('[dusk="invoice-'.$creditNote->id.'-gross"]'), 'credit note shown negative');

            // The tiles: paid once, credited in full, nothing spent.
            $this->assertShownAmount($browser, 'stats-paid', 4034, 'total paid');
            $this->assertShownAmount($browser, 'stats-credits', 4034, 'credits');
            $this->assertShownAmount($browser, 'stats-spent', 0, 'spent');
            $this->shot($browser, 'Renewal and its credit note');

            // The dashboard still says cancelled — not active again.
            $browser->visit('/billing')->waitFor('[dusk="subscription-status"]', 15);
            $this->assertSame(SubscriptionStatus::Cancelled->label(), $browser->text('[dusk="subscription-status"]'));
            $browser->assertPresent('[dusk="cancelled-banner"]')
                ->assertSee(__('billing::portal.resubscribe'));
            $this->shot($browser, 'Dashboard: still cancelled');
        });
    }

    // ── 28b. A second first payment for a running subscription ──────────────

    /**
     * A second checkout tab's first payment settles after the subscription is already
     * running. The package books it and returns it in full (flows.md §13), the subscription
     * runs on, and the customer sees the payment and its credit note side by side.
     */
    public function test_a_second_first_payment_for_a_running_subscription_is_booked_and_returned_in_full(): void
    {
        $billable = $this->opsSubscriber('pro', 0, [], 'DE');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        $payment = FakeMollie::createPayment([
            'amount' => ['value' => '34.51', 'currency' => 'EUR'],
            'description' => 'Pro — second tab',
            'sequenceType' => 'first',
            'customerId' => $billable->getMollieCustomerId(),
            'metadata' => [
                'type' => 'subscription',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'plan_code' => 'pro',
                'interval' => 'monthly',
            ],
        ]);
        FakeMollie::settle((string) $payment['id'], 'paid');
        $this->assertSame(200, $this->deliverWebhook((string) $payment['id']));

        $duplicate = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();
        $creditNote = $this->creditNotesOf($billable)->sole();

        $this->assertSame(3451, (int) $duplicate->amount_gross);
        $this->assertSame(-3451, (int) $creditNote->amount_gross, 'credit note of the same amount');
        $this->assertSame(-(int) $duplicate->amount_vat, (int) $creditNote->amount_vat, 'VAT back to zero');

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds, 'one refund at Mollie');
        $this->assertSame('34.51', (string) $refunds->first()->amount_value);
        $this->assertSame((string) $payment['id'], (string) $refunds->first()->payment_id);

        // A re-delivery (Mollie calls again once the refund exists) changes nothing.
        $this->assertSame(200, $this->deliverWebhook((string) $payment['id']));
        $this->assertCount(1, $this->creditNotesOf($billable));
        $this->assertSame(1, DB::table('fake_mollie_refunds')->count());

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus(), 'still active');
        $this->assertSame($subscriptionId, (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'], 'the running subscription is kept');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->where('customer_id', $billable->getMollieCustomerId())->count(), 'no second subscription');

        $mail = $this->mailbox(UnwantedPaymentRefundedNotification::class);
        $this->assertCount(1, $mail, 'one UnwantedPaymentRefundedNotification');

        $this->browse(function (Browser $browser) use ($billable, $duplicate, $creditNote): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$duplicate->id.'"]', 15)
                ->waitFor('[dusk="invoice-row-'.$creditNote->id.'"]', 15);
            $this->assertShownAmount($browser, 'invoice-'.$duplicate->id.'-gross', 3451);
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->id.'-gross', -3451);
            $this->shot($browser, 'Duplicate payment and its credit note');

            $browser->visit('/billing')->waitFor('[dusk="subscription-status"]', 15);
            $this->assertSame(SubscriptionStatus::Active->label(), $browser->text('[dusk="subscription-status"]'));
        });
    }

    // ── 29. A chargeback on a renewal, and its reversal ─────────────────────

    public function test_a_chargeback_on_a_renewal_puts_the_customer_past_due_and_its_reversal_restores_them(): void
    {
        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $renewal = $this->opsRenew($billable, '11.78');
        $paymentId = (string) $renewal->mollie_payment_id;

        $this->assertSame(SubscriptionStatus::Active, $billable->refresh()->getBillingSubscriptionStatus());

        // The bank pulls the whole renewal back.
        $chargebackId = FakeMollie::chargeback($paymentId);
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $this->assertSame('chargeback', $billable->getBillingSubscriptionMeta()['payment_failure']['reason'] ?? null);

        $creditNote = $this->creditNotesOf($billable)->sole();
        $this->assertSame(-1178, (int) $creditNote->amount_gross);
        $this->assertSame(RefundReasonCode::Chargeback, $creditNote->refund_reason_code);
        $this->assertSame($chargebackId, $creditNote->line_items[0]['mollie_chargeback_id'] ?? null);
        $this->assertTrue($renewal->fresh()->isFullyRefunded());

        // Delivered again unchanged: nothing booked twice.
        $this->assertSame(200, $this->deliverWebhook($paymentId));
        $this->assertCount(1, $this->creditNotesOf($billable));
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count(), 'a chargeback is not refunded on top');

        $this->browse(function (Browser $browser) use ($billable, $creditNote): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="past-due-banner"]', 15);
            $browser->assertSeeIn('[dusk="past-due-banner"]', __('billing::portal.past_due_banner'));
            $this->assertSame(SubscriptionStatus::PastDue->label(), $browser->text('[dusk="subscription-status"]'));
            $this->shot($browser, 'Charged back: past due');

            $browser->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$creditNote->id.'"]', 15);
            $this->assertShownAmount($browser, 'invoice-'.$creditNote->id.'-gross', -1178);
            $this->assertStringContainsString('-', $browser->text('[dusk="invoice-'.$creditNote->id.'-gross"]'));
            $this->assertShownAmount($browser, 'stats-paid', 1178, 'total paid');
            $this->assertShownAmount($browser, 'stats-credits', 1178, 'credits');
            $this->assertShownAmount($browser, 'stats-spent', 0, 'spent');
            $this->shot($browser, 'Chargeback credit note listed');
        });

        // The dispute is won: the same payment, its chargeback now reversed.
        FakeMollie::reverseChargeback($chargebackId);
        $this->assertSame(200, $this->deliverWebhook($paymentId));

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus(), 'active again');
        $this->assertArrayNotHasKey('past_due_since', $billable->getBillingSubscriptionMeta());

        $notes = $this->creditNotesOf($billable);
        $this->assertCount(2, $notes, 'the chargeback note and its reversal');
        $reversal = $notes->firstWhere('id', '!=', $creditNote->id);
        $this->assertSame(1178, (int) $reversal->amount_gross, 'the reversal puts the money back');
        $this->assertSame(0, $renewal->fresh()->effectiveRefundedNet(), 'the renewal stands again');

        // And once more: the reversal is not booked twice.
        $this->assertSame(200, $this->deliverWebhook($paymentId));
        $this->assertCount(2, $this->creditNotesOf($billable));

        $this->browse(function (Browser $browser) use ($billable, $creditNote, $reversal): void {
            $this->actAs($browser, $billable);
            $browser->waitFor('[dusk="subscription-status"]', 15);
            $this->assertSame(SubscriptionStatus::Active->label(), $browser->text('[dusk="subscription-status"]'));
            $browser->assertMissing('[dusk="past-due-banner"]')
                ->assertDontSee(__('billing::portal.past_due_banner'));
            $this->shot($browser, 'Chargeback reversed: active again');

            $browser->visit('/billing/invoices')
                ->waitFor('[dusk="invoice-row-'.$reversal->id.'"]', 15)
                ->assertPresent('[dusk="invoice-row-'.$creditNote->id.'"]');
            $this->assertShownAmount($browser, 'invoice-'.$reversal->id.'-gross', 1178);
            $this->assertStringNotContainsString('-', $browser->text('[dusk="invoice-'.$reversal->id.'-gross"]'), 'reversal shown positive');

            // The reversal is the credit taken back, not a second payment: the renewal was
            // paid once, nothing stays credited (read 23.56 paid / 11.78 credits before).
            $this->assertShownAmount($browser, 'stats-paid', 1178, 'total paid after the reversal');
            $this->assertShownAmount($browser, 'stats-credits', 0, 'credits after the reversal');
            $this->assertShownAmount($browser, 'stats-spent', 1178, 'spent after the reversal');
            $this->shot($browser, 'Reversal document listed');
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function openRefundModal(Browser $browser, BillingInvoice $invoice): void
    {
        $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
        $browser->waitFor(self::AMOUNT, 10)->pause(400);
    }

    /** The "refund all" button, and the confirm dialog that names the amount. */
    private function refundAllConfirmed(Browser $browser, int $net): void
    {
        $this->clickWire($browser, self::REFUND_FULL);
        $browser->pause(300);

        $confirm = $browser->driver->switchTo()->alert()->getText();
        $this->assertStringContainsString(
            $this->opsNormaliseMoney(BillingMoney::format($net)),
            $this->opsNormaliseMoney($confirm),
            'confirmation names the amount',
        );

        $browser->acceptDialog();
    }

    private function assertModalOffersInFull(Browser $browser, int $net): void
    {
        $this->assertPageContains(
            $browser,
            __('billing::admin.billables.invoices.modal.refund_full', ['amount' => BillingMoney::format($net)]),
            'refund-all offers '.$net,
        );
    }

    private function assertPageContains(Browser $browser, string $expected, string $what): void
    {
        $this->assertStringContainsString(
            $this->opsNormaliseMoney($expected),
            $this->opsNormaliseMoney($this->opsPageText($browser)),
            $what,
        );
    }

    /**
     * The refundable line whose original net is `$net`.
     *
     * @return array{index: int, label: ?string, net: int, rate: float, remaining_net: int, remaining_vat: int}
     */
    private function refundableLine(BillingInvoice $invoice, int $net): array
    {
        foreach (app(InvoiceService::class)->refundableLines($invoice->fresh(), true) as $line) {
            if ($line['net'] === $net) {
                return $line;
            }
        }

        $this->fail('No refundable line of '.$net);
    }

    /** @return \Illuminate\Support\Collection<int, BillingInvoice> */
    private function creditNotesOf(TestBillable $billable): \Illuminate\Support\Collection
    {
        return BillingInvoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Refund)
            ->orderBy('id')
            ->get();
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

    /** The usage meter's plan line for a Tokens balance, worked out as the meter does. */
    private function meterText(TestBillable $billable, int $balance): string
    {
        $included = $billable->fresh()->grantedBillingQuota('Tokens');
        $remaining = max(0, min($included, $balance));
        // Tokens roll over in the workbench catalog: what lies above the quota is named.
        $carried = max(0, $balance - $included);

        $text = __('billing::portal.usage_plan_remaining', [
            'remaining' => number_format($remaining),
            'total' => number_format($included),
        ]);

        return $carried > 0
            ? $text.' '.__('billing::portal.usage_carried_over', ['count' => number_format($carried)])
            : $text;
    }
}

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsLineAmountException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Refunds by line: an operator refunds one line of an invoice — the addon a customer
 * never used, the seats that were booked by mistake — and the credit note says which
 * line it reverses, taxed at that line's own rate and capped at what is left of it.
 *
 * Every figure below is worked out by hand. The invoice: Pro 30.00 at 20 % (VAT 6.00)
 * plus an addon 9.00 at 10 % (VAT 0.90) — two rates, so a refund taxed at the wrong one
 * shows at once.
 */

final class LineRefundRecordingService extends RefundInvoiceService
{
    /** @var list<int> */
    public static array $grossSentToMollie = [];

    /** @var list<string> */
    public static array $paymentIds = [];

    /** Runs once, right before the next refund: somebody else booking in between. */
    public static ?\Closure $beforeNextRefund = null;

    public function refundLine(BillingInvoice $invoice, int $lineIndex, int $amountNet, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        if (self::$beforeNextRefund !== null) {
            $competitor = self::$beforeNextRefund;
            self::$beforeNextRefund = null;
            $competitor($invoice, $lineIndex);
        }

        return parent::refundLine($invoice, $lineIndex, $amountNet, $reason, $reasonText);
    }

    protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
    {
        self::$grossSentToMollie[] = $grossCents;
        self::$paymentIds[] = $paymentId;

        return 're_line_'.count(self::$grossSentToMollie);
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');

    LineRefundRecordingService::$grossSentToMollie = [];
    LineRefundRecordingService::$paymentIds = [];
    LineRefundRecordingService::$beforeNextRefund = null;

    // The numbers, not the document.
    $this->app->instance(InvoiceService::class, new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            return true;
        }
    });

    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new LineRefundRecordingService(
        $app->make(InvoiceService::class),
        $app->make(WalletUsageService::class),
    ));
});

function lineRefundInvoice(): BillingInvoice
{
    $billable = new TestBillable;
    $billable->forceFill(['email' => 'line-refund@example.com', 'name' => 'Line Refund', 'billing_country' => 'AT'])->save();

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_line_'.uniqid();
    $invoice->serial_number = 'IN-LINE-1';
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 3900;
    $invoice->amount_vat = 690;
    $invoice->amount_gross = 4590;
    $invoice->refunded_net = 0;
    $invoice->line_items = [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600],
        ['kind' => 'addon', 'code' => 'softdrinks', 'label' => 'Softdrinks', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900, 'vat_rate' => 10.0, 'vat_amount' => 90, 'amount_gross' => 990],
    ];
    $invoice->save();

    return $invoice;
}

/** @return list<array<string, mixed>> */
function lineRefundCreditLines(BillingInvoice $creditNote): array
{
    return array_values((array) $creditNote->line_items);
}

it('refunds one line at that line\'s own rate, and the credit note names the line', function (): void {
    $invoice = lineRefundInvoice();

    $creditNote = app(RefundInvoiceService::class)->refundLine($invoice, 1, 900, RefundReasonCode::BillingError);

    // 9.00 at 10 % = 0.90 VAT — not the 20 % of the plan, and not a blend.
    expect((int) $creditNote->amount_net)->toBe(-900)
        ->and((int) $creditNote->amount_vat)->toBe(-90)
        ->and((int) $creditNote->amount_gross)->toBe(-990)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([990]);

    $lines = lineRefundCreditLines($creditNote);

    expect($lines)->toHaveCount(1)
        ->and((int) $lines[0]['parent_invoice_id'])->toBe((int) $invoice->id)
        ->and((int) $lines[0]['parent_line_item_index'])->toBe(1)
        ->and((float) $lines[0]['vat_rate'])->toBe(10.0)
        ->and((int) $lines[0]['amount_net'])->toBe(-900)
        ->and((int) $lines[0]['vat_amount'])->toBe(-90)
        ->and($lines[0]['label'])->toBe(__('billing::portal.credit_note_line_label', ['serial' => 'IN-LINE-1', 'label' => 'Softdrinks']))
        ->and($lines[0]['mollie_refund_id'])->toBe('re_line_1');

    // The plan line is untouched; the invoice has 30.00 left.
    expect($invoice->fresh()->remainingRefundableNet())->toBe(3000)
        ->and($invoice->fresh()->creditedPerOriginalLine())->toBe([1 => ['net' => 900, 'vat' => 90]]);
});

it('refunds part of a line and caps every later refund of it at what is left', function (): void {
    $invoice = lineRefundInvoice();
    $service = app(RefundInvoiceService::class);

    // 4.50 of the addon: 0.45 VAT at 10 %.
    $first = $service->refundLine($invoice, 1, 450, RefundReasonCode::Goodwill);
    expect((int) $first->amount_gross)->toBe(-495);

    // 4.51 is one cent more than the line has left — refused, nothing sent.
    $thrown = null;
    try {
        $service->refundLine($invoice->fresh(), 1, 451, RefundReasonCode::Goodwill);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RefundExceedsLineAmountException::class)
        ->and($thrown)->toBeInstanceOf(RefundExceedsInvoiceAmountException::class)
        ->and($thrown->remainingNet)->toBe(450)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([495]);

    // The rest of the line closes it exactly: 0.90 − 0.45 = 0.45 VAT.
    $second = $service->refundLine($invoice->fresh(), 1, 450, RefundReasonCode::Goodwill);

    expect((int) $second->amount_vat)->toBe(-45)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([495, 495])
        ->and(app(InvoiceService::class)->refundableLines($invoice->fresh()))->toHaveCount(1);
});

it('refuses a line that does not exist or is a discount, before Mollie is called', function (): void {
    $invoice = lineRefundInvoice();
    $lines = (array) $invoice->line_items;
    $lines[] = ['kind' => 'coupon', 'code' => null, 'label' => 'Discount', 'quantity' => 1, 'unit_price_net' => -100, 'amount_net' => -100, 'vat_rate' => 20.0, 'vat_amount' => -20, 'amount_gross' => -120];
    $invoice->line_items = $lines;
    $invoice->amount_net = 3800;
    $invoice->amount_vat = 670;
    $invoice->amount_gross = 4470;
    $invoice->save();

    foreach ([2, 7] as $index) {
        expect(fn () => app(RefundInvoiceService::class)->refundLine($invoice->fresh(), $index, 100, RefundReasonCode::Goodwill))
            ->toThrow(InvalidRefundTargetException::class);
    }

    expect(LineRefundRecordingService::$grossSentToMollie)->toBe([]);

    // The discount is folded into the plan line, which carries what was actually paid
    // for it: 30.00 − 1.00 = 29.00 net, 6.00 − 0.20 = 5.80 VAT.
    $offered = app(InvoiceService::class)->refundableLines($invoice->fresh());

    expect(array_column($offered, 'index'))->toBe([0, 1])
        ->and($offered[0]['remaining_net'])->toBe(2900)
        ->and($offered[0]['remaining_vat'])->toBe(580)
        ->and($offered[1]['remaining_net'])->toBe(900)
        ->and($offered[1]['label'])->toBe('Softdrinks');
});

it('lets a later refund of the rest credit what each line has left, not its share of the original', function (): void {
    // The addon line is refunded on its own — here through the existing custom-line API,
    // which is what refundOverageUnits() and a hand-built credit note use too. Then the
    // rest of the invoice goes back. Allocated by each line's ORIGINAL share, 6.92 of the
    // 30.00 landed on the exhausted addon line at 0 VAT and the plan line was taxed on
    // 23.08 only: 4.62 VAT credited instead of 6.00, and the customer short by 1.38.
    $invoice = lineRefundInvoice();

    app(RefundInvoiceService::class)->refundPartially($invoice, 900, RefundReasonCode::BillingError, null, [[
        'kind' => 'refund', 'code' => null, 'label' => 'Softdrinks', 'quantity' => 1,
        'unit_price_net' => -900, 'amount_net' => -900, 'vat_rate' => 10.0, 'vat_amount' => -90, 'amount_gross' => -990,
        'parent_invoice_id' => $invoice->id, 'parent_line_item_index' => 1,
    ]]);

    $rest = app(RefundInvoiceService::class)->refundFully($invoice->fresh(), RefundReasonCode::Goodwill);

    expect((int) $rest->amount_net)->toBe(-3000)
        ->and((int) $rest->amount_vat)->toBe(-600)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([990, 3600])
        ->and($invoice->fresh()->isFullyRefunded())->toBeTrue();

    // Everything went onto the plan line; the addon line got nothing more.
    $byIndex = [];
    foreach (lineRefundCreditLines($rest) as $line) {
        $byIndex[(int) $line['parent_line_item_index']] = (int) $line['amount_net'];
    }

    expect($byIndex)->toBe([0 => -3000]);
});

it('credits a partial refund in proportion to what each line has left', function (): void {
    $invoice = lineRefundInvoice();

    app(RefundInvoiceService::class)->refundLine($invoice, 1, 900, RefundReasonCode::BillingError);

    // 15.00 of the remaining 30.00: all of it is the plan's, at 20 %.
    $half = app(RefundInvoiceService::class)->refundPartially($invoice->fresh(), 1500, RefundReasonCode::Goodwill);

    expect((int) $half->amount_vat)->toBe(-300)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([990, 1800]);
});

it('books a line refund money-only — an overage line gives no wallet units back', function (): void {
    $invoice = lineRefundInvoice();
    $invoice->invoice_kind = InvoiceKind::Overage;
    $invoice->line_items = [
        ['kind' => 'overage', 'code' => 'tokens', 'label' => 'Tokens', 'quantity' => 25, 'unit_price_net' => 12, 'amount_net' => 300, 'vat_rate' => 20.0, 'vat_amount' => 60, 'amount_gross' => 360],
    ];
    $invoice->amount_net = 300;
    $invoice->amount_vat = 60;
    $invoice->amount_gross = 360;
    $invoice->save();

    $creditNote = app(RefundInvoiceService::class)->refundLine($invoice, 0, 120, RefundReasonCode::Goodwill);

    expect((int) $creditNote->amount_gross)->toBe(-144)
        ->and(lineRefundCreditLines($creditNote)[0])->not->toHaveKey('wallet_credits');
});

// ── The admin panel ───────────────────────────────────────────────────────────────────

it('offers the lines in the refund modal, pre-fills a chosen line\'s remainder and books the refund against it', function (): void {
    $invoice = lineRefundInvoice();

    $page = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->assertSee('Softdrinks')
        ->set('refundLine', '1')
        // The line's remainder, typed the way the hint shows amounts.
        ->assertSet('refundAmount', \GraystackIT\MollieBilling\Support\BillingMoney::numberFromMinor(900, 'EUR'));

    // More than the line has left is refused on the panel, in the operator's words.
    $page->set('refundAmount', '9.01')->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.exceeds_line', [
            'amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(900, 'EUR'),
        ]));

    expect(LineRefundRecordingService::$grossSentToMollie)->toBe([]);

    // Less than the line is booked against the line.
    $page->set('refundAmount', '4.00')->call('refund')
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.billables.invoices.flash.refund_processed'));

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    $line = lineRefundCreditLines($creditNote)[0];

    expect((int) $line['parent_line_item_index'])->toBe(1)
        ->and((int) $creditNote->amount_vat)->toBe(-40)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([440]);
});

it('says the line refund refusal in German too, and goes back to the whole invoice', function (): void {
    app()->setLocale('de');
    $invoice = lineRefundInvoice();

    $message = __('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(900, 'EUR')]);
    expect($message)->not->toContain('billing::')
        ->and($message)->not->toBe(__('billing::admin.billables.invoices.flash.exceeds_line', ['amount' => 'x'], 'en'));

    $page = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->set('refundLine', '1')
        ->set('refundAmount', '9,01')
        ->call('refund')
        ->assertSet('error', $message);

    // Back to "the whole invoice": the same amount is spread over the lines.
    $page->set('refundLine', '')->set('refundAmount', '9,01')->call('refund')->assertSet('error', null);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    expect(count(lineRefundCreditLines($creditNote)))->toBe(2);
});

it('refuses a line index the modal never offered', function (): void {
    $invoice = lineRefundInvoice();

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->set('refundLine', '5')
        ->set('refundAmount', '1.00')
        ->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.invalid_line'));

    expect(LineRefundRecordingService::$grossSentToMollie)->toBe([]);
});

// ── Somebody else refunds the same line while the modal is open ───────────────────────

it('refuses the confirmation when the line was partly refunded since the modal opened, with the new remainder', function (): void {
    $invoice = lineRefundInvoice();

    $page = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->set('refundLine', '1')
        ->assertSet('refundAmount', \GraystackIT\MollieBilling\Support\BillingMoney::numberFromMinor(900, 'EUR'));

    // A colleague refunds 5.00 of the same line in the meantime.
    app(RefundInvoiceService::class)->refundLine($invoice->fresh(), 1, 500, RefundReasonCode::Goodwill);

    // The operator confirms the 9.00 the modal had filled in: refused, with what is left.
    $page->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.exceeds_line', [
            'amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(400, 'EUR'),
        ]));

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([550]);
});

it('says the line is already refunded when a colleague refunded all of it since the modal opened', function (): void {
    $invoice = lineRefundInvoice();

    $page = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->set('refundLine', '1');

    app(RefundInvoiceService::class)->refundLine($invoice->fresh(), 1, 900, RefundReasonCode::Goodwill);

    $page->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.line_already_refunded'));

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([990]);
});

it('refuses the refund when the line is booked between the panel\'s check and the refund lock', function (int $competing, string $expected): void {
    // The narrowest window: the panel has checked the line, and the service has not yet
    // taken the invoice's lock. What the service refuses there has to reach the operator
    // as a sentence about the LINE — not as the invoice's remainder, which is larger.
    $invoice = lineRefundInvoice();

    LineRefundRecordingService::$beforeNextRefund = function (BillingInvoice $target, int $lineIndex) use ($competing): void {
        // Called on a plain instance, so the hook does not fire again.
        app(RefundInvoiceService::class)->refundLine($target->fresh(), $lineIndex, $competing, RefundReasonCode::Goodwill);
    };

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $invoice->billable_id])
        ->call('openRefund', $invoice->id)
        ->set('refundLine', '1')
        ->call('refund')
        ->assertSet('error', $expected === 'line_already_refunded'
            ? __('billing::admin.billables.invoices.flash.line_already_refunded')
            : __('billing::admin.billables.invoices.flash.exceeds_line', [
                'amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(900 - $competing, 'EUR'),
            ]));

    // The colleague's refund is the only one: one credit note, one refund at Mollie.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(LineRefundRecordingService::$grossSentToMollie)->toHaveCount(1);
})->with([
    'part of the line' => [500, 'exceeds_line'],
    'all of the line' => [900, 'line_already_refunded'],
]);

// ── A settled plan-switch invoice ─────────────────────────────────────────────────────

/**
 * A renewal (Pro 29.00 at 20 %) and the saldo-zero switch written against it on day 11
 * of 31: Pro B charged for 21 days (29.00 × 21/31 = 19.65, VAT 3.93) and Pro credited the
 * same. No money moved — the switch carries no payment and is settled out of the
 * renewal's, whose `refunded_net` took the credit, exactly as `createPlanSwitchInvoice()`
 * books it.
 *
 * @return array{0: BillingInvoice, 1: BillingInvoice}
 */
function lineRefundSwitchInvoices(): array
{
    $billable = new TestBillable;
    $billable->forceFill(['email' => 'switch-refund@example.com', 'name' => 'Switch Refund', 'billing_country' => 'AT'])->save();

    $root = new BillingInvoice;
    $root->billable_type = $billable->getMorphClass();
    $root->billable_id = $billable->getKey();
    $root->mollie_payment_id = 'tr_switch_root';
    $root->serial_number = 'IN-ROOT-1';
    $root->invoice_kind = InvoiceKind::Subscription;
    $root->status = InvoiceStatus::Paid;
    $root->country = 'AT';
    $root->currency = 'EUR';
    $root->amount_net = 2900;
    $root->amount_vat = 580;
    $root->amount_gross = 3480;
    $root->refunded_net = 1965;
    $root->line_items = [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 20.0, 'vat_amount' => 580, 'amount_gross' => 3480],
    ];
    $root->save();

    $switch = new BillingInvoice;
    $switch->billable_type = $billable->getMorphClass();
    $switch->billable_id = $billable->getKey();
    $switch->mollie_payment_id = null;
    $switch->settled_by_invoice_id = $root->id;
    $switch->serial_number = 'IN-SWITCH-1';
    $switch->invoice_kind = InvoiceKind::Subscription;
    $switch->status = InvoiceStatus::Paid;
    $switch->country = 'AT';
    $switch->currency = 'EUR';
    $switch->amount_net = 0;
    $switch->amount_vat = 0;
    $switch->amount_gross = 0;
    $switch->refunded_net = 0;
    $switch->line_items = [
        ['kind' => 'plan', 'code' => 'pro-b', 'label' => 'Pro B', 'quantity' => 1, 'unit_price_net' => 1965, 'amount_net' => 1965, 'vat_rate' => 20.0, 'vat_amount' => 393, 'amount_gross' => 2358],
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => -1965, 'amount_net' => -1965, 'vat_rate' => 20.0, 'vat_amount' => -393, 'amount_gross' => -2358, 'parent_invoice_id' => $root->id, 'parent_line_item_index' => 0],
    ];
    $switch->save();

    return [$root->fresh(), $switch->fresh()];
}

it('offers the switch invoice\'s own charge line, never the credit it carries against the renewal', function (): void {
    [$root, $switch] = lineRefundSwitchInvoices();

    $offered = app(InvoiceService::class)->refundableLines($switch);

    expect(array_column($offered, 'index'))->toBe([0])
        ->and($offered[0]['label'])->toBe('Pro B')
        ->and($offered[0]['remaining_net'])->toBe(1965)
        ->and($offered[0]['remaining_vat'])->toBe(393);

    // The renewal's line is offered with what the invoice has left, not what the line once
    // charged: 29.00 − 19.65 already spent on the switch = 9.35.
    expect(app(InvoiceService::class)->refundableLines($root)[0]['remaining_net'])->toBe(935);
});

it('refunds a line of a settled switch invoice out of the payment that holds the money', function (): void {
    [$root, $switch] = lineRefundSwitchInvoices();

    // 10.00 of Pro B's 19.65, at 20 %: 2.00 VAT, 12.00 back to the customer.
    $creditNote = app(RefundInvoiceService::class)->refundLine($switch, 0, 1000, RefundReasonCode::Goodwill);
    $line = lineRefundCreditLines($creditNote)[0];

    expect(LineRefundRecordingService::$paymentIds)->toBe(['tr_switch_root'])
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([1200])
        ->and((int) $creditNote->amount_vat)->toBe(-200)
        ->and((int) $line['parent_invoice_id'])->toBe((int) $switch->id)
        ->and((int) $line['parent_line_item_index'])->toBe(0);

    // Booked on the switch, not on the renewal — and together they still hold no more than
    // the payment collected: 29.00 − 10.00.
    expect($switch->fresh()->remainingRefundableNet())->toBe(965)
        ->and($root->fresh()->remainingRefundableNet())->toBe(935)
        ->and($switch->fresh()->remainingRefundableNet() + $root->fresh()->remainingRefundableNet())->toBe(1900);
});

it('refunds the rest of a settled switch invoice by amount the same way, and then reads it as fully refunded', function (): void {
    [$root, $switch] = lineRefundSwitchInvoices();

    $creditNote = app(RefundInvoiceService::class)->refundFully($switch, RefundReasonCode::Goodwill);

    // 19.65 + 3.93 VAT — the switch's header VAT is 0, its own charge line's is not.
    expect((int) $creditNote->amount_net)->toBe(-1965)
        ->and((int) $creditNote->amount_vat)->toBe(-393)
        ->and(LineRefundRecordingService::$paymentIds)->toBe(['tr_switch_root'])
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([2358])
        ->and($switch->fresh()->remainingRefundableNet())->toBe(0)
        ->and($switch->fresh()->isFullyRefunded())->toBeTrue()
        ->and(app(InvoiceService::class)->refundableLines($switch->fresh()))->toBe([]);
});

it('waits for a refund already running on the renewal before refunding its switch', function (): void {
    // The webhook for the renewal's payment and every refund of it serialise on the
    // renewal's lock — the refund of the switch moves the renewal's money, so it takes the
    // same one.
    [$root, $switch] = lineRefundSwitchInvoices();

    $held = \Illuminate\Support\Facades\Cache::lock('billing:invoice-refund:'.$root->id, 120);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => app(RefundInvoiceService::class)->refundLine($switch, 0, 1000, RefundReasonCode::Goodwill))
            ->toThrow(\GraystackIT\MollieBilling\Exceptions\RefundInProgressException::class);
    } finally {
        $held->release();
    }

    expect(LineRefundRecordingService::$grossSentToMollie)->toBe([]);
});

it('refuses a switch invoice whose settlement leads to no payment, in plain words', function (): void {
    [$root, $switch] = lineRefundSwitchInvoices();
    $switch->settled_by_invoice_id = null;
    $switch->save();

    expect(fn () => app(RefundInvoiceService::class)->refundLine($switch->fresh(), 0, 1000, RefundReasonCode::Goodwill))
        ->toThrow(InvalidRefundTargetException::class, 'no Mollie payment to refund against');

    expect(LineRefundRecordingService::$grossSentToMollie)->toBe([]);
});

it('refunds a switch invoice\'s line from the panel', function (): void {
    [$root, $switch] = lineRefundSwitchInvoices();

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $switch->billable_id])
        // The row offers the refund: it has a payment to refund against, through the renewal.
        ->assertSeeHtml('openRefund('.$switch->id.')')
        ->call('openRefund', $switch->id)
        ->assertSee('Pro B')
        ->set('refundLine', '0')
        ->assertSet('refundAmount', \GraystackIT\MollieBilling\Support\BillingMoney::numberFromMinor(1965, 'EUR'))
        ->set('refundAmount', '10.00')
        ->call('refund')
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.billables.invoices.flash.refund_processed'));

    expect(LineRefundRecordingService::$paymentIds)->toBe(['tr_switch_root'])
        ->and(LineRefundRecordingService::$grossSentToMollie)->toBe([1200]);
});

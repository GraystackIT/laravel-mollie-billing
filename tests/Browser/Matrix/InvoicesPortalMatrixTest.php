<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;

/**
 * The customer's invoice page: what is listed, what the totals add up to, what a credit
 * note looks like, and what is in the PDF behind each row.
 */
class InvoicesPortalMatrixTest extends BrowserTestCase
{
    use OperationsWorkbench;

    public function test_invoice_list_totals_credit_note_and_every_kind_of_line_on_the_pdf(): void
    {
        // An invoice with every recurring kind of line: plan, seats, addon, addon seats.
        $billable = $this->opsSubscriber('ops-team', 4, ['ops-crm'], 'AT');
        $renewal = $this->opsRenew($billable, '108.00');

        // An overage invoice: 25 Tokens past the quota at Ops Team's 10 ct.
        $overage = $this->opsOverageInvoice($billable, 'Tokens', 25);

        // A credit note for part of the renewal.
        app(RefundInvoiceService::class)->refundPartially($renewal, 1200, RefundReasonCode::Goodwill, 'Seats were double counted');
        $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

        // An invoice in another currency: listed, but in no total.
        $foreign = new BillingInvoice;
        $foreign->forceFill([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'serial_number' => 'IN-USD-1',
            'mollie_payment_id' => 'tr_usd_1',
            'invoice_kind' => InvoiceKind::Subscription,
            'status' => InvoiceStatus::Paid,
            'country' => 'AT',
            'currency' => 'USD',
            'amount_net' => 5000,
            'amount_vat' => 1000,
            'amount_gross' => 6000,
            'refunded_net' => 0,
            'line_items' => [],
        ])->save();

        // By hand: 108.00 + (250 net + 20 % =) 3.00 paid; 12.00 + 20 % = 14.40 credited.
        expect((int) $renewal->amount_gross)->toBe(10800)
            ->and((int) $overage->amount_gross)->toBe(300)
            ->and((int) $creditNote->amount_gross)->toBe(-1440);

        // Every kind of recurring line is on the renewal, and the overage on its own.
        expect(collect($renewal->line_items)->pluck('kind')->all())->toBe(['plan', 'seats', 'addon', 'addon_seats'])
            ->and(collect($overage->line_items)->pluck('kind')->all())->toBe(['overage']);

        $this->browse(function (Browser $browser) use ($billable, $renewal, $overage, $creditNote, $foreign): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$renewal->id.'"]', 15)->pause(500);
            $this->shot($browser, 'Invoices');

            // Totals: in the configured currency only.
            $this->assertShownAmount($browser, 'stats-paid', 10800 + 300, 'paid');
            $this->assertShownAmount($browser, 'stats-credits', 1440, 'credits');
            $this->assertShownAmount($browser, 'stats-spent', 10800 + 300 - 1440, 'spent');

            // Every row carries the booked amounts.
            foreach ([$renewal, $overage, $creditNote] as $invoice) {
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-net', (int) $invoice->amount_net);
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-vat', (int) $invoice->amount_vat);
                $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', (int) $invoice->amount_gross);
            }

            // The credit note is a negative row of its own kind.
            $credit = $browser->text('[dusk="invoice-row-'.$creditNote->id.'"]');
            expect($credit)->toContain(InvoiceKind::Refund->label())
                ->and($browser->text('[dusk="invoice-'.$creditNote->id.'-gross"]'))->toStartWith('-');

            expect($browser->text('[dusk="invoice-row-'.$overage->id.'"]'))->toContain(InvoiceKind::Overage->label());

            // The foreign invoice is shown in its own currency.
            expect($browser->text('[dusk="invoice-'.$foreign->id.'-gross"]'))->toBe(BillingMoney::format(6000, 'USD'));

            // Each PDF link answers with that invoice's PDF.
            foreach ([$renewal, $overage, $creditNote] as $invoice) {
                $href = (string) $browser->attribute('[dusk="invoice-row-'.$invoice->id.'"] a', 'href');
                $download = $this->opsFetch($browser, $href);

                expect($download['status'])->toBe(200)
                    ->and($download['head'])->toStartWith('%PDF');
            }
        });

        // What is IN those PDFs: every line of the invoice, by its label, and the serial.
        // Read in PHP, so it runs on every machine — not only where poppler is installed.
        foreach ([$renewal, $overage, $creditNote->refresh()] as $invoice) {
            $text = $this->pdfText($invoice);

            foreach ((array) $invoice->line_items as $line) {
                expect($text)->toContain($this->squash((string) $line['label']));
            }

            expect($text)->toContain($this->squash((string) $invoice->serial_number));
        }
    }

    public function test_a_customer_cannot_download_another_customers_invoice(): void
    {
        $mine = $this->opsRenew($this->opsSubscriber('starter'), '11.78');
        $theirs = $this->opsRenew($this->opsSubscriber('pro'), '34.51');

        $this->browse(function (Browser $browser) use ($mine, $theirs): void {
            $this->actAs($browser, $mine->billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$mine->id.'"]', 15)->pause(300);

            // Only my own invoice is listed …
            expect($this->domCount($browser, '[dusk="invoice-row-'.$theirs->id.'"]'))->toBe(0);

            // … and asking for the other one by its URL gets nothing.
            $own = $this->opsFetch($browser, route('billing.invoice.download', ['invoice' => $mine]));
            $other = $this->opsFetch($browser, route('billing.invoice.download', ['invoice' => $theirs]));

            expect($own['status'])->toBe(200)
                ->and($other['status'])->not->toBe(200)
                ->and($other['head'])->not->toStartWith('%PDF');
        });
    }

    /**
     * Decision 13: the PDF link is shown for an invoice whose document is not on the disk
     * yet (a deferred render still to come, or one that failed), and the click renders it.
     */
    public function test_the_pdf_link_of_an_invoice_without_a_document_renders_it_on_the_click(): void
    {
        $billable = $this->opsSubscriber('starter');
        $invoice = $this->opsRenew($billable, '11.78');
        $serial = (string) $invoice->serial_number;
        $disk = Storage::disk((string) $invoice->pdf_disk);

        // What a failed or not-yet-run render leaves: the booking, and no document.
        $disk->delete((string) $invoice->pdf_path);
        $invoice->forceFill(['pdf_disk' => null, 'pdf_path' => null])->save();
        expect($invoice->fresh()->hasPdf())->toBeFalse();

        $download = route('billing.invoice.download', ['invoice' => $invoice]);
        $downloadPath = (string) parse_url($download, PHP_URL_PATH);

        $this->browse(function (Browser $browser) use ($billable, $invoice, $downloadPath): void {
            // The dashboard's recent invoices list offers it …
            $this->actAs($browser, $billable, '/billing');
            $browser->waitFor('a[href$="'.$downloadPath.'"]', 15);

            // … and so does the invoice page, pointing at the download route.
            $browser->visit('/billing/invoices')
                ->waitFor('[dusk="invoice-'.$invoice->id.'-pdf"]', 15)
                ->pause(300);
            $this->shot($browser, 'PDF link before the document exists');

            $href = (string) $browser->attribute('[dusk="invoice-'.$invoice->id.'-pdf"]', 'href');
            expect((string) parse_url($href, PHP_URL_PATH))->toBe($downloadPath);

            $first = $this->opsFetch($browser, $href);
            $second = $this->opsFetch($browser, $href);

            expect($first['status'])->toBe(200)
                ->and($first['head'])->toStartWith('%PDF')
                ->and($second['status'])->toBe(200)
                ->and($second['length'])->toBe($first['length']);
        });

        $invoice->refresh();

        // Rendered and kept, under the serial the booking drew.
        expect($invoice->hasPdf())->toBeTrue()
            ->and(Storage::disk((string) $invoice->pdf_disk)->exists((string) $invoice->pdf_path))->toBeTrue()
            ->and((string) $invoice->serial_number)->toBe($serial)
            ->and($this->pdfText($invoice))->toContain($this->squash($serial));
    }

    public function test_an_empty_invoice_page_says_so(): void
    {
        $billable = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitForText(__('billing::portal.invoices'), 15)->pause(400);

            expect($this->domCount($browser, '[dusk^="invoice-row-"]'))->toBe(0);
            $this->shot($browser, 'No invoices yet');
        });
    }

    /** The text of an invoice's stored PDF. */
    private function pdfText(BillingInvoice $invoice): string
    {
        expect($invoice->hasPdf())->toBeTrue();

        return \GraystackIT\MollieBilling\Tests\Support\PdfText::of(
            (string) Storage::disk((string) $invoice->pdf_disk)->get((string) $invoice->pdf_path),
        );
    }

    private function squash(string $text): string
    {
        return \GraystackIT\MollieBilling\Tests\Support\PdfText::squash($text);
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }
}

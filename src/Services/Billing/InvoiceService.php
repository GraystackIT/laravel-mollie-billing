<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Brick\Money\Money;
use Elegantly\Invoices\Pdf\PdfInvoice;
use Elegantly\Invoices\Pdf\PdfInvoiceItem;
use Elegantly\Invoices\Support\Address;
use Elegantly\Invoices\Support\Buyer;
use Elegantly\Invoices\Support\Seller;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly InvoiceNumberGenerator $numberGenerator,
    ) {}

    /**
     * Build a BillingInvoice from a Mollie payment + line items. VAT is recomputed
     * from the billable's billing country (authoritative). If the sum of line items'
     * `total_net` disagrees with the expected net derived from the items, a
     * LineItemTotalsMismatchException is thrown.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function createForPayment(object $payment, string $invoiceKind, array $lineItems, Billable $billable): BillingInvoice
    {
        /** @var Model&Billable $billable */
        $expectedNet = $this->expectedNetFromLineItems($lineItems);
        $summedNet = $this->sumTotalNet($lineItems);

        if ($expectedNet !== $summedNet) {
            throw new LineItemTotalsMismatchException($expectedNet, $summedNet);
        }

        $country = (string) ($billable->getBillingCountry() ?? '');
        $vat = $this->vat->calculate($country, $summedNet);

        $invoice = new BillingInvoice();
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = (string) $payment->id;
        $invoice->mollie_subscription_id = $payment->subscriptionId ?? null;
        $invoice->serial_number = $this->numberGenerator->generate($invoiceKind);
        $invoice->invoice_kind = $invoiceKind;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper($country);
        $invoice->vat_rate = (float) $vat['rate'];
        $invoice->currency = (string) config('mollie-billing.currency', 'EUR');
        $invoice->amount_net = (int) $vat['net'];
        $invoice->amount_vat = (int) $vat['vat'];
        $invoice->amount_gross = (int) $vat['gross'];
        $invoice->line_items = $lineItems;
        $invoice->refunded_net = 0;
        $invoice->save();

        $this->generateAndStorePdf($invoice, $billable);

        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }

    /**
     * Create a credit-note BillingInvoice (negative amounts) that references the
     * original invoice. The credit note re-uses the ORIGINAL invoice's VAT rate
     * so that refunds computed at today's rate do not drift from the rate used
     * at the time the invoice was issued.
     */
    public function createCreditNote(BillingInvoice $original, int $amountNet): BillingInvoice
    {
        if ($amountNet <= 0) {
            throw new \InvalidArgumentException('Credit-note net amount must be positive.');
        }

        $rate = (float) $original->vat_rate;
        $creditVat = (int) round($amountNet * $rate / 100);
        $creditGross = $amountNet + $creditVat;

        $creditNote = new BillingInvoice();
        $creditNote->billable_type = $original->billable_type;
        $creditNote->billable_id = $original->billable_id;
        $creditNote->mollie_payment_id = $original->mollie_payment_id.':cn:'.uniqid('', true);
        $creditNote->mollie_subscription_id = $original->mollie_subscription_id;
        $creditNote->serial_number = $this->numberGenerator->generate('credit_note');
        $creditNote->invoice_kind = InvoiceKind::CreditNote;
        $creditNote->status = InvoiceStatus::Refunded;
        $creditNote->country = $original->country;
        $creditNote->vat_rate = $rate;
        $creditNote->currency = $original->currency ?: (string) config('mollie-billing.currency', 'EUR');
        $creditNote->amount_net = -$amountNet;
        $creditNote->amount_vat = -$creditVat;
        $creditNote->amount_gross = -$creditGross;
        $creditNote->line_items = [[
            'kind' => 'credit_note',
            'label' => 'Credit note for invoice #'.$original->serial_number,
            'quantity' => 1,
            'unit_price' => -$amountNet,
            'total_net' => -$amountNet,
            'parent_invoice_id' => $original->id,
        ]];
        $creditNote->parent_invoice_id = $original->id;
        $creditNote->refunded_net = 0;
        $creditNote->save();

        /** @var Billable $billable */
        $billable = $original->billable()->first();

        if ($billable !== null) {
            $this->generateAndStorePdf($creditNote, $billable);
            event(new CreditNoteIssued($billable, $creditNote, $original));
        }

        return $creditNote;
    }

    /**
     * Generate a PDF from the BillingInvoice data and store it on the configured disk.
     * Failures are logged but do not prevent the invoice from being persisted.
     */
    protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): void
    {
        try {
            $pdfInvoice = $this->buildPdfInvoice($invoice, $billable);
            $pdfContent = $pdfInvoice->getPdfOutput();

            if ($pdfContent === null) {
                Log::warning('PDF invoice generation returned null', ['invoice_id' => $invoice->id]);

                return;
            }

            $disk = (string) config('mollie-billing.invoices.disk', 'local');
            $basePath = rtrim((string) config('mollie-billing.invoices.path', 'billing/invoices'), '/');
            $filename = Str::slug($invoice->serial_number ?? 'invoice-'.$invoice->id).'.pdf';
            $path = $basePath.'/'.$invoice->created_at->format('Y/m').'/'.$filename;

            Storage::disk($disk)->put($path, $pdfContent);

            $invoice->pdf_disk = $disk;
            $invoice->pdf_path = $path;
            $invoice->save();
        } catch (\Throwable $e) {
            Log::warning('PDF invoice generation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a PdfInvoice value object from BillingInvoice data for PDF rendering.
     */
    protected function buildPdfInvoice(BillingInvoice $invoice, Billable $billable): PdfInvoice
    {
        $currency = $invoice->currency ?: (string) config('mollie-billing.currency', 'EUR');
        $sellerConfig = (array) config('mollie-billing.invoices.seller', []);
        $addressConfig = (array) ($sellerConfig['address'] ?? []);

        $seller = new Seller(
            company: $sellerConfig['company'] ?? config('app.name'),
            name: $sellerConfig['name'] ?? null,
            address: new Address(
                street: $addressConfig['street'] ?? null,
                city: $addressConfig['city'] ?? null,
                postal_code: $addressConfig['postal_code'] ?? null,
                state: $addressConfig['state'] ?? null,
                country: $addressConfig['country'] ?? null,
            ),
            tax_number: $sellerConfig['tax_number'] ?? null,
            email: $sellerConfig['email'] ?? null,
            phone: $sellerConfig['phone'] ?? null,
        );

        $buyer = new Buyer(
            name: $billable->getBillingName(),
            address: new Address(
                street: $billable->getBillingStreet(),
                city: $billable->getBillingCity(),
                postal_code: $billable->getBillingPostalCode(),
                country: $billable->getBillingCountry(),
            ),
            tax_number: $billable->vat_number ?? null,
            email: $billable->getBillingEmail(),
        );

        $isCredit = $invoice->invoice_kind === InvoiceKind::CreditNote;

        $items = [];
        foreach ((array) $invoice->line_items as $item) {
            $qty = (int) ($item['quantity'] ?? 1);
            $netUnit = (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);

            // For credit notes, amounts are negative — PdfInvoice expects positive values
            // with the type set to 'Credit' to indicate the direction.
            $absNetUnit = abs($netUnit);

            $items[] = new PdfInvoiceItem(
                label: (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                unit_price: Money::ofMinor($absNetUnit, $currency),
                tax_percentage: (float) $invoice->vat_rate,
                quantity: abs($qty),
                description: $item['code'] ?? null,
            );
        }

        return new PdfInvoice(
            type: $isCredit ? 'Credit Note' : 'Invoice',
            state: $this->mapInvoiceState($invoice->status),
            serial_number: $invoice->serial_number,
            created_at: $invoice->created_at,
            seller: $seller,
            buyer: $buyer,
            items: $items,
            description: $this->buildDescription($invoice),
        );
    }

    private function mapInvoiceState(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Paid => 'Paid',
            InvoiceStatus::Refunded => 'Refunded',
            InvoiceStatus::Open => 'Pending',
            InvoiceStatus::Failed => 'Draft',
        };
    }

    private function buildDescription(BillingInvoice $invoice): ?string
    {
        if ($invoice->period_start && $invoice->period_end) {
            return 'Billing period: '.$invoice->period_start->format('Y-m-d').' – '.$invoice->period_end->format('Y-m-d');
        }

        return null;
    }

    /**
     * Sum the line items' total_net values.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function sumTotalNet(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            $sum += (int) ($item['total_net'] ?? 0);
        }

        return $sum;
    }

    /**
     * Expected net = quantity * unit_price, summed across items.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function expectedNetFromLineItems(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            if (array_key_exists('quantity', $item) && array_key_exists('unit_price', $item)) {
                $sum += (int) $item['quantity'] * (int) $item['unit_price'];
            } else {
                $sum += (int) ($item['total_net'] ?? 0);
            }
        }

        return $sum;
    }
}

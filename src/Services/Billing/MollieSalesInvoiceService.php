<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Illuminate\Database\Eloquent\Model;

class MollieSalesInvoiceService
{
    public function __construct(
        private readonly VatCalculationService $vat,
    ) {
    }

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
        $invoice->mollie_sales_invoice_id = null;
        $invoice->mollie_invoice_url = null;
        $invoice->mollie_pdf_url = null;
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

        $this->issueMollieSalesInvoice($invoice, $payment, $billable);

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
        $creditNote->invoice_kind = 'credit_note';
        $creditNote->status = InvoiceStatus::Refunded;
        $creditNote->country = $original->country;
        $creditNote->vat_rate = $rate;
        $creditNote->currency = $original->currency ?: (string) config('mollie-billing.currency', 'EUR');
        $creditNote->amount_net = -$amountNet;
        $creditNote->amount_vat = -$creditVat;
        $creditNote->amount_gross = -$creditGross;
        $creditNote->line_items = [[
            'kind' => 'credit_note',
            'label' => 'Credit note for invoice #'.$original->id,
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
            event(new CreditNoteIssued($billable, $creditNote, $original));
        }

        return $creditNote;
    }

    /**
     * Issue a Mollie Sales Invoice for a successful payment. Mollie's Sales Invoice API is
     * a B2B add-on and may not be available on every account. Failures are logged and do not
     * affect the locally persisted BillingInvoice — the lookup URLs simply stay null.
     */
    protected function issueMollieSalesInvoice(BillingInvoice $invoice, object $payment, Billable $billable): void
    {
        if (! $this->mollieSalesInvoicesEnabled()) {
            return;
        }

        try {
            $currency = strtoupper($invoice->currency ?: (string) config('mollie-billing.currency', 'EUR'));

            $orderLines = [];
            foreach ((array) $invoice->line_items as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $netUnit = (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);
                $totalNet = (int) ($item['total_net'] ?? $qty * $netUnit);
                $ratePercent = (float) $invoice->vat_rate;
                $vatUnit = (int) round($netUnit * $ratePercent / 100);
                $totalGross = $totalNet + (int) round($totalNet * $ratePercent / 100);

                $orderLines[] = [
                    'description' => (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                    'quantity' => $qty,
                    'vatRate' => number_format($ratePercent, 2, '.', ''),
                    'unitPrice' => [
                        'currency' => $currency,
                        'value' => number_format(($netUnit + $vatUnit) / 100, 2, '.', ''),
                    ],
                    'totalAmount' => [
                        'currency' => $currency,
                        'value' => number_format($totalGross / 100, 2, '.', ''),
                    ],
                ];
            }

            $response = $this->createMollieSalesInvoice([
                'status' => 'issued',
                'paymentTerm' => '30 days',
                'memo' => 'Invoice for '.$invoice->invoice_kind,
                'recipient' => [
                    'type' => $billable->vat_number ? 'business' : 'consumer',
                    'email' => $billable->getBillingEmail(),
                    'businessName' => $billable->getBillingName(),
                    'vatNumber' => $billable->vat_number ?: null,
                    'streetAndNumber' => $billable->getBillingStreet(),
                    'city' => $billable->getBillingCity(),
                    'postalCode' => $billable->getBillingPostalCode(),
                    'country' => $billable->getBillingCountry(),
                ],
                'lines' => $orderLines,
                'currency' => $currency,
            ]);

            $invoice->mollie_sales_invoice_id = (string) ($response->id ?? '');
            $invoice->mollie_invoice_url = (string) ($response->_links->self->href ?? '') ?: null;
            $invoice->mollie_pdf_url = (string) ($response->_links->pdf->href ?? '') ?: null;
            $invoice->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Mollie sales invoice creation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stubbable seam for tests — wraps the actual Mollie SDK call.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function createMollieSalesInvoice(array $payload): object
    {
        $client = \Mollie\Laravel\Facades\Mollie::api();
        /** @phpstan-ignore-next-line — Mollie SDK uses magic property access for endpoints. */
        return $client->salesInvoices->create($payload);
    }

    protected function mollieSalesInvoicesEnabled(): bool
    {
        return (bool) config('mollie-billing.mollie_sales_invoices_enabled', false);
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

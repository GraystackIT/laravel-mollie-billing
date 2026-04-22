<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Brick\Money\Money;
use Elegantly\Invoices\Pdf\PdfInvoice;
use Elegantly\Invoices\Pdf\PdfInvoiceItem;
use Elegantly\Invoices\Support\Address;
use Elegantly\Invoices\Support\Buyer;
use Elegantly\Invoices\Support\PaymentInstruction;
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
        $vat = $this->vat->calculate($country, $summedNet, $billable->vat_number ?? null);

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
        $invoice->payment_method_details = self::extractPaymentMethodDetails($payment);
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
    /**
     * @param  array<int, array<string, mixed>>|null  $lineItems  Custom line items; if null, a generic single-line credit note is created.
     */
    public function createCreditNote(BillingInvoice $original, int $amountNet, ?array $lineItems = null, ?string $description = null): BillingInvoice
    {
        if ($amountNet <= 0) {
            throw new \InvalidArgumentException('Credit-note net amount must be positive.');
        }

        $rate = (float) $original->vat_rate;
        $creditVat = (int) round($amountNet * $rate / 100);
        $creditGross = $amountNet + $creditVat;

        $resolvedLineItems = $lineItems ?? [[
            'kind' => 'credit_note',
            'label' => __('billing::portal.credit_note_label', ['serial' => $original->serial_number]),
            'quantity' => 1,
            'unit_price' => -$amountNet,
            'total_net' => -$amountNet,
            'parent_invoice_id' => $original->id,
        ]];

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
        $creditNote->line_items = $resolvedLineItems;
        $creditNote->payment_method_details = $original->payment_method_details;
        $creditNote->parent_invoice_id = $original->id;
        $creditNote->refund_reason_text = $description;
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
     * Create a standalone credit-note invoice for a prorata refund.
     *
     * Unlike {@see createCreditNote()}, this is NOT tied to a parent invoice.
     * It represents an independent refund (e.g. prorata credit on plan downgrade)
     * that is issued via the billable's Mollie mandate.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function createStandaloneCreditNote(
        Billable $billable,
        int $amountNet,
        float $vatRate,
        array $lineItems,
        ?string $description = null,
        ?string $molliePaymentId = null,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        if ($amountNet <= 0) {
            throw new \InvalidArgumentException('Credit-note net amount must be positive.');
        }

        $creditVat = (int) round($amountNet * $vatRate / 100);
        $creditGross = $amountNet + $creditVat;

        $creditNote = new BillingInvoice();
        $creditNote->billable_type = $billable->getMorphClass();
        $creditNote->billable_id = $billable->getKey();
        $creditNote->mollie_payment_id = $molliePaymentId !== null
            ? $molliePaymentId.':cn:'.uniqid('', true)
            : 'cn:'.uniqid('', true);
        $creditNote->serial_number = $this->numberGenerator->generate('credit_note');
        $creditNote->invoice_kind = InvoiceKind::CreditNote;
        $creditNote->status = InvoiceStatus::Refunded;
        $creditNote->country = strtoupper($billable->getBillingCountry() ?? '');
        $creditNote->vat_rate = $vatRate;
        $creditNote->currency = (string) config('mollie-billing.currency', 'EUR');
        $creditNote->amount_net = -$amountNet;
        $creditNote->amount_vat = -$creditVat;
        $creditNote->amount_gross = -$creditGross;
        $creditNote->line_items = $lineItems;
        $creditNote->refund_reason_text = $description;
        $creditNote->refunded_net = 0;
        $creditNote->save();

        $this->generateAndStorePdf($creditNote, $billable);
        event(new CreditNoteIssued($billable, $creditNote, null));

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

            // Per-item billing_period or description only — no fallback to global period.
            $description = $item['description'] ?? $item['billing_period'] ?? null;

            $items[] = new PdfInvoiceItem(
                label: (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                unit_price: Money::ofMinor($netUnit, $currency),
                tax_percentage: (float) $invoice->vat_rate,
                quantity: $qty,
                description: $description,
            );
        }

        $logo = $this->resolveLogoPath(config('mollie-billing.invoices.logo'));
        $paymentInstruction = $this->buildPaymentInstruction($invoice, $isCredit);

        // Credit note reason as general description (below items, above payment info).
        $description = ($isCredit && ! empty($invoice->refund_reason_text))
            ? (string) $invoice->refund_reason_text
            : null;

        return new PdfInvoice(
            type: $isCredit ? __('billing::portal.credit_note_type') : __('billing::portal.invoice_type'),
            state: $this->mapInvoiceState($invoice->status),
            serial_number: $invoice->serial_number,
            created_at: $invoice->created_at,
            seller: $seller,
            buyer: $buyer,
            items: $items,
            description: $description,
            paymentInstructions: $paymentInstruction !== null ? [$paymentInstruction] : [],
            logo: $logo,
        );
    }

    /**
     * Resolve the logo config value to a base64 data-URI for DOMPDF.
     *
     * Accepts: absolute local path, public-relative path, APP_URL-based URL,
     * or an already-encoded data-URI. The result is always a data-URI so
     * DOMPDF renders the logo without needing isRemoteEnabled.
     */
    private function resolveLogoPath(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $value = (string) $value;

        // Already a data-URI — return as-is.
        if (str_starts_with($value, 'data:')) {
            return $value;
        }

        // Extract the file basename and build every candidate path we can think of.
        $candidates = [];

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl !== '' && str_starts_with($value, $appUrl.'/')) {
                $candidates[] = public_path(ltrim(substr($value, strlen($appUrl)), '/'));
            }
            $parsed = parse_url($value);
            if (isset($parsed['path'])) {
                $candidates[] = public_path(ltrim($parsed['path'], '/'));
            }
        } elseif (str_starts_with($value, '/')) {
            $candidates[] = $value;
            $candidates[] = public_path(ltrim($value, '/'));
        } else {
            $candidates[] = public_path($value);
        }

        // Always try base_path and resource_path as well.
        $basename = basename($value);
        $candidates[] = public_path($basename);
        $candidates[] = base_path($value);
        $candidates[] = resource_path($basename);

        // Deduplicate.
        $candidates = array_unique($candidates);

        foreach ($candidates as $path) {
            if (file_exists($path) && is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    $mime = mime_content_type($path) ?: 'image/png';

                    return 'data:'.$mime.';base64,'.base64_encode($contents);
                }
            }
        }

        Log::warning('Invoice logo could not be resolved to a local file', [
            'configured_value' => $value,
            'tried_paths' => $candidates,
            'public_path' => public_path(),
        ]);

        return null;
    }

    private function mapInvoiceState(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Paid => __('billing::portal.invoice_state_paid'),
            InvoiceStatus::Refunded => __('billing::portal.invoice_state_refunded'),
            InvoiceStatus::Open => __('billing::portal.invoice_state_pending'),
            InvoiceStatus::Failed => __('billing::portal.invoice_state_failed'),
        };
    }

    /**
     * Extract payment method details from a Mollie payment object for storage.
     *
     * @return array{method: string, summary: string, details: array<string, mixed>}|null
     */
    public static function extractPaymentMethodDetails(object $payment): ?array
    {
        $method = $payment->method ?? null;
        if ($method === null) {
            return null;
        }

        $details = is_object($payment->details ?? null)
            ? json_decode(json_encode($payment->details), true) ?: []
            : (array) ($payment->details ?? []);

        $method = (string) $method;
        $summary = self::buildPaymentSummary($method, $details);

        return [
            'method' => $method,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * Build a human-readable summary for a payment method (e.g. "Visa •••• 1234, 12/2027").
     *
     * @param  array<string, mixed>  $details
     */
    private static function buildPaymentSummary(string $method, array $details): ?string
    {
        if ($method === 'creditcard') {
            $parts = [];
            if (! empty($details['cardLabel'])) {
                $parts[] = (string) $details['cardLabel'];
            }
            if (! empty($details['cardNumber'])) {
                $parts[] = '**** '.(string) $details['cardNumber'];
            }
            $summary = implode(' ', $parts);
            if (! empty($details['cardExpiryDate'])) {
                try {
                    $summary .= ', '.\Carbon\Carbon::parse((string) $details['cardExpiryDate'])->format('m/Y');
                } catch (\Throwable) {
                }
            }

            return $summary ?: null;
        }

        if ($method === 'directdebit') {
            $parts = [];
            if (! empty($details['consumerName'])) {
                $parts[] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $parts[] = (string) $details['consumerAccount'];
            }

            return $parts !== [] ? implode(' - ', $parts) : null;
        }

        if ($method === 'paypal') {
            return ! empty($details['consumerAccount']) ? (string) $details['consumerAccount'] : null;
        }

        return ucfirst(str_replace('_', ' ', $method));
    }

    /**
     * Build a PaymentInstruction for the PDF footer showing payment method details.
     */
    private function buildPaymentInstruction(BillingInvoice $invoice, bool $isCredit): ?PaymentInstruction
    {
        $pmd = $invoice->payment_method_details;
        if (! is_array($pmd) || empty($pmd['method'])) {
            return null;
        }

        $method = (string) $pmd['method'];
        $methodLabel = ucfirst(str_replace('_', ' ', $method));
        $details = (array) ($pmd['details'] ?? []);

        $name = $isCredit
            ? __('billing::portal.refund_to', ['method' => $methodLabel])
            : __('billing::portal.charged_via', ['method' => $methodLabel]);

        $fields = [];

        if ($method === 'creditcard') {
            if (! empty($details['cardHolder'])) {
                $fields[__('billing::portal.payment_method.card_holder')] = (string) $details['cardHolder'];
            }
            if (! empty($details['cardLabel'])) {
                $fields[__('billing::portal.payment_method.card_brand')] = (string) $details['cardLabel'];
            }
            if (! empty($details['cardNumber'])) {
                $fields[__('billing::portal.payment_method.card_number')] = '**** '.(string) $details['cardNumber'];
            }
            if (! empty($details['cardExpiryDate'])) {
                try {
                    $fields[__('billing::portal.payment_method.expires_label')] = \Carbon\Carbon::parse((string) $details['cardExpiryDate'])->format('m/Y');
                } catch (\Throwable) {
                }
            }
        } elseif ($method === 'directdebit') {
            if (! empty($details['consumerName'])) {
                $fields[__('billing::portal.payment_method.account_holder')] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $fields[__('billing::portal.payment_method.iban')] = (string) $details['consumerAccount'];
            }
            if (! empty($details['consumerBic'])) {
                $fields[__('billing::portal.payment_method.bic')] = (string) $details['consumerBic'];
            }
        } elseif ($method === 'paypal') {
            if (! empty($details['consumerName'])) {
                $fields[__('billing::portal.payment_method.account_holder')] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $fields[__('billing::portal.payment_method.paypal_email')] = (string) $details['consumerAccount'];
            }
        }

        return new PaymentInstruction(
            name: $name,
            fields: $fields,
        );
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

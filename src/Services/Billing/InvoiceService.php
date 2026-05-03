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
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Mollie\Api\Http\Data\Money as MollieMoney;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;
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
     * Mappt einen Mollie-`metadata.type`-String auf den passenden InvoiceKind.
     * Subtypen (`'prorata'`, `'addon'`, `'seats'`) werden alle als `Subscription` klassifiziert —
     * der Subtyp ist via line_items[*].kind ablesbar.
     */
    public static function mapTypeToInvoiceKind(string $type): InvoiceKind
    {
        return match ($type) {
            'subscription', 'prorata', 'addon', 'seats', 'prorata_charge', 'mid_cycle_addon', 'mid_cycle_seats' => InvoiceKind::Subscription,
            'overage' => InvoiceKind::Overage,
            'one_time_order' => InvoiceKind::OneTimeOrder,
            'refund' => InvoiceKind::Refund,
            default => throw new \InvalidArgumentException("Unknown metadata.type: {$type}"),
        };
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
        $vat = $this->vat->calculate($country, $summedNet, $billable);

        $invoice = new BillingInvoice();
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = (string) $payment->id;
        $invoice->mollie_subscription_id = $payment->subscriptionId ?? null;
        // Mapping vom Mollie-metadata-type-String auf InvoiceKind. Heute-Werte (`'prorata'`,
        // `'addon'`, `'seats'`) werden alle als Subscription-Buchung klassifiziert. Der Subtyp
        // ist aus den line_items[*].kind ablesbar.
        $invoiceKindEnum = self::mapTypeToInvoiceKind($invoiceKind);
        $invoice->serial_number = $this->numberGenerator->generate($invoiceKindEnum->value);
        $invoice->invoice_kind = $invoiceKindEnum;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper($country);
        $invoice->currency = (string) config('mollie-billing.currency', 'EUR');
        $invoice->amount_net = (int) $vat['net'];
        $invoice->amount_vat = (int) $vat['vat'];
        $invoice->amount_gross = (int) $vat['gross'];
        $rate = (float) $vat['rate'];

        // Period for line items: prefer the billable's current subscription period
        // (so currentPeriodLines() can later locate them for refunds), else fall back
        // to "now → now+1 month" as a defensive default for one-off charges.
        $linePeriodStart = $billable->getBillingPeriodStartsAt() ?? BillingTime::nowUtc();
        $linePeriodEnd = $billable->nextBillingDate() ?? BillingTime::nowUtc()->addMonth();

        $invoice->period_start = $linePeriodStart;
        $invoice->period_end = $linePeriodEnd;

        $invoice->line_items = array_map(function (array $line) use ($rate, $linePeriodStart, $linePeriodEnd): array {
            // Normalize legacy kind alias.
            if (($line['kind'] ?? null) === 'seat') {
                $line['kind'] = 'seats';
            }

            $netLine = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);
            $lineRate = (float) ($line['vat_rate'] ?? $rate);
            $vatLine = (int) round($netLine * $lineRate / 100);

            $line['vat_rate'] = $lineRate;
            $line['amount_net'] = $netLine;
            $line['vat_amount'] = $vatLine;
            $line['amount_gross'] = $netLine + $vatLine;
            $line['period_start'] = $line['period_start'] ?? $linePeriodStart->toIso8601String();
            $line['period_end'] = $line['period_end'] ?? $linePeriodEnd->toIso8601String();

            return $line;
        }, $lineItems);
        $invoice->payment_method_details = self::extractPaymentMethodDetails($payment);
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
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

        // VAT-Rate aus dem ersten Original-Line-Item lesen (Per-Item-VAT als Source of Truth).
        $originalLines = (array) ($original->line_items ?? []);
        $firstLine = $originalLines[0] ?? null;
        $rate = $firstLine !== null && isset($firstLine['vat_rate']) ? (float) $firstLine['vat_rate'] : 0.0;
        $creditVat = (int) round($amountNet * $rate / 100);
        $creditGross = $amountNet + $creditVat;

        // Bei Custom-line_items: User übergibt komplette Lines (mit eigenen vat_rate-Feldern).
        // Bei null: ein generischer Refund-Line-Eintrag mit Verweis auf Original-Line[0].
        if ($lineItems !== null) {
            $resolvedLineItems = $lineItems;
        } else {
            $resolvedLineItems = [[
                'kind' => 'refund',
                'code' => null,
                'label' => __('billing::portal.credit_note_label', ['serial' => $original->serial_number]),
                'quantity' => 1,
                'unit_price_net' => -$amountNet,
                'amount_net' => -$amountNet,
                'vat_rate' => $rate,
                'vat_amount' => -$creditVat,
                'amount_gross' => -$creditGross,
                'period_start' => ($original->period_start ?? BillingTime::nowUtc())->toIso8601String(),
                'period_end' => ($original->period_end ?? BillingTime::nowUtc())->toIso8601String(),
                'parent_invoice_id' => $original->id,
                'parent_line_item_index' => 0,
                'mollie_refund_id' => null, // wird vom Aufrufer gesetzt falls Mollie-Refund-ID bekannt ist
            ]];
        }

        $creditNote = new BillingInvoice;
        $creditNote->billable_type = $original->billable_type;
        $creditNote->billable_id = $original->billable_id;
        $creditNote->mollie_payment_id = null;
        $creditNote->mollie_subscription_id = $original->mollie_subscription_id;
        $creditNote->serial_number = $this->numberGenerator->generate('refund');
        $creditNote->invoice_kind = InvoiceKind::Refund;
        $creditNote->status = InvoiceStatus::Refunded;
        $creditNote->country = $original->country;
        $creditNote->currency = $original->currency ?: (string) config('mollie-billing.currency', 'EUR');
        $creditNote->amount_net = -$amountNet;
        $creditNote->amount_vat = -$creditVat;
        $creditNote->amount_gross = -$creditGross;
        $creditNote->line_items = $resolvedLineItems;
        $creditNote->payment_method_details = $original->payment_method_details;
        $creditNote->refund_reason_text = $description;
        $creditNote->refunded_net = 0;
        // Credit notes anchor on the same VAT validation as the original invoice —
        // they are a correction of that specific tax event, not a fresh decision.
        $creditNote->vat_validation_id = $original->vat_validation_id;
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
                country: CountryResolver::name($addressConfig['country'] ?? null),
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
                country: CountryResolver::name($billable->getBillingCountry()),
            ),
            tax_number: $billable->vat_number ?? null,
            email: $billable->getBillingEmail(),
        );

        $isCredit = $invoice->invoice_kind === InvoiceKind::Refund;

        $items = [];
        foreach ((array) $invoice->line_items as $item) {
            $qty = (int) ($item['quantity'] ?? 1);
            $netUnit = (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);

            // Per-item billing_period or description only — no fallback to global period.
            $description = $item['description'] ?? $item['billing_period'] ?? null;

            // Per-Item-VAT: line_item.vat_rate ist Source of Truth.
            $taxPercentage = (float) ($item['vat_rate'] ?? 0);

            $items[] = new PdfInvoiceItem(
                label: (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                unit_price: Money::ofMinor($netUnit, $currency),
                tax_percentage: $taxPercentage,
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

        // Force the tax column on the PDF whenever at least one line has a
        // non-zero VAT rate. The library's default heuristic only shows it when
        // totalTaxAmount() > 0, which fails for credit notes (negative totals)
        // even though the lines themselves carry tax rates and need to be
        // shown for tax-compliance reasons.
        $hasAnyTaxedLine = false;
        foreach ((array) $invoice->line_items as $line) {
            if (((float) ($line['vat_rate'] ?? 0)) > 0) {
                $hasAnyTaxedLine = true;
                break;
            }
        }
        $taxLabel = $hasAnyTaxedLine ? 'invoices::invoice.tax_label' : null;

        return new PdfInvoice(
            type: $isCredit ? __('billing::portal.credit_note_type') : __('billing::portal.invoice_type'),
            state: $this->mapInvoiceState($invoice->status),
            serial_number: $invoice->serial_number,
            created_at: $invoice->created_at,
            seller: $seller,
            buyer: $buyer,
            items: $items,
            description: $description,
            tax_label: $taxLabel,
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

    // ========================================================================
    // Neue API für Plan-Change-Sammel-Charges/Refunds (Multi-VAT-Lines)
    // ========================================================================

    /**
     * Generische Charge-Invoice-Persistierung für Webhook-Routing.
     * Wird vom Webhook gerufen für alle paid-Events (Recurring-Subscription, Mid-cycle,
     * Plan-Change-Sammel-Charges, Overage, OneTimeOrder).
     *
     * KEIN Mollie-Call hier — der ist vorher passiert (Webhook reagiert nur auf paid).
     *
     * @param  array<int, array<string, mixed>>  $lineItems  jedes mit kind/code/label/quantity/
     *   unit_price_net/amount_net/vat_rate/vat_amount/amount_gross/period_start/period_end
     */
    public function createInvoice(
        Billable $billable,
        InvoiceKind $kind,
        string $molliePaymentId,
        ?string $mollieSubscriptionId,
        array $lineItems,
        ?\Carbon\CarbonInterface $periodStart = null,
        ?\Carbon\CarbonInterface $periodEnd = null,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        foreach ($lineItems as $line) {
            $sumNet += (int) ($line['amount_net'] ?? 0);
            $sumVat += (int) ($line['vat_amount'] ?? 0);
            $sumGross += (int) ($line['amount_gross'] ?? 0);
        }

        $country = (string) ($billable->getBillingCountry() ?? '');

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = $molliePaymentId;
        $invoice->mollie_subscription_id = $mollieSubscriptionId;
        $invoice->serial_number = $this->numberGenerator->generate($kind->value);
        $invoice->invoice_kind = $kind;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper($country);
        $invoice->currency = (string) config('mollie-billing.currency', 'EUR');
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItems;
        $invoice->period_start = $periodStart;
        $invoice->period_end = $periodEnd;
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $invoice->save();

        $this->generateAndStorePdf($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }

    /**
     * Phase 1 für Plan-Change: schickt CreatePaymentRequest mit metadata.type='prorata_charge'
     * und persistiert Pending-State in subscription_meta.pending_prorata_change.
     *
     * Persistierung der Charge-Invoice geschieht später im Webhook via createInvoice().
     *
     * @param  list<ProrataLine>  $chargeLines
     * @param  list<ProrataLine>  $pendingRefundLines  Werden nach Charge-Webhook-OK ausgeführt
     */
    public function createCharge(
        Billable $billable,
        array $chargeLines,
        array $pendingRefundLines,
        PlanChangeIntent $intent,
    ): void {
        /** @var Model&Billable $billable */
        if (! $billable->hasMollieMandate()) {
            throw new \LogicException('Cannot create charge: billable has no Mollie mandate.');
        }

        $grossTotal = array_sum(array_map(fn (ProrataLine $l) => $l->amountGross, $chargeLines));
        if ($grossTotal <= 0) {
            return; // Coupon-covered or zero — no Mollie charge
        }

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $value = number_format($grossTotal / 100, 2, '.', '');

        // Mollie metadata is hard-capped at 1024 bytes — keep it to a minimal correlation
        // payload. The full charge/refund line list lives in subscription_meta.pending_prorata_change
        // and is read back by the Phase-2 webhook.
        $payment = Mollie::send(new CreatePaymentRequest(
            description: "Plan change for {$intent->newPlan}",
            amount: new MollieMoney($currency, $value),
            metadata: [
                'type' => 'prorata_charge',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['pending_prorata_change'] = [
            'charge_payment_id' => is_object($payment) ? ($payment->id ?? null) : null,
            'charge_lines' => array_map(fn (ProrataLine $l) => $l->toArray(), $chargeLines),
            'refund_lines' => array_map(fn (ProrataLine $l) => $l->toArray(), $pendingRefundLines),
            'intent' => $intent->toArray(),
            'created_at' => BillingTime::nowUtc()->toIso8601String(),
        ];
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Macht intern N Mollie-Refund-Calls (gegen originalInvoice.mollie_payment_id pro Line)
     * und persistiert das Ergebnis als BillingInvoice mit invoice_kind=Refund.
     *
     * Mollie-409-Idempotenz pro Line: existiert bereits ein Refund-line_item mit
     * (parent_invoice_id, parent_line_item_index, amount_net) → übersprungen.
     *
     * Coupon-covered Lines werden gefiltert.
     *
     * Fehlgeschlagene Lines kommen in subscription_meta.pending_refund_retries.
     *
     * @param  list<ProrataLine>  $refundLines  alle direction='refund'
     * @return BillingInvoice|null  null wenn alle Lines coupon-covered oder fehlgeschlagen
     */
    public function createRefund(
        Billable $billable,
        array $refundLines,
        ?string $description = null,
    ): ?BillingInvoice {
        /** @var Model&Billable $billable */
        $effective = array_values(array_filter($refundLines, fn (ProrataLine $l) => ! $l->isCouponCovered && $l->amountGross < 0));
        if (empty($effective)) {
            return null;
        }

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $persistedLines = [];
        $failedLines = [];

        foreach ($effective as $line) {
            $original = $line->originalInvoice;
            if ($original === null || $original->mollie_payment_id === null) {
                $failedLines[] = ['line' => $line->toArray(), 'error' => 'Missing original invoice or payment_id'];
                continue;
            }

            // No (parent_invoice_id, idx, amount_net)-style idempotency check here:
            // the same triple can legitimately recur when a user reduces seats,
            // re-adds them, then reduces again — each event is a distinct refund.
            // BillingInvoice::currentPeriodLines() already filters by remaining
            // quantity, so the composer never produces a refund line that exceeds
            // what's still on the original invoice.

            $absGross = abs($line->amountGross);

            // Mollie only knows the original payment's gross charge — it has
            // no concept of our local net/VAT split. The refund amount must
            // therefore never exceed what Mollie actually collected, minus
            // anything already refunded on that payment. If our local gross
            // drifts above Mollie's charge (e.g. due to a VAT-classification
            // change between original payment and refund), clamp and warn so
            // the drift is investigable.
            $restRefundable = max(0, (int) $original->amount_gross - (int) $original->refunded_net);
            $refundAmount = min($absGross, $restRefundable);

            if ($refundAmount < $absGross) {
                Log::warning('Refund amount clamped below local gross', [
                    'billable' => $billable->getKey(),
                    'original_invoice_id' => $original->getKey(),
                    'mollie_payment_id' => $original->mollie_payment_id,
                    'local_gross' => $absGross,
                    'rest_refundable' => $restRefundable,
                    'refund_sent' => $refundAmount,
                ]);
            }

            if ($refundAmount <= 0) {
                $failedLines[] = ['line' => $line->toArray(), 'error' => 'Nothing left to refund on the original payment', 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];
                continue;
            }

            $value = number_format($refundAmount / 100, 2, '.', '');

            // Without an explicit idempotency key, Mollie deduplicates
            // refund requests against (payment_id, amount) — so two legitimate
            // refunds for identical amounts on the same payment (e.g. seat
            // reduce → re-add → reduce again) get rejected with 409
            // "duplicate refund". A unique key per refund line marks each
            // call as a distinct operation so Mollie processes them all.
            $idempotencyKey = sprintf(
                'refund:%s:%s:%s:%d:%d:%s',
                $billable->getMorphClass(),
                (string) $billable->getKey(),
                (string) $original->getKey(),
                (int) ($line->originalLineItemIndex ?? 0),
                (int) $refundAmount,
                bin2hex(random_bytes(4)),
            );

            // Mollie's SDK auto-resets the key on response (ResetIdempotencyKey
            // middleware), so we set it once per refund call and don't need
            // to clean up explicitly. Wrapped in try/catch because Mockery-based
            // tests use a strict facade mock that doesn't whitelist this method
            // and would throw BadMethodCallException — which would otherwise
            // mask the real refund attempt that follows.
            try {
                Mollie::setIdempotencyKey($idempotencyKey);
            } catch (\BadMethodCallException) {
                // Test mock without idempotency-key whitelist; safe to skip.
            }

            try {
                $refund = Mollie::send(new CreatePaymentRefundRequest(
                    paymentId: $original->mollie_payment_id,
                    description: $description ?? "Plan change refund: {$line->label}",
                    amount: new MollieMoney($currency, $value),
                ));
                $line->mollieRefundId = is_object($refund) ? ($refund->id ?? null) : null;
                $persistedLines[] = $line;
            } catch (\Throwable $e) {
                $failedLines[] = ['line' => $line->toArray(), 'error' => $e->getMessage(), 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];
            }
        }

        // Failed Lines in pending_refund_retries für Retry-Job.
        if (! empty($failedLines)) {
            $meta = $billable->getBillingSubscriptionMeta();
            $existing = (array) ($meta['pending_refund_retries'] ?? []);
            $meta['pending_refund_retries'] = array_merge($existing, $failedLines);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        if (empty($persistedLines)) {
            return null;
        }

        // Sammel-Refund-Invoice persistieren.
        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        $lineItemsForPersist = [];
        $countryFromOriginal = null;

        foreach ($persistedLines as $line) {
            $sumNet += $line->amountNet;
            $sumVat += $line->amountVat;
            $sumGross += $line->amountGross;
            $lineItemsForPersist[] = $line->toArray();
            if ($countryFromOriginal === null && $line->originalInvoice !== null) {
                $countryFromOriginal = (string) $line->originalInvoice->country;
            }
        }

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = null;
        $invoice->mollie_subscription_id = null;
        $invoice->serial_number = $this->numberGenerator->generate('refund');
        $invoice->invoice_kind = InvoiceKind::Refund;
        $invoice->status = InvoiceStatus::Refunded;
        $invoice->country = strtoupper($countryFromOriginal ?? (string) ($billable->getBillingCountry() ?? ''));
        $invoice->currency = (string) config('mollie-billing.currency', 'EUR');
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItemsForPersist;
        $invoice->refund_reason_text = $description;
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $invoice->save();

        $this->generateAndStorePdf($invoice, $billable);
        event(new CreditNoteIssued($billable, $invoice, null));

        return $invoice;
    }

    /**
     * Saldo-0-Sidegrade: lokale Plan-Switch-Invoice mit Charge- und Refund-Lines (Saldo=0).
     * KEIN Mollie-Call. Nur lokale Buchhaltungs-Invoice für den Audit-Trail.
     *
     * @param  list<ProrataLine>  $chargeLines
     * @param  list<ProrataLine>  $refundLines
     */
    public function createPlanSwitchInvoice(
        Billable $billable,
        array $chargeLines,
        array $refundLines,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        $allLines = array_merge($chargeLines, $refundLines);

        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        $lineItemsForPersist = [];

        foreach ($allLines as $line) {
            $sumNet += $line->amountNet;
            $sumVat += $line->amountVat;
            $sumGross += $line->amountGross;
            $lineItemsForPersist[] = $line->toArray();
        }

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = null;
        $invoice->mollie_subscription_id = null;
        $invoice->serial_number = $this->numberGenerator->generate('subscription');
        $invoice->invoice_kind = InvoiceKind::Subscription;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper((string) ($billable->getBillingCountry() ?? ''));
        $invoice->currency = (string) config('mollie-billing.currency', 'EUR');
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItemsForPersist;
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $invoice->save();

        $this->generateAndStorePdf($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }

}

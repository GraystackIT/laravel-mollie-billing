<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use horstoeko\invoicesuite\InvoiceSuiteBuiltInProviders;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Lang;
use horstoeko\invoicesuite\InvoiceSuiteDocumentBuilder;
use horstoeko\invoicesuite\InvoiceSuitePdfDocumentBuilder;
use horstoeko\invoicesuite\InvoiceSuiteSettings;
use horstoeko\invoicesuite\utils\InvoiceSuiteMessageBagItem;
use horstoeko\invoicesuite\validators\InvoiceSuiteXsdDocumentValidator;

/**
 * Structured e-invoice (EN 16931) for a booked {@see BillingInvoice}.
 *
 * The XML is UN/CEFACT CII in the configured profile — `en16931` (ZUGFeRD 2.x /
 * Factur-X "EN 16931", the default) or `xrechnung` (XRechnung 3.x CIUS) — and
 * {@see embedIntoPdf()} turns the rendered invoice PDF into a hybrid ZUGFeRD
 * document: PDF/A-3 with the XML attached as `factur-x.xml`.
 *
 * That hybrid is an e-invoice in the sense of § 14 (1) UStG (Germany, mandatory to
 * issue B2B from 2027/2028) — the structured part is the invoice, the PDF a readable
 * copy — and the format Austrian businesses accept for B2B. MINIMUM and BASIC-WL are
 * deliberately not offered: they are not invoices under German law.
 *
 * Built from the stored document only — line items, amounts, rates, the VAT validation
 * the invoice relied on — never from the billable's *current* state where the invoice
 * holds its own answer, so a document regenerated a year later carries the same figures
 * as the one that was issued. The two exceptions are the buyer's name and address,
 * which the invoice does not snapshot; the PDF reads them from the billable as well.
 */
class EInvoiceService
{
    public const PROFILE_EN16931 = 'en16931';

    public const PROFILE_XRECHNUNG = 'xrechnung';

    /** UNTDID 1001: commercial invoice. */
    private const TYPE_INVOICE = '380';

    /** UNTDID 1001: credit note. */
    private const TYPE_CREDIT_NOTE = '381';

    /** UNTDID 4461: online payment service — Mollie collects every payment. */
    private const PAYMENT_MEANS_ONLINE = '68';

    /** UN/ECE Rec. 20: "one" (piece / unit). */
    private const UNIT_CODE = 'C62';

    /** Where every built-in provider and validator of the library lives; see {@see withLibrarySettings()}. */
    private const LIBRARY_NAMESPACE = 'horstoeko\invoicesuite';

    public function __construct(
        private readonly VatCalculationService $vat,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('mollie-billing.invoices.e_invoice.enabled', true);
    }

    public function profile(): string
    {
        $profile = strtolower(trim((string) config('mollie-billing.invoices.e_invoice.profile', self::PROFILE_EN16931)));

        return $profile === self::PROFILE_XRECHNUNG ? self::PROFILE_XRECHNUNG : self::PROFILE_EN16931;
    }

    /**
     * The CII XML of $invoice.
     */
    public function xml(BillingInvoice $invoice, Billable $billable, ?string $profile = null): string
    {
        return $this->withLibrarySettings(
            $invoice,
            fn (): string => $this->render($invoice, $billable, $profile ?? $this->profile()),
        );
    }

    /**
     * Turn the rendered PDF into a hybrid ZUGFeRD / Factur-X document (PDF/A-3 with the
     * XML as associated file).
     */
    public function embedIntoPdf(string $pdf, BillingInvoice $invoice, Billable $billable, ?string $profile = null): string
    {
        return $this->withLibrarySettings($invoice, function () use ($pdf, $invoice, $billable, $profile): string {
            $xml = $this->render($invoice, $billable, $profile ?? $this->profile());

            return InvoiceSuitePdfDocumentBuilder::createFromDocumentContentAndPdfContent($xml, $pdf)
                ->setAdditionalCreatorTool('graystackit/laravel-mollie-billing')
                ->generatePdfDocumentAndGetContent();
        });
    }

    /**
     * XSD errors of the XML for $invoice; empty when the document is schema-valid.
     * The business rules (EN 16931 / XRechnung Schematron) need the KoSIT validator,
     * see docs/e-invoicing.md.
     *
     * @return array<int, string>
     */
    public function schemaErrors(BillingInvoice $invoice, Billable $billable, ?string $profile = null): array
    {
        return $this->withLibrarySettings($invoice, function () use ($invoice, $billable, $profile): array {
            $validator = InvoiceSuiteXsdDocumentValidator::createFromContent(
                $this->render($invoice, $billable, $profile ?? $this->profile())
            )->validate();

            return self::messages($validator->getErrorOrInternalErrorMessagesInMessageBag());
        });
    }

    /**
     * The library's settings are globals; the two this document depends on are set for
     * its duration and restored, so nothing leaks into the next document or into the app.
     *
     *  - The amount precision follows the invoice's currency, so a JPY invoice cannot
     *    change the next EUR one rendered in the same worker.
     *  - Provider and validator discovery is confined to the library's own namespace.
     *    Unconfined, it loads *every* class in Composer's class map to ask
     *    `is_subclass_of()` — with an optimised autoloader that is all of `vendor/`. It
     *    took longer than PHP's 30-second limit on the first invoice of an app, and
     *    it can die outright on a class that cannot be loaded (Mockery's PHPUnit
     *    listener in a dev install). In the webhook that fatal came after the invoice
     *    had been written and before the paid plan change was applied. Every built-in
     *    provider and validator lives under `horstoeko\invoicesuite`; an app that
     *    registers providers of its own sets its namespaces and those are kept.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function withLibrarySettings(BillingInvoice $invoice, \Closure $callback): mixed
    {
        $previousDecimals = InvoiceSuiteSettings::getAmountDecimals();
        $previousNamespaces = InvoiceSuiteSettings::getDiscoveryNamespaces();

        InvoiceSuiteSettings::setAmountDecimals(BillingMoney::fractionDigits($invoice->currency));

        if ($previousNamespaces === []) {
            InvoiceSuiteSettings::setDiscoveryNamespaces([self::LIBRARY_NAMESPACE]);
        }

        try {
            return $callback();
        } finally {
            InvoiceSuiteSettings::setAmountDecimals($previousDecimals);
            InvoiceSuiteSettings::setDiscoveryNamespaces($previousNamespaces);
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private static function messages(array $items): array
    {
        return array_values(array_map(
            fn (mixed $item): string => $item instanceof InvoiceSuiteMessageBagItem ? $item->getMessageContent() : (string) $item,
            $items,
        ));
    }

    /**
     * The statement a zero-rated invoice has to carry on its face — for reverse charge
     * the wording of the seller's country ({@see sellerCountryText()}).
     * Null when every line is taxed. The readable PDF prints the same text the XML
     * states as exemption reason, so both parts of the hybrid say the same thing.
     */
    public function taxExemptionNote(BillingInvoice $invoice, Billable $billable): ?string
    {
        $zeroRated = false;
        foreach ((array) $invoice->line_items as $item) {
            if (abs((float) ($item['vat_rate'] ?? 0)) < 0.0001) {
                $zeroRated = true;
                break;
            }
        }

        if (! $zeroRated) {
            return null;
        }

        // The statement alone: both VAT IDs are printed, labelled, with the parties.
        return $this->compatibleZeroRateCategory($this->zeroRateCategory(
            $invoice,
            $this->sellerCountry(),
            $this->buyerCountry($invoice, $billable),
            $this->buyerVatId($invoice),
        ), (array) $invoice->line_items)['reason'];
    }

    /**
     * The final XML: the builder's output, with the one correction the library cannot
     * express. Its line-level tax setter drops the whole tax element when no rate is
     * given, while EN 16931 requires exactly that for a line "not subject to VAT"
     * (BR-O-05: category O, no rate). The line is therefore built with 0 % and the rate
     * removed here.
     */
    private function render(BillingInvoice $invoice, Billable $billable, string $profile): string
    {
        $xml = $this->build($invoice, $billable, $profile)->getContent();

        if (! str_contains($xml, '<ram:CategoryCode>O</ram:CategoryCode>')) {
            return $xml;
        }

        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

        $rates = $xpath->query('//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="O"]/ram:RateApplicablePercent');
        foreach ($rates === false ? [] : iterator_to_array($rates) as $rate) {
            $rate->parentNode?->removeChild($rate);
        }

        return (string) $dom->saveXML();
    }

    private function build(BillingInvoice $invoice, Billable $billable, string $profile): InvoiceSuiteDocumentBuilder
    {
        $isXRechnung = $profile === self::PROFILE_XRECHNUNG;
        $currency = BillingMoney::normalizeCurrency($invoice->currency);

        // A credit note states its amounts positively under type code 381; the stored
        // document is negative. Every amount below is multiplied by this sign.
        $isCreditNote = $invoice->invoice_kind === InvoiceKind::Refund;
        $sign = $isCreditNote ? -1 : 1;

        $lines = $this->lines($invoice, $sign);
        $sellerCountry = $this->sellerCountry();
        $buyerCountry = $this->buyerCountry($invoice, $billable);
        $buyerVatId = $this->buyerVatId($invoice);
        $category = $this->zeroRateCategory($invoice, $sellerCountry, $buyerCountry, $buyerVatId);

        $document = InvoiceSuiteDocumentBuilder::createByProviderUniqueId(
            $isXRechnung ? InvoiceSuiteBuiltInProviders::XRECHNUNG_CII : InvoiceSuiteBuiltInProviders::ZFFX_COMFORT
        );

        $issuedAt = $this->documentDate($invoice->created_at ?? BillingTime::nowUtc());

        $document
            ->setDocumentNo((string) ($invoice->serial_number ?? 'INV-'.$invoice->id))
            ->setDocumentType($isCreditNote ? self::TYPE_CREDIT_NOTE : self::TYPE_INVOICE)
            ->setDocumentDate($issuedAt)
            ->setDocumentCurrency($currency);

        $buyerReference = $this->buyerReference($billable);
        if ($buyerReference !== null) {
            $document->setDocumentBuyerReference($buyerReference);
        } elseif ($isXRechnung) {
            // BT-10 is mandatory in XRechnung (BR-DE-15). Public buyers give a Leitweg-ID
            // (see getBillingBuyerReference()); for everyone else the customer number is
            // the reference they can route on.
            $document->setDocumentBuyerReference((string) $billable->getKey());
        }

        if (! empty($invoice->refund_reason_text)) {
            $document->addDocumentNote((string) $invoice->refund_reason_text);
        }

        // The zero-rate category only describes zero-rated lines. A non-EU country with a
        // configured rate is taxed as S on every line, and must keep the seller's VAT ID
        // (BR-S-02) — only a document that really has O lines drops it (BR-O-02).
        $hasZeroRatedLine = false;
        foreach ($lines as $line) {
            if ($line['rate'] <= 0) {
                $hasZeroRatedLine = true;
                break;
            }
        }
        $category = $this->compatibleZeroRateCategory($category, $lines);
        $zeroRateCode = $hasZeroRatedLine ? $category['code'] : null;

        if ($zeroRateCode === 'AE') {
            $document->addDocumentNote((string) $category['reason']);
        }

        $this->addSeller($document, $zeroRateCode, $sellerCountry);
        $this->addBuyer($document, $billable, $buyerCountry, $buyerVatId, $zeroRateCode);

        // Invoicing period (BG-14): the period the supply covers. Without it the
        // service date (§ 14 (4) Nr. 6 UStG) is missing from the structured part.
        // The corrected invoices, once: for the delivery date and for BT-25.
        $preceding = $this->precedingInvoices($invoice);
        $deliveryDate = $this->deliveryDateFrom($invoice, $preceding);
        [$periodStart, $periodEnd] = $deliveryDate === null
            ? $this->servicePeriod($invoice->period_start, $invoice->period_end, $billable)
            : [null, null];

        // A credit note stores no header period, only the periods of the lines it
        // credits: their span is the period, not the credit note's own issue date.
        if ($deliveryDate === null && ($periodStart === null || $periodEnd === null)) {
            [$periodStart, $periodEnd] = $this->linePeriodSpan($lines, $billable);
        }

        if ($deliveryDate !== null) {
            $document->setDocumentSupplyChainEvent($deliveryDate);
        } elseif ($periodStart !== null && $periodEnd !== null) {
            $document->setDocumentBillingPeriod($periodStart, $periodEnd);
        } else {
            $document->setDocumentSupplyChainEvent($issuedAt);
        }

        // BT-25: the invoice(s) a credit note corrects.
        foreach ($preceding as $parent) {
            $document->addDocumentInvoiceReference(
                (string) $parent->serial_number,
                $this->documentDate($parent->created_at),
            );
        }

        $breakdown = [];
        $lineTotal = 0;

        foreach ($lines as $index => $line) {
            $lineCategory = $line['rate'] > 0 ? 'S' : $category['code'];

            $document
                ->addDocumentPosition((string) ($index + 1))
                ->setDocumentPositionProductDetails(null, $line['name'], $line['description'])
                ->setDocumentPositionNetPrice($this->amount($line['unit_price'], $currency))
                ->setDocumentPositionQuantities((float) $line['quantity'], self::UNIT_CODE)
                // Category O loses its rate again in render() (BR-O-05).
                ->setDocumentPositionTax($lineCategory, 'VAT', null, $line['rate']);

            [$lineStart, $lineEnd] = $deliveryDate === null
                ? $this->servicePeriod($line['period_start'], $line['period_end'], $billable)
                : [null, null];
            if ($lineStart !== null && $lineEnd !== null) {
                $document->setDocumentPositionBillingPeriod($lineStart, $lineEnd);
            }

            $document->setDocumentPositionSummation($this->amount($line['net'], $currency));

            $key = $lineCategory.'|'.number_format($line['rate'], 2, '.', '');
            $breakdown[$key] ??= ['category' => $lineCategory, 'rate' => $line['rate'], 'basis' => 0, 'vat' => 0];
            $breakdown[$key]['basis'] += $line['net'];
            $breakdown[$key]['vat'] += $line['vat'];
            $lineTotal += $line['net'];
        }

        $taxTotal = 0;
        foreach ($breakdown as $group) {
            $isZeroRated = $group['category'] !== 'S';

            $document->addDocumentTax(
                $group['category'],
                'VAT',
                $this->amount($group['basis'], $currency),
                $this->amount($group['vat'], $currency),
                // 0 for category O too: XRechnung requires BT-119 (BR-DE-14), and
                // EN 16931 forbids a rate only on the line (BR-O-05), not here.
                $group['rate'],
                $isZeroRated ? $category['reason'] : null,
                $isZeroRated ? $category['reason_code'] : null,
            );

            $taxTotal += $group['vat'];
        }

        $grandTotal = $lineTotal + $taxTotal;

        // Mollie has already collected (or, for a credit note, paid back) everything a
        // booked document states, so nothing is due. An open or failed invoice is due
        // on its issue date — BR-CO-25 wants a due date whenever an amount is payable.
        $settled = in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true);
        $prepaid = $settled ? $grandTotal : 0;
        $due = $grandTotal - $prepaid;

        $document->setDocumentPaymentMean(self::PAYMENT_MEANS_ONLINE, $this->paymentInformation($invoice));

        if ($due !== 0) {
            $document->setDocumentPaymentTerm(null, $issuedAt);
        }

        $document->setDocumentSummation(
            $this->amount($lineTotal, $currency),
            0.0,
            0.0,
            $this->amount($lineTotal, $currency),
            $this->amount($taxTotal, $currency),
            null,
            $this->amount($grandTotal, $currency),
            $this->amount($due, $currency),
            $this->amount($prepaid, $currency),
        );

        // The builder records what a profile cannot carry in its message bag instead of
        // throwing. A document that silently lost a field is not the invoice that was
        // booked, so it is refused here and the caller keeps the plain PDF.
        if ($document->hasErrorMessagesInMessageBag()) {
            throw new \RuntimeException('E-invoice could not be built: '.implode('; ', self::messages($document->getErrorMessagesInMessageBag())));
        }

        return $document;
    }

    /**
     * The stored lines in document orientation (credit-note sign applied), each
     * expressed so that quantity × unit price is exactly its net: EN 16931 forbids a
     * negative price (BR-27), so a negative line (a discount) carries a negative
     * quantity, and a line whose stored net is not an exact multiple of its unit price
     * (pro-rata seats) collapses to one unit carrying the true total — the same
     * collapse the PDF applies.
     *
     * @return list<array{name:string, description:?string, quantity:int, unit_price:int, net:int, vat:int, rate:float, period_start:mixed, period_end:mixed}>
     */
    private function lines(BillingInvoice $invoice, int $sign): array
    {
        $result = [];

        foreach ((array) $invoice->line_items as $item) {
            $quantity = abs((int) ($item['quantity'] ?? $item['qty'] ?? 1)) ?: 1;
            $unit = (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);
            $net = $this->lineNet($item, $quantity * $unit) * $sign;
            $rate = (float) ($item['vat_rate'] ?? 0);
            $vat = array_key_exists('vat_amount', $item)
                ? (int) $item['vat_amount'] * $sign
                : (int) round($net * $rate / 100);

            if ($net % $quantity !== 0) {
                $quantity = 1;
            }

            $unitPrice = intdiv(abs($net), $quantity);
            $signedQuantity = $net < 0 ? -$quantity : $quantity;

            $label = trim((string) ($item['label'] ?? $item['kind'] ?? ''));
            $description = $item['description'] ?? $item['billing_period'] ?? null;

            $result[] = [
                'name' => $label !== '' ? $label : 'Line',
                'description' => is_string($description) && trim($description) !== '' ? $description : null,
                'quantity' => $signedQuantity,
                'unit_price' => $unitPrice,
                'net' => $net,
                'vat' => $vat,
                'rate' => abs($rate) < 0.0001 ? 0.0 : $rate,
                'period_start' => $item['period_start'] ?? null,
                'period_end' => $item['period_end'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function lineNet(array $item, int $fallback): int
    {
        if (array_key_exists('total_net', $item)) {
            return (int) $item['total_net'];
        }

        if (array_key_exists('amount_net', $item)) {
            return (int) $item['amount_net'];
        }

        return $fallback;
    }

    /**
     * The VAT category (UNTDID 5305) of a zero-rated line, with the exemption reason
     * EN 16931 requires for it (BR-AE-10, BR-O-10, BR-E-10).
     *
     *  - AE — intra-EU B2B supply under reverse charge: the invoice relied on a valid VIES
     *    validation and the customer sits in another EU country than the seller.
     *  - O  — customer outside the EU: the service is not taxable where the seller is.
     *  - E  — anything else carrying 0 % (a configured zero rate, e.g. a small business).
     *
     * @return array{code:string, reason:string, reason_code:?string}
     */
    private function zeroRateCategory(BillingInvoice $invoice, ?string $sellerCountry, ?string $buyerCountry, ?string $buyerVatId): array
    {
        if ($buyerCountry !== null && ! CountryResolver::isEuMember($buyerCountry)) {
            return [
                'code' => 'O',
                'reason' => __('billing::portal.e_invoice.not_subject_reason'),
                'reason_code' => 'VATEX-EU-O',
            ];
        }

        $crossBorder = $sellerCountry === null || ! CountryResolver::sameCountry($sellerCountry, $buyerCountry);

        if ($buyerVatId !== null && $crossBorder) {
            return [
                'code' => 'AE',
                'reason' => $this->reverseChargeText(),
                'reason_code' => 'VATEX-EU-AE',
            ];
        }

        return $this->exemptCategory();
    }

    /**
     * @return array{code:string, reason:string, reason_code:?string}
     */
    private function exemptCategory(): array
    {
        $configured = config('mollie-billing.invoices.e_invoice.exemption_reason');

        return [
            'code' => 'E',
            'reason' => is_string($configured) && trim($configured) !== ''
                ? $configured
                : __('billing::portal.e_invoice.exempt_reason'),
            'reason_code' => null,
        ];
    }

    /**
     * "Not subject to VAT" (O) excludes every other category on the same document
     * (BR-O-11 to BR-O-14). A non-EU country with a configured rate taxes its lines as S,
     * so a zero-rated line next to them cannot be O; it is stated as exempt (E).
     *
     * @param  array{code:string, reason:string, reason_code:?string}  $category
     * @param  iterable<array{rate: float}>|array<int, array<string, mixed>>  $lines
     * @return array{code:string, reason:string, reason_code:?string}
     */
    private function compatibleZeroRateCategory(array $category, iterable $lines): array
    {
        if ($category['code'] !== 'O') {
            return $category;
        }

        foreach ($lines as $line) {
            $rate = (float) ($line['rate'] ?? $line['vat_rate'] ?? 0);

            if ($rate > 0) {
                return $this->exemptCategory();
            }
        }

        return $category;
    }

    /**
     * The reverse-charge statement: `invoices.e_invoice.reverse_charge_text`
     * (`BILLING_REVERSE_CHARGE_TEXT`) when set, else the seller country's wording.
     */
    private function reverseChargeText(): string
    {
        $configured = config('mollie-billing.invoices.e_invoice.reverse_charge_text');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : $this->sellerCountryText('reverse_charge_reason');
    }

    /**
     * A text of the invoice in the wording of the seller's country. Art. 226 Nr. 11a VAT
     * Directive only demands "reverse charge"; the national formulas differ, and a
     * German-language invoice from Vienna must not quote the German one:
     *
     *  - DE: "Steuerschuldnerschaft des Leistungsempfängers" (§ 14a (5) UStG, verbatim)
     *  - AT: "Übergang der Steuerschuld auf den Leistungsempfänger" (§ 11 (1a) UStG
     *    1994), and "UID-Nummer" rather than "USt-IdNr."
     *
     * Looked up as `billing::portal.e_invoice.{key}_{country}` in the rendering locale
     * only — never through the fallback locale, which would print another language — and
     * falling back to `billing::portal.e_invoice.{key}`. An app adds a country by
     * publishing that key.
     *
     * @param  array<string, string>  $replace
     */
    public function sellerCountryText(string $key, array $replace = []): string
    {
        $country = $this->sellerCountry() ?? $this->sellerVatIdCountry();

        if ($country !== null) {
            $specific = 'billing::portal.e_invoice.'.$key.'_'.strtolower($country);

            if (Lang::has($specific, null, false)) {
                return (string) __($specific, $replace);
            }
        }

        return (string) __('billing::portal.e_invoice.'.$key, $replace);
    }

    private function addSeller(InvoiceSuiteDocumentBuilder $document, ?string $zeroRateCategory, ?string $sellerCountry): void
    {
        $seller = (array) config('mollie-billing.invoices.seller', []);
        $address = (array) ($seller['address'] ?? []);

        $document->setDocumentSellerName((string) ($seller['company'] ?? config('app.name')));

        [$vatId, $taxNumber] = $this->sellerTaxIdentifiers($seller);

        // BR-O-02: an invoice whose lines are "not subject to VAT" carries no seller VAT
        // identifier. BR-CO-26 still wants the seller identified by BT-29, BT-30 or
        // BT-31 — the tax number (BT-32) does not count — so the VAT number moves to
        // the plain seller identifier (BT-29), falling back to the tax number.
        if ($zeroRateCategory === 'O') {
            $sellerId = $vatId ?? $taxNumber;
            if ($sellerId !== null) {
                $document->setDocumentSellerId($sellerId);
            }
            if ($taxNumber !== null) {
                $document->addDocumentSellerTaxRegistration('FC', $taxNumber);
            }
        } else {
            if ($vatId !== null) {
                $document->addDocumentSellerTaxRegistration('VA', $vatId);
            }
            if ($taxNumber !== null) {
                $document->addDocumentSellerTaxRegistration('FC', $taxNumber);
            }
        }

        $document->setDocumentSellerAddress(
            $this->nullable($address['street'] ?? null),
            null,
            null,
            $this->nullable($address['postal_code'] ?? null),
            $this->nullable($address['city'] ?? null),
            $sellerCountry,
            $this->nullable($address['state'] ?? null),
        );

        $email = $this->nullable($seller['email'] ?? null);
        $phone = $this->nullable($seller['phone'] ?? null);
        $contactName = $this->nullable($seller['name'] ?? null) ?? $this->nullable($seller['company'] ?? null);

        if ($email !== null || $phone !== null) {
            $document->setDocumentSellerContact($contactName, null, $phone, null, $email);
        }

        if ($email !== null) {
            $document->setDocumentSellerCommunication('EM', $email);
        }
    }

    private function addBuyer(
        InvoiceSuiteDocumentBuilder $document,
        Billable $billable,
        ?string $buyerCountry,
        ?string $buyerVatId,
        ?string $zeroRateCategory,
    ): void {
        $document
            ->setDocumentBuyerName($billable->getBillingName())
            ->setDocumentBuyerId((string) $billable->getKey());

        // Outside the EU there is no VAT identifier to state (BR-O-02 forbids it).
        if ($buyerVatId !== null && $zeroRateCategory !== 'O') {
            $document->setDocumentBuyerTaxRegistration('VA', $buyerVatId);
        }

        $document->setDocumentBuyerAddress(
            $this->nullable($billable->getBillingStreet()),
            null,
            null,
            $this->nullable($billable->getBillingPostalCode()),
            $this->nullable($billable->getBillingCity()),
            $buyerCountry,
        );

        $email = $this->nullable($billable->getBillingEmail());
        if ($email !== null) {
            $document->setDocumentBuyerCommunication('EM', $email);
        }
    }

    /**
     * Seller VAT ID (BT-31) and national tax number (BT-32, scheme "FC").
     *
     * `seller.vat_id` is the VAT identification number; `seller.tax_number` has long been
     * documented as "tax number" and is in practice set to either. A value shaped like a
     * VAT ID (country prefix + digits) in `tax_number` is therefore read as one.
     *
     * @param  array<string, mixed>  $seller
     * @return array{0:?string, 1:?string}
     */
    private function sellerTaxIdentifiers(array $seller): array
    {
        $vatId = $this->nullable($seller['vat_id'] ?? null);
        $taxNumber = $this->nullable($seller['tax_number'] ?? null);

        if ($taxNumber !== null && self::looksLikeVatId($taxNumber)) {
            $vatId ??= strtoupper(str_replace(' ', '', $taxNumber));
            $taxNumber = null;
        }

        return [$vatId !== null ? strtoupper(str_replace(' ', '', $vatId)) : null, $taxNumber];
    }

    /**
     * The seller's VAT ID and tax number as the invoice states them — the same pair for
     * the readable PDF and the XML.
     *
     * @return array{vat_id:?string, tax_number:?string}
     */
    public function sellerIdentifiers(): array
    {
        [$vatId, $taxNumber] = $this->sellerTaxIdentifiers((array) config('mollie-billing.invoices.seller', []));

        return ['vat_id' => $vatId, 'tax_number' => $taxNumber];
    }

    public static function looksLikeVatId(string $value): bool
    {
        return preg_match('/^[A-Z]{2}[0-9A-Z+*.]{2,13}$/', strtoupper(str_replace(' ', '', $value))) === 1;
    }

    /**
     * The country of the seller's own VAT ID (`ATU12345678` → AT). Only the wording of
     * the invoice falls back to it, when no seller country is configured: an Austrian
     * company that set nothing but its UID must not get the German formula. The tax
     * decision itself never reads it — that stays with {@see VatCalculationService}.
     */
    private function sellerVatIdCountry(): ?string
    {
        [$vatId] = $this->sellerTaxIdentifiers((array) config('mollie-billing.invoices.seller', []));

        return $vatId !== null ? CountryResolver::isoFromVatPrefix(substr($vatId, 0, 2)) : null;
    }

    private function sellerCountry(): ?string
    {
        $country = $this->vat->sellerCountry();

        return $country !== null ? CountryResolver::isoFromVatPrefix($country) : null;
    }

    private function buyerCountry(BillingInvoice $invoice, Billable $billable): ?string
    {
        // The invoice's own country is what it was taxed under; the billable may have
        // moved since.
        $country = $this->nullable($invoice->country) ?? $this->nullable($billable->getBillingCountry());

        return $country !== null ? CountryResolver::isoFromVatPrefix($country) : null;
    }

    /**
     * The buyer VAT ID the invoice relied on — its own VIES validation, not whatever
     * number the billable holds today.
     */
    public function buyerVatId(BillingInvoice $invoice): ?string
    {
        $validation = $invoice->vatValidation;

        if ($validation === null || ! $validation->valid) {
            return null;
        }

        $number = strtoupper(preg_replace('/\s+/', '', (string) $validation->vat_number) ?? '');
        if ($number === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}/', $number) !== 1 && ! empty($validation->country_code)) {
            $number = CountryResolver::vatPrefixFor((string) $validation->country_code).$number;
        }

        return $number;
    }

    /**
     * BT-10 — a Leitweg-ID for German public buyers, a purchase order reference for
     * anyone else. Optional: a billable model opts in with `getBillingBuyerReference()`.
     */
    private function buyerReference(Billable $billable): ?string
    {
        if (! method_exists($billable, 'getBillingBuyerReference')) {
            return null;
        }

        return $this->nullable($billable->getBillingBuyerReference());
    }

    /**
     * The invoices a credit note corrects (its lines' `parent_invoice_id`). One query —
     * callers load them once per document.
     *
     * @return Collection<int, BillingInvoice>
     */
    private function precedingInvoices(BillingInvoice $invoice): Collection
    {
        if ($invoice->invoice_kind !== InvoiceKind::Refund) {
            return new Collection;
        }

        $parentIds = [];
        foreach ((array) $invoice->line_items as $item) {
            if (! empty($item['parent_invoice_id'])) {
                $parentIds[(int) $item['parent_invoice_id']] = true;
            }
        }

        if ($parentIds === []) {
            return new Collection;
        }

        return BillingInvoice::query()
            ->whereKey(array_keys($parentIds))
            ->whereNotNull('serial_number')
            ->orderBy('id')
            ->get();
    }

    private function paymentInformation(BillingInvoice $invoice): string
    {
        $details = $invoice->payment_method_details;
        $summary = is_array($details) ? ($details['summary'] ?? null) : null;
        $method = is_array($details) ? ($details['method'] ?? null) : null;

        $parts = array_filter([
            'Mollie',
            is_string($method) && $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : null,
            is_string($summary) && $summary !== '' && $summary !== ucfirst(str_replace('_', ' ', (string) $method)) ? $summary : null,
        ]);

        return implode(' – ', $parts);
    }

    /**
     * A stored [start, end) period as inclusive calendar dates.
     *
     * Period boundaries are billing days, computed in UTC whatever the billable's
     * timezone ({@see BillingTime::billingDay()}, docs/timezone.md): converted, a period
     * ending at 00:00 UTC ended a day late in Vienna and began a day early in New York.
     * The instant behind a boundary carries an incidental hour (Mollie charges at 00:28
     * UTC, a first payment at whatever hour it was made), so the end is the day *before*
     * the next period's first day, whatever the hour — 14 Nov 10:00 to 1 Dec 00:28 is
     * 14–30 November. A period within one day stays that day.
     *
     * @return array{0:?\DateTimeInterface, 1:?\DateTimeInterface}
     */
    public function servicePeriod(mixed $start, mixed $end, Billable $billable): array
    {
        $start = BillingTime::billingDay($this->toCarbon($start));
        $end = BillingTime::billingDay($this->toCarbon($end));

        if ($start === null || $end === null) {
            return [null, null];
        }

        $startDay = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        if ($endDay->greaterThan($startDay)) {
            $endDay = $endDay->subDay();
        } else {
            $endDay = $startDay->copy();
        }

        return [$startDay->toDateTime(), $endDay->toDateTime()];
    }

    private function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return BillingTime::toUtc($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The delivery date of a one-off supply, or null for a supply over a period.
     *
     * A product (one-time order) is delivered when it is paid, so its invoice states a
     * delivery date (BT-72, "Leistungsdatum"), not a service period. The stored period
     * cannot say so: `createForPayment()` stamps a one-time order with the billable's
     * current subscription period — or a month from now — because refunds locate lines
     * by it. A credit note for one-time orders states the date of the invoice it corrects.
     * Subscriptions and usage keep their period.
     */
    public function deliveryDate(BillingInvoice $invoice): ?\DateTime
    {
        if ($invoice->invoice_kind === InvoiceKind::OneTimeOrder) {
            return $this->documentDate($invoice->created_at ?? BillingTime::nowUtc());
        }

        if ($invoice->invoice_kind !== InvoiceKind::Refund) {
            return null;
        }

        return $this->deliveryDateFrom($invoice, $this->precedingInvoices($invoice));
    }

    /**
     * {@see deliveryDate()} with the corrected invoices already loaded.
     *
     * @param  Collection<int, BillingInvoice>  $parents
     */
    private function deliveryDateFrom(BillingInvoice $invoice, Collection $parents): ?\DateTime
    {
        if ($invoice->invoice_kind === InvoiceKind::OneTimeOrder) {
            return $this->documentDate($invoice->created_at ?? BillingTime::nowUtc());
        }

        if ($invoice->invoice_kind !== InvoiceKind::Refund || $parents->isEmpty()) {
            return null;
        }

        if ($parents->contains(fn (BillingInvoice $parent): bool => $parent->invoice_kind !== InvoiceKind::OneTimeOrder)) {
            return null;
        }

        return $this->documentDate($parents->max('created_at'));
    }

    /**
     * The span of the lines' own service periods, or [null, null] when none has one.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0:?\DateTimeInterface, 1:?\DateTimeInterface}
     */
    private function linePeriodSpan(array $lines, Billable $billable): array
    {
        $start = null;
        $end = null;

        foreach ($lines as $line) {
            [$lineStart, $lineEnd] = $this->servicePeriod($line['period_start'], $line['period_end'], $billable);

            if ($lineStart === null || $lineEnd === null) {
                continue;
            }

            $start = $start === null || $lineStart < $start ? $lineStart : $start;
            $end = $end === null || $lineEnd > $end ? $lineEnd : $end;
        }

        return [$start, $end];
    }

    /**
     * The calendar date a document carries — the date the readable PDF prints, which
     * is `created_at` as stored (UTC). The two parts of a hybrid invoice must not
     * disagree, so the XML does not convert it into the billable's timezone: a document
     * created at 23:30 UTC would otherwise be dated a day apart in PDF and XML.
     */
    private function documentDate(?CarbonInterface $at): ?\DateTime
    {
        return $at === null
            ? null
            : new \DateTime($at->copy()->setTimezone('UTC')->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    private function amount(int $minor, string $currency): float
    {
        return $minor / BillingMoney::minorUnitFactor($currency);
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

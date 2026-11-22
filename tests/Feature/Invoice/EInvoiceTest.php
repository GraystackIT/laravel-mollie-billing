<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\EInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Tests\Support\EInvoiceFixtures;
use GraystackIT\MollieBilling\Tests\Support\PdfText;
use Illuminate\Support\Facades\Storage;

/*
 * The structured e-invoice (EN 16931 CII) embedded in every invoice PDF as ZUGFeRD.
 * Read back from the stored PDF with the library's own reader and checked against the
 * CII schema; the business rules are checked against the KoSIT validator outside the
 * suite (docs/e-invoicing.md), the figures here are asserted by XPath.
 */

beforeEach(function (): void {
    Storage::fake('local');
    EInvoiceFixtures::configureSeller();
});

function storedPdf(BillingInvoice $invoice): string
{
    expect(app(InvoiceService::class)->regeneratePdf($invoice))->toBeTrue();
    $invoice->refresh();

    return (string) Storage::disk($invoice->pdf_disk)->get($invoice->pdf_path);
}

it('embeds a schema-valid EN 16931 invoice into the stored PDF', function (): void {
    $invoice = EInvoiceFixtures::invoice();
    $pdf = storedPdf($invoice);
    $embedded = EInvoiceFixtures::embedded($pdf);
    expect($embedded)->not->toBeNull()
        ->and($embedded['filename'])->toBe('factur-x.xml');
    $x = EInvoiceFixtures::xpath($embedded['xml']);

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, '//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'))
        ->toBe('urn:cen.eu:en16931:2017')
        ->and(EInvoiceFixtures::value($x, '//rsm:ExchangedDocument/ram:ID'))->toBe($invoice->serial_number)
        ->and(EInvoiceFixtures::value($x, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('380')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:InvoiceCurrencyCode'))->toBe('EUR')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('ATU12345678')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))->toBe('AT')
        ->and(EInvoiceFixtures::value($x, '//ram:BuyerTradeParty/ram:Name'))->toBe('Kundin GmbH')
        ->and(EInvoiceFixtures::value($x, '//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))->toBe('AT')
        // Service period: November, inclusive of the 30th.
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'))->toBe('20261101')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'))->toBe('20261130')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe('S')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:RateApplicablePercent'))->toBe('20.00')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe('38.50')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe('7.70')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:LineTotalAmount'))->toBe('38.50')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:TaxTotalAmount'))->toBe('7.70')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:GrandTotalAmount'))->toBe('46.20')
        // Mollie has collected it: nothing is due.
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:TotalPrepaidAmount'))->toBe('46.20')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:DuePayableAmount'))->toBe('0.00')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode'))->toBe('68');
});

it('states every line so that quantity times price is its net', function (): void {
    $invoice = EInvoiceFixtures::invoice();
    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    $lines = $x->query('//ram:IncludedSupplyChainTradeLineItem');
    expect($lines->length)->toBe(2);

    // 3 seats × 2.83 ≠ 8.50: collapsed to one unit carrying the true total, as on the PDF.
    $seats = $lines->item(1);
    expect(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:BilledQuantity'))->toBe('1.00')
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:NetPriceProductTradePrice/ram:ChargeAmount'))->toBe('8.50')
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:LineTotalAmount'))->toBe('8.50')
        ->and($seats)->not->toBeNull();
});

it('states a discount line with a negative quantity, never a negative price', function (): void {
    $invoice = EInvoiceFixtures::invoice(lines: [
        [
            'kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1,
            'unit_price_net' => 3000, 'amount_net' => 3000,
            'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600,
        ],
        [
            'kind' => 'coupon', 'label' => 'Coupon WELCOME', 'quantity' => 1,
            'unit_price_net' => -500, 'amount_net' => -500,
            'vat_rate' => 20.0, 'vat_amount' => -100, 'amount_gross' => -600,
        ],
    ]);

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:BilledQuantity'))->toBe('-1.00')
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:NetPriceProductTradePrice/ram:ChargeAmount'))->toBe('5.00')
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[2]//ram:LineTotalAmount'))->toBe('-5.00')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:GrandTotalAmount'))->toBe('30.00');
});

it('marks an intra-EU reverse-charge invoice as AE with both VAT IDs and the statement on the PDF', function (): void {
    $invoice = EInvoiceFixtures::withValidVatNumber(
        EInvoiceFixtures::invoice('DE', lines: EInvoiceFixtures::zeroRatedLines()),
        'DE123456789',
        'DE',
    );

    $pdf = storedPdf($invoice);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe('AE')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:ExemptionReasonCode'))->toBe('VATEX-EU-AE')
        ->and(EInvoiceFixtures::value($x, '//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('DE123456789')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('ATU12345678')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:TaxTotalAmount'))->toBe('0.00');

    expect(PdfText::of($pdf))->toContain('Reverse charge')
        ->and(PdfText::of($pdf))->toContain('VAT ID: DE123456789')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:ExemptionReason'))->toBe('Reverse charge');
});

function reverseChargePdfText(string $sellerCountry, string $sellerVatId, string $buyerCountry, string $buyerVatId): array
{
    config()->set('mollie-billing.invoices.locale', 'de');
    config()->set('mollie-billing.vat.seller_country', $sellerCountry);
    config()->set('mollie-billing.invoices.seller.address.country', $sellerCountry);
    config()->set('mollie-billing.invoices.seller.vat_id', $sellerVatId);
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $invoice = EInvoiceFixtures::withValidVatNumber(
        EInvoiceFixtures::invoice($buyerCountry, lines: EInvoiceFixtures::zeroRatedLines()),
        $buyerVatId,
        $buyerCountry,
    );

    $pdf = storedPdf($invoice);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    return [PdfText::of($pdf), (string) EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:ExemptionReason')];
}

it('words the reverse-charge statement as Austrian law does for an Austrian seller', function (): void {
    [$text, $reason] = reverseChargePdfText('AT', 'ATU12345678', 'DE', 'DE123456789');

    // § 11 Abs. 1a UStG 1994 — not the German § 14a formula.
    // The statement alone; both UIDs stand, labelled, with the parties.
    expect($text)->toContain('Übergang der Steuerschuld auf den Leistungsempfänger')
        ->and($text)->not->toContain('Übergang der Steuerschuld auf den Leistungsempfänger (')
        ->and($text)->not->toContain('MwStSystRL')
        ->and($text)->toContain('UID-Nr.: ATU12345678')
        ->and($text)->toContain('UID-Nr.: DE123456789')
        ->and($text)->not->toContain('Steuerschuldnerschaft')
        ->and($text)->not->toContain('USt-IdNr.')
        ->and($reason)->toBe('Übergang der Steuerschuld auf den Leistungsempfänger');
});

it('words the reverse-charge statement as German law does for a German seller', function (): void {
    [$text, $reason] = reverseChargePdfText('DE', 'DE123456789', 'AT', 'ATU98765432');

    // § 14a Abs. 5 UStG prescribes the wording verbatim.
    expect($text)->toContain('Steuerschuldnerschaft des Leistungsempfängers')
        ->and($text)->not->toContain('MwStSystRL')
        ->and($text)->toContain('USt-IdNr.: ATU98765432')
        ->and($text)->toContain('USt-IdNr.: DE123456789')
        ->and($reason)->toBe('Steuerschuldnerschaft des Leistungsempfängers');
});

it('words the statement for an Austrian seller whose only country hint is its UID', function (): void {
    app()->setLocale('de');
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.invoices.seller.address.country', null);
    config()->set('mollie-billing.invoices.seller.vat_id', null);
    config()->set('mollie-billing.invoices.seller.tax_number', 'ATU12345678');

    $invoice = EInvoiceFixtures::withValidVatNumber(
        EInvoiceFixtures::invoice('DE', lines: EInvoiceFixtures::zeroRatedLines()),
        'DE123456789',
        'DE',
    );

    expect(app(EInvoiceService::class)->taxExemptionNote($invoice, $invoice->billable))
        ->toBe('Übergang der Steuerschuld auf den Leistungsempfänger');
});

it('takes the reverse-charge statement from BILLING_REVERSE_CHARGE_TEXT when set', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.reverse_charge_text', 'Reverse Charge – die Steuerschuld geht auf den Leistungsempfänger über.');

    [$text, $reason] = reverseChargePdfText('AT', 'ATU12345678', 'DE', 'DE123456789');

    expect($text)->toContain('Reverse Charge – die Steuerschuld geht auf den Leistungsempfänger über.')
        ->and($text)->not->toContain('Übergang der Steuerschuld auf den Leistungsempfänger')
        ->and($reason)->toBe('Reverse Charge – die Steuerschuld geht auf den Leistungsempfänger über.');
});

it('states a supply to a customer outside the EU as not subject to VAT, without VAT identifiers', function (): void {
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $invoice = EInvoiceFixtures::invoice(
        'US',
        ['billing_city' => 'Austin', 'billing_postal_code' => '78701'],
        EInvoiceFixtures::zeroRatedLines(),
    );

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe('O')
        ->and($x->query('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]')->length)->toBe(0)
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'))->toBe('12/345/67890')
        // BR-O-05: no rate on a line that is not subject to VAT; BR-CO-26: the seller
        // is still identified, by BT-29.
        ->and($x->query('//ram:IncludedSupplyChainTradeLineItem//ram:RateApplicablePercent')->length)->toBe(0)
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:ID'))->toBe('ATU12345678');
});

it('states a domestic zero-rated invoice as exempt, with the configured reason', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.exemption_reason', 'Gemäß § 6 Abs. 1 Z 27 UStG steuerfrei (Kleinunternehmer).');

    $invoice = EInvoiceFixtures::invoice(lines: EInvoiceFixtures::zeroRatedLines());
    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe('E')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:ExemptionReason'))
        ->toBe('Gemäß § 6 Abs. 1 Z 27 UStG steuerfrei (Kleinunternehmer).');
});

it('renders the e-invoice PDF in an embedded font', function (): void {
    $pdf = storedPdf(EInvoiceFixtures::invoice());

    // PDF/A-3 requires embedded fonts; DOMPDF's default Helvetica is never embedded.
    expect($pdf)->toContain('DejaVuSans')
        ->and($pdf)->toContain('/FontFile2')
        ->and($pdf)->not->toContain('/BaseFont /Helvetica');
});

it('prints the seller VAT ID on the PDF it states in the XML', function (): void {
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $invoice = EInvoiceFixtures::invoice();
    $pdf = storedPdf($invoice);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(PdfText::of($pdf))->toContain('VAT ID: ATU12345678')
        ->and(PdfText::of($pdf))->toContain('12/345/67890')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('ATU12345678');
});

it('dates PDF and XML alike, also for an invoice created just before midnight UTC', function (): void {
    // 23:30 UTC on 31 December is already 1 January in Vienna.
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');
    $invoice = EInvoiceFixtures::invoice(invoiceAttributes: ['created_at' => '2026-12-31 23:30:00']);

    $pdf = storedPdf($invoice);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(EInvoiceFixtures::value($x, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'))->toBe('20261231')
        ->and(PdfText::of($pdf))->toContain('2026-12-31');
});

it('prints the service period, German dates, the payment method in words and labelled tax numbers', function (): void {
    config()->set('mollie-billing.invoices.locale', 'de');
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $invoice = EInvoiceFixtures::withValidVatNumber(
        EInvoiceFixtures::invoice('DE', invoiceAttributes: ['created_at' => '2026-11-01 08:15:00']),
        'DE123456789',
        'DE',
    );
    $text = PdfText::of(storedPdf($invoice));

    // § 14 Abs. 4 Nr. 6 UStG / § 11 Abs. 1 Z 3 lit. d UStG 1994, inclusive end date.
    expect($text)->toContain('Leistungszeitraum: 01.11.2026 – 30.11.2026')
        // The seats line carries no period of its own and takes the invoice's.
        ->and(substr_count($text, 'Leistungszeitraum: 01.11.2026 – 30.11.2026'))->toBe(2)
        ->and($text)->toContain('Rechnungsdatum 01.11.2026')
        ->and($text)->not->toContain('2026-11-01')
        ->and($text)->toContain('Abgebucht via Kreditkarte')
        ->and($text)->not->toContain('Creditcard')
        // Austrian seller: UID-Nr. for both parties, the tax number labelled.
        ->and($text)->toContain('UID-Nr.: ATU12345678')
        ->and($text)->toContain('UID-Nr.: DE123456789')
        ->and($text)->toContain('Steuernummer: 12/345/67890');
});

it('states a delivery date, not a service period, for a product and its credit note', function (): void {
    config()->set('mollie-billing.invoices.locale', 'de');

    // createForPayment() stamps a one-time order with the billable's subscription period;
    // a product is delivered on payment, so that period must not reach the document.
    $order = EInvoiceFixtures::invoice(lines: [[
        'kind' => 'product', 'code' => 'tokens-1000', 'label' => '1.000 Tokens', 'quantity' => 1,
        'unit_price_net' => 1900, 'amount_net' => 1900, 'vat_rate' => 20.0, 'vat_amount' => 380,
        'period_start' => '2026-11-01T00:00:00+00:00', 'period_end' => '2026-12-01T00:00:00+00:00',
    ]], invoiceAttributes: [
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::OneTimeOrder,
        'created_at' => '2026-11-14 10:00:00',
    ]);

    $pdf = storedPdf($order);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(PdfText::of($pdf))->toContain('Leistungsdatum: 14.11.2026')
        ->and(PdfText::of($pdf))->not->toContain('Leistungszeitraum')
        ->and(EInvoiceFixtures::value($x, '//ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'))->toBe('20261114')
        ->and($x->query('//ram:BillingSpecifiedPeriod')->length)->toBe(0)
        ->and(app(EInvoiceService::class)->schemaErrors($order, $order->billable))->toBe([]);

    $creditNote = app(InvoiceService::class)->createCreditNote($order->refresh(), 1900);
    $creditNote->refresh();
    $creditPdf = (string) Storage::disk($creditNote->pdf_disk)->get($creditNote->pdf_path);
    $cx = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($creditPdf)['xml'] ?? '');

    expect(PdfText::of($creditPdf))->toContain('Leistungsdatum: 14.11.2026')
        ->and(PdfText::of($creditPdf))->not->toContain('Leistungszeitraum')
        ->and($cx->query('//ram:BillingSpecifiedPeriod')->length)->toBe(0)
        ->and(EInvoiceFixtures::value($cx, '//ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'))->toBe('20261114');
});

it('loads the corrected invoices once per document, not once per credit-note line', function (): void {
    $order = EInvoiceFixtures::invoice(lines: [[
        'kind' => 'product', 'label' => 'Tokens', 'quantity' => 1,
        'unit_price_net' => 100000, 'amount_net' => 100000, 'vat_rate' => 20.0, 'vat_amount' => 20000,
    ]], invoiceAttributes: ['invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::OneTimeOrder]);

    $creditNoteWith = function (int $lines) use ($order): BillingInvoice {
        $items = [];
        for ($i = 0; $i < $lines; $i++) {
            $items[] = [
                'kind' => 'refund', 'label' => 'Credit '.$i, 'quantity' => 1,
                'unit_price_net' => -10, 'amount_net' => -10, 'vat_rate' => 20.0, 'vat_amount' => -2,
                'parent_invoice_id' => $order->id, 'parent_line_item_index' => 0,
            ];
        }

        return app(InvoiceService::class)->createCreditNote($order->refresh(), 10 * $lines, $items, deferDocument: true);
    };

    $parentQueries = function (BillingInvoice $creditNote): int {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $rendered = app(InvoiceService::class)->regeneratePdf($creditNote);
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // A render that failed before the lookup would measure 0 and pass for nothing.
        expect($rendered)->toBeTrue();

        return collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'from "billing_invoices"') && str_contains($q['query'], '"id" in'))
            ->count();
    };

    $one = $parentQueries($creditNoteWith(1));
    $hundred = $parentQueries($creditNoteWith(100));

    // One lookup for the readable PDF and one for the XML — whatever the number of lines.
    expect($hundred)->toBe($one)
        ->and($one)->toBeLessThanOrEqual(2);
});

it('takes the PDF date format from BILLING_INVOICE_DATE_FORMAT when set', function (): void {
    config()->set('mollie-billing.invoices.locale', 'de');
    config()->set('mollie-billing.invoices.date_format', 'j. n. Y');

    $text = PdfText::of(storedPdf(EInvoiceFixtures::invoice(invoiceAttributes: ['created_at' => '2026-11-01 08:15:00'])));

    expect($text)->toContain('Rechnungsdatum 1. 11. 2026')
        ->and($text)->toContain('Leistungszeitraum: 1. 11. 2026 – 30. 11. 2026')
        // The vendor config is restored after rendering.
        ->and(config('invoices.date_format'))->toBe('Y-m-d');
});

it('keeps the seller VAT ID on a taxed invoice to a non-EU country', function (): void {
    // A non-EU country with a configured rate (additional_countries) is taxed as S on every
    // line; BR-S-02 then requires the seller's VAT registration. Only category O drops it.
    $invoice = EInvoiceFixtures::invoice('CH', ['billing_city' => 'Zürich', 'billing_postal_code' => '8001'], [[
        'kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1,
        'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 8.1, 'vat_amount' => 243,
    ]]);

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe('S')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('ATU12345678')
        ->and(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([]);
});

it('dates a credit note for a subscription by the period its lines credit, not by its own issue date', function (): void {
    $invoice = EInvoiceFixtures::invoice(invoiceAttributes: ['created_at' => '2026-11-01 08:00:00']);
    storedPdf($invoice);

    \Illuminate\Support\Carbon::setTestNow('2026-12-10 10:00:00');
    try {
        $creditNote = app(InvoiceService::class)->createCreditNote($invoice->refresh(), 1000, deferDocument: true);
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($creditNote->refresh(), $creditNote->billable));

    // November, the period credited — not 10 December as a delivery date.
    expect(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'))->toBe('20261101')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'))->toBe('20261130')
        ->and($x->query('//ram:ActualDeliverySupplyChainEvent')->length)->toBe(0);
});

it('never mixes category O with taxed lines', function (): void {
    // A taxed non-EU country (additional_countries) with one zero-rated line: BR-O-11/12
    // forbid O next to S, so the zero-rated line is exempt (E) instead.
    $invoice = EInvoiceFixtures::invoice('CH', ['billing_city' => 'Zürich', 'billing_postal_code' => '8001'], [
        ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 8.1, 'vat_amount' => 243],
        ['kind' => 'addon', 'label' => 'Add-on', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500, 'vat_rate' => 0.0, 'vat_amount' => 0],
    ]);

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));
    $categories = [];
    foreach ($x->query(EInvoiceFixtures::SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode') as $node) {
        $categories[] = $node->textContent;
    }

    expect($categories)->toBe(['S', 'E'])
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'))->toBe('ATU12345678')
        ->and(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([]);
});

it('states service periods as UTC billing days in every timezone', function (string $timezone): void {
    // Period boundaries are billing days, computed in UTC (docs/timezone.md): a period
    // [1 Nov 00:00 UTC, 1 Dec 00:00 UTC) is 1–30 November in Vienna and in New York too.
    config()->set('mollie-billing.billing_timezone', $timezone);
    config()->set('mollie-billing.invoices.locale', 'de');

    $invoice = EInvoiceFixtures::invoice(invoiceAttributes: ['created_at' => '2026-11-01 08:00:00']);
    $pdf = storedPdf($invoice);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'))->toBe('20261101')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'))->toBe('20261130')
        ->and(PdfText::of($pdf))->toContain('Leistungszeitraum: 01.11.2026 – 30.11.2026');
})->with(['UTC', 'Europe/Vienna', 'America/New_York']);

it('ends a period anchored at an incidental hour on the day before the next one starts', function (): void {
    // Mollie charges at an hour nobody chooses (00:28 UTC measured); the boundary is the day.
    $invoice = EInvoiceFixtures::invoice(lines: [[
        'kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1,
        'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600,
        'period_start' => '2026-11-14T10:00:00+00:00', 'period_end' => '2026-12-01T00:28:00+00:00',
    ]], invoiceAttributes: ['period_start' => '2026-11-14 10:00:00', 'period_end' => '2026-12-01 00:28:00']);

    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[1]//ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'))->toBe('20261114')
        ->and(EInvoiceFixtures::value($x, '//ram:IncludedSupplyChainTradeLineItem[1]//ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'))->toBe('20261130');
});

it('issues a credit note as type 381 with positive amounts and the invoice it corrects', function (): void {
    $invoice = EInvoiceFixtures::invoice();
    storedPdf($invoice);

    $creditNote = app(InvoiceService::class)->createCreditNote($invoice->refresh(), 3850);
    $creditNote->refresh();

    $pdf = (string) Storage::disk($creditNote->pdf_disk)->get($creditNote->pdf_path);
    $x = EInvoiceFixtures::xpath(EInvoiceFixtures::embedded($pdf)['xml'] ?? '');

    expect(app(EInvoiceService::class)->schemaErrors($creditNote, $creditNote->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('381')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:LineTotalAmount'))->toBe('38.50')
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SUMMATION.'/ram:GrandTotalAmount'))->toBe(number_format(-$creditNote->amount_gross / 100, 2, '.', ''))
        ->and(EInvoiceFixtures::value($x, EInvoiceFixtures::SETTLEMENT.'/ram:InvoiceReferencedDocument/ram:IssuerAssignedID'))->toBe($invoice->serial_number);
});

it('produces a schema-valid XRechnung with a buyer reference and seller contact', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.profile', 'xrechnung');

    $invoice = EInvoiceFixtures::invoice();
    $x = EInvoiceFixtures::xpath(app(EInvoiceService::class)->xml($invoice, $invoice->billable));

    expect(app(EInvoiceService::class)->schemaErrors($invoice, $invoice->billable))->toBe([])
        ->and(EInvoiceFixtures::value($x, '//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'))
        ->toStartWith('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3')
        ->and(EInvoiceFixtures::value($x, '//ram:BuyerReference'))->toBe((string) $invoice->billable->getKey())
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID'))->toBe('billing@graystack.test')
        ->and(EInvoiceFixtures::value($x, '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'))->toBe('billing@graystack.test');
});

it('is on by default and switched off with BILLING_E_INVOICE_ENABLED=false', function (): void {
    $shipped = fn (): array => (require dirname(__DIR__, 3).'/config/mollie-billing.php')['invoices']['e_invoice'];

    expect($shipped()['enabled'])->toBeTrue();

    putenv('BILLING_E_INVOICE_ENABLED=false');
    try {
        expect($shipped()['enabled'])->toBeFalse();
    } finally {
        putenv('BILLING_E_INVOICE_ENABLED');
    }
});

it('stores a plain PDF when e-invoicing is disabled', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.enabled', false);

    $pdf = storedPdf(EInvoiceFixtures::invoice());

    expect(EInvoiceFixtures::embedded($pdf))->toBeNull()
        ->and(PdfText::of($pdf))->not->toContain('Reverse charge');
});

it('keeps the plain PDF when the e-invoice cannot be built', function (): void {
    app()->bind(EInvoiceService::class, fn () => new class(app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class)) extends EInvoiceService
    {
        public function embedIntoPdf(string $pdf, BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable, ?string $profile = null): string
        {
            throw new RuntimeException('boom');
        }
    });

    $invoice = EInvoiceFixtures::invoice();
    $pdf = storedPdf($invoice);

    expect($pdf)->toStartWith('%PDF')
        ->and(PdfText::of($pdf))->toContain((string) $invoice->serial_number);
});

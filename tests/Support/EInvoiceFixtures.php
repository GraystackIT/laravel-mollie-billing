<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use DOMDocument;
use DOMXPath;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use horstoeko\invoicesuite\InvoiceSuitePdfDocumentReader;

/**
 * Seller configuration and booked invoices for the e-invoice tests
 * (tests/Feature/Invoice/EInvoice*Test.php).
 */
final class EInvoiceFixtures
{
    public const SETTLEMENT = '//rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';

    public const SUMMATION = self::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    /** An Austrian seller with complete master data. */
    public static function configureSeller(): void
    {
        config()->set('mollie-billing.invoices.disk', 'local');
        config()->set('mollie-billing.invoices.seller.company', 'Graystack Test GmbH');
        config()->set('mollie-billing.invoices.seller.name', 'Erika Muster');
        config()->set('mollie-billing.invoices.seller.email', 'billing@graystack.test');
        config()->set('mollie-billing.invoices.seller.phone', '+43 1 234567');
        config()->set('mollie-billing.invoices.seller.vat_id', 'ATU12345678');
        config()->set('mollie-billing.invoices.seller.tax_number', null);
        config()->set('mollie-billing.invoices.seller.address.street', 'Teststraße 1');
        config()->set('mollie-billing.invoices.seller.address.city', 'Wien');
        config()->set('mollie-billing.invoices.seller.address.postal_code', '1010');
        config()->set('mollie-billing.invoices.seller.address.country', 'AT');
        config()->set('mollie-billing.vat.seller_country', 'AT');
        config()->set('mollie-billing.invoices.e_invoice.enabled', true);
        config()->set('mollie-billing.invoices.e_invoice.profile', 'en16931');
    }

    /**
     * A plan line (20 %, with a period) and a pro-rata seats line whose net is not
     * quantity × unit price — the shape the renderer has to collapse.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLines(): array
    {
        return [
            [
                'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro plan (monthly)', 'quantity' => 1,
                'unit_price_net' => 3000, 'amount_net' => 3000,
                'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600,
                'period_start' => '2026-11-01T00:00:00+00:00', 'period_end' => '2026-12-01T00:00:00+00:00',
            ],
            [
                'kind' => 'seats', 'code' => 'seats', 'label' => 'Extra seats', 'quantity' => 3,
                'unit_price_net' => 283, 'amount_net' => 850,
                'vat_rate' => 20.0, 'vat_amount' => 170, 'amount_gross' => 1020,
            ],
        ];
    }

    /**
     * One zero-rated plan line.
     *
     * @return list<array<string, mixed>>
     */
    public static function zeroRatedLines(): array
    {
        return [[
            'kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1,
            'unit_price_net' => 3000, 'amount_net' => 3000,
            'vat_rate' => 0.0, 'vat_amount' => 0, 'amount_gross' => 3000,
        ]];
    }

    /**
     * @param  array<string, mixed>  $billableAttributes
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $invoiceAttributes
     */
    public static function invoice(
        string $country = 'AT',
        array $billableAttributes = [],
        ?array $lines = null,
        array $invoiceAttributes = [],
    ): BillingInvoice {
        $billable = new TestBillable;
        $billable->forceFill(array_merge([
            'email' => uniqid('einv-').'@example.test',
            'name' => 'Kundin GmbH',
            'billing_street' => 'Hauptplatz 7',
            'billing_postal_code' => '8010',
            'billing_city' => 'Graz',
            'billing_country' => $country,
        ], $billableAttributes))->save();

        $lines ??= self::defaultLines();

        $net = array_sum(array_column($lines, 'amount_net'));
        $vat = array_sum(array_column($lines, 'vat_amount'));

        $invoice = new BillingInvoice;
        $invoice->forceFill(array_merge([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'mollie_payment_id' => uniqid('tr_einv_'),
            'serial_number' => uniqid('IN-'),
            'invoice_kind' => InvoiceKind::Subscription,
            'status' => InvoiceStatus::Paid,
            'country' => $country,
            'currency' => 'EUR',
            'amount_net' => $net,
            'amount_vat' => $vat,
            'amount_gross' => $net + $vat,
            'refunded_net' => 0,
            'line_items' => $lines,
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-12-01 00:00:00',
            'payment_method_details' => ['method' => 'creditcard', 'summary' => 'Visa **** 1234', 'details' => []],
        ], $invoiceAttributes))->save();

        return $invoice;
    }

    /** Attach a valid VIES validation of $vatNumber to the invoice's billable and the invoice. */
    public static function withValidVatNumber(BillingInvoice $invoice, string $vatNumber, string $countryCode): BillingInvoice
    {
        $billable = $invoice->billable;
        $billable->forceFill(['vat_number' => $vatNumber])->save();

        $validation = $billable->vatValidations()->create([
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'valid' => true,
            'vies_response' => ['valid' => true],
            'checked_at' => now(),
        ]);

        $invoice->forceFill(['vat_validation_id' => $validation->getKey()])->save();

        return $invoice->refresh();
    }

    public static function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        return $xpath;
    }

    /**
     * The XML embedded in a hybrid PDF, and the name it is attached under; null when the
     * PDF carries no e-invoice.
     *
     * @return array{xml:string, filename:string}|null
     */
    public static function embedded(string $pdf): ?array
    {
        try {
            $attachment = InvoiceSuitePdfDocumentReader::createFromContent($pdf)->getInvoiceDocumentAttachment();
        } catch (\Throwable) {
            return null;
        }

        return $attachment === null ? null : [
            'xml' => $attachment->getAttachmentContent(),
            'filename' => $attachment->getAttachmentFilename(),
        ];
    }

    public static function value(DOMXPath $xpath, string $path): ?string
    {
        return $xpath->query($path)->item(0)?->textContent;
    }
}

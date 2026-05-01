<?php

declare(strict_types=1);

use Brick\Money\Money;
use Elegantly\Invoices\Pdf\PdfInvoice;
use Elegantly\Invoices\Pdf\PdfInvoiceItem;
use Elegantly\Invoices\Support\Address;
use Elegantly\Invoices\Support\Buyer;
use Elegantly\Invoices\Support\Seller;

it('renders a PDF with three line items at three different VAT rates', function (): void {
    $items = [
        new PdfInvoiceItem(
            label: 'Plan-Anteil (Enterprise)',
            unit_price: Money::ofMinor(3000, 'EUR'),
            tax_percentage: 20.0,
            quantity: 1,
        ),
        new PdfInvoiceItem(
            label: 'Print Gateway',
            unit_price: Money::ofMinor(900, 'EUR'),
            tax_percentage: 10.0,
            quantity: 1,
        ),
        new PdfInvoiceItem(
            label: 'B2B Reverse Charge Service',
            unit_price: Money::ofMinor(5000, 'EUR'),
            tax_percentage: 0.0,
            quantity: 1,
        ),
    ];

    $pdfInvoice = new PdfInvoice(
        serial_number: 'TEST-MULTIVAT-001',
        created_at: now(),
        seller: new Seller(
            company: 'Graystack IT',
            tax_number: 'ATU12345678',
            address: new Address(street: 'Beispielstraße 1', postal_code: '1010', city: 'Wien', country: 'AT'),
        ),
        buyer: new Buyer(
            company: 'Test Customer GmbH',
            address: new Address(street: 'Customer Lane 99', postal_code: '50667', city: 'Köln', country: 'DE'),
        ),
        items: $items,
    );

    expect($pdfInvoice->subTotalAmount()->getMinorAmount()->toInt())->toBe(8900);
    // 3000 × 20% = 600, 900 × 10% = 90, 5000 × 0% = 0  →  690
    expect($pdfInvoice->totalTaxAmount()->getMinorAmount()->toInt())->toBe(690);
    expect($pdfInvoice->totalAmount()->getMinorAmount()->toInt())->toBe(9590);

    $output = $pdfInvoice->getPdfOutput();
    expect($output)->not->toBeNull();
    expect(strlen($output))->toBeGreaterThan(2000);
});

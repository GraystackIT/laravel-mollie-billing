<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * What billing:check-config says about the seller data the structured e-invoice needs.
 * A gap never stops the PDF from being written, so this command is the only place it
 * surfaces before a customer's portal rejects the XML.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'Graystack Test GmbH', 'name' => 'Erika Muster', 'email' => 'billing@graystack.test',
        'phone' => '+43 1 234567', 'tax_number' => null, 'vat_id' => 'ATU12345678',
        'address' => ['street' => 'Teststraße 1', 'city' => 'Wien', 'postal_code' => '1010', 'country' => 'AT'],
    ]);
    config()->set('mollie-billing.invoices.e_invoice.enabled', true);
    config()->set('mollie-billing.invoices.e_invoice.profile', 'en16931');
});

it('is quiet about complete seller data', function (): void {
    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('invoices.seller.vat_id')
        ->doesntExpectOutputToContain('invoices.e_invoice')
        ->doesntExpectOutputToContain('invoices.seller.tax_number');
});

it('accepts a VAT ID kept in tax_number, as the key was long documented', function (): void {
    config()->set('mollie-billing.invoices.seller.vat_id', null);
    config()->set('mollie-billing.invoices.seller.tax_number', 'ATU12345678');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('invoices.seller.vat_id is empty');
});

it('warns when the e-invoice cannot state a seller VAT ID', function (): void {
    config()->set('mollie-billing.invoices.seller.vat_id', null);
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.seller.vat_id is empty');
});

it('warns when neither a tax number nor a VAT ID is configured', function (): void {
    config()->set('mollie-billing.invoices.seller.vat_id', null);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.seller.tax_number and invoices.seller.vat_id are both empty');
});

it('warns about a vat_id that is not shaped like one', function (): void {
    config()->set('mollie-billing.invoices.seller.vat_id', '12345678');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('does not look like a VAT ID');
});

it('warns about an unsupported profile', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.profile', 'basic');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.e_invoice.profile "basic" is not supported');
});

it('demands the seller contact XRechnung requires', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.profile', 'xrechnung');
    config()->set('mollie-billing.invoices.seller.phone', null);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.seller.phone is empty — XRechnung requires the seller contact');
});

it('says nothing about e-invoice data while e-invoicing is off', function (): void {
    config()->set('mollie-billing.invoices.e_invoice.enabled', false);
    config()->set('mollie-billing.invoices.seller.vat_id', null);
    config()->set('mollie-billing.invoices.seller.tax_number', '12/345/67890');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('invoices.seller.vat_id is empty');
});

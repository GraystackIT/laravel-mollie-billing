<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
});

/**
 * The seller country decides domestic VAT versus cross-border reverse charge. At
 * runtime a malformed value is refused and VAT is charged — recoverable, but every
 * B2B invoice until someone notices carries the wrong treatment. This command is
 * where the typo is supposed to stop you.
 */
it('errors on a seller country that is not an alpha-2 code', function (string $bad): void {
    config()->set('mollie-billing.vat.seller_country', $bad);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('vat.seller_country')
        ->assertExitCode(1);
})->with([
    'three letters' => ['AUT'],
    'country name' => ['Austria'],
    'one letter' => ['A'],
    'letter and digit' => ['A1'],
    'digits' => ['40'],
    'locale tag' => ['de-AT'],
    'two codes' => ['AT,DE'],
]);

it('accepts the alpha-2 shapes the resolver normalises', function (string $good): void {
    config()->set('mollie-billing.vat.seller_country', $good);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('vat.seller_country')
        ->assertExitCode(0);
})->with([
    'uppercase' => ['AT'],
    'lowercase' => ['de'],
    'mixed case' => ['aT'],
    'padded' => ['  DE  '],
]);

it('says nothing about an unset seller country', function (): void {
    // Unset is a different state with its own contract (require_seller_country), and
    // validateSeller() already warns about the empty address field.
    config()->set('mollie-billing.vat.seller_country', null);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('vat.seller_country')
        ->assertExitCode(0);
});

it('also errors on a malformed invoices.seller.address.country', function (): void {
    // The documented fallback source, so a typo there has the same consequence.
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.invoices.seller.address.country', 'Deutschland');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.seller.address.country')
        ->assertExitCode(1);
});

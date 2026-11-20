<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;

/*
 * Validation messages named the property instead of the field: "company name muss ausgefüllt
 * werden" under a field labelled "Name auf der Rechnung", "billing street …" under "Straße und
 * Hausnummer". They now use the labels the forms show — including an app's override of one.
 */

dataset('locales', ['de', 'en']);

it('names the checkout billing fields by their labels', function (string $locale): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    app()->setLocale($locale);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('company_name', '')
        ->set('billing_street', '')
        ->set('billing_postal_code', '')
        ->set('billing_city', '')
        ->call('next')
        ->assertHasErrors(['company_name', 'billing_street', 'billing_postal_code', 'billing_city']);

    $errors = $component->errors();

    foreach ([
        'company_name' => 'billing::checkout.company_name',
        'billing_street' => 'billing::checkout.street',
        'billing_postal_code' => 'billing::checkout.postal_code',
        'billing_city' => 'billing::checkout.city',
    ] as $field => $label) {
        expect($errors->first($field))
            ->toContain(__($label))
            ->not->toContain(str_replace('_', ' ', $field));
    }
})->with('locales');

it('follows an app override of a label into the message', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    app()->setLocale('de');
    app('translator')->addLines(['checkout.company_name' => 'Name auf der Rechnung'], 'de', 'billing');

    $component = Livewire::test('mollie-billing::checkout')
        ->set('company_name', '')
        ->call('next');

    expect($component->errors()->first('company_name'))->toContain('Name auf der Rechnung');
});

it('keeps "(optional)" out of the VAT number in a message', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    app()->setLocale('de');

    $component = Livewire::test('mollie-billing::checkout')
        ->set('vat_number', str_repeat('A', 60))
        ->call('next');

    expect($component->errors()->first('vat_number'))
        ->toContain(__('billing::checkout.vat_number_attribute'))
        ->not->toContain('(optional)')
        ->not->toContain('vat number');
});

it('names the billing-data fields by their labels', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    MollieBilling::resolveBillableUsing(fn () => $billable);
    MollieBilling::authUsing(fn (): bool => true);
    app()->setLocale('de');

    $component = Livewire::test('mollie-billing::billing-data')
        ->set('billing_street', '')
        ->call('save')
        ->assertHasErrors('billing_street');

    expect($component->errors()->first('billing_street'))
        ->toContain(__('billing::checkout.street'))
        ->not->toContain('billing street');
});

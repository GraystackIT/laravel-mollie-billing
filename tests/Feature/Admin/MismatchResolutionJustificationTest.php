<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * Resolving a country mismatch refunds and reissues every linked invoice at
 * another country's VAT rate — a hand-made tax decision. The screen recorded
 * that it happened but never why, so an audit could not tell a documented
 * correction from a guess.
 */
function justificationMismatch(string $chosenSignals = 'AT'): array
{
    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'mismatch-'.uniqid().'@example.com',
        'name' => 'Mismatch Co',
        'billing_country' => 'DE',
        'tax_country_user' => 'DE',
    ])->save();

    $mismatch = new BillingCountryMismatch;
    $mismatch->billable_type = $billable->getMorphClass();
    $mismatch->billable_id = $billable->getKey();
    $mismatch->status = CountryMismatchStatus::Pending;
    $mismatch->tax_country_user = 'DE';
    $mismatch->tax_country_payment = $chosenSignals;
    $mismatch->save();

    return [$billable->refresh(), $mismatch];
}

it('renders a justification field bound to the resolve modal', function (): void {
    [, $mismatch] = justificationMismatch();

    $html = Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->assertSee(__('billing::admin.mismatches.modal.justification_label'))
        ->assertSee(__('billing::admin.mismatches.modal.justification_description'))
        ->html();

    // Flux's provider is not booted in the package test env, so its components pass
    // through as literal tags — the binding is still visible, the rendered <textarea>
    // is not.
    expect($html)->toContain('wire:model="resolvingJustification"');
});

it('refuses to resolve a mismatch without a justification', function (): void {
    [$billable, $mismatch] = justificationMismatch();

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->set('resolvingChosen', 'AT')
        ->call('confirmResolve')
        ->assertSet('resolvingError', __('billing::admin.mismatches.modal.justification_required'))
        ->assertSet('flash', null);

    expect($mismatch->refresh()->status)->toBe(CountryMismatchStatus::Pending);
    expect($mismatch->chosen_country)->toBeNull();
    expect($billable->refresh()->tax_country_user)->toBe('DE');
});

it('treats a whitespace-only justification as missing', function (): void {
    [, $mismatch] = justificationMismatch();

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->set('resolvingChosen', 'AT')
        ->set('resolvingJustification', "   \n\t ")
        ->call('confirmResolve')
        ->assertSet('resolvingError', __('billing::admin.mismatches.modal.justification_required'));

    expect($mismatch->refresh()->status)->toBe(CountryMismatchStatus::Pending);
});

it('records the operator justification with the resolution', function (): void {
    [$billable, $mismatch] = justificationMismatch();

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->set('resolvingChosen', 'AT')
        ->set('resolvingJustification', '  Residence certificate for AT on file, ticket #1234  ')
        ->call('confirmResolve')
        ->assertSet('resolvingError', null)
        ->assertSet('flash', __('billing::admin.mismatches.modal.resolved'));

    expect($mismatch->refresh()->status)->toBe(CountryMismatchStatus::Resolved);
    expect($mismatch->chosen_country)->toBe('AT');

    $verified = $billable->refresh()->getBillingSubscriptionMeta()['tax_country_verified'] ?? [];

    expect($verified['country'] ?? null)->toBe('AT');
    expect($verified['justification'] ?? null)->toBe('Residence certificate for AT on file, ticket #1234');
});

it('accepts an off-signal country once the operator justifies it', function (): void {
    [$billable, $mismatch] = justificationMismatch('AT');

    // FR is neither the declared, the payment nor the IP country: without a reason
    // on record CountryMatchService refuses it outright.
    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->set('resolvingChosen', 'FR')
        ->set('resolvingJustification', 'Customer moved to FR, lease agreement verified')
        ->call('confirmResolve')
        ->assertSet('resolvingError', null);

    expect($mismatch->refresh()->chosen_country)->toBe('FR');
    expect($billable->refresh()->getBillingSubscriptionMeta()['tax_country_verified']['justification'] ?? null)
        ->toBe('Customer moved to FR, lease agreement verified');
});

it('rejects a justification longer than the persisted cap', function (): void {
    [, $mismatch] = justificationMismatch();

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->set('resolvingChosen', 'AT')
        ->set('resolvingJustification', str_repeat('x', 501))
        ->call('confirmResolve')
        ->assertSet('resolvingError', __('billing::admin.mismatches.modal.justification_too_long', ['max' => 500]));

    expect($mismatch->refresh()->status)->toBe(CountryMismatchStatus::Pending);
});

it('clears a typed justification when the modal is reopened for another mismatch', function (): void {
    [, $first] = justificationMismatch();
    [, $second] = justificationMismatch();

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $first->id)
        ->set('resolvingJustification', 'reason for the first case')
        ->call('openResolve', $second->id)
        ->assertSet('resolvingJustification', null);
});

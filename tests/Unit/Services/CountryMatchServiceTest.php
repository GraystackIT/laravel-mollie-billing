<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not flag when only tax_country_user is known (no payment yet)', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => null,
    ]);

    app(CountryMatchService::class)->check($billable);

    expect(BillingCountryMismatch::count())->toBe(0);
});

it('flags as ? when payment is set but user is missing (upstream data bug)', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => null,
        'tax_country_payment' => 'AT',
    ]);

    app(CountryMatchService::class)->check($billable);

    $rows = BillingCountryMismatch::all();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->tax_country_user)->toBe('?');
    expect($rows->first()->tax_country_payment)->toBe('AT');
});

it('does not flag when both countries match', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'AT',
    ]);

    app(CountryMatchService::class)->check($billable);

    expect(BillingCountryMismatch::count())->toBe(0);
});

it('flags a Pending row when user and payment differ', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
    ]);

    app(CountryMatchService::class)->check($billable);

    $rows = BillingCountryMismatch::all();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->status)->toBe(CountryMismatchStatus::Pending);
    expect($rows->first()->tax_country_user)->toBe('AT');
    expect($rows->first()->tax_country_payment)->toBe('DE');
});

it('is idempotent on repeated calls with the same state', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
    ]);

    app(CountryMatchService::class)->check($billable);
    app(CountryMatchService::class)->check($billable);
    app(CountryMatchService::class)->check($billable);

    expect(BillingCountryMismatch::count())->toBe(1);
});

it('flags a fresh row when the payment country changes', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
    ]);

    app(CountryMatchService::class)->check($billable);

    $billable->forceFill(['tax_country_payment' => 'FR'])->save();
    app(CountryMatchService::class)->check($billable);

    $rows = BillingCountryMismatch::orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->tax_country_payment)->toBe('DE');
    expect($rows[1]->tax_country_payment)->toBe('FR');
});

it('does not re-flag when a Resolved row exists with the same values', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 't@example.com',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
    ]);

    app(CountryMatchService::class)->check($billable);
    BillingCountryMismatch::query()->update(['status' => CountryMismatchStatus::Resolved]);

    app(CountryMatchService::class)->check($billable);

    expect(BillingCountryMismatch::count())->toBe(1);
});

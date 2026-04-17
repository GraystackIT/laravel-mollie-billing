<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;

afterEach(function (): void {
    // Reset the callback so it doesn't bleed into other tests.
    $ref = new ReflectionClass(MollieBilling::class);
    $prop = $ref->getProperty('urlParametersCallback');
    $prop->setValue(null, null);
});

it('returns empty array when no callback is registered', function (): void {
    expect(MollieBilling::resolveUrlParameters())->toBe([]);
});

it('returns empty array with a billable when no callback is registered', function (): void {
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    expect(MollieBilling::resolveUrlParameters($billable))->toBe([]);
});

it('registers and invokes urlParametersUsing callback with billable', function (): void {
    $receivedBillable = null;

    MollieBilling::urlParametersUsing(function (?Billable $billable) use (&$receivedBillable): array {
        $receivedBillable = $billable;

        return $billable ? ['org' => $billable->getKey()] : [];
    });

    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $params = MollieBilling::resolveUrlParameters($billable);

    expect($params)->toBe(['org' => $billable->getKey()]);
    expect($receivedBillable)->toBe($billable);
});

it('invokes urlParametersUsing callback with null billable', function (): void {
    MollieBilling::urlParametersUsing(fn (?Billable $billable): array => $billable ? ['org' => $billable->getKey()] : ['fallback' => true]);

    $params = MollieBilling::resolveUrlParameters(null);

    expect($params)->toBe(['fallback' => true]);
});

it('uses resolver in HasBilling::billingPortalUrl', function (): void {
    MollieBilling::urlParametersUsing(fn (?Billable $billable): array => []);

    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $url = $billable->billingPortalUrl();

    expect($url)->toContain('/billing');
});

it('allows model to override urlRouteParameters over the resolver', function (): void {
    // Register a resolver that provides params
    MollieBilling::urlParametersUsing(fn (?Billable $billable): array => ['should' => 'not-be-used']);

    // Create an anonymous subclass that overrides urlRouteParameters
    $billable = new class extends TestBillable
    {
        protected $table = 'test_billables';

        protected function urlRouteParameters(): array
        {
            return [];
        }
    };

    $billable->forceFill(['name' => 'Override', 'email' => 'o@example.test'])->save();

    // The override returns [] so the URL should work without the resolver params
    $url = $billable->billingPortalUrl();

    expect($url)->toContain('/billing');
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;

it('registers and invokes createBillableUsing callback', function (): void {
    MollieBilling::createBillableUsing(function (array $data): Billable {
        return TestBillable::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? 'test@example.test',
        ]);
    });

    $billable = MollieBilling::createBillable(['name' => 'Checkout Corp', 'email' => 'co@example.test']);

    expect($billable)->toBeInstanceOf(Billable::class);
    expect($billable->getBillingName())->toBe('Checkout Corp');
});

it('throws when createBillableUsing is not registered', function (): void {
    // Reset the callback by using reflection (it's a static property)
    $ref = new ReflectionClass(MollieBilling::class);
    $prop = $ref->getProperty('createBillableCallback');
    $prop->setValue(null, null);

    MollieBilling::createBillable(['name' => 'Fail']);
})->throws(RuntimeException::class, 'No createBillable callback registered');

it('registers and invokes beforeCheckoutUsing callback', function (): void {
    MollieBilling::beforeCheckoutUsing(fn (Billable $b): ?string => 'blocked: test');

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $result = MollieBilling::runBeforeCheckout($billable);

    expect($result)->toBe('blocked: test');
});

it('returns null from beforeCheckout when no callback registered', function (): void {
    $ref = new ReflectionClass(MollieBilling::class);
    $prop = $ref->getProperty('beforeCheckoutCallback');
    $prop->setValue(null, null);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    expect(MollieBilling::runBeforeCheckout($billable))->toBeNull();
});

it('registers and invokes afterCheckoutUsing callback', function (): void {
    $called = false;
    $receivedSuccess = null;

    MollieBilling::afterCheckoutUsing(function (Billable $b, bool $success) use (&$called, &$receivedSuccess): void {
        $called = true;
        $receivedSuccess = $success;
    });

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    MollieBilling::runAfterCheckout($billable, true);

    expect($called)->toBeTrue();
    expect($receivedSuccess)->toBeTrue();
});

it('generates a checkout URL', function (): void {
    $url = MollieBilling::checkoutUrl();

    expect($url)->toContain('/billing/checkout');
});

it('generates a checkout URL with back parameter', function (): void {
    $url = MollieBilling::checkoutUrl('/pricing');

    expect($url)->toContain('/billing/checkout');
    expect($url)->toContain('back=');
});

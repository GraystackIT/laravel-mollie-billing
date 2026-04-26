<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\UpdateCustomerRequest;
use Mollie\Laravel\Facades\Mollie;

it('sync() pushes name and email to Mollie when a customer exists', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme GmbH',
        'email' => 'acme@example.test',
        'mollie_customer_id' => 'cst_existing',
    ]);

    $captured = null;

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$captured): bool {
            $captured = $request;
            return $request instanceof UpdateCustomerRequest;
        })
        ->andReturn(new stdClass);

    app(MollieCustomerResolver::class)->sync($billable);

    expect($captured)->toBeInstanceOf(UpdateCustomerRequest::class);

    $reflection = new ReflectionObject($captured);
    $idProp = $reflection->getProperty('id');
    $idProp->setAccessible(true);
    $nameProp = $reflection->getProperty('name');
    $nameProp->setAccessible(true);
    $emailProp = $reflection->getProperty('email');
    $emailProp->setAccessible(true);

    expect($idProp->getValue($captured))->toBe('cst_existing');
    expect($nameProp->getValue($captured))->toBe('Acme GmbH');
    expect($emailProp->getValue($captured))->toBe('acme@example.test');
});

it('sync() is a no-op when no Mollie customer exists', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'New Co',
        'email' => 'new@example.test',
    ]);

    Mollie::shouldReceive('send')->never();

    app(MollieCustomerResolver::class)->sync($billable);
});

it('resolve() still creates a customer when none exists', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Fresh Co',
        'email' => 'fresh@example.test',
    ]);

    $fakeCustomer = new class {
        public string $id = 'cst_fresh';
    };

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(fn ($request) => $request instanceof CreateCustomerRequest)
        ->andReturn($fakeCustomer);

    $id = app(MollieCustomerResolver::class)->resolve($billable);

    expect($id)->toBe('cst_fresh');
    expect($billable->fresh()->mollie_customer_id)->toBe('cst_fresh');
});

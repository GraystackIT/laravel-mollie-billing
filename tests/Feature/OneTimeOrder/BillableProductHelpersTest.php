<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
        'consulting' => [
            'name' => 'Consulting',
            'price_net' => 14900,
            'onetimeonly' => true,
        ],
        'setup-fee' => [
            'name' => 'Setup Fee',
            'price_net' => 9900,
            'onetimeonly' => true,
        ],
    ]);
});

it('returns all configured product codes', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    expect($billable->allBillingProducts())->toBe(['token-pack', 'consulting', 'setup-fee']);
});

it('returns empty bought products when none purchased', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    expect($billable->boughtBillingProducts())->toBe([]);
});

it('returns bought product codes from paid invoices', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_bought_1',
        'serial_number' => 'OT-26000001',
        'invoice_kind' => InvoiceKind::OneTimeOrder,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'vat_rate' => 19.00,
        'amount_net' => 14900,
        'amount_vat' => 2831,
        'amount_gross' => 17731,
        'line_items' => [['kind' => 'one_time_order', 'code' => 'consulting', 'label' => 'Consulting', 'quantity' => 1, 'unit_price' => 14900, 'unit_price_net' => 14900, 'total_net' => 14900]],
    ]);

    expect($billable->boughtBillingProducts())->toBe(['consulting']);
});

it('returns all products as available when nothing bought', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    expect($billable->availableBillingProducts())->toBe(['token-pack', 'consulting', 'setup-fee']);
});

it('excludes onetimeonly products already purchased from available', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_bought_2',
        'serial_number' => 'OT-26000002',
        'invoice_kind' => InvoiceKind::OneTimeOrder,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'vat_rate' => 19.00,
        'amount_net' => 14900,
        'amount_vat' => 2831,
        'amount_gross' => 17731,
        'line_items' => [['kind' => 'one_time_order', 'code' => 'consulting', 'label' => 'Consulting', 'quantity' => 1, 'unit_price' => 14900, 'unit_price_net' => 14900, 'total_net' => 14900]],
    ]);

    $available = $billable->availableBillingProducts();

    expect($available)->toContain('token-pack');
    expect($available)->toContain('setup-fee');
    expect($available)->not->toContain('consulting');
});

it('keeps non-onetimeonly products available even when bought', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_bought_3',
        'serial_number' => 'OT-26000003',
        'invoice_kind' => InvoiceKind::OneTimeOrder,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'vat_rate' => 19.00,
        'amount_net' => 4900,
        'amount_vat' => 931,
        'amount_gross' => 5831,
        'line_items' => [['kind' => 'one_time_order', 'code' => 'token-pack', 'label' => '500 Token Pack', 'quantity' => 1, 'unit_price' => 4900, 'unit_price_net' => 4900, 'total_net' => 4900]],
    ]);

    expect($billable->availableBillingProducts())->toContain('token-pack');
});

it('ignores non-paid invoices for bought products', function (): void {
    $billable = TestBillable::create(['name' => 'Test', 'email' => 'test@example.test']);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_failed_1',
        'serial_number' => 'OT-26000004',
        'invoice_kind' => InvoiceKind::OneTimeOrder,
        'status' => InvoiceStatus::Failed,
        'country' => 'DE',
        'currency' => 'EUR',
        'vat_rate' => 19.00,
        'amount_net' => 14900,
        'amount_vat' => 2831,
        'amount_gross' => 17731,
        'line_items' => [['kind' => 'one_time_order', 'code' => 'consulting', 'label' => 'Consulting', 'quantity' => 1, 'unit_price' => 14900, 'unit_price_net' => 14900, 'total_net' => 14900]],
    ]);

    expect($billable->boughtBillingProducts())->toBe([]);
    expect($billable->availableBillingProducts())->toContain('consulting');
});

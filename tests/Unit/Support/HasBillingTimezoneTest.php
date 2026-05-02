<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

it('default getBillingTimezone reads mollie-billing.billing_timezone config', function (): void {
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');

    $billable = new TestBillable();

    expect($billable->getBillingTimezone())->toBe('Europe/Vienna');
});

it('default getBillingTimezone falls back to UTC when config is missing', function (): void {
    config()->set('mollie-billing.billing_timezone', null);

    $billable = new TestBillable();

    expect($billable->getBillingTimezone())->toBe('UTC');
});

it('consuming app can override getBillingTimezone on the model', function (): void {
    config()->set('mollie-billing.billing_timezone', 'UTC');

    $billable = new class extends TestBillable {
        public ?string $preferred_timezone = 'America/Los_Angeles';

        public function getBillingTimezone(): string
        {
            return $this->preferred_timezone ?? parent::getBillingTimezone();
        }
    };

    expect($billable->getBillingTimezone())->toBe('America/Los_Angeles');
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\SubscriptionPaymentHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.standard', [
        'name' => 'Standard',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('flips PastDue → Active and clears payment_failure/past_due_since on a successful recurring charge', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Past-Due Customer',
        'email' => 'pastdue@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_pd',
        'mollie_mandate_id' => 'mdt_pd',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'standard',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(35),
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_pd_recovery',
            'past_due_since' => BillingTime::nowUtc()->subDays(5)->toIso8601String(),
            'payment_failure' => [
                'payment_id' => 'tr_failed_earlier',
                'failed_at' => BillingTime::nowUtc()->subDays(5)->toIso8601String(),
                'reason' => 'insufficient_funds',
            ],
        ],
    ])->save();
    $billable->refresh();

    // Stub-Invoice so the handler does not actually invoke the local PDF/invoice pipeline.
    $stubInvoice = new BillingInvoice;
    $stubInvoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-PD-001',
        'mollie_payment_id' => 'tr_stub_recovery',
        'invoice_kind' => InvoiceKind::Subscription->value,
        'status' => InvoiceStatus::Paid->value,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3900,
        'amount_vat' => 780,
        'amount_gross' => 4680,
        'line_items' => [],
    ])->save();

    $invoiceService = Mockery::mock(InvoiceService::class);
    $invoiceService->shouldReceive('createForPayment')->andReturn($stubInvoice);
    $this->app->instance(InvoiceService::class, $invoiceService);

    $payment = (object) [
        'id' => 'tr_recovery_charge',
        'status' => 'paid',
        'amount' => (object) ['value' => '46.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_pd_recovery',
        'customerId' => 'cst_pd',
        'mandateId' => 'mdt_pd',
        'metadata' => null,
    ];

    app(SubscriptionPaymentHandler::class)->paid($payment, $billable, []);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);

    $meta = $billable->getBillingSubscriptionMeta();
    expect($meta)->not->toHaveKey('past_due_since')
        ->and($meta)->not->toHaveKey('payment_failure')
        ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_pd_recovery');
});

it('leaves an Active subscription untouched and does not regress meta on a regular recurring charge', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Healthy Customer',
        'email' => 'ok@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_ok',
        'mollie_mandate_id' => 'mdt_ok',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'standard',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(30),
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_ok',
        ],
    ])->save();
    $billable->refresh();

    $stubInvoice = new BillingInvoice;
    $stubInvoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-OK-001',
        'mollie_payment_id' => 'tr_stub_ok',
        'invoice_kind' => InvoiceKind::Subscription->value,
        'status' => InvoiceStatus::Paid->value,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3900,
        'amount_vat' => 780,
        'amount_gross' => 4680,
        'line_items' => [],
    ])->save();

    $invoiceService = Mockery::mock(InvoiceService::class);
    $invoiceService->shouldReceive('createForPayment')->andReturn($stubInvoice);
    $this->app->instance(InvoiceService::class, $invoiceService);

    $payment = (object) [
        'id' => 'tr_ok',
        'status' => 'paid',
        'amount' => (object) ['value' => '46.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_ok',
        'customerId' => 'cst_ok',
        'mandateId' => 'mdt_ok',
        'metadata' => null,
    ];

    app(SubscriptionPaymentHandler::class)->paid($payment, $billable, []);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since');
});

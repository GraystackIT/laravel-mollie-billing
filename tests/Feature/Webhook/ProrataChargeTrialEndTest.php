<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.standard', [
        'name' => 'Standard',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'yearly' => ['base_price_net' => 42000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('ends the trial when a prorata charge is paid for a Trial billable (matches portal plan-change during trial)', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Mustermann GmbH',
        'email' => 'mustermann@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_t',
        'mollie_mandate_id' => 'mdt_t',
    ]);

    // Setup: billable is mid-trial on Starter (matches the portal screenshot
    // state before "Plan wechseln" was clicked).
    $trialEnds = BillingTime::nowUtc()->addDays(7);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => $trialEnds,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDay(),
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_old',
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_prorata_123',
                'charge_lines' => [[
                    'kind' => 'plan',
                    'code' => 'standard',
                    'label' => 'Standard yearly',
                    'quantity' => 1,
                    'amount_net' => 42000,
                    'vat_rate' => 20.0,
                    'amount_vat' => 8400,
                    'amount_gross' => 50400,
                    'period_start' => BillingTime::nowUtc()->toIso8601String(),
                    'period_end' => BillingTime::nowUtc()->addYear()->toIso8601String(),
                    'days_active' => 0,
                    'days_remaining' => 365,
                ]],
                'intent' => [
                    'current_plan' => 'starter',
                    'current_interval' => 'monthly',
                    'new_plan' => 'standard',
                    'new_interval' => 'yearly',
                    'current_seats' => 1,
                    'new_seats' => 1,
                    'current_addons' => [],
                    'new_addons' => [],
                ],
            ],
        ],
    ])->save();
    $billable->refresh();

    // Stub the three handler dependencies.
    // Persist a stand-in invoice so the handler has a real model to attach
    // events to. We use an unrelated payment id so the idempotency guard
    // (invoiceAlreadyExistsForPayment) does not short-circuit the handler.
    $fakeInvoice = new BillingInvoice;
    $fakeInvoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-TEST-001',
        'mollie_payment_id' => 'tr_stand_in',
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription->value,
        'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid->value,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 42000,
        'amount_vat' => 8400,
        'amount_gross' => 50400,
        'line_items' => [],
    ])->save();

    $invoiceService = Mockery::mock(InvoiceService::class);
    $invoiceService->shouldReceive('createInvoice')->andReturn($fakeInvoice);

    $patcher = Mockery::mock(MollieSubscriptionPatcher::class);
    $patcher->shouldReceive('updateForIntent')->andReturnNull();

    $walletAdjuster = Mockery::mock(WalletPlanChangeAdjuster::class);
    $walletAdjuster->shouldReceive('adjust')->andReturnNull();

    $this->app->instance(InvoiceService::class, $invoiceService);
    $this->app->instance(MollieSubscriptionPatcher::class, $patcher);
    $this->app->instance(WalletPlanChangeAdjuster::class, $walletAdjuster);

    $payment = (object) [
        'id' => 'tr_prorata_123',
        'status' => 'paid',
        'amount' => (object) ['value' => '504.00', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'metadata' => null,
    ];

    app(ProrataChargeHandler::class)->paid($payment, $billable, []);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->trial_ends_at)->toBeNull();
    expect($billable->isOnBillingTrial())->toBeFalse();
    expect($billable->subscription_plan_code)->toBe('standard');
    expect($billable->subscription_interval->value)->toBe('yearly');
});

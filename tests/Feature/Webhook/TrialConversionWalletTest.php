<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * A trial that ends by being charged for a change — a seat, an addon, another plan —
 * has paid for a full period, and its wallets have to hold that period's quota.
 *
 * They did not. The trial's own wallet is credited only the prorated share of the plan
 * quota, and Phase 2 either ran the plan-change adjuster (whose baseline is a period
 * that was paid for and partly consumed — a trial's is neither) or, when only the seat
 * count moved, ran nothing at all. The customer paid for a full month and kept the
 * trial's fraction of the quota for it.
 */
beforeEach(function (): void {
    // Explicit, because the shipped example config makes `Tokens` a rollover type and
    // the two semantics differ here: a rollover type adds the new period's quota on top
    // of what is left, the rest are reset to it. Both are covered below.
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => false],
        'SMS' => ['rollover' => false],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 3900,
                'seat_price_net' => 900,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 20, 'SMS' => 25],
                'usage_overage_prices' => ['Tokens' => 12, 'SMS' => 12],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 9900,
                'seat_price_net' => 900,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 100, 'SMS' => 200],
                'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 10],
            ],
        ],
    ]);
});

/**
 * A billable mid-trial on Pro, with the wallets a 14-day trial actually receives:
 * ceil(20 × 14/30) = 10 Tokens, ceil(25 × 14/30) = 12 SMS.
 *
 * @param  array<string, mixed>  $intentOverrides
 */
function trialConversionBillable(array $intentOverrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Convert GmbH',
        'email' => 'convert@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_t',
        'mollie_mandate_id' => 'mdt_t',
    ]);

    $intent = array_merge([
        'billable_type' => TestBillable::class,
        'billable_id' => $billable->getKey(),
        'current_plan' => 'pro',
        'current_interval' => 'monthly',
        'new_plan' => 'pro',
        'new_interval' => 'monthly',
        'current_seats' => 1,
        'new_seats' => 3,
        'current_addons' => [],
        'new_addons' => [],
    ], $intentOverrides);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(13),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDay(),
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_old',
            'trial_quota' => TrialQuota::marker('pro', 'monthly', 14, ['Tokens' => 10, 'SMS' => 12]),
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_convert',
                'charge_lines' => [[
                    'kind' => 'plan',
                    'code' => (string) $intent['new_plan'],
                    'label' => 'Charge',
                    'quantity' => 1,
                    'amount_net' => 1000,
                    'vat_rate' => 20.0,
                    'amount_vat' => 200,
                    'amount_gross' => 1200,
                ]],
                'intent' => $intent,
            ],
        ],
    ])->save();

    $wallet = app(WalletUsageService::class);
    $wallet->credit($billable, 'Tokens', 10, 'subscription_trial_start');
    $wallet->credit($billable, 'SMS', 12, 'subscription_trial_start');

    return $billable->refresh();
}

function payTrialConversion(TestBillable $billable): void
{
    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-CONV-001',
        'mollie_payment_id' => 'tr_stand_in_conv',
        'invoice_kind' => InvoiceKind::Subscription->value,
        'status' => InvoiceStatus::Paid->value,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [],
    ])->save();

    $invoiceService = Mockery::mock(InvoiceService::class);
    $invoiceService->shouldReceive('createInvoice')->andReturn($invoice);
    $invoiceService->shouldReceive('createRefund')->andReturnNull();

    $patcher = Mockery::mock(MollieSubscriptionPatcher::class);
    $patcher->shouldReceive('updateForIntent')->andReturnTrue();

    app()->instance(InvoiceService::class, $invoiceService);
    app()->instance(MollieSubscriptionPatcher::class, $patcher);

    app(ProrataChargeHandler::class)->paid((object) [
        'id' => 'tr_convert',
        'status' => 'paid',
        'amount' => (object) ['value' => '12.00', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'metadata' => null,
    ], $billable, []);

    $billable->refresh();
}

it('credits the full period quota when a seat change converts the trial', function (): void {
    $billable = trialConversionBillable();

    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(10);

    payTrialConversion($billable);

    // The customer has just paid for a full period on Pro.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(20);
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(25);

    expect($billable->grantedBillingQuota('Tokens'))->toBe(20);
    expect($billable->usedBillingQuota('Tokens'))->toBe(0);
});

it('credits the new plan in full when a plan change converts the trial', function (): void {
    $billable = trialConversionBillable(['new_plan' => 'enterprise']);

    payTrialConversion($billable);

    expect($billable->subscription_plan_code)->toBe('enterprise');
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(100);
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(200);
});

it('does not bill a converting trial for quota it was never granted', function (): void {
    // Downgrade-shaped conversion: the old plan lists 20 Tokens, the trial holds the 10
    // it was granted and consumed none. Measured against the old plan's full quota, 10
    // units read as consumed and — the trial period having barely elapsed — as excess,
    // which is deducted from the new quota and billed on top of it.
    $billable = trialConversionBillable();

    payTrialConversion($billable);

    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(20);
    expect($billable->getBillingSubscriptionMeta()['usage_overage'] ?? null)->toBeNull();
});

it('clears the trial grant record once the trial has been paid for', function (): void {
    $billable = trialConversionBillable();

    payTrialConversion($billable);

    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('trial_quota');
});

it('keeps real trial consumption out of the new period', function (): void {
    $billable = trialConversionBillable();

    // 4 of the 10 granted Tokens actually used during the trial.
    app(WalletUsageService::class)->debit($billable, 'Tokens', 4);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(6);

    payTrialConversion($billable);

    // The new period is paid for in full: what the trial consumed does not follow it,
    // exactly as an ordinary renewal resets the wallet.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(20);
});

it('carries a rollover type over the conversion instead of resetting it', function (): void {
    config()->set('mollie-billing-plans.usage_types.Tokens.rollover', true);

    $billable = trialConversionBillable();

    payTrialConversion($billable);

    // A rollover type never zeroes: the 10 unused trial units survive and the paid
    // period's 20 land on top. The same rule an ordinary renewal follows.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(30);
    expect((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(25);
});

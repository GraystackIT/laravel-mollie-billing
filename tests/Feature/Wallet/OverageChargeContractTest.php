<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\UsageOverageChargeOutcome;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1200],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
        ],
    ]);
});

function overageBillable(int $balance = 0): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_test',
        'mollie_customer_id' => 'cst_test',
        'allows_billing_overage' => true,
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    if ($balance < 0) {
        $wallet->forceWithdraw(abs($balance));
    } elseif ($balance > 0) {
        $wallet->deposit($balance);
    }

    return $b->refresh();
}

it('reports nothing-to-charge distinctly from a created payment', function (): void {
    Mollie::shouldReceive('send')->never();

    $billable = overageBillable(50);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::NothingToCharge);
    expect($result->wasCharged())->toBeFalse();
    expect($result->paymentId)->toBeNull();
});

it('reports a created payment with its id and amounts', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_1', 'status' => 'open']);

    $billable = overageBillable(-20);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged);
    expect($result->wasCharged())->toBeTrue();
    expect($result->paymentId)->toBe('tr_ov_1');
    expect($result->amountNet)->toBe(100);   // 20 × 5 cents
    expect($result->amountGross)->toBe(120); // + 20% AT VAT
});

it('persists the pending charge with its line items and the status marker', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_2', 'status' => 'open']);

    $billable = overageBillable(-20);

    app(ChargeUsageOverageDirectly::class)->handle($billable);

    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    expect($meta['usage_overage']['payment_id'])->toBe('tr_ov_2');
    expect($meta['usage_overage']['line_items'][0]['quantity'])->toBe(20);
    // Without the marker the daily Pass 2 sweep can never pick the charge up.
    expect($meta['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('sets the status marker for an explicit plan-change charge too', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_3', 'status' => 'open']);

    $billable = overageBillable(0);

    $result = app(ChargeUsageOverageDirectly::class)->handleExplicit($billable, [[
        'kind' => 'overage',
        'code' => 'Tokens',
        'type' => 'Tokens',
        'label' => 'Overage',
        'quantity' => 390,
        'unit_price_net' => 5,
        'total_net' => 1950,
    ]]);

    expect($result->wasCharged())->toBeTrue();
    expect($billable->refresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('rebuilds a retry from the persisted snapshot, not from the wallets', function (): void {
    // This is the plan-change failure the retry path used to lose: the wallets
    // were zeroed before charging, so rebuilding from them yields nothing.
    Mollie::shouldReceive('send')->twice()->andReturn(
        (object) ['id' => 'tr_ov_4', 'status' => 'open'],
        (object) ['id' => 'tr_ov_5', 'status' => 'open'],
    );

    $billable = overageBillable(0);

    app(ChargeUsageOverageDirectly::class)->handleExplicit($billable, [[
        'kind' => 'overage',
        'code' => 'Tokens',
        'type' => 'Tokens',
        'label' => 'Overage',
        'quantity' => 390,
        'unit_price_net' => 5,
        'total_net' => 1950,
    ]]);

    // The declined payment never produced an invoice → the retry must re-charge.
    $result = app(ChargeUsageOverageDirectly::class)->retry($billable->refresh());

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged);
    expect($result->paymentId)->toBe('tr_ov_5');
    expect($result->lineItems[0]['quantity'])->toBe(390);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(0);
});

it('never re-charges a pending payment that already produced an invoice', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_6', 'status' => 'open']);

    $billable = overageBillable(-20);
    app(ChargeUsageOverageDirectly::class)->handle($billable);

    // The webhook created the invoice but died before clearing the snapshot.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_ov_6',
        'serial_number' => 'IN-'.uniqid(),
        'invoice_kind' => InvoiceKind::Overage,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 100,
        'amount_vat' => 20,
        'amount_gross' => 120,
        'line_items' => [],
    ]);

    $service = app(ChargeUsageOverageDirectly::class);

    expect($service->pendingChargeIsSettled($billable->refresh()))->toBeTrue();

    $result = $service->retry($billable->refresh());

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::AlreadySettled);
    expect($result->paymentId)->toBe('tr_ov_6');
});

it('exposes the pending charge so callers can inspect the payment id', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_7', 'status' => 'open']);

    $service = app(ChargeUsageOverageDirectly::class);
    $billable = overageBillable(-10);

    expect($service->pendingCharge($billable))->toBeNull();

    $service->handle($billable);

    $pending = $service->pendingCharge($billable->refresh());

    expect($pending)->not->toBeNull();
    expect($pending['payment_id'])->toBe('tr_ov_7');
    expect($pending['amount_net'])->toBe(50);
});

it('falls back to the wallets when no snapshot exists', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ov_8', 'status' => 'open']);

    $billable = overageBillable(-8);

    $result = app(ChargeUsageOverageDirectly::class)->retry($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged);
    expect($result->lineItems[0]['quantity'])->toBe(8);
});

it('reports nothing to charge on retry when neither snapshot nor overage exists', function (): void {
    Mollie::shouldReceive('send')->never();

    $billable = overageBillable(30);

    expect(app(ChargeUsageOverageDirectly::class)->retry($billable)->outcome)
        ->toBe(UsageOverageChargeOutcome::NothingToCharge);
});

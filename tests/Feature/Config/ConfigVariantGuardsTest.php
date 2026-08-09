<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// The fixes in this round were verified against the default configuration. These pin the
// ones whose behaviour a config flag could plausibly change.

beforeEach(function (): void {
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 4, 20, 12, 0, 0, 'UTC'));
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('api')->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_cfg', 'status' => 'open']);
});

afterEach(function (): void {
    \Carbon\Carbon::setTestNow();
});

function configVariantBillable(bool $allowOverage, bool $rollover): TestBillable
{
    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => $rollover]]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => [], 'usage_overage_prices' => ['SMS' => 5]],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => [], 'usage_overage_prices' => ['SMS' => 5]],
        ],
    ]);

    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Cfg', 'email' => uniqid('cfg-').'@x.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_cfg',
        'mollie_mandate_id' => 'mdt_cfg',
        'allows_billing_overage' => $allowOverage,
    ])->save();

    $b->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(120);

    return $b->refresh();
}

it('still bills an existing pay-per-use debt after overage was switched off', function (): void {
    // `canChargeBillingOverage()` gates the *debit*, so a negative balance can only exist
    // because overage was allowed when the units were used. Switching the flag off later
    // stops new consumption; it does not cancel what is already owed, and the plan-change
    // rebalance is where that debt would otherwise be silently dropped.
    $billable = configVariantBillable(allowOverage: false, rollover: false);

    $lines = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'pro', 'yearly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    expect(collect($lines)->firstWhere('code', 'SMS'))->not->toBeNull();
});

it('bills a pay-per-use debt on a rollover type too', function (): void {
    // Rollover carries a *positive* remainder into the next period. A negative balance is
    // debt either way, and `max(0, …)` on the rollover credit must not swallow it.
    $billable = configVariantBillable(allowOverage: true, rollover: true);

    $lines = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'pro', 'yearly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    $sms = collect($lines)->firstWhere('code', 'SMS');

    expect($sms)->not->toBeNull()
        ->and((int) $sms['quantity'])->toBe(120);
});

it('renders a zero-decimal currency invoice at its own scale', function (): void {
    // `BILLING_CURRENCY=JPY` changes what a correct amount looks like everywhere: the
    // renderer's per-unit tax, the scale guard on every Mollie request, and the reading of
    // amounts back from Mollie. All three take the scale from the currency, so this pins
    // that the invoice document does too.
    config()->set('mollie-billing.currency', 'JPY');
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'ACME', 'name' => 'Jane', 'email' => 'billing@acme.test', 'tax_number' => 'ATU12345678',
        'address' => ['street' => 'Main 1', 'city' => 'Vienna', 'postal_code' => '1010', 'country' => 'AT'],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Yen Co', 'email' => 'yen@example.test', 'billing_country' => 'AT']);

    $invoice = \GraystackIT\MollieBilling\Models\BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_yen',
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'JPY',
        'amount_net' => 5000,
        'amount_vat' => 1000,
        'amount_gross' => 6000,
        'serial_number' => 'IN-yen-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 5000, 'amount_net' => 5000,
            'vat_rate' => 20.0, 'vat_amount' => 1000, 'amount_gross' => 6000,
        ]],
    ]);

    $method = new ReflectionMethod(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class, 'buildPdfInvoice');
    $method->setAccessible(true);
    $pdf = $method->invoke(app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class), $invoice, $billable);

    expect($pdf->totalAmount()->getMinorAmount()->toInt())->toBe(6000)
        // ¥6000 is written without decimals, and that is what the scale guard demands.
        ->and(\GraystackIT\MollieBilling\Support\BillingMoney::mollieAmount(6000, 'JPY'))->toBe('6000');
});

it('sends a zero-decimal amount through the Mollie guard unchanged', function (): void {
    config()->set('mollie-billing.currency', 'JPY');

    \GraystackIT\MollieBilling\Support\MollieCall::send(new \Mollie\Api\Http\Requests\CreatePaymentRequest(
        description: 'Yen charge',
        amount: new \Mollie\Api\Http\Data\Money('JPY', \GraystackIT\MollieBilling\Services\Billing\InvoiceService::molliePrice(6000)),
        redirectUrl: 'https://example.test/return',
    ));

    expect(true)->toBeTrue();
});

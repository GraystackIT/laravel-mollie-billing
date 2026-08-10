<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\UsageOverageChargeOutcome;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// A metered wallet stays negative until the charge that covers it settles — with SEPA
// that is days. Every path that rebuilds line items from the wallets therefore has to
// ask whether a payment is already in flight, or the same debt goes to Mollie twice.

beforeEach(function (): void {
    // The overage charge carries a stable idempotency key, so a retry cannot buy a second
    // payment for the same debt — see MollieCall::send() and ChargeUsageOverageDirectly.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();

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
        ],
    ]);
});

function inFlightBillable(int $balance = -40): TestBillable
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

    $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens'])->forceWithdraw(abs($balance));

    return $b->refresh();
}

function markOverageInFlight(TestBillable $billable, string $paymentId = 'tr_in_flight'): void
{
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY] = [
        'payment_id' => $paymentId,
        'line_items' => [[
            'kind' => 'overage',
            'code' => 'Tokens',
            'type' => 'Tokens',
            'label' => 'Overage: Tokens',
            'quantity' => 40,
            'unit_price_net' => 5,
            'total_net' => 200,
        ]],
        'total_net' => 200,
        'created_at' => now()->toIso8601String(),
    ];
    $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;

    $billable->forceFill(['subscription_meta' => $meta])->save();
    $billable->refresh();
}

it('refuses a second overage charge while one is still settling', function (): void {
    Mollie::shouldReceive('send')->never();

    $billable = inFlightBillable();
    markOverageInFlight($billable);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::NothingToCharge);

    // The snapshot must still point at the payment that is actually live at Mollie —
    // overwriting it is what made the first charge unreconcilable.
    expect($billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY]['payment_id'])
        ->toBe('tr_in_flight');
});

it('does not double-charge the overage when the customer cancels immediately', function (): void {
    Mollie::shouldReceive('send')->never();

    $billable = inFlightBillable();
    markOverageInFlight($billable);

    app(CancelSubscription::class)->handle($billable, immediately: true);

    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY]['payment_id'])
        ->toBe('tr_in_flight');
});

it('charges again once the in-flight payment has produced its invoice', function (): void {
    // The guard releases on settlement, not on a timer: an invoice for the pending
    // payment id means the money arrived, so the wallet debt that is left is new.
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_second', 'status' => 'open']);

    $billable = inFlightBillable();
    markOverageInFlight($billable);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_in_flight',
        'invoice_kind' => InvoiceKind::Overage,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 200,
        'amount_vat' => 40,
        'amount_gross' => 240,
        'line_items' => [],
    ]);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged)
        ->and($result->paymentId)->toBe('tr_second');
});

it('still lets the retry path re-charge the snapshot it owns', function (): void {
    // The retry is driven by the very snapshot the guard keys off, so applying the
    // guard there would make every retry a permanent no-op.
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_retry', 'status' => 'open']);

    $billable = inFlightBillable();
    markOverageInFlight($billable);

    $result = app(ChargeUsageOverageDirectly::class)->retry($billable);

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged)
        ->and($result->paymentId)->toBe('tr_retry');
});

it('lets the retry path fall back to the wallets when the snapshot carries no lines', function (): void {
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_retry_wallets', 'status' => 'open']);

    $billable = inFlightBillable();

    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY] = ['payment_id' => 'tr_lost'];
    $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $result = app(ChargeUsageOverageDirectly::class)->retry($billable->refresh());

    expect($result->outcome)->toBe(UsageOverageChargeOutcome::Charged)
        ->and($result->paymentId)->toBe('tr_retry_wallets');
});

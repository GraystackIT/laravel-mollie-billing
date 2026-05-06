<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Services\Billing\ProrataExecutor;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

function makeExecutorBillable(string $plan = 'enterprise'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'active_addon_codes' => [],
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    return $billable->refresh();
}

function makeExecutorInvoice(TestBillable $billable, array $lines): BillingInvoice
{
    $sumNet = array_sum(array_column($lines, 'amount_net'));
    $sumVat = array_sum(array_column($lines, 'vat_amount'));
    $sumGross = array_sum(array_column($lines, 'amount_gross'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $sumNet,
        'amount_vat' => $sumVat,
        'amount_gross' => $sumGross,
        'line_items' => $lines,
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);
}

function planLineExec(string $code, int $netCents, float $vatRate): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'plan',
        'code' => $code,
        'label' => ucfirst($code),
        'quantity' => 1,
        'unit_price_net' => $netCents,
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => now()->subDays(15)->toIso8601String(),
        'period_end' => now()->addDays(15)->toIso8601String(),
    ];
}

it('Mollie→Free pure refund creates Refund invoice + cancels Mollie sub', function (): void {
    $billable = makeExecutorBillable('enterprise');
    makeExecutorInvoice($billable, [planLineExec('enterprise', 3000, 20.0)]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $cancelCalled = false;
    $refundCalled = false;
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$cancelCalled, &$refundCalled) {
        if ($request instanceof CancelSubscriptionRequest) {
            $cancelCalled = true;
            return new \stdClass;
        }
        if ($request instanceof CreatePaymentRefundRequest) {
            $refundCalled = true;
            $r = new \stdClass;
            $r->id = 're_test_'.uniqid();
            return $r;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    $lines = app(ProrataComposer::class)->compose($intent);
    app(ProrataExecutor::class)->execute($billable, $intent, $lines);

    expect($cancelCalled)->toBeTrue();
    expect($refundCalled)->toBeTrue();

    $refunds = BillingInvoice::where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->get();
    expect($refunds)->toHaveCount(1);
    expect($refunds[0]->mollie_payment_id)->toBeNull();
    expect($refunds[0]->amount_net)->toBeLessThan(0);
});

it('Mollie→Mollie plan change initiates charge + writes pending_prorata_change', function (): void {
    $billable = makeExecutorBillable('enterprise');
    makeExecutorInvoice($billable, [planLineExec('enterprise', 3000, 20.0)]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $createPaymentCalled = false;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$createPaymentCalled) {
        if ($request instanceof CreatePaymentRequest) {
            $createPaymentCalled = true;
            $p = new \stdClass;
            $p->id = 'tr_charge_'.uniqid();
            return $p;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    $lines = app(ProrataComposer::class)->compose($intent);
    app(ProrataExecutor::class)->execute($billable, $intent, $lines);

    expect($createPaymentCalled)->toBeTrue();

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
    expect($pending)->not->toBeNull();
    expect($pending['intent']['new_plan'])->toBe('starter');
    expect($pending['refund_lines'])->toHaveCount(1);

    // Mollie-subscription PATCH did NOT run yet (only happens in phase 2).
    // Implicitly verified: the andReturnUsing() closure above throws LogicException
    // on any non-CreatePaymentRequest, so a MollieUpdateSubscriptionRequest would fail the test.
});

it('idempotent: second execute() call is no-op while pending_prorata_change exists', function (): void {
    $billable = makeExecutorBillable('enterprise');
    makeExecutorInvoice($billable, [planLineExec('enterprise', 3000, 20.0)]);

    $billable->forceFill(['subscription_meta' => array_merge(
        $billable->getBillingSubscriptionMeta(),
        ['pending_prorata_change' => ['charge_payment_id' => 'tr_existing']],
    )])->save();
    $billable->refresh();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    Mollie::shouldReceive('send')->never();

    $lines = app(ProrataComposer::class)->compose($intent);
    app(ProrataExecutor::class)->execute($billable, $intent, $lines);

    // No new BillingInvoice entries.
    expect(BillingInvoice::where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0);
});

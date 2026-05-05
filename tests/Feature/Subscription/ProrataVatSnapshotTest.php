<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
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
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function makeMolliePeriodBillable(string $country, ?string $vatNumber = null): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => $country,
        'vat_number' => $vatNumber,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    return $billable->refresh();
}

function makePeriodInvoice(TestBillable $billable, float $vatRate, string $country): BillingInvoice
{
    $vatAmount = (int) round(3000 * $vatRate / 100);
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_period_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => $country,
        'currency' => 'EUR',
        'amount_net' => 3000,
        'amount_vat' => $vatAmount,
        'amount_gross' => 3000 + $vatAmount,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => 3000,
            'amount_net' => 3000,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'amount_gross' => 3000 + $vatAmount,
            'period_start' => now()->subDays(15)->toIso8601String(),
            'period_end' => now()->addDays(15)->toIso8601String(),
        ]],
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);
}

it('snapshots the original VAT rate in the pending refund lines even when the customer has since registered a VAT ID', function (): void {
    // Original period: B2C in AT → 20% VAT charged.
    $billable = makeMolliePeriodBillable('AT');
    makePeriodInvoice($billable, 20.00, 'AT');

    // Customer now claims B2B (mid-period UID registration).
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();
    $billable->refresh();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_'.uniqid()]);

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

    expect($pending)->not->toBeNull('Pending prorata change should be persisted');
    $refundLines = $pending['refund_lines'] ?? [];
    expect($refundLines)->not->toBeEmpty();

    // The first refund-line carries the snapshot vat_rate from the original invoice — 20%, not 0%.
    expect((float) $refundLines[0]['vat_rate'])->toEqualWithDelta(20.0, 0.01, 'Refund-line must carry the original 20% VAT rate, not the now-active 0% reverse-charge');
});

it('snapshots the original 0% reverse-charge rate in the pending refund lines even when the customer has since lost their VAT ID', function (): void {
    // Original period: B2B reverse-charge → 0% VAT.
    $billable = makeMolliePeriodBillable('DE', 'DE123456789');
    makePeriodInvoice($billable, 0.00, 'DE');

    // Customer's UID got removed mid-period.
    $billable->forceFill(['vat_number' => null])->save();
    $billable->refresh();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_'.uniqid()]);

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

    expect($pending)->not->toBeNull();
    $refundLines = $pending['refund_lines'] ?? [];
    expect($refundLines)->not->toBeEmpty();

    expect((float) $refundLines[0]['vat_rate'])->toEqualWithDelta(0.0, 0.01, 'Refund-line must carry the original 0% rate, not the now-active B2C rate');
});

it('preview shows prorata VAT from the period invoice, not the live billable', function (): void {
    $billable = makeMolliePeriodBillable('AT');
    makePeriodInvoice($billable, 20.00, 'AT');

    // Customer adds a VAT number mid-period. Without the snapshot fix the preview
    // would compute 0% VAT on the prorata amount — letting the user expect a
    // smaller refund than what we are legally obliged to issue.
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();
    $billable->refresh();

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'starter', 'monthly');

    expect($preview['prorataCreditNet'])->toBeGreaterThan(0);
    // gross > net proves VAT was applied; specifically gross/net must be ≈ 1.20.
    $net = (int) $preview['prorataCreditNet'];
    $gross = (int) $preview['prorataCreditGross'];
    expect($gross)->toBeGreaterThan($net);
    expect($gross / $net)->toEqualWithDelta(1.20, 0.01, 'Preview gross must reflect original 20% rate');
});

it('skips the refund line when no period invoice exists for the current plan', function (): void {
    $billable = makeMolliePeriodBillable('AT');
    // No period invoice — e.g. after a full refund or data inconsistency. The ProrataComposer
    // must no longer throw; it returns a charge-only line for the new plan.

    $intent = new \GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(\GraystackIT\MollieBilling\Services\Billing\ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('charge');
    expect($lines[0]->code)->toBe('starter');
});

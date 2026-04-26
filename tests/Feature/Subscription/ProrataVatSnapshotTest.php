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
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_period_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => $country,
        'vat_rate' => $vatRate,
        'currency' => 'EUR',
        'amount_net' => 3000,
        'amount_vat' => (int) round(3000 * $vatRate / 100),
        'amount_gross' => 3000 + (int) round(3000 * $vatRate / 100),
        'line_items' => [],
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);
}

it('refunds with the original VAT rate even when the customer has since registered a VAT ID', function (): void {
    // Original period: B2C in AT → 20% VAT charged.
    $billable = makeMolliePeriodBillable('AT');
    makePeriodInvoice($billable, 20.00, 'AT');

    // Customer now claims B2B (mid-period UID registration).
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();
    $billable->refresh();

    $capturedRefund = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$capturedRefund) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $capturedRefund = $request;
        }
        return new \stdClass;
    });

    // Downgrade triggers a prorata refund.
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    expect($capturedRefund)->not->toBeNull('Mollie refund call should have been issued');

    // Refund gross must reflect the period's 20% rate, not the now-active 0% reverse-charge.
    $reflection = new ReflectionObject($capturedRefund);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    /** @var Money $money */
    $money = $amountProp->getValue($capturedRefund);

    // Prorata credit_net is roughly (3000 - 1000) * 15/30 = 1000. With 20% VAT → 1200 gross = "12.00".
    // We assert the gross is non-zero AND > the net (proves VAT was applied).
    $value = (float) $money->value;
    expect($value)->toBeGreaterThan(10.00, 'Refund must include the original 20% VAT, not 0%');
});

it('refunds with the original 0% reverse-charge rate even when the customer has since lost their VAT ID', function (): void {
    // Original period: B2B reverse-charge → 0% VAT.
    $billable = makeMolliePeriodBillable('DE', 'DE123456789');
    makePeriodInvoice($billable, 0.00, 'DE');

    // Customer's UID got removed mid-period.
    $billable->forceFill(['vat_number' => null])->save();
    $billable->refresh();

    $capturedRefund = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$capturedRefund) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $capturedRefund = $request;
        }
        return new \stdClass;
    });

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    expect($capturedRefund)->not->toBeNull();

    $reflection = new ReflectionObject($capturedRefund);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    /** @var Money $money */
    $money = $amountProp->getValue($capturedRefund);

    // Prorata credit_net ≈ 1000 cents, with 0% VAT → 1000 gross = "10.00" exactly.
    expect((float) $money->value)->toEqualWithDelta(10.00, 0.10, 'Refund must use original 0% rate, not the now-active B2C rate');
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

it('throws when no period invoice exists for a Mollie subscription on prorata refund', function (): void {
    $billable = makeMolliePeriodBillable('AT');
    // Intentionally no period invoice.

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]))->toThrow(\RuntimeException::class, 'no paid Subscription invoice found');
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'trial_days' => 0,
        'included_seats' => 10,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.business', [
        'name' => 'Business',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 3,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

it('reproduces the screenshot: 50% Recurring coupon on Enterprise→Business with 7 extra seats', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'TEST04',
        'name' => 'Test 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Screenshot',
        'email' => 's@x.test',
        'billing_country' => 'AT',
    ]);
    // Period started today → 31 days remaining of ~31 = factor ~1.0.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'enterprise',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test', 'seat_count' => 10],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    // Original Enterprise invoice for the prorata composer.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_orig',
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3900,
        'amount_vat' => 780,
        'amount_gross' => 4680,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'enterprise',
            'label' => 'Enterprise',
            'quantity' => 1,
            'unit_price_net' => 3900,
            'amount_net' => 3900,
            'vat_rate' => 20.0,
            'vat_amount' => 780,
            'amount_gross' => 4680,
            'period_start' => BillingTime::nowUtc()->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(31)->toIso8601String(),
        ]],
        'period_start' => BillingTime::nowUtc(),
        'period_end' => BillingTime::nowUtc()->addDays(31),
    ]);

    $preview = app(PreviewService::class)->previewUpdate($billable->fresh(), new SubscriptionUpdateRequest(
        planCode: 'business',
        interval: 'monthly',
        seats: 10, // 3 included + 7 extra (matches screenshot)
        couponCodes: ['TEST04'],
    ));

    $couponLines = array_values(array_filter(
        (array) ($preview['prorataLines'] ?? []),
        fn ($l) => ($l['kind'] ?? null) === 'coupon',
    ));

    expect($couponLines)->toHaveCount(1);

    $couponLine = $couponLines[0];
    echo "\n========\n";
    echo "newPriceNet (recurring) = " . ($preview['newPriceNet'] ?? 0) . " (expected 5400)\n";
    echo "couponDiscountNet (recurring) = " . ($preview['couponDiscountNet'] ?? 0) . " (expected 2700 = 50%)\n";
    echo "prorataChargeNet (due now, before coupon) = (was reduced)\n";
    echo "couponLine.amount_net = " . $couponLine['amount_net'] . "\n";
    echo "couponLine.amount_gross = " . $couponLine['amount_gross'] . "\n";
    echo "Total prorataLines net = " . $preview['prorataTotalNet'] . "\n";
    echo "Total prorataLines gross = " . $preview['prorataTotalGross'] . "\n";
    echo "========\n";

    expect((int) ($preview['newPriceNet'] ?? 0))->toBe(5400);
    expect((int) ($preview['couponDiscountNet'] ?? 0))->toBe(2700);

    // Sanity: the coupon line on the prorata block must NEVER be larger than the
    // remaining prorata charge — otherwise the "due now" total would go negative
    // (refund), which is wrong for an upgrade flow.
    $sumOfChargeLinesNet = 0;
    foreach ((array) $preview['prorataLines'] as $l) {
        if (($l['kind'] ?? null) !== 'coupon') {
            $sumOfChargeLinesNet += (int) ($l['amount_net'] ?? 0);
        }
    }
    expect(abs((int) $couponLine['amount_net']))->toBeLessThanOrEqual($sumOfChargeLinesNet);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
});

function billableWithRecurringMarker(int $baseAmount = 1000): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Marker Test',
        'email' => 'marker@x.test',
        'billing_country' => 'AT',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_test',
            'active_recurring_coupon' => [
                'coupon_id' => 0, // filled in below after coupon creation
                'code' => 'REC50',
                'discount_type' => 'percentage',
                'discount_value' => 50,
                // 6 monthly periods × 30d + 1d buffer = 181 days lifetime.
                'valid_until' => BillingTime::nowUtc()->copy()->addDays(181)->toIso8601String(),
                'base_amount_net' => $baseAmount,
                'first_applied_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $billable;
}

it('Bug #2: redeemRecurringCouponForRenewal writes the capped discount, not the full netAmount discount', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = billableWithRecurringMarker(1000);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon']['coupon_id'] = $coupon->id;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Simulate a renewal where the user has since added seats: charge net is 4000.
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-1',
        'invoice_kind' => InvoiceKind::Subscription,
        'amount_net' => 3500,
        'amount_vat' => 0,
        'amount_gross' => 3500,
        'status' => 'paid',
        'country' => 'AT',
        'currency' => 'EUR',
        'line_items' => [],
    ]);

    $service->redeemRecurringCouponForRenewal($billable->fresh(), 4000, (int) $invoice->id);

    $redemption = CouponRedemption::query()->where('coupon_id', $coupon->id)->latest('id')->first();
    expect($redemption)->not->toBeNull()
        // 50% × min(base=1000, current=4000) = 500. NOT 50% × 4000 = 2000.
        ->and((int) $redemption->discount_amount_net)->toBe(500);
});

it('Bug #4: free downgrade clears the active_recurring_coupon marker', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = billableWithRecurringMarker(1000);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon']['coupon_id'] = $coupon->id;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Original plan invoice for the prorata composer (downgrade triggers a refund line).
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_orig',
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'basic',
            'label' => 'Basic',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
            'period_start' => BillingTime::nowUtc()->subDays(5)->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(25)->toIso8601String(),
        ]],
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
    ]);

    // Stub Mollie cancellation/refund calls so the test doesn't actually hit the API.
    \Mollie\Laravel\Facades\Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    \Mollie\Laravel\Facades\Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_test']);

    // Downgrade Mollie → Free (basic → free).
    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->toBeNull();
});

it('Bug #5: an existing recurring marker is rendered in the plan-change preview even without re-applying the coupon', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = billableWithRecurringMarker(1000);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon']['coupon_id'] = $coupon->id;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Preview a no-coupon plan-change (just adding seats).
    $preview = app(PreviewService::class)->previewUpdate($billable->fresh(), new SubscriptionUpdateRequest(
        seats: 3, // 1 included + 2 extra × 500 = 1000 net + 1000 plan = 2000
    ));

    // Marker discount = 50% × min(1000, 2000) = 500.
    expect((int) ($preview['couponDiscountNet'] ?? 0))->toBe(500);

    // The line items list should include the marker as a coupon line.
    $couponLines = array_values(array_filter(
        (array) ($preview['lineItems'] ?? []),
        fn ($l) => ($l['kind'] ?? null) === 'coupon',
    ));
    expect($couponLines)->toHaveCount(1)
        ->and((int) $couponLines[0]['total_net'])->toBe(-500);
});

it('Bug #6: re-entering the same recurring coupon while active is rejected with recurring_already_active', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = billableWithRecurringMarker(1000);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon']['coupon_id'] = $coupon->id;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    try {
        $service->validate('REC50', $billable->fresh(), [
            'planCode' => 'basic',
            'interval' => 'monthly',
            'orderAmountNet' => 1000,
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('recurring_already_active');
    }
});

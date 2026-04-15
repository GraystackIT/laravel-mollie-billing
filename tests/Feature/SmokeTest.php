<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingPolicy;

it('boots the service provider and runs migrations', function () {
    expect(\Schema::hasTable('billing_invoices'))->toBeTrue();
    expect(\Schema::hasTable('billing_country_mismatches'))->toBeTrue();
    expect(\Schema::hasTable('coupons'))->toBeTrue();
    expect(\Schema::hasTable('coupon_redemptions'))->toBeTrue();
    expect(\Schema::hasTable('billing_processed_webhooks'))->toBeTrue();
});

it('persists a coupon with all access-grant fields', function () {
    $coupon = Coupon::create([
        'code' => 'PARTNER-2026',
        'name' => 'Partner 2026',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'yearly',
        'grant_addon_codes' => ['print-gateway'],
        'grant_duration_days' => 365,
    ]);

    expect($coupon->fresh()->type)->toBe(CouponType::AccessGrant);
    expect($coupon->fresh()->grant_addon_codes)->toBe(['print-gateway']);
});

it('reserves and finalises a webhook signature', function () {
    $reservation = BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_test_1',
        'event_signature' => BillingProcessedWebhook::pendingSignature('tr_test_1'),
        'received_at' => now(),
    ]);

    $reservation->update([
        'event_signature' => BillingProcessedWebhook::finalSignature('tr_test_1', 'paid'),
        'processed_at' => now(),
    ]);

    expect($reservation->fresh()->event_signature)->toBe('tr_test_1:paid');
});

it('exposes the enum cases used by the package', function () {
    expect(CouponType::cases())->toHaveCount(5);
    expect(SubscriptionStatus::cases())->toHaveCount(5);
    expect(SubscriptionSource::cases())->toHaveCount(3);
});

it('computes prorata factors', function () {
    $start = now()->subDays(20);
    $end = now()->addDays(10);

    $factor = BillingPolicy::prorataFactor($start, $end);

    expect($factor)->toBeFloat()->toBeGreaterThan(0)->toBeLessThan(1);
});

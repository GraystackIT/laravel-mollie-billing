<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\CouponInUseException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * `validate()` and `redeem()` have to agree on whether a revoked redemption
 * occupies a per-billable slot. While validate() counted revoked rows and
 * redeem() did not, revoking a grant left the coupon unusable through every UI
 * path (validate() runs first) even though the write path would have taken it.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function revokedCapBillable(string $email = 'revcap@x.test'): TestBillable
{
    return TestBillable::create(['name' => 'Rev Cap', 'email' => $email])->refresh();
}

it('lets validate re-accept a coupon whose only redemption was revoked', function (): void {
    $service = app(CouponService::class);
    $billable = revokedCapBillable();

    $coupon = $service->accessGrantCoupon('GRANT-REVCAP', 'pro', 'monthly', [], 30);
    $redemption = $service->redeem($coupon, $billable, []);

    $service->revokeGrant($redemption->fresh(), 'admin revoke');

    // revokeGrant() already frees the global slot by decrementing the counter;
    // the per-billable slot has to follow, in both readings.
    expect($coupon->fresh()->redemptions_count)->toBe(0);

    $validated = $service->validate('GRANT-REVCAP', $billable->fresh(), []);
    expect($validated->id)->toBe($coupon->id);

    $second = $service->redeem($coupon->fresh(), $billable->fresh(), []);
    expect($second->id)->not->toBe($redemption->id);
    expect(CouponRedemption::where('coupon_id', $coupon->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('still blocks a coupon whose live redemption fills the per-billable slot', function (): void {
    $service = app(CouponService::class);
    $billable = revokedCapBillable('revcap2@x.test');

    $coupon = $service->accessGrantCoupon('GRANT-LIVECAP', 'pro', 'monthly', [], 30);
    $service->redeem($coupon, $billable, []);

    expect(fn () => $service->validate('GRANT-LIVECAP', $billable->fresh(), []))
        ->toThrow(InvalidCouponException::class, 'per_billable_limit_reached');
});

it('keeps a revoked redemption in the coupon history', function (): void {
    $service = app(CouponService::class);
    $billable = revokedCapBillable('revcap3@x.test');

    $coupon = $service->accessGrantCoupon('GRANT-HISTORY', 'pro', 'monthly', [], 30);
    $redemption = $service->redeem($coupon, $billable, []);
    $service->revokeGrant($redemption->fresh(), null);

    // The counter is back to 0, but the redemption happened: its audit row and the
    // documents issued under the old code still exist, so neither renaming nor
    // deleting the coupon may be waved through.
    expect($coupon->fresh()->redemptions_count)->toBe(0);

    expect(fn () => $service->update($coupon->fresh(), ['code' => 'GRANT-RENAMED']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->delete($coupon->fresh()))
        ->toThrow(CouponInUseException::class);
});

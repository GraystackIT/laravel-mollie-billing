<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Events\CouponRedemptionRecordMissing;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

/*
 * A coupon is redeemed after the money moved. When that redemption then fails — the
 * campaign filled up while the payment travelled — the customer keeps the discount they
 * paid with, and for a recurring coupon the marker is written so the renewals honour it
 * too. Nothing is left for an operator to do: that is a log line and an audit entry, not
 * a mail. Only when the marker cannot be written either does somebody have to act.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);

    Notification::fake();
});

/**
 * A coupon priced for this billable whose only campaign slot was then taken by somebody
 * else, so the redemption that follows the payment is refused.
 *
 * @return array{0: TestBillable, 1: array<string, mixed>}
 */
function failedRedemptionPriced(CouponType $type): array
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Late', 'email' => uniqid('late-').'@x.test', 'billing_country' => 'AT']);
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    $coupon = app(CouponService::class)->create([
        'code' => 'RACE-'.strtoupper(bin2hex(random_bytes(2))),
        'type' => $type,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions' => 1,
    ] + ($type === CouponType::Recurring ? ['discount_periods' => 3] : []));

    $priced = app(FirstPaymentCouponPricing::class)->price($billable, (string) $coupon->code, 'pro', 'monthly', [], 0);

    Coupon::query()->whereKey($coupon->id)->update(['redemptions_count' => 1]);

    return [$billable, $priced];
}

it('records a missing redemption row as an audit entry, not as a mail to the admins', function (CouponType $type): void {
    [$billable, $priced] = failedRedemptionPriced($type);

    app(FirstPaymentCouponPricing::class)->redeem($billable, $priced, 'pro', 'monthly', 0);

    expect(CouponRedemption::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $entry = BillingAuditEntry::model()::query()->where('event', 'coupon_redemption_record_missing')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['replace']['code'])->toBe((string) $priced['coupon']->code)
        ->and((int) $entry->properties['replace']['discount_cents'])->toBe(1000);

    // The discount itself is in force: a recurring coupon has its marker.
    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    expect($marker['code'] ?? null)->toBe($type === CouponType::Recurring ? (string) $priced['coupon']->code : null);
})->with([
    'single payment' => CouponType::SinglePayment,
    'recurring' => CouponType::Recurring,
]);

it('still mails the admins when the recurring marker cannot be written either', function (): void {
    [$billable, $priced] = failedRedemptionPriced(CouponType::Recurring);

    // Mollie holds the discounted amount, the books do not know the discount.
    app()->bind(CouponService::class, fn ($app) => new class($app->make(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class), $app->make(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)) extends CouponService
    {
        public function applyRecurringMarker(Coupon $coupon, \GraystackIT\MollieBilling\Contracts\Billable $billable, int $baseAmountNet, ?string $interval = null): void
        {
            throw new RuntimeException('row is locked');
        }
    });

    app(FirstPaymentCouponPricing::class)->redeem($billable, $priced, 'pro', 'monthly', 0);

    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class);
    expect($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
});

it('explains the audit entry in both languages', function (string $locale): void {
    app()->setLocale($locale);

    expect(__('billing::audit.coupon_redemption_record_missing', ['code' => 'X']))->not->toContain('billing::');
})->with(['en', 'de']);

it('dispatches an event an app can listen for', function (): void {
    [$billable, $priced] = failedRedemptionPriced(CouponType::SinglePayment);
    Illuminate\Support\Facades\Event::fake([CouponRedemptionRecordMissing::class]);

    app(FirstPaymentCouponPricing::class)->redeem($billable, $priced, 'pro', 'monthly', 0);

    Illuminate\Support\Facades\Event::assertDispatched(
        CouponRedemptionRecordMissing::class,
        fn (CouponRedemptionRecordMissing $e): bool => $e->coupon->is($priced['coupon']) && $e->discountNet === 1000,
    );
});

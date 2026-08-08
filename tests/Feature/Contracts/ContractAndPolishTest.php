<?php

declare(strict_types=1);

use Bavix\Wallet\Models\Transaction;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * Places where a promise and the code behind it had drifted apart: a UI that accepted a
 * coupon the purchase rejects, a discount field that could take money instead of giving
 * it, a serial that could carry the wrong year, and a period window with no anchor.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.products.token-pack', [
        'name' => 'Token pack',
        'price_net' => 1000,
        'usage_type' => 'Tokens',
        'quantity' => 500,
    ]);
});

function contractBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    return TestBillable::create(array_merge([
        'name' => 'Contract Co',
        'email' => uniqid('contract-').'@x.test',
        'billing_country' => 'DE',
    ], $overrides))->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// A discount must give money, never take it
// ─────────────────────────────────────────────────────────────────────────────

it('refuses a negative or zero discount value', function (): void {
    $service = app(CouponService::class);

    foreach ([
        ['discount_type' => DiscountType::Fixed, 'discount_value' => -500],
        ['discount_type' => DiscountType::Fixed, 'discount_value' => 0],
        ['discount_type' => DiscountType::Percentage, 'discount_value' => -10],
        ['discount_type' => DiscountType::Percentage, 'discount_value' => 0],
    ] as $i => $attrs) {
        // A Fixed −500 was accepted and the pricing services subtract it unexamined:
        // the "discount" *increased* the charge by €5. A 0 consumes a redemption slot
        // and changes nothing.
        expect(fn () => $service->create(array_merge([
            'code' => 'BAD'.$i,
            'name' => 'Bad discount',
            'type' => CouponType::SinglePayment,
        ], $attrs)))->toThrow(InvalidArgumentException::class);
    }

    // Only checked for the two types that carry a discount, and the 100 % ceiling for
    // Percentage still holds.
    expect(fn () => $service->create([
        'code' => 'TOOMUCH',
        'name' => 'Too much',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 101,
    ]))->toThrow(InvalidArgumentException::class);

    expect($service->create([
        'code' => 'GOOD',
        'name' => 'Good',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 500,
    ])->code)->toBe('GOOD');
});

// ─────────────────────────────────────────────────────────────────────────────
// The products page must not accept a code its purchase rejects
// ─────────────────────────────────────────────────────────────────────────────

it('refuses a recurring coupon on a product order', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'FOREVER10',
        'name' => 'Ten percent forever',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = contractBillable();

    // A one-time order has no recurring charge to attach a marker to, and
    // StartOneTimeOrderCheckout accepts SinglePayment only — so the products page
    // offering `Recurring` in its allowed_types accepted a code at the field and then
    // failed the purchase it was entered for.
    expect(fn () => $service->validate('FOREVER10', $billable, [
        'productCodes' => ['token-pack'],
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::SinglePayment],
    ]))->toThrow(InvalidCouponException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// The serial's year must be the same year everything else records
// ─────────────────────────────────────────────────────────────────────────────

it('takes the serial year from the same UTC clock as everything else', function (): void {
    // `date('y')` reads the server's wall clock in the server's timezone, which is
    // neither of the two things the rest of the package uses. In production the symptom
    // was New Year: a serial minted at 00:30 UTC on a UTC−5 server carried the previous
    // year's digits while `created_at` — which the OSS export and the PDF both read —
    // said otherwise. Here the same divergence is produced by moving the clock, which
    // `date()` cannot see and `BillingTime::nowUtc()` can.
    test()->travelTo(BillingTime::nowUtc()->setDate(2030, 1, 1)->setTime(0, 30));

    $invoiceNumber = app(InvoiceNumberGenerator::class)->generate('invoice');

    expect($invoiceNumber)->toStartWith('IN-30');
});

// ─────────────────────────────────────────────────────────────────────────────
// The purchased-balance window needs an anchor that is always written
// ─────────────────────────────────────────────────────────────────────────────

it('marks the renewal credit so a zero-balance renewal still closes the period', function (): void {
    $billable = contractBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth(),
    ]);

    $wallet = $billable->getWallet('Tokens') ?? $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);

    // Bought 500 extra units last period and consumed every one of them, ending the
    // period at exactly 0.
    app(WalletUsageService::class)->credit($billable, 'Tokens', 500, 'one_time_order:token-pack');
    $billable->recordBillingUsage('Tokens', 500);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0);

    // The renewal. `resetAndCredit()` writes no `period_reset` here — that branch only
    // fires for a non-zero balance — so before this fix the period had no anchor at all
    // and the backfill counted last period's 500 as "purchased this period", which
    // every later reset then re-credited on top of the quota.
    app(WalletUsageService::class)->resetAndCredit($billable, 'Tokens', 100);

    $reasons = Transaction::query()
        ->where('wallet_id', $wallet->getKey())
        ->pluck('meta')
        ->map(fn ($m) => is_array($m) ? ($m['reason'] ?? null) : null)
        ->all();

    expect($reasons)->toContain(WalletUsageService::PERIOD_CREDIT_REASON)
        ->and($reasons)->not->toContain('period_reset');

    // And the marker closes the window: nothing was purchased after the renewal.
    app('Illuminate\Contracts\Console\Kernel')->call('billing:sync-purchased-balance', ['--force' => true]);

    expect(WalletUsageService::getPurchasedBalance($billable->fresh()->getWallet('Tokens')))->toBe(0);
});

it('still counts a purchase made after the renewal', function (): void {
    $billable = contractBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth(),
    ]);

    app(WalletUsageService::class)->credit($billable, 'Tokens', 500, 'one_time_order:token-pack');
    $billable->recordBillingUsage('Tokens', 500);
    app(WalletUsageService::class)->resetAndCredit($billable, 'Tokens', 100);

    // This one belongs to the new period and must survive the next reset.
    app(WalletUsageService::class)->credit($billable, 'Tokens', 300, 'one_time_order:token-pack');

    app('Illuminate\Contracts\Console\Kernel')->call('billing:sync-purchased-balance', ['--force' => true]);

    expect(WalletUsageService::getPurchasedBalance($billable->fresh()->getWallet('Tokens')))->toBe(300);
});

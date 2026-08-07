<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Storage;

/**
 * Every coupon type, measured where it actually matters: on the money of the NEXT
 * renewal.
 *
 * Redeeming a coupon is the easy half. The half that goes wrong silently is what
 * the following period charges — a `SinglePayment` that keeps discounting is
 * revenue lost every month forever, a `Recurring` that stops early is a promise
 * broken, and a `Credits` top-up eaten by the renewal recharge is goods paid for
 * and not delivered. Each case here redeems, asserts the immediate effect, then
 * drives a real renewal webhook and asserts the invoice.
 *
 * Plan: 2000 net monthly, 19% DE VAT → 2380 gross at full price.
 */
class CouponMatrixFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    CouponMatrixFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, CouponMatrixFakeWebhookController::class);

    // Extensions PATCH the Mollie subscription's next charge date; the spy records
    // that without a network call.
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['reports'],
        'allowed_addons' => ['extra'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'seat_price_net' => 500,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
            'yearly' => [
                'base_price_net' => 20000,
                'seat_price_net' => 5000,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 1200],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 500], 'yearly' => ['price_net' => 5000]],
    ]);
    config()->set('mollie-billing-plans.features', [
        'reports' => ['name' => 'Reports', 'description' => null],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
});

function matrixBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Matrix Co',
        'email' => uniqid('matrix-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_matrix',
        'mollie_mandate_id' => 'mdt_matrix',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::toUtc('2026-01-01T09:00:00Z'),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_matrix'],
    ], $overrides))->save();

    return $b->refresh();
}

/**
 * A renewal Mollie generated. The gross is what Mollie would have collected for
 * the CURRENT recurring amount — a discounted subscription is PATCHed down, so a
 * discounted renewal arrives with the discounted gross.
 */
function renewMatrix(TestBillable $billable, string $paymentId, string $paidAt, string $gross): BillingInvoice
{
    CouponMatrixFakeWebhookController::$nextPayment = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => $paidAt,
        'subscriptionId' => 'sub_matrix',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);

    return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->sole();
}

/** Net of the coupon line on an invoice, or null when there is none. */
function couponLineNet(BillingInvoice $invoice): ?int
{
    $line = collect($invoice->line_items)->firstWhere('kind', 'coupon');

    return $line === null ? null : (int) ($line['total_net'] ?? $line['amount_net'] ?? 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// SinglePayment — discounts ONE payment. The renewal must be full price.
// ─────────────────────────────────────────────────────────────────────────────

it('SinglePayment: discounts nothing on the renewal', function (): void {
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'HALF-ONCE',
        'name' => 'Half off once',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    // A SinglePayment coupon leaves no recurring marker — that is the whole
    // difference between it and Recurring, and the only thing the renewal reads.
    $service->redeem($coupon, $billable, ['orderAmountNet' => 2000]);

    expect($billable->refresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey('active_recurring_coupon');

    $invoice = renewMatrix($billable, 'tr_m_single', '2026-02-01T09:00:00Z', '23.80');

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and((int) $invoice->amount_gross)->toBe(2380)
        ->and(couponLineNet($invoice))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Recurring — discounts every period until its window closes.
// ─────────────────────────────────────────────────────────────────────────────

it('Recurring: discounts the renewal and writes the coupon line', function (): void {
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'LOYAL-25',
        'name' => '25% for three periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 3,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 2000]);

    $marker = $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];
    expect($marker['code'])->toBe('LOYAL-25')
        ->and((int) $marker['base_amount_net'])->toBe(2000);

    // 2000 − 25% = 1500 net → 1785 gross. Mollie was PATCHed to that amount, so
    // the renewal arrives with it.
    $invoice = renewMatrix($billable, 'tr_m_rec1', '2026-02-01T09:00:00Z', '17.85');

    expect((int) $invoice->amount_net)->toBe(1500)
        ->and((int) $invoice->amount_gross)->toBe(1785)
        ->and(couponLineNet($invoice))->toBe(-500);
});

it('Recurring: stops discounting once its window has closed', function (): void {
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'LOYAL-END',
        'name' => '25% for one period',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 1,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 2000]);

    // The marker's window is max_redemptions × interval days. Walk past it.
    $this->travel(40)->days();

    $invoice = renewMatrix($billable, 'tr_m_rec_end', now()->toIso8601String(), '23.80');

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and(couponLineNet($invoice))->toBeNull();
});

it('Recurring: a fixed discount is capped at the base amount it was applied to', function (): void {
    // Seats added after the coupon must not be discounted — the marker pins the
    // basis, otherwise growing the subscription silently grows the discount.
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'FIVE-OFF',
        'name' => '€5 off every period',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 500,
        'max_redemptions_per_billable' => 6,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 2000]);

    // Two extra seats at 500 each — the subscription is now 3000 net.
    $billable->forceFill([
        'subscription_meta' => array_merge($billable->refresh()->getBillingSubscriptionMeta(), ['seat_count' => 3]),
    ])->save();

    $invoice = renewMatrix($billable->refresh(), 'tr_m_rec_fixed', '2026-02-01T09:00:00Z', '29.75');

    // 3000 − 500 = 2500 net. The discount stayed 500, not 750.
    expect(couponLineNet($invoice))->toBe(-500)
        ->and((int) $invoice->amount_net)->toBe(2500);
});

// ─────────────────────────────────────────────────────────────────────────────
// Credits — a wallet top-up. The renewal recharge must not eat it.
// ─────────────────────────────────────────────────────────────────────────────

it('Credits: purchased units survive a renewal that resets the plan quota', function (): void {
    // Tokens are configured rollover=false here, so the renewal RESETS the plan
    // allowance. Purchased credits are a different pot and must survive that —
    // the customer paid for them separately.
    $billable = matrixBillable();
    $service = app(CouponService::class);

    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'Tokens', 100, 'seed');

    $service->redeem($service->creditsCoupon('TOPUP-250', ['Tokens' => 250]), $billable, []);

    expect($billable->refresh()->remainingBillingQuota('Tokens'))->toBe(350)
        ->and($billable->purchasedBillingCredits('Tokens'))->toBe(250);

    // Spend 60 — plan credits go first, so the purchased 250 are untouched.
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->debit($billable, 'Tokens', 60, 'usage');

    renewMatrix($billable->refresh(), 'tr_m_credits', '2026-02-01T09:00:00Z', '23.80');

    // Reset to the plan's 100 PLUS the 250 that were bought.
    expect($billable->refresh()->remainingBillingQuota('Tokens'))->toBe(350)
        ->and($billable->purchasedBillingCredits('Tokens'))->toBe(250);
});

it('Credits: the renewal invoice is full price — credits are not a discount', function (): void {
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $service->redeem($service->creditsCoupon('TOPUP-50', ['Tokens' => 50]), $billable, []);

    $invoice = renewMatrix($billable->refresh(), 'tr_m_credits_price', '2026-02-01T09:00:00Z', '23.80');

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and(couponLineNet($invoice))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// TrialExtension — moves the trial end, so the first charge moves with it.
// ─────────────────────────────────────────────────────────────────────────────

it('TrialExtension: pushes the trial end and the first charge starts the paid period there', function (): void {
    // Anchored on now(), not an absolute date: `extendBillingTrialUntil()` extends
    // from max(current, now), so a trial that already lapsed would be extended from
    // today instead of from its own end — and the assertion would be measuring the
    // wrong thing.
    $trialEnd = BillingTime::nowUtc()->addDays(10)->startOfSecond();

    $billable = matrixBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => $trialEnd,
    ]);
    $service = app(CouponService::class);

    $service->redeem($service->trialExtensionCoupon('MORE-14', 14), $billable, []);

    $billable->refresh();

    $extended = $trialEnd->copy()->addDays(14);

    expect($billable->trial_ends_at->toIso8601String())->toBe($extended->toIso8601String())
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial);

    // The charge lands at the NEW trial end and starts the paid period there — so
    // the customer gets the extra 14 days for free, not billed twice for them.
    $paidAt = $extended->copy()->addMinute();
    $invoice = renewMatrix($billable, 'tr_m_trial_ext', $paidAt->toIso8601String(), '23.80');

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(2000)
        // The paid period starts at the charge, and the next billing date is one
        // interval after THAT. The trial extension had PATCHed a
        // `next_charge_date_override` onto the billable; once its charge landed the
        // override is spent, and leaving it would report a next-billing date a
        // month early — the overage pass and every prorata factor read this date.
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe($paidAt->toIso8601String())
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override')
        ->and($billable->nextBillingDate()->greaterThan($billable->subscription_period_starts_at))->toBeTrue()
        ->and($billable->nextBillingDate()->toIso8601String())
        ->toBe($paidAt->copy()->addMonth()->toIso8601String());
});

// ─────────────────────────────────────────────────────────────────────────────
// AccessGrant — complimentary access, no Mollie subscription behind it.
// ─────────────────────────────────────────────────────────────────────────────

it('AccessGrant: grants the plan locally, so no renewal is charged at all', function (): void {
    $billable = matrixBillable([
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::New,
        'subscription_plan_code' => null,
        'subscription_interval' => null,
        'subscription_meta' => [],
    ]);
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'COMP-PRO',
        'name' => 'Complimentary Pro for 90 days',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 90,
    ]);

    $service->redeem($coupon, $billable, []);

    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->hasPlanFeature('reports'))->toBeTrue()
        // Local, not Mollie: there is no subscription to renew, so nothing can be
        // charged when the grant runs out — it simply expires.
        ->and($billable->isLocalBillingSubscription())->toBeTrue()
        ->and($billable->getBillingSubscriptionEndsAt())->not->toBeNull()
        ->and(BillingInvoice::query()->count())->toBe(0);
});

it('AccessGrant: expires into no access once its days run out', function (): void {
    $billable = matrixBillable([
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::New,
        'subscription_plan_code' => null,
        'subscription_interval' => null,
        'subscription_meta' => [],
    ]);
    $service = app(CouponService::class);

    $service->redeem($service->create([
        'code' => 'COMP-SHORT',
        'name' => 'Complimentary Pro for 7 days',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 7,
    ]), $billable, []);

    expect($billable->refresh()->hasAccessibleBillingSubscription())->toBeTrue();

    $this->travel(8)->days();

    expect(TestBillable::sole()->hasAccessibleBillingSubscription())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// PeriodExtension — free days on the current period, so billing moves out.
// ─────────────────────────────────────────────────────────────────────────────

it('PeriodExtension: pushes the next charge date out on a Mollie subscription', function (): void {
    // A running period, not a historical one: `nextBillingDate()` only honours the
    // override while it is still in the future, so a fixture anchored in the past
    // would silently fall back to the computed date and hide the effect.
    $billable = matrixBillable([
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ]);
    $service = app(CouponService::class);

    $before = $billable->nextBillingDate()->toIso8601String();

    $service->redeem($service->create([
        'code' => 'PLUS-30',
        'name' => '30 free days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 30,
    ]), $billable, []);

    $billable->refresh();

    // A Mollie subscription is PATCHed via `next_charge_date_override`, which
    // nextBillingDate() honours ahead of the computed date.
    expect($billable->nextBillingDate()->toIso8601String())->not->toBe($before)
        ->and($billable->nextBillingDate()->isAfter(BillingTime::toUtc($before)))->toBeTrue()
        ->and(BillingInvoice::query()->count())->toBe(0);
});

it('PeriodExtension: the renewal after the extension is full price', function (): void {
    // Free days are not a discount — when the charge finally comes, it is whole.
    $billable = matrixBillable();
    $service = app(CouponService::class);

    $service->redeem($service->create([
        'code' => 'PLUS-30-B',
        'name' => '30 free days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 30,
    ]), $billable, []);

    $invoice = renewMatrix($billable->refresh(), 'tr_m_periodext', '2026-03-03T09:00:00Z', '23.80');

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and(couponLineNet($invoice))->toBeNull();
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Every coupon type the checkout accepts, driven through a REAL signup — and then
 * one period further.
 *
 * A signup coupon is priced three times over, by three different pieces of code:
 * once for the checkout charge, once for the amount Mollie's subscription is
 * created with, and once again on every renewal invoice. They are allowed to
 * differ (that is the entire point of `SinglePayment` vs `Recurring`), but only
 * in the one way that was intended. So each case here starts at
 * `StartSubscriptionCheckout`, pays the first payment through the webhook, asserts
 * the invoice AND the recurring amount handed to Mollie, then renews at exactly
 * that amount and asserts the invoice again.
 *
 * Feeding the captured Mollie amount back in as the renewal gross is what makes
 * this adversarial: the renewal reconciles its lines against the money that
 * actually cleared, so a wrong recurring amount cannot hide — our expected net
 * and Mollie's charge would disagree and the assertion fails.
 *
 * Plan `pro`: 2000 net monthly, 19% DE VAT → 2380 gross. `protrial` adds a
 * 14-day trial on the same prices.
 */
class SignupMatrixFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId]
            ?? throw new RuntimeException("No payment stub for {$paymentId}.");
    }
}

/** What the package told Mollie, recorded off the typed request objects. */
class SignupMollieRecorder
{
    /** @var array<int, array<string, mixed>> */
    public static array $payments = [];

    /** @var array<int, array<string, mixed>> */
    public static array $subscriptions = [];

    /** Runs while Mollie "creates" a subscription — e.g. to let the clock pass midnight. */
    public static ?\Closure $onCreateSubscription = null;

    public static function reset(): void
    {
        self::$payments = [];
        self::$subscriptions = [];
        self::$onCreateSubscription = null;
    }

    public static function install(): void
    {
        self::reset();

        Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
            if ($request instanceof CreatePaymentRequest) {
                $id = 'tr_signup_'.(count(self::$payments) + 1);
                self::$payments[] = [
                    'id' => $id,
                    'amount' => signupProp($request, 'amount')->value,
                    'metadata' => signupProp($request, 'metadata'),
                    'sequence_type' => signupProp($request, 'sequenceType'),
                ];

                return (object) ['id' => $id];
            }

            if ($request instanceof CreateSubscriptionRequest) {
                if (self::$onCreateSubscription !== null) {
                    (self::$onCreateSubscription)();
                }

                $id = 'sub_signup_'.(count(self::$subscriptions) + 1);
                self::$subscriptions[] = [
                    'id' => $id,
                    'amount' => signupProp($request, 'amount')->value,
                    'start_date' => (string) signupProp($request, 'startDate'),
                ];

                return (object) ['id' => $id];
            }

            return (object) ['id' => 'unexpected'];
        });
    }

    /** @return array<string, mixed> */
    public static function lastPayment(): array
    {
        return self::$payments[count(self::$payments) - 1] ?? [];
    }

    /** @return array<string, mixed> */
    public static function lastSubscription(): array
    {
        return self::$subscriptions[count(self::$subscriptions) - 1] ?? [];
    }
}

/** Mollie's request objects keep their payload in protected properties. */
function signupProp(object $request, string $name): mixed
{
    return (new ReflectionObject($request))->getProperty($name)->getValue($request);
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    SignupMatrixFakeWebhookController::$payments = [];
    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieWebhookController::class, SignupMatrixFakeWebhookController::class);
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    $intervals = [
        'monthly' => [
            'base_price_net' => 2000,
            'seat_price_net' => 500,
            'included_usages' => ['Tokens' => 100],
            'usage_overage_prices' => ['Tokens' => 10],
        ],
        'yearly' => [
            'base_price_net' => 20000,
            'seat_price_net' => 5000,
            'included_usages' => ['Tokens' => 1200],
            'usage_overage_prices' => ['Tokens' => 10],
        ],
    ];

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['reports'],
        'allowed_addons' => [],
        'intervals' => $intervals,
    ]);

    // Same prices, plus a trial — the trial branch of checkout is a different
    // code path (mandate-only) and prices its coupon at the FIRST CHARGE, not
    // at signup.
    $trialIntervals = $intervals;
    $trialIntervals['monthly']['trial_days'] = 14;
    $trialIntervals['yearly']['trial_days'] = 14;

    config()->set('mollie-billing-plans.plans.protrial', [
        'name' => 'Pro Trial',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['reports'],
        'allowed_addons' => [],
        'intervals' => $trialIntervals,
    ]);

    config()->set('mollie-billing-plans.features', [
        'reports' => ['name' => 'Reports', 'description' => null],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    SignupMollieRecorder::install();
});

/** A brand-new billable: no subscription, a Mollie customer, no mandate yet. */
function signupBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Signup Co',
        'email' => uniqid('signup-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(['mollie_customer_id' => 'cst_signup'])->save();

    return $b->refresh();
}

/**
 * Run the checkout the way the Livewire component does: net minus the coupon's
 * discount, plus VAT, handed over as `amount_gross`.
 *
 * @param  array<int, string>  $addonCodes
 */
function startSignup(
    TestBillable $billable,
    ?string $couponCode = null,
    string $planCode = 'pro',
    string $interval = 'monthly',
    int $extraSeats = 0,
    array $addonCodes = [],
): array {
    $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
    $seats = $catalog->includedSeats($planCode) + $extraSeats;
    $net = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
        $catalog, $billable, $planCode, $interval, $seats, $addonCodes
    );

    $discount = 0;
    if ($couponCode !== null) {
        $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($couponCode)])->first();
        if ($coupon !== null) {
            $discount = app(CouponService::class)->computeRecurringDiscount($coupon, $net);
        }
    }

    $vat = app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class)
        ->calculate('DE', max(0, $net - $discount), $billable);

    return app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => $planCode,
        'interval' => $interval,
        'addon_codes' => $addonCodes,
        'extra_seats' => $extraSeats,
        'amount_gross' => (int) $vat['gross'],
        'coupon_code' => $couponCode,
    ]);
}

/**
 * Settle the checkout payment the package created, with the mandate Mollie mints
 * on a successful first payment. The metadata is the metadata the service wrote —
 * not a hand-rolled copy of it.
 */
function paySignup(): void
{
    $payment = SignupMollieRecorder::lastPayment();

    SignupMatrixFakeWebhookController::$payments[$payment['id']] = (object) [
        'id' => $payment['id'],
        'status' => 'paid',
        'amount' => (object) ['value' => $payment['amount'], 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_signup',
        'mandateId' => 'mdt_signup',
        'countryCode' => 'DE',
        'metadata' => $payment['metadata'],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $payment['id']])->assertStatus(200);
}

/**
 * A renewal Mollie generated for the subscription the signup created, charged at
 * the amount the signup told Mollie to charge.
 */
function renewSignup(TestBillable $billable, string $paymentId, ?string $gross = null, ?string $paidAt = null): BillingInvoice
{
    $subscriptionId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_missing');

    SignupMatrixFakeWebhookController::$payments[$paymentId] = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross ?? SignupMollieRecorder::lastSubscription()['amount'], 'currency' => 'EUR'],
        'paidAt' => $paidAt ?? BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => $subscriptionId,
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
function signupCouponLineNet(BillingInvoice $invoice): ?int
{
    $line = collect($invoice->line_items)->firstWhere('kind', 'coupon');

    return $line === null ? null : (int) ($line['total_net'] ?? $line['amount_net'] ?? 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// SinglePayment at signup — one discounted payment, then full price forever.
// ─────────────────────────────────────────────────────────────────────────────

it('SinglePayment at signup: discounts the first payment only', function (DiscountType $type, int $value, int $firstNet, string $firstGross): void {
    app(CouponService::class)->create([
        'code' => 'WELCOME',
        'name' => 'Welcome discount',
        'type' => CouponType::SinglePayment,
        'discount_type' => $type,
        'discount_value' => $value,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'WELCOME');

    // The checkout charged the discounted gross…
    expect(SignupMollieRecorder::lastPayment()['amount'])->toBe($firstGross)
        ->and(SignupMollieRecorder::lastPayment()['sequence_type'])->toBe('first');

    paySignup();

    $first = BillingInvoice::query()->where('mollie_payment_id', SignupMollieRecorder::lastPayment()['id'])->sole();

    expect((int) $first->amount_net)->toBe($firstNet)
        ->and(signupCouponLineNet($first))->toBe(-($value === 100 ? 2000 : 2000 - $firstNet))
        ->and(CouponRedemption::query()->count())->toBe(1);

    // …while Mollie's subscription runs at the FULL price: a SinglePayment coupon
    // that leaked into the recurring amount would discount every period forever.
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80');

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    $renewal = renewSignup($billable, 'tr_signup_renewal');

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and((int) $renewal->amount_gross)->toBe(2380)
        ->and(signupCouponLineNet($renewal))->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(1);
})->with([
    'percentage 25%' => [DiscountType::Percentage, 25, 1500, '17.85'],
    'fixed 10.00' => [DiscountType::Fixed, 1000, 1000, '11.90'],
]);

it('SinglePayment at 100% at signup: rides the mandate-only path and renews at full price', function (): void {
    app(CouponService::class)->create([
        'code' => 'FIRSTFREE',
        'name' => 'First month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'FIRSTFREE');

    // Mollie rejects a 0-EUR subscription charge, so a fully covered first period
    // is collected as a 0-EUR mandate authorisation instead.
    expect(SignupMollieRecorder::lastPayment()['amount'])->toBe('0.00')
        ->and(SignupMollieRecorder::lastPayment()['metadata']['type'])->toBe('mandate_only')
        ->and(SignupMollieRecorder::lastPayment()['metadata']['pending_subscription_coupon_code'])->toBe('FIRSTFREE');

    paySignup();

    $first = BillingInvoice::query()->where('mollie_payment_id', SignupMollieRecorder::lastPayment()['id'])->sole();

    // A zero invoice, but a real one: the free period still has to be documented.
    expect((int) $first->amount_net)->toBe(0)
        ->and((int) $first->amount_gross)->toBe(0)
        ->and(signupCouponLineNet($first))->toBe(-2000);

    // Full price at Mollie, first charge one period out — the customer got period
    // one free, not a free subscription.
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe(BillingTime::nowUtc()->addMonth()->toDateString());

    expect($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    $renewal = renewSignup($billable, 'tr_signup_renewal');

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and((int) $renewal->amount_gross)->toBe(2380)
        ->and(signupCouponLineNet($renewal))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Recurring at signup — the discount has to reach Mollie, or the renewal breaks.
// ─────────────────────────────────────────────────────────────────────────────

it('Recurring at signup: discounts the first payment AND the renewal at the same amount', function (): void {
    app(CouponService::class)->create([
        'code' => 'ALWAYS25',
        'name' => 'A quarter off',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'ALWAYS25');
    expect(SignupMollieRecorder::lastPayment()['amount'])->toBe('17.85');

    paySignup();

    $first = BillingInvoice::query()->where('mollie_payment_id', SignupMollieRecorder::lastPayment()['id'])->sole();
    expect((int) $first->amount_net)->toBe(1500)
        ->and(signupCouponLineNet($first))->toBe(-500);

    // The discount must reach Mollie: the renewal is charged by Mollie, not by us,
    // so a full-price subscription here would silently overcharge every period.
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85');

    $marker = $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('ALWAYS25')
        ->and((int) $marker['base_amount_net'])->toBe(2000);

    // Renewed at the amount Mollie was told to charge — our invoice must agree.
    $renewal = renewSignup($billable, 'tr_signup_renewal');

    expect((int) $renewal->amount_net)->toBe(1500)
        ->and((int) $renewal->amount_gross)->toBe(1785)
        ->and(signupCouponLineNet($renewal))->toBe(-500)
        ->and(CouponRedemption::query()->count())->toBe(2);
});

it('Recurring at signup: charges full price once the coupon window has closed', function (): void {
    app(CouponService::class)->create([
        'code' => 'FIRST2',
        'name' => 'Two periods off',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 500,
        'max_redemptions_per_billable' => 2,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'FIRST2');
    paySignup();

    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85');

    // Period two, still inside the window (`valid_until` = 2 × 30 + 1 days).
    $this->travel(31)->days();
    $second = renewSignup($billable, 'tr_signup_period2');

    expect((int) $second->amount_net)->toBe(1500)
        ->and(signupCouponLineNet($second))->toBe(-500);

    // Period three arrives after the window. Mollie still charges the discounted
    // amount — it can only be PATCHed once we have SEEN the expiry, and this charge
    // is what shows it to us. The invoice books the money that actually cleared,
    // through an adjustment rather than a coupon line, and the reset PATCH goes out
    // so the NEXT charge is full price.
    $this->travel(31)->days();
    $third = renewSignup($billable, 'tr_signup_expired');

    expect((int) $third->amount_gross)->toBe(1785)
        ->and(signupCouponLineNet($third))->toBeNull()
        ->and(collect($third->line_items)->firstWhere('kind', 'adjustment'))->not->toBeNull()
        ->and($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    $reset = collect(SpyMollieSubscriptionPatcher::$calls)
        ->firstWhere(fn (array $call): bool => $call[0] === 'update' && ($call[3]['coupon_discount_net'] ?? null) === 0);
    expect($reset)->not->toBeNull();

    // Period four: the PATCH has landed, so Mollie collects the full price and the
    // invoice needs no adjustment at all.
    $this->travel(31)->days();
    $fourth = renewSignup($billable, 'tr_signup_full_again', '23.80');

    expect((int) $fourth->amount_net)->toBe(2000)
        ->and((int) $fourth->amount_gross)->toBe(2380)
        ->and(signupCouponLineNet($fourth))->toBeNull()
        ->and(collect($fourth->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();
});

it('Recurring at 100% at signup: defers the first Mollie charge past the coupon, then charges full price', function (): void {
    app(CouponService::class)->create([
        'code' => 'FREE3M',
        'name' => 'Three months free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'FREE3M');
    expect(SignupMollieRecorder::lastPayment()['amount'])->toBe('0.00');

    paySignup();

    // Full price (Mollie rejects 0), but not charged until the coupon is spent:
    // 3 × 30 + 1 days of validity, + 1 day.
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe(BillingTime::nowUtc()->addDays(92)->toDateString());

    // The charge Mollie eventually makes lands after the window — full price.
    $this->travel(93)->days();

    $renewal = renewSignup($billable, 'tr_signup_after_free');

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and((int) $renewal->amount_gross)->toBe(2380)
        ->and(signupCouponLineNet($renewal))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Trial signups — the coupon discounts the FIRST CHARGE, the one at the trial's end.
// ─────────────────────────────────────────────────────────────────────────────

it('SinglePayment on a trial signup: discounts the charge that ends the trial, not the one after', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYHALF',
        'name' => 'Half off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYHALF', 'protrial');

    expect(SignupMollieRecorder::lastPayment()['amount'])->toBe('0.00')
        ->and(SignupMollieRecorder::lastPayment()['metadata']['pending_subscription_trial_days'])->toBe(14);

    paySignup();

    $billable->refresh();
    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    // Nothing is charged during a trial. The grant is counted (a row without a discount,
    // like a recurring code's on a trial) and the discount waits for the charge it is for.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())->toBe(BillingTime::nowUtc()->addDays(14)->toDateString())
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and((int) CouponRedemption::query()->sole()->discount_amount_net)->toBe(0)
        ->and($marker)->not->toBeNull()
        ->and($marker['first_charge_only'] ?? null)->toBeTrue()
        ->and($marker['discount_periods'] ?? null)->toBe(1)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_first_charge_coupon');

    // Mollie's first charge is the one at the trial's end, and it is what Mollie holds as
    // the recurring amount — so that amount is the discounted one. Created at the full
    // price, the trial-end charge collected 23.80 and the code was never honoured.
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('11.90')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe(BillingTime::nowUtc()->addDays(14)->toDateString());

    $this->travel(14)->days();
    $converting = renewSignup($billable, 'tr_signup_trial_end');

    $redemption = CouponRedemption::query()->latest('id')->first();

    expect(signupCouponLineNet($converting))->toBe(-1000)
        ->and((int) $converting->amount_net)->toBe(1000)
        ->and((int) $converting->amount_gross)->toBe(1190)
        ->and(CouponRedemption::query()->count())->toBe(2)
        ->and((int) $redemption->discount_amount_net)->toBe(1000)
        ->and((int) $redemption->invoice_id)->toBe((int) $converting->id)
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        // The one application is spent: the marker is gone…
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    // …and Mollie was PATCHed back to the full price by the same renewal.
    $reset = collect(SpyMollieSubscriptionPatcher::$calls)->last(fn (array $c): bool => $c[0] === 'update');
    expect($reset)->not->toBeNull()
        ->and($reset[3]['coupon_discount_net'])->toBe(0);

    $this->travel(1)->months();
    $renewal = renewSignup($billable, 'tr_signup_after_trial', '23.80');

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($renewal))->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(2);
});

it('SinglePayment at 100% on a trial signup: the period after the trial is free, then full price', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE', 'protrial');
    paySignup();

    // Mollie cannot hold an amount of 0: full price, and the first charge one period after
    // the trial — the charge the coupon covers is never made.
    $firstCharge = BillingTime::nowUtc()->addDays(14)->addMonthsNoOverflow(1);

    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])->toBe($firstCharge->toDateString())
        ->and(CouponRedemption::query()->count())->toBe(1);

    // What the customer is shown is the charge they bought the code for: the one at the
    // trial's end, on that day, at 0.00 — not "first charge a month later at full price",
    // which is only how Mollie is made to skip it.
    $trialEnd = BillingTime::nowUtc()->addDays(14);
    $shown = app(NextChargeSummary::class)->for($billable->refresh());

    expect($shown['state'])->toBe(NextChargeSummary::STATE_SCHEDULED)
        ->and($shown['date']?->toDateString())->toBe($trialEnd->toDateString())
        ->and($shown['discount_net'])->toBe(2000)
        ->and($shown['discount_code'])->toBe('TRYFREE')
        ->and($shown['gross'])->toBe(0)
        ->and($shown['deferred'])->toBeFalse();

    // Once that charge has passed, the next one is the deferred full-price charge.
    $this->travelTo($trialEnd->copy()->addDay()->setTime(7, 0));
    (new ProcessTrialLifecycleJob)->handle();
    $after = app(NextChargeSummary::class)->for($billable->refresh());

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($after['date']?->toDateString())->toBe($firstCharge->toDateString())
        ->and($after['discount_net'])->toBe(0)
        ->and($after['gross'])->toBe(2380);

    $this->travelTo($firstCharge->copy()->setTime(7, 0));
    $charge = renewSignup($billable, 'tr_signup_trial_free_period_end', '23.80');

    expect((int) $charge->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($charge))->toBeNull()
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('SinglePayment on a trial signup: is honoured after the code itself expired or was switched off', function (string $change): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'TRYLATE',
        'name' => 'A quarter off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'valid_until' => BillingTime::nowUtc()->addDays(3),
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYLATE', 'protrial');
    paySignup();

    // The last day to redeem the code is not the day its discount ends: the customer
    // redeemed it at signup, and the trial merely postpones the charge it discounts.
    if ($change === 'deactivated') {
        app(CouponService::class)->deactivate($coupon);
    }

    $this->travel(14)->days();
    $converting = renewSignup($billable, 'tr_signup_trial_late');

    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85')
        ->and(signupCouponLineNet($converting))->toBe(-500)
        ->and((int) $converting->amount_net)->toBe(1500);
})->with(['expired' => ['expired'], 'deactivated' => ['deactivated']]);

it('SinglePayment at 100% on a trial signup: switching the code off does not take the free charge back', function (): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'TRYFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE', 'protrial');
    paySignup();

    $trialEnd = BillingTime::nowUtc()->addDays(14);
    $firstCharge = $trialEnd->copy()->addMonthsNoOverflow(1);

    app(CouponService::class)->deactivate($coupon);
    SpyMollieSubscriptionPatcher::$calls = [];

    // The nightly pass ends the deferral of a fully covering RECURRING code that has been
    // switched off. A first-payment code was redeemed and shown at signup; switching it off
    // stops new redemptions and must not pull the full-price charge onto the trial's end.
    $this->travel(1)->days();
    PrepareUsageOverageJob::dispatchSync();

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon')
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($firstCharge->toDateString())
        ->and(collect(SpyMollieSubscriptionPatcher::$calls)->where(0, 'update')->all())->toBe([]);

    $shown = app(NextChargeSummary::class)->for($billable);
    expect($shown['date']?->toDateString())->toBe($trialEnd->toDateString())
        ->and($shown['gross'])->toBe(0);
});

it('SinglePayment on a trial signup: a trial extended past the window of the code still gets the discount', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYQUARTER',
        'name' => 'A quarter off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYQUARTER', 'protrial');
    paySignup();

    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85');

    // Well past the window the code was given for the original trial end (trial end + one
    // period): the first charge moves with the trial, and so does the discount on it.
    $newTrialEnd = BillingTime::nowUtc()->addDays(60);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $marker = $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    expect($marker)->not->toBeNull()
        ->and(\Carbon\Carbon::parse($marker['valid_until'])->greaterThan($newTrialEnd))->toBeTrue();

    // What Mollie is told on any change before the charge still carries the discount.
    expect(app(CouponService::class)->computeMarkerDiscount($billable, 2000, [], $newTrialEnd))->toBe(500);

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $converting = renewSignup($billable, 'tr_signup_trial_extended', '17.85');

    expect(signupCouponLineNet($converting))->toBe(-500)
        ->and((int) $converting->amount_net)->toBe(1500)
        ->and($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
});

it('SinglePayment at 100% on a trial signup: an extended trial moves the free charge and the paid one after it', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE', 'protrial');
    paySignup();

    $newTrialEnd = BillingTime::nowUtc()->addDays(30);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);
    $billable->refresh();

    // The free charge is the one at the (new) trial end; the first paid one a period after.
    expect(ChargeDeferral::until($billable)?->toDateString())
        ->toBe($newTrialEnd->copy()->addMonthsNoOverflow(1)->toDateString());

    $shown = app(NextChargeSummary::class)->for($billable);
    expect($shown['date']?->toDateString())->toBe($newTrialEnd->toDateString())
        ->and($shown['discount_net'])->toBe(2000)
        ->and($shown['gross'])->toBe(0);
});

it('Recurring on a trial signup: keeps discounting every charge after the trial', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYALWAYS',
        'name' => 'A quarter off forever',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYALWAYS', 'protrial');
    paySignup();

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);

    // The marker exists from the moment the trial starts, so the discounted amount
    // is what Mollie is created with — the trial's first charge is already correct.
    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and((int) $marker['base_amount_net'])->toBe(2000)
        ->and(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85');

    $this->travel(14)->days();
    $first = renewSignup($billable, 'tr_signup_trial_charge');

    expect((int) $first->amount_net)->toBe(1500)
        ->and(signupCouponLineNet($first))->toBe(-500);

    $this->travel(30)->days();
    $second = renewSignup($billable, 'tr_signup_second');

    expect((int) $second->amount_net)->toBe(1500)
        ->and(signupCouponLineNet($second))->toBe(-500);
});

it('Recurring on a trial signup: an extended trial keeps every discounted period', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYTWO',
        'name' => 'A quarter off two charges',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'discount_periods' => 2,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYTWO', 'protrial');
    paySignup();

    // 46 days more: the first charge moves from day 14 to day 60. The two discounted charges
    // are the first two there are — day 60 and a month later — not two months from day 14.
    $newTrialEnd = BillingTime::nowUtc()->addDays(60);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $first = renewSignup($billable, 'tr_signup_two_first', '17.85');

    $this->travelTo($newTrialEnd->copy()->addMonthsNoOverflow(1)->setTime(7, 0));
    $second = renewSignup($billable, 'tr_signup_two_second', '17.85');

    $this->travelTo($newTrialEnd->copy()->addMonthsNoOverflow(2)->setTime(7, 0));
    $third = renewSignup($billable, 'tr_signup_two_third', '23.80');

    expect(signupCouponLineNet($first))->toBe(-500)
        ->and(signupCouponLineNet($second))->toBe(-500)
        ->and((int) $second->amount_net)->toBe(1500)
        ->and(signupCouponLineNet($third))->toBeNull()
        ->and((int) $third->amount_net)->toBe(2000);
});

it('Recurring at 100% on a trial signup: an extended trial moves the free periods and the paid charge after them', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE2',
        'name' => 'Two months free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'discount_periods' => 2,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE2', 'protrial');
    paySignup();

    expect(ChargeDeferral::until($billable->refresh())?->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(14)->addMonthsNoOverflow(2)->toDateString());

    $newTrialEnd = BillingTime::nowUtc()->addDays(30);
    $billable->extendBillingTrialUntil($newTrialEnd);

    expect(ChargeDeferral::until($billable->refresh())?->toDateString())
        ->toBe($newTrialEnd->copy()->addMonthsNoOverflow(2)->toDateString());
});

it('SinglePayment at 100% on a trial signup: an extended trial carries extension days given on top of the deferral', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);
    $extension = app(CouponService::class)->create([
        'code' => 'PLUS10',
        'name' => 'Ten more days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 10,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE', 'protrial');
    paySignup();

    $trialEnd = BillingTime::nowUtc()->addDays(14);

    // Ten days appended to the deferred first paid charge.
    app(CouponService::class)->validate('PLUS10', $billable->refresh(), []);
    app(CouponService::class)->redeem($extension, $billable, []);

    expect(ChargeDeferral::until($billable->refresh())?->toDateString())
        ->toBe($trialEnd->copy()->addMonthsNoOverflow(1)->addDays(10)->toDateString());

    // The trial moves 30 days; the free charge moves with it, and the paid one after it
    // keeps the ten days on top — never inside the free window.
    $newTrialEnd = $trialEnd->copy()->addDays(30);
    $billable->extendBillingTrialUntil($newTrialEnd);

    expect(ChargeDeferral::until($billable->refresh())?->toDateString())
        ->toBe($newTrialEnd->copy()->addMonthsNoOverflow(1)->addDays(10)->toDateString());
});

it('SinglePayment on a trial signup: extending a trial whose first charge failed keeps the discount for it', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYHALF',
        'name' => 'Half off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYHALF', 'protrial');
    paySignup();

    // The charge at the trial's end failed: past due, the discounted charge never collected.
    $this->travel(15)->days();
    $billable->refresh()->forceFill(['subscription_status' => SubscriptionStatus::PastDue])->save();

    // "Another six weeks to add a card": past the window the code had from the old trial end.
    $newTrialEnd = BillingTime::nowUtc()->addDays(45);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $charge = renewSignup($billable, 'tr_signup_after_failed', '11.90');

    expect(signupCouponLineNet($charge))->toBe(-1000)
        ->and((int) $charge->amount_net)->toBe(1000);
});

it('Recurring on a trial signup: a trial extended past the code\'s own end returns Mollie to the full price', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYSHORT',
        'name' => 'A quarter off, until day 20',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'discount_periods' => 3,
        'max_redemptions_per_billable' => 3,
        'valid_until' => BillingTime::nowUtc()->addDays(20),
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYSHORT', 'protrial');
    paySignup();

    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('17.85');

    SpyMollieSubscriptionPatcher::$calls = [];

    // The first charge moves to day 30, after the code has ended: it discounts nothing,
    // so Mollie must not keep collecting the discounted amount.
    $newTrialEnd = BillingTime::nowUtc()->addDays(30);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $reset = collect(SpyMollieSubscriptionPatcher::$calls)->last(fn (array $c): bool => $c[0] === 'update');

    expect($reset)->not->toBeNull()
        ->and($reset[3]['coupon_discount_net'])->toBe(0)
        ->and($billable->refresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $charge = renewSignup($billable, 'tr_signup_short_first', '23.80');

    expect(signupCouponLineNet($charge))->toBeNull()
        ->and((int) $charge->amount_net)->toBe(2000);
});

it('SinglePayment on a trial signup: an activation that crosses midnight still moves with an extended trial', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYQUARTER',
        'name' => 'A quarter off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
    ]);

    $billable = signupBillable();

    // The activation starts a second before midnight and Mollie answers after it.
    $this->travelTo(BillingTime::nowUtc()->setTime(23, 59, 59));
    SignupMollieRecorder::$onCreateSubscription = fn () => $this->travel(2)->seconds();

    startSignup($billable, 'TRYQUARTER', 'protrial');
    paySignup();

    $newTrialEnd = BillingTime::nowUtc()->addDays(60);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $charge = renewSignup($billable, 'tr_signup_midnight', '17.85');

    expect(signupCouponLineNet($charge))->toBe(-500);
});

it('Recurring on a trial signup: an extended trial keeps the window it was granted after the code\'s end date is edited', function (): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'TRYTWO',
        'name' => 'A quarter off two charges',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'discount_periods' => 2,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYTWO', 'protrial');
    paySignup();

    // An end date given to the offer later is a change of the offer, not of what a
    // customer who already holds it was promised.
    $coupon->forceFill(['valid_until' => BillingTime::nowUtc()->addDays(50)])->save();

    $newTrialEnd = BillingTime::nowUtc()->addDays(60);
    $billable->refresh()->extendBillingTrialUntil($newTrialEnd);

    $this->travelTo($newTrialEnd->copy()->setTime(7, 0));
    $first = renewSignup($billable, 'tr_signup_edited_first', '17.85');

    $this->travelTo($newTrialEnd->copy()->addMonthsNoOverflow(1)->setTime(7, 0));
    $second = renewSignup($billable, 'tr_signup_edited_second', '17.85');

    expect(signupCouponLineNet($first))->toBe(-500)
        ->and(signupCouponLineNet($second))->toBe(-500);
});

it('SinglePayment at 100% on a trial signup: the day after the trial shows the paid charge, not the passed free one', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE',
        'name' => 'First paid month free',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'TRYFREE', 'protrial');
    paySignup();

    $trialEnd = BillingTime::nowUtc()->addDays(14);
    $firstCharge = $trialEnd->copy()->addMonthsNoOverflow(1);

    // On the trial's last day the free charge is today's.
    $this->travelTo($trialEnd->copy()->addHours(3));
    $onTheDay = app(NextChargeSummary::class)->for($billable->refresh());

    expect($onTheDay['date']?->toDateString())->toBe($trialEnd->toDateString())
        ->and($onTheDay['gross'])->toBe(0);

    // A day later, before the nightly job has converted the trial: the free charge is
    // behind the customer, the next one is the paid charge.
    $this->travelTo($trialEnd->copy()->addDay()->setTime(1, 0));
    $after = app(NextChargeSummary::class)->for($billable->refresh());

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($after['date']?->toDateString())->toBe($firstCharge->toDateString())
        ->and($after['gross'])->toBe(2380);
});

it('auto-applied Recurring at 100% on a trial signup: waits for the later of the trial and the coupon', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRYFREE3M',
        'name' => 'Trial plus three free months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = signupBillable();

    // A promotion link only puts the code in the session — the checkout form never
    // sees it and submits the FULL gross, which is what makes a trial and a fully
    // covering coupon collide on the same signup. (Entering the same code by hand
    // takes the coupon branch instead: gross 0, no trial, Active from the start.)
    session(['billing.auto_apply_coupon' => 'TRYFREE3M']);

    startSignup($billable, null, 'protrial');
    paySignup();

    expect(SignupMollieRecorder::lastPayment()['metadata']['pending_subscription_coupon_code'])->toBe('TRYFREE3M')
        ->and(SignupMollieRecorder::lastPayment()['metadata']['pending_subscription_trial_days'])->toBe(14);

    // Charging at trial end would collect full price during months the coupon promised
    // free. The three free periods are the subscription's own and start where its first
    // charge would be, at the trial's end: three calendar months on that day (shortened
    // where a month does not have it). Until 17 November 2026 they were counted from the
    // signup in 30-day blocks, `now + 92` days, and the trial ate two weeks of them.
    $firstCharge = BillingTime::nowUtc()->addDays(14)->addMonthsNoOverflow(3);

    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe($firstCharge->toDateString())
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Trial);

    $this->travelTo($firstCharge->copy()->setTime(7, 0));
    $charge = renewSignup($billable, 'tr_signup_trial_free_end', '23.80');

    expect((int) $charge->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($charge))->toBeNull();
});

it('TrialExtension at signup: extends the trial the checkout accepted it for', function (): void {
    app(CouponService::class)->create([
        'code' => 'PLUS7',
        'name' => 'Seven more days',
        'type' => CouponType::TrialExtension,
        'trial_extension_days' => 7,
    ]);

    $billable = signupBillable();

    startSignup($billable, 'PLUS7', 'protrial');
    paySignup();

    $billable->refresh();

    // 14 plan days + 7 coupon days. Accepting the code at checkout and then
    // dropping it silently is the failure this pins: the customer sees the coupon
    // applied and gets nothing for it.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(21)->toDateString())
        ->and(CouponRedemption::query()->count())->toBe(1);

    // Mollie must not charge during the days the coupon bought.
    $target = collect(SpyMollieSubscriptionPatcher::$calls)->firstWhere(fn (array $c): bool => $c[0] === 'set_next_charge_date');
    expect($target)->not->toBeNull()
        ->and(\Illuminate\Support\Carbon::parse($target[3]['target'])->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(21)->toDateString());

    // The charge that ends the extended trial is full price — a TrialExtension
    // buys days, not a discount.
    $this->travel(21)->days();
    $charge = renewSignup($billable, 'tr_signup_extended_end', '23.80');

    expect((int) $charge->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($charge))->toBeNull()
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('TrialExtension on a paid first payment: is not redeemed, and never moves the next charge', function (): void {
    app(CouponService::class)->create([
        'code' => 'PLUS7',
        'name' => 'Seven more days',
        'type' => CouponType::TrialExtension,
        'trial_extension_days' => 7,
    ]);

    // `pro` has no trial, so this checkout charges the full price up front. The
    // coupon has nothing to extend — and applying it anyway would put trial_ends_at
    // a week out on a paying subscriber AND PATCH Mollie to charge again on that
    // date. The plan's own trial_days is what the coupon was validated against, so
    // the guard has to sit at activation, not only in the UI.
    $billable = signupBillable();

    startSignup($billable, 'PLUS7', 'protrial');
    // Simulate the returning-customer case the UI blocks: a paid first payment
    // carrying the code, which is what a hand-built request or a stale session
    // would produce.
    $payment = SignupMollieRecorder::lastPayment();
    SignupMatrixFakeWebhookController::$payments['tr_paid_ext'] = (object) [
        'id' => 'tr_paid_ext',
        'status' => 'paid',
        'amount' => (object) ['value' => '23.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_signup',
        'mandateId' => 'mdt_signup',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'protrial',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            'coupon_code' => 'PLUS7',
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => 'tr_paid_ext'])->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(collect(SpyMollieSubscriptionPatcher::$calls)->firstWhere(fn (array $c): bool => $c[0] === 'set_next_charge_date'))
        ->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override');

    // The paid period is invoiced at full price, undisturbed.
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_paid_ext')->sole();
    expect((int) $invoice->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($invoice))->toBeNull();

    unset($payment);
});

// ─────────────────────────────────────────────────────────────────────────────
// AccessGrant at signup — a subscription without Mollie, and without money.
// ─────────────────────────────────────────────────────────────────────────────

it('AccessGrant at signup: activates locally, charges nothing and never renews', function (): void {
    app(CouponService::class)->create([
        'code' => 'PRESS90',
        'name' => 'Press access',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 90,
    ]);

    $billable = signupBillable();

    $result = startSignup($billable, 'PRESS90');

    // No checkout, because there is nothing to pay and no mandate to collect.
    expect($result['checkout_url'])->toBeNull()
        ->and($result['payment_id'])->toBe('')
        ->and(SignupMollieRecorder::$payments)->toBeEmpty()
        ->and(SignupMollieRecorder::$subscriptions)->toBeEmpty();

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->hasPlanFeature('reports'))->toBeTrue()
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(1);

    // A local grant has no Mollie subscription, so there is no renewal to price —
    // it simply runs out.
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');

    $this->travel(91)->days();
    expect($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();
});

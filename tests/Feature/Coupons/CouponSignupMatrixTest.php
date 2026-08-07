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
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
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

    public static function reset(): void
    {
        self::$payments = [];
        self::$subscriptions = [];
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
// Trial signups — the coupon is stashed and priced at the FIRST CHARGE.
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
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())->toBe(BillingTime::nowUtc()->addDays(14)->toDateString())
        // Nothing is charged and nothing is redeemed during a trial — the coupon
        // waits for the charge it is meant to discount.
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(0);

    // Mollie's first charge is scheduled for the trial's end, at full price…
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe(BillingTime::nowUtc()->addDays(14)->toDateString());

    // …and the stashed coupon is applied when it arrives. Mollie charged the full
    // price, so the discount lands as a credit on the invoice, which is why the
    // gross stays 23.80 while the coupon line is on the document.
    $this->travel(14)->days();
    $converting = renewSignup($billable, 'tr_signup_trial_end', '23.80');

    expect(signupCouponLineNet($converting))->toBe(-1000)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);

    // The next charge is full price — the slot is spent.
    $this->travel(30)->days();
    $renewal = renewSignup($billable, 'tr_signup_after_trial', '23.80');

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and(signupCouponLineNet($renewal))->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(1);
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

    // A 14-day trial ends long before three free months do: charging at trial end
    // would collect full price during months the coupon promised free, and charging
    // only at the coupon's end would be right here but wrong the other way round
    // (a 90-day trial with a one-month coupon).
    expect(SignupMollieRecorder::lastSubscription()['amount'])->toBe('23.80')
        ->and(SignupMollieRecorder::lastSubscription()['start_date'])
        ->toBe(BillingTime::nowUtc()->addDays(92)->toDateString())
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Trial);

    $this->travel(93)->days();
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

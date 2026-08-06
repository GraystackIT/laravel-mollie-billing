<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Every invoice the package books derives its header VAT by summing the VAT of its
 * line items, so the amount collected has to be assembled the same way. VAT on the
 * summed net is a different number: two 550 lines at 19% are 105 + 105 = 210, while
 * 19% of 1100 is 209. One cent hides inside reconcileWithPaidAmount()'s slack; five
 * or six lines do not, and the customer would then be reported as underpaying on
 * every single renewal.
 */
class PerLineVatFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    PerLineVatFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, PerLineVatFakeWebhookController::class);

    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.vat_rate_overrides', ['DE' => 19]);

    // 550 + 550: the smallest configuration where per-line and per-total rounding
    // disagree (105 + 105 = 210 vs 209).
    config()->set('mollie-billing-plans.plans.duo', [
        'name' => 'Duo',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['extra'],
        'intervals' => [
            'monthly' => ['base_price_net' => 550, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'intervals' => ['monthly' => ['price_net' => 550]],
    ]);

    // 1999 + 999 + 999: three lines, residuals of the other sign.
    config()->set('mollie-billing-plans.plans.trio', [
        'name' => 'Trio',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['a1', 'a2'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1999, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.a1', [
        'name' => 'Addon One',
        'intervals' => ['monthly' => ['price_net' => 999]],
    ]);
    config()->set('mollie-billing-plans.addons.a2', [
        'name' => 'Addon Two',
        'intervals' => ['monthly' => ['price_net' => 999]],
    ]);

    // Six 550 lines: the residuals add up to 3 cents, past the one-cent slack
    // reconcileWithPaidAmount() forgives.
    config()->set('mollie-billing-plans.plans.sextet', [
        'name' => 'Sextet',
        'tier' => 4,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['b1', 'b2', 'b3', 'b4', 'b5'],
        'intervals' => [
            'monthly' => ['base_price_net' => 550, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $code) {
        config()->set('mollie-billing-plans.addons.'.$code, [
            'name' => 'Bundle '.$code,
            'intervals' => ['monthly' => ['price_net' => 550]],
        ]);
    }
});

/**
 * @param  array<int, string>  $addonCodes
 */
function perLineVatBillable(string $planCode, array $addonCodes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Per Line',
        'email' => 'perline@example.test',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_pl',
        'mollie_mandate_id' => 'mdt_pl',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $planCode,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'active_addon_codes' => $addonCodes,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_pl'],
        'allows_billing_overage' => true,
    ])->save();

    return $billable->refresh();
}

/** The decimal `value` of the first outbound Mollie amount of the given request type. */
function perLineVatCapturedValue(array $requests, string $requestClass): string
{
    foreach ($requests as $request) {
        if (! $request instanceof $requestClass) {
            continue;
        }

        for ($class = new ReflectionObject($request); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty('amount')) {
                $property = $class->getProperty('amount');
                $property->setAccessible(true);

                return (string) $property->getValue($request)->value;
            }
        }
    }

    throw new RuntimeException("No {$requestClass} with an amount was captured.");
}

it('patches the recurring amount with VAT summed per line', function (): void {
    $billable = perLineVatBillable('duo', ['extra']);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_pl'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'duo',
        interval: 'monthly',
        addons: ['extra'],
        extraSeats: 0,
    );

    // 550 + 550 net, 105 + 105 VAT. 19% of the summed 1100 would be 209 → 13.09.
    expect(perLineVatCapturedValue($captured, MollieUpdateSubscriptionRequest::class))->toBe('13.10');
});

it('collects on renewal exactly what the renewal invoice books', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = perLineVatBillable('duo', ['extra']);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_pl'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'duo',
        interval: 'monthly',
        addons: ['extra'],
        extraSeats: 0,
    );

    $patchedValue = perLineVatCapturedValue($captured, MollieUpdateSubscriptionRequest::class);

    // Mollie now charges the patched amount, period after period.
    PerLineVatFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_renewal_perline',
        'status' => 'paid',
        'amount' => (object) ['value' => $patchedValue, 'currency' => 'EUR'],
        'subscriptionId' => 'sub_pl',
        'customerId' => 'cst_pl',
        'mandateId' => 'mdt_pl',
        'countryCode' => 'DE',
        'paidAt' => now()->toIso8601String(),
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_renewal_perline'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_renewal_perline')->first();

    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(1100)
        ->and((int) $invoice->amount_vat)->toBe(210)
        // The whole point: the booked gross is the amount that was collected.
        ->and((int) $invoice->amount_gross)->toBe((int) round(((float) $patchedValue) * 100));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('keeps collected and booked in step once the residuals exceed the reconcile slack', function (): void {
    $addons = ['b1', 'b2', 'b3', 'b4', 'b5'];
    $billable = perLineVatBillable('sextet', $addons);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_pl'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'sextet',
        interval: 'monthly',
        addons: $addons,
        extraSeats: 0,
    );

    // Six 550 lines: 6 × 105 = 630 VAT → 3930. 19% of the summed 3300 is 627 →
    // 3927, three cents short of what the invoice books.
    $patchedValue = perLineVatCapturedValue($captured, MollieUpdateSubscriptionRequest::class);
    expect($patchedValue)->toBe('39.30');

    PerLineVatFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_renewal_sextet',
        'status' => 'paid',
        'amount' => (object) ['value' => $patchedValue, 'currency' => 'EUR'],
        'subscriptionId' => 'sub_pl',
        'customerId' => 'cst_pl',
        'mandateId' => 'mdt_pl',
        'countryCode' => 'DE',
        'paidAt' => now()->toIso8601String(),
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_renewal_sextet'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_renewal_sextet')->first();

    // No event assertion here on purpose: SubscriptionPaymentHandler still
    // compares the paid amount against VAT on the *total* (3927), so it reports a
    // mismatch the books do not have. Making that guard per-line is the matching
    // change on the webhook side; what this test pins is that the money collected
    // and the money booked no longer drift apart to begin with.
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(3300)
        ->and((int) $invoice->amount_vat)->toBe(630)
        ->and((int) $invoice->amount_gross)->toBe(3930);
});

it('creates the Mollie subscription with VAT summed per line', function (): void {
    $billable = perLineVatBillable('trio', ['a1', 'a2']);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_trio'];
    });

    app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'trio',
        'interval' => 'monthly',
        'addon_codes' => ['a1', 'a2'],
    ]);

    // 1999 + 999 + 999 net = 3997; per line 380 + 190 + 190 = 760 → 4757.
    // 19% of 3997 is 759 → 47.56, a cent below what the invoice books.
    expect(perLineVatCapturedValue($captured, CreateSubscriptionRequest::class))->toBe('47.57');
});

it('keeps a discount line rounding on its own in the recurring amount', function (): void {
    $billable = perLineVatBillable('duo', ['extra']);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_pl'];
    });

    // 1100 catalog net − 550 discount. The renewal invoice books the discount as
    // its own negative line, so the charge has to round it as its own line too:
    // (550 + 105) + (550 + 105) − (550 + 105) = 655.
    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'duo',
        interval: 'monthly',
        addons: ['extra'],
        extraSeats: 0,
        couponDiscountNet: 550,
    );

    expect(perLineVatCapturedValue($captured, MollieUpdateSubscriptionRequest::class))->toBe('6.55');
});

it('charges usage overage with VAT summed per wallet line', function (): void {
    $billable = perLineVatBillable('duo', ['extra']);
    config()->set('mollie-billing-plans.plans.duo.intervals.monthly.usage_overage_prices', [
        'Tokens' => 5,
        'Sms' => 5,
    ]);

    // 110 units over quota × 5 = 550 per wallet, twice.
    $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens'])->forceWithdraw(110);
    $billable->createWallet(['name' => 'Sms', 'slug' => 'Sms'])->forceWithdraw(110);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'tr_overage_perline', 'status' => 'open'];
    });

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect(perLineVatCapturedValue($captured, CreatePaymentRequest::class))->toBe('13.10')
        ->and($result->amountGross)->toBe(1310);

    // The snapshot the webhook and every retry read must carry the same numbers,
    // otherwise a retry would collect a different amount than the first attempt.
    $pending = app(ChargeUsageOverageDirectly::class)->pendingCharge($billable->refresh());
    expect((int) $pending['amount_gross'])->toBe(1310)
        ->and((int) $pending['vat_amount'])->toBe(210);
});

it('charges a discounted one-time order with VAT summed per line', function (): void {
    config()->set('mollie-billing-plans.products', [
        'pack' => ['name' => 'Pack', 'price_net' => 1000, 'usage_type' => 'Tokens', 'quantity' => 100],
    ]);

    app(CouponService::class)->create([
        'code' => 'FIVEFIFTY',
        'name' => '550 off',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 550,
    ]);

    $billable = perLineVatBillable('duo');
    request()->setLaravelSession(app('session.store'));

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'tr_order_perline', 'status' => 'open'];
    });

    app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'pack',
        'coupon_code' => 'FIVEFIFTY',
    ]);

    // OneTimeOrderHandler books 1000 and −550 as two lines: 1190 − 655 = 535.
    // 19% of the netted 450 rounds to 86 → 5.36, a cent no line accounts for.
    expect(perLineVatCapturedValue($captured, CreatePaymentRequest::class))->toBe('5.35');
});

it('leaves a reverse-charged amount at the plain sum of the nets', function (): void {
    $billable = perLineVatBillable('duo', ['extra']);
    config()->set('mollie-billing.vat_rate_overrides', ['DE' => 0]);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'sub_pl'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'duo',
        interval: 'monthly',
        addons: ['extra'],
        extraSeats: 0,
    );

    expect(perLineVatCapturedValue($captured, MollieUpdateSubscriptionRequest::class))->toBe('11.00');
});

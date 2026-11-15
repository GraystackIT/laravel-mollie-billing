<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The scenario the plan waiver exists for: a tenant is GIVEN the product, then buys
 * paid addons and seats. The extras are charged, the plan stays free for as long as the
 * gift runs, and the gift can be extended afterwards.
 */

class GrantExtrasFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    /** @var list<object> every request sent to Mollie after the purchase was started */
    public static array $sent = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    GrantExtrasFakeWebhookController::$payments = [];
    GrantExtrasFakeWebhookController::$sent = [];
    $this->app->bind(MollieWebhookController::class, GrantExtrasFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'storage'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);
    config()->set('mollie-billing-plans.addons.storage', [
        'name' => 'Storage',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 900]],
    ]);
});

/** A tenant who was given Pro for $days days. */
function giftedTenant(int $days = 90, array $grantedAddons = [], ?string $onExpiry = null): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gifted Co', 'email' => uniqid('gift-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(['mollie_customer_id' => 'cst_gift'])->save();

    $service = app(CouponService::class);
    $code = 'GRANT-'.strtoupper(bin2hex(random_bytes(3)));
    $coupon = $service->accessGrantCoupon($code, 'pro', 'monthly', $grantedAddons, $days, $onExpiry);
    $service->validate($code, $billable->fresh(), []);
    $service->redeem($coupon, $billable->fresh(), []);

    return $billable->fresh();
}

/**
 * Start the purchase and return the payment request Mollie received.
 *
 * @return array{request: CreatePaymentRequest, result: array<string, mixed>}
 */
function startExtrasPurchase(TestBillable $billable, array $addons, int $seats): array
{
    $captured = null;
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'tr_extras'];
    });

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_gift');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, $addons, $seats);

    return ['request' => $captured, 'result' => $result];
}

function requestProperty(object $request, string $name): mixed
{
    $p = (new ReflectionClass($request))->getProperty($name);
    $p->setAccessible(true);

    return $p->getValue($request);
}

/**
 * Deliver the paid first payment for the purchase. Returns every request sent to Mollie.
 *
 * @return list<object>
 */
function settleExtrasPurchase(TestBillable $billable, CreatePaymentRequest $payment, string $paymentId = 'tr_extras'): array
{
    // One expectation for the rest of the test: Mockery answers with the first that
    // matches, so a second `shouldReceive` further down would never see a request.
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        GrantExtrasFakeWebhookController::$sent[] = $request;

        return (object) ['id' => 'sub_gift'];
    });

    GrantExtrasFakeWebhookController::$payments[$paymentId] = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => requestProperty($payment, 'amount')->value, 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_gift',
        'mandateId' => 'mdt_gift',
        'countryCode' => 'AT',
        'metadata' => requestProperty($payment, 'metadata'),
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);

    return GrantExtrasFakeWebhookController::$sent;
}

/** A recurring payment Mollie generated for the subscription. */
function deliverGiftRenewal(TestBillable $billable, string $paymentId, string $gross): void
{
    GrantExtrasFakeWebhookController::$payments[$paymentId] = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_gift',
        'countryCode' => 'AT',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

it('charges only the addon and the extra seat when a gifted tenant buys them', function (): void {
    $billable = giftedTenant();

    // Pro 50.00 is the gift. Bought on top: SSO 15.00 and one extra seat 5.00.
    ['request' => $payment, 'result' => $result] = startExtrasPurchase($billable, ['sso'], 3);

    expect($result['amount_net'])->toBe(2000)
        ->and(requestProperty($payment, 'amount')->value)->toBe('24.00')
        ->and(requestProperty($payment, 'metadata')['keep_grant'])->toBeTrue();
});

it('refuses a purchase that has nothing to pay for', function (): void {
    $billable = giftedTenant();

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);
    Mollie::shouldReceive('send')->never();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, [], 2);
})->throws(InvalidArgumentException::class);

it('keeps the gift running on the Mollie subscription: same end date, plan free, extras paid', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = giftedTenant(days: 90);
    $giftEnds = $billable->getBillingSubscriptionEndsAt();

    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 3);
    $sent = settleExtrasPurchase($billable, $payment);

    $billable->refresh();

    // A real Mollie subscription now, and the gift is still there.
    expect($billable->getBillingSubscriptionSource())->toBe('mollie')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['sso'])
        ->and($billable->getBillingSeatCount())->toBe(3);

    $marker = app(PlanWaiver::class)->marker($billable);
    expect($marker)->not->toBeNull()
        ->and(Carbon::parse((string) $marker['valid_until'])->toDateString())->toBe($giftEnds->toDateString())
        ->and($marker['on_expiry'])->toBe('end');

    // Mollie collects the extras every period: 20.00 net + 20 %.
    $subscription = collect($sent)->first(fn ($r) => $r instanceof CreateSubscriptionRequest);
    expect($subscription)->not->toBeNull()
        ->and(requestProperty($subscription, 'amount')->value)->toBe('24.00');

    // The invoice shows the plan and takes it off again.
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_extras')->sole();
    $lines = collect($invoice->line_items);

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and((int) $invoice->amount_gross)->toBe(2400)
        ->and((int) $lines->firstWhere('kind', 'plan')['total_net'])->toBe(5000)
        ->and((int) $lines->firstWhere('kind', 'coupon')['total_net'])->toBe(-5000)
        ->and($lines->firstWhere('kind', 'adjustment'))->toBeNull();

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('waives the plan again at the renewal and charges the extras', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = giftedTenant(days: 90);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 3);
    settleExtrasPurchase($billable, $payment);

    Carbon::setTestNow(BillingTime::nowUtc()->addMonthNoOverflow());
    deliverGiftRenewal($billable->fresh(), 'tr_gift_renew_1', '24.00');
    Carbon::setTestNow();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_gift_renew_1')->sole();
    $lines = collect($invoice->line_items);

    expect((int) $invoice->amount_net)->toBe(2000)
        ->and((int) $lines->firstWhere('kind', 'coupon')['total_net'])->toBe(-5000)
        ->and($lines->firstWhere('kind', 'adjustment'))->toBeNull();

    // Still a running, uncancelled subscription: the gift reaches the next charge.
    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('ends the subscription with the gift by default, instead of starting to charge the plan', function (): void {
    // 45 days: the first renewal (day ~30) is covered, the second (day ~60) is not.
    $billable = giftedTenant(days: 45);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 2);
    settleExtrasPurchase($billable, $payment);

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    GrantExtrasFakeWebhookController::$sent = [];

    Carbon::setTestNow(BillingTime::nowUtc()->addMonthNoOverflow());
    deliverGiftRenewal($billable->fresh(), 'tr_gift_last', '18.00');
    $sent = GrantExtrasFakeWebhookController::$sent;

    $billable->refresh();
    $now = BillingTime::nowUtc();
    Carbon::setTestNow();

    // The period just paid for is the last one: cancelled at Mollie, access until it ends.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->greaterThan($now->copy()->addDays(25)))->toBeTrue()
        ->and(collect($sent)->contains(fn ($r) => $r instanceof CancelSubscriptionRequest))->toBeTrue()
        // …and Mollie was never told to collect the full plan price.
        ->and(collect($sent)->contains(fn ($r) => $r instanceof UpdateSubscriptionRequest))->toBeFalse();
});

it('makes the plan chargeable instead when the gift was given to continue as paid', function (): void {
    $billable = giftedTenant(days: 45, onExpiry: PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 2);
    settleExtrasPurchase($billable, $payment);

    GrantExtrasFakeWebhookController::$sent = [];

    Carbon::setTestNow(BillingTime::nowUtc()->addMonthNoOverflow());
    deliverGiftRenewal($billable->fresh(), 'tr_gift_cont', '18.00');
    $sent = GrantExtrasFakeWebhookController::$sent;
    Carbon::setTestNow();

    $billable->refresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(app(PlanWaiver::class)->marker($billable))->toBeNull();

    // From the next charge on: plan 50.00 + SSO 15.00, plus 20 %.
    $patch = collect($sent)->first(fn ($r) => $r instanceof UpdateSubscriptionRequest);
    expect($patch)->not->toBeNull()
        ->and(requestProperty($patch, 'amount')->value)->toBe('78.00');
});

it('keeps an addon the gift included free, and charges one bought on top', function (): void {
    $billable = giftedTenant(days: 90, grantedAddons: ['sso']);

    ['request' => $payment, 'result' => $result] = startExtrasPurchase($billable, ['sso', 'storage'], 2);

    // Pro 50.00 and SSO 15.00 are the gift; Storage 9.00 is bought.
    expect($result['amount_net'])->toBe(900);

    settleExtrasPurchase($billable, $payment);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_extras')->sole();

    expect((int) $invoice->amount_net)->toBe(900)
        ->and((int) collect($invoice->line_items)->firstWhere('kind', 'coupon')['total_net'])->toBe(-6500);
});

it('credits a paid addon in full and the free plan not at all when things change mid-period', function (): void {
    $billable = giftedTenant(days: 90);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 3);
    settleExtrasPurchase($billable, $payment);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_extras')->sole();
    $lines = collect($invoice->line_items);

    // The waiver line says what it takes off.
    expect($lines->firstWhere('kind', 'coupon')['applies_to'])->toBe(['plan']);

    $composer = app(\GraystackIT\MollieBilling\Services\Billing\ProrataComposer::class);
    $paid = new ReflectionMethod($composer, 'paidLineNet');
    $paid->setAccessible(true);

    // Plan 50.00 was free; SSO 15.00 and the seat 5.00 were paid in full. Spread like an
    // ordinary coupon, the addon would count as 4.29 paid and the plan as 14.29.
    expect($paid->invoke($composer, $invoice, $lines->firstWhere('kind', 'plan')))->toBe(0)
        ->and($paid->invoke($composer, $invoice, $lines->firstWhere('kind', 'addon')))->toBe(1500)
        ->and($paid->invoke($composer, $invoice, $lines->firstWhere('kind', 'seats')))->toBe(500);
});

it('takes the waiver away on revoke: the plan is charged from the next regular date', function (): void {
    $billable = giftedTenant(days: 90);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 2);
    settleExtrasPurchase($billable, $payment);

    $redemption = \GraystackIT\MollieBilling\Models\CouponRedemption::query()
        ->where('billable_id', $billable->getKey())
        ->where('grant_applied_snapshot->mode', 'full')
        ->sole();

    GrantExtrasFakeWebhookController::$sent = [];
    app(CouponService::class)->revokeGrant($redemption, 'admin_revoke: test');

    $billable->refresh();
    $patch = collect(GrantExtrasFakeWebhookController::$sent)->first(fn ($r) => $r instanceof UpdateSubscriptionRequest);

    // Still a running paid subscription — only the gift is gone.
    expect($billable->getBillingSubscriptionSource())->toBe('mollie')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(app(PlanWaiver::class)->marker($billable))->toBeNull()
        ->and($patch)->not->toBeNull()
        // Pro 50.00 + SSO 15.00, plus 20 %.
        ->and(requestProperty($patch, 'amount')->value)->toBe('78.00');
});

it('carries the per-tenant end-of-gift choice over when the tenant buys extras', function (): void {
    $billable = giftedTenant(days: 45);

    // The operator decided for THIS tenant that the plan should continue as paid.
    app(\GraystackIT\MollieBilling\Services\Billing\AdminExtensionService::class)
        ->extendGrant($billable, 1, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    ['request' => $payment] = startExtrasPurchase($billable->fresh(), ['sso'], 2);
    settleExtrasPurchase($billable, $payment);

    expect(app(PlanWaiver::class)->marker($billable->fresh())['on_expiry'])->toBe('continue_paid');
});

it('refunds the purchase instead of booking it when the gift has ended before the payment settled', function (): void {
    // This used to read "charges the upgrade as a regular one": a subscription at the full
    // price, from the next period on, for a tenant who had agreed to pay for an addon on
    // top of a free plan. The purchase is now not carried out and the payment returned.
    // tests/Feature/Checkout/GrantExtrasGiftGoneRefundTest.php covers the whole path.
    $billable = giftedTenant(days: 2);
    ['request' => $payment] = startExtrasPurchase($billable, ['sso'], 2);

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        GrantExtrasFakeWebhookController::$sent[] = $request;

        return (object) ['id' => 're_gift', 'status' => 'pending'];
    });
    Mollie::getFacadeRoot()->shouldIgnoreMissing();

    GrantExtrasFakeWebhookController::$payments['tr_extras'] = new class(requestProperty($payment, 'amount')->value, requestProperty($payment, 'metadata'))
    {
        public string $id = 'tr_extras';

        public string $status = 'paid';

        public object $amount;

        public ?string $subscriptionId = null;

        public string $customerId = 'cst_gift';

        public string $mandateId = 'mdt_gift';

        public string $countryCode = 'AT';

        public function __construct(string $value, public array $metadata)
        {
            $this->amount = (object) ['value' => $value, 'currency' => 'EUR'];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) ['id' => 're_gift', 'status' => 'pending', 'amount' => $this->amount]];
        }
    };

    // SEPA: the payment settles three days later, after the gift ran out.
    Carbon::setTestNow(BillingTime::nowUtc()->addDays(3));
    $this->postJson(route('billing.webhook'), ['id' => 'tr_extras'])->assertStatus(200);
    Carbon::setTestNow();

    $sent = collect(GrantExtrasFakeWebhookController::$sent);
    $refund = $sent->first(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CreatePaymentRefundRequest);

    // No waiver, no subscription — and the money goes back, all of it.
    expect(app(PlanWaiver::class)->marker($billable->fresh()))->toBeNull()
        ->and($sent->first(fn ($r) => $r instanceof CreateSubscriptionRequest))->toBeNull()
        ->and($refund)->not->toBeNull()
        ->and(requestProperty($refund, 'amount')->value)->toBe(requestProperty($payment, 'amount')->value)
        ->and($billable->fresh()->getBillingSubscriptionSource())->toBe('local')
        ->and($billable->fresh()->getActiveBillingAddonCodes())->toBe([]);
});

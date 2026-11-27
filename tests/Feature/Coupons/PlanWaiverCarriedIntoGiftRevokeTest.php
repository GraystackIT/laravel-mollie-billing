<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * Free plan periods given on a gift, then carried into the gift's waiver.
 *
 * On a Local gift, `applyPlanWaiver()` adds the periods to the gift's end date. When the
 * tenant buys extras and keeps the gift, `carryGrantOver()` turns the rest of the gift —
 * up to that extended end, so including those periods — into a date-based waiver on the
 * new Mollie subscription. Revoking the plan_waiver redemption afterwards met
 * `redemptionFedWaiver()`: applied before the waiver's `first_applied_at`, so "a waiver
 * given later", and the revoke was a record only. The tenant kept every revoked free period
 * and Mollie collected nothing for them.
 */
class PlanWaiverCarryFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    PlanWaiverCarryFakeWebhookController::$payments = [];
    $this->app->bind(MollieWebhookController::class, PlanWaiverCarryFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);
});

function planWaiverCarryProperty(object $request, string $name): mixed
{
    $p = (new ReflectionClass($request))->getProperty($name);
    $p->setAccessible(true);

    return $p->getValue($request);
}

/**
 * A tenant given Pro for 30 days, two free plan periods added to that gift, and — a day
 * later — SSO bought while keeping the gift.
 *
 * @param  list<object>  $sent  every request sent to Mollie after the purchase settled
 * @return array{0: TestBillable, 1: CouponRedemption}
 */
function planWaiverCarryScenario(array &$sent, ?string $onExpiry = null): array
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Carried Co', 'email' => uniqid('carry-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(['mollie_customer_id' => 'cst_carry'])->save();

    $coupons = app(CouponService::class);
    $code = 'GRANT-'.strtoupper(bin2hex(random_bytes(3)));
    $grant = $coupons->accessGrantCoupon($code, 'pro', 'monthly', [], 30, $onExpiry);
    $coupons->validate($code, $billable->fresh(), []);
    $coupons->redeem($grant, $billable->fresh(), []);

    $giftEnd = $billable->fresh()->getBillingSubscriptionEndsAt();
    $periods = app(AdminExtensionService::class)->freePlanPeriods($billable->fresh(), 2);

    expect($periods->grant_applied_snapshot['source'] ?? null)->toBe('local')
        ->and((int) $periods->grant_days_added)->toBeGreaterThan(55)
        ->and($billable->fresh()->getBillingSubscriptionEndsAt()->greaterThan($giftEnd))->toBeTrue();

    test()->travel(1)->days();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured, &$sent) {
        if ($request instanceof CreatePaymentRequest && $captured === null) {
            $captured = $request;

            return (object) ['id' => 'tr_carry'];
        }

        $sent[] = $request;

        return (object) ['id' => 'sub_carry'];
    });

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_carry');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->fresh(), ['sso'], 2);

    PlanWaiverCarryFakeWebhookController::$payments['tr_carry'] = (object) [
        'id' => 'tr_carry',
        'status' => 'paid',
        'amount' => (object) ['value' => planWaiverCarryProperty($captured, 'amount')->value, 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_carry',
        'mandateId' => 'mdt_carry',
        'countryCode' => 'AT',
        'metadata' => planWaiverCarryProperty($captured, 'metadata'),
    ];

    test()->postJson(route('billing.webhook'), ['id' => 'tr_carry'])->assertStatus(200);

    $billable = $billable->fresh();
    $marker = app(PlanWaiver::class)->marker($billable);

    expect($billable->getBillingSubscriptionSource())->toBe('mollie')
        ->and($marker)->not->toBeNull()
        // The carried waiver runs to the extended end — the free periods included.
        ->and(BillingTime::toUtc($marker['valid_until'])->greaterThan($giftEnd->copy()->addDays(55)))->toBeTrue();

    return [$billable, $periods->fresh()];
}

it('takes revoked free periods off a gift they were carried into', function (): void {
    $sent = [];
    [$billable, $periods] = planWaiverCarryScenario($sent, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    $sent = [];
    app(CouponService::class)->revokeGrant($periods, 'admin_revoke: test');

    $billable->refresh();
    $marker = app(PlanWaiver::class)->marker($billable);
    $patch = collect($sent)->first(fn ($r) => $r instanceof UpdateSubscriptionRequest);

    // Only the days these periods added come off the gift's end, which now lies before
    // the next charge. Given to continue as paid, so Mollie holds the full price for that
    // charge, as at a carry-over of a gift that ends before the first renewal:
    // Pro 50.00 + SSO 15.00, plus 20 %.
    expect($periods->fresh()->revoked_at)->not->toBeNull()
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($marker)->toBeNull()
        ->and($patch)->not->toBeNull()
        ->and(planWaiverCarryProperty($patch, 'amount')->value)->toBe('78.00');
});

it('ends the subscription with the shortened gift when the gift was given to end', function (): void {
    $sent = [];
    [$billable, $periods] = planWaiverCarryScenario($sent);
    $until = BillingTime::toUtc(app(PlanWaiver::class)->marker($billable)['valid_until']);
    $nextCharge = $billable->nextBillingDate();

    $sent = [];
    app(CouponService::class)->revokeGrant($periods, 'admin_revoke: test');

    $billable->refresh();
    $marker = app(PlanWaiver::class)->marker($billable);

    // The gift keeps running, shortened by exactly the revoked days; it no longer reaches
    // the next charge, so the subscription ends with the period already covered — never
    // a full plan price the tenant did not agree to.
    expect($marker)->not->toBeNull()
        ->and(BillingTime::toUtc($marker['valid_until'])->toDateString())
        ->toBe($until->copy()->subDays((int) $periods->grant_days_added)->toDateString())
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe($nextCharge->toDateString())
        ->and(collect($sent)->contains(fn ($r) => $r instanceof CancelSubscriptionRequest))->toBeTrue()
        ->and(collect($sent)->contains(fn ($r) => $r instanceof UpdateSubscriptionRequest))->toBeFalse();
});

it('only shortens a carried gift that still reaches the next charge', function (): void {
    $sent = [];
    [$billable, $periods] = planWaiverCarryScenario($sent);
    $until = BillingTime::toUtc(app(PlanWaiver::class)->marker($billable)['valid_until']);

    // Revoked days fewer than the gift's own remainder past the next charge: pretend the
    // redemption added only ten days.
    $periods->forceFill(['grant_days_added' => 10])->save();

    $sent = [];
    app(CouponService::class)->revokeGrant($periods->fresh(), 'admin_revoke: test');

    $billable->refresh();
    $marker = app(PlanWaiver::class)->marker($billable);

    expect($marker)->not->toBeNull()
        ->and(BillingTime::toUtc($marker['valid_until'])->toDateString())->toBe($until->copy()->subDays(10)->toDateString())
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($sent)->toBe([]);
});

it('leaves a waiver alone whose free periods were given on Mollie after a carried gift ended', function (): void {
    $sent = [];
    [$billable, $periods] = planWaiverCarryScenario($sent);

    // The carried gift's waiver ends and its marker is cleared; free periods given later
    // on the Mollie subscription start a waiver of their own.
    app(PlanWaiver::class)->remove($billable);
    test()->travel(1)->days();
    app(AdminExtensionService::class)->freePlanPeriods($billable->fresh(), 1);
    $later = app(PlanWaiver::class)->marker($billable->fresh());
    expect($later)->not->toBeNull();

    $sent = [];
    app(CouponService::class)->revokeGrant($periods, 'admin_revoke: test');

    expect(app(PlanWaiver::class)->marker($billable->fresh()))->toBe($later)
        ->and(collect($sent)->first(fn ($r) => $r instanceof UpdateSubscriptionRequest))->toBeNull();
});

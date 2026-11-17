<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Phase 2 of the deferred plan change (metadata.type = prorata_charge) — the
 * path every paid plan change takes in production.
 */
class ProrataPhaseTwoFakeWebhookController extends MollieWebhookController
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
    ProrataPhaseTwoFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, ProrataPhaseTwoFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 3000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 50],
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<int, array<string, mixed>>  $chargeLines
 * @param  array<string, mixed>  $extraMeta
 */
function prorataPhaseTwoBillable(
    string $newPlan,
    array $chargeLines,
    array $extraMeta = [],
    string $currentPlan = 'pro',
): TestBillable {
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'phase2@x.test',
        'name' => 'Phase Two',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_p2',
        'mollie_mandate_id' => 'mdt_p2',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $currentPlan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ])->save();

    $b->forceFill([
        'subscription_meta' => array_merge([
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_p2',
            'prorata_pending_payment_id' => 'tr_phase2',
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_phase2',
                'charge_lines' => $chargeLines,
                'refund_lines' => [],
                'intent' => [
                    'billable_type' => TestBillable::class,
                    'billable_id' => $b->getKey(),
                    'current_plan' => $currentPlan,
                    'current_interval' => 'monthly',
                    'new_plan' => $newPlan,
                    'new_interval' => 'monthly',
                    'current_seats' => 1,
                    'new_seats' => 1,
                    'current_addons' => [],
                    'new_addons' => [],
                ],
                'created_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ], $extraMeta),
    ])->save();

    return $b->fresh();
}

/** @return array<int, array<string, mixed>> */
function prorataPlanLine(string $code, string $label, int $net): array
{
    $vat = (int) round($net * 0.19);

    return [
        'kind' => 'plan',
        'code' => $code,
        'label' => $label,
        'quantity' => 1,
        'unit_price_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 19.0,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
        'period_start' => BillingTime::nowUtc()->toIso8601String(),
        'period_end' => BillingTime::nowUtc()->addMonth()->toIso8601String(),
    ];
}

function prorataPhaseTwoPayment(TestBillable $billable, string $value = '11.90'): object
{
    return (object) [
        'id' => 'tr_phase2',
        'status' => 'paid',
        'amount' => (object) ['value' => $value, 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'prorata_charge',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
}

it('rebalances the wallet against the OLD period, not the freshly started one', function (): void {
    // Fixed clock so the 5-of-30-days fraction is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_p2']);

    $billable = prorataPhaseTwoBillable('basic', [prorataPlanLine('basic', 'Basic monthly', 1000)]);

    // Pro grants 100 Tokens/month; 40 were consumed in the first 5 days.
    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->deposit(60, ['type' => 'Tokens', 'reason' => 'credit']);

    ProrataPhaseTwoFakeWebhookController::$nextPayment = prorataPhaseTwoPayment($billable);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_phase2'])->assertStatus(200);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('basic');

    // Prorated Pro entitlement for 5 of 30 days = 17; used 40 → excess 23.
    // New Basic quota 50 − 23 = 27. Deriving the fraction from the already-reset
    // period would make the excess the full 40 and leave 10.
    expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe(27);
});

it('redeems the deferred coupon and lets the Phase-2 PATCH keep the discount', function (): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'SAVE20',
        'name' => 'Twenty percent forever',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 12,
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof UpdateSubscriptionRequest) {
            $captured = $request;
        }

        return (object) ['id' => 'sub_p2'];
    });

    // basic → pro with a 20% recurring coupon: Phase 1 stored the codes and
    // deliberately left the redemption to Phase 2.
    $billable = prorataPhaseTwoBillable(
        'pro',
        [
            prorataPlanLine('pro', 'Pro monthly', 2000),
            [
                'kind' => 'coupon',
                'code' => 'SAVE20',
                'label' => 'Coupon SAVE20',
                'quantity' => 1,
                'unit_price_net' => -400,
                'amount_net' => -400,
                'vat_rate' => 19.0,
                'vat_amount' => -76,
                'amount_gross' => -476,
            ],
        ],
        [
            'pending_plan_change' => [
                'current_plan' => 'basic',
                'current_interval' => 'monthly',
                'current_seats' => 1,
                'current_addons' => [],
                'plan_code' => 'pro',
                'interval' => 'monthly',
                'seats' => 1,
                'addons' => [],
                'new_net' => 3000,
                'coupon_codes' => ['SAVE20'],
            ],
        ],
        currentPlan: 'basic',
    );

    ProrataPhaseTwoFakeWebhookController::$nextPayment = prorataPhaseTwoPayment($billable, '19.04');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_phase2'])->assertStatus(200);

    $chargeInvoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_phase2')->first();
    expect($chargeInvoice)->not->toBeNull();

    // Redemption row with the discount that was actually billed, linked to the charge.
    $redemption = CouponRedemption::query()->where('coupon_id', $coupon->id)->first();
    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(400)
        ->and((int) $redemption->invoice_id)->toBe((int) $chargeInvoice->id);
    expect((int) $coupon->fresh()->redemptions_count)->toBe(1);

    // Recurring marker so future renewals carry the promised discount…
    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('SAVE20')
        ->and((int) $marker['base_amount_net'])->toBe(3000);

    // …and the PATCH that ran afterwards charges 3000 − 20% = 2400 net (2856 gross).
    expect($captured)->not->toBeNull();
    $amountProp = (new ReflectionObject($captured))->getProperty('amount');
    $amountProp->setAccessible(true);
    expect($amountProp->getValue($captured)->value)->toBe('28.56');
});

it('returns a paid prorata charge whose pending plan change was already cancelled', function (): void {
    Notification::fake();

    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'orphan@x.test',
        'name' => 'Orphan',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_or',
        'mollie_mandate_id' => 'mdt_or',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        // cancelPendingPlanChange() cleared the local state even though Mollie
        // could not cancel the payment.
        'subscription_meta' => ['mollie_subscription_id' => 'sub_or'],
    ])->save();

    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    ProrataPhaseTwoFakeWebhookController::$nextPayment = prorataPhaseTwoPayment($billable, '23.80');

    try {
        $this->postJson(route('billing.webhook'), ['id' => 'tr_phase2'])->assertStatus(200);
    } finally {
        $prop = (new ReflectionClass(MollieBilling::class))->getProperty('notifyAdminCallback');
        $prop->setValue(null, null);
    }

    // No plan change is applied — the user cancelled it — and the money is not left for
    // an operator to refund by hand: it is booked as a payment that was not applied and
    // returned automatically. (AutoRefundUnwantedProrataPaymentTest covers the refund,
    // the customer mail and when an admin is told.)
    expect($billable->fresh()->subscription_plan_code)->toBe('basic');

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_phase2')->first();

    expect($invoice)->not->toBeNull()
        ->and(\GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund::isUnwanted($invoice))->toBeTrue()
        ->and($invoice->line_items[0]['reason'])->toBe('change_cancelled');
});

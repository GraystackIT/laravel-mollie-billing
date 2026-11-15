<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/*
 * A plan change keeps the seats it was approved with.
 *
 * Phase 2 used to take the seat count from the charge invoice — included seats plus the
 * quantity of its `seats` line — and only fall back to the intent when the invoice had no
 * plan line. A seats line is left off the charge whenever it rounds to nothing (a change
 * on the last day of a period, a low seat price, a zero-decimal currency) or the new plan
 * does not price its seats, and the count then collapsed to the plan's included seats:
 * the customer lost seats they hold, while Mollie — patched from the intent — went on
 * charging for all of them.
 */
class SeatCountProrataFakeWebhookController extends MollieWebhookController
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

    SeatCountProrataFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, SeatCountProrataFakeWebhookController::class);

    foreach (['basic' => 1000, 'pro' => 3000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $price,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => $price, 'seat_price_net' => 10, 'included_usages' => []]],
        ]);
    }

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_seatcount']);
});

it('keeps the seats of the approved change when the charge carries no seats line', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Seats Co', 'email' => uniqid('seats-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_seatcount',
        'mollie_mandate_id' => 'mdt_seatcount',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(29),
        'subscription_meta' => [
            'seat_count' => 5,
            'mollie_subscription_id' => 'sub_seatcount',
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_seatcount',
                // Upgrade basic → pro on the last day of the period, five seats kept.
                // Plan: 30.00 × 1/30 = 1.00. Three extra seats at 0.10: 0.30 × 1/30
                // rounds to nothing, so the composer leaves the line off.
                'charge_lines' => [[
                    'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
                    'unit_price_net' => 100, 'amount_net' => 100, 'vat_rate' => 19.0, 'vat_amount' => 19, 'amount_gross' => 119,
                    'period_start' => BillingTime::nowUtc()->toIso8601String(),
                    'period_end' => BillingTime::nowUtc()->addDay()->toIso8601String(),
                ]],
                'refund_lines' => [],
                'intent' => [
                    'billable_type' => TestBillable::class,
                    'billable_id' => $billable->getKey(),
                    'current_plan' => 'basic',
                    'current_interval' => 'monthly',
                    'new_plan' => 'pro',
                    'new_interval' => 'monthly',
                    'current_seats' => 5,
                    'new_seats' => 5,
                    'current_addons' => [],
                    'new_addons' => [],
                ],
                'created_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ],
    ])->save();

    SeatCountProrataFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_seatcount',
        'status' => 'paid',
        'amount' => (object) ['value' => '1.19', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'prorata_charge',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_seatcount'])->assertStatus(200);

    $billable = $billable->fresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSeatCount())->toBe(5);
});

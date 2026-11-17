<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

// `pending_prorata_change` is a single slot, and the handler acted on whatever was in it.
// Change A is started, the customer cancels it while its SEPA payment is still travelling,
// and starts change B — whose pending state now occupies the slot. When A's payment then
// reports, it was applied as if it paid for B: a `paid` granted B's plan with A's (smaller)
// amount, and a `canceled`/`failed` wiped B's pending state and rolled back B's seats while
// B's own payment was still on its way, leaving it to settle as an orphan.

beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 9900, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
});

function billableWithPendingChangeB(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Identity Co',
        'email' => uniqid('identity-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_identity',
        'mollie_mandate_id' => 'mdt_identity',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_identity',
            'seat_count' => 3,
            'prorata_pending_payment_id' => 'tr_change_b',
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_change_b',
                'charge_lines' => [],
                'refund_lines' => [],
                'intent' => [
                    'billable_type' => TestBillable::class,
                    'billable_id' => 1,
                    'current_plan' => 'team',
                    'new_plan' => 'pro',
                    'current_interval' => 'monthly',
                    'new_interval' => 'monthly',
                    'current_seats' => 1,
                    'new_seats' => 3,
                    'current_addons' => [],
                    'new_addons' => [],
                    'force_reset_start_date' => false,
                ],
            ],
        ],
    ])->save();

    return $billable->refresh();
}

it('does not apply a newer pending change on the strength of an older payment', function (): void {
    $billable = billableWithPendingChangeB();

    app(ProrataChargeHandler::class)->paid((object) [
        'id' => 'tr_change_a',
        'status' => 'paid',
        'amount' => (object) ['value' => '5.00', 'currency' => 'EUR'],
    ], $billable, ['type' => 'prorata_charge']);

    $billable->refresh();

    // The newer change is untouched. The older payment is money for a change that no
    // longer exists: it is booked as a payment that was not applied and returned
    // automatically (UnwantedPaymentRefund) — never applied, never left lying.
    expect($billable->subscription_plan_code)->toBe('team')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)
        ->toBe('tr_change_b')
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and(\GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund::isUnwanted(
            BillingInvoice::query()->where('mollie_payment_id', 'tr_change_a')->first(),
        ))->toBeTrue();
});

it('does not discard a newer pending change when an older payment fails', function (): void {
    $billable = billableWithPendingChangeB();

    app(ProrataChargeHandler::class)->failed((object) [
        'id' => 'tr_change_a',
        'status' => 'canceled',
    ], $billable, ['type' => 'prorata_charge']);

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)
        ->toBe('tr_change_b')
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->toBeNull()
        ->and($billable->getBillingSeatCount())->toBe(3);
});

it('still clears the pending change when its own payment fails', function (): void {
    $billable = billableWithPendingChangeB();

    app(ProrataChargeHandler::class)->failed((object) [
        'id' => 'tr_change_b',
        'status' => 'failed',
    ], $billable, ['type' => 'prorata_charge']);

    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();
});

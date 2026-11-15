<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

// A plan or interval switch waits for its prorata charge to clear before it is applied
// locally. A seat or add-on change does not — it is written straight away and reported as
// done. When the charge is then declined (with SEPA, days later) nothing put it back: the
// customer kept seats they had not paid for, `FeatureAccess` kept granting the add-on's
// features, and the next renewal rebuilt its lines from the inflated count while Mollie was
// still billing the old amount, because no PATCH ran on the deferred path.
//
// Since then the seat/add-on path PATCHes Mollie in the same call that writes the local
// state (so a renewal in between is collected at the right amount), which gives this
// handler a second half to undo: the recurring amount goes back together with the seats.

beforeEach(function (): void {
    Notification::fake();

    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => ['extra-feature'],
        'intervals' => ['monthly' => ['price_net' => 500]],
    ]);
    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['extra'],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
});

function optimisticallyChangedBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Optimistic Co',
        'email' => 'optimistic@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_opt',
        'mollie_mandate_id' => 'mdt_opt',
        // The state phase 3 already committed: three seats and the add-on switched on,
        // while the charge that pays for them is still in flight.
        'active_addon_codes' => ['extra'],
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_opt',
            'seat_count' => 3,
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_opt',
                'charge_lines' => [],
                'refund_lines' => [],
                'intent' => [
                    'billable_type' => TestBillable::class,
                    'billable_id' => 1,
                    'current_plan' => 'team',
                    'new_plan' => 'team',
                    'current_interval' => 'monthly',
                    'new_interval' => 'monthly',
                    'current_seats' => 1,
                    'new_seats' => 3,
                    'current_addons' => [],
                    'new_addons' => ['extra' => 1],
                    'force_reset_start_date' => false,
                ],
            ],
        ],
    ])->save();

    return $billable->refresh();
}

it('restores the seat count and add-ons when the prorata charge is declined', function (): void {
    $billable = optimisticallyChangedBillable();

    expect($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['extra']);

    app(ProrataChargeHandler::class)->failed((object) [
        'id' => 'tr_opt',
        'status' => 'failed',
        'details' => (object) ['failureReason' => 'insufficient_funds'],
    ], $billable, ['type' => 'prorata_charge']);

    $billable->refresh();

    // Back to what the customer has actually paid for — locally and at Mollie, which was
    // PATCHed to the new amount together with the local write and is PATCHed back here.
    expect($billable->getBillingSeatCount())->toBe(1)
        ->and($billable->getActiveBillingAddonCodes())->toBe([])
        ->and(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(1)
        ->and(SpyMollieSubscriptionPatcher::$calls[0][3]['seats'])->toBe(1)
        ->and(SpyMollieSubscriptionPatcher::$calls[0][3]['addons'])->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_reason'] ?? null)
        ->toBe('insufficient_funds')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();
});

it('is still a no-op when the customer already cancelled the pending change', function (): void {
    $billable = optimisticallyChangedBillable();

    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta['pending_prorata_change']);
    $billable->forceFill(['subscription_meta' => $meta, 'active_addon_codes' => ['extra']])->save();

    app(ProrataChargeHandler::class)->failed((object) [
        'id' => 'tr_opt',
        'status' => 'failed',
    ], $billable->refresh(), ['type' => 'prorata_charge']);

    // Nothing to undo and nothing to announce — the customer already decided.
    expect($billable->refresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->toBeNull()
        ->and($billable->getActiveBillingAddonCodes())->toBe(['extra']);
});

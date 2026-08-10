<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// Two ways a plan change used to lose track of metered debt:
//
//  - it cleared a negative balance an in-flight period-end charge is still collecting, so
//    that charge's settlement credited the units onto a healthy wallet — free capacity;
//  - it clamped the wallet to 0 and handed the excess over as line items, and when the
//    charge for them did not happen (another one in flight, no mandate, an API failure)
//    the units were simply gone: zero balance, nothing in the snapshot, no sweep able to
//    find them.

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => ['SMS' => 100], 'usage_overage_prices' => ['SMS' => 2]],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => ['SMS' => 1200], 'usage_overage_prices' => ['SMS' => 2]],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.mini', [
        'name' => 'Mini',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => ['SMS' => 10], 'usage_overage_prices' => ['SMS' => 2]]],
    ]);
});

function pendingOverageBillable(int $negativeBalance, bool $withMandate = true): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Adj Co', 'email' => uniqid('adj-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(28),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_adj',
        'mollie_mandate_id' => $withMandate ? 'mdt_adj' : null,
        'allows_billing_overage' => true,
    ])->save();

    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw($negativeBalance);

    return $billable->refresh();
}

/** A period-end charge already in flight for `$quantity` SMS. */
function markPeriodEndOverageInFlight(TestBillable $billable, int $quantity): void
{
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY] = [
        'payment_id' => 'tr_period_end',
        'line_items' => [[
            'kind' => 'overage',
            'code' => 'SMS',
            'type' => 'SMS',
            'quantity' => $quantity,
            'unit_price_net' => 2,
            'total_net' => $quantity * 2,
        ]],
        'total_net' => $quantity * 2,
    ];
    $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;

    $billable->forceFill(['subscription_meta' => $meta])->save();
    $billable->refresh();
}

it('does not hand out free units when the pending charge settles', function (): void {
    // The scenario the old code broke on: a downgrade whose new quota is smaller than the
    // excess, so the wallet is clamped to 0. The 50 units the in-flight charge collects
    // were wiped off the balance with everything else, and when that charge settled its
    // credit landed on a healthy wallet — 50 units of capacity the customer never bought.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_ignored', 'status' => 'open']);

    $billable = pendingOverageBillable(50);
    markPeriodEndOverageInFlight($billable, 50);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'pro', 'monthly', 'mini', 'monthly',
        now()->subDays(28),
        now()->addDays(2),
        deferOverageCharge: true,
    );

    // `SingleChargeHandler::settleOverageWallets()` credits the charged quantity back when
    // the period-end payment clears.
    $billable->refresh()->getWallet('SMS')->deposit(50, ['type' => 'SMS', 'reason' => 'overage_settlement']);

    // `mini` includes 10. Of the 57 units consumed beyond the prorated old entitlement, 50
    // were paid in cash and 7 absorbed against the new quota — so 3 remain. Not 53.
    expect((int) $billable->refresh()->getWallet('SMS')->balanceInt)->toBe(3);
});

it('leaves excess on the wallet when its charge could not be created', function (): void {
    // `handleExplicit()` refuses while another charge holds the single snapshot slot.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $billable = pendingOverageBillable(50);
    markPeriodEndOverageInFlight($billable, 50);

    $adjuster = app(WalletPlanChangeAdjuster::class);

    $deferred = $adjuster->adjust(
        $billable->refresh(),
        'pro', 'monthly', 'mini', 'monthly',
        now()->subDays(28),
        now()->addDays(2),
        deferOverageCharge: true,
    );

    $balanceAfterRebalance = (int) $billable->refresh()->getWallet('SMS')->balanceInt;

    $adjuster->chargeDeferredOverage($billable->refresh(), $deferred);

    $uncharged = array_sum(array_map(fn (array $line): int => (int) $line['quantity'], $deferred));

    // Whatever the refused charge would have collected is back on the wallet, where the
    // next period-end sweep can find it — not silently forgiven.
    expect((int) $billable->refresh()->getWallet('SMS')->balanceInt)
        ->toBe($balanceAfterRebalance - $uncharged);
});

it('charges the excess normally when nothing is in the way', function (): void {
    $captured = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'tr_excess', 'status' => 'open'];
    });

    $billable = pendingOverageBillable(50);

    $adjuster = app(WalletPlanChangeAdjuster::class);

    $deferred = $adjuster->adjust(
        $billable->refresh(),
        'pro', 'monthly', 'mini', 'monthly',
        now()->subDays(28),
        now()->addDays(2),
        deferOverageCharge: true,
    );

    expect($deferred)->not->toBeEmpty();

    $adjuster->chargeDeferredOverage($billable->refresh(), $deferred);

    // Charged, so the units stay off the wallet — a plan-change line is consumption
    // already accounted for, not capacity being bought.
    expect($captured)->not->toBeEmpty()
        ->and((int) $billable->refresh()->getWallet('SMS')->balanceInt)->toBeGreaterThanOrEqual(0)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * plan-change excess that could not be charged at once is later billed at
 * the NEW plan's overage price.
 *
 * `WalletPlanChangeAdjuster::adjust()` prices the unresolved excess with the OLD plan's
 * overage price (it is consumption on the old plan). When the charge cannot be created —
 * the single overage slot is occupied by a period-end charge that is still settling —
 * `restoreUnchargedExcess()` puts only the *units* back on the wallet. The next sweep
 * rebuilds the line from the wallet and multiplies by whatever the billable's CURRENT
 * plan charges per unit.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.plans.capped', [
        'name' => 'Capped', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 700, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 10],
            // Hard cap: this plan sells no overage at all.
            'usage_overage_prices' => ['SMS' => false],
        ]],
    ]);
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 0, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 10],
        ]],
    ]);

    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 5000, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 100],
            'usage_overage_prices' => ['SMS' => 2],
        ]],
    ]);
    config()->set('mollie-billing-plans.plans.mini', [
        'name' => 'Mini', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 500, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 10],
            // The small plan charges more per extra unit — an ordinary pricing shape.
            'usage_overage_prices' => ['SMS' => 9],
        ]],
    ]);
});

it('bills restored plan-change excess at the price of the plan it was consumed on', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_restored_excess', 'status' => 'open']);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Reprice Co', 'email' => 'reprice@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(28),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_reprice',
        'mollie_mandate_id' => 'mdt_reprice',
        'allows_billing_overage' => true,
    ])->save();

    // 250 SMS used on Pro (quota 100): balance −150.
    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(150);

    // Last night's period-end sweep already charged the first 50 of them; SEPA, unsettled.
    $billable->forceFill(['subscription_meta' => [
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_period_end',
            'line_items' => [[
                'kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS',
                'quantity' => 50, 'unit_price_net' => 2, 'total_net' => 100,
            ]],
            'created_at' => now()->subDay()->toIso8601String(),
        ],
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
    ]])->save();

    // Downgrade to Mini. Callers save the new plan before rebalancing.
    $billable->forceFill(['subscription_plan_code' => 'mini'])->save();

    $deferred = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'pro', 'monthly', 'mini', 'monthly',
        now()->subDays(28),
        now()->addDays(2),
        deferOverageCharge: true,
    );

    // What the adjuster itself says is owed: old-plan units at the old plan's price.
    expect($deferred)->toHaveCount(1);
    $units = (int) $deferred[0]['quantity'];
    $owedNet = (int) $deferred[0]['total_net'];
    expect((int) $deferred[0]['unit_price_net'])->toBe(2)
        ->and($owedNet)->toBe($units * 2);

    // The slot is occupied, so the charge is refused and the units go back on the wallet.
    app(WalletPlanChangeAdjuster::class)->chargeDeferredOverage($billable->refresh(), $deferred);

    // The period-end payment settles: its 50 units are credited back, the slot is freed.
    $billable->refresh()->getWallet('SMS')->deposit(50, ['type' => 'SMS', 'reason' => 'overage_settlement']);
    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta[ChargeUsageOverageDirectly::META_KEY], $meta['usage_overage_status']);
    $billable->forceFill(['subscription_meta' => $meta])->save();

    expect((int) $billable->refresh()->getWallet('SMS')->refresh()->balanceInt)->toBe(-$units);

    // The next period-end sweep collects what is left.
    $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($result->wasCharged())->toBeTrue();

    // Same units, same debt — it must cost what the plan change said it would.
    expect($result->amountNet)->toBe(
        $owedNet,
        "Restored plan-change excess of {$units} units was billed {$result->amountNet} ct net instead of {$owedNet} ct.",
    );
});

/**
 * A billable that has just left Pro for `$newPlan` with 97 SMS of excess the plan change
 * could not charge: the units are back on the wallet, recorded at Pro's 2 ct.
 */
function billableWithDeferredExcess(string $newPlan, SubscriptionSource $source = SubscriptionSource::Mollie): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Deferred Co', 'email' => uniqid('deferred-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => $source,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $newPlan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(10),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_deferred',
        'mollie_mandate_id' => 'mdt_deferred',
        'allows_billing_overage' => true,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_deferred'],
    ])->save();

    $wallet = $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS']);
    $wallet->forceWithdraw(97, ['type' => 'SMS', 'reason' => 'plan_change_excess_uncharged']);
    WalletUsageService::addDeferredOverage($wallet, 97, 2);

    return $billable->refresh();
}

it('still bills restored excess when the new plan sells no overage', function (): void {
    // The reverse direction: priced from the current plan this debt had no price at all,
    // the line was skipped, and the next reset was the end of it.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_capped', 'status' => 'open']);

    $billable = billableWithDeferredExcess('capped');

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);

    expect($result->wasCharged())->toBeTrue()
        ->and($result->amountNet)->toBe(194)
        ->and($result->lineItems)->toHaveCount(1)
        ->and((int) $result->lineItems[0]['unit_price_net'])->toBe(2);
});

it('prices new overage from the current plan and only the restored units from the old one', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_mixed', 'status' => 'open']);

    $billable = billableWithDeferredExcess('mini');

    // 10 more SMS past Mini's quota, on Mini: these are 9 ct units.
    $billable->getWallet('SMS')->forceWithdraw(10, ['type' => 'SMS', 'reason' => 'usage']);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($result->amountNet)->toBe(97 * 2 + 10 * 9)
        ->and(collect($result->lineItems)->pluck('total_net', 'unit_price_net')->all())->toBe([2 => 194, 9 => 90]);
});

it('drops the price record once the charge for it has settled', function (): void {
    // Usage that accrues while the charge settles keeps the wallet negative afterwards.
    // Those are new units on the new plan — the old price must not stick to them.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_settle', 'status' => 'open']);

    $billable = billableWithDeferredExcess('mini');

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable);
    expect($result->amountNet)->toBe(194);

    $billable->refresh()->getWallet('SMS')->forceWithdraw(10, ['type' => 'SMS', 'reason' => 'usage']);

    app(MollieWebhookController::class)->replayDelivery((object) [
        'id' => 'tr_settle',
        'status' => 'paid',
        'amount' => (object) ['value' => BillingMoney::mollieAmount($result->amountGross, 'EUR'), 'currency' => 'EUR'],
        'paidAt' => now()->toIso8601String(),
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => $result->lineItems,
        ],
    ], $billable->refresh());

    $wallet = $billable->refresh()->getWallet('SMS')->refresh();

    expect((int) $wallet->balanceInt)->toBe(-10)
        ->and(WalletUsageService::getDeferredOverage($wallet))->toBe([]);

    $next = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($next->amountNet)->toBe(10 * 9);
});

it('forgets the old price once the debt has been paid off by a credit', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_after_credit', 'status' => 'open']);

    $billable = billableWithDeferredExcess('mini');

    // A purchased pack (or an admin credit) clears the debt…
    app(WalletUsageService::class)->credit($billable, 'SMS', 100, 'coupon_credit');
    // …and later usage takes the wallet negative again.
    $billable->refresh()->getWallet('SMS')->forceWithdraw(8, ['type' => 'SMS', 'reason' => 'usage']);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($result->amountNet)->toBe(5 * 9);
});

it('carries restored excess through a second plan change at its recorded price', function (): void {
    // Read as consumption on the plan being left, the 97 units would be prorated against
    // Mini's quota and whatever was left re-priced at Mini's 9 ct.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_second_change', 'status' => 'open']);

    $billable = billableWithDeferredExcess('mini');
    $billable->forceFill(['subscription_plan_code' => 'capped'])->save();

    $deferred = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'mini', 'monthly', 'capped', 'monthly',
        now()->subDays(10),
        now()->addDays(20),
        deferOverageCharge: true,
    );

    // Nothing was consumed on Mini, so the second change owes nothing of its own.
    expect($deferred)->toBe([]);

    $result = app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    expect($result->wasCharged())->toBeTrue()
        ->and(collect($result->lineItems)->every(fn (array $line): bool => (int) $line['unit_price_net'] === 2))->toBeTrue();
});

it('collects restored excess from a free plan at its period boundary instead of resetting it away', function (): void {
    // Downgrade to the free plan with the mandate still in place. The Local renewal pass
    // used to reset the wallet and that was the end of the debt.
    config()->set('wallet.cache.ttl', 10 * 365 * 24 * 3600);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_free_boundary', 'status' => 'open']);

    $billable = billableWithDeferredExcess('free', SubscriptionSource::Local);
    $billable->forceFill(['subscription_period_starts_at' => now()->addDay()->startOfDay()->addHours(6)->subMonthNoOverflow()])->save();

    PrepareUsageOverageJob::dispatchSync();

    $billable->refresh();
    $snapshot = (array) ($billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? []);

    expect($snapshot['payment_id'] ?? null)->toBe('tr_free_boundary')
        ->and((int) ($snapshot['amount_net'] ?? 0))->toBe(194)
        // Debt carried until the payment settles: 10 − 97.
        ->and((int) $billable->getWallet('SMS')->refresh()->balanceInt)->toBe(-87);
});

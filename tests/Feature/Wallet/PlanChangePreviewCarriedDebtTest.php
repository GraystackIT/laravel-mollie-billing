<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * The plan-change preview quotes the usage excess the change will actually bill.
 *
 * `WalletPlanChangeAdjuster::adjust()` takes two kinds of debt out of the excess before it
 * prices it: units an in-flight period-end overage charge is already collecting, and units a
 * previous plan change left on the wallet with their price (`deferred_overage`). Both are
 * part of the negative balance and neither is consumption on the plan being left. The
 * preview read the bare balance, so it quoted those units a second time — at the plan being
 * left's price — on the screen the customer confirms. Found by
 * tests/Browser/UsageDeepTest (a downgrade while the sweep's SEPA charge was open: 50 units
 * quoted, 20 owed).
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);

    config()->set('mollie-billing-plans.plans.big', [
        'name' => 'Big', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 2900, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 50],
            'usage_overage_prices' => ['SMS' => 15],
        ]],
    ]);
    config()->set('mollie-billing-plans.plans.small', [
        'name' => 'Small', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 990, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 20],
            'usage_overage_prices' => ['SMS' => 18],
        ]],
    ]);
    config()->set('mollie-billing-plans.plans.tiny', [
        'name' => 'Tiny', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 490, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 5],
            'usage_overage_prices' => ['SMS' => 20],
        ]],
    ]);
});

function carriedDebtPreviewBillable(string $plan): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Carried Co', 'email' => uniqid('carried-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        // The period began an hour ago: practically nothing of the old quota is earned yet.
        'subscription_period_starts_at' => now()->subHour(),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_carried',
        'mollie_mandate_id' => 'mdt_carried',
        'allows_billing_overage' => true,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_carried'],
    ])->save();

    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS']);

    return $billable->refresh();
}

/** What `adjust()` itself bills for SMS when the change is made. */
function carriedDebtAdjusterUnits(TestBillable $billable, string $from, string $to): int
{
    // The boundaries the plan-change caller passes (ProrataExecutor), read before the plan moves.
    $periodStart = $billable->getBillingPeriodStartsAt();
    $periodEnd = $billable->nextBillingDate();

    $billable->forceFill(['subscription_plan_code' => $to])->save();

    $lines = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        $from, 'monthly', $to, 'monthly',
        $periodStart,
        $periodEnd,
        deferOverageCharge: true,
    );

    return (int) array_sum(array_map(static fn (array $line): int => (int) $line['quantity'], $lines));
}

it('does not quote the units an in-flight overage charge is already collecting', function (): void {
    $billable = carriedDebtPreviewBillable('big');

    // Last period's 30 units over are being collected by the sweep's SEPA payment; the
    // renewal carried them as debt (50 − 30 = 20), and 40 more were used since: −20.
    $billable->getWallet('SMS')->forceWithdraw(20, ['type' => 'SMS']);
    $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), [
        ChargeUsageOverageDirectly::META_KEY => [
            'payment_id' => 'tr_open_sepa',
            'line_items' => [[
                'kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS',
                'quantity' => 30, 'unit_price_net' => 15, 'total_net' => 450,
            ]],
            'created_at' => now()->subDay()->toIso8601String(),
        ],
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
    ])])->save();

    $preview = app(PreviewService::class)->previewPlanChange($billable->refresh(), 'small', 'monthly');
    $quoted = (int) $preview['usageChanges']['SMS']['unresolved_overage'];

    $billed = carriedDebtAdjusterUnits($billable->refresh(), 'big', 'small');

    // 40 used this period against Small's 20: 20 units at Big's 15 ct. The 30 in flight
    // are the previous period's, already being paid.
    expect($billed)->toBe(20)
        ->and($quoted)->toBe($billed)
        ->and((int) $preview['usageChanges']['SMS']['overage_unit_price_net'])->toBe(15)
        ->and((int) $preview['usageOverageChargeNet'])->toBe(300)
        ->and((int) $preview['usageChanges']['SMS']['actually_used'])->toBe(40);
});

it('does not quote the excess a previous plan change left on the wallet with its price', function (): void {
    // A change Big → Small could not charge 25 units (the slot was taken): they are on the
    // wallet, recorded at Big's 15 ct. Since then Small's 20 have been used up: −25.
    $billable = carriedDebtPreviewBillable('small');
    $wallet = $billable->getWallet('SMS');
    $wallet->forceWithdraw(25, ['type' => 'SMS', 'reason' => 'plan_change_excess_uncharged']);
    WalletUsageService::addDeferredOverage($wallet, 25, 15);

    // Small → Tiny: 20 used against Tiny's 5 is 15 units at Small's 18 ct; the 25 below zero
    // are the earlier change's debt.
    $preview = app(PreviewService::class)->previewPlanChange($billable->refresh(), 'tiny', 'monthly');
    $quoted = (int) $preview['usageChanges']['SMS']['unresolved_overage'];

    $billed = carriedDebtAdjusterUnits($billable->refresh(), 'small', 'tiny');

    // The 25 are debt with a price, collected by the next sweep at 15 ct — not consumption on
    // Small to be quoted again at 18 ct. What the change itself bills is what the screen says.
    expect($billed)->toBe(15)
        ->and($quoted)->toBe($billed)
        ->and((int) $preview['usageOverageChargeNet'])->toBe(15 * 18)
        ->and((int) $preview['usageChanges']['SMS']['actually_used'])->toBe(20);
});

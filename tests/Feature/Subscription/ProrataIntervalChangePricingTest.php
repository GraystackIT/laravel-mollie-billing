<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function intervalChangeBillable(int $daysElapsed): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Interval Co',
        'email' => 'interval@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_iv',
        'mollie_mandate_id' => 'mdt_iv',
        'vat_exempt' => true,
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysElapsed)->startOfDay(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_iv', 'seat_count' => 1],
    ])->save();

    $b->refresh();
    $periodStart = $b->getBillingPeriodStartsAt();
    $periodEnd = $b->nextBillingDate();

    // The paid monthly invoice the refund line is computed from. Its window matches the
    // billable's billing window so the composer and BillingPolicy prorate over the same days.
    BillingInvoice::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'mollie_payment_id' => 'tr_iv_'.$daysElapsed,
        'mollie_subscription_id' => 'sub_iv',
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 2900,
        'amount_vat' => 0,
        'amount_gross' => 2900,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro monthly',
            'quantity' => 1,
            'amount_net' => 2900,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => 2900,
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
        ]],
    ]);

    return $b->refresh();
}

function monthlyToYearlyIntent(TestBillable $billable): PlanChangeIntent
{
    return new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'yearly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );
}

/** @param list<ProrataLine> $lines */
function netOf(array $lines, string $direction): int
{
    return array_sum(array_map(
        fn (ProrataLine $l) => $l->amountNet,
        array_values(array_filter($lines, fn (ProrataLine $l) => $l->direction === $direction)),
    ));
}

it('charges the full new-interval price and credits the unused old period', function (): void {
    // 15 of 30 days remaining, monthly €29 → yearly €290.
    $billable = intervalChangeBillable(daysElapsed: 15);

    $lines = app(ProrataComposer::class)->compose(monthlyToYearlyIntent($billable));

    $chargeNet = netOf($lines, 'charge');
    $refundNet = netOf($lines, 'refund');

    $factor = BillingPolicy::prorataFactor(
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    );

    // A full year is granted (ProrataChargeHandler opens a 1-year window and the patcher
    // resets Mollie's startDate), so the full yearly list price is charged — NOT scaled by
    // the old month's remaining fraction, which collected about half of it.
    expect($chargeNet)->toBe(29000);
    // The unused remainder of the monthly period is credited.
    expect($refundNet)->toBe(-(int) round(2900 * $factor));

    // Same rule, same numbers as BillingPolicy — the aggregate the $hasProrata gate, the
    // returned prorataChargeNet and the PlanChangePending payload all use. One live rule.
    $policy = BillingPolicy::computeProrata(
        2900, 29000, true,
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    );
    expect($chargeNet + $refundNet)->toBe($policy['charge_net']);
    expect(abs($refundNet))->toBe($policy['credit_net']);
});

it('does not undercharge an interval change made late in the old period', function (): void {
    // Day 28 of 30: the old bug collected ~7% of the yearly price for a full year.
    $billable = intervalChangeBillable(daysElapsed: 28);

    $lines = app(ProrataComposer::class)->compose(monthlyToYearlyIntent($billable));

    $factor = BillingPolicy::prorataFactor(
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    );

    // Full yearly price regardless of how little of the month is left.
    expect(netOf($lines, 'charge'))->toBe(29000);
    expect(netOf($lines, 'refund'))->toBe(-(int) round(2900 * $factor));
    // The old behaviour charged only a few percent of a year for a full year of access.
    expect($factor)->toBeLessThan(0.15);
});

it('keeps the remaining-window factor for a plan change inside the same interval', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 5800, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 58000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    $billable = intervalChangeBillable(daysElapsed: 15);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $factor = BillingPolicy::prorataFactor(
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    );

    // The period boundary does not move here, so both sides stay prorated — the new plan
    // is explicitly NOT charged at its full list price.
    expect(netOf($lines, 'charge'))->toBe((int) round(5800 * $factor));
    expect(netOf($lines, 'charge'))->toBeLessThan(5800);
    expect(netOf($lines, 'refund'))->toBe(-(int) round(2900 * $factor));
});

it('never refunds more than a line was paid when its net is not divisible by quantity', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Seat Co',
        'email' => 'seat-round@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_seat',
        'mollie_mandate_id' => 'mdt_seat',
        'vat_exempt' => true,
    ]);

    $start = BillingTime::nowUtc()->startOfDay();
    $end = $start->copy()->addDays(30);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $start,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_seat', 'seat_count' => 3],
    ])->save();

    // 1001 cents over 2 seats: round(1001/2) = 501 per seat → 1002 refunded at factor 1.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'mollie_payment_id' => 'tr_seat_1',
        'mollie_subscription_id' => 'sub_seat',
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1001,
        'amount_vat' => 0,
        'amount_gross' => 1001,
        'period_start' => $start,
        'period_end' => $end,
        'line_items' => [[
            'kind' => 'seats',
            'code' => null,
            'label' => '2 extra seats',
            'quantity' => 2,
            'amount_net' => 1001,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => 1001,
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
        ]],
    ]);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable->refresh(),
        currentPlan: 'pro', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 3, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    expect(netOf($lines, 'refund'))->toBe(-1001);
});

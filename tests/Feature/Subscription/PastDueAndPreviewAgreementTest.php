<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * What the customer is shown and what the customer is charged have to be the same
 * number.
 *
 * Both cases here are one rule implemented in more than one place, where the copies had
 * drifted: "is this change a past-due reset" (three copies, one missing its
 * plan/interval condition) and "does the new quota leave the customer in overage" (three
 * copies, the preview's missing interval changes).
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900,
                    'seat_price_net' => 500,
                    'included_usages' => ['Tokens' => 1000],
                    'usage_overage_prices' => ['Tokens' => 2],
                ],
                'yearly' => [
                    'base_price_net' => 29000,
                    'seat_price_net' => 5000,
                    'included_usages' => ['Tokens' => 12000],
                    'usage_overage_prices' => ['Tokens' => 2],
                ],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing.vat.seller_country', 'AT');
});

function agreementBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Agreement Ltd',
        'email' => uniqid('agree-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(9),
        'mollie_customer_id' => 'cst_agree',
        'mollie_mandate_id' => 'mdt_agree',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_agree', 'seat_count' => 1],
    ], $overrides))->save();

    return $billable->refresh();
}

it('prorates an extra seat for a past-due customer instead of charging a fresh period', function (): void {
    // PastDue is reachable mid-period — the overage-charge retry ladder and a chargeback
    // both set it without touching subscription_period_starts_at. The period is still the
    // one the customer paid for, so adding a seat is an adjustment, not a fresh start.
    $billable = agreementBillable(['subscription_status' => SubscriptionStatus::PastDue]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro',
        currentInterval: 'monthly',
        currentSeats: 1,
        currentAddons: [],
        newPlan: 'pro',
        newInterval: 'monthly',
        newSeats: 2,
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    $chargeNet = array_sum(array_map(
        fn ($line) => max(0, (int) $line->toArray()['amount_net']),
        $lines,
    ));

    // One extra seat at 5,00 for the ~21 remaining of 30 days — not 34,00 for plan plus
    // seat over a whole synthetic period, which is what the reset branch produced while
    // the preview quoted the delta.
    expect($chargeNet)->toBeLessThan(500)
        ->and($chargeNet)->toBeGreaterThan(0);
});

it('still charges a fresh period when a past-due customer moves plan', function (): void {
    // The reset itself is correct and has to stay: the current period was never paid, so
    // there is nothing to prorate against and nothing to refund.
    $billable = agreementBillable(['subscription_status' => SubscriptionStatus::PastDue]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro',
        currentInterval: 'monthly',
        currentSeats: 1,
        currentAddons: [],
        newPlan: 'pro',
        newInterval: 'yearly',
        newSeats: 1,
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    $rows = array_map(fn ($line) => $line->toArray(), $lines);

    $chargeNet = array_sum(array_map(fn (array $r) => max(0, (int) $r['amount_net']), $rows));
    $creditNet = array_sum(array_map(fn (array $r) => min(0, (int) $r['amount_net']), $rows));

    expect($chargeNet)->toBe(29000)
        ->and($creditNet)->toBe(0);
});

it('shows the usage overage an interval switch will charge', function (): void {
    $billable = agreementBillable([
        'subscription_interval' => SubscriptionInterval::Yearly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(300),
    ]);

    // 11 900 of the yearly 12 000 tokens consumed, 300 of 365 days elapsed: the prorated
    // entitlement is well below what was used, so the switch to monthly (1 000 included)
    // charges the excess. The preview skipped the whole calculation for interval-only
    // changes and quoted nothing.
    $billable->creditBillingUsage('Tokens', 12000);
    $billable->recordBillingUsage('Tokens', 11900);

    $preview = MollieBilling::preview()->previewUpdate($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]);

    expect($preview['usageOverageChargeNet'] ?? 0)->toBeGreaterThan(0);
});

it('shows no usage overage when the new quota absorbs what was used', function (): void {
    $billable = agreementBillable([
        'subscription_interval' => SubscriptionInterval::Monthly,
    ]);

    $billable->creditBillingUsage('Tokens', 1000);
    $billable->recordBillingUsage('Tokens', 100);

    // Monthly → yearly: 12 000 included covers everything, so the quote stays at zero.
    // The guard has to distinguish "no overage" from "not calculated".
    $preview = MollieBilling::preview()->previewUpdate($billable, [
        'plan_code' => 'pro',
        'interval' => 'yearly',
    ]);

    expect($preview['usageOverageChargeNet'] ?? 0)->toBe(0);
});

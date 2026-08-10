<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use GraystackIT\MollieBilling\Testing\TestBillable;

// `billing:simulate` prints what the next renewal will collect. Every invoice the package
// books rounds VAT per line — round(Σ net × rate) and Σ round(net × rate) are not the same
// number — so an estimate assembled the other way disagrees with the charge by a cent or
// two, on the one screen whose whole job is to tell an operator what to expect.

it('estimates the renewal gross the way the invoice books it', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        // 550 + 550 at 19% is the documented case: 1309 rounded once, 1310 per line.
        'allowed_addons' => ['analytics'],
        'intervals' => ['monthly' => ['base_price_net' => 550, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 550]]],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Sim Co', 'email' => 'sim@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'DE',
        'active_addon_codes' => ['analytics'],
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    $billable->refresh();

    $catalog = app(SubscriptionCatalogInterface::class);
    $lines = SubscriptionAmount::lineItems($catalog, $billable, 'pro', 'monthly', 0, ['analytics']);
    $expected = BillingMoney::grossWithLineVat(
        array_map(fn (array $l): int => (int) ($l['amount_net'] ?? $l['total_net'] ?? 0), $lines),
        19.0,
    );

    $method = new ReflectionMethod(LifecycleSimulator::class, 'estimateRenewalGrossMinor');
    $method->setAccessible(true);

    expect($method->invoke(app(LifecycleSimulator::class), $billable))->toBe($expected);
});

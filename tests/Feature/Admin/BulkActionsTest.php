<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * "Mass trial extension" filtered on the plan alone, so it rewrote the billing
 * schedule of every active paying customer on that plan. And both bulk credits and
 * per-billable admin credits were not registered as purchased balance, so they
 * evaporated at the next renewal for non-rollover usage types.
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
                    'base_price_net' => 1000,
                    'seat_price_net' => null,
                    'included_usages' => ['emails' => 100],
                    'usage_overage_prices' => ['emails' => 5],
                ],
                'yearly' => [
                    'base_price_net' => 10000,
                    'seat_price_net' => null,
                    'included_usages' => ['emails' => 1200],
                    'usage_overage_prices' => ['emails' => 5],
                ],
            ],
        ],
    ]);
});

function bulkBillable(array $attributes): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'email' => 'bulk-'.uniqid().'@example.com',
        'name' => 'Bulk',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ], $attributes))->save();

    return $b->refresh();
}

it('extends only actual trials, leaving active paying customers alone', function (): void {
    $trialing = bulkBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(2),
    ]);

    // Converted customer: still carries the trial_ends_at of its finished trial.
    $paying = bulkBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'trial_ends_at' => BillingTime::nowUtc()->subMonth(),
        'mollie_customer_id' => 'cst_paying',
        'mollie_mandate_id' => 'mdt_paying',
    ]);

    // Never trialed at all.
    $neverTrialed = bulkBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_never',
        'mollie_mandate_id' => 'mdt_never',
    ]);

    $payingTrialEnd = $paying->trial_ends_at?->toIso8601String();

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('trialDays', 7)
        ->call('extendTrials')
        ->assertSet('error', null)
        ->assertSet('flash', fn (?string $f) => $f !== null && str_contains($f, '1'));

    expect($trialing->refresh()->trial_ends_at->isAfter(BillingTime::nowUtc()->addDays(6)))->toBeTrue();

    $paying->refresh();
    expect($paying->trial_ends_at?->toIso8601String())->toBe($payingTrialEnd);
    expect($paying->subscription_status)->toBe(SubscriptionStatus::Active);

    $neverTrialed->refresh();
    expect($neverTrialed->trial_ends_at)->toBeNull();
    expect($neverTrialed->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('reports that nothing matched instead of flashing success', function (): void {
    bulkBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_only',
        'mollie_mandate_id' => 'mdt_only',
    ]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('trialDays', 7)
        ->call('extendTrials')
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);
});

it('also picks up a lapsed trial that the lifecycle job has not processed yet', function (): void {
    $lapsed = bulkBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->subHours(2),
    ]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('trialDays', 7)
        ->call('extendTrials')
        ->assertSet('error', null);

    expect($lapsed->refresh()->trial_ends_at->isFuture())->toBeTrue();
});

it('registers bulk wallet credits as purchased balance so they survive a renewal reset', function (): void {
    $b = bulkBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
    ]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('creditType', 'emails')
        ->set('creditUnits', 5000)
        ->set('creditReason', 'goodwill after the March outage')
        ->call('creditWallets')
        ->assertSet('error', null);

    $wallet = $b->refresh()->getWallet('emails');
    expect($wallet)->not->toBeNull();
    expect((int) $wallet->balanceInt)->toBe(5000);
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(5000);

    // The renewal path for a non-rollover usage type rebuilds the balance from
    // quota + purchased remaining — the goodwill credit has to survive it.
    app(WalletUsageService::class)->resetAndCredit($b, 'emails', 100);

    expect((int) $b->refresh()->getWallet('emails')->balanceInt)->toBe(5100);
});

it('registers per-billable admin wallet credits as purchased balance', function (): void {
    $b = bulkBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
    ]);

    Livewire::test('mollie-billing::admin.billables.wallet-tab', ['billableId' => $b->getKey()])
        ->set('creditType', 'emails')
        ->set('creditUnits', 5000)
        ->set('creditReason', 'goodwill')
        ->call('credit')
        ->assertSet('error', null);

    $wallet = $b->refresh()->getWallet('emails');
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(5000);

    app(WalletUsageService::class)->resetAndCredit($b, 'emails', 100);

    expect((int) $b->refresh()->getWallet('emails')->balanceInt)->toBe(5100);
});

it('locks billableId on the wallet tab', function (): void {
    $mine = bulkBillable(['subscription_status' => SubscriptionStatus::Active]);
    $other = bulkBillable(['subscription_status' => SubscriptionStatus::Active]);

    $component = Livewire::test('mollie-billing::admin.billables.wallet-tab', ['billableId' => $mine->getKey()]);

    expect(fn () => $component->set('billableId', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

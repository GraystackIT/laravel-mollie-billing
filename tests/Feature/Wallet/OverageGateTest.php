<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\UsageLimitReached;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

// `hasBillingQuotaLeft()` (the gate an app asks before doing work) and the hard cap
// in `WalletUsageService::debit()` (the enforcement) both resolve through
// `canChargeBillingOverage()`. These tests pin that they cannot disagree.
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['emails' => 100, 'sms' => 10],
                // `emails` is chargeable, `sms` deliberately is not.
                'usage_overage_prices' => ['emails' => 5, 'sms' => false],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            // A positive `emails` overage price on purpose: the Local cases below must
            // fail on `isLocalBillingSubscription()` alone, not because the price is
            // missing — that condition already has its own tests on the `pro` plan.
            'monthly' => [
                'base_price_net' => 0,
                'seat_price_net' => null,
                'included_usages' => ['emails' => 10],
                'usage_overage_prices' => ['emails' => 5],
            ],
        ],
    ]);
});

function makeOverageBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'gate@example.test']);
    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'mollie_mandate_id' => 'mdt_test_1',
        'mollie_customer_id' => 'cst_test_1',
        'allows_billing_overage' => true,
    ], $overrides))->save();

    return $b->refresh();
}

// ── canChargeBillingOverage: all four conditions ──

it('allows overage for a Mollie billable with mandate, opt-in and a configured price', function (): void {
    expect(makeOverageBillable()->canChargeBillingOverage('emails'))->toBeTrue();
});

it('refuses overage when no price is configured for the usage type', function (): void {
    // `notmetered` has neither quota nor price on this plan.
    expect(makeOverageBillable()->canChargeBillingOverage('notmetered'))->toBeFalse();
});

it('refuses overage for a usage type declared as overage => false', function (): void {
    expect(makeOverageBillable()->canChargeBillingOverage('sms'))->toBeFalse();
});

it('refuses overage when the billable opted out', function (): void {
    expect(makeOverageBillable(['allows_billing_overage' => false])->canChargeBillingOverage('emails'))->toBeFalse();
});

it('refuses overage without a Mollie mandate', function (): void {
    expect(makeOverageBillable(['mollie_mandate_id' => null])->canChargeBillingOverage('emails'))->toBeFalse();
});

it('refuses overage on a Local subscription that still carries a mandate', function (): void {
    // Real state after a downgrade to a free plan: source flips to Local but
    // `mollie_mandate_id` is never cleared. The old gate consulted the mandate
    // alone and wrongly reported quota left while debit() threw.
    $billable = makeOverageBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
    ]);

    expect($billable->hasMollieMandate())->toBeTrue()
        ->and($billable->canChargeBillingOverage('emails'))->toBeFalse();
});

// ── Gate and enforcement agree ──

it('reports quota left at zero balance only when the overage is chargeable', function (): void {
    $billable = makeOverageBillable();

    expect($billable->remainingBillingQuota('emails'))->toBe(0)
        ->and($billable->hasBillingQuotaLeft('emails'))->toBeTrue()
        ->and($billable->hasBillingQuotaLeft('sms'))->toBeFalse();
});

it('reports quota left while a positive balance exists even without chargeable overage', function (): void {
    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'sms', 10);

    expect($billable->hasBillingQuotaLeft('sms'))->toBeTrue();
});

it('lets a chargeable usage type go negative', function (): void {
    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'emails', 10);

    app(WalletUsageService::class)->debit($billable, 'emails', 25);

    expect($billable->getWallet('emails')->refresh()->balanceInt)->toBe(-15);
});

it('hard-caps a usage type whose overage is declared as false', function (): void {
    Event::fake([UsageLimitReached::class]);

    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'sms', 10);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'sms', 25))
        ->toThrow(UsageLimitExceededException::class);

    expect($billable->getWallet('sms')->refresh()->balanceInt)->toBe(10);
    Event::assertDispatched(UsageLimitReached::class);
});

it('hard-caps a usage type with no configured overage price at all', function (): void {
    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'notmetered', 5);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'notmetered', 10))
        ->toThrow(UsageLimitExceededException::class);
});

it('hard-caps a Local subscription that still carries a mandate', function (): void {
    $billable = makeOverageBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
    ]);
    app(WalletUsageService::class)->credit($billable, 'emails', 5);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'emails', 10))
        ->toThrow(UsageLimitExceededException::class);
});

it('evaluates the overage policy on the refreshed billable, not the caller model', function (): void {
    // The caller holds a model loaded before a downgrade to the free plan was
    // committed elsewhere. Without the refresh in debit() the stale Mollie/pro
    // state would permit a negative balance nothing can ever collect.
    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'emails', 5);

    TestBillable::query()->whereKey($billable->getKey())->update([
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_plan_code' => 'free',
    ]);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'emails', 10))
        ->toThrow(UsageLimitExceededException::class);

    expect($billable->getWallet('emails')->refresh()->balanceInt)->toBe(5);
});

it('still allows a debit within the remaining balance when overage is not chargeable', function (): void {
    $billable = makeOverageBillable();
    app(WalletUsageService::class)->credit($billable, 'sms', 10);

    app(WalletUsageService::class)->debit($billable, 'sms', 10);

    expect($billable->getWallet('sms')->refresh()->balanceInt)->toBe(0);
});

// ── Threshold notification tells the truth ──

it('announces overage in the threshold notification only when it is chargeable', function (): void {
    $billable = makeOverageBillable();

    $chargeable = (new UsageThresholdNotification($billable, 'emails', 80))->toArray($billable);
    $notChargeable = (new UsageThresholdNotification($billable, 'sms', 80))->toArray($billable);

    expect($chargeable['overage_allowed'])->toBeTrue()
        ->and($notChargeable['overage_allowed'])->toBeFalse();
});

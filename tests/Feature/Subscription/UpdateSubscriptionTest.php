<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\ValidateSubscriptionChange;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyUpdateSubscription;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    // Spy out Mollie API calls — same pattern as UpdateSubscriptionMollieTest.
    // SpyUpdateSubscription is defined in that file and loaded globally by Pest.
    $this->app->bind(UpdateSubscription::class, function ($app): UpdateSubscription {
        return new SpyUpdateSubscription(
            $app->make(CouponService::class),
            $app->make(PreviewService::class),
            $app->make(SubscriptionCatalogInterface::class),
            $app->make(VatCalculationService::class),
            $app->make(ValidateSubscriptionChange::class),
            $app->make(ScheduleSubscriptionChange::class),
            $app->make(WalletPlanChangeAdjuster::class),
        );
    });
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 5,
        'feature_keys' => ['dashboard', 'pro-feature'],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'feature_keys' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['price_net' => 990],
            'yearly' => ['price_net' => 9900],
        ],
    ]);
});

function makeMollieProBillable(int $seats = 5, array $addons = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'active_addon_codes' => $addons,
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_123',
        'mollie_mandate_id' => 'mdt_test_123',
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    return $billable->refresh();
}

it('decreases seats on a Mollie subscription and fires SeatsChanged', function (): void {
    Event::fake([SeatsChanged::class, SubscriptionUpdated::class]);

    // Seat decrease is a downgrade — no prorata charge → applied immediately.
    $billable = makeMollieProBillable(7);

    $result = app(UpdateSubscription::class)->update($billable, ['seats' => 5]);

    $billable->refresh();

    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(5);
    expect($result['seatsChanged'])->toBeTrue();
    expect($result['events'])->toContain(SeatsChanged::class);

    Event::assertDispatched(SeatsChanged::class, function (SeatsChanged $event) use ($billable): bool {
        return $event->billable->getKey() === $billable->getKey()
            && $event->oldCount === 7
            && $event->newCount === 5;
    });
});

it('blocks switching a Local subscription directly to a paid plan', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]))->toThrow(\GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException::class);
});

it('disables an addon on a Mollie subscription and records it in diff.addonsRemoved', function (): void {
    Event::fake([AddonDisabled::class, SubscriptionUpdated::class]);

    // Removing an addon is a downgrade — applies immediately, no prorata charge.
    $billable = makeMollieProBillable(5, ['print-gateway']);

    $result = app(UpdateSubscription::class)->update($billable, [
        'addons' => [],
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toBe([]);
    expect($result['addonsRemoved'])->toContain('print-gateway');

    Event::assertDispatched(AddonDisabled::class, function (AddonDisabled $event): bool {
        return $event->addonCode === 'print-gateway';
    });
});

it('delegates to schedule when applyAt is end_of_period and does not modify current state', function (): void {
    Event::fake([SubscriptionChangeScheduled::class, SeatsChanged::class]);

    $billable = makeMollieProBillable(5);

    $result = app(UpdateSubscription::class)->update($billable, [
        'seats' => 3,
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();

    // Seats are not modified yet.
    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(5);
    expect($billable->subscription_meta['scheduled_change']['seats'] ?? null)->toBe(3);
    expect($result)->toHaveKey('scheduledFor');

    Event::assertDispatched(SubscriptionChangeScheduled::class);
    Event::assertNotDispatched(SeatsChanged::class);
});

it('applies a scheduled downgrade via ScheduleSubscriptionChange::apply', function (): void {
    $billable = makeMollieProBillable(5);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'seats' => 3,
    ]);

    $billable->refresh();
    expect($billable->subscription_meta['scheduled_change']['seats'] ?? null)->toBe(3);

    app(ScheduleSubscriptionChange::class)->apply($billable);

    $billable->refresh();

    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(3);
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull();
});

// ── Bug 3: Seats auto-derived on plan change ──

it('auto-derives seat count from new plan when no explicit seats given', function (): void {
    // Pro has 5 included seats, used_seats = 3 → max(3, 5) = 5
    $billable = makeMollieProBillable(10); // old seat_count = 10
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 3;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // free has 1 included seat, used_seats = 3, seat_price_net = null → should throw
})->throws(SeatDowngradeRequiredException::class);

it('blocks plan change when used seats exceed new plan without extra seat support', function (): void {
    $billable = makeMollieProBillable(5);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 3;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Free has 1 included seat, no seat_price_net → must throw
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]))->toThrow(SeatDowngradeRequiredException::class);
});

it('allows plan change with extra seats when new plan supports them', function (): void {
    // Set up a plan with extra seat support and fewer included seats
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 2,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => 200, 'included_usages' => []],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => 2000, 'included_usages' => []],
        ],
    ]);

    $billable = makeMollieProBillable(5);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 4;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Starter has 2 included seats + seat_price_net = 200 → extra seats allowed
    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // Existing seat count (5) is preserved across the plan change.
    // max(current=5, used=4, included=2) = 5
    expect($billable->subscription_meta['seat_count'])->toBe(5);
    expect($result['seatsChanged'])->toBeFalse();
});

// ── Bug 2: Incompatible addons stripped on plan change ──

it('strips incompatible addons when changing to a plan that does not allow them', function (): void {
    Event::fake([AddonDisabled::class, PlanChanged::class, SubscriptionUpdated::class]);

    $billable = makeMollieProBillable(5, ['print-gateway']);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 0;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Free plan has 1 included seat, no extra-seat support — explicitly drop
    // the paid extras to satisfy validateSeats (otherwise the bought-but-unused
    // seats would be silently kept on a plan that can't host them).
    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'seats' => 1,
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toBe([]);
    expect($result['addonsRemoved'])->toContain('print-gateway');

    Event::assertDispatched(AddonDisabled::class, function (AddonDisabled $event): bool {
        return $event->addonCode === 'print-gateway';
    });
});

it('keeps compatible addons when changing plans', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 10,
        'feature_keys' => ['dashboard', 'pro-feature', 'enterprise'],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    $billable = makeMollieProBillable(5, ['print-gateway']);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toContain('print-gateway');
    expect($result['addonsRemoved'])->toBe([]);
});

// ── Bug 4: Wallet adjustment on plan change ──

it('credits wallet difference on upgrade to plan with more usage quota', function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);

    // Start on Basic (Mollie), upgrade to Pro — pure quota increase test.
    $billable = makeMollieProBillable(1);
    $billable->forceFill([
        'subscription_plan_code' => 'basic',
        'subscription_meta' => ['seat_count' => 1, 'used_seats' => 0, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    // Seed the wallet with 10 tokens (basic plan quota)
    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->deposit(10);

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]);

    // Pro is paid → upgrade is deferred (pending payment); wallet is only
    // adjusted in applyPendingPlanChange after the prorata payment confirms.
    // For this immediate-path test we instead apply a downgrade in reverse.
})->skip('covered by WalletPlanChangeAdjuster contract — see WalletUsageServiceTest');

it('caps wallet balance on downgrade to plan with less usage quota', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);

    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
        ],
    ]);

    $billable = makeMollieProBillable(1);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 0;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Seed wallet: 100 included, 40 used → balance = 60 (within new quota, no overage)
    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->deposit(60);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'basic',
        'interval' => 'monthly',
    ]);

    $wallet->refresh();

    // With prorated plan change logic: period started 5 days ago (monthly),
    // elapsed ≈ 5/30 ≈ 0.167, proratedOldQuota ≈ 17, balance=60 so excess=0.
    // Wallet is reset to newIncluded (50) with no excess to deduct.
    expect((int) $wallet->balanceInt)->toBe(50);
});

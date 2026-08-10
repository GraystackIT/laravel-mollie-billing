<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * Everything about metered billing is declared in `mollie-billing-plans.php`, and the
 * declarations combine: a quota may be absent or set, an overage price may be absent,
 * explicitly `false`, or a number, and a type may roll over, not roll over, or say nothing
 * and inherit `usage_rollover_fallback`. That is a matrix, and the individual behaviours
 * were each pinned somewhere while the combinations were not.
 *
 * These walk it. The invariants under test are the ones a customer's bill depends on:
 *
 * 1. No overage price means a hard cap — the balance cannot go negative, because nothing
 *    could ever collect it.
 * 2. `false` behaves exactly like absent. It is the documented way to say "this quota has
 *    no overage", and a 0 would otherwise read as a €0.00 unit price.
 * 3. A negative balance is debt and is never silently forgiven — not by a renewal, not by
 *    a plan change.
 * 4. Rollover carries the positive remainder; non-rollover levels it. Neither loses
 *    purchased credits.
 * 5. Quotas are per (plan, interval), so a renewal on the other interval credits that
 *    interval's number.
 */
beforeEach(function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('api')->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_matrix', 'status' => 'open']);
});

/**
 * @param  array{included?: int|null, overage?: int|false|null, rollover?: bool|null}  $spec
 */
function usageMatrixPlans(array $spec, ?array $yearlySpec = null): void
{
    $build = function (array $s, int $base): array {
        $interval = ['base_price_net' => $base, 'seat_price_net' => null];

        if (array_key_exists('included', $s) && $s['included'] !== null) {
            $interval['included_usages'] = ['SMS' => $s['included']];
        } else {
            $interval['included_usages'] = [];
        }

        if (array_key_exists('overage', $s) && $s['overage'] !== null) {
            $interval['usage_overage_prices'] = ['SMS' => $s['overage']];
        }

        return $interval;
    };

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => [
            'monthly' => $build($spec, 1000),
            'yearly' => $build($yearlySpec ?? $spec, 10000),
        ],
    ]);

    // A second plan at the same shape, for plan-change cases.
    config()->set('mollie-billing-plans.plans.lite', [
        'name' => 'Lite', 'tier' => 1, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => $build($spec, 500), 'yearly' => $build($yearlySpec ?? $spec, 5000)],
    ]);

    if (array_key_exists('rollover', $spec) && $spec['rollover'] !== null) {
        config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => $spec['rollover']]]);
    } else {
        // Undeclared on purpose — the fallback decides.
        config()->set('mollie-billing-plans.usage_types', []);
    }
}

function usageMatrixBillable(int $startingBalance = 0, bool $local = false): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Matrix', 'email' => uniqid('mx-').'@x.test']);
    $b->forceFill([
        'subscription_source' => $local ? SubscriptionSource::Local : SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'billing_country' => 'AT',
        'mollie_customer_id' => $local ? null : 'cst_mx',
        'mollie_mandate_id' => $local ? null : 'mdt_mx',
        'allows_billing_overage' => true,
    ])->save();

    $b = $b->refresh();
    $wallet = $b->createWallet(['name' => 'SMS', 'slug' => 'SMS']);

    if ($startingBalance > 0) {
        $wallet->deposit($startingBalance);
    } elseif ($startingBalance < 0) {
        $wallet->forceWithdraw(abs($startingBalance));
    }

    return $b->refresh();
}

function usageMatrixBalance(TestBillable $billable): int
{
    return (int) $billable->refresh()->getWallet('SMS')->refresh()->balanceInt;
}

// ── 1. The hard cap ───────────────────────────────────────────────────────────

it('caps a quota with no overage price instead of going negative', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => null, 'rollover' => false]);
    $billable = usageMatrixBillable(100);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'SMS', 150))
        ->toThrow(UsageLimitExceededException::class)
        ->and(usageMatrixBalance($billable))->toBe(100);
});

it('treats an overage price of false exactly like an absent one', function (): void {
    // The documented way to say "this quota has no overage". A 0 would render as a
    // €0.00 unit price in the checkout's usage list, which is why it is not spelled 0.
    usageMatrixPlans(['included' => 100, 'overage' => false, 'rollover' => false]);
    $billable = usageMatrixBillable(100);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'SMS', 150))
        ->toThrow(UsageLimitExceededException::class)
        ->and(usageMatrixBalance($billable))->toBe(100)
        ->and($billable->billingOveragePrice('SMS'))->toBeNull();
});

it('lets a priced quota go negative', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(100);

    app(WalletUsageService::class)->debit($billable, 'SMS', 150);

    expect(usageMatrixBalance($billable))->toBe(-50);
});

it('caps a pay-per-use type that has no overage price either', function (): void {
    // No quota and no price is a type that is simply not sold on this plan.
    usageMatrixPlans(['included' => null, 'overage' => null, 'rollover' => false]);
    $billable = usageMatrixBillable(0);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'SMS', 10))
        ->toThrow(UsageLimitExceededException::class)
        ->and(usageMatrixBalance($billable))->toBe(0);
});

it('lets a pay-per-use type go negative from zero', function (): void {
    usageMatrixPlans(['included' => null, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(0);

    app(WalletUsageService::class)->debit($billable, 'SMS', 40);

    expect(usageMatrixBalance($billable))->toBe(-40);
});

// ── 2. What the period-end charge picks up ────────────────────────────────────

it('bills every negative wallet that has a price, quota or not', function (): void {
    usageMatrixPlans(['included' => null, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(-40);

    $items = (new ReflectionMethod(ChargeUsageOverageDirectly::class, 'buildLineItemsFromWallets'))
        ->invoke(app(ChargeUsageOverageDirectly::class), $billable);

    $sms = collect($items)->firstWhere('code', 'SMS');

    expect($sms)->not->toBeNull()
        ->and((int) $sms['quantity'])->toBe(40)
        ->and((int) $sms['total_net'])->toBe(200);
});

it('bills nothing for a negative wallet whose price was removed', function (): void {
    // The balance can only have gone negative while a price existed. Removing it later
    // stops new consumption; there is nothing left that could collect the old debt, and
    // inventing a 0-priced line would book a €0 invoice instead of saying so.
    usageMatrixPlans(['included' => 100, 'overage' => false, 'rollover' => false]);
    $billable = usageMatrixBillable(-40);

    $items = (new ReflectionMethod(ChargeUsageOverageDirectly::class, 'buildLineItemsFromWallets'))
        ->invoke(app(ChargeUsageOverageDirectly::class), $billable);

    expect(collect($items)->firstWhere('code', 'SMS'))->toBeNull();
});

// ── 3. Renewal: reset vs rollover ─────────────────────────────────────────────

it('levels a non-rollover quota to the plan number on renewal', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(30);

    app(WalletUsageService::class)->resetAndCredit($billable, 'SMS', 100, 'subscription_renewal');

    expect(usageMatrixBalance($billable))->toBe(100);
});

it('adds a rollover quota on top of the remainder', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);
    $billable = usageMatrixBillable(30);

    app(WalletUsageService::class)->creditRollover($billable, 'SMS', 100);

    expect(usageMatrixBalance($billable))->toBe(130);
});

it('keeps the debt on renewal while its charge is still settling', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(-37);

    app(WalletUsageService::class)->resetAndCredit($billable, 'SMS', 100, 'subscription_renewal', preserveDebt: true);

    // −37 + 100. Forgiving it and then crediting the charged units when the overage
    // settles handed the customer those units for free, every cycle.
    expect(usageMatrixBalance($billable))->toBe(63);
});

it('clears a debt on renewal when nothing is collecting it', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(-37);

    app(WalletUsageService::class)->resetAndCredit($billable, 'SMS', 100, 'subscription_renewal');

    expect(usageMatrixBalance($billable))->toBe(100);
});

it('never levels a rollover wallet, so its debt survives the renewal', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);
    $billable = usageMatrixBillable(-37);

    app(WalletUsageService::class)->creditRollover($billable, 'SMS', 100);

    expect(usageMatrixBalance($billable))->toBe(63);
});

// ── 4. The rollover fallback for an undeclared type ───────────────────────────

it('inherits usage_rollover_fallback for a type that declares nothing', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => null]);

    config()->set('mollie-billing.usage_rollover_fallback', true);
    expect(app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class)->usageRollover('SMS'))->toBeTrue();

    config()->set('mollie-billing.usage_rollover_fallback', false);
    expect(app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class)->usageRollover('SMS'))->toBeFalse();
});

it('lets an explicit declaration outrank the fallback', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    config()->set('mollie-billing.usage_rollover_fallback', true);

    expect(app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class)->usageRollover('SMS'))->toBeFalse();
});

// ── 5. Quotas are per (plan, interval) ────────────────────────────────────────

it('credits the target interval quota, not the source one', function (): void {
    usageMatrixPlans(
        ['included' => 100, 'overage' => 5, 'rollover' => false],
        ['included' => 1200, 'overage' => 5, 'rollover' => false],
    );
    // Untouched quota, so nothing is prorated away and the number is the target
    // interval's own — a monthly 100 must not carry into a yearly 1200.
    $billable = usageMatrixBillable(100);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'pro', 'yearly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    expect(usageMatrixBalance($billable))->toBe(1200);
});

it('carries the rollover remainder into the new plan', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);
    $billable = usageMatrixBillable(130);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    // 30 carried over the old quota, plus the new plan's 100.
    expect(usageMatrixBalance($billable))->toBe(130);
});

it('does not carry a remainder for a non-rollover type', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    $billable = usageMatrixBillable(130);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    expect(usageMatrixBalance($billable))->toBe(100);
});

it('drops a wallet to zero when the target plan does not sell the type', function (): void {
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);
    config()->set('mollie-billing-plans.plans.lite.intervals.monthly.included_usages', []);
    config()->set('mollie-billing-plans.plans.lite.intervals.monthly.usage_overage_prices', []);

    $billable = usageMatrixBillable(60);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    expect(usageMatrixBalance($billable))->toBe(0);
});

it('cuts a customer off when only one interval of a plan sells the type', function (): void {
    // A usage type declared on `monthly` and forgotten on `yearly` is a config mistake with
    // no symptom until somebody switches interval: the quota resolves to 0, and with no
    // overage price on that side the hard cap turns the customer's next call into an
    // exception. Same plan, same subscription, feature gone.
    usageMatrixPlans(
        ['included' => 100, 'overage' => 5, 'rollover' => false],
        ['included' => null, 'overage' => null, 'rollover' => false],
    );

    $billable = usageMatrixBillable(100);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'pro', 'yearly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Yearly])->save();

    expect(usageMatrixBalance($billable))->toBe(0)
        ->and(fn () => app(WalletUsageService::class)->debit($billable->refresh(), 'SMS', 1))
        ->toThrow(UsageLimitExceededException::class);
});

it('keeps purchased credits whole across a rollover plan change', function (): void {
    // Two things at once, and they are not the same thing.
    //
    // Purchased credits are a separate ledger on the same wallet: consumed last, never
    // prorated, and carried through a plan change untouched.
    //
    // Rollover carries what sits *above* the old plan's allowance — credits an earlier
    // period already handed forward. The current period's unused allowance is not carried,
    // because the plan change ends that period early and settles it proportionally. That is
    // deliberately different from a renewal, which has no period to settle and simply adds
    // the new quota on top. See docs/usage-billing.md, "Plan Change: Usage Settlement".
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);

    $billable = usageMatrixBillable(0);
    $wallet = $billable->getWallet('SMS');
    $wallet->deposit(100);                                   // plan quota
    WalletUsageService::addPurchasedBalance($wallet, 500);
    $wallet->deposit(500);                                   // purchased pack

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(), 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    $after = $billable->refresh()->getWallet('SMS')->refresh();

    // Nothing above the old allowance, so nothing rolls over: 100 new quota + 500 purchased.
    expect((int) $after->balanceInt)->toBe(600)
        ->and(WalletUsageService::getPurchasedBalance($after))->toBe(500);
});

it('does not let a plan change eat purchased credits to cover excess', function (): void {
    // The plan half is exhausted and then some; the excess is charged, not taken out of
    // credits the customer bought separately.
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => false]);

    $billable = usageMatrixBillable(0);
    $wallet = $billable->getWallet('SMS');
    WalletUsageService::addPurchasedBalance($wallet, 300);
    $wallet->deposit(300);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(), 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    $after = $billable->refresh()->getWallet('SMS')->refresh();

    expect(WalletUsageService::getPurchasedBalance($after))->toBe(300)
        ->and((int) $after->balanceInt)->toBeGreaterThanOrEqual(300);
});

it('carries only what sits above the old allowance on a plan change', function (): void {
    // The documented example: 100/mo, balance 250, half the period elapsed, new quota 80.
    // 150 of those 250 were carried in from earlier periods and stay; the 100 belonging to
    // this allowance are settled with the period and replaced by the new plan's 80.
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);
    config()->set('mollie-billing-plans.plans.lite.intervals.monthly.included_usages', ['SMS' => 80]);

    $billable = usageMatrixBillable(250);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable, 'pro', 'monthly', 'lite', 'monthly',
        now()->subDays(15), now()->addDays(15), deferOverageCharge: true,
    );

    expect(usageMatrixBalance($billable))->toBe(230);
});

it('adds the new quota on top at a renewal, allowance included', function (): void {
    // The other side of the pair: a renewal ends no period early, so there is nothing to
    // settle and the unused allowance stays. 100 unused + 100 credited = 200, where the
    // same starting point through a plan change gives 100.
    usageMatrixPlans(['included' => 100, 'overage' => 5, 'rollover' => true]);
    $billable = usageMatrixBillable(100);

    app(WalletUsageService::class)->creditRollover($billable, 'SMS', 100);

    expect(usageMatrixBalance($billable))->toBe(200);
});

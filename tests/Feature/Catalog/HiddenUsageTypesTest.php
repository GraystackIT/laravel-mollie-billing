<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;

/*
 * A usage type can be kept off the plan cards (`usage_types.{type}.visible = false`) while
 * it is credited, metered, capped and charged exactly like a visible one — and stays on
 * every surface where the customer reads what was used or what is billed.
 *
 * `Webhooks` is the hidden type throughout, declared in lower case under `usage_types` so
 * that every lookup below also proves it is case-insensitive. `Tokens` is the visible one.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900, 'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 100, 'Webhooks' => 1000],
                    'usage_overage_prices' => ['Tokens' => 7, 'Webhooks' => 3],
                ],
                'yearly' => [
                    'base_price_net' => 29000, 'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 1200, 'Webhooks' => 12000],
                    'usage_overage_prices' => ['Tokens' => 7, 'Webhooks' => 3],
                ],
            ],
        ],
        'max' => [
            'name' => 'Max', 'tier' => 3, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 4900, 'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 500, 'Webhooks' => 5000],
                    'usage_overage_prices' => ['Tokens' => 5, 'Webhooks' => 2],
                ],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => false],
        'webhooks' => ['rollover' => false, 'visible' => false],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    MollieBilling::authUsing(fn (): bool => true);
});

function hiddenUsageBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Hidden Usage Co', 'email' => uniqid('hu-').'@x.test', 'billing_country' => 'AT', 'tax_country_user' => 'AT']);
    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_hu', 'seat_count' => 1],
        'mollie_customer_id' => 'cst_hu',
        'mollie_mandate_id' => 'mdt_hu',
        'allows_billing_overage' => true,
        'billing_street' => 'Teststr. 1', 'billing_postal_code' => '1010', 'billing_city' => 'Wien',
    ], $overrides))->save();

    $b = $b->fresh();
    MollieBilling::resolveBillableUsing(fn () => $b->fresh());

    return $b;
}

// ── The catalog ────────────────────────────────────────────────────────────────

it('reads visibility from usage_types, and only a literal false hides', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->usageTypeIsVisible('Webhooks'))->toBeFalse()
        // An entry without `visible`, and no entry at all, are visible.
        ->and($catalog->usageTypeIsVisible('Tokens'))->toBeTrue()
        ->and($catalog->usageTypeIsVisible('never-declared'))->toBeTrue();

    config()->set('mollie-billing-plans.usage_types.Tokens.visible', true);
    expect($catalog->usageTypeIsVisible('Tokens'))->toBeTrue();
});

it('does not hide on a visible flag that is not the boolean false', function (mixed $value): void {
    config()->set('mollie-billing-plans.usage_types.webhooks.visible', $value);

    expect(app(SubscriptionCatalogInterface::class)->usageTypeIsVisible('Webhooks'))->toBeTrue();
})->with(['string false' => 'false', 'zero' => 0, 'null' => null, 'string no' => 'no']);

it('looks a usage type up case-insensitively', function (string $asked): void {
    expect(app(SubscriptionCatalogInterface::class)->usageTypeIsVisible($asked))->toBeFalse();
})->with(['Webhooks', 'webhooks', 'WEBHOOKS']);

it('lists only the visible included usages of a plan and interval, in their order', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->visibleIncludedUsages('pro', 'monthly'))->toBe(['Tokens' => 100])
        ->and($catalog->visibleIncludedUsages('pro', 'yearly'))->toBe(['Tokens' => 1200])
        ->and($catalog->visibleIncludedUsages('pro', null))->toBe([])
        // The quota itself is untouched: hiding is presentation only.
        ->and($catalog->includedUsages('pro', 'monthly'))->toBe(['Tokens' => 100, 'Webhooks' => 1000])
        ->and($catalog->includedUsage('pro', 'monthly', 'Webhooks'))->toBe(1000)
        ->and($catalog->usageOveragePrice('pro', 'monthly', 'Webhooks'))->toBe(3);

    config()->set('mollie-billing-plans.usage_types.webhooks.visible', true);

    expect($catalog->visibleIncludedUsages('pro', 'monthly'))->toBe(['Tokens' => 100, 'Webhooks' => 1000]);
});

// ── billing:check-config ───────────────────────────────────────────────────────

it('rejects a visible flag on a usage type that is not a boolean', function (mixed $value): void {
    config()->set('mollie-billing-plans.usage_types.webhooks.visible', $value);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_types.webhooks.visible must be a boolean')
        ->assertExitCode(1);
})->with(['false', 'no', 0, 1]);

it('warns when a hidden usage type carries an overage price in any plan or interval', function (): void {
    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_types.webhooks.visible is false, but plans.pro.intervals.monthly.usage_overage_prices.Webhooks charges for it')
        ->expectsOutputToContain('plans.pro.intervals.yearly.usage_overage_prices.Webhooks')
        ->expectsOutputToContain('plans.max.intervals.monthly.usage_overage_prices.Webhooks');
});

it('does not warn about a hidden usage type that has no overage price', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices.Webhooks', false);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.usage_overage_prices.Webhooks', null);
    config()->set('mollie-billing-plans.plans.max.intervals.monthly.usage_overage_prices', ['Tokens' => 5]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('usage_types.webhooks.visible');
});

it('does not warn about a hidden usage type whose overage price is 0', function (): void {
    // A price of 0 charges nothing: the customer pays for nothing the card leaves out.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices.Webhooks', 0);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.usage_overage_prices.Webhooks', 0);
    config()->set('mollie-billing-plans.plans.max.intervals.monthly.usage_overage_prices.Webhooks', 0);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('usage_types.webhooks.visible');
});

it('does not warn about a priced usage type that is visible', function (): void {
    config()->set('mollie-billing-plans.usage_types.webhooks.visible', true);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('usage_types.webhooks.visible');
});

// ── Plan cards: the hidden type is not named ──────────────────────────────────

it('keeps a hidden usage type off the checkout plan cards', function (): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100, 'Webhooks' => 1000]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 7, 'Webhooks' => 3]);
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => false],
        'webhooks' => ['rollover' => false, 'visible' => false],
    ]);
    $m->billable('AT');

    $checkout = Livewire::test('mollie-billing::checkout');

    expect($checkout->instance()->planUsages('pro', 'monthly'))->toBe([
        'Tokens' => ['label' => 'Tokens', 'included' => 100, 'overage_price' => 7],
    ]);

    $checkout->assertSee(__('billing::checkout.usage_included', ['count' => '100', 'type' => 'Tokens']))
        ->assertDontSee('Webhooks');
});

it('keeps a hidden usage type off the plan-change cards', function (): void {
    hiddenUsageBillable();

    Livewire::test('mollie-billing::plan-change')
        ->assertSee(__('billing::portal.usage_included_count', ['count' => '100', 'type' => 'Tokens']))
        ->assertSee(__('billing::portal.usage_included_count', ['count' => '500', 'type' => 'Tokens']))
        ->assertDontSee('Webhooks');
});

// ── Where the customer reads what was used or billed: still there ─────────────

it('still shows a hidden usage type on the dashboard usage meters and the usage page', function (): void {
    $billable = hiddenUsageBillable();
    $billable->creditBillingUsage('Tokens', 100);
    $billable->creditBillingUsage('Webhooks', 1000);
    $billable->recordBillingUsage('Webhooks', 250);

    $dashboard = Livewire::test('mollie-billing::dashboard');
    $types = array_column($dashboard->instance()->dashboardData($billable->fresh())['usageTypes'], 'type');

    expect($types)->toBe(['Tokens', 'Webhooks']);
    $dashboard->assertSee('Webhooks');

    Livewire::test('mollie-billing::usage-history')->assertSee('Webhooks');
});

it('still credits, caps and charges a hidden usage type like any other', function (): void {
    $billable = hiddenUsageBillable();
    $billable->creditBillingUsage('Webhooks', 1000);

    // Priced: the wallet goes into overage …
    app(WalletUsageService::class)->debit($billable, 'Webhooks', 1040);
    expect((int) $billable->fresh()->getWallet('Webhooks')->refresh()->balanceInt)->toBe(-40)
        ->and($billable->fresh()->billingOveragePrice('Webhooks'))->toBe(3);

    // … and the period-end charge bills it at its price.
    $items = (new ReflectionMethod(ChargeUsageOverageDirectly::class, 'buildLineItemsFromWallets'))
        ->invoke(app(ChargeUsageOverageDirectly::class), $billable->fresh());
    $line = collect($items)->firstWhere('code', 'Webhooks');

    expect($line)->not->toBeNull()
        ->and((int) $line['quantity'])->toBe(40)
        ->and((int) $line['total_net'])->toBe(120);

    // Unpriced: the hard cap holds for a hidden type too.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices.Webhooks', false);
    $capped = hiddenUsageBillable();
    $capped->creditBillingUsage('Webhooks', 10);

    expect(fn () => app(WalletUsageService::class)->debit($capped, 'Webhooks', 11))
        ->toThrow(UsageLimitExceededException::class);
});

it('credits a hidden usage type on activation', function (): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100, 'Webhooks' => 1000]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 7, 'Webhooks' => 3]);
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => false],
        'webhooks' => ['rollover' => false, 'visible' => false],
    ]);
    $billable = $m->billable('AT');

    $checkout = $m->checkout('pro');
    $checkout->call('submit');
    $m->settle($m->lastPayment());

    expect($billable->fresh()->remainingBillingQuota('Webhooks'))->toBe(1000)
        ->and($billable->fresh()->remainingBillingQuota('Tokens'))->toBe(100);
});

it('still quotes and shows the overage of a hidden usage type in the plan-change preview', function (): void {
    // 11 900 of the yearly 12 000 consumed after 300 of 365 days: switching to monthly
    // (1 000 included) leaves overage the change charges.
    $billable = hiddenUsageBillable([
        'subscription_interval' => SubscriptionInterval::Yearly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(300),
    ]);
    $billable->creditBillingUsage('Webhooks', 12000);
    $billable->recordBillingUsage('Webhooks', 11900);

    $preview = MollieBilling::preview()->previewUpdate($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]);

    expect($preview['usageOverageChargeNet'] ?? 0)->toBeGreaterThan(0)
        ->and(array_keys($preview['usageChanges'] ?? []))->toContain('Webhooks')
        ->and($preview['usageChanges']['Webhooks']['unresolved_overage'] ?? 0)->toBeGreaterThan(0);

    // The screen names it in the usage column and on the overage line of what is due now.
    Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'monthly')
        ->call('previewFor', 'pro')
        ->assertSee(__('billing::portal.usage').' · Webhooks')
        ->assertSee(__('billing::portal.preview_usage_overage_charge', [
            'count' => number_format($preview['usageChanges']['Webhooks']['unresolved_overage']),
        ]))
        ->assertSee(__('billing::portal.invoice_line_overage', ['type' => 'Webhooks']));
});

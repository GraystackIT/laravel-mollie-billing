<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * How many decimals an outbound Mollie amount carries is a property of the
 * currency, not the constant 2. JPY has none, so `number_format($cents / 100, 2)`
 * turned a ¥2900 charge into ¥29 while the invoice still booked 2900 minor units.
 *
 * Every service that builds a `Mollie\Api\Http\Data\Money` is covered here: the
 * bug is not in the arithmetic, it is in forgetting to ask the currency.
 *
 * VAT is pinned to 0% so each case isolates the scale question — the per-line VAT
 * rounding it interacts with lives in PerLineVatChargeTest.
 */
beforeEach(function (): void {
    config()->set('mollie-billing.currency', 'JPY');
    config()->set('mollie-billing.vat_rate_overrides', ['AT' => 0]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2900,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 5],
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
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => 'Token Pack',
            'price_net' => 2900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
    ]);
});

/** The `amount` a captured Mollie request carries, wherever in the hierarchy it is declared. */
function scaleTestMollieAmount(object $request): object
{
    for ($class = new ReflectionObject($request); $class !== false; $class = $class->getParentClass()) {
        if ($class->hasProperty('amount')) {
            $property = $class->getProperty('amount');
            $property->setAccessible(true);

            return $property->getValue($request);
        }
    }

    throw new RuntimeException('Captured request carries no amount.');
}

/** Capture the single outbound Mollie request the callback triggers. */
function scaleTestCapture(callable $act): object
{
    $captured = [];

    // Some of these paths carry a stable idempotency key (the usage-overage charge does — see
    // MollieCall::send()). The amount is what this file measures, so the key is merely allowed.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured): object {
        $captured[] = $request;

        return (object) ['id' => 'tr_scale_'.count($captured), 'status' => 'open'];
    });

    $act();

    expect($captured)->not->toBeEmpty();

    return scaleTestMollieAmount($captured[0]);
}

function scaleTestBillable(SubscriptionSource $source = SubscriptionSource::Mollie): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Scale Test',
        'email' => 'scale@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_scale',
        'mollie_mandate_id' => 'mdt_scale',
    ]);

    $billable->forceFill([
        'subscription_source' => $source,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $source === SubscriptionSource::Local ? 'free' : 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_scale'],
        'allows_billing_overage' => true,
    ])->save();

    return $billable->refresh();
}

it('sends the subscription checkout amount in whole yen', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Checkout',
        'email' => 'checkout@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_scale',
    ]);

    $amount = scaleTestCapture(fn () => app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 2900,
    ]));

    expect($amount->currency)->toBe('JPY')
        ->and($amount->value)->toBe('2900');
});

it('sends the zero mandate-authorisation amount in whole yen', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Mandate',
        'email' => 'mandate@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_scale',
    ]);

    // The literal '0.00' was rejected outright for a 0-decimal currency.
    $amount = scaleTestCapture(fn () => app(StartMandateCheckout::class)->handle($billable->fresh()));

    expect($amount->value)->toBe('0');
});

it('sends the recurring subscription amount in whole yen', function (): void {
    $billable = scaleTestBillable();

    $amount = scaleTestCapture(fn () => app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]));

    expect($amount->value)->toBe('2900');
});

it('sends the local-to-Mollie upgrade amount in whole yen', function (): void {
    $billable = scaleTestBillable(SubscriptionSource::Local);

    $amount = scaleTestCapture(fn () => app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 2900,
    ]));

    expect($amount->value)->toBe('2900');
});

it('sends the one-time order amount in whole yen', function (): void {
    $billable = scaleTestBillable();
    request()->setLaravelSession(app('session.store'));

    $amount = scaleTestCapture(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'token-pack',
    ]));

    expect($amount->value)->toBe('2900');
});

it('patches the Mollie subscription amount in whole yen', function (): void {
    $billable = scaleTestBillable();

    $amount = scaleTestCapture(fn () => app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'pro',
        interval: 'monthly',
        addons: [],
        extraSeats: 0,
    ));

    expect($amount->value)->toBe('2900');
});

it('sends the usage-overage amount in whole yen', function (): void {
    $billable = scaleTestBillable();
    $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens'])->forceWithdraw(580);

    // 580 units over quota × 5 = 2900.
    $amount = scaleTestCapture(fn () => app(ChargeUsageOverageDirectly::class)->handle($billable->refresh()));

    expect($amount->value)->toBe('2900');
});

it('still spells a two-decimal currency with its decimals', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    $billable = scaleTestBillable();

    $amount = scaleTestCapture(fn () => app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]));

    expect($amount->currency)->toBe('EUR')
        ->and($amount->value)->toBe('29.00');
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function fakeUpgradePaymentResponse(string $id, string $checkoutUrl): object
{
    return new class($id, $checkoutUrl) {
        public string $id;
        private string $checkoutUrl;

        public function __construct(string $id, string $checkoutUrl)
        {
            $this->id = $id;
            $this->checkoutUrl = $checkoutUrl;
        }

        public function getCheckoutUrl(): string
        {
            return $this->checkoutUrl;
        }
    };
}

function makeUpgradeLocalBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_existing',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('creates a Mollie payment with upgrade_from_local metadata', function (): void {
    $billable = makeUpgradeLocalBillable();
    $captured = null;

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$captured) {
            $captured = $request;
            return $request instanceof CreatePaymentRequest;
        })
        ->andReturn(fakeUpgradePaymentResponse('tr_upgrade_1', 'https://checkout.mollie.com/upgrade'));

    $service = app(UpgradeLocalToMollie::class);

    $result = $service->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 3500,
    ]);

    expect($result['payment_id'])->toBe('tr_upgrade_1');
    expect($result['checkout_url'])->toBe('https://checkout.mollie.com/upgrade');

    // Inspect the metadata on the captured request via reflection.
    $reflection = new ReflectionObject($captured);
    $metadataProp = $reflection->getProperty('metadata');
    $metadataProp->setAccessible(true);
    $metadata = $metadataProp->getValue($captured);

    expect($metadata['type'])->toBe('subscription');
    expect($metadata['plan_code'])->toBe('pro');
    expect($metadata['upgrade_from_local'])->toBeTrue();
});

it('reuses an existing Mollie customer ID', function (): void {
    $billable = makeUpgradeLocalBillable();

    // Only the CreatePaymentRequest should hit Mollie — no CreateCustomerRequest.
    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(fn ($request) => $request instanceof CreatePaymentRequest)
        ->andReturn(fakeUpgradePaymentResponse('tr_upgrade_2', 'https://checkout.mollie.com/upgrade'));

    app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3500,
    ]);

    $billable->refresh();
    expect($billable->mollie_customer_id)->toBe('cst_existing');
});

it('rejects when the subscription is not local', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now(),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    expect(fn () => app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3500,
    ]))->toThrow(LocalSubscriptionUpgradeRequiresMolliePathException::class);
});

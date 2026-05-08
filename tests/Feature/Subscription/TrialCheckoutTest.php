<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1900,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => [],
            ],
            'yearly' => [
                'base_price_net' => 19000,
                'seat_price_net' => null,
                'included_usages' => [],
            ],
        ],
    ]);
});

function makeTrialBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trial@x.test',
        'billing_country' => 'AT',
    ]);

    return $billable->refresh();
}

it('routes a paid plan with interval-level trial_days to the mandate-only flow', function (): void {
    $billable = makeTrialBillable();

    $captured = ['payment' => null];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateCustomerRequest) {
            $c = new \stdClass;
            $c->id = 'cst_trial';

            return $c;
        }
        if ($request instanceof CreatePaymentRequest) {
            $captured['payment'] = $request;
            $p = new \stdClass;
            $p->id = 'tr_mandate_only';
            $p->getCheckoutUrl = fn () => 'https://mollie/checkout';

            return new class {
                public string $id = 'tr_mandate_only';
                public function getCheckoutUrl(): string { return 'https://mollie/checkout'; }
            };
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    $result = app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 2299, // arbitrary non-zero — would normally trigger paid flow
    ]);

    expect($result['payment_id'])->toBe('tr_mandate_only');
    expect($captured['payment'])->not->toBeNull();

    $reflection = new \ReflectionObject($captured['payment']);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    $amount = $amountProp->getValue($captured['payment']);
    expect($amount->value)->toBe('0.00');

    $metadataProp = $reflection->getProperty('metadata');
    $metadataProp->setAccessible(true);
    $metadata = $metadataProp->getValue($captured['payment']);
    expect($metadata['type'])->toBe('mandate_only');
    expect($metadata['pending_subscription_plan_code'])->toBe('pro');
    expect($metadata['pending_subscription_interval'])->toBe('monthly');
    expect($metadata['pending_subscription_trial_days'])->toBe(14);
});

it('does not trigger the trial flow for an interval without trial_days', function (): void {
    $billable = makeTrialBillable();

    $captured = ['payment' => null];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateCustomerRequest) {
            $c = new \stdClass;
            $c->id = 'cst_trial';

            return $c;
        }
        if ($request instanceof CreatePaymentRequest) {
            $captured['payment'] = $request;

            return new class {
                public string $id = 'tr_first';
                public function getCheckoutUrl(): string { return 'https://mollie/checkout'; }
            };
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    $result = app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'yearly',
        'amount_gross' => 22800,
    ]);

    expect($result['payment_id'])->toBe('tr_first');

    $reflection = new \ReflectionObject($captured['payment']);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    $amount = $amountProp->getValue($captured['payment']);
    expect($amount->value)->toBe('228.00');

    $metadataProp = $reflection->getProperty('metadata');
    $metadataProp->setAccessible(true);
    $metadata = $metadataProp->getValue($captured['payment']);
    expect($metadata['type'])->toBe('subscription');
    expect($metadata)->not->toHaveKey('pending_subscription_trial_days');
});

it('skips the trial flow when the billable already has a Mollie mandate', function (): void {
    $billable = makeTrialBillable();
    $billable->forceFill(['mollie_mandate_id' => 'mdt_existing'])->save();
    $billable->refresh();

    $captured = ['payment' => null];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateCustomerRequest) {
            $c = new \stdClass;
            $c->id = 'cst_trial';

            return $c;
        }
        if ($request instanceof CreatePaymentRequest) {
            $captured['payment'] = $request;

            return new class {
                public string $id = 'tr_paid';
                public function getCheckoutUrl(): string { return 'https://mollie/checkout'; }
            };
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 2299,
    ]);

    $reflection = new \ReflectionObject($captured['payment']);
    $metadataProp = $reflection->getProperty('metadata');
    $metadataProp->setAccessible(true);
    $metadata = $metadataProp->getValue($captured['payment']);
    expect($metadata['type'])->toBe('subscription');
    expect($metadata)->not->toHaveKey('pending_subscription_trial_days');
});

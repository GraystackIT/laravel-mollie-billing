<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Webhook\MandateOnlyPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A €0 authorisation whose activation was REFUSED is not money on its way to a
 * subscription.
 *
 * `activationInFlight()` keeps a customer out of the checkout while a paid first payment
 * waits for its activation retry — so they do not pay twice. A 100% code that lost its
 * cover between checkout and webhook (its cap filled up, an operator switched it off) also
 * leaves a retry marker, and every retry is refused the same way. Counting that marker
 * locked the customer out of buying at full price for the whole ladder (~7 h), with no
 * money collected and nothing that could be charged twice.
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
                'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
    Bus::fake([RetrySubscriptionActivationJob::class]);
});

function refusedAuthBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Refused Ltd', 'email' => uniqid('refused-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::New,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Refused Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_refused',
    ])->save();

    return $billable->refresh();
}

/** The €0 authorisation of a 100%-code checkout, as the webhook fetched it. */
function refusedAuthPayment(TestBillable $billable, string $code): object
{
    return (object) [
        'id' => 'tr_auth',
        'status' => 'paid',
        'customerId' => 'cst_refused',
        'mandateId' => 'mdt_refused',
        'countryCode' => 'AT',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => (object) [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_coupon_code' => $code,
        ],
    ];
}

/** Settle the authorisation: the code no longer exists, so its cover is gone. */
function refusedAuthSettle(TestBillable $billable): TestBillable
{
    $payment = refusedAuthPayment($billable, 'GONE100');

    app(MandateOnlyPaymentHandler::class)->paid($payment, $billable, json_decode((string) json_encode($payment->metadata), true));

    $billable = $billable->fresh();
    expect($billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_auth')
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();

    return $billable;
}

it('lets a customer whose 100% code lost its cover check out at full price', function (): void {
    $billable = refusedAuthSettle(refusedAuthBillable());
    MollieBilling::resolveBillableUsing(fn () => $billable);

    expect(app(WebhookSupport::class)->activationInFlight($billable))->toBeFalse();

    $created = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof CreatePaymentRequest) {
            $created[] = $request;

            return new class
            {
                public string $id = 'tr_full';

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/tr_full';
                }
            };
        }

        return (object) ['id' => 'cst_refused'];
    });

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_full');

    expect($created)->toHaveCount(1);
});

it('does not refuse the service for a refused authorisation', function (): void {
    $billable = refusedAuthSettle(refusedAuthBillable());

    $created = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof CreatePaymentRequest) {
            $created++;

            return new class
            {
                public string $id = 'tr_full';

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/tr_full';
                }
            };
        }

        return (object) ['id' => 'cst_refused'];
    });

    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3600,
    ]);

    expect($created)->toBe(1);
});

it('still holds the checkout while a €0 authorisation waits for a retry that can finish it', function (): void {
    // A trial whose CreateSubscription failed on a Mollie hiccup: the retry will very
    // likely activate it, and a second checkout now has a mandate — so no trial, and a
    // full charge next to the trial that is about to start.
    $billable = refusedAuthBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes) {
        $classes[] = $request::class;

        return (object) [];
    });

    // A checkout opened before the activation failed: the form is on screen.
    $openBefore = Livewire::test('mollie-billing::checkout')->assertNoRedirect();

    $billable->forceFill(['subscription_meta' => [
        ActivationRetry::META_KEY => ['tr_auth' => [
            'context' => 'mandate_only',
            'reason' => 'Mollie hiccup',
            'first_failed_at' => now()->subMinutes(2)->toIso8601String(),
        ]],
    ]])->save();
    $billable->refresh();

    // Opened now, it goes straight to the return page (decision 3, 26 November 2026)…
    Livewire::test('mollie-billing::checkout')
        ->assertRedirect(BillingRoute::url('return', $billable));

    // …and the form opened before still cannot place a second order.
    $openBefore
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect(BillingRoute::url('return', $billable));

    expect($classes)->toBe([]);
});

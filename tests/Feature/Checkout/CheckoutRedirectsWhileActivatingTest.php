<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A customer whose first payment is paid but whose subscription is not activated yet is sent
 * to the return page as soon as the checkout opens — not shown the whole form.
 *
 * `RequireActiveSubscription` sends a billable without an accessible subscription to the
 * checkout, and that is exactly the state between "Mollie took the money" and "the
 * subscription exists": the webhook still on its way, an activation running right now (a
 * fresh `activation_claim`), or one waiting on its retry ladder (`activation_retries`, ~7 h).
 * The checkout rendered every step; only the final submit asked `PendingFirstPayment` and
 * redirected to the return page. The customer walked through plans and addons for an order
 * that could never be placed. Decision 3 of 26 November 2026: redirect at mount, on the same
 * question the submit asks.
 *
 * What stays: a refused €0 authorisation (`refused_without_charge`) and an activation that
 * failed for good leave nothing in flight, so the customer can check out again; an open
 * payment on record is still judged at submit, against the order the form then holds.
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
});

/** @param array<string, mixed> $meta */
function activatingMountBillable(array $meta = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Underway Ltd', 'email' => uniqid('underway-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::New,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Underway Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_underway',
        'subscription_meta' => $meta,
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** A payment as GetPaymentRequest hydrates it. */
function activatingMountPayment(string $id, string $status, ?string $checkoutUrl = null): object
{
    return new class($id, $status, $checkoutUrl)
    {
        public object $metadata;

        public object $amount;

        public function __construct(public string $id, public string $status, private readonly ?string $checkoutUrl)
        {
            $this->metadata = (object) ['type' => 'subscription', 'plan_code' => 'pro', 'interval' => 'monthly'];
            $this->amount = (object) ['currency' => 'EUR', 'value' => '36.00'];
        }

        public function getCheckoutUrl(): ?string
        {
            return $this->checkoutUrl;
        }
    };
}

/** @return array<string, mixed> */
function activatingMountRetryMarker(bool $refusedWithoutCharge = false): array
{
    return array_filter([
        'context' => 'CreateSubscription during webhook failed',
        'reason' => 'Mollie hiccup',
        'first_failed_at' => now()->subMinutes(2)->toIso8601String(),
        'refused_without_charge' => $refusedWithoutCharge ?: null,
    ], fn ($value) => $value !== null);
}

it('sends the customer to the return page at mount while a paid activation waits for its retry', function (): void {
    $billable = activatingMountBillable([
        ActivationRetry::META_KEY => ['tr_paid' => activatingMountRetryMarker()],
    ]);

    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests) {
        $requests[] = $request::class;

        return activatingMountPayment('tr_paid', 'paid');
    });

    Livewire::test('mollie-billing::checkout')
        ->assertRedirect(BillingRoute::url('return', $billable));

    // The marker alone decides; Mollie is not asked.
    expect($requests)->toBe([]);
});

it('sends the customer to the return page at mount while an activation is running', function (): void {
    $billable = activatingMountBillable([
        WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
            'payment_id' => 'tr_paid',
            'claimed_at' => now()->subSeconds(20)->toIso8601String(),
        ],
    ]);

    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::checkout')
        ->assertRedirect(BillingRoute::url('return', $billable));
});

it('sends the customer to the return page at mount when Mollie took the money and the webhook is not here yet', function (): void {
    $billable = activatingMountBillable();
    $billable->recordPendingFirstPayment('tr_paid');

    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests) {
        $requests[] = $request::class;

        return activatingMountPayment('tr_paid', 'paid');
    });

    Livewire::test('mollie-billing::checkout')
        ->assertRedirect(BillingRoute::url('return', $billable));

    expect($requests)->toBe([GetPaymentRequest::class]);
});

it('shows the checkout for a refused €0 authorisation, which has nothing in flight', function (): void {
    activatingMountBillable([
        ActivationRetry::META_KEY => ['tr_auth' => activatingMountRetryMarker(refusedWithoutCharge: true)],
    ]);

    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::checkout')
        ->assertNoRedirect()
        ->assertSet('billing_locked', true);
});

it('shows the checkout once an activation claim has gone stale', function (): void {
    activatingMountBillable([
        WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
            'payment_id' => 'tr_dead',
            'claimed_at' => now()->subMinutes(WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES + 5)->toIso8601String(),
        ],
    ]);

    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::checkout')->assertNoRedirect();
});

it('leaves an open payment on record to the submit, which judges it against the order', function (): void {
    $billable = activatingMountBillable();
    $billable->recordPendingFirstPayment('tr_open');

    Mollie::shouldReceive('send')->andReturnUsing(fn () => activatingMountPayment('tr_open', 'open', 'https://mollie.test/checkout/tr_open'));

    Livewire::test('mollie-billing::checkout')->assertNoRedirect();
});

it('shows the checkout when Mollie cannot be asked about the payment on record', function (): void {
    $billable = activatingMountBillable();
    $billable->recordPendingFirstPayment('tr_unknown');

    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie is down'));

    Livewire::test('mollie-billing::checkout')->assertNoRedirect();
});

it('does not tell a visitor that a billable which is not theirs is activating', function (): void {
    activatingMountBillable([
        ActivationRetry::META_KEY => ['tr_paid' => activatingMountRetryMarker()],
    ]);
    MollieBilling::authUsing(fn (): bool => false);

    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::checkout')
        ->assertNoRedirect()
        ->assertSet('billing_locked', false);
});

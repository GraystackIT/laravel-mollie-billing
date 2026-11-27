<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A first payment already on record is the same checkout only while it is the same ORDER,
 * and a first payment that has been paid is not one to start again beside.
 *
 * `reusablePendingCheckoutUrl()` decided on status and checkout link alone. A customer who
 * went back from Mollie's page, picked another plan and confirmed the new total was sent to
 * the OLD payment — and paying it booked the old plan at the old price. And a payment that
 * was paid but not yet activated (webhook not here yet, or its activation waiting for a
 * retry) let a second `sequenceType: first` payment be created next to it: the customer
 * paid twice for one subscription, the second kept with no invoice and no refund.
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
                'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function pendingReuseBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Again Ltd', 'email' => uniqid('again-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Again Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_again',
    ])->save();

    return $billable->refresh();
}

/**
 * A Mollie payment as GetPaymentRequest hydrates it: metadata is an object, the amount a
 * currency/value pair.
 *
 * @param  array<string, mixed>  $metadata
 */
function pendingReusePayment(string $id, string $status, array $metadata, string $value, ?string $checkoutUrl): object
{
    return new class($id, $status, $metadata, $value, $checkoutUrl)
    {
        public object $metadata;

        public object $amount;

        /** @param array<string, mixed> $metadata */
        public function __construct(
            public string $id,
            public string $status,
            array $metadata,
            string $value,
            private readonly ?string $checkoutUrl,
        ) {
            $this->metadata = json_decode((string) json_encode((object) $metadata));
            $this->amount = (object) ['currency' => 'EUR', 'value' => $value];
        }

        public function getCheckoutUrl(): ?string
        {
            return $this->checkoutUrl;
        }
    };
}

/** The metadata StartSubscriptionCheckout writes for Pro monthly, no extras. */
function proMonthlyMetadata(TestBillable $billable): array
{
    return [
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => (string) $billable->getKey(),
        'type' => 'subscription',
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'coupon_code' => null,
    ];
}

function capturedMetadata(CreatePaymentRequest $request): array
{
    $property = (new ReflectionObject($request))->getProperty('metadata');
    $property->setAccessible(true);

    return (array) $property->getValue($request);
}

it('still sends a customer who resubmits the same order back to its open payment', function (): void {
    $billable = pendingReuseBillable();
    $billable->recordPendingFirstPayment('tr_same');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests, $billable) {
        $requests[] = $request::class;

        return pendingReusePayment('tr_same', 'open', proMonthlyMetadata($billable), '36.00', 'https://mollie.test/checkout/tr_same');
    });

    // Opening the checkout asks once whether that payment is already paid; it is open.
    $component = Livewire::test('mollie-billing::checkout')->assertNoRedirect();
    expect($requests)->toBe([GetPaymentRequest::class]);
    $requests = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_same');

    expect($requests)->toBe([GetPaymentRequest::class]);
});

it('does not send a customer who changed the order to the old payment — it is cancelled and a new one created', function (): void {
    $billable = pendingReuseBillable();
    $billable->recordPendingFirstPayment('tr_old');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // Pro MONTHLY is on record; the customer came back and confirmed Pro YEARLY.
    $sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent, $billable) {
        $sent[] = $request;

        if ($request instanceof GetPaymentRequest) {
            return pendingReusePayment('tr_old', 'open', proMonthlyMetadata($billable), '36.00', 'https://mollie.test/checkout/tr_old');
        }

        if ($request instanceof CancelPaymentRequest) {
            return pendingReusePayment('tr_old', 'canceled', proMonthlyMetadata($billable), '36.00', null);
        }

        return pendingReusePayment('tr_new', 'open', [], '360.00', 'https://mollie.test/checkout/tr_new');
    });

    // Opening the checkout asks once whether the payment on record is already paid.
    $component = Livewire::test('mollie-billing::checkout')->assertNoRedirect();
    expect(array_map(fn ($r) => $r::class, $sent))->toBe([GetPaymentRequest::class]);
    $sent = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_new');

    $classes = array_map(fn ($r) => $r::class, $sent);
    $created = collect($sent)->first(fn ($r) => $r instanceof CreatePaymentRequest);

    // The old payment is taken off the table BEFORE the new one exists, so the two can
    // never both be paid.
    expect($classes)->toBe([GetPaymentRequest::class, CancelPaymentRequest::class, CreatePaymentRequest::class])
        ->and(capturedMetadata($created)['interval'])->toBe('yearly')
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_new');
});

it('tells a customer who changed the order when the old payment cannot be withdrawn, and creates nothing', function (): void {
    $billable = pendingReuseBillable();
    $billable->recordPendingFirstPayment('tr_old');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes, $billable) {
        $classes[] = $request::class;

        if ($request instanceof CancelPaymentRequest) {
            throw new RuntimeException('The payment cannot be canceled.');
        }

        return pendingReusePayment('tr_old', 'open', proMonthlyMetadata($billable), '36.00', 'https://mollie.test/checkout/tr_old');
    });

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertNoRedirect();

    expect($classes)->not->toContain(CreatePaymentRequest::class)
        // Told as the wait notice (until when, and the link back): PendingPaymentWaitNoticeTest.
        ->and($component->get('pendingOtherOrder'))->toBeTrue()
        ->and($component->get('pendingOtherOrderUrl'))->toBe('https://mollie.test/checkout/tr_old')
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_old');
});

it('sends a customer whose first payment is paid but not yet booked to the return page instead of charging again', function (): void {
    $billable = pendingReuseBillable();
    $billable->recordPendingFirstPayment('tr_paid');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // Paid at Mollie, the webhook not here yet: the marker is still on record. The form was
    // opened while the payment was still open (a checkout opened after it was paid goes to the
    // return page at once — CheckoutRedirectsWhileActivatingTest).
    $classes = [];
    $status = 'open';
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes, &$status, $billable) {
        $classes[] = $request::class;

        return pendingReusePayment('tr_paid', $status, proMonthlyMetadata($billable), '36.00', $status === 'open' ? 'https://mollie.test/checkout/tr_paid' : null);
    });

    $component = Livewire::test('mollie-billing::checkout')->assertNoRedirect();
    $status = 'paid';
    $classes = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect(BillingRoute::url('return', $billable));

    expect($classes)->toBe([GetPaymentRequest::class]);
});

it('sends a customer whose paid activation is waiting for its retry to the return page instead of charging again', function (): void {
    $billable = pendingReuseBillable();

    $billable->forceFill(['subscription_status' => SubscriptionStatus::New])->save();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes) {
        $classes[] = $request::class;

        return pendingReusePayment('tr_b', 'open', [], '36.00', 'https://mollie.test/checkout/tr_b');
    });

    // The form was opened before; a checkout opened now goes to the return page at once
    // (CheckoutRedirectsWhileActivatingTest).
    $component = Livewire::test('mollie-billing::checkout')->assertNoRedirect();

    // CreateSubscription failed for payment A: its invoice is booked, the retry ladder runs,
    // and the webhook has already dropped the pending marker.
    $billable->forceFill(['subscription_meta' => [
        ActivationRetry::META_KEY => ['tr_a' => [
            'context' => 'CreateSubscription during webhook failed',
            'reason' => 'Mollie hiccup',
            'first_failed_at' => now()->subMinutes(2)->toIso8601String(),
        ]],
    ]])->save();
    $billable->refresh();

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect(BillingRoute::url('return', $billable));

    expect($classes)->toBe([]);
});

it('refuses a new first payment in the service while a paid activation is waiting for its retry', function (): void {
    $billable = pendingReuseBillable();
    $billable->forceFill(['subscription_status' => SubscriptionStatus::New, 'subscription_meta' => [
        ActivationRetry::META_KEY => ['tr_a' => [
            'context' => 'CreateSubscription during webhook failed',
            'reason' => 'Mollie hiccup',
            'first_failed_at' => now()->subMinutes(2)->toIso8601String(),
        ]],
    ]])->save();
    $billable->refresh();

    $classes = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$classes) {
        $classes[] = $request::class;

        return pendingReusePayment('tr_b', 'open', [], '36.00', 'https://mollie.test/checkout/tr_b');
    });

    // The seam an app's own checkout passes through.
    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3600,
    ]))->toThrow(\GraystackIT\MollieBilling\Exceptions\ActivationInFlightException::class);

    expect($classes)->toBe([]);
});

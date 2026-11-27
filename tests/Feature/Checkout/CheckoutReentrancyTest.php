<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/**
 * Two submits, one intent.
 *
 * `$processing` is component state, so two `/livewire/update` POSTs replaying their own
 * snapshots never see each other's flag. Both used to reach `StartSubscriptionCheckout`
 * and create a `sequenceType: first` payment; `recordPendingFirstPayment()` keeps only
 * the last id, so the return page polled the payment the customer did *not* pay, and the
 * other settling later was caught only by the activation gate — as a duplicate, after
 * the money had moved.
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

function reentrantBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Twice Ltd', 'email' => uniqid('twice-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Twice Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ])->save();

    return $billable->refresh();
}

it('refuses a second submit while the first is still in flight', function (): void {
    $billable = reentrantBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // The lock the first submit is holding. Taken here rather than by racing two real
    // requests, because that is exactly the state the second POST finds.
    $held = Cache::lock('billing:checkout-submit:'.$billable->getKey(), 60);
    expect($held->get())->toBeTrue();

    $created = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function () use (&$created) {
        $created++;

        return (object) ['id' => 'tr_second', 'status' => 'open'];
    });

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit');

    // No payment created, an error the customer can act on, and the button unlocked.
    expect($created)->toBe(0)
        ->and($component->get('errorMessage'))->toBe(__('billing::checkout.error_already_in_progress'))
        ->and($component->get('processing'))->toBeFalse()
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBeNull();

    $held->release();
});

it('returns the customer to a first payment that is still open instead of creating a second', function (): void {
    $billable = reentrantBillable();
    $billable->recordPendingFirstPayment('tr_first');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // The sequential case the lock alone cannot cover: the first submit has finished and
    // released, the customer hits submit again (back button, double tab) before paying.
    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests) {
        $requests[] = $request::class;

        return new class
        {
            public string $id = 'tr_first';

            public string $status = 'open';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_first';
            }
        };
    });

    $component = Livewire::test('mollie-billing::checkout');

    // Opening the checkout asks once whether that payment is already paid; it is open.
    expect($requests)->toBe([\Mollie\Api\Http\Requests\GetPaymentRequest::class]);
    $requests = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_first');

    // Exactly one Mollie call, and it was the probe — no second payment was created.
    expect($requests)->toBe([\Mollie\Api\Http\Requests\GetPaymentRequest::class])
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_first');
});

it('creates a payment when the previous one is no longer payable', function (): void {
    $billable = reentrantBillable();
    $billable->recordPendingFirstPayment('tr_expired');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // An expired or failed attempt is not a checkout to return to — the customer has to
    // be able to try again, or a single abandoned payment would lock them out forever.
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            return (object) ['id' => 'tr_expired', 'status' => 'expired'];
        }

        return new class
        {
            public string $id = 'tr_new';

            public string $status = 'open';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_new';
            }
        };
    });

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_new');

    expect($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_new');
});

it('creates a payment when the open one has lost its checkout link', function (): void {
    $billable = reentrantBillable();
    $billable->recordPendingFirstPayment('tr_left');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // "Previous page" at Mollie after picking a method: the payment stays `open`, but Mollie
    // drops its checkout link, so nobody can pay it any more. Sending the customer to the
    // return page instead — which polls that `open` payment — was a dead end they could not
    // leave until the payment expired.
    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests) {
        $requests[] = $request::class;

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            return new class
            {
                public string $id = 'tr_left';

                public string $status = 'open';

                public function getCheckoutUrl(): ?string
                {
                    return null;
                }
            };
        }

        return new class
        {
            public string $id = 'tr_new';

            public string $status = 'open';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_new';
            }
        };
    });

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_new');

    expect($requests[0])->toBe(\Mollie\Api\Http\Requests\GetPaymentRequest::class)
        ->and($requests)->toContain(\Mollie\Api\Http\Requests\CreatePaymentRequest::class)
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_new');
});

it('sends a pending first payment without a checkout link to the return page', function (): void {
    $billable = reentrantBillable();
    $billable->recordPendingFirstPayment('tr_sepa');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // `pending` has left the browser (SEPA, bank transfer) and may still be paid: no second
    // payment, the return page follows this one to its end.
    $requests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$requests) {
        $requests[] = $request::class;

        return (object) ['id' => 'tr_sepa', 'status' => 'pending'];
    });

    // Opening the checkout does not redirect for it: only a paid payment or an activation in
    // flight does (decision 3, 26 November 2026). The submit still sends it to the return page.
    $component = Livewire::test('mollie-billing::checkout')->assertNoRedirect();

    expect($requests)->toBe([\Mollie\Api\Http\Requests\GetPaymentRequest::class]);
    $requests = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertRedirect(\GraystackIT\MollieBilling\Support\BillingRoute::url('return', $billable));

    expect($requests)->toBe([\Mollie\Api\Http\Requests\GetPaymentRequest::class])
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_sepa');
});

it('releases the lock so the customer can retry after a failure', function (): void {
    $billable = reentrantBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie is down'));

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit');

    // A held lock after a failed attempt would be worse than no lock at all: the
    // customer could not check out for its full TTL.
    $after = Cache::lock('billing:checkout-submit:'.$billable->getKey(), 60);

    expect($after->get())->toBeTrue();

    $after->release();
});

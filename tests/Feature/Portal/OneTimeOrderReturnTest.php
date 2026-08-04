<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    MollieBilling::resolveBillableUsing(fn () => $billable);
});

/** Register one pending order under `$token` and return the return-page query params. */
function pendingOrder(string $token, string $paymentId): array
{
    $pending = (array) session(StartOneTimeOrderCheckout::PENDING_ORDER_SESSION_KEY, []);
    $pending[$token] = $paymentId;
    session([StartOneTimeOrderCheckout::PENDING_ORDER_SESSION_KEY => $pending]);

    return ['origin' => 'products', StartOneTimeOrderCheckout::ORDER_TOKEN_PARAM => $token];
}

/**
 * Answer every GetPaymentRequest from a payment-id => status map, so a test can
 * hold several payments in flight at once.
 *
 * @param  array<string, 'paid'|'authorized'|'canceled'|'expired'|'failed'|'open'>  $statuses
 */
function fakeOrderPaymentStatuses(array $statuses): void
{
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($statuses) {
        if (! $request instanceof GetPaymentRequest) {
            throw new LogicException('Unexpected request: '.get_class($request));
        }

        $paymentId = str_replace('payments/', '', $request->resolveResourcePath());

        if (! isset($statuses[$paymentId])) {
            throw new LogicException("Unexpected payment probed: {$paymentId}");
        }

        $payment = new stdClass;
        $payment->status = $statuses[$paymentId];

        return $payment;
    });
}

/** @param  'paid'|'authorized'|'canceled'|'expired'|'failed'|'open'  $status */
function fakeOrderPaymentStatus(string $status): void
{
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($status) {
        if ($request instanceof GetPaymentRequest) {
            $payment = new stdClass;
            $payment->status = $status;

            return $payment;
        }

        throw new LogicException('Unexpected request: '.get_class($request));
    });
}

it('reports a cancelled one-time order as failed instead of received', function (): void {
    $params = pendingOrder('tok_cancelled', 'tr_cancelled');
    fakeOrderPaymentStatus('canceled');

    Livewire::withQueryParams($params)
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('failed', true)
        ->assertSet('failureReason', 'canceled')
        ->assertSee(__('billing::portal.return.failed_title'))
        ->assertDontSee(__('billing::portal.return.title'));
});

it('reports a paid one-time order as received', function (): void {
    $params = pendingOrder('tok_paid', 'tr_paid');
    fakeOrderPaymentStatus('paid');

    Livewire::withQueryParams($params)
        ->test('mollie-billing::return')
        ->assertSet('activated', true)
        ->assertSet('failed', false)
        ->assertSee(__('billing::portal.return.body_products'));
});

it('keeps polling while a one-time order payment is still open', function (): void {
    $params = pendingOrder('tok_open', 'tr_open');
    fakeOrderPaymentStatus('open');

    Livewire::withQueryParams($params)
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('failed', false)
        ->assertSet('unresolved', false)
        ->assertSee(__('billing::portal.return.processing_title_products'));
});

it('treats an authorized one-time order as pending, not as received', function (): void {
    // Mollie holds an uncaptured authorisation. The webhook only writes the
    // invoice and credits the wallet on `paid`, so announcing success here would
    // promise goods that nothing has booked.
    $params = pendingOrder('tok_authorized', 'tr_authorized');
    fakeOrderPaymentStatus('authorized');

    Livewire::withQueryParams($params)
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('failed', false)
        ->assertSet('unresolved', false)
        ->assertSee(__('billing::portal.return.processing_title_products'))
        ->assertDontSee(__('billing::portal.return.title'));
});

it('does not claim success when no order payment is on file', function (): void {
    Livewire::withQueryParams(['origin' => 'products'])
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('unresolved', true)
        ->assertSee(__('billing::portal.return.unresolved_title'))
        ->assertDontSee(__('billing::portal.return.title'));
});

it('does not claim success for an unknown order token', function (): void {
    pendingOrder('tok_known', 'tr_known');

    Livewire::withQueryParams(['origin' => 'products', StartOneTimeOrderCheckout::ORDER_TOKEN_PARAM => 'tok_bogus'])
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('unresolved', true);
});

it('resolves each of two simultaneous checkouts against its own payment', function (): void {
    // Two tabs, two Mollie roundtrips. With a single session-wide payment id the
    // second checkout overwrote the first, so the paid order's return page read
    // the cancelled payment and sent the customer back to buy it again.
    $firstParams = pendingOrder('tok_one', 'tr_one');
    $secondParams = pendingOrder('tok_two', 'tr_two');

    fakeOrderPaymentStatuses(['tr_one' => 'paid', 'tr_two' => 'canceled']);

    Livewire::withQueryParams($firstParams)
        ->test('mollie-billing::return')
        ->assertSet('activated', true)
        ->assertSet('failed', false);

    Livewire::withQueryParams($secondParams)
        ->test('mollie-billing::return')
        ->assertSet('activated', false)
        ->assertSet('failed', true)
        ->assertSet('failureReason', 'canceled');
});

it('forgets a settled order so its token cannot be replayed', function (): void {
    $params = pendingOrder('tok_settled', 'tr_settled');
    fakeOrderPaymentStatus('paid');

    Livewire::withQueryParams($params)->test('mollie-billing::return')->assertSet('activated', true);

    expect(session(StartOneTimeOrderCheckout::PENDING_ORDER_SESSION_KEY))->not->toHaveKey('tok_settled');
});

it('times out a one-time order that never leaves the open state', function (): void {
    $params = pendingOrder('tok_stuck', 'tr_stuck');
    fakeOrderPaymentStatus('open');

    $component = Livewire::withQueryParams($params)->test('mollie-billing::return');

    for ($i = 0; $i < 22; $i++) {
        $component->call('checkStatus');
    }

    $component->assertSet('timedOut', true)
        ->assertSee(__('billing::portal.return.timeout_body_products'));
});

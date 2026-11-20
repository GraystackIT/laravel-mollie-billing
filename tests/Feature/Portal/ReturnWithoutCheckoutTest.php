<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\GetPaymentRequest;

/*
 * The return page opened without a checkout behind it — typed in, bookmarked, reopened after
 * the outcome was already reported — greeted the customer with "Activating your
 * subscription…" and polled for 90 seconds for a payment that did not exist. It now decides on
 * load whether anything is under way, and when nothing is says so: no subscription yet, and
 * the way back into the checkout. Everything that IS under way keeps its own state.
 */

/** Mollie answers a payment probe with `$status`; every probe is counted. */
function rwcMollieSays(BookingMatrix $m, ?string $status): \ArrayObject
{
    $probes = new \ArrayObject;

    $m->intercept = function (object $request) use ($status, $probes): ?object {
        if (! $request instanceof GetPaymentRequest) {
            return null;
        }

        $probes->append($request);

        return $status === null ? null : (object) ['id' => 'tr_rwc', 'status' => $status];
    };

    return $probes;
}

it('says on load that no subscription was taken out, offers the checkout and does not poll', function (string $interval): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT', ['subscription_interval' => $interval === 'yearly' ? SubscriptionInterval::Yearly : SubscriptionInterval::Monthly]);
    $probes = rwcMollieSays($m, 'open');

    $page = Livewire::test('mollie-billing::return');

    expect($page->get('noSubscription'))->toBeTrue()
        ->and($page->get('activated'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeFalse()
        ->and(count($probes))->toBe(0);

    $page->assertSee(__('billing::portal.return.no_subscription_title'))
        ->assertSee(__('billing::portal.return.continue_checkout'))
        ->assertSeeHtml('href="'.e($page->instance()->checkoutUrl()).'"')
        ->assertDontSee(__('billing::portal.return.processing_title'))
        ->assertDontSee(__('billing::portal.return.title'))
        ->assertDontSeeHtml('wire:poll');

    // A poll tick that arrives anyway changes nothing and asks Mollie nothing.
    $page->call('checkStatus');
    expect($page->get('pollCount'))->toBe(0)
        ->and(count($probes))->toBe(0)
        ->and($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();
})->with(['monthly', 'yearly']);

it('still waits for a first payment that is open at Mollie', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->recordPendingFirstPayment('tr_rwc');
    rwcMollieSays($m, 'open');

    $page = Livewire::test('mollie-billing::return');
    expect($page->get('noSubscription'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();
    $page->assertSee(__('billing::portal.return.processing_title'))->assertSeeHtml('wire:poll');

    $page->call('checkStatus');
    expect($page->get('failed'))->toBeFalse()
        ->and($page->get('activated'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();
});

/*
 * "Previous page" at Mollie leads to the redirect URL — this page — and leaves the payment
 * `open`. The page used to poll it until it expired, and the checkout sent every new attempt
 * back here: a dead end. After a grace of a few ticks it now says the payment was not
 * completed and leads back into the checkout.
 */
it('calls a first payment that stays open while the customer is here abandoned, and leads back into the checkout', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->recordPendingFirstPayment('tr_rwc');
    rwcMollieSays($m, 'open');

    $page = Livewire::test('mollie-billing::return');

    // Right after the redirect `open` is no verdict yet: some methods return before Mollie
    // has the bank's answer.
    foreach (range(1, 4) as $tick) {
        $page->call('checkStatus');
        expect($page->get('failed'))->toBeFalse("abandoned after only {$tick} tick(s)");
    }

    $page->call('checkStatus');

    expect($page->get('failed'))->toBeTrue()
        ->and($page->get('failureReason'))->toBe('abandoned')
        ->and($page->get('activated'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeFalse();
    $page->assertSee(__('billing::portal.return.abandoned_title'))
        ->assertSee(__('billing::portal.return.failed_body_abandoned'))
        ->assertSee(__('billing::portal.return.try_again'))
        ->assertSeeHtml('href="'.e($page->instance()->checkoutUrl()).'"')
        ->assertDontSee(__('billing::portal.return.processing_title'))
        ->assertDontSeeHtml('wire:poll');

    // The marker stays: the checkout decides from it whether the payment can be resumed, and
    // a payment that settles after all is still found by this page.
    expect($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_rwc')
        ->and($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();
});

it('keeps waiting for a pending first payment however long it takes', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->recordPendingFirstPayment('tr_rwc');
    rwcMollieSays($m, 'pending');

    $page = Livewire::test('mollie-billing::return');

    // `pending` has left the browser (SEPA, bank transfer): the bank decides, not the customer.
    foreach (range(1, 10) as $tick) {
        $page->call('checkStatus');
    }

    expect($page->get('failed'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();
});

it('keeps waiting for a paid payment whose webhook is late, and reports the activation when it lands', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->recordPendingFirstPayment('tr_rwc');
    rwcMollieSays($m, 'paid');

    $page = Livewire::test('mollie-billing::return');
    $page->call('checkStatus');
    expect($page->get('activated'))->toBeFalse()
        ->and($page->get('failed'))->toBeFalse()
        ->and($page->get('noSubscription'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();

    // The webhook arrives and activates.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_rwc'],
    ])->save();

    $page->call('checkStatus');
    expect($page->get('activated'))->toBeTrue();
});

it('keeps waiting while a paid activation is running or waiting for its retry', function (string $marker): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->forceFill(['subscription_meta' => match ($marker) {
        'retry' => [ActivationRetry::META_KEY => ['tr_rwc' => ['context' => 'first_payment', 'first_failed_at' => BillingTime::nowUtc()->toIso8601String()]]],
        'claim' => [WebhookSupport::ACTIVATION_CLAIM_META_KEY => ['payment_id' => 'tr_rwc', 'at' => BillingTime::nowUtc()->toIso8601String()]],
    }])->save();
    rwcMollieSays($m, null);

    $page = Livewire::test('mollie-billing::return');

    expect($page->get('noSubscription'))->toBeFalse()
        ->and($page->get('activated'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();
})->with(['retry', 'claim']);

it('reports a failed, cancelled or expired first payment, activates nothing, and opened again says there is no subscription', function (string $status): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    $billable->recordPendingFirstPayment('tr_rwc');
    rwcMollieSays($m, $status);

    $page = Livewire::test('mollie-billing::return');
    $page->call('checkStatus');

    expect($page->get('failed'))->toBeTrue()
        ->and($page->get('failureReason'))->toBe($status)
        ->and($page->get('activated'))->toBeFalse();
    $page->assertSee(__('billing::portal.return.failed_body_'.$status))
        ->assertSee(__('billing::portal.return.try_again'));

    $billable->refresh();
    expect($billable->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($billable->getPendingFirstPaymentId())->toBeNull();

    // Reopened later: nothing is under way any more, and the page does not claim otherwise.
    $again = Livewire::test('mollie-billing::return');
    expect($again->get('noSubscription'))->toBeTrue()
        ->and($again->get('failed'))->toBeFalse();
    $again->assertSee(__('billing::portal.return.no_subscription_title'));
})->with(['failed', 'canceled', 'expired']);

it('still tells an active subscription and a free plan without anything in flight apart from no subscription', function (): void {
    $m = BookingMatrix::boot();
    $active = $m->billable('AT', [
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);
    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue();

    $free = $m->billable('AT');
    app(ActivateLocalSubscription::class)->handle($free, 'free', 'monthly', []);
    $page = Livewire::test('mollie-billing::return');
    expect($page->get('activated'))->toBeTrue()->and($page->get('noSubscription'))->toBeFalse();

    // A free plan with an upgrade in flight is waited for, not called activated or absent.
    $free->refresh()->recordPendingFirstPayment('tr_upgrade');
    rwcMollieSays($m, 'open');
    $upgrading = Livewire::test('mollie-billing::return');
    expect($upgrading->get('activated'))->toBeFalse()
        ->and($upgrading->get('noSubscription'))->toBeFalse()
        ->and($upgrading->instance()->shouldPoll())->toBeTrue();
});

it('reports nothing about a billable the visitor may not see, and asks Mollie nothing about it', function (): void {
    $m = BookingMatrix::boot();
    $stranger = $m->billable('AT');
    $stranger->recordPendingFirstPayment('tr_stranger');
    $probes = rwcMollieSays($m, 'paid');

    MollieBilling::authUsing(fn (): bool => false);

    $page = Livewire::test('mollie-billing::return');
    $page->call('checkStatus');

    expect($page->get('noSubscription'))->toBeTrue()
        ->and($page->get('activated'))->toBeFalse()
        ->and(count($probes))->toBe(0);
});

it('waits for this browser\'s own signup through its session stamp, as before', function (): void {
    $m = BookingMatrix::boot();
    $signup = $m->billable('AT');
    $signup->recordPendingFirstPayment('tr_signup');
    rwcMollieSays($m, 'open');

    MollieBilling::authUsing(fn (): bool => false);
    MollieBilling::resolveBillableUsing(fn () => null);
    session([StartSubscriptionCheckout::CHECKOUT_SUBJECT_SESSION_KEY => [
        'class' => $signup->getMorphClass(),
        'id' => (string) $signup->getKey(),
    ]]);

    $page = Livewire::test('mollie-billing::return');

    expect($page->get('noSubscription'))->toBeFalse()
        ->and($page->instance()->shouldPoll())->toBeTrue();
});

it('leaves the one-time order return page as it was', function (): void {
    BookingMatrix::boot();
    $page = Livewire::withQueryParams(['origin' => 'products'])->test('mollie-billing::return');

    expect($page->get('unresolved'))->toBeTrue()
        ->and($page->get('noSubscription'))->toBeFalse();
});

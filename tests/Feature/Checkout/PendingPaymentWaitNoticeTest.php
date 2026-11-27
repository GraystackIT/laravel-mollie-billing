<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PendingFirstPayment;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * Back from Mollie with a changed order: Mollie never cancels an open first payment
 * (measured 26 November 2026, 422 / `isCancelable: false`), so the changed order waits for
 * the earlier payment's expiry. The checkout says until when, in the customer's time and
 * language, and offers the earlier payment's own checkout link while Mollie still has one.
 * See docs/decisions.md, "A changed order waits for the earlier payment, and says until when".
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

function waitNoticeBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Wait Ltd', 'email' => uniqid('wait-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Wait Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_wait',
    ])->save();

    $billable = $billable->refresh();
    $billable->recordPendingFirstPayment('tr_old');
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/**
 * The open Pro MONTHLY first payment as GetPaymentRequest hydrates it.
 *
 * @param  array<string, mixed>  $fields  expiresAt / createdAt / isCancelable as Mollie sends them
 */
function waitNoticePayment(TestBillable $billable, array $fields, ?string $checkoutUrl = 'https://mollie.test/checkout/tr_old'): object
{
    $payment = new class($checkoutUrl)
    {
        public string $id = 'tr_old';

        public string $status = 'open';

        public object $metadata;

        public object $amount;

        public function __construct(private readonly ?string $checkoutUrl)
        {
        }

        public function getCheckoutUrl(): ?string
        {
            return $this->checkoutUrl;
        }
    };

    $payment->metadata = json_decode((string) json_encode([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => (string) $billable->getKey(),
        'type' => 'subscription',
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'coupon_code' => null,
    ]));
    $payment->amount = (object) ['currency' => 'EUR', 'value' => '36.00'];

    foreach ($fields as $name => $value) {
        $payment->{$name} = $value;
    }

    return $payment;
}

it('does not ask Mollie to cancel a payment it reports as not cancelable, and says until when it stays open', function (): void {
    $billable = waitNoticeBillable();

    $sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent, $billable) {
        $sent[] = $request::class;

        if ($request instanceof CancelPaymentRequest) {
            throw new RuntimeException('The payment cannot be cancelled.');
        }

        return waitNoticePayment($billable, [
            'isCancelable' => false,
            'createdAt' => '2026-11-26T13:00:00+00:00',
            'expiresAt' => '2026-11-26T13:15:00+00:00',
        ]);
    });

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertNoRedirect();

    expect($sent)->not->toContain(CancelPaymentRequest::class)
        ->and($sent)->not->toContain(CreatePaymentRequest::class)
        ->and($component->get('pendingOtherOrder'))->toBeTrue()
        ->and($component->get('pendingOtherOrderUntil'))->toBe('2026-11-26T13:15:00+00:00')
        ->and($component->get('pendingOtherOrderUrl'))->toBe('https://mollie.test/checkout/tr_old')
        ->and($component->get('errorMessage'))->toBeNull()
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_old');
});

it('shows the expiry in the customer\'s timezone and language, with a link back to the earlier payment', function (): void {
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');
    app()->setLocale('de');
    $billable = waitNoticeBillable();

    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => waitNoticePayment($billable, [
        'isCancelable' => false,
        'expiresAt' => '2026-11-26T13:15:00+00:00',
    ]));

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertSeeHtml('dusk="pending-other-order"')
        // 13:15 UTC is 14:15 in Vienna in November.
        ->assertSeeHtml('<strong dusk="pending-other-order-until">14:15</strong>')
        ->assertSee('Uhr offen')
        ->assertSee(__('billing::checkout.pending_other_order_finish_or_wait', [], 'de'))
        ->assertSeeHtml('href="https://mollie.test/checkout/tr_old"')
        ->assertSee(__('billing::checkout.pending_other_order_resume', [], 'de'));
});

it('falls back to fifteen minutes after creation when Mollie sends no expiresAt', function (): void {
    $billable = waitNoticeBillable();

    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => waitNoticePayment($billable, [
        'isCancelable' => false,
        'createdAt' => '2026-11-26T13:02:00+00:00',
    ]));

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit');

    expect($component->get('pendingOtherOrderUntil'))->toBe('2026-11-26T13:17:00+00:00');
});

it('still withdraws a payment Mollie reports as cancelable, and starts the changed order', function (): void {
    $billable = waitNoticeBillable();

    $sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent, $billable) {
        $sent[] = $request::class;

        if ($request instanceof GetPaymentRequest) {
            return waitNoticePayment($billable, ['isCancelable' => true, 'expiresAt' => '2026-11-26T13:15:00+00:00']);
        }

        if ($request instanceof CancelPaymentRequest) {
            return (object) ['id' => 'tr_old', 'status' => 'canceled'];
        }

        $new = waitNoticePayment($billable, [], 'https://mollie.test/checkout/tr_new');
        $new->id = 'tr_new';

        return $new;
    });

    $component = Livewire::test('mollie-billing::checkout');
    $sent = [];

    $component
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertRedirect('https://mollie.test/checkout/tr_new');

    expect($sent)->toBe([GetPaymentRequest::class, CancelPaymentRequest::class, CreatePaymentRequest::class])
        ->and($component->get('pendingOtherOrder'))->toBeFalse();
});

it('offers no link when Mollie has no checkout link for the earlier payment any more, and says when to try again', function (): void {
    $billable = waitNoticeBillable();

    $pending = Mockery::mock(PendingFirstPayment::class);
    $pending->shouldReceive('activationUnderway')->andReturnNull();
    $pending->shouldReceive('redirectFor')->andThrow(new PendingPaymentForAnotherOrderException(
        $billable,
        'tr_old',
        \Carbon\CarbonImmutable::parse('2026-11-26T13:15:00+00:00'),
        null,
    ));
    app()->instance(PendingFirstPayment::class, $pending);
    Mollie::shouldReceive('send')->never();

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'yearly')
        ->call('submit')
        ->assertSeeHtml('dusk="pending-other-order"')
        ->assertSeeHtml('<strong dusk="pending-other-order-until">1:15 PM</strong>')
        ->assertSee(__('billing::checkout.pending_other_order_wait'))
        ->assertDontSeeHtml('dusk="pending-other-order-resume"');

    expect($component->get('pendingOtherOrderUrl'))->toBeNull();
});

it('keeps the earlier payment link out of the customer\'s hands: the notice state is locked', function (): void {
    waitNoticeBillable();
    Mollie::shouldReceive('send')->andReturnNull();

    expect(fn () => Livewire::test('mollie-billing::checkout')->set('pendingOtherOrderUrl', 'https://evil.test'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

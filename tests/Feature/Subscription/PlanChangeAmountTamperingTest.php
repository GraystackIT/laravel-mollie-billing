<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * `confirmAndPay()` hands `amount_gross` to Mollie verbatim and the webhook accepts
 * whatever was actually paid, so the amount must be re-priced from the catalog in the
 * same request — never read back out of the rendered preview.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 9900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

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

    $billable = $billable->refresh();
    $this->billable = $billable;

    // Captured by value: the resolver callback is static and outlives this file, so it
    // must never query — RefreshDatabase has dropped the row by the time it fires again.
    MollieBilling::resolveBillableUsing(fn () => $billable);
    MollieBilling::authUsing(fn (): bool => true);
});

function fakeTamperPayment(): object
{
    return new class
    {
        public string $id = 'tr_tamper_1';

        public function getCheckoutUrl(): string
        {
            return 'https://checkout.mollie.com/tamper';
        }
    };
}

/** The Money amount off a captured CreatePaymentRequest. */
function capturedAmountValue(CreatePaymentRequest $request): string
{
    $property = (new ReflectionObject($request))->getProperty('amount');
    $property->setAccessible(true);

    return $property->getValue($request)->value;
}

it('refuses to let the client rewrite the pricing preview', function (): void {
    expect(fn () => Livewire::test('mollie-billing::plan-change')->set('preview', ['grossTotal' => 1]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('charges the catalog price, not the amount carried in the preview', function (): void {
    $captured = null;

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$captured) {
            $captured = $request;

            return $request instanceof CreatePaymentRequest;
        })
        ->andReturn(fakeTamperPayment());

    $component = Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'yearly')
        ->call('previewFor', 'pro');

    // Stand in for a tampered snapshot: the property is #[Locked], so this is what
    // an attacker would need to achieve. confirmAndPay() must ignore it entirely.
    $component->instance()->preview['grossTotal'] = 1;

    $component->call('confirmAndPay');

    // €99.00 net + 20% AT VAT = €118.80.
    expect(capturedAmountValue($captured))->toBe('118.80');
});

it('prices the seats it actually orders — the included ones, unless the customer keeps more', function (): void {
    config()->set('mollie-billing-plans.plans.pro.included_seats', 2);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.seat_price_net', 500);

    $this->billable->forceFill([
        'subscription_meta' => ['seat_count' => 5, 'used_seats' => 2],
    ])->save();
    $this->billable->refresh();

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$captured) {
            $captured = $request;

            return $request instanceof CreatePaymentRequest;
        })
        ->andReturn(fakeTamperPayment());

    // No choice made: a plan change lands on the seats in use or included.
    $component = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro');

    expect($component->get('keepExtraSeats'))->toBeFalse()
        ->and($component->instance()->preview['newSeats'])->toBe(2);

    $component->call('confirmAndPay');

    $metadata = (new ReflectionObject($captured))->getProperty('metadata');
    $metadata->setAccessible(true);

    // The 2 included seats: no extra seats ordered, and the amount is the bare base
    // price (€29.00 + 20% = €34.80) rather than base + 3 paid seats.
    expect($metadata->getValue($captured)['extra_seats'])->toBe(0)
        ->and(capturedAmountValue($captured))->toBe('34.80');
});

it('orders and prices the extra seats when the customer chooses to keep them', function (): void {
    config()->set('mollie-billing-plans.plans.pro.included_seats', 2);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.seat_price_net', 500);

    $this->billable->forceFill([
        'subscription_meta' => ['seat_count' => 5, 'used_seats' => 2],
    ])->save();
    $this->billable->refresh();

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$captured) {
            $captured = $request;

            return $request instanceof CreatePaymentRequest;
        })
        ->andReturn(fakeTamperPayment());

    $component = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->call('toggleKeepExtraSeats');

    expect($component->get('keepExtraSeats'))->toBeTrue()
        ->and($component->instance()->preview['newSeats'])->toBe(5);

    $component->call('confirmAndPay');

    $metadata = (new ReflectionObject($captured))->getProperty('metadata');
    $metadata->setAccessible(true);

    // All five kept: three paid extras. €29.00 + 3 × €5.00 = €44.00, + 20 % = €52.80.
    expect($metadata->getValue($captured)['extra_seats'])->toBe(3)
        ->and(capturedAmountValue($captured))->toBe('52.80');
});

it('refuses to act on a plan code the client substituted', function (): void {
    // Not solvable with #[Locked]: a locked property reverts to its default on every
    // round-trip, so previewFor()'s own assignment would be lost too. Validation is the
    // guard. Without it the catalog resolved an unknown code to a free plan —
    // basePriceNet() ?? 0 plus seatPriceNet() ?? null makes isFreePlan() true — and the
    // change ran as a downgrade: a real Mollie refund of the unused period, the
    // subscription cancelled, and hasAccessibleBillingSubscription() left true forever.
    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::plan-change')
        ->set('selectedPlan', 'does-not-exist')
        ->call('applyChange');

    $this->billable->refresh();

    expect($this->billable->subscription_plan_code)->toBe('free')
        ->and($this->billable->subscription_source)->toBe(SubscriptionSource::Local);
});

it('refuses to act on an interval the catalog does not know', function (): void {
    // The interval toggle is wire:model-bound, so it cannot be locked — it is
    // validated instead. An unknown value makes every (plan, interval) lookup fall
    // through to 0/null, which is the same free-plan downgrade path.
    Mollie::shouldReceive('send')->never();

    // The interval toggle's updated hook clears the plan, so the vector is two-step:
    // set the bogus interval, then re-select a real plan (previewFor validates the plan
    // against selectablePlans(), never the interval) and apply.
    Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'weekly')
        ->call('previewFor', 'pro')
        ->call('applyChange');

    $this->billable->refresh();

    expect($this->billable->subscription_plan_code)->toBe('free')
        ->and($this->billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
});

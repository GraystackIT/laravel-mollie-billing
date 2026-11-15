<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

// `checkout_countries.include` only ever fed the country *selector*. Whether a country can
// be priced is a different list (EU member, or declared with a rate under
// `additional_countries`), and nothing tied the two together: with `include => ['GB']` —
// the example the config file itself gave —
//
//   - validateStep1() accepted the country,
//   - totals() swallowed the NonEuCountryException and priced at 0 %,
//   - submit() persisted `billing_country = GB` and sent that untaxed amount to Mollie,
//   - the webhook then threw on every delivery: money collected, no invoice, no subscription.
//
// Now the country is not offered, the form refuses it, and the service — the seam an app
// calling it directly passes through — refuses to open a payment for it.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'trial_days' => 0, 'included_usages' => []],
                'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => ['GB'], 'exclude' => []]);
    config()->set('mollie-billing.additional_countries', []);
});

/** @return array{0: \Closure, 1: \ArrayObject<int, object>} */
function recordMolliePayments(): \ArrayObject
{
    $payments = new \ArrayObject;

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($payments) {
        if ($request instanceof CreatePaymentRequest) {
            $payments->append($request);

            return new class
            {
                public string $id = 'tr_gb';

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/tr_gb';
                }
            };
        }

        return (object) ['id' => 'cst_gb'];
    });

    return $payments;
}

it('does not offer an included country that has no VAT rate', function (): void {
    expect(CountryResolver::resolve())->not->toHaveKey('GB');

    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);

    expect(CountryResolver::resolve())->toHaveKey('GB');
});

it('refuses the checkout form for a country that cannot be invoiced, before any payment exists', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'UK Ltd', 'email' => 'uk@x.test']);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::New])->save();
    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $payments = recordMolliePayments();

    Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'UK Ltd')
        ->set('billing_street', '1 High Street')
        ->set('billing_postal_code', 'N1 1AA')
        ->set('billing_city', 'London')
        ->set('billing_country', 'GB')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit')
        ->assertHasErrors('billing_country');

    expect($payments->count())->toBe(0)
        ->and($billable->fresh()->billing_country)->not->toBe('GB');
});

it('refuses to open a payment for a billable whose persisted country cannot be invoiced', function (string $interval, int $amountGross): void {
    // The state a locked checkout, an app-side import or a country removed from
    // `additional_countries` leaves behind — and the one the service is called with by an
    // app that bypasses the form.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'UK Ltd', 'email' => 'uk@x.test', 'billing_country' => 'GB']);

    $payments = recordMolliePayments();

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => $interval,
        'amount_gross' => $amountGross,
    ]))->toThrow(NonEuCountryException::class);

    expect($payments->count())->toBe(0);
})->with([
    'paid first payment' => ['monthly', 3000],
    'trial (mandate-only) checkout' => ['yearly', 30000],
]);

it('opens the payment once the country is declared with its rate', function (): void {
    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'UK Ltd', 'email' => 'uk@x.test', 'billing_country' => 'GB']);

    $payments = recordMolliePayments();

    app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3600,
    ]);

    expect($payments->count())->toBe(1);
});

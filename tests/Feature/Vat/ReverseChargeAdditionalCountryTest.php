<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

// `reverseChargeApplies()` calls itself the "single decision point … so the label and the
// amount can never disagree". `calculate()` adds a condition of its own in front of it —
// `$isEu` — that neither `reverseChargeApplies()` nor `reverseChargeCountriesQualify()`
// knows about. For a country that is priced through `additional_countries` (the documented
// way to sell to GB/CH/NO) the two therefore disagree: the display side says "reverse
// charge, net", the charging side adds the configured VAT.
//
// A validated GB number is reachable: mpociot validates `GB…` against HMRC, and
// `validateAndPersist()` stores the result like any VIES answer.

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
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);

    MollieBilling::authUsing(fn (): bool => true);
});

function gbBusinessBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'UK Ltd', 'email' => 'uk@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => '1 High Street',
        'billing_postal_code' => 'N1 1AA',
        'billing_city' => 'London',
        'billing_country' => 'GB',
        'tax_country_user' => 'GB',
        'vat_number' => 'GB123456789',
    ])->save();

    $validation = new BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'GB123456789';
    $validation->country_code = 'GB';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

it('gives one answer on reverse charge for a billable in an additional (non-EU) country', function (): void {
    $billable = gbBusinessBillable();
    $vat = app(VatCalculationService::class);

    $charged = $vat->calculate('GB', 3000, $billable);

    // usesReverseCharge() drives every portal price label; calculate() is what is charged.
    expect($billable->usesReverseCharge())->toBe($charged['vat'] === 0)
        // … and the answer is "no": reverse charge is intra-EU, the configured 20 % applies.
        ->and($billable->usesReverseCharge())->toBeFalse()
        ->and($vat->reverseChargeCountriesQualify('GB'))->toBeFalse()
        ->and($charged['gross'])->toBe(3600);
});

it('shows the checkout total the webhook will book for that billable', function (): void {
    $billable = gbBusinessBillable();
    $vat = app(VatCalculationService::class);

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    $totals = $component->instance()->totals($vat);

    // What FirstPaymentArtifacts::persist() / CreateSubscription compute for the same order.
    $booked = $vat->calculate('GB', 3000, $billable);

    expect($totals['gross'])->toBe($booked['gross'])
        ->and($totals['reverseCharge'])->toBeFalse();
});

it('keeps reverse charge for a cross-border EU business customer', function (): void {
    $billable = gbBusinessBillable();
    $billable->forceFill(['billing_country' => 'DE', 'tax_country_user' => 'DE', 'vat_number' => 'DE123456789'])->save();

    $validation = new BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'DE123456789';
    $validation->country_code = 'DE';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();

    $billable = $billable->refresh();
    $vat = app(VatCalculationService::class);

    expect($billable->usesReverseCharge())->toBeTrue()
        ->and($vat->reverseChargeCountriesQualify('DE'))->toBeTrue()
        ->and($vat->calculate('DE', 3000, $billable)['gross'])->toBe(3000);
});

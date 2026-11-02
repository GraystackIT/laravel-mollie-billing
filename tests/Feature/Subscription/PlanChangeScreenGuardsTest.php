<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.plans.flat', [
        'name' => 'Flat',
        'tier' => 1,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => [],
        // Sells no extra seats at all.
        'intervals' => ['monthly' => ['base_price_net' => 1500, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function localFreeBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Local Co',
        'email' => uniqid('local-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'billing_street' => 'Main 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Wien',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

// The preview flags seats a target plan cannot sell as a blocking error, and the view
// greys the button out on it — but `confirmAndPay()` never looked, and
// UpgradeLocalToMollie trusted the `extra_seats` it was handed. The payment was priced
// with no seat price (0 for every extra seat), and CreateSubscription then stored the
// seat count as paid-for capacity.
it('refuses a Local-to-Mollie upgrade carrying extra seats the target plan does not sell', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $billable = localFreeBillable();

    expect(fn () => app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'flat',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 3,
        'amount_gross' => 1800,
    ]))->toThrow(SeatDowngradeRequiredException::class);
});

// The two sibling forms (billing data, checkout) write the declared tax country together
// with the billing country. This one did not, so the country VAT is priced from moved
// while the evidence CountryMatchService reconciles against stayed on the old value — and
// a stale match then suppressed the mismatch the move should have opened.
it('records the declared tax country when billing details are edited on the plan screen', function (): void {
    $billable = localFreeBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::plan-change')
        ->set('company_name', 'Local Co')
        ->set('billing_street', 'Hauptstr. 2')
        ->set('billing_postal_code', '10115')
        ->set('billing_city', 'Berlin')
        ->set('billing_country', 'DE')
        ->set('vat_number', null)
        ->call('saveBillingDetails');

    $billable->refresh();

    expect($billable->getBillingCountry())->toBe('DE')
        ->and($billable->tax_country_user)->toBe('DE');
});

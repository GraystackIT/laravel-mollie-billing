<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * Who a screen is about, and what it calls things.
 *
 * Four separate places where the portal answered a question with a local copy of a rule
 * that lives somewhere else: which billable the checkout is for, whether a supply is
 * reverse-charged, what an addon is called, and — on the return page — whether the
 * customer who just paid is allowed to see their own activation.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['analytics'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 500]]],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    MollieBilling::authUsing(fn (): bool => true);
});

function portalIdentityBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Identity Ltd',
        'email' => uniqid('ident-').'@x.test',
    ]);

    $billable->forceFill($overrides)->save();

    return $billable->refresh();
}

it('checks out for the billable it resolved, even without a saved address', function (): void {
    // A fresh signup, or an org whose access grant just lapsed: authorized, resolvable,
    // no billing address yet. Adopting the identity only together with the address
    // prefill meant submit() fell through to createBillable() — the customer paid and a
    // *second* organisation was created to hold the subscription, while their own one
    // stayed unsubscribed and became eligible for orphan cleanup.
    $billable = portalIdentityBillable([
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::New,
        'billing_street' => null,
        'billing_postal_code' => null,
        'billing_city' => null,
        'billing_country' => null,
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $component = Livewire::test('mollie-billing::checkout');

    expect($component->get('billableId'))->toBe((string) $billable->getKey())
        ->and($component->get('billableClass'))->toBe($billable->getMorphClass())
        // The address question is still answered separately: nothing is prefilled and
        // the billing step is not skipped.
        ->and($component->get('billing_locked'))->toBeFalse()
        ->and($component->get('billing_street'))->toBe('');
});

it('still prefills and locks when the address is there', function (): void {
    $billable = portalIdentityBillable([
        'billing_street' => 'Prefill Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $component = Livewire::test('mollie-billing::checkout');

    expect($component->get('billableId'))->toBe((string) $billable->getKey())
        ->and($component->get('billing_locked'))->toBeTrue()
        ->and($component->get('billing_street'))->toBe('Prefill Street 1');
});

it('shows a domestic business customer domestic VAT, not reverse charge', function (): void {
    // The badge on the page that explains the customer's VAT treatment has to agree with
    // the invoices they receive. The local copy of the rule here was "valid EU VAT number
    // in an EU country", which omits the seller-country test — so an Austrian business
    // customer of an Austrian seller was told they are net-invoiced while their card was
    // charged 20 %.
    $billable = portalIdentityBillable([
        'billing_street' => 'Domestic Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'vat_number' => 'ATU12345678',
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    // The form's own "the number validated" flag is deliberately set: the old local rule
    // read it plus an EU-country list and answered true, which is the disagreement. The
    // seller-country test is the part it was missing.
    $component = Livewire::test('mollie-billing::billing-data');
    // Assigned on the instance, not through set(): the property is #[Locked], which is
    // about client updates and not about what the component itself holds.
    $component->instance()->vatNumberValid = true;

    expect($component->instance()->isReverseCharge())->toBeFalse();
});

it('labels addons through the catalog so a rebound catalog and translations both apply', function (): void {
    $billable = portalIdentityBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(3),
        'active_addon_codes' => ['analytics'],
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    // A published translation is what the catalog prefers; the dashboard read the raw
    // config value and was the one screen showing the untranslated name (or a bare code
    // against a DB-backed catalog).
    app('translator')->addLines(['addons.analytics.name' => 'Auswertungen'], 'en', 'billing');

    $component = Livewire::test('mollie-billing::dashboard');

    expect($component->instance()->dashboardData($billable)['addonLabels'] ?? [])->toContain('Auswertungen');
});

it('lets an anonymous signup see its own activation on the return page', function (): void {
    // No owner exists yet, so authorizes() fails closed — which is why the return route
    // is deliberately outside `billing.portal`. Applying the same gate inside the
    // component undid that: the page polled for ~90 seconds and then offered a Logout
    // button, seconds after the webhook had activated the subscription.
    $billable = portalIdentityBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);

    MollieBilling::authUsing(fn (): bool => false);
    MollieBilling::resolveBillableUsing(fn () => null);

    session([StartSubscriptionCheckout::CHECKOUT_SUBJECT_SESSION_KEY => [
        'class' => $billable->getMorphClass(),
        'id' => (string) $billable->getKey(),
    ]]);

    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue();
});

it('refuses to report on a billable the request names but the session does not', function (): void {
    $mine = portalIdentityBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::New,
    ]);
    $stranger = portalIdentityBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ]);

    MollieBilling::authUsing(fn (): bool => false);
    MollieBilling::resolveBillableUsing(fn () => $stranger);

    // The stamp is this browser's own checkout. A request that resolves someone else
    // must not be answered with either of them.
    session([StartSubscriptionCheckout::CHECKOUT_SUBJECT_SESSION_KEY => [
        'class' => $mine->getMorphClass(),
        'id' => (string) $mine->getKey(),
    ]]);

    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeFalse();
});

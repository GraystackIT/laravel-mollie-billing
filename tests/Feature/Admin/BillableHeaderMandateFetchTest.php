<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The admin billable header asks Mollie for the mandate once per page load, not once per
 * tab action. The header refresh (`billing-admin-billable-changed`) re-read the billable and
 * re-rendered the page, and `mandate()` called `MollieMandateInspector` — one GetMandate
 * request per admin action, for a status/plan refresh that cannot change the mandate. The
 * page now keeps the summary it fetched for a mandate id and asks again only when the id
 * changed; a full page load always fetches.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);

    $this->mandateRequests = [];

    Mollie::shouldReceive('send')->andReturnUsing(function (object $request) {
        if ($request instanceof GetMandateRequest) {
            $this->mandateRequests[] = $request->resolveResourcePath();

            return (object) [
                'id' => str_contains($request->resolveResourcePath(), 'mdt_second') ? 'mdt_second' : 'mdt_headerfetch',
                'status' => 'valid',
                'method' => 'directdebit',
                'details' => (object) ['consumerName' => 'Header Fetch GmbH', 'consumerAccount' => 'NL55INGB0000004321'],
                'mandateReference' => 'REF-1',
                'signatureDate' => '2026-01-02',
            ];
        }

        return (object) ['id' => 'sub_headerfetch'];
    });
});

function headerFetchBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Header Fetch', 'email' => uniqid('hf-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_headerfetch', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_headerfetch',
        'mollie_mandate_id' => 'mdt_headerfetch',
    ])->save();

    return $billable->fresh();
}

it('does not ask Mollie for the mandate again when a tab action refreshes the header', function (): void {
    $billable = headerFetchBillable();
    app(CancelSubscription::class)->handle($billable, false, 'customer');

    $page = Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]])
        ->assertSee('4321');

    expect($this->mandateRequests)->toHaveCount(1);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()])
        ->call('resubscribe')
        ->assertDispatched('billing-admin-billable-changed');

    $page->dispatch('billing-admin-billable-changed')
        ->assertSee(SubscriptionStatus::Active->label())
        ->assertSee('4321')
        ->assertSee('Header Fetch GmbH');

    expect($this->mandateRequests)->toHaveCount(1);
});

it('asks again when the mandate itself changed, and on every full page load', function (): void {
    $billable = headerFetchBillable();

    $page = Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);
    expect($this->mandateRequests)->toHaveCount(1);

    $billable->forceFill(['mollie_mandate_id' => 'mdt_second'])->save();
    $page->dispatch('billing-admin-billable-changed');

    expect($this->mandateRequests)->toHaveCount(2)
        ->and($this->mandateRequests[1])->toContain('mdt_second');

    $billable->forceFill(['mollie_mandate_id' => null])->save();
    $page->dispatch('billing-admin-billable-changed')
        ->assertSee(__('billing::admin.billables.show.mandate_none'));

    expect($this->mandateRequests)->toHaveCount(2);

    Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);
    Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);

    // No mandate id: nothing to ask. Back to a mandate: each full load asks once.
    expect($this->mandateRequests)->toHaveCount(2);

    $billable->forceFill(['mollie_mandate_id' => 'mdt_headerfetch'])->save();
    Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);
    Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);

    expect($this->mandateRequests)->toHaveCount(4);
});

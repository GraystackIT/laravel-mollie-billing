<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The admin billable page renders its header (name, status badge, plan, mandate) in the
 * page component and every tab as a separate child component. A tab action changed the
 * subscription and re-rendered only the tab: the header kept saying "Cancelled" after a
 * resume until the operator reloaded. Every action of the subscription tab that reached
 * the service now announces `billing-admin-billable-changed`, and the page listens for it.
 */

const HEADER_REFRESH_EVENT = 'billing-admin-billable-changed';

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_headerrefresh']);

    // The page asks Mollie for the mandate on every render; not what is tested here.
    $inspector = Mockery::mock(MollieMandateInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(null);
    app()->instance(MollieMandateInspector::class, $inspector);
});

function headerRefreshPaying(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Header Refresh', 'email' => uniqid('hr-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_headerrefresh', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_headerrefresh',
        'mollie_mandate_id' => 'mdt_headerrefresh',
        ...$overrides,
    ])->save();

    return $billable->fresh();
}

function headerRefreshTab(TestBillable $billable): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()]);
}

it('announces a cancel to the page', function (bool $immediately): void {
    $billable = headerRefreshPaying();

    headerRefreshTab($billable)
        ->call('openCancel', $immediately)
        ->assertNotDispatched(HEADER_REFRESH_EVENT)
        ->set('cancelNote', 'Asked for by phone')
        ->call('confirmCancel')
        ->assertSet('error', null)
        ->assertDispatched(HEADER_REFRESH_EVENT);

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
})->with([true, false]);

it('does not announce a cancel the tab refused before reaching the service', function (): void {
    headerRefreshTab(headerRefreshPaying())
        ->call('openCancel', true)
        ->call('confirmCancel')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.cancel_note_required'))
        ->assertNotDispatched(HEADER_REFRESH_EVENT);
});

it('announces a resume to the page', function (): void {
    $billable = headerRefreshPaying();
    app(CancelSubscription::class)->handle($billable, false, 'customer');

    headerRefreshTab($billable->fresh())
        ->call('resubscribe')
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.resubscribed'))
        ->assertDispatched(HEADER_REFRESH_EVENT);

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('announces a resume refused because somebody else already resumed, so the stale header catches up', function (): void {
    // The tab still offered "resume" from its last render; the subscription is active by
    // now. The header shows the same stale "Cancelled", and the refusal is the moment to fix it.
    headerRefreshTab(headerRefreshPaying())
        ->call('resubscribe')
        ->assertNotSet('error', null)
        ->assertDispatched(HEADER_REFRESH_EVENT);
});

it('announces a trial extension to the page', function (): void {
    $billable = headerRefreshPaying([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(3),
    ]);

    headerRefreshTab($billable)
        ->set('trialUntil', BillingTime::nowUtc()->addDays(20)->format('Y-m-d'))
        ->call('extendTrial')
        ->assertSet('error', null)
        ->assertDispatched(HEADER_REFRESH_EVENT);
});

it('announces the free-time actions to the page', function (string $action, string $field): void {
    headerRefreshTab(headerRefreshPaying())
        ->set($field, '2')
        ->call($action)
        ->assertSet('error', null)
        ->assertDispatched(HEADER_REFRESH_EVENT);
})->with([
    'extend by days' => ['extendSubscription', 'extendDays'],
    'free plan periods' => ['giveFreePlanPeriods', 'freePeriods'],
]);

it('does not announce a free-time action refused for its input', function (): void {
    headerRefreshTab(headerRefreshPaying())
        ->set('extendDays', '0')
        ->call('extendSubscription')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.extension_number_invalid'))
        ->assertNotDispatched(HEADER_REFRESH_EVENT);
});

it('announces a revoked grant to the page', function (): void {
    $billable = headerRefreshPaying();
    $waiver = app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    headerRefreshTab($billable->fresh())
        ->call('openRevoke', $waiver->id)
        ->set('revokeNote', 'Given to the wrong tenant')
        ->call('revokeGrant')
        ->assertSet('error', null)
        ->assertDispatched(HEADER_REFRESH_EVENT);
});

it('the page listens for the announcement and re-reads the billable for its header', function (): void {
    $billable = headerRefreshPaying(['subscription_status' => SubscriptionStatus::Cancelled]);

    $page = Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);

    // What the browser routes on: an event reaches a component only if it is in the
    // listeners the component announced when it rendered.
    expect(SupportEvents::getListenerEventNames($page->instance()))->toContain(HEADER_REFRESH_EVENT);

    $page->assertSee(SubscriptionStatus::Cancelled->label());

    $billable->forceFill(['subscription_status' => SubscriptionStatus::Active])->save();

    $page->dispatch(HEADER_REFRESH_EVENT)
        ->assertSee(SubscriptionStatus::Active->label())
        ->assertDontSee(SubscriptionStatus::Cancelled->label());
});

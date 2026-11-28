<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PendingFirstPayment;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The checkout's wait notice on the other screens that start a first payment: the upgrade
 * from a free plan (plan screen) and the purchase of extras on a gift (addons, seats). An
 * earlier payment for another order is still open and Mollie would not withdraw it — the
 * screen says until when it stays open and links to it, instead of a generic error. See
 * docs/decisions.md, "The wait notice is the same on every screen that starts a first payment".
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
    ]);
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');

    MollieBilling::authUsing(fn (): bool => true);

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_wait_screens');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    // The refusal comes from the mocked service; any Mollie call means it was not there.
    Mollie::shouldReceive('send')->never();
});

function waitScreensRefusal(TestBillable $billable, ?string $url = 'https://mollie.test/checkout/tr_earlier'): PendingPaymentForAnotherOrderException
{
    return new PendingPaymentForAnotherOrderException(
        $billable,
        'tr_earlier',
        CarbonImmutable::parse('2026-11-26T13:15:00+00:00'),
        $url,
    );
}

function waitScreensFreeTenant(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Wait Free Co', 'email' => uniqid('wait-free-').'@x.test', 'billing_country' => 'AT', 'mollie_customer_id' => 'cst_wait_screens']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();
    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function waitScreensGiftedTenant(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Wait Gift Co', 'email' => uniqid('wait-gift-').'@x.test', 'billing_country' => 'AT']);
    $coupons = app(CouponService::class);
    $coupons->redeem($coupons->accessGrantCoupon('GRANT-W'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], 60), $billable->fresh(), []);
    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** The extras purchase refuses with the wait, then — when `$then` is given — succeeds with it. */
function waitScreensExtrasPurchase(TestBillable $billable, ?string $url = 'https://mollie.test/checkout/tr_earlier', ?string $then = null): void
{
    $purchase = Mockery::mock(StartGrantExtrasPurchase::class, [app(UpgradeLocalToMollie::class), app(SubscriptionCatalogInterface::class)])->makePartial();
    $expectation = $purchase->shouldReceive('handle');

    $then === null
        ? $expectation->andThrow(waitScreensRefusal($billable, $url))
        : $expectation->andReturnUsing(function () use ($billable, $url, $then): string {
            static $calls = 0;

            if ($calls++ === 0) {
                throw waitScreensRefusal($billable, $url);
            }

            return $then;
        });

    app()->instance(StartGrantExtrasPurchase::class, $purchase);
}

it('the plan screen shows the wait notice with the expiry and the link when an upgrade meets an earlier open payment', function (): void {
    app()->setLocale('de');
    $billable = waitScreensFreeTenant();

    $pending = Mockery::mock(PendingFirstPayment::class);
    $pending->shouldReceive('activationUnderway')->andReturnNull();
    $pending->shouldReceive('redirectFor')->once()->andThrow(waitScreensRefusal($billable));
    app()->instance(PendingFirstPayment::class, $pending);

    $component = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->call('confirmAndPay')
        ->assertNoRedirect()
        ->assertSeeHtml('dusk="pending-other-order"')
        // 13:15 UTC is 14:15 in Vienna in November.
        ->assertSeeHtml('<strong dusk="pending-other-order-until">14:15</strong>')
        ->assertSee(__('billing::checkout.pending_other_order_finish_or_wait', [], 'de'))
        ->assertSeeHtml('href="https://mollie.test/checkout/tr_earlier"')
        ->assertSee(__('billing::checkout.pending_other_order_resume', [], 'de'));

    expect($component->get('pendingOtherOrder'))->toBeTrue()
        ->and($component->get('pendingOtherOrderUntil'))->toBe('2026-11-26T13:15:00+00:00')
        ->and($component->get('pendingOtherOrderUrl'))->toBe('https://mollie.test/checkout/tr_earlier')
        ->and($billable->fresh()->getBillingSubscriptionPlanCode())->toBe('free');
});

it('the addons screen shows the wait notice when a gifted tenant\'s addon purchase meets an earlier open payment', function (): void {
    $billable = waitScreensGiftedTenant();
    waitScreensExtrasPurchase($billable);

    $component = Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'sso')
        ->assertNoRedirect()
        ->assertSeeHtml('dusk="pending-other-order"')
        ->assertSeeHtml('<strong dusk="pending-other-order-until">2:15 PM</strong>')
        ->assertSee(__('billing::checkout.pending_other_order_finish_or_wait'))
        ->assertSeeHtml('href="https://mollie.test/checkout/tr_earlier"');

    expect($component->get('pendingOtherOrder'))->toBeTrue()
        ->and($billable->fresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('the seats screen shows the wait notice instead of the generic message when a gifted tenant\'s seat purchase meets an earlier open payment', function (): void {
    $billable = waitScreensGiftedTenant();
    waitScreensExtrasPurchase($billable);

    $component = Livewire::test('mollie-billing::seats')
        ->set('seatCount', 3)
        ->call('syncSeats')
        ->assertNoRedirect()
        ->assertSet('flash', null)
        ->assertSeeHtml('dusk="pending-other-order"')
        ->assertSeeHtml('<strong dusk="pending-other-order-until">2:15 PM</strong>')
        ->assertSeeHtml('dusk="pending-other-order-resume"');

    expect($component->get('pendingOtherOrderUrl'))->toBe('https://mollie.test/checkout/tr_earlier')
        ->and($billable->fresh()->getBillingSeatCount())->toBe(2);
});

it('links only to an https checkout: any other link is dropped and the notice says to wait', function (string $url): void {
    $billable = waitScreensGiftedTenant();
    waitScreensExtrasPurchase($billable, $url);

    $component = Livewire::test('mollie-billing::seats')
        ->set('seatCount', 3)
        ->call('syncSeats')
        ->assertSeeHtml('dusk="pending-other-order"')
        ->assertSee(__('billing::checkout.pending_other_order_wait'))
        ->assertDontSeeHtml('dusk="pending-other-order-resume"')
        ->assertDontSeeHtml($url);

    expect($component->get('pendingOtherOrderUrl'))->toBeNull();
})->with([
    'plain http elsewhere' => 'http://mollie.test/checkout/tr_earlier',
    'javascript' => 'javascript:alert(1)',
    'relative' => '/checkout/tr_earlier',
]);

it('clears the notice when the purchase is tried again and goes through', function (): void {
    $billable = waitScreensGiftedTenant();
    waitScreensExtrasPurchase($billable, then: 'https://mollie.test/checkout/tr_new');

    $component = Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'sso');

    expect($component->get('pendingOtherOrder'))->toBeTrue();

    $component->call('enableAddon', 'sso')
        ->assertRedirect('https://mollie.test/checkout/tr_new');

    expect($component->get('pendingOtherOrder'))->toBeFalse()
        ->and($component->get('pendingOtherOrderUntil'))->toBeNull()
        ->and($component->get('pendingOtherOrderUrl'))->toBeNull();
});

it('keeps the earlier payment link out of the customer\'s hands on the portal screens: the notice state is locked', function (string $screen): void {
    waitScreensGiftedTenant();

    expect(fn () => Livewire::test('mollie-billing::'.$screen)->set('pendingOtherOrderUrl', 'https://evil.test'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
})->with(['plan-change', 'addons', 'seats']);

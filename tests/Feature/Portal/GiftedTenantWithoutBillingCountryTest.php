<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\BillingCountryRequiredException;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Livewire\Livewire;

/*
 * A tenant who was GIVEN their plan by an operator may never have been through a checkout,
 * and so may have no billing address. Buying paid extras needs the billing country (VAT).
 * The purchase was refused with the pricing probe's exception — "VAT cannot be calculated
 * for country []" — which the addons and seats screens could only show as the generic
 * "something went wrong". They now say what is missing and where to add it.
 */

function giftedWithoutCountry(BookingMatrix $m, ?string $country = null): TestBillable
{
    $billable = $m->billable('AT');

    $service = app(CouponService::class);
    $coupon = $service->accessGrantCoupon('GIFT-NOCOUNTRY', 'pro', 'monthly', [], 90);
    $service->validate('GIFT-NOCOUNTRY', $billable->fresh(), []);
    $service->redeem($coupon, $billable->fresh(), []);

    $billable->forceFill(['billing_country' => $country, 'tax_country_user' => $country])->save();

    return $billable->fresh();
}

it('refuses the purchase with a dedicated exception, before Mollie is called', function (): void {
    $m = BookingMatrix::boot();
    $billable = giftedWithoutCountry($m);

    $thrown = null;
    try {
        app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 2);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    // Its own type — and still the one existing callers catch.
    expect($thrown)->toBeInstanceOf(BillingCountryRequiredException::class)
        ->and($thrown)->toBeInstanceOf(NonEuCountryException::class)
        ->and($thrown->getMessage())->toContain('billing details')
        ->and($m->sent)->toBe([]);
});

it('tells a gifted tenant on the addons screen to complete the billing details', function (string $locale): void {
    app()->setLocale($locale);
    $m = BookingMatrix::boot();
    giftedWithoutCountry($m);

    $message = __('billing::portal.flash.billing_details_required', ['page' => __('billing::portal.nav.billing_data')]);
    expect($message)->not->toContain('billing::');

    $page = Livewire::test('mollie-billing::addons')
        ->assertSee($message)
        // The button to the billing-details page is a Flux component, which the feature
        // suite does not compile — its target is in the markup as written.
        ->assertSeeHtml("BillingRoute::name('billing-data')");

    // And buying anyway creates no payment and changes nothing.
    $page->call('enableAddon', 'sso')->assertOk();

    expect($m->sent)->toBe([])
        ->and($m->billable('AT'))->not->toBeNull();
})->with(['en', 'de']);

it('tells a gifted tenant on the seats screen to complete the billing details', function (string $locale): void {
    app()->setLocale($locale);
    $m = BookingMatrix::boot();
    $billable = giftedWithoutCountry($m);

    $message = __('billing::portal.flash.billing_details_required', ['page' => __('billing::portal.nav.billing_data')]);

    $page = Livewire::test('mollie-billing::seats')
        ->assertSee($message)
        // The button to the billing-details page is a Flux component, which the feature
        // suite does not compile — its target is in the markup as written.
        ->assertSeeHtml("BillingRoute::name('billing-data')");

    // Asking for a paid seat anyway: the specific message, not the generic error.
    $page->set('seatCount', 4)->call('syncSeats')
        ->assertSet('flash', $message)
        ->assertSet('flashSuccess', false);

    expect($m->sent)->toBe([])
        ->and($billable->fresh()->getBillingSeatCount())->toBe(2);
})->with(['en', 'de']);

it('shows no such hint once the billing country is on file, or to a tenant who was not given the plan', function (): void {
    $m = BookingMatrix::boot();
    giftedWithoutCountry($m, 'AT');

    $message = __('billing::portal.flash.billing_details_required', ['page' => __('billing::portal.nav.billing_data')]);

    Livewire::test('mollie-billing::addons')->assertDontSee($message);
    Livewire::test('mollie-billing::seats')->assertDontSee($message);
});

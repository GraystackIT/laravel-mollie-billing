<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/*
 * The coupon list and the coupon page: sorting, the bulk action, deactivate and delete.
 */

beforeEach(function (): void {
    $coupons = app(CouponService::class);

    $this->alpha = $coupons->create(['code' => 'ALPHA', 'name' => 'Zulu', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 1]]);
    $this->bravo = $coupons->create(['code' => 'BRAVO', 'name' => 'Yankee', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 5]);
    $this->charlie = $coupons->create(['code' => 'CHARLIE', 'name' => 'X-ray', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 5]);
});

it('sorts by an allowed column and ignores any other', function (): void {
    $list = Livewire::test('mollie-billing::admin.coupons.index');

    $list->call('sort', 'name')
        ->assertSet('sortBy', 'name')->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['CHARLIE', 'BRAVO', 'ALPHA']);

    // The same column again flips the direction.
    $list->call('sort', 'name')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['ALPHA', 'BRAVO', 'CHARLIE']);

    // Not a column of the list: nothing changes, and nothing reaches the query.
    $list->call('sort', 'code; drop table coupons')
        ->assertSet('sortBy', 'name')
        ->assertSeeInOrder(['ALPHA', 'BRAVO', 'CHARLIE']);

    foreach (['code', 'type', 'redemptions_count', 'valid_until', 'active'] as $column) {
        $list->call('sort', $column)->assertSet('sortBy', $column)->assertSet('sortDirection', 'asc');
    }

    // Search narrows by code and by name.
    $list->set('search', 'yank')->assertSee('BRAVO')->assertDontSee('ALPHA');
});

it('deactivates exactly the selected coupons', function (): void {
    Livewire::test('mollie-billing::admin.coupons.index')
        ->set('selected', [(string) $this->alpha->id, (string) $this->charlie->id])
        ->call('deactivateSelected')
        ->assertSet('selected', []);

    expect($this->alpha->fresh()->active)->toBeFalse()
        ->and($this->charlie->fresh()->active)->toBeFalse()
        ->and($this->bravo->fresh()->active)->toBeTrue();
});

it('deactivates the coupon the page is locked to', function (): void {
    Livewire::test('mollie-billing::admin.coupons.show', ['routeParameters' => ['coupon' => $this->bravo->id]])
        ->call('deactivate')
        ->assertSet('flash', __('billing::admin.coupons.show.deactivated'));

    expect($this->bravo->fresh()->active)->toBeFalse()
        ->and($this->alpha->fresh()->active)->toBeTrue();
});

it('deletes an unused coupon and refuses a redeemed one', function (): void {
    Livewire::test('mollie-billing::admin.coupons.show', ['routeParameters' => ['coupon' => $this->bravo->id]])
        ->call('delete')
        ->assertRedirect();

    expect(Coupon::query()->whereKey($this->bravo->id)->exists())->toBeFalse();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Redeemer', 'email' => 'redeemer@x.test']);
    app(CouponService::class)->redeem($this->alpha, $billable, []);

    Livewire::test('mollie-billing::admin.coupons.show', ['routeParameters' => ['coupon' => $this->alpha->id]])
        ->call('delete')
        ->assertSet('error', __('billing::admin.coupons.show.delete_failed'));

    expect(Coupon::query()->whereKey($this->alpha->id)->exists())->toBeTrue();
});

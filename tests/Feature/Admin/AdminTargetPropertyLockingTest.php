<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Admin screens carry the id of the record they act on or read as a public
 * Livewire property. Anything the server assigned — a route parameter, a row the
 * operator clicked — must be #[Locked], or the next update round-trip lets the
 * browser point the same screen at a different tenant.
 */
function lockingBillable(string $name): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => strtolower($name).'-'.uniqid().'@example.com',
        'name' => $name,
    ])->save();

    return $b->refresh();
}

/** Writes one audit row whose rendered detail block contains $marker. */
function lockingAuditRow(TestBillable $billable, string $marker): void
{
    activity('billing')
        ->performedOn($billable)
        ->event('plan_changed')
        ->withProperties([
            'category' => AuditCategory::Subscription->value,
            'actor' => 'admin',
            'replace' => ['old_plan' => $marker, 'new_plan' => $marker, 'interval' => 'monthly'],
        ])
        ->log('audit.plan_changed');
}

it('locks the audit tab billableId so a scoped operator cannot read another trail', function (): void {
    $mine = lockingBillable('MineCo');
    $other = lockingBillable('OtherCo');
    lockingAuditRow($mine, 'AUDITMINE');
    lockingAuditRow($other, 'AUDITOTHER');

    $component = Livewire::test('mollie-billing::admin.billables.audit-tab', ['billableId' => $mine->getKey()])
        ->assertSee('AUDITMINE')
        ->assertDontSee('AUDITOTHER');

    // Caught rather than asserted with toThrow() so the consequence of a missing
    // lock — the foreign trail actually rendering — is asserted too, not hidden
    // behind "no exception thrown".
    $rejection = null;
    try {
        $component->set('billableId', $other->getKey());
    } catch (CannotUpdateLockedPropertyException $e) {
        $rejection = $e;
    }

    expect($component->html())
        ->toContain('AUDITMINE')
        ->not->toContain('AUDITOTHER');
    expect($component->get('billableId'))->toBe($mine->getKey());
    expect($rejection)->not->toBeNull('the update must be rejected outright, not silently ignored');
    expect($rejection->getMessage())->toBe('Cannot update locked property: [billableId]');
});

it('locks the routed billable on the billables show page', function (): void {
    $mine = lockingBillable('MineCo');
    $other = lockingBillable('OtherCo');

    $component = Livewire::test('mollie-billing::admin.billables.show', [
        'routeParameters' => ['billable' => $mine->getKey()],
    ])->assertSee('MineCo');

    expect(fn () => $component->set('billable', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'Cannot update locked property: [billable]');
});

it('locks the routed coupon so deactivate and delete cannot be retargeted', function (): void {
    $mine = Coupon::create([
        'code' => 'LOCKMINE',
        'name' => 'Lock Mine',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
    ]);
    $other = Coupon::create([
        'code' => 'LOCKOTHER',
        'name' => 'Lock Other',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
    ]);

    $component = Livewire::test('mollie-billing::admin.coupons.show', [
        'routeParameters' => ['coupon' => $mine->getKey()],
    ])->assertSee('LOCKMINE');

    expect(fn () => $component->set('coupon', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'Cannot update locked property: [coupon]');

    $component->call('delete');

    expect(Coupon::whereKey($other->getKey())->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Every public action of the three change screens — plan, seats, addons — driven
 * through the Livewire component itself, and for each one the result it has: what the
 * screen holds afterwards, what the Mollie stand-in was asked to collect or return, and
 * what was booked.
 *
 * The matrix files (ChangeMatrix*Test.php) go through `UpdateSubscription` directly; a
 * screen that passed the wrong payload, swallowed a refusal or quoted another amount
 * than the one it then sends would pass all of them.
 */

beforeEach(function (): void {
    World::boot();
});

function changeScreen(string $screen, TestBillable $billable): Testable
{
    // Captured by value: the resolver outlives this test.
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    return Livewire::test('mollie-billing::'.$screen);
}

function changeScreenCoupon(string $code, bool $stackable = false): void
{
    app(CouponService::class)->create([
        'code' => $code, 'name' => $code, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions_per_billable' => 3, 'stackable' => $stackable,
    ]);
}

/** Gross minor units the stand-in was asked to collect for the pending change. */
function changeScreenPendingCharge(TestBillable $billable): ?int
{
    $id = $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null;

    return $id === null ? null : BillingMoney::minorFromMollieAmount(World::$payments[(string) $id]['value'], 'EUR');
}

/** Gross minor units the stand-in was asked to return, over all payments. */
function changeScreenRefunded(): int
{
    $total = 0;
    foreach (World::$refunds as $refunds) {
        foreach ($refunds as $refund) {
            $total += BillingMoney::minorFromMollieAmount((string) $refund->amount->value, 'EUR');
        }
    }

    return $total;
}

/** Gross booked for the billable after `$afterId`: [charged on invoices, credited on credit notes]. */
function changeScreenBooked(TestBillable $billable, int $afterId): array
{
    $charged = 0;
    $credited = 0;

    foreach (BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $afterId)->get() as $invoice) {
        if ($invoice->invoice_kind === InvoiceKind::Refund) {
            $credited += abs((int) $invoice->amount_gross);
        } else {
            $charged += (int) $invoice->amount_gross;
        }
    }

    return [$charged, $credited];
}

// ── seats ──────────────────────────────────────────────────────────────────────

it('actions seats@increment, @decrement, @syncSeats: the amount quoted is collected, booked, and comes back when the seat goes', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    $after = (int) BillingInvoice::query()->max('id');

    $seats = changeScreen('seats', $billable);
    expect($seats->get('seatCount'))->toBe(2)
        ->and($seats->viewData('dueNowGross'))->toBe(0);

    // Not below what the plan includes.
    $seats->call('decrement');
    expect($seats->get('seatCount'))->toBe(2);

    $seats->call('increment');
    $quoted = $seats->viewData('dueNowGross');
    expect($seats->get('seatCount'))->toBe(3)->and($quoted)->toBeGreaterThan(0);

    $seats->call('syncSeats');
    expect($seats->get('flashSuccess'))->toBeTrue()
        ->and(changeScreenPendingCharge($billable))->toBe($quoted);

    World::settle($billable);
    expect($billable->refresh()->getBillingSeatCount())->toBe(3)
        ->and(changeScreenBooked($billable, $after))->toBe([$quoted, 0]);

    // And down again: the same seat for the same remaining days comes back in full.
    $after = (int) BillingInvoice::query()->max('id');
    $seats = changeScreen('seats', $billable);
    $seats->call('decrement');
    $credit = $seats->viewData('dueNowGross');
    expect($seats->get('seatCount'))->toBe(2)->and($credit)->toBe(-$quoted);

    $seats->call('syncSeats');
    expect($seats->get('flashSuccess'))->toBeTrue()
        ->and($billable->refresh()->getBillingSeatCount())->toBe(2)
        ->and(changeScreenRefunded())->toBe($quoted)
        ->and(changeScreenBooked($billable, $after))->toBe([0, $quoted]);
});

it('actions seats@applyCoupon, @removeCoupon, @canAddMoreCoupons: a code lowers what is quoted and collected, and can be taken out again', function (): void {
    changeScreenCoupon('SEAT20');
    changeScreenCoupon('OTHER20');
    $billable = World::subscription('none', 'pro', 2);

    $seats = changeScreen('seats', $billable);
    $seats->call('increment');
    $full = $seats->viewData('dueNowGross');

    expect($seats->instance()->canAddMoreCoupons())->toBeTrue();

    $seats->set('couponInput', 'nope')->call('applyCoupon');
    expect($seats->get('couponError'))->not->toBeNull()->and($seats->get('appliedCouponCodes'))->toBe([]);

    $seats->set('couponInput', 'seat20')->call('applyCoupon');
    expect($seats->get('appliedCouponCodes'))->toBe(['SEAT20'])
        ->and($seats->get('couponError'))->toBeNull()
        ->and($seats->instance()->canAddMoreCoupons())->toBeFalse();

    // A second code next to one that does not stack is refused, and says so.
    $seats->set('couponInput', 'OTHER20')->call('applyCoupon');
    expect($seats->get('couponError'))->toBe(__('billing::checkout.coupon_not_stackable_with_current'))
        ->and($seats->get('appliedCouponCodes'))->toBe(['SEAT20']);

    $seats->call('removeCoupon', 'SEAT20');
    expect($seats->get('appliedCouponCodes'))->toBe([])
        ->and($seats->instance()->canAddMoreCoupons())->toBeTrue()
        ->and($seats->viewData('dueNowGross'))->toBe($full);

    $seats->set('couponInput', 'SEAT20')->call('applyCoupon');
    $discounted = $seats->viewData('dueNowGross');
    expect($discounted)->toBeLessThan($full)->toBeGreaterThan(0);

    $seats->call('syncSeats');
    expect(changeScreenPendingCharge($billable))->toBe($discounted);

    World::settle($billable);
    expect($billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('SEAT20');
});

it('config max_seats: the seat screen refuses a count above the ceiling, names it, and sends nothing', function (): void {
    config()->set('mollie-billing.max_seats', 4);
    $billable = World::subscription('none', 'pro', 2);
    $sent = count(World::$sent);

    $seats = changeScreen('seats', $billable);
    $seats->set('seatCount', 5)->call('syncSeats');

    expect($seats->get('flashSuccess'))->toBeFalse()
        ->and($seats->get('flash'))->toBe(__('billing::portal.seats_flash.too_many', ['max' => 4]))
        ->and(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(2);

    $seats->set('seatCount', 4)->call('syncSeats');
    expect($seats->get('flashSuccess'))->toBeTrue();
});

// ── addons ─────────────────────────────────────────────────────────────────────

it('actions addons@enableAddon, @disableAddon, @pollForChange: quoted, collected, booked, announced — and returned when it goes', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    $after = (int) BillingInvoice::query()->max('id');
    $row = fn (Testable $screen, string $code): array => collect($screen->viewData('addons'))->firstWhere('code', $code);

    $addons = changeScreen('addons', $billable);
    $quoted = $row($addons, 'storage')['dueNowGross'];
    expect($quoted)->toBeGreaterThan(0)->and($row($addons, 'storage')['isActive'])->toBeFalse();

    $addons->call('enableAddon', 'storage');
    expect($addons->get('polling'))->toBeTrue()
        ->and($addons->get('pendingAction'))->toBe('enabled')
        ->and(changeScreenPendingCharge($billable))->toBe($quoted);

    // An addon is usable at once; the screen keeps polling while its payment is open.
    $addons->call('pollForChange');
    expect($addons->get('polling'))->toBeTrue()
        ->and($addons->get('pendingAddon'))->toBe('storage')
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toContain('storage');

    World::settle($billable);
    $addons->call('pollForChange');
    expect($addons->get('polling'))->toBeFalse()
        ->and($addons->get('pendingAddon'))->toBeNull()
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toContain('storage')
        ->and(changeScreenBooked($billable, $after))->toBe([$quoted, 0]);

    $after = (int) BillingInvoice::query()->max('id');
    $addons = changeScreen('addons', $billable);
    expect($row($addons, 'storage')['dueNowGross'])->toBe(-$quoted);

    $addons->call('disableAddon', 'storage');
    expect($addons->get('pendingAction'))->toBe('disabled')
        ->and($billable->refresh()->getActiveBillingAddonCodes())->not->toContain('storage')
        ->and(changeScreenRefunded())->toBe($quoted)
        ->and(changeScreenBooked($billable, $after))->toBe([0, $quoted]);
});

it('action addons@pollForChange: a declined payment takes the addon back, and Mollie back to the old amount', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    $held = World::$heldValue;

    $addons = changeScreen('addons', $billable);
    $addons->call('enableAddon', 'storage');
    World::settle($billable, 'failed');

    $addons->call('pollForChange');

    expect($addons->get('polling'))->toBeFalse()
        ->and($billable->refresh()->getActiveBillingAddonCodes())->not->toContain('storage')
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->not->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and(World::$heldValue)->toBe($held)
        ->and(BillingInvoice::query()->where('billable_id', $billable->getKey())->count())->toBe(1);
});

it('actions addons@applyCoupon, @removeCoupon, @canAddMoreCouponsFor: a code on one addon lowers what that addon costs now', function (): void {
    changeScreenCoupon('ADDON20');
    changeScreenCoupon('OTHER20');
    $billable = World::subscription('none', 'pro', 2);
    $row = fn (Testable $screen, string $code): array => collect($screen->viewData('addons'))->firstWhere('code', $code);

    $addons = changeScreen('addons', $billable);
    $full = $row($addons, 'storage')['dueNowGross'];
    expect($addons->instance()->canAddMoreCouponsFor('storage'))->toBeTrue();

    $addons->set('couponInputs.storage', 'addon20')->call('applyCoupon', 'storage');
    expect($addons->get('appliedCouponCodes')['storage'] ?? [])->toBe(['ADDON20'])
        ->and($addons->instance()->canAddMoreCouponsFor('storage'))->toBeFalse()
        ->and($addons->instance()->canAddMoreCouponsFor('sso'))->toBeTrue();

    $addons->set('couponInputs.storage', 'OTHER20')->call('applyCoupon', 'storage');
    expect($addons->get('couponErrors')['storage'] ?? null)->toBe(__('billing::checkout.coupon_not_stackable_with_current'));

    $addons->call('removeCoupon', 'storage', 'ADDON20');
    expect($addons->get('appliedCouponCodes')['storage'] ?? [])->toBe([])
        ->and($row($addons, 'storage')['dueNowGross'])->toBe($full);

    $addons->set('couponInputs.storage', 'ADDON20')->call('applyCoupon', 'storage');
    $discounted = $row($addons, 'storage')['dueNowGross'];
    expect($discounted)->toBeLessThan($full)->toBeGreaterThan(0);

    $addons->call('enableAddon', 'storage');
    expect(changeScreenPendingCharge($billable))->toBe($discounted);
});

// ── plan ───────────────────────────────────────────────────────────────────────

it('actions plan-change@selectablePlans, @previewFor, @applyChange: an upgrade is collected at what the preview says and booked', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    $after = (int) BillingInvoice::query()->max('id');

    $plan = changeScreen('plan-change', $billable);
    expect($plan->instance()->selectablePlans())->toContain('enterprise')->toContain('starter');

    // A plan that is not on offer is not previewed.
    $plan->call('previewFor', 'no-such-plan');
    expect($plan->get('selectedPlan'))->toBeNull();

    $plan->call('previewFor', 'enterprise');
    $preview = $plan->get('preview');
    $charge = 0;
    $refund = 0;
    foreach ($preview['prorataLines'] as $line) {
        ($line['direction'] ?? 'charge') === 'refund' ? $refund += abs((int) $line['amount_gross']) : $charge += (int) $line['amount_gross'];
    }
    expect($plan->get('selectedPlan'))->toBe('enterprise')->and($charge)->toBeGreaterThan(0);

    $plan->call('applyChange', 'immediate');
    expect($plan->get('selectedPlan'))->toBeNull()
        ->and(changeScreenPendingCharge($billable))->toBe($charge);

    World::settle($billable);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(changeScreenRefunded())->toBe($refund)
        ->and(changeScreenBooked($billable, $after))->toBe([$charge, $refund]);
});

it('action plan-change@toggleKeepExtraSeats: a plan change drops the unused extra seats unless the customer keeps them', function (): void {
    $billable = World::subscription('none', 'pro', 5, [], 'monthly', World::MID, 2);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'enterprise');
    expect($plan->get('preview')['newSeats'])->toBe(2)->and($plan->get('keepExtraSeats'))->toBeFalse();

    $plan->call('toggleKeepExtraSeats');
    expect($plan->get('keepExtraSeats'))->toBeTrue()->and($plan->get('preview')['newSeats'])->toBe(5);

    $plan->call('applyChange', 'immediate');
    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSeatCount())->toBe(5);

    // And without the choice, the same change lands on the seats in use.
    $other = World::subscription('none', 'pro', 5, [], 'monthly', World::MID, 2);
    $plan = changeScreen('plan-change', $other);
    $plan->call('previewFor', 'enterprise')->call('applyChange', 'immediate');
    World::settle($other);

    expect($other->refresh()->getBillingSeatCount())->toBe(2);
});

it('actions plan-change@applyChange (end of period), @cancelScheduledChange, @applyScheduledNow: scheduled, withdrawn, scheduled again, applied', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'starter')->call('applyChange', 'end_of_period');

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('starter')
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');

    $plan = changeScreen('plan-change', $billable);
    $plan->call('cancelScheduledChange');

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::heldGross())->toBe(6000);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'starter')->call('applyChange', 'end_of_period');
    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('starter');

    $plan = changeScreen('plan-change', $billable);
    $plan->call('applyScheduledNow');
    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(World::heldGross())->toBe(2400);
});

it('enum PlanChangeMode / config plan_change_mode: the mode decides when a change the screen sends is applied', function (PlanChangeMode $mode, string $asked, string $outcome): void {
    config()->set('mollie-billing.plan_change_mode', $mode);
    $billable = World::subscription('none', 'pro', 2);
    $sent = count(World::$sent);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'starter');
    expect($plan->get('preview')['planChangeMode'])->toBe($mode->value);

    $plan->call('applyChange', $asked);
    World::settle($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe($outcome === 'scheduled' ? 'starter' : null)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe($outcome === 'applied' ? 'starter' : 'pro');

    if ($outcome === 'refused') {
        // What the mode does not allow is not sent anywhere, and the selection stays open.
        expect(count(World::$sent))->toBe($sent)->and($plan->get('selectedPlan'))->toBe('starter');
    }
})->with([
    'immediate, asked for immediate' => [PlanChangeMode::Immediate, 'immediate', 'applied'],
    'immediate, asked for the period end' => [PlanChangeMode::Immediate, 'end_of_period', 'refused'],
    'end of period, asked for immediate' => [PlanChangeMode::EndOfPeriod, 'immediate', 'refused'],
    'end of period, asked for the period end' => [PlanChangeMode::EndOfPeriod, 'end_of_period', 'scheduled'],
    'user choice, asked for immediate' => [PlanChangeMode::UserChoice, 'immediate', 'applied'],
    'user choice, asked for the period end' => [PlanChangeMode::UserChoice, 'end_of_period', 'scheduled'],
]);

it('action plan-change@cancelPendingChange: a change still waiting for its payment is withdrawn and the plan stays', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'enterprise')->call('applyChange', 'immediate');
    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->not->toBeNull();

    $plan = changeScreen('plan-change', $billable);
    $plan->call('cancelPendingChange');

    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::heldGross())->toBe(6000);
});

it('actions plan-change@applyCoupon, @removeCoupon, @canAddMoreCoupons: a code entered with the change lowers the charge and the renewals', function (): void {
    changeScreenCoupon('PLAN20');
    changeScreenCoupon('OTHER20');
    $billable = World::subscription('none', 'pro', 2);
    $charge = fn (Testable $screen): int => array_sum(array_map(
        fn (array $l): int => ($l['direction'] ?? 'charge') === 'refund' ? 0 : (int) $l['amount_gross'],
        $screen->get('preview')['prorataLines'],
    ));

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'enterprise');
    $full = $charge($plan);
    expect($plan->instance()->canAddMoreCoupons())->toBeTrue();

    $plan->set('couponInput', 'plan20')->call('applyCoupon');
    expect($plan->get('appliedCouponCodes'))->toBe(['PLAN20'])
        ->and($plan->instance()->canAddMoreCoupons())->toBeFalse()
        ->and($charge($plan))->toBeLessThan($full);

    $plan->set('couponInput', 'OTHER20')->call('applyCoupon');
    expect($plan->get('couponError'))->toBe(__('billing::checkout.coupon_not_stackable_with_current'));

    $plan->call('removeCoupon', 'PLAN20');
    expect($plan->get('appliedCouponCodes'))->toBe([])->and($charge($plan))->toBe($full);

    $plan->set('couponInput', 'PLAN20')->call('applyCoupon');
    $discounted = $charge($plan);

    $plan->call('applyChange', 'immediate');
    expect(changeScreenPendingCharge($billable))->toBe($discounted);

    World::settle($billable);
    // Enterprise 200.00 − 20 % = 160.00 net, + 20 % AT.
    expect(World::heldGross())->toBe(19200);
});

it('actions plan-change@openEditBillingModal, @saveBillingDetails: the address is loaded, saved, and the open preview re-priced for the new country', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'enterprise');
    $rateBefore = (float) $plan->get('preview')['vatRate'];

    $plan->call('openEditBillingModal');
    expect($plan->get('showEditBillingModal'))->toBeTrue()
        ->and($plan->get('billing_country'))->toBe('AT')
        ->and($plan->get('company_name'))->toBe($billable->getBillingName());

    $plan->set('billing_street', '')->call('saveBillingDetails')->assertHasErrors(['billing_street']);
    expect($plan->get('showEditBillingModal'))->toBeTrue();

    $plan->set('billing_street', 'Musterweg 12')
        ->set('billing_postal_code', '10115')
        ->set('billing_city', 'Berlin')
        ->set('billing_country', 'DE')
        ->call('saveBillingDetails')
        ->assertHasNoErrors();

    expect($plan->get('showEditBillingModal'))->toBeFalse()
        ->and($billable->refresh()->billing_country)->toBe('DE')
        ->and($billable->billing_city)->toBe('Berlin')
        ->and($rateBefore)->toBe(20.0)
        ->and((float) $plan->get('preview')['vatRate'])->toBe(19.0);
});

it('actions plan-change@applyChange, @backToPlanSelection, @confirmAndPay: a free local plan asks before it sends the customer to pay', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Local Co', 'email' => 'local@x.test', 'billing_country' => 'AT',
        'billing_street' => 'Ring 1', 'billing_postal_code' => '1010', 'billing_city' => 'Wien',
        'mollie_customer_id' => 'cst_local',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => 'monthly',
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 2],
    ])->save();

    $plan = changeScreen('plan-change', $billable->refresh());
    $plan->call('previewFor', 'pro')->call('applyChange', 'immediate');

    // Nothing is sent yet: the screen asks first.
    expect($plan->get('showLocalUpgradeConfirmation'))->toBeTrue()->and(World::$payments)->toBe([]);

    $plan->call('backToPlanSelection');
    expect($plan->get('showLocalUpgradeConfirmation'))->toBeFalse()->and(World::$payments)->toBe([]);

    $plan->call('applyChange', 'immediate')->call('confirmAndPay');

    // Pro 50.00 net + 20 % AT, as a first payment the customer completes at Mollie.
    expect(World::$payments)->toHaveCount(1)
        ->and(array_values(World::$payments)[0]['value'])->toBe('60.00')
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('free');
});

it('says a credit comes back on its own when there is no payment to wait for, and "once this payment is captured" only when there is one', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    $amount = fn (Testable $screen): string => BillingMoney::format(abs(array_sum(array_map(
        fn (array $l): int => ($l['direction'] ?? 'charge') === 'refund' ? (int) $l['amount_gross'] : 0,
        $screen->get('preview')['prorataLines'],
    ))));

    // To the free plan: nothing is charged, the unused share of Pro comes back.
    $plan = changeScreen('plan-change', $billable);
    $plan->call('previewFor', 'free');

    $plan->assertSee(__('billing::portal.prorata_refund_only', ['amount' => $amount($plan)]))
        ->assertDontSee(__('billing::portal.prorata_refund_separate', ['amount' => $amount($plan)]));

    // An upgrade: a payment for the new plan, and the credit for the old one after it.
    $plan->call('previewFor', 'enterprise');

    $plan->assertSee(__('billing::portal.prorata_refund_separate', ['amount' => $amount($plan)]))
        ->assertDontSee(__('billing::portal.prorata_refund_only', ['amount' => $amount($plan)]));
});

<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CheckoutDraft;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Illuminate\Auth\GenericUser;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The checkout keeps an unfinished order as a draft in the session: a reload, or leaving and
 * coming back, resumes it at the furthest step that still validates. Kept apart per signed-in
 * user, billable and checkout URL; input only — prices, VAT, the VIES verdict, the coupon and
 * permissions are worked out again on every resume; never written to the billable; dropped when
 * the checkout is done, when it expires, and when the billable or the permission is gone.
 */

afterEach(function (): void {
    Carbon::setTestNow();
    auth()->forgetUser();
});

/** A signup: nothing resolves, the checkout creates the billable on submit. */
function draftSignup(): void
{
    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::createBillableUsing(function (array $data): TestBillable {
        /** @var TestBillable $billable */
        $billable = TestBillable::create(['name' => $data['name'], 'email' => uniqid('draft-').'@x.test']);
        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::New,
            'billing_street' => $data['billing_street'],
            'billing_postal_code' => $data['billing_postal_code'],
            'billing_city' => $data['billing_city'],
            'billing_country' => $data['billing_country'],
            'tax_country_user' => $data['tax_country_user'],
            'vat_number' => $data['vat_number'],
            'mollie_customer_id' => 'cst_draft',
        ])->save();

        return $billable->refresh();
    });
}

/** An existing billable without an address on file: the billing step is open, not locked. */
function draftBillableWithoutAddress(string $name = 'Open Co'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => $name, 'email' => uniqid('open-').'@x.test']);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::New, 'mollie_customer_id' => 'cst_open'])->save();

    return $billable->refresh();
}

function draftFillAddress(Testable $checkout, string $company = 'Acme GmbH', string $country = 'AT', ?string $vat = null): Testable
{
    return $checkout->set('company_name', $company)
        ->set('billing_street', 'Ringstraße 1')
        ->set('billing_postal_code', '1010')
        ->set('billing_city', 'Wien')
        ->set('billing_country', $country)
        ->set('vat_number', $vat);
}

/** Walk a fresh checkout to its confirm step: address, plan, extras. */
function draftWalkToConfirm(string $interval = 'monthly', string $company = 'Acme GmbH'): Testable
{
    $checkout = draftFillAddress(Livewire::test('mollie-billing::checkout'), $company)->call('next');
    $checkout->set('plan_code', 'pro')->set('interval', $interval)->call('next');
    $checkout->set('addon_codes', ['sso'])->set('extra_seats', 1)->call('next');

    expect($checkout->get('step'))->toBe(4);

    return $checkout;
}

/** @return array<string, array<string, mixed>> */
function draftEntries(): array
{
    return (array) session(CheckoutDraft::SESSION_KEY, []);
}

/** Overwrite the data of the only draft in the session, as anything that can write the session could. */
function draftTamper(array $data): void
{
    $entries = draftEntries();
    expect($entries)->toHaveCount(1);

    $key = array_key_first($entries);
    $entries[$key]['data'] = array_merge($entries[$key]['data'], $data);
    session([CheckoutDraft::SESSION_KEY => $entries]);
}

// ── Resume ──────────────────────────────────────────────────────────────────────

it('resumes a signup after a reload with everything typed and picked, at the step reached', function (string $interval): void {
    $m = BookingMatrix::boot();
    draftSignup();

    $first = draftWalkToConfirm($interval);
    $shown = $m->shown($first);

    // The page is reloaded, or the customer comes back later.
    $again = Livewire::test('mollie-billing::checkout');

    expect($again->get('step'))->toBe(4)
        ->and($again->get('company_name'))->toBe('Acme GmbH')
        ->and($again->get('billing_street'))->toBe('Ringstraße 1')
        ->and($again->get('billing_country'))->toBe('AT')
        ->and($again->get('plan_code'))->toBe('pro')
        ->and($again->get('interval'))->toBe($interval)
        ->and($again->get('addon_codes'))->toBe(['sso'])
        ->and($again->get('extra_seats'))->toBe(1)
        ->and($again->get('billableId'))->toBeNull()
        // Priced again, to the cent of what was shown before.
        ->and($m->shown($again))->toBe($shown);

    $again->assertSee(__('billing::checkout.headline_confirm'));
})->with(['monthly', 'yearly']);

it('resumes at the step the customer was on, not past it', function (): void {
    BookingMatrix::boot();
    draftSignup();

    draftFillAddress(Livewire::test('mollie-billing::checkout'))->call('next')->set('plan_code', 'pro');

    $again = Livewire::test('mollie-billing::checkout');

    expect($again->get('step'))->toBe(2)
        ->and($again->get('plan_code'))->toBe('pro')
        ->and($again->get('company_name'))->toBe('Acme GmbH');
});

// ── Input only ──────────────────────────────────────────────────────────────────

it('keeps input only: no price, no discount amount, no VIES verdict, no billable id', function (): void {
    BookingMatrix::boot();
    draftSignup();
    app(CouponService::class)->create([
        'code' => 'TEN', 'name' => 'Ten percent', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 3,
    ]);

    draftWalkToConfirm()->set('coupon_input', 'TEN')->call('applyCoupon');

    $data = array_values(draftEntries())[0]['data'];

    expect(array_keys($data))->each->toBeIn([
        'step', 'custom_steps', 'company_name', 'billing_street', 'billing_postal_code', 'billing_city',
        'billing_country', 'vat_number', 'plan_code', 'interval', 'addon_codes', 'extra_seats', 'coupon_code',
    ])
        ->and($data['coupon_code'])->toBe('TEN')
        ->and(json_encode($data))->not->toContain('discount_net')
        ->and(json_encode($data))->not->toContain('vatNumberValid')
        ->and(json_encode($data))->not->toContain('billableId');
});

it('takes nothing on trust from a tampered draft: billable, prices, VIES and an unsold plan are all re-derived', function (): void {
    BookingMatrix::boot();
    draftSignup();
    $stranger = draftBillableWithoutAddress('Stranger');

    draftWalkToConfirm();

    draftTamper([
        'billableId' => (string) $stranger->getKey(),
        'billableClass' => $stranger->getMorphClass(),
        'billing_locked' => true,
        'vatNumberValid' => true,
        'applied_coupon' => ['code' => 'FAKE', 'name' => 'Fake', 'discount_net' => 999999],
        'amount_gross' => 1,
        'plan_code' => 'ghost-plan',
        'extra_seats' => 5000,
        'step' => 4,
    ]);

    $again = Livewire::test('mollie-billing::checkout');

    expect($again->get('billableId'))->toBeNull()
        ->and($again->get('billing_locked'))->toBeFalse()
        ->and($again->get('vatNumberValid'))->toBeNull()
        ->and($again->get('applied_coupon'))->toBeNull()
        // An unknown plan is not restored; the seat count is clamped, then refused with the plan.
        ->and($again->get('plan_code'))->not->toBe('ghost-plan')
        ->and($again->get('extra_seats'))->toBeLessThanOrEqual(100)
        // And the resume stops where validation does: at the plan step.
        ->and($again->get('step'))->toBe(2);
});

it('checks the VAT number again on resume instead of trusting the draft', function (): void {
    $m = BookingMatrix::boot();
    // A German business number the faked VIES confirms (registered through a throwaway billable).
    $m->business('DE', 'DE123456789')->delete();
    draftSignup();

    draftFillAddress(Livewire::test('mollie-billing::checkout'), 'Berlin AG', 'DE', 'DE123456789')->call('next')
        ->set('plan_code', 'pro')->call('next');

    $again = Livewire::test('mollie-billing::checkout');
    expect($again->get('vatNumberValid'))->toBeTrue()
        ->and($again->get('step'))->toBe(3)
        ->and($m->shown($again)['reverseCharge'])->toBeTrue();

    // A number VIES does not confirm, put into the draft: the resume stops at the billing step,
    // and the price is gross — the draft cannot buy reverse charge.
    draftTamper(['vat_number' => 'DE999999999']);

    $tampered = Livewire::test('mollie-billing::checkout');
    expect($tampered->get('vatNumberValid'))->toBeFalse()
        ->and($tampered->get('step'))->toBe(1)
        ->and($m->shown($tampered)['reverseCharge'])->toBeFalse();
});

it('re-validates the coupon by code: a code that ended meanwhile is dropped with its reason', function (): void {
    $m = BookingMatrix::boot();
    draftSignup();
    app(CouponService::class)->create([
        'code' => 'TEN', 'name' => 'Ten percent', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 3,
    ]);

    $first = draftWalkToConfirm()->set('coupon_input', 'TEN')->call('applyCoupon');
    $discount = $first->get('applied_coupon')['discount_net'];
    expect($discount)->toBeGreaterThan(0);

    $again = Livewire::test('mollie-billing::checkout');
    expect($again->get('applied_coupon')['discount_net'])->toBe($discount)
        ->and($m->shown($again)['gross'])->toBe($m->shown($first)['gross']);

    Coupon::query()->where('code', 'TEN')->update(['active' => false]);

    $later = Livewire::test('mollie-billing::checkout');
    expect($later->get('applied_coupon'))->toBeNull()
        ->and($later->get('couponError'))->toBe(__('billing::checkout.coupon_inactive'))
        ->and($m->shown($later)['gross'])->toBeGreaterThan($m->shown($first)['gross']);
});

// ── Separation ──────────────────────────────────────────────────────────────────

it('keeps drafts apart between signed-in users and after a logout', function (): void {
    BookingMatrix::boot();
    draftSignup();
    $alice = new GenericUser(['id' => 101]);
    $bob = new GenericUser(['id' => 202]);

    auth()->setUser($alice);
    draftWalkToConfirm(company: 'Alice GmbH');

    auth()->setUser($bob);
    $forBob = Livewire::test('mollie-billing::checkout');
    expect($forBob->get('company_name'))->toBe('')
        ->and($forBob->get('step'))->toBe(1);

    auth()->forgetUser();
    expect(Livewire::test('mollie-billing::checkout')->get('company_name'))->toBe('');

    auth()->setUser($alice);
    expect(Livewire::test('mollie-billing::checkout')->get('company_name'))->toBe('Alice GmbH');
});

it('stops and drops a draft when another user is signed in while the page is open', function (): void {
    BookingMatrix::boot();
    draftSignup();

    auth()->setUser(new GenericUser(['id' => 101]));
    $open = draftWalkToConfirm(company: 'Alice GmbH');

    // Logout and login as someone else in another tab; the open page sends its next update.
    auth()->setUser(new GenericUser(['id' => 202]));
    $open->set('company_name', 'Typed after the switch');

    expect(draftEntries())->toBe([])
        ->and($open->get('draftKey'))->toBeNull();

    auth()->setUser(new GenericUser(['id' => 101]));
    expect(Livewire::test('mollie-billing::checkout')->get('company_name'))->toBe('');
});

it('keeps drafts apart between billables of the same user', function (): void {
    BookingMatrix::boot();
    $first = draftBillableWithoutAddress('First Co');
    $second = draftBillableWithoutAddress('Second Co');

    MollieBilling::resolveBillableUsing(fn () => $first->fresh());
    draftFillAddress(Livewire::test('mollie-billing::checkout'), 'First Co')->call('next')->set('plan_code', 'pro');

    MollieBilling::resolveBillableUsing(fn () => $second->fresh());
    $forSecond = Livewire::test('mollie-billing::checkout');
    expect($forSecond->get('company_name'))->toBe('')->and($forSecond->get('plan_code'))->toBe('');

    MollieBilling::resolveBillableUsing(fn () => $first->fresh());
    $forFirst = Livewire::test('mollie-billing::checkout');
    expect($forFirst->get('company_name'))->toBe('First Co')->and($forFirst->get('plan_code'))->toBe('pro');
});

// ── Locked billing data ─────────────────────────────────────────────────────────

it('respects a locked address: the draft neither changes it nor brings the billing step back', function (): void {
    $m = BookingMatrix::boot();
    $m->billable('AT');

    $checkout = Livewire::test('mollie-billing::checkout');
    expect($checkout->get('billing_locked'))->toBeTrue()->and($checkout->get('step'))->toBe(2);
    $checkout->set('plan_code', 'pro')->call('next');

    expect(array_values(draftEntries())[0]['data'])->not->toHaveKey('company_name');

    draftTamper(['company_name' => 'Evil Ltd', 'billing_country' => 'HU', 'step' => 1]);

    $again = Livewire::test('mollie-billing::checkout');
    expect($again->get('billing_locked'))->toBeTrue()
        ->and($again->get('company_name'))->toBe('Matrix GmbH')
        ->and($again->get('billing_country'))->toBe('AT')
        ->and($again->get('plan_code'))->toBe('pro')
        ->and($again->get('step'))->toBe(2);
});

it('writes nothing of a draft to the billable', function (): void {
    BookingMatrix::boot();
    $billable = draftBillableWithoutAddress();
    MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

    draftFillAddress(Livewire::test('mollie-billing::checkout'), 'Provisional AG')->call('next')
        ->set('plan_code', 'pro')->call('next');

    $billable->refresh();
    expect($billable->billing_street)->toBeNull()
        ->and($billable->billing_country)->toBeNull()
        ->and($billable->name)->toBe('Open Co');
});

// ── Link preselection ───────────────────────────────────────────────────────────

it('lets a link that names another plan win, and resumes no further than the plan step', function (): void {
    BookingMatrix::boot();
    draftSignup();
    draftWalkToConfirm();

    $other = Livewire::withQueryParams(['plan' => 'solo'])->test('mollie-billing::checkout');
    expect($other->get('plan_code'))->toBe('solo')
        ->and($other->get('step'))->toBe(2)
        ->and($other->get('company_name'))->toBe('Acme GmbH')
        // sso is not sold with solo.
        ->and($other->get('addon_codes'))->toBe([]);

    // The same plan again: nothing new was chosen, the resume goes on to where the draft was.
    $same = Livewire::withQueryParams(['plan' => 'solo'])->test('mollie-billing::checkout');
    expect($same->get('step'))->toBe(2);

    session()->forget(CheckoutDraft::SESSION_KEY);
    draftWalkToConfirm();
    expect(Livewire::withQueryParams(['plan' => 'pro', 'interval' => 'monthly'])->test('mollie-billing::checkout')->get('step'))->toBe(4);
});

// ── App-provided steps ──────────────────────────────────────────────────────────

it('keeps only the custom step data the app allowed, and shows a step again whose data it did not keep', function (bool $allowed): void {
    BookingMatrix::boot();
    draftSignup();
    config()->set('mollie-billing.checkout_draft_custom_data', $allowed ? ['terms'] : []);
    MollieBilling::checkoutStepsUsing(fn (): array => [[
        'key' => 'terms', 'label' => 'Terms', 'headline' => 'Accept the terms', 'description' => 'First.',
        'view' => 'mollie-billing::livewire.billing.components.checkout.step-billing',
        'validate' => function ($component): void {
            if (! ($component->customData['terms'] ?? false)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['customData.terms' => 'Accept the terms.']);
            }
        },
    ]]);

    $checkout = Livewire::test('mollie-billing::checkout')
        ->set('customData.terms', true)
        ->set('customData.one_time_code', '493021')
        ->call('next');
    draftFillAddress($checkout)->call('next');
    expect($checkout->get('step'))->toBe(3);

    $data = array_values(draftEntries())[0]['data'];
    expect($data['custom_data'] ?? [])->toBe($allowed ? ['terms' => true] : [])
        ->and(json_encode($data))->not->toContain('493021');

    $again = Livewire::test('mollie-billing::checkout');
    expect($again->get('step'))->toBe($allowed ? 3 : 1)
        ->and($again->get('company_name'))->toBe('Acme GmbH');
})->with(['allowed' => [true], 'not allowed' => [false]]);

// ── Cleanup and expiry ──────────────────────────────────────────────────────────

it('drops the draft when a booking completes without leaving the site', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');

    $checkout = Livewire::test('mollie-billing::checkout')->set('plan_code', 'free')->call('next');
    expect(draftEntries())->toHaveCount(1);

    $checkout->call('submit');

    expect($billable->fresh()->hasAccessibleBillingSubscription())->toBeTrue()
        ->and(draftEntries())->toBe([]);
});

it('keeps the draft while the customer is at Mollie, and drops it once the return page sees the subscription', function (): void {
    $m = BookingMatrix::boot();
    draftSignup();

    draftWalkToConfirm()->call('submit');
    $payment = $m->lastPayment();
    expect($payment)->not->toBeNull()
        ->and(draftEntries())->toHaveCount(1);

    $billable = TestBillable::query()->latest('id')->firstOrFail();
    expect(array_values(draftEntries())[0]['created']['id'] ?? null)->toBe((string) $billable->getKey());

    $m->settle($payment);
    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);

    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue()
        ->and(draftEntries())->toBe([]);
});

it('resumes a signup that was left at Mollie as the billable it created', function (): void {
    $m = BookingMatrix::boot();
    draftSignup();
    auth()->setUser(new GenericUser(['id' => 7]));

    draftWalkToConfirm()->call('submit');
    $created = TestBillable::query()->latest('id')->firstOrFail();

    // Cancelled at Mollie: the app now resolves the organisation the signup created.
    MollieBilling::resolveBillableUsing(fn () => $created->fresh());
    $back = Livewire::test('mollie-billing::checkout');

    expect($back->get('billing_locked'))->toBeTrue()
        ->and($back->get('billableId'))->toBe((string) $created->getKey())
        ->and($back->get('plan_code'))->toBe('pro')
        ->and($back->get('addon_codes'))->toBe(['sso'])
        ->and($back->get('extra_seats'))->toBe(1)
        ->and($back->get('step'))->toBe(4);

    // Another signed-in user who could also see that billable does not get the draft.
    auth()->setUser(new GenericUser(['id' => 8]));
    expect(Livewire::test('mollie-billing::checkout')->get('plan_code'))->toBe('');
});

it('expires a draft after its time, and keeps none when drafts are switched off', function (): void {
    BookingMatrix::boot();
    draftSignup();
    config()->set('mollie-billing.checkout_draft_ttl_minutes', 60);
    Carbon::setTestNow($start = Carbon::parse('2026-11-20 10:00:00', 'UTC'));

    draftWalkToConfirm();

    Carbon::setTestNow($start->copy()->addMinutes(59));
    expect(Livewire::test('mollie-billing::checkout')->get('step'))->toBe(4);

    // That visit refreshed it; an hour of nothing after it ends it.
    Carbon::setTestNow($start->copy()->addMinutes(59 + 61));
    $expired = Livewire::test('mollie-billing::checkout');
    expect($expired->get('step'))->toBe(1)->and($expired->get('company_name'))->toBe('');

    config()->set('mollie-billing.checkout_draft_ttl_minutes', 0);
    session()->forget(CheckoutDraft::SESSION_KEY);
    draftWalkToConfirm();
    expect(draftEntries())->toBe([])
        ->and(Livewire::test('mollie-billing::checkout')->get('step'))->toBe(1);
});

// ── Deleted billable, revoked permission ────────────────────────────────────────

it('starts over when the billable a signup created has been deleted, and re-creates nothing', function (): void {
    $m = BookingMatrix::boot();
    draftSignup();

    draftWalkToConfirm()->call('submit');
    $created = TestBillable::query()->latest('id')->firstOrFail();
    $created->delete();
    $count = TestBillable::query()->count();

    $again = Livewire::test('mollie-billing::checkout');

    expect($again->get('company_name'))->toBe('')
        ->and($again->get('step'))->toBe(1)
        ->and(draftEntries())->toBe([])
        ->and(TestBillable::query()->count())->toBe($count);
});

it('drops the draft when the billable is deleted while the page is open, and submits nothing for it', function (): void {
    $m = BookingMatrix::boot();
    $billable = draftBillableWithoutAddress();
    MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

    $open = draftFillAddress(Livewire::test('mollie-billing::checkout'))->call('next')->set('plan_code', 'pro')->call('next');
    expect(draftEntries())->toHaveCount(1);

    $billable->delete();
    $count = TestBillable::query()->count();

    $open->set('extra_seats', 2);
    expect(draftEntries())->toBe([]);

    $open->set('step', 4)->call('submit');
    expect($open->get('errorMessage'))->toBe(__('billing::checkout.error_no_billable'))
        ->and(TestBillable::query()->count())->toBe($count)
        ->and($m->lastPayment())->toBeNull();
});

it('restores nothing and writes nothing for a billable the visitor may no longer act for', function (): void {
    BookingMatrix::boot();
    $billable = draftBillableWithoutAddress();
    MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

    $open = draftFillAddress(Livewire::test('mollie-billing::checkout'), 'Before Revoke AG')->call('next')->set('plan_code', 'pro');
    expect(draftEntries())->toHaveCount(1);

    MollieBilling::authUsing(fn (): bool => false);

    $refused = Livewire::test('mollie-billing::checkout');
    expect($refused->get('company_name'))->toBe('')
        ->and($refused->get('plan_code'))->toBe('')
        ->and($refused->get('draftKey'))->toBeNull();

    // The page that was already open stops writing, and what it kept is gone.
    $open->set('plan_code', 'solo');
    expect(draftEntries())->toBe([]);

    $open->set('step', 4)->call('submit')->assertForbidden();
});

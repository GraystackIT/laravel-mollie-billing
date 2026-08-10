<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Component;
use Livewire\Livewire;

/**
 * A log the step callbacks can append to across Livewire request boundaries.
 *
 * A by-reference closure capture does not survive them: `resolveCheckoutSteps()` re-runs the
 * registration closure on every request, so each one gets fresh inner closures bound to the
 * variable as it was when the *callback* was registered.
 */
class CustomStepLog
{
    /** @var array<int, string> */
    public static array $entries = [];
}

/**
 * The custom-step contract, from the point of view of a step whose state can go stale.
 *
 * A step that gates on something outside the form — an email confirmation, an external
 * verification — needs two things the contract only half provided: its complaint must
 * disappear once the customer fixes what it complained about, and it must be told when the
 * customer comes back to it, because by then whatever it verified may no longer hold.
 *
 * Without the first, "please confirm your email address" stayed on screen under a field that
 * already read "confirmed". Without the second, returning via Back left the step live but
 * unaware, so it could not re-issue a code and only a page reload got the customer moving.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 990, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn () => true);
});

afterEach(function (): void {
    MollieBilling::checkoutStepsUsing(fn () => []);
});

/**
 * A step that refuses to advance until `$confirmed` is true, reporting it the way an app
 * reports a condition no `validate()` rule can express.
 */
function registerConfirmationStep(): void
{
    MollieBilling::checkoutStepsUsing(function (): array {
        return [[
            'key' => 'account',
            'label' => 'Account',
            'headline' => 'Create your account',
            'description' => 'Confirm your email address.',
            'view' => 'mollie-billing::livewire.billing.components.checkout.step-billing',
            'validate' => function (Component $component): void {
                CustomStepLog::$entries[] = 'validate';

                // Thrown, not merely added: a bare addError() is discarded by the exception
                // Livewire expects a failing validation to raise, and an app reporting a
                // condition no rule can express raises it this way.
                if (($component->customData['confirmed'] ?? false) !== true) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'customData.confirmed' => 'Please confirm your email address.',
                    ]);
                }
            },
            'enter' => function (Component $component): void {
                CustomStepLog::$entries[] = 'enter';

                // What a real step does here: the confirmation cannot be trusted after the
                // customer has been elsewhere, so it is dropped and can be requested again.
                $component->customData['confirmed'] = false;
            },
        ]];
    });
}

it('clears a custom step error once the customer fixes what it complained about', function (): void {
    CustomStepLog::$entries = [];
    registerConfirmationStep();

    $component = Livewire::test('mollie-billing::checkout');

    // Attempt to advance unconfirmed: the step reports it.
    $component->call('next')->assertHasErrors('customData.confirmed');
    expect($component->get('step'))->toBe(1);

    // The customer confirms, and the step advances. The complaint must be gone — it is keyed
    // on something no later validate() touches, so nothing else would ever clear it.
    $component->set('customData.confirmed', true)
        ->call('next')
        ->assertHasNoErrors();

    expect($component->get('step'))->toBe(2);
});

it('tells a custom step when the customer navigates back into it', function (): void {
    CustomStepLog::$entries = [];
    registerConfirmationStep();

    $component = Livewire::test('mollie-billing::checkout');

    $component->set('customData.confirmed', true)->call('next');
    expect($component->get('step'))->toBe(2);

    CustomStepLog::$entries = [];

    $component->call('back');

    // The step is entered, so it can re-establish whatever went stale while the customer was
    // away — here, dropping a confirmation that no longer holds and being in a position to
    // ask for a new code.
    expect($component->get('step'))->toBe(1)
        ->and(CustomStepLog::$entries)->toContain('enter')
        ->and($component->get('customData.confirmed'))->toBeFalse();
});

it('does not leave the previous step complaint on the step returned to', function (): void {
    CustomStepLog::$entries = [];
    registerConfirmationStep();

    $component = Livewire::test('mollie-billing::checkout');

    $component->set('customData.confirmed', true)->call('next');

    // Fail forwards out of the billing step, then go back.
    $component->set('company_name', '')->call('next')->assertHasErrors('company_name');

    $component->call('back')->assertHasNoErrors();

    expect($component->get('step'))->toBe(1);
});

it('runs enter on a forward arrival too, so a step cannot be entered untold', function (): void {
    // Two custom steps: stepping forward from the first into the second has to announce the
    // second, or a step reached only by going forwards never hears about itself.
    CustomStepLog::$entries = [];

    MollieBilling::checkoutStepsUsing(function (): array {
        $step = fn (string $key): array => [
            'key' => $key,
            'label' => $key,
            'headline' => $key,
            'description' => $key,
            'view' => 'mollie-billing::livewire.billing.components.checkout.step-billing',
            'enter' => function (Component $component) use ($key): void {
                CustomStepLog::$entries[] = 'enter:'.$key;
            },
        ];

        return [$step('one'), $step('two')];
    });

    $component = Livewire::test('mollie-billing::checkout');

    $component->call('next');

    expect($component->get('step'))->toBe(2)
        ->and(CustomStepLog::$entries)->toContain('enter:two');
});

it('leaves a step without an enter callback alone', function (): void {
    // The hook is optional — the contract before it had `validate` only, and a step that
    // declares no `enter` must behave exactly as it did.
    MollieBilling::checkoutStepsUsing(fn () => [[
        'key' => 'account',
        'label' => 'Account',
        'headline' => 'Account',
        'description' => 'Account',
        'view' => 'mollie-billing::livewire.billing.components.checkout.step-billing',
    ]]);

    $component = Livewire::test('mollie-billing::checkout');

    $component->call('next');
    expect($component->get('step'))->toBe(2);

    $component->call('back');
    expect($component->get('step'))->toBe(1);
});

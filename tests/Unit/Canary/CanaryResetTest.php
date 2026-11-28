<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryReset;

/**
 * Which canary a visit resets (tests/SandboxBrowser/Support/CanaryReset): one variable per canary,
 * and the old MOLLIE_BILLING_CANARY_RESET still meaning both.
 */
function canaryResetEnv(array $vars): Closure
{
    return static fn (string $name): mixed => $vars[$name] ?? false;
}

it('resets nothing without a variable', function (): void {
    expect(CanaryReset::requested('subscription', canaryResetEnv([])))->toBeFalse()
        ->and(CanaryReset::requested('renewal_replay', canaryResetEnv([])))->toBeFalse();
});

it('resets only the canary whose own variable is set', function (): void {
    $subscription = canaryResetEnv([CanaryReset::SUBSCRIPTION => '1']);
    $renewal = canaryResetEnv([CanaryReset::RENEWAL_REPLAY => '1']);

    expect(CanaryReset::requested('subscription', $subscription))->toBeTrue()
        ->and(CanaryReset::requested('renewal_replay', $subscription))->toBeFalse()
        ->and(CanaryReset::requested('renewal_replay', $renewal))->toBeTrue()
        ->and(CanaryReset::requested('subscription', $renewal))->toBeFalse();
});

it('keeps MOLLIE_BILLING_CANARY_RESET meaning both', function (): void {
    $both = canaryResetEnv([CanaryReset::BOTH => '1']);

    expect(CanaryReset::requested('subscription', $both))->toBeTrue()
        ->and(CanaryReset::requested('renewal_replay', $both))->toBeTrue();
});

it('takes only 1 as yes — an empty value (the workflow\'s "no") or 0 resets nothing', function (): void {
    foreach (['', '0', 'yes', 'true'] as $value) {
        expect(CanaryReset::requested('subscription', canaryResetEnv([CanaryReset::BOTH => $value, CanaryReset::SUBSCRIPTION => $value])))->toBeFalse();
    }

    expect(CanaryReset::requested('subscription', canaryResetEnv([CanaryReset::SUBSCRIPTION => " 1\n"])))->toBeTrue();
});

it('refuses a canary it does not know', function (): void {
    CanaryReset::requested('renewal', canaryResetEnv([]));
})->throws(InvalidArgumentException::class, "Unknown canary 'renewal'");

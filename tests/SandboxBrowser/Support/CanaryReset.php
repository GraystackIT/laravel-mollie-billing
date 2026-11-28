<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser\Support;

/**
 * Which canary a visit is asked to reset — pure, pinned by `tests/Unit/Canary/CanaryResetTest`.
 *
 * Each canary has its own variable; `MOLLIE_BILLING_CANARY_RESET=1` keeps its old meaning and
 * resets both. The workflow's `reset` choice (no | renewal | subscription | both) maps onto
 * these (`.github/workflows/renewal-canary.yml`).
 */
final class CanaryReset
{
    /** Both canaries — the variable that existed first. */
    public const BOTH = 'MOLLIE_BILLING_CANARY_RESET';

    public const SUBSCRIPTION = 'MOLLIE_BILLING_SUBSCRIPTION_CANARY_RESET';

    public const RENEWAL_REPLAY = 'MOLLIE_BILLING_RENEWAL_REPLAY_RESET';

    public const CANARIES = [
        'subscription' => self::SUBSCRIPTION,
        'renewal_replay' => self::RENEWAL_REPLAY,
    ];

    /**
     * @param  'subscription'|'renewal_replay'  $canary
     * @param  (callable(string): mixed)|null  $env  reads a variable; the process environment by default
     */
    public static function requested(string $canary, ?callable $env = null): bool
    {
        if (! isset(self::CANARIES[$canary])) {
            throw new \InvalidArgumentException("Unknown canary '{$canary}', expected one of: ".implode(', ', array_keys(self::CANARIES)));
        }

        $env ??= static fn (string $name): mixed => $_SERVER[$name] ?? getenv($name);

        foreach ([self::BOTH, self::CANARIES[$canary]] as $name) {
            if (trim((string) ($env($name) ?: '')) === '1') {
                return true;
            }
        }

        return false;
    }
}

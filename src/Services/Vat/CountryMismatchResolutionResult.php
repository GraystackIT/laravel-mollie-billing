<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Enums\CountryMismatchStrategy;

/**
 * Outcome of an auto-resolve attempt.
 *
 * - Resolved: mismatch is now in Resolved state (incl. NoOp).
 * - Skipped: already resolved or attempts exhausted — no action taken.
 * - PermanentFail: cannot be auto-resolved (e.g. insufficient signal); pending stays for manual review.
 * - TransientFail: temporary error (e.g. VIES down, Mollie 5xx); job should retry.
 */
class CountryMismatchResolutionResult
{
    private function __construct(
        public readonly string $kind,
        public readonly ?string $reason = null,
        public readonly ?CountryMismatchStrategy $strategy = null,
        public readonly ?string $chosenCountry = null,
        public readonly bool $noop = false,
    ) {}

    public static function resolved(CountryMismatchStrategy $strategy, string $chosenCountry, bool $noop = false): self
    {
        return new self('resolved', null, $strategy, $chosenCountry, $noop);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }

    public static function permanentFail(string $reason): self
    {
        return new self('permanent_fail', $reason);
    }

    public static function transientFail(string $reason): self
    {
        return new self('transient_fail', $reason);
    }

    public function isResolved(): bool
    {
        return $this->kind === 'resolved';
    }

    public function isSkipped(): bool
    {
        return $this->kind === 'skipped';
    }

    public function isPermanentFail(): bool
    {
        return $this->kind === 'permanent_fail';
    }

    public function isTransientFail(): bool
    {
        return $this->kind === 'transient_fail';
    }
}

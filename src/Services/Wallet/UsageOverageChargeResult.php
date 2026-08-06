<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

/**
 * Outcome of an overage charge attempt.
 *
 * Callers must branch on {@see $outcome}, never on "is this null". The invoice
 * itself is intentionally absent: it is created by the webhook once Mollie
 * confirms the payment, so no synchronous caller can ever hold one.
 */
final class UsageOverageChargeResult
{
    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function __construct(
        public readonly UsageOverageChargeOutcome $outcome,
        public readonly ?string $paymentId = null,
        public readonly array $lineItems = [],
        public readonly int $amountNet = 0,
        public readonly int $amountGross = 0,
    ) {
    }

    public static function nothingToCharge(): self
    {
        return new self(UsageOverageChargeOutcome::NothingToCharge);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public static function charged(?string $paymentId, array $lineItems, int $amountNet, int $amountGross): self
    {
        return new self(UsageOverageChargeOutcome::Charged, $paymentId, $lineItems, $amountNet, $amountGross);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public static function alreadySettled(?string $paymentId, array $lineItems): self
    {
        return new self(UsageOverageChargeOutcome::AlreadySettled, $paymentId, $lineItems);
    }

    public function wasCharged(): bool
    {
        return $this->outcome === UsageOverageChargeOutcome::Charged;
    }
}

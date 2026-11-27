<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

/**
 * Thrown when a seat change asks for more seats than `mollie-billing.max_seats` allows.
 *
 * The portal's number input has a `min` attribute and nothing else, and `SyncSeats`
 * clamped only the lower bound — so a mistyped order of magnitude (or a `$wire.set()`)
 * reached `SubscriptionAmount::net()` and raised a prorata charge in the millions. Mollie
 * rejects an amount like that, but the local seat count may already have been written by
 * the time it does. Checkout has capped the equivalent field at 100 all along.
 */
class SeatCountExceedsMaximumException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.seat_count_exceeds_maximum';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return ['requested' => $this->requested, 'max' => $this->maximum];
    }

    public function __construct(
        public readonly Billable $billable,
        public readonly int $requested,
        public readonly int $maximum,
    ) {
        parent::__construct("Seat count {$requested} exceeds the configured maximum of {$maximum}.");
    }
}

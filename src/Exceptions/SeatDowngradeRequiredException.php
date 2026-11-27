<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class SeatDowngradeRequiredException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.seat_downgrade_required';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return ['used' => $this->usedSeats, 'included' => $this->includedSeats];
    }

    public function __construct(
        public readonly Billable $billable,
        public readonly int $usedSeats,
        public readonly int $includedSeats,
    ) {
        parent::__construct(
            "Plan change requires reducing seats from {$usedSeats} to {$includedSeats} first."
        );
    }
}

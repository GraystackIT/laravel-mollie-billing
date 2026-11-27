<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class UsageLimitExceededException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.usage_limit_exceeded';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return ['type' => $this->usageType];
    }

    public function __construct(
        public readonly Billable $billable,
        public readonly string $usageType,
        public readonly int $balance,
        public readonly int $attemptedQuantity,
    ) {
        parent::__construct();
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class DowngradeRequiresMandateException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.downgrade_requires_mandate';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return ['plan' => $this->newPlanCode];
    }

    public function __construct(
        public readonly Billable $billable,
        public readonly string $newPlanCode,
    ) {
        parent::__construct();
    }
}

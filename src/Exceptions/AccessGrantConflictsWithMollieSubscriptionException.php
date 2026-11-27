<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class AccessGrantConflictsWithMollieSubscriptionException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.access_grant_conflicts_with_mollie';
    }
}

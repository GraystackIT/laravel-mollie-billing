<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use InvalidArgumentException;

/**
 * A service refused what it was given: an empty coupon code, a refund amount of zero, a
 * country that is not one.
 *
 * An {@see InvalidArgumentException}, so every caller that catches that — and every test
 * that expects it — keeps working. What it adds is the sentence for the screen: the
 * message stays the English one the log reads, the key says it in the reader's language.
 */
class InvalidBillingInputException extends InvalidArgumentException implements TranslatableException
{
    use CarriesTranslation;

    /**
     * @param  array<string, string|int|float>  $translationParameters
     */
    public function __construct(string $message, string $translationKey, array $translationParameters = [])
    {
        parent::__construct($message);
        $this->withTranslation($translationKey, $translationParameters);
    }
}

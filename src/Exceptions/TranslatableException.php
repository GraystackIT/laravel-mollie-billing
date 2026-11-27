<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use Throwable;

/**
 * A refusal that knows how to say itself to a person.
 *
 * The exception message is for the log and stays English. What a screen shows is the
 * sentence behind {@see translationKey()}, in the reader's language, resolved through
 * {@see \GraystackIT\MollieBilling\Support\ExceptionMessage}. Showing `getMessage()` on a
 * screen read an English developer sentence to every operator and customer.
 *
 * The key is null where an instance carries none — a package exception an app throws with
 * its own message, say. The screen then falls back to a generic translated sentence.
 */
interface TranslatableException extends Throwable
{
    /** The full `billing::…` key of the sentence to show, or null when this instance has none. */
    public function translationKey(): ?string;

    /** @return array<string, string|int|float> */
    public function translationParameters(): array;
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Exceptions\TranslatableException;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * The sentence a screen shows for an exception — never its `getMessage()`.
 *
 * Exception messages are English and written for the log. A package exception carries a
 * translation key ({@see TranslatableException}); this resolves it in the current locale.
 * Anything else falls back:
 *
 * - {@see for()}: a generic translated sentence — for customers, who must not read an
 *   internal message.
 * - {@see forOperator()}: a translated sentence with the original message as its detail —
 *   for the admin screens, where an operator acting on an app's own refusal needs what it
 *   said, even in English.
 */
final class ExceptionMessage
{
    /** The translated sentence the exception carries, or null when it carries none. */
    public static function translated(Throwable $e): ?string
    {
        if (! $e instanceof TranslatableException) {
            return null;
        }

        $key = $e->translationKey();

        if ($key === null || ! Lang::has($key)) {
            return null;
        }

        return (string) __($key, $e->translationParameters());
    }

    /**
     * @param  array<string, string|int|float>  $fallbackParameters
     */
    public static function for(Throwable $e, string $fallbackKey = 'billing::exceptions.generic', array $fallbackParameters = []): string
    {
        return self::translated($e) ?? (string) __($fallbackKey, $fallbackParameters);
    }

    public static function forOperator(Throwable $e): string
    {
        return self::translated($e)
            ?? (string) __('billing::exceptions.refused_with_detail', ['message' => $e->getMessage()]);
    }

    /** Whether the exception says itself — a refusal to show, not an error to report. */
    public static function isTranslatable(Throwable $e): bool
    {
        return self::translated($e) !== null;
    }
}

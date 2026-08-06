<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * Money formatting helper for the package.
 *
 * Single place that turns integer cents into a display string. Views should use
 * the `<x-mollie-billing::admin.money>` component where markup is possible and
 * call {@see format()} directly where a plain string is needed — e.g. inside a
 * translation placeholder, which cannot contain a Blade component.
 *
 * The currency symbol comes from `mollie-billing.currency_symbol` when that is
 * set, otherwise from the built-in map for the configured currency — leave the
 * config key empty and a non-EUR setup renders its own symbol everywhere.
 * Digit grouping follows the active locale when ext-intl is available; without
 * it the helper falls back to `number_format()` rather than throwing, since
 * ext-intl is not a package requirement.
 *
 * Negative amounts render as "-€5.00": the sign precedes the symbol.
 */
class BillingMoney
{
    public static function format(?int $cents, ?string $currency = null, bool $signed = false): string
    {
        $amount = (int) ($cents ?? 0);

        // The sign belongs in front of the symbol ("-€5.00"), so it is resolved here
        // and the magnitude is handed to number() — otherwise the formatter emits its
        // own minus behind the symbol.
        $sign = match (true) {
            $amount < 0 => '-',
            $signed && $amount > 0 => '+',
            default => '',
        };

        return $sign.self::symbol($currency).self::number(abs($amount) / 100);
    }

    public static function symbol(?string $currency = null): string
    {
        $configured = self::configuredCurrency();
        $code = strtoupper($currency ?? $configured);

        if ($code === $configured) {
            $symbol = config('mollie-billing.currency_symbol');

            if (is_string($symbol) && $symbol !== '') {
                return $symbol;
            }
        }

        return match ($code) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $code.' ',
        };
    }

    /**
     * Locale-aware decimal string without a currency symbol. For amounts that
     * appear inside a sentence that already names the currency, and for input
     * examples the admin is meant to retype.
     */
    public static function number(float $value): string
    {
        if (! class_exists(\NumberFormatter::class)) {
            return number_format($value, 2);
        }

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

        return $formatter->format($value) ?: number_format($value, 2);
    }

    private static function configuredCurrency(): string
    {
        $currency = config('mollie-billing.currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR';
    }
}

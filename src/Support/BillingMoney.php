<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;

/**
 * Money helper for the package: minor-unit scale, display strings and the
 * decimal strings that go out to Mollie.
 *
 * Every amount in the package is integer minor units. How many minor units make
 * up one major unit is a property of the currency — 100 for EUR, 1 for JPY, 1000
 * for KWD — so neither a display string nor an outbound Mollie amount may
 * hardcode two decimals. {@see minorUnitFactor()} is the single place that
 * answers that question and {@see mollieAmount()} the only way an outbound
 * `Mollie\Api\Http\Data\Money` value should be built.
 *
 * Views should use the `<x-mollie-billing::admin.money>` component where markup
 * is possible and call {@see format()} directly where a plain string is needed —
 * e.g. inside a translation placeholder, which cannot contain a Blade component.
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

        return $sign.self::symbol($currency).self::numberFromMinor(abs($amount), $currency);
    }

    /**
     * The price of one unit of a pack, with two decimals more than the currency has
     * ("€0.0833" for 500 SMS at €41.65) — a unit of a pack usually costs less than the
     * smallest coin. Divided from the total that is shown next to it, so the two cannot
     * disagree about VAT: pass the gross total to a customer who sees gross, the net one
     * under reverse charge. Display only; nothing is charged per unit.
     */
    public static function formatUnitPrice(int $totalMinor, int $quantity, ?string $currency = null): string
    {
        $quantity = max(1, $quantity);
        $digits = self::fractionDigits($currency) + 2;
        $value = $totalMinor / self::minorUnitFactor($currency) / $quantity;

        if (! class_exists(\NumberFormatter::class)) {
            return self::symbol($currency).number_format($value, $digits);
        }

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $digits);

        return self::symbol($currency).($formatter->format($value) ?: number_format($value, $digits));
    }

    /**
     * A whole count in the active locale's grouping ("1.000" in German, "1,000" in
     * English) — for quantities shown next to prices.
     */
    public static function count(int $value): string
    {
        if (! class_exists(\NumberFormatter::class)) {
            return number_format($value);
        }

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);

        return $formatter->format($value) ?: number_format($value);
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
     * Locale-aware decimal string for an amount in minor units, without a
     * currency symbol. Preferred over {@see number()} everywhere the amount comes
     * from the database or the catalog: the caller does not have to know how many
     * minor units the currency has.
     */
    public static function numberFromMinor(?int $minor, ?string $currency = null): string
    {
        return self::number((int) ($minor ?? 0) / self::minorUnitFactor($currency), $currency);
    }

    /**
     * Locale-aware decimal string without a currency symbol. For amounts that
     * appear inside a sentence that already names the currency, and for input
     * examples the admin is meant to retype.
     *
     * `$value` is a major-unit amount; pass the currency along so the number of
     * decimals matches it (a JPY amount has none).
     */
    public static function number(float $value, ?string $currency = null): string
    {
        $digits = self::fractionDigits($currency);

        if (! class_exists(\NumberFormatter::class)) {
            return number_format($value, $digits);
        }

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $digits);

        return $formatter->format($value) ?: number_format($value, $digits);
    }

    /**
     * Format integer minor units as the decimal string Mollie's Money expects.
     *
     * The number of decimals is a property of the currency, so a hardcoded 2
     * sends the wrong order of magnitude for every currency that does not have
     * exactly two: JPY has none, and a ¥2900 plan would go out as ¥29. Unknown
     * codes fall back to two decimals instead of throwing — the amount is already
     * on its way to Mollie, which rejects the currency itself.
     */
    public static function mollieAmount(int $minor, ?string $currency = null): string
    {
        $code = self::normalizeCurrency($currency);

        try {
            return (string) Money::ofMinor($minor, $code)->getAmount();
        } catch (UnknownCurrencyException) {
            return number_format($minor / 100, 2, '.', '');
        }
    }

    /**
     * Inverse of {@see mollieAmount()}: read a decimal amount Mollie sent us as
     * integer minor units.
     *
     * The scale is a property of the currency, so a hardcoded ×100 turns a JPY
     * refund of 500 into 50 000 — and since every webhook amount and every refund
     * amount enters the package through this direction, the error lands directly in
     * a booked invoice. An unknown currency or unparsable amount degrades to the
     * two-decimal reading rather than throwing, because the caller is handling money
     * Mollie has already moved — but the catch stays narrow on purpose: a `Throwable`
     * here silently turns any programming error into a ×100 misreading, which is how
     * a missing `RoundingMode` import once made ¥500 book as ¥50 000.
     */
    public static function minorFromMollieAmount(string|float|int|null $amount, ?string $currency = null): int
    {
        $value = trim((string) ($amount ?? ''));

        if ($value === '') {
            return 0;
        }

        try {
            return Money::of($value, self::normalizeCurrency($currency), null, RoundingMode::HalfUp)
                ->getMinorAmount()
                ->toInt();
        } catch (MoneyException|MathException) {
            return (int) round(((float) $value) * 100);
        }
    }

    /**
     * Gross total for a set of line nets, with VAT rounded per line.
     *
     * Every invoice the package books derives its header VAT by summing the VAT of
     * its line items (see `InvoiceService::createForPayment()`), so any amount we
     * ask Mollie to collect for those same lines has to be assembled the same way.
     * `round(Σ net × rate)` and `Σ round(net × rate)` are not the same number: a
     * 550 plan plus a 550 addon at 19% collects 1309 the first way and books 1310
     * the second. A single cent still fits inside
     * `WebhookSupport::reconcileWithPaidAmount()`'s slack; five or six lines do
     * not, and every renewal would then report a spurious PaymentAmountMismatch.
     *
     * `$vatRate` is a percentage from the country lookup (19.0 for 19%) and is
     * never reverse-engineered from a gross amount. A rate of 0 — reverse-charge,
     * or a country without VAT — collapses this to the sum of the nets.
     *
     * @param  iterable<int>  $lineNets  net amount per invoice line, in minor units
     */
    public static function grossWithLineVat(iterable $lineNets, float $vatRate): int
    {
        $gross = 0;

        foreach ($lineNets as $net) {
            $net = (int) $net;
            $gross += $net + (int) round($net * $vatRate / 100);
        }

        return $gross;
    }

    /** Minor units per major unit: 100 for EUR, 1 for JPY, 1000 for KWD. */
    public static function minorUnitFactor(?string $currency = null): int
    {
        return 10 ** self::fractionDigits($currency);
    }

    /**
     * Decimals the currency is written with. Unknown codes are treated as 2 so a
     * misconfigured `BILLING_CURRENCY` degrades to the common case instead of
     * breaking every price on the page.
     */
    public static function fractionDigits(?string $currency = null): int
    {
        try {
            return Currency::of(self::normalizeCurrency($currency))->getDefaultFractionDigits();
        } catch (UnknownCurrencyException) {
            return 2;
        }
    }

    /**
     * Normalise a currency code to the uppercase ISO-4217 form brick/money and
     * Mollie expect, falling back to the configured currency when empty. A
     * lowercase `BILLING_CURRENCY` would otherwise blow up every lookup.
     */
    public static function normalizeCurrency(?string $currency): string
    {
        $code = strtoupper(trim((string) $currency));

        if ($code === '') {
            $code = strtoupper(trim((string) config('mollie-billing.currency', 'EUR')));
        }

        return $code !== '' ? $code : 'EUR';
    }

    private static function configuredCurrency(): string
    {
        return self::normalizeCurrency(null);
    }
}

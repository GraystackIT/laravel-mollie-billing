<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that pins a datetime column to UTC on both read and write,
 * regardless of `app.timezone`.
 *
 * **Why this exists.** The default `'datetime'` cast rehydrates stored values
 * via `Date::createFromFormat($format, $value)` which falls back to PHP's
 * `date_default_timezone_get()` (= `app.timezone`) for the timezone of the
 * resulting Carbon instance. A consuming app that runs in `Europe/Berlin` would
 * therefore reinterpret a UTC-written string `2026-05-02 23:30:00` as Berlin
 * local time — a silent two-hour shift on every read.
 *
 * This cast bypasses that path: stored strings are always parsed as UTC, and
 * incoming Carbon/`DateTimeInterface` values are converted to UTC before being
 * formatted into the storage string. The package's persistence guarantees are
 * therefore independent of `app.timezone`.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string|null>
 */
class UtcDatetime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }

        return CarbonImmutable::createFromFormat(
            $model->getDateFormat(),
            (string) $value,
            'UTC',
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC')->format($model->getDateFormat());
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC')->format($model->getDateFormat());
        }

        // Fallback: parse as UTC. Strings written by other code paths land here.
        return CarbonImmutable::parse((string) $value, 'UTC')
            ->setTimezone('UTC')
            ->format($model->getDateFormat());
    }
}

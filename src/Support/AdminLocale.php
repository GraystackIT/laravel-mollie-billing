<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Illuminate\Container\Container;

/**
 * The admin panel is operator tooling — its terminology stays in English no
 * matter what locale the surrounding app uses. This keeps badge labels,
 * enum names and translatable strings consistent with code identifiers,
 * logs and the package spec, so an operator searching for `single_payment`
 * always sees `Single payment` in the UI.
 */
class AdminLocale
{
    /**
     * Run a callback under the admin locale (English) and restore the previous
     * locale afterwards. Use to render any translatable string in admin views.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function with(callable $callback): mixed
    {
        $app = Container::getInstance();
        $original = $app->getLocale();

        try {
            $app->setLocale('en');

            return $callback();
        } finally {
            $app->setLocale($original);
        }
    }

    /**
     * Convenience: render an enum's `label()` in the admin locale.
     */
    public static function enumLabel(\UnitEnum $enum): string
    {
        if (! method_exists($enum, 'label')) {
            return $enum->name;
        }

        /** @var \UnitEnum&object{label(): string} $enum */
        return self::with(fn (): string => $enum->label());
    }
}

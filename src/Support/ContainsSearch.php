<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * The admin's "contains" search: `lower(column) like ? escape '!'` with the pattern built
 * here, the same on every driver.
 *
 * - Case-insensitive: Postgres LIKE is case-sensitive, so both sides are lower-cased.
 * - `%` and `_` in the term are characters, not wildcards; `!` is the escape character
 *   because a backslash means different things to the three drivers' string literals.
 * - The term is lower-cased the way the database lower-cases the column. SQLite's built-in
 *   `lower()` folds ASCII only ("MÜLLER" → "mÜller"), so a term folded by `mb_strtolower()`
 *   ("müller") would no longer find even its own exact spelling there. Postgres and MySQL
 *   fold Unicode.
 */
final class ContainsSearch
{
    public const SQL = "like ? escape '!'";

    public static function pattern(Builder $query, string $term): string
    {
        $folded = $query->getConnection()->getDriverName() === 'sqlite'
            ? strtolower($term)
            : mb_strtolower($term);

        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $folded).'%';
    }
}

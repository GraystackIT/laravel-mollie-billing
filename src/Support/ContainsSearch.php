<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * The admin's "contains" search: `lower(column) like lower(?) escape '!'`, the pattern built
 * here, the same on every driver.
 *
 * - Case-insensitive: Postgres LIKE is case-sensitive, so both sides are lower-cased.
 * - Both sides by the database's own `lower()`, never one of them by PHP: SQLite's folds
 *   ASCII only ("MÜLLER" → "mÜller"), Postgres' depends on the collation provider (simple
 *   case mapping under `C.UTF-8` keeps a final "Σ" as "σ", where `mb_strtolower()` gives
 *   "ς"). A term folded differently from the column would not even find its own spelling.
 * - `%` and `_` in the term are characters, not wildcards; `!` is the escape character
 *   because a backslash means different things to the three drivers' string literals, and
 *   `lower()` leaves all three alone.
 */
final class ContainsSearch
{
    public const SQL = "like lower(?) escape '!'";

    public static function pattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }
}

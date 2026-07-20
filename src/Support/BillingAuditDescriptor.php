<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Closure;
use GraystackIT\MollieBilling\Enums\AuditCategory;

/**
 * Describes how one billing event becomes one audit-trail row.
 *
 * The `key` is a translation key suffix, never a rendered sentence: the stored
 * description is `audit.<key>` and the text is resolved at render time from
 * resources/lang/{locale}/audit.php. See BillingAuditEntry.
 */
final readonly class BillingAuditDescriptor
{
    /**
     * @param  Closure(object): array<string, scalar|null>  $properties
     */
    public function __construct(
        public string $key,
        public AuditCategory $category,
        public Closure $properties,
    ) {
    }

    /**
     * Placeholder values for the translation string.
     *
     * Deliberately raw (plan codes, cents, counts, ids) rather than formatted —
     * BillingAuditEntry resolves them against the current locale and catalog so
     * old rows render with today's plan names and number formats.
     *
     * @return array<string, scalar|null>
     */
    public function properties(object $event): array
    {
        /** @var array<string, scalar|null> $values */
        $values = ($this->properties)($event);

        return $values;
    }

    /** The value stored in `activity_log.description`. */
    public function descriptionKey(): string
    {
        return 'audit.'.$this->key;
    }
}

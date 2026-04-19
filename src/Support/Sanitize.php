<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

class Sanitize
{
    /**
     * Validate a back-URL to prevent open redirects.
     *
     * Only relative paths (starting with "/" but not "//") are considered safe.
     */
    public static function backUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Must start with exactly one slash (not protocol-relative //)
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        // Must not contain a scheme
        $parsed = parse_url($url);
        if (isset($parsed['scheme']) || isset($parsed['host'])) {
            return null;
        }

        return $url;
    }
}

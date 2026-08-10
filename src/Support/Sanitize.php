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

        // A backslash is an authority delimiter, not a path character. Browsers follow the
        // WHATWG URL parser, where `/\evil.example` enters the authority state exactly as
        // `//evil.example` does — `new URL('/\evil.example', 'https://app.test/')` resolves to
        // `https://evil.example/`. parse_url() disagrees and reports no host, so the checks
        // below waved it through and the checkout header rendered an attacker-controlled
        // "Back" link on the page immediately before the payment step. Control characters go
        // with it: they can be stripped by the browser and change what the rest parses as.
        if (str_contains($url, '\\') || preg_match('/[[:cntrl:]]/', $url) === 1) {
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

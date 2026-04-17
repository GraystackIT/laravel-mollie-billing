<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

class Sanitize
{
    /**
     * Validate a CSS color value, returning a safe default if invalid.
     *
     * Accepts hex (#RGB, #RRGGBB, #RRGGBBAA), rgb(), rgba(), hsl(), hsla().
     */
    public static function cssColor(string $value, string $default = '#6366f1'): string
    {
        $value = trim($value);

        // Hex colors: #RGB, #RGBA, #RRGGBB, #RRGGBBAA
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) && in_array(strlen($value), [4, 5, 7, 9], true)) {
            return $value;
        }

        // rgb()/rgba()/hsl()/hsla() with only safe characters (digits, commas, dots, spaces, %)
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[\d\s,.\/%]+\s*\)$/', $value)) {
            return $value;
        }

        return $default;
    }

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

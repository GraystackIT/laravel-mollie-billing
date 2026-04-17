<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

class CountryResolver
{
    /** @var array<string, string> ISO alpha-2 => English short name */
    private const EU_COUNTRIES = [
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BG' => 'Bulgaria',
        'HR' => 'Croatia',
        'CY' => 'Cyprus',
        'CZ' => 'Czechia',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'FI' => 'Finland',
        'FR' => 'France',
        'DE' => 'Germany',
        'GR' => 'Greece',
        'HU' => 'Hungary',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'LV' => 'Latvia',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MT' => 'Malta',
        'NL' => 'Netherlands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'ES' => 'Spain',
        'SE' => 'Sweden',
    ];

    /**
     * Resolve the configured country list to a [iso => localised name] map.
     *
     * Sources (in order):
     *  1. checkout_countries.regions (currently only 'EU')
     *  2. checkout_countries.include (individual ISO codes)
     *  3. additional_countries from config (auto-included)
     *  4. checkout_countries.exclude (removed)
     *
     * Names are translated via billing::countries.XX lang keys with the
     * English short name as fallback.
     *
     * @return array<string, string> Sorted by localised name.
     */
    public static function resolve(): array
    {
        $config = config('mollie-billing.checkout_countries', ['regions' => ['EU']]);
        $countries = [];

        // 1. Resolve built-in regions
        foreach ($config['regions'] ?? ['EU'] as $region) {
            if ($region === 'EU') {
                $countries = array_merge($countries, self::EU_COUNTRIES);
            }
        }

        // 2. Add individual ISO codes from 'include'
        foreach ($config['include'] ?? [] as $iso) {
            if (! isset($countries[$iso])) {
                $countries[$iso] = $iso;
            }
        }

        // 3. Auto-include countries from 'additional_countries' config
        foreach (config('mollie-billing.additional_countries', []) as $iso => $data) {
            if (! isset($countries[$iso])) {
                $countries[$iso] = $data['name'] ?? $iso;
            }
        }

        // 4. Remove excluded countries
        foreach ($config['exclude'] ?? [] as $iso) {
            unset($countries[$iso]);
        }

        // 5. Translate via package lang files
        foreach ($countries as $iso => $fallback) {
            $key = "billing::countries.{$iso}";
            $translated = __($key);
            $countries[$iso] = $translated !== $key ? $translated : $fallback;
        }

        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        return $countries;
    }

    /**
     * Get the raw EU-27 ISO codes (useful for VAT-related logic).
     *
     * @return list<string>
     */
    public static function euCountryCodes(): array
    {
        return array_keys(self::EU_COUNTRIES);
    }
}

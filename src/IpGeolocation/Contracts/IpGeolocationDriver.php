<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation\Contracts;

interface IpGeolocationDriver
{
    /**
     * Resolve the ISO-3166-1 alpha-2 country code for the given IP, or null if unknown.
     */
    public function getCountry(string $ip): ?string;
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation\Drivers;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;

class NullDriver implements IpGeolocationDriver
{
    public function getCountry(string $ip): ?string
    {
        return null;
    }
}

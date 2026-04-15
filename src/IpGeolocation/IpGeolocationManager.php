<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\IpInfoLiteDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\NullDriver;
use Illuminate\Support\Manager;

class IpGeolocationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('mollie-billing.ip_geolocation.driver', 'null');
    }

    public function createIpinfoLiteDriver(): IpGeolocationDriver
    {
        $token = $this->config->get('mollie-billing.ip_geolocation.ipinfo_lite.token');

        return new IpInfoLiteDriver($token !== null ? (string) $token : null);
    }

    public function createNullDriver(): IpGeolocationDriver
    {
        return new NullDriver();
    }

    public function getCountry(string $ip): ?string
    {
        return $this->driver()->getCountry($ip);
    }
}

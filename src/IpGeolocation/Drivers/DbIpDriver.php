<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation\Drivers;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use Illuminate\Support\Facades\Http;

class DbIpDriver implements IpGeolocationDriver
{
    public function __construct(private readonly ?string $apiKey)
    {
    }

    public function getCountry(string $ip): ?string
    {
        // DB-IP supports `free` as the API key for the public free tier.
        $apiKey = $this->apiKey !== null && $this->apiKey !== '' ? $this->apiKey : 'free';

        $response = Http::connectTimeout(1)->timeout(2)
            ->get("https://api.db-ip.com/v2/{$apiKey}/{$ip}");

        if (! $response->successful()) {
            return null;
        }

        $country = strtoupper((string) ($response->json('countryCode') ?? ''));

        return $country !== '' ? $country : null;
    }
}

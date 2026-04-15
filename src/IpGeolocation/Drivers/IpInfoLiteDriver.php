<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\IpGeolocation\Drivers;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use Illuminate\Support\Facades\Http;

class IpInfoLiteDriver implements IpGeolocationDriver
{
    public function __construct(private readonly ?string $token)
    {
    }

    public function getCountry(string $ip): ?string
    {
        if ($this->token === null) {
            return null;
        }

        $response = Http::timeout(3)->get("https://api.ipinfo.io/lite/{$ip}", ['token' => $this->token]);

        if (! $response->successful()) {
            return null;
        }

        $country = strtoupper((string) ($response->json('country') ?? ''));

        return $country !== '' ? $country : null;
    }
}

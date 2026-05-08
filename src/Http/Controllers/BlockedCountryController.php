<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BlockedCountryController
{
    public function __invoke(Request $request): View
    {
        $iso = (string) $request->query('country', '');
        $iso = preg_match('/^[A-Za-z]{2}$/', $iso) ? strtoupper($iso) : null;

        return view('mollie-billing::blocked', [
            'countryCode' => $iso,
            'countryName' => $iso !== null ? CountryResolver::name($iso) : null,
            'backUrl' => '/',
        ]);
    }
}

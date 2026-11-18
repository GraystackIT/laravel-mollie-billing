<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Illuminate\Database\Eloquent\Model;

/** Display policy only; VAT calculation and reverse charge remain unchanged. */
class BillingPriceDisplay
{
    public static function showsNetAndGross(?string $country, ?string $vatNumber): bool
    {
        $sellerCountry = app(VatCalculationService::class)->sellerCountry();

        return filled($vatNumber)
            && $sellerCountry !== null
            && CountryResolver::isoFromVatPrefix((string) $country) === $sellerCountry;
    }

    public static function forBillable(?Billable $billable): bool
    {
        return $billable instanceof Model
            && self::showsNetAndGross($billable->getBillingCountry(), $billable->vat_number ?? null);
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

/**
 * Single source of truth for subscription net amounts and invoice line items.
 *
 * Both the recurring webhook (subscription renewal) and the plan change flow
 * (immediate / scheduled / preview) compute amounts here. Apps that override
 * `Billable::getBillingAddonQuantity()` to return values > 1 are honoured
 * consistently in every place where pricing is calculated.
 */
class SubscriptionAmount
{
    /**
     * Net amount for a subscription configuration: base + extra seats + addons.
     *
     * @param  array<int, string>  $addonCodes
     */
    public static function net(
        SubscriptionCatalogInterface $catalog,
        Billable $billable,
        string $planCode,
        string $interval,
        int $seats,
        array $addonCodes,
    ): int {
        if ($planCode === '') {
            return 0;
        }

        $total = $catalog->basePriceNet($planCode, $interval);

        $includedSeats = $catalog->includedSeats($planCode);
        $extraSeats = max(0, $seats - $includedSeats);
        $seatPrice = $catalog->seatPriceNet($planCode, $interval);
        if ($seatPrice !== null && $extraSeats > 0) {
            $total += $seatPrice * $extraSeats;
        }

        foreach ($addonCodes as $code) {
            $qty = $billable->getBillingAddonQuantity((string) $code) ?: 1;
            $total += $qty * $catalog->addonPriceNet((string) $code, $interval);
        }

        return $total;
    }

    /**
     * Invoice line items for a subscription charge: base, seats, addons.
     *
     * @param  array<int, string>  $addonCodes
     * @return array<int, array<string, mixed>>
     */
    public static function lineItems(
        SubscriptionCatalogInterface $catalog,
        Billable $billable,
        string $planCode,
        string $interval,
        int $extraSeats,
        array $addonCodes,
    ): array {
        $items = [];

        $base = $catalog->basePriceNet($planCode, $interval);
        $items[] = [
            'kind' => 'plan',
            'label' => $catalog->planName($planCode) ?? $planCode,
            'code' => $planCode,
            'quantity' => 1,
            'unit_price' => $base,
            'unit_price_net' => $base,
            'total_net' => $base,
        ];

        if ($extraSeats > 0) {
            $seat = (int) ($catalog->seatPriceNet($planCode, $interval) ?? 0);
            $items[] = [
                'kind' => 'seat',
                'label' => 'Extra seats',
                'code' => null,
                'quantity' => $extraSeats,
                'unit_price' => $seat,
                'unit_price_net' => $seat,
                'total_net' => $seat * $extraSeats,
            ];
        }

        foreach ($addonCodes as $code) {
            $price = $catalog->addonPriceNet((string) $code, $interval);
            $qty = $billable->getBillingAddonQuantity((string) $code) ?: 1;
            $items[] = [
                'kind' => 'addon',
                'label' => $catalog->addonName((string) $code) ?? $code,
                'code' => $code,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_price_net' => $price,
                'total_net' => $price * $qty,
            ];
        }

        return $items;
    }
}

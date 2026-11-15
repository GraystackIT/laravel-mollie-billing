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
     * Net amount for a subscription configuration: base + extra seats + addons
     * (each addon's base price plus, where it has one, its per-seat price).
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
            $total += self::addonSeatsNet($catalog, (string) $code, $planCode, $interval, $seats);
        }

        return $total;
    }

    /**
     * The seat count a subscription is billed for: never below what the plan includes.
     *
     * Every billing path carries seats as "extra seats beyond the plan's included ones"
     * (the Mollie subscription metadata, `CreateSubscription`'s spec, the renewal
     * handler), so a stored count below the included number is indistinguishable from
     * the included number by the time an amount is built. Per-seat addon pricing counts
     * ALL seats, so it has to settle on one reading — this one, which is the one those
     * paths can reproduce.
     */
    public static function billedSeats(SubscriptionCatalogInterface $catalog, string $planCode, int $seats): int
    {
        return max($seats, $catalog->includedSeats($planCode));
    }

    /**
     * How many seats an addon's per-seat price is charged for: every billed seat of the
     * subscription — the plan's included ones too — beyond the addon's own free seats.
     * 0 for an addon without a per-seat price.
     */
    public static function addonChargedSeats(
        SubscriptionCatalogInterface $catalog,
        string $addonCode,
        string $planCode,
        string $interval,
        int $seats,
    ): int {
        $seatPrice = $catalog->addonSeatPriceNet($addonCode, $interval);

        if ($seatPrice === null || $seatPrice <= 0) {
            return 0;
        }

        return max(0, self::billedSeats($catalog, $planCode, $seats) - $catalog->addonIncludedSeats($addonCode));
    }

    /**
     * Net of an addon's per-seat part for a subscription with `$seats` seats.
     */
    public static function addonSeatsNet(
        SubscriptionCatalogInterface $catalog,
        string $addonCode,
        string $planCode,
        string $interval,
        int $seats,
    ): int {
        $charged = self::addonChargedSeats($catalog, $addonCode, $planCode, $interval, $seats);

        return $charged > 0 ? $charged * (int) $catalog->addonSeatPriceNet($addonCode, $interval) : 0;
    }

    /**
     * Invoice line items for a subscription charge: base, seats, addons — and one
     * `addon_seats` line per addon that is priced per seat.
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
                'kind' => 'seats',
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

            // The per-seat part is a line of its own, never folded into the base line or
            // into the plan's seat line: it has its own quantity (which moves with the seat
            // count while the base does not) and is credited separately on a seat change.
            // `$extraSeats` is all this method is given, and all any caller has — the
            // billed seat count is the plan's included seats plus those.
            $totalSeats = $catalog->includedSeats($planCode) + max(0, $extraSeats);
            $chargedSeats = self::addonChargedSeats($catalog, (string) $code, $planCode, $interval, $totalSeats);

            if ($chargedSeats > 0) {
                $seatPrice = (int) $catalog->addonSeatPriceNet((string) $code, $interval);
                $items[] = [
                    'kind' => 'addon_seats',
                    'label' => self::addonSeatsLabel($catalog, (string) $code),
                    'code' => $code,
                    'quantity' => $chargedSeats,
                    'unit_price' => $seatPrice,
                    'unit_price_net' => $seatPrice,
                    'total_net' => $seatPrice * $chargedSeats,
                ];
            }
        }

        return $items;
    }

    public static function addonSeatsLabel(SubscriptionCatalogInterface $catalog, string $addonCode): string
    {
        return __('billing::portal.addon_seats_line', ['addon' => $catalog->addonName($addonCode) ?? $addonCode]);
    }
}

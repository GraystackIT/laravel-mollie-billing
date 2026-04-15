<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingPolicy;

class PreviewService
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly CouponService $couponService,
    ) {
    }

    public function previewPlanChange(Billable $billable, string $planCode, string $interval): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            planCode: $planCode,
            interval: $interval,
        ));
    }

    public function previewSeatChange(Billable $billable, int $seats): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            seats: $seats,
        ));
    }

    public function previewAddonChange(Billable $billable, array $addons): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            addons: $addons,
        ));
    }

    public function previewUpdate(Billable $billable, array|SubscriptionUpdateRequest $request): array
    {
        $dto = SubscriptionUpdateRequest::from($request);

        $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
        $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $currentSeats = $billable->getBillingSeatCount();
        $currentAddons = $this->currentAddonQuantities($billable);

        $newPlan = $dto->planCode ?? $currentPlan;
        $newInterval = $dto->interval ?? $currentInterval;
        $newSeats = $dto->seats ?? $currentSeats;
        $newAddons = $dto->addons ?? $currentAddons;

        $warnings = [];
        $errors = [];

        $currentNet = $this->computeAmountNet(
            $currentPlan,
            $currentInterval,
            $currentSeats,
            $currentAddons,
        );

        $newNet = $this->computeAmountNet(
            $newPlan,
            $newInterval,
            $newSeats,
            $newAddons,
        );

        $lineItems = $this->buildLineItems(
            $newPlan,
            $newInterval,
            $newSeats,
            $newAddons,
        );

        // Coupon handling
        $couponDiscountNet = 0;
        if ($dto->couponCode !== null && $dto->couponCode !== '') {
            try {
                $coupon = $this->couponService->validate(
                    $dto->couponCode,
                    $billable,
                    [
                        'planCode' => $newPlan,
                        'interval' => $newInterval,
                        'addonCodes' => array_keys($newAddons),
                        'orderAmountNet' => $newNet,
                    ],
                );

                $couponDiscountNet = $this->couponService->computeRecurringDiscount($coupon, $newNet);

                if ($couponDiscountNet > 0) {
                    $lineItems[] = [
                        'kind' => 'coupon',
                        'label' => 'Coupon '.$coupon->code,
                        'code' => (string) $coupon->code,
                        'quantity' => 1,
                        'unit_price_net' => -$couponDiscountNet,
                        'total_net' => -$couponDiscountNet,
                    ];
                }
            } catch (InvalidCouponException $e) {
                $warnings[] = 'Coupon '.$dto->couponCode.' is not applicable: '.$e->getMessage();
            } catch (\RuntimeException $e) {
                $warnings[] = 'Coupon '.$dto->couponCode.' cannot be applied: '.$e->getMessage();
            }
        }

        // Seat-downgrade warning
        if ($dto->seats !== null) {
            $usedSeats = (int) ($billable->getBillingSubscriptionMeta()['used_seats'] ?? 0);
            $includedSeats = $this->catalog->includedSeats($newPlan);
            $minimumRequired = max($usedSeats, $includedSeats);
            if ($dto->seats < $minimumRequired) {
                $warnings[] = 'seats below active count';
            }
        }

        // Prorata
        $planChanged = $dto->planCode !== null && $dto->planCode !== $currentPlan;
        $intervalChanged = $dto->interval !== null && $dto->interval !== $currentInterval;
        $prorataFactor = 0.0;
        $prorataChargeNet = 0;
        $prorataCreditNet = 0;

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();

        if (
            ($planChanged || $intervalChanged || BillingPolicy::isProrataEnabled())
            && $periodStart !== null
            && $periodEnd !== null
        ) {
            $prorataFactor = BillingPolicy::prorataFactor($periodStart, $periodEnd);
            $diff = $newNet - $currentNet;
            if ($diff > 0) {
                $prorataChargeNet = (int) round($diff * $prorataFactor);
            } elseif ($diff < 0) {
                $prorataCreditNet = (int) round(-$diff * $prorataFactor);
            }
        }

        // VAT
        $country = $billable->getBillingCountry() ?? 'DE';
        try {
            $vat = $this->vatService->calculate($country, max(0, $newNet - $couponDiscountNet));
        } catch (\Throwable $e) {
            $vat = ['net' => max(0, $newNet - $couponDiscountNet), 'vat' => 0, 'gross' => max(0, $newNet - $couponDiscountNet), 'rate' => 0.0];
            $warnings[] = 'VAT calculation unavailable: '.$e->getMessage();
        }

        return [
            'currentPriceNet' => $currentNet,
            'newPriceNet' => $newNet,
            'diffNet' => $newNet - $currentNet,
            'prorataFactor' => $prorataFactor,
            'prorataChargeNet' => $prorataChargeNet,
            'prorataCreditNet' => $prorataCreditNet,
            'couponDiscountNet' => $couponDiscountNet,
            'vatRate' => $vat['rate'],
            'vatAmount' => $vat['vat'],
            'grossTotal' => $vat['gross'],
            'lineItems' => $lineItems,
            'warnings' => $warnings,
            'errors' => $errors,
            'appliesAt' => $dto->applyAt === 'end_of_period'
                ? ($periodEnd ?? 'immediate')
                : 'immediate',
        ];
    }

    /**
     * @param  array<string, int>  $addons
     */
    private function computeAmountNet(
        string $planCode,
        string $interval,
        int $seats,
        array $addons,
    ): int {
        if ($planCode === '') {
            return 0;
        }

        $base = $this->catalog->basePriceNet($planCode, $interval);
        $includedSeats = $this->catalog->includedSeats($planCode);
        $seatPrice = $this->catalog->seatPriceNet($planCode, $interval);
        $extraSeats = max(0, $seats - $includedSeats);

        $total = $base;
        if ($seatPrice !== null && $extraSeats > 0) {
            $total += $seatPrice * $extraSeats;
        }

        foreach ($addons as $addonCode => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $total += $this->catalog->addonPriceNet((string) $addonCode, $interval) * $qty;
        }

        return $total;
    }

    /**
     * @param  array<string, int>  $addons
     * @return array<int, array<string, mixed>>
     */
    private function buildLineItems(
        string $planCode,
        string $interval,
        int $seats,
        array $addons,
    ): array {
        $items = [];

        if ($planCode !== '') {
            $base = $this->catalog->basePriceNet($planCode, $interval);
            $items[] = [
                'kind' => 'plan',
                'label' => $this->catalog->planName($planCode) ?? $planCode,
                'code' => $planCode,
                'quantity' => 1,
                'unit_price_net' => $base,
                'total_net' => $base,
            ];

            $includedSeats = $this->catalog->includedSeats($planCode);
            $seatPrice = $this->catalog->seatPriceNet($planCode, $interval);
            $extraSeats = max(0, $seats - $includedSeats);

            if ($seatPrice !== null && $extraSeats > 0) {
                $items[] = [
                    'kind' => 'seat',
                    'label' => 'Extra seats',
                    'code' => 'seats',
                    'quantity' => $extraSeats,
                    'unit_price_net' => $seatPrice,
                    'total_net' => $extraSeats * $seatPrice,
                ];
            }
        }

        foreach ($addons as $addonCode => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $price = $this->catalog->addonPriceNet((string) $addonCode, $interval);
            $items[] = [
                'kind' => 'addon',
                'label' => (string) $addonCode,
                'code' => (string) $addonCode,
                'quantity' => $qty,
                'unit_price_net' => $price,
                'total_net' => $price * $qty,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function currentAddonQuantities(Billable $billable): array
    {
        $out = [];
        foreach ($billable->getActiveBillingAddonCodes() as $code) {
            $out[$code] = $billable->getBillingAddonQuantity($code) ?: 1;
        }

        return $out;
    }
}

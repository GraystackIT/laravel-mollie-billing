<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
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
        $planChanged = $dto->planCode !== null && $dto->planCode !== $currentPlan;

        // Auto-filter incompatible addons when the plan changes.
        $incompatibleAddons = [];
        $newAddons = $dto->addons ?? $currentAddons;

        if ($planChanged) {
            $filteredAddons = [];
            foreach ($newAddons as $code => $qty) {
                if ($this->catalog->planAllowsAddon($newPlan, (string) $code)) {
                    $filteredAddons[$code] = $qty;
                } else {
                    $incompatibleAddons[] = (string) $code;
                }
            }
            $newAddons = $filteredAddons;
        }

        // Auto-derive seats with A+C strategy.
        $usedSeats = $billable->getUsedBillingSeats();
        $newIncludedSeats = $this->catalog->includedSeats($newPlan);
        $seatPriceNet = $this->catalog->seatPriceNet($newPlan, $newInterval);
        $newSeats = $dto->seats ?? max($currentSeats, $usedSeats, $newIncludedSeats);

        $warnings = [];
        $errors = [];

        // Seat validation: block if plan doesn't support extra seats.
        if ($dto->seats === null && $seatPriceNet === null) {
            if ($usedSeats > $newIncludedSeats) {
                // Real team members exceed the new plan's quota — caller must
                // remove members manually before downgrading.
                $errors[] = [
                    'type' => 'seats_exceed_plan',
                    'used' => $usedSeats,
                    'included' => $newIncludedSeats,
                ];
            } elseif ($currentSeats > $newIncludedSeats) {
                // Paid (but unused) extra seats would be lost on a plan that
                // can't sell them. Resolvable by activating the drop-extra-seats
                // toggle in the UI (sets `seats` explicitly).
                $errors[] = [
                    'type' => 'paid_seats_lost',
                    'current' => $currentSeats,
                    'included' => $newIncludedSeats,
                    'lost' => $currentSeats - $newIncludedSeats,
                ];
            }
        }

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
            $includedSeats = $this->catalog->includedSeats($newPlan);
            $minimumRequired = max($usedSeats, $includedSeats);
            if ($dto->seats < $minimumRequired) {
                $warnings[] = 'seats below active count';
            }
        }

        // Prorata
        $intervalChanged = $dto->interval !== null && $dto->interval !== $currentInterval;
        $prorataFactor = 0.0;
        $prorataChargeNet = 0;
        $prorataCreditNet = 0;
        $prorataRemainingDays = 0;
        $prorataTotalDays = 0;

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();

        if (
            ($planChanged || $intervalChanged)
            && $periodStart !== null
            && $periodEnd !== null
        ) {
            $prorata = BillingPolicy::computeProrata($currentNet, $newNet, $intervalChanged, $periodStart, $periodEnd);
            $prorataFactor = $prorata['factor'];
            $prorataChargeNet = $prorata['charge_net'];
            $prorataCreditNet = $prorata['credit_net'];

            $days = BillingPolicy::prorataPeriodDays($periodStart, $periodEnd);
            $prorataRemainingDays = $days['remaining'];
            $prorataTotalDays = $days['total'];
        }

        // VAT on recurring price.
        // Display-only: trust that a stored vat_number means reverse-charge.
        // The actual VIES check happens at checkout / billing-data save — by the
        // time we render the plan-change preview, that decision is already made.
        $country = $billable->getBillingCountry() ?? 'DE';
        $vatNumber = $billable instanceof \Illuminate\Database\Eloquent\Model ? ($billable->vat_number ?? null) : null;
        $netForRecurring = max(0, $newNet - $couponDiscountNet);

        if (filled($vatNumber)) {
            $vat = ['net' => $netForRecurring, 'vat' => 0, 'gross' => $netForRecurring, 'rate' => 0.0];
        } else {
            try {
                $vat = $this->vatService->calculate($country, $netForRecurring, null);
            } catch (\Throwable $e) {
                $vat = ['net' => $netForRecurring, 'vat' => 0, 'gross' => $netForRecurring, 'rate' => 0.0];
                $warnings[] = 'VAT calculation unavailable: '.$e->getMessage();
            }
        }

        // VAT on prorata amount (due now). Always derived from the invoice that
        // paid for the current period — see UpdateSubscription::prorataVat().
        // Falls back to the live billable VAT only when no period invoice exists
        // yet (e.g. local→Mollie upgrade where no payment has been made).
        $prorataVat = ['net' => 0, 'vat' => 0, 'gross' => 0, 'rate' => $vat['rate']];
        $prorataNet = $prorataChargeNet > 0 ? $prorataChargeNet : ($prorataCreditNet > 0 ? $prorataCreditNet : 0);
        if ($prorataNet > 0) {
            // VAT-Rate aus dem Plan-Line-Item der laufenden Periode (Per-Item-VAT).
            $candidates = BillingInvoice::currentPeriodLines($billable, 'plan', $currentPlan);
            if (! empty($candidates)) {
                $first = $candidates[0];
                $line = $first['invoice']->lineItem($first['line_index']) ?? [];
                $rate = (float) ($line['vat_rate'] ?? 0);
                $vatAmount = (int) round($prorataNet * $rate / 100);
                $prorataVat = [
                    'net' => $prorataNet,
                    'vat' => $vatAmount,
                    'gross' => $prorataNet + $vatAmount,
                    'rate' => $rate,
                ];
            } else {
                try {
                    $prorataVat = $this->vatService->calculate($country, $prorataNet, $vatNumber);
                } catch (\Throwable) {
                    $prorataVat = ['net' => $prorataNet, 'vat' => 0, 'gross' => $prorataNet, 'rate' => 0.0];
                }
            }
        }

        // Usage comparison with prorated excess calculation
        $usageChanges = [];
        $usageOverageChargeNet = 0;
        $rollover = $currentPlan !== '' ? $this->catalog->usageRollover($currentPlan) : false;

        foreach ($this->catalog->allUsageTypes() as $usageType) {
            $currentQuota = $this->catalog->includedUsage($currentPlan, $currentInterval, $usageType);
            $newQuota = $this->catalog->includedUsage($newPlan, $newInterval, $usageType);
            if ($currentQuota <= 0 && $newQuota <= 0) {
                continue;
            }

            $wallet = $billable instanceof \Illuminate\Database\Eloquent\Model
                ? $billable->getWallet($usageType)
                : null;
            $walletBalance = (int) ($wallet?->balanceInt ?? 0);

            // Separate purchased credits from plan credits.
            $purchasedBalance = $wallet !== null ? WalletUsageService::getPurchasedBalance($wallet) : 0;
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $walletBalance);
            $planOnlyBalance = $walletBalance - $purchasedRemaining;

            $excess = 0;
            $proratedOldQuota = 0;
            $actuallyUsed = max(0, $currentQuota - $planOnlyBalance);

            if ($periodStart !== null && $periodEnd !== null && $currentQuota > 0 && $planChanged) {
                $usageProrata = BillingPolicy::computeUsageOverageForPlanChange(
                    $currentQuota,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $usageProrata['excess'];
                $proratedOldQuota = $usageProrata['prorated_old_quota'];
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $currentQuota) : 0;
            $effectiveNewQuota = max(0, $newQuota + $rolloverCredits + $purchasedRemaining - $excess);
            $unresolvedOverage = max(0, $excess - $newQuota - $rolloverCredits - $purchasedRemaining);

            $overagePrice = (int) ($this->catalog->usageOveragePrice($currentPlan, $currentInterval, $usageType) ?? 0);
            $overageTotalNet = $unresolvedOverage * $overagePrice;
            $usageOverageChargeNet += $overageTotalNet;

            $usageChanges[$usageType] = [
                'current' => $currentQuota,
                'new' => $newQuota,
                'diff' => $newQuota - $currentQuota,
                'actually_used' => $actuallyUsed,
                'prorated_old_quota' => $proratedOldQuota,
                'excess' => $excess,
                'rollover_credits' => $rolloverCredits,
                'purchased_remaining' => $purchasedRemaining,
                'offset_by_new_plan' => min($excess, $newQuota + $rolloverCredits + $purchasedRemaining),
                'effective_new_quota' => $effectiveNewQuota,
                'unresolved_overage' => $unresolvedOverage,
                'overage_unit_price_net' => $overagePrice,
                'overage_total_net' => $overageTotalNet,
            ];
        }

        // VAT on usage overage
        $usageOverageChargeGross = 0;
        if ($usageOverageChargeNet > 0) {
            try {
                $usageOverageChargeGross = (int) $this->vatService->calculate($country, $usageOverageChargeNet, $vatNumber)['gross'];
            } catch (\Throwable) {
                $usageOverageChargeGross = $usageOverageChargeNet;
            }
        }

        // Seat comparison
        $currentIncludedSeats = $currentPlan !== '' ? $this->catalog->includedSeats($currentPlan) : 0;
        $extraSeatsCharged = max(0, $newSeats - $newIncludedSeats);

        return [
            'currentPlanCode' => $currentPlan,
            'currentPlanName' => $currentPlan !== '' ? ($this->catalog->planName($currentPlan) ?? $currentPlan) : null,
            'currentInterval' => $currentInterval,
            'newPlanCode' => $newPlan,
            'newPlanName' => $newPlan !== '' ? ($this->catalog->planName($newPlan) ?? $newPlan) : null,
            'newInterval' => $newInterval,
            'planChanged' => $planChanged,
            'intervalChanged' => $intervalChanged,
            'usedSeats' => $usedSeats,
            'currentSeats' => $currentSeats,
            'newSeats' => $newSeats,
            'currentIncludedSeats' => $currentIncludedSeats,
            'newIncludedSeats' => $newIncludedSeats,
            'extraSeatsCharged' => $extraSeatsCharged,
            'seatPriceNet' => $seatPriceNet,
            'incompatibleAddons' => $incompatibleAddons,
            'usageChanges' => $usageChanges,
            'currentPriceNet' => $currentNet,
            'newPriceNet' => $newNet,
            'diffNet' => $newNet - $currentNet,
            'prorataFactor' => $prorataFactor,
            'prorataRemainingDays' => $prorataRemainingDays,
            'prorataTotalDays' => $prorataTotalDays,
            'prorataChargeNet' => $prorataChargeNet,
            'prorataChargeGross' => $prorataChargeNet > 0 ? (int) $prorataVat['gross'] : 0,
            'prorataChargeVat' => $prorataChargeNet > 0 ? (int) $prorataVat['vat'] : 0,
            'prorataCreditNet' => $prorataCreditNet,
            'prorataCreditGross' => $prorataCreditNet > 0 ? (int) $prorataVat['gross'] : 0,
            'currentPeriodCredit' => $periodStart !== null && $periodEnd !== null
                ? (int) round($currentNet * $prorataFactor)
                : 0,
            'usageOverageChargeNet' => $usageOverageChargeNet,
            'usageOverageChargeGross' => $usageOverageChargeGross,
            'couponDiscountNet' => $couponDiscountNet,
            'vatRate' => $vat['rate'],
            'vatAmount' => $vat['vat'],
            'grossTotal' => $vat['gross'],
            'reverseCharge' => abs((float) $vat['rate']) < 0.001 && filled($vatNumber),
            'lineItems' => $lineItems,
            'warnings' => $warnings,
            'errors' => $errors,
            'appliesAt' => $dto->applyAt === 'end_of_period'
                ? ($periodEnd ?? 'immediate')
                : 'immediate',
            'planChangeMode' => config('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice)->value,
            'isUpgrade' => BillingPolicy::isUpgrade(
                $this->catalog, $currentPlan, $currentInterval, $newPlan, $newInterval,
            ),
            'isDowngrade' => BillingPolicy::isDowngrade(
                $this->catalog, $currentPlan, $currentInterval, $newPlan, $newInterval,
            ),
            // Multi-VAT-Aufschlüsselung via Composer (UI-Source-of-Truth für die neue Preview).
            // Aggregat-Felder oben (prorataChargeNet etc.) bleiben für Backwards-Compat.
            ...$this->prorataLinesPreview($billable, $currentPlan, $newPlan, $currentInterval, $newInterval, $currentSeats, $newSeats, $currentAddons, $newAddons),
        ];
    }

    /**
     * Baut die prorataLines via ProrataComposer für die neue UI-Aufschlüsselung.
     *
     * @param  array<string, int>  $currentAddons
     * @param  array<string, int>  $newAddons
     * @return array{prorataLines: list<array<string, mixed>>, prorataTotalNet: int, prorataTotalVat: int, prorataTotalGross: int}
     */
    private function prorataLinesPreview(
        Billable $billable,
        string $currentPlan,
        string $newPlan,
        string $currentInterval,
        string $newInterval,
        int $currentSeats,
        int $newSeats,
        array $currentAddons,
        array $newAddons,
    ): array {
        if ($currentPlan === '' || $newPlan === '') {
            return ['prorataLines' => [], 'prorataTotalNet' => 0, 'prorataTotalVat' => 0, 'prorataTotalGross' => 0];
        }

        try {
            $intent = new PlanChangeIntent(
                billable: $billable,
                currentPlan: $currentPlan,
                newPlan: $newPlan,
                currentInterval: $currentInterval,
                newInterval: $newInterval,
                currentSeats: $currentSeats,
                newSeats: $newSeats,
                currentAddons: $currentAddons,
                newAddons: $newAddons,
            );

            $lines = app(ProrataComposer::class)->compose($intent);
        } catch (\Throwable) {
            // Bei Daten-Inkonsistenz (z.B. fehlende Original-Lines) leer zurückgeben.
            // Composer wirft RuntimeException — Preview soll nicht crashen.
            return ['prorataLines' => [], 'prorataTotalNet' => 0, 'prorataTotalVat' => 0, 'prorataTotalGross' => 0];
        }

        $totalNet = 0;
        $totalVat = 0;
        $totalGross = 0;
        $serialized = [];

        foreach ($lines as $line) {
            $serialized[] = $line->toArray();
            $totalNet += $line->amountNet;
            $totalVat += $line->amountVat;
            $totalGross += $line->amountGross;
        }

        return [
            'prorataLines' => $serialized,
            'prorataTotalNet' => $totalNet,
            'prorataTotalVat' => $totalVat,
            'prorataTotalGross' => $totalGross,
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
                'label' => $this->catalog->addonName((string) $addonCode) ?? (string) $addonCode,
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

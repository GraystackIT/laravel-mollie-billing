<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;

/**
 * Pure Berechnung der Pro-rata-Lines für einen Plan-Change-Intent.
 *
 * Liefert eine gemischte Liste aus Charge- und Refund-Zeilen.
 * Charges vor Refunds, innerhalb gleicher Direction: plan, seats, addons.
 *
 * Free-Plan-Origin (currentPlan = Free): leere Liste — Free→Mollie läuft über UpgradeLocalToMollie.
 * Mollie→Free: nur Refunds, kein Charge.
 * Mollie→Mollie: Plan-Wechsel = Refund alter + Charge neuer Plan, getrennt.
 */
class ProrataComposer
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {}

    /**
     * @return list<ProrataLine>
     */
    public function compose(PlanChangeIntent $intent): array
    {
        $billable = $intent->billable;

        // Free-Origin: gar nichts (Free→Mollie läuft über UpgradeLocalToMollie).
        if ($this->catalog->isFreePlan($intent->currentPlan, $intent->currentInterval)) {
            return [];
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();
        if ($periodStart === null || $periodEnd === null) {
            return [];
        }

        $charges = [];
        $refunds = [];

        // Plan-Wechsel
        $planChanged = $intent->currentPlan !== $intent->newPlan;
        $intervalChanged = $intent->currentInterval !== $intent->newInterval;

        if ($planChanged || $intervalChanged) {
            // Plan or interval change → close out the entire current period
            // (plan + currently-active extra seats + currently-active addons
            // get a pro-rata refund) and open a fresh window with the new
            // plan's catalog prices. This avoids "old seats survive plan
            // change at old price/VAT" drift.
            //
            // Quantity is bounded by the currently-active state (seat_count
            // and currentAddons), NOT by the sum of remaining_quantity across
            // historical invoices — those can include stale leftovers from
            // mid-cycle adjustments that no longer reflect what the user
            // actually has access to today.
            $refundLine = $this->planRefundLine($intent, $periodStart, $periodEnd);
            if ($refundLine !== null) {
                $refunds[] = $refundLine;
            }

            $currentExtraSeats = max(0, $intent->currentSeats - $this->catalog->includedSeats($intent->currentPlan));
            if ($currentExtraSeats > 0) {
                $refunds = array_merge($refunds, $this->seatsRefundLines($billable, $currentExtraSeats));
            }

            foreach ($intent->currentAddons as $code => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $refunds = array_merge($refunds, $this->addonRefundLines($billable, (string) $code, $qty));
            }

            if (! $this->catalog->isFreePlan($intent->newPlan, $intent->newInterval)) {
                $chargeLine = $this->planChargeLine($intent, $periodStart, $periodEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }

                $newExtraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
                if ($newExtraSeats > 0) {
                    $chargeLine = $this->seatsChargeLine($intent, $newExtraSeats, $periodStart, $periodEnd);
                    if ($chargeLine !== null) {
                        $charges[] = $chargeLine;
                    }
                }

                foreach ($intent->newAddons as $code => $qty) {
                    $qty = (int) $qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $chargeLine = $this->addonChargeLine($intent, (string) $code, $qty, $periodStart, $periodEnd);
                    if ($chargeLine !== null) {
                        $charges[] = $chargeLine;
                    }
                }
            }

            return array_merge($charges, $refunds);
        }

        // Mid-cycle change (same plan, same interval) — only diff what's actually
        // different. Existing seats and addons stay at the price they were bought.

        // Sitz-Änderung — wir betrachten nur EXTRA-Sitze (über includedSeats hinaus).
        // Inkludierte Sitze sind im Plan-Preis enthalten und werden bereits über die
        // Plan-Refund/Charge-Line abgerechnet — sie dürfen nicht doppelt erscheinen.
        $currentExtraSeats = max(0, $intent->currentSeats - $this->catalog->includedSeats($intent->currentPlan));
        $newExtraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        $seatDiff = $newExtraSeats - $currentExtraSeats;

        if ($seatDiff < 0) {
            $refunds = array_merge($refunds, $this->seatsRefundLines($billable, abs($seatDiff)));
        } elseif ($seatDiff > 0) {
            $chargeLine = $this->seatsChargeLine($intent, $seatDiff, $periodStart, $periodEnd);
            if ($chargeLine !== null) {
                $charges[] = $chargeLine;
            }
        }

        // Addon-Änderungen
        $currentAddons = $intent->currentAddons;
        $newAddons = $intent->newAddons;
        $allCodes = array_unique(array_merge(array_keys($currentAddons), array_keys($newAddons)));

        foreach ($allCodes as $code) {
            $currentQty = (int) ($currentAddons[$code] ?? 0);
            $newQty = (int) ($newAddons[$code] ?? 0);
            $diff = $newQty - $currentQty;

            if ($diff < 0) {
                $refunds = array_merge($refunds, $this->addonRefundLines($billable, $code, abs($diff)));
            } elseif ($diff > 0) {
                $chargeLine = $this->addonChargeLine($intent, $code, $diff, $periodStart, $periodEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }
            }
        }

        return array_merge($charges, $refunds);
    }


    private function planRefundLine(PlanChangeIntent $intent, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $candidates = BillingInvoice::currentPeriodLines($intent->billable, 'plan', $intent->currentPlan);
        if (empty($candidates)) {
            // Kein Original — nur erlaubt wenn currentPlan war Free (oben schon abgefangen).
            // Sonst Daten-Inkonsistenz.
            throw new \RuntimeException("Kein Original-Plan-Line-Item für {$intent->currentPlan} in laufender Periode.");
        }

        $first = $candidates[0];
        /** @var BillingInvoice $invoice */
        $invoice = $first['invoice'];
        $idx = $first['line_index'];
        $line = $invoice->lineItem($idx);
        if ($line === null) {
            return null;
        }

        [$lineStart, $lineEnd] = $this->resolveLinePeriod($line, $invoice, $periodStart, $periodEnd);

        $cashNet = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);
        $vatRate = (float) ($line['vat_rate'] ?? 0);

        ['days' => $days, 'factor' => $factor] = $this->periodMath($lineStart, $lineEnd);

        $planName = $this->catalog->planName($intent->currentPlan) ?? $intent->currentPlan;
        $label = __('billing::portal.prorata_label_plan_refund', ['plan' => $planName]);

        $isCouponCovered = $cashNet === 0;
        if ($isCouponCovered) {
            return new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: $idx,
                kind: 'plan',
                code: $intent->currentPlan,
                label: $label,
                quantity: 1,
                amountNet: 0,
                vatRate: $vatRate,
                amountVat: 0,
                amountGross: 0,
                periodStart: $lineStart,
                periodEnd: $lineEnd,
                daysActive: $days['total'],
                daysRemaining: $days['remaining'],
                isCouponCovered: true,
                direction: 'refund',
            );
        }

        $refundNet = (int) round($cashNet * $factor);
        $refundVat = (int) round($refundNet * $vatRate / 100);
        $refundGross = $refundNet + $refundVat;

        return new ProrataLine(
            originalInvoice: $invoice,
            originalLineItemIndex: $idx,
            kind: 'plan',
            code: $intent->currentPlan,
            label: $label,
            quantity: 1,
            amountNet: -$refundNet,
            vatRate: $vatRate,
            amountVat: -$refundVat,
            amountGross: -$refundGross,
            periodStart: $lineStart,
            periodEnd: $lineEnd,
            daysActive: $days['total'],
            daysRemaining: $days['remaining'],
            isCouponCovered: false,
            direction: 'refund',
        );
    }

    /**
     * Resolve the period for a line item (line → invoice → fallback period).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function resolveLinePeriod(array $line, BillingInvoice $invoice, CarbonInterface $fallbackStart, CarbonInterface $fallbackEnd): array
    {
        $startRaw = $line['period_start'] ?? $invoice->period_start ?? null;
        $endRaw = $line['period_end'] ?? $invoice->period_end ?? null;

        // Period values may arrive as a CarbonImmutable (UtcDatetime cast),
        // a DateTimeInterface, or an ISO string from a JSON line_items column.
        // Branching on type avoids a (string)-cast that would drop the offset
        // and let app.timezone reinterpret an already-UTC value.
        $start = $startRaw !== null ? BillingTime::toUtc($startRaw) : $fallbackStart->copy();
        $end = $endRaw !== null ? BillingTime::toUtc($endRaw) : $fallbackEnd->copy();

        return [$start, $end];
    }

    private function planChargeLine(PlanChangeIntent $intent, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $listPrice = $this->catalog->basePriceNet($intent->newPlan, $intent->newInterval);
        if ($listPrice <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($listPrice * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'plan',
            code: $intent->newPlan,
            label: __('billing::portal.prorata_label_plan_charge', ['plan' => $planName]),
            quantity: 1,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            periodStart: $periodStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $days['total'],
            daysRemaining: $days['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * Single source of truth for pro-rata day math.
     *
     * @return array{days: array{total: int, remaining: int}, factor: float}
     */
    private function periodMath(CarbonInterface $start, CarbonInterface $end): array
    {
        $days = BillingPolicy::prorataPeriodDays($start, $end);
        $factor = $days['total'] > 0 ? round($days['remaining'] / $days['total'], 6) : 0.0;

        return ['days' => $days, 'factor' => $factor];
    }

    /**
     * @return list<ProrataLine>
     */
    private function seatsRefundLines(Billable $billable, int $reduction): array
    {
        if ($reduction <= 0) {
            return [];
        }

        $candidates = BillingInvoice::currentPeriodLines($billable, 'seats', null);
        if (empty($candidates)) {
            return []; // keine Original-Sitz-Lines (z.B. Plan ohne Extra-Sitze)
        }

        $remaining = $reduction;
        $lines = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            /** @var BillingInvoice $invoice */
            $invoice = $candidate['invoice'];
            $idx = $candidate['line_index'];
            $availableQty = (int) $candidate['remaining_quantity'];
            $takeQty = min($remaining, $availableQty);
            $remaining -= $takeQty;

            $lines[] = $this->buildRefundLine($invoice, $idx, 'seats', null, $takeQty);
        }

        return $lines;
    }

    private function seatsChargeLine(PlanChangeIntent $intent, int $extraSeats, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $seatPrice = $this->catalog->seatPriceNet($intent->newPlan, $intent->newInterval);
        if ($seatPrice === null || $seatPrice <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($seatPrice * $extraSeats * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'seats',
            code: null,
            label: trans_choice('billing::portal.prorata_label_seats_charge', $extraSeats, ['count' => $extraSeats, 'plan' => $planName]),
            quantity: $extraSeats,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            periodStart: $periodStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $days['total'],
            daysRemaining: $days['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * @return list<ProrataLine>
     */
    private function addonRefundLines(Billable $billable, string $code, int $reduction): array
    {
        if ($reduction <= 0) {
            return [];
        }

        $candidates = BillingInvoice::currentPeriodLines($billable, 'addon', $code);
        if (empty($candidates)) {
            return [];
        }

        $remaining = $reduction;
        $lines = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            /** @var BillingInvoice $invoice */
            $invoice = $candidate['invoice'];
            $idx = $candidate['line_index'];
            $availableQty = (int) $candidate['remaining_quantity'];
            $takeQty = min($remaining, $availableQty);
            $remaining -= $takeQty;

            $lines[] = $this->buildRefundLine($invoice, $idx, 'addon', $code, $takeQty);
        }

        return $lines;
    }

    private function addonChargeLine(PlanChangeIntent $intent, string $code, int $quantity, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $price = $this->catalog->addonPriceNet($code, $intent->newInterval);
        if ($price <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($price * $quantity * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $addonName = $this->catalog->addonName($code) ?? $code;
        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'addon',
            code: $code,
            label: __('billing::portal.prorata_label_addon_charge', ['addon' => $addonName, 'plan' => $planName]),
            quantity: $quantity,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            periodStart: $periodStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $days['total'],
            daysRemaining: $days['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * Baut eine Refund-Zeile für eine bestimmte Original-Line mit gegebener Mengen-Reduktion.
     */
    private function buildRefundLine(BillingInvoice $invoice, int $idx, string $kind, ?string $code, int $quantity): ProrataLine
    {
        $line = $invoice->lineItem($idx);
        if ($line === null) {
            throw new \RuntimeException("Original-Line-Item $idx nicht gefunden in Invoice {$invoice->id}.");
        }

        $originalQty = max(1, (int) ($line['quantity'] ?? 1));
        $originalCashNet = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);
        $vatRate = (float) ($line['vat_rate'] ?? 0);

        // Periode auflösen mit Fallback auf Billable-Period (für Legacy-Invoices ohne line/invoice period).
        /** @var Billable&\Illuminate\Database\Eloquent\Model $billable */
        $billable = $invoice->billable()->first();
        $billableStart = $billable?->getBillingPeriodStartsAt() ?? BillingTime::nowUtc();
        $billableEnd = $billable?->nextBillingDate() ?? BillingTime::nowUtc()->addMonth();
        [$lineStart, $lineEnd] = $this->resolveLinePeriod($line, $invoice, $billableStart, $billableEnd);

        // Pro-rata-Faktor auf Line-Item-Periode.
        $perItemNet = (int) round($originalCashNet / $originalQty);
        ['days' => $days, 'factor' => $factor] = $this->periodMath($lineStart, $lineEnd);
        $itemDaysActive = $days['total'];
        $itemDaysRemaining = $days['remaining'];

        $label = $this->buildRefundLabel($invoice, $kind, $code, $quantity);

        $isCouponCovered = $originalCashNet === 0;

        if ($isCouponCovered) {
            return new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: $idx,
                kind: $kind,
                code: $code,
                label: $label,
                quantity: $quantity,
                amountNet: 0,
                vatRate: $vatRate,
                amountVat: 0,
                amountGross: 0,
                periodStart: $lineStart,
                periodEnd: $lineEnd,
                daysActive: $itemDaysActive,
                daysRemaining: $itemDaysRemaining,
                isCouponCovered: true,
                direction: 'refund',
            );
        }

        $refundNet = (int) round($perItemNet * $quantity * $factor);
        $refundVat = (int) round($refundNet * $vatRate / 100);
        $refundGross = $refundNet + $refundVat;

        return new ProrataLine(
            originalInvoice: $invoice,
            originalLineItemIndex: $idx,
            kind: $kind,
            code: $code,
            label: $label,
            quantity: $quantity,
            amountNet: -$refundNet,
            vatRate: $vatRate,
            amountVat: -$refundVat,
            amountGross: -$refundGross,
            periodStart: $lineStart,
            periodEnd: $lineEnd,
            daysActive: $itemDaysActive,
            daysRemaining: $itemDaysRemaining,
            isCouponCovered: false,
            direction: 'refund',
        );
    }

    /**
     * Baut ein konsistentes Label für eine Refund-Zeile, mit Plan-Kontext aus der Original-Invoice.
     *
     * Der Plan-Code wird aus dem ersten plan-Line-Item der Original-Invoice gelesen — falls keiner
     * existiert (z.B. Mid-cycle Sitz-Increase-Invoice ohne plan-Line), fallback auf den aktuellen
     * Plan des Billable.
     */
    private function buildRefundLabel(BillingInvoice $invoice, string $kind, ?string $code, int $quantity): string
    {
        $planCode = $this->resolveOriginalPlanCode($invoice);
        $planName = $planCode !== null
            ? ($this->catalog->planName($planCode) ?? $planCode)
            : ($invoice->billable()->first()?->getCurrentBillingPlanName() ?? '');

        return match ($kind) {
            'seats' => trans_choice('billing::portal.prorata_label_seats_refund', $quantity, ['count' => $quantity, 'plan' => $planName]),
            'addon' => __('billing::portal.prorata_label_addon_refund', [
                'addon' => $code !== null ? ($this->catalog->addonName($code) ?? $code) : '',
                'plan' => $planName,
            ]),
            default => $planName,
        };
    }

    /**
     * Liest den Plan-Code aus dem ersten plan-Line-Item der Invoice. Mid-cycle-Charge-Invoices
     * (z.B. nur Sitz-Erhöhung) tragen oft keinen plan-Line — dann return null.
     */
    private function resolveOriginalPlanCode(BillingInvoice $invoice): ?string
    {
        foreach ((array) ($invoice->line_items ?? []) as $line) {
            if (($line['kind'] ?? null) === 'plan' && ! empty($line['code'])) {
                return (string) $line['code'];
            }
        }

        return null;
    }

    /**
     * Live-VAT-Berechnung aus Billable-Status.
     *
     * @return array{rate: float, amount: int}
     */
    private function liveVat(Billable $billable, int $netAmount): array
    {
        $country = (string) ($billable->getBillingCountry() ?? 'DE');

        $vat = $this->vatService->calculate($country, $netAmount, $billable);

        return [
            'rate' => (float) $vat['rate'],
            'amount' => (int) $vat['vat'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Zwei-Phasen-Orchestrator für Plan-Change-Pro-rata.
 *
 * Phase 1 (synchron, hier):
 * - Sidegrade-0 → lokale Plan-Switch-Invoice + Mollie-Subscription-PATCH (kein Mollie-Charge).
 * - Mit Charge-Lines → Mollie-CreatePaymentRequest + Pending-State (PATCH wartet auf Phase 2).
 * - Nur Refund-Lines → Mollie-Subscription-PATCH + sofortige Refunds.
 *
 * Phase 2 (asynchron, vom Webhook getriggert) lebt im MollieWebhookController.
 */
class ProrataExecutor
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly MollieSubscriptionPatcher $subscriptionPatcher,
    ) {}

    /**
     * @param  list<ProrataLine>  $lines  vom ProrataComposer
     */
    public function execute(Billable $billable, PlanChangeIntent $intent, array $lines): void
    {
        // Idempotenz: bereits laufender Plan-Change → No-Op.
        if ($this->hasPendingProrataChange($billable)) {
            return;
        }

        [$chargeLines, $refundLines] = $this->partition($lines);

        // Coupon-covered Lines werden in den Service-Methoden gefiltert; hier nur leere Listen prüfen.
        if (empty($chargeLines) && empty($refundLines)) {
            return;
        }

        // Sidegrade: echter Plan-Wechsel + Charge-Summe == |Refund-Summe|.
        if ($this->isSaldoZeroPlanSwitch($intent, $chargeLines, $refundLines)) {
            $this->invoices->createPlanSwitchInvoice($billable, $chargeLines, $refundLines);
            $this->safelyPatch($billable, $intent);
            return;
        }

        if (! empty($chargeLines)) {
            // Phase 1: Mollie-Charge + Pending-State (PATCH läuft erst in Phase 2 nach Charge-OK).
            $this->invoices->createCharge($billable, $chargeLines, $refundLines, $intent);
            return;
        }

        // Reine Refund-Phase: PATCH (best-effort) + Refunds direkt.
        // Ein PATCH-Fail darf den Refund nicht blockieren — er wird in pending_subscription_patch
        // persistiert und vom RetrySubscriptionPatchJob aufgegriffen.
        $this->safelyPatch($billable, $intent);
        $this->invoices->createRefund($billable, $refundLines);
    }

    /**
     * Runs the Mollie-Subscription PATCH and falls back to a pending-state marker
     * (for the Retry-Job) if it fails. Never throws — the refund/sidegrade flow
     * downstream must always complete.
     */
    private function safelyPatch(Billable $billable, PlanChangeIntent $intent): void
    {
        try {
            $this->subscriptionPatcher->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            Log::warning('Mollie-Subscription PATCH failed during pro-rata flow — queued for retry', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_subscription_patch'] = [
                    'intent' => $intent->toArray(),
                    'first_attempt_at' => now()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }
    }

    /**
     * @param  list<ProrataLine>  $lines
     * @return array{0: list<ProrataLine>, 1: list<ProrataLine>}
     */
    private function partition(array $lines): array
    {
        $charges = [];
        $refunds = [];
        foreach ($lines as $line) {
            if ($line->direction === 'charge') {
                $charges[] = $line;
            } else {
                $refunds[] = $line;
            }
        }
        return [$charges, $refunds];
    }

    /**
     * Sidegrade = echter Plan-Wechsel mit Saldo 0 (Charge-Summe == |Refund-Summe|).
     *
     * @param  list<ProrataLine>  $chargeLines
     * @param  list<ProrataLine>  $refundLines
     */
    private function isSaldoZeroPlanSwitch(PlanChangeIntent $intent, array $chargeLines, array $refundLines): bool
    {
        $planChanged = $intent->currentPlan !== $intent->newPlan || $intent->currentInterval !== $intent->newInterval;
        if (! $planChanged) {
            return false;
        }

        $chargeGross = array_sum(array_map(fn (ProrataLine $l) => $l->amountGross, $chargeLines));
        $refundGross = array_sum(array_map(fn (ProrataLine $l) => $l->amountGross, $refundLines));

        // Charge ist positiv, Refund negativ → Summe = 0 bedeutet Saldo 0.
        return ($chargeGross + $refundGross) === 0 && $chargeGross > 0;
    }

    private function hasPendingProrataChange(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();
        return ! empty($meta['pending_prorata_change']);
    }
}

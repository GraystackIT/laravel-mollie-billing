<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Two-phase orchestrator for plan-change pro-rata.
 *
 * Phase 1 (synchronous, here):
 * - Sidegrade-0 → local plan-switch invoice + Mollie subscription PATCH (no Mollie charge).
 * - With charge lines → Mollie CreatePaymentRequest + pending-state (PATCH waits for phase 2).
 * - Refund lines only → Mollie subscription PATCH + immediate refunds.
 *
 * Phase 2 (asynchronous, triggered by the webhook) lives in MollieWebhookController.
 */
class ProrataExecutor
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly MollieSubscriptionPatcher $subscriptionPatcher,
    ) {}

    /**
     * @param  list<ProrataLine>  $lines  from the ProrataComposer
     * @return array{path: string, invoice: ?\GraystackIT\MollieBilling\Models\BillingInvoice}
     *         path ∈ {'noop', 'sidegrade', 'deferred_charge', 'refund'}.
     *         invoice is filled for sidegrade and refund (immediate redemption with that invoice id).
     *         For deferred_charge the invoice is null — redemption happens in
     *         UpdateSubscription::applyPendingPlanChange() once the Mollie charge clears.
     */
    public function execute(Billable $billable, PlanChangeIntent $intent, array $lines): array
    {
        // Idempotency: plan change already in flight → no-op.
        if ($this->hasPendingProrataChange($billable)) {
            return ['path' => 'noop', 'invoice' => null];
        }

        [$chargeLines, $refundLines] = $this->partition($lines);

        // Coupon-covered lines are filtered in the service methods; here we only check for empty lists.
        if (empty($chargeLines) && empty($refundLines)) {
            return ['path' => 'noop', 'invoice' => null];
        }

        // Sidegrade: real plan switch + charge total == |refund total|.
        if ($this->isSaldoZeroPlanSwitch($intent, $chargeLines, $refundLines)) {
            $invoice = $this->invoices->createPlanSwitchInvoice($billable, $chargeLines, $refundLines);
            $this->safelyPatch($billable, $intent);
            return ['path' => 'sidegrade', 'invoice' => $invoice];
        }

        if (! empty($chargeLines)) {
            // Phase 1: Mollie charge + pending state (PATCH only runs in phase 2 after charge OK).
            $this->invoices->createCharge($billable, $chargeLines, $refundLines, $intent);
            return ['path' => 'deferred_charge', 'invoice' => null];
        }

        // Pure refund phase: PATCH (best-effort) + refunds directly.
        // A PATCH failure must not block the refund — it is persisted to pending_subscription_patch
        // and picked up by the RetrySubscriptionPatchJob.
        $this->safelyPatch($billable, $intent);
        $refundInvoice = $this->invoices->createRefund($billable, $refundLines);
        return ['path' => 'refund', 'invoice' => $refundInvoice];
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
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
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

        // Charge is positive, refund is negative — sum == 0 means net balance is zero.
        return ($chargeGross + $refundGross) === 0 && $chargeGross > 0;
    }

    private function hasPendingProrataChange(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();
        return ! empty($meta['pending_prorata_change']);
    }
}

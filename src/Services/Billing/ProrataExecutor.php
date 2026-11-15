<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
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
     * @return array{path: string, invoice: ?\GraystackIT\MollieBilling\Models\BillingInvoice, cancelled_at_mollie?: bool}
     *         `cancelled_at_mollie` is present on the two paths that patch Mollie themselves
     *         and is false when the PATCH (or, for a free target, the cancel) did not land —
     *         the caller must then keep `mollie_subscription_id`, or a subscription that is
     *         still live at Mollie becomes untargetable and keeps charging.
     *         path ∈ {'noop', 'in_flight', 'sidegrade', 'deferred_charge', 'refund'}.
     *         invoice is filled for sidegrade and refund (immediate redemption with that invoice id).
     *         For deferred_charge the invoice is null — redemption happens in
     *         UpdateSubscription::applyPendingPlanChange() once the Mollie charge clears.
     *
     *         'noop' and 'in_flight' both mean "no money moved here", but they are not
     *         interchangeable to the caller: 'noop' means Mollie was not touched at all and
     *         still has to be patched, whereas 'in_flight' means another change is already
     *         waiting on a Mollie charge and must not be patched over.
     */
    public function execute(Billable $billable, PlanChangeIntent $intent, array $lines): array
    {
        // Idempotency: plan change already in flight → do nothing.
        if ($this->hasPendingProrataChange($billable)) {
            return ['path' => 'in_flight', 'invoice' => null];
        }

        [$chargeLines, $refundLines] = $this->partition($lines);

        // Coupon-covered lines are filtered in the service methods; here we only check for empty lists.
        if (empty($chargeLines) && empty($refundLines)) {
            return ['path' => 'noop', 'invoice' => null];
        }

        // Sidegrade: real plan switch + charge total == |refund total|.
        if ($this->isSaldoZeroPlanSwitch($intent, $chargeLines, $refundLines)) {
            $invoice = $this->invoices->createPlanSwitchInvoice($billable, $chargeLines, $refundLines);
            $cancelledAtMollie = $this->safelyPatch($billable, $intent);
            return ['path' => 'sidegrade', 'invoice' => $invoice, 'cancelled_at_mollie' => $cancelledAtMollie];
        }

        // Charge lines that add up to nothing are not a charge. `createCharge()` creates no
        // payment for them — and with no payment there is no webhook, so the refund lines
        // handed to it as "pending until the charge clears" were never paid out: a
        // downgrade whose (small) new price a coupon covered in full lost the customer
        // the whole credit for the plan they left. Treated as what it is: a change with
        // credits only, or with nothing to book at all.
        $chargeGross = array_sum(array_map(fn (ProrataLine $l) => $l->amountGross, $chargeLines));

        if (! empty($chargeLines) && $chargeGross <= 0) {
            if (empty($refundLines)) {
                return ['path' => 'noop', 'invoice' => null];
            }

            $chargeLines = [];
        }

        if (! empty($chargeLines)) {
            // Phase 1: Mollie charge + pending state (PATCH only runs in phase 2 after charge OK).
            $this->invoices->createCharge($billable, $chargeLines, $refundLines, $intent);
            return ['path' => 'deferred_charge', 'invoice' => null];
        }

        // Pure refund phase: PATCH (best-effort) + refunds directly.
        // A PATCH failure must not block the refund — it is persisted to pending_subscription_patch
        // and picked up by the RetrySubscriptionPatchJob.
        $cancelledAtMollie = $this->safelyPatch($billable, $intent);
        $refundInvoice = $this->invoices->createRefund($billable, $refundLines);
        return ['path' => 'refund', 'invoice' => $refundInvoice, 'cancelled_at_mollie' => $cancelledAtMollie];
    }

    /**
     * Runs the Mollie-Subscription PATCH and falls back to a pending-state marker
     * (for the Retry-Job) if it fails. Never throws — the refund/sidegrade flow
     * downstream must always complete.
     */
    private function safelyPatch(Billable $billable, PlanChangeIntent $intent): bool
    {
        try {
            return $this->subscriptionPatcher->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            Log::warning('Mollie-Subscription PATCH failed during pro-rata flow — queued for retry', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            if ($billable instanceof Model) {
                // `billable_type` is normalised to the configured billable FQCN:
                // PlanChangeIntent::fromArray() and RetrySubscriptionPatchJob both resolve
                // the row by class name, which a morph-map alias from getMorphClass()
                // would not satisfy.
                $intentData = [
                    ...$intent->toArray(),
                    'billable_type' => (string) config('mollie-billing.billable_model'),
                    'billable_id' => $billable->getKey(),
                ];
                $firstAttemptAt = BillingTime::nowUtc()->toIso8601String();

                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_subscription_patch'] = [
                    'intent' => $intentData,
                    'first_attempt_at' => $firstAttemptAt,
                    'last_error' => $e->getMessage(),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();

                // Actually queue the retry. The marker alone is only read by the hourly
                // SweepPendingRetriesJob; without a dispatch here Mollie keeps charging
                // the old amount for up to an hour longer than necessary. The job is
                // ShouldBeUnique per (billable, patch), so the sweep finding the same
                // marker later is a no-op while this chain is in flight.
                RetrySubscriptionPatchJob::dispatch($intentData, $firstAttemptAt);
            }
        }

        return false;
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

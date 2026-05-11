<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProrataChargeHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $invoiceService,
        protected readonly MollieSubscriptionPatcher $patcher,
        protected readonly WalletPlanChangeAdjuster $walletAdjuster,
    ) {
    }

    /**
     * Phase-2-Trigger für die neue Plan-Change-Charge-Logik (Multi-VAT-Sammel-Charges).
     *
     * 1. Charge-Invoice via InvoiceService::createInvoice persistieren.
     * 2. Mollie-Subscription-PATCH via MollieSubscriptionPatcher::updateForIntent.
     * 3. Geplante Refunds (aus Pending-State) via InvoiceService::createRefund ausführen.
     * 4. Pending-State löschen.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for prorata charge, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
        if (empty($pending)) {
            return;
        }

        $intentData = is_array($pending['intent'] ?? null) ? $pending['intent'] : null;
        $oldPlan = $intentData !== null ? (string) ($intentData['current_plan'] ?? '') : '';
        $oldInterval = $intentData !== null ? (string) ($intentData['current_interval'] ?? '') : '';
        $newPlanCode = $intentData !== null
            ? (string) ($intentData['new_plan'] ?? $billable->getBillingSubscriptionPlanCode())
            : (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $newIntervalCode = $intentData !== null
            ? (string) ($intentData['new_interval'] ?? $billable->getBillingSubscriptionInterval())
            : (string) ($billable->getBillingSubscriptionInterval() ?? '');
        $startsNewPeriod = $oldPlan !== '' && ($oldPlan !== $newPlanCode || $oldInterval !== $newIntervalCode);

        $paidAt = $payment->paidAt ?? null;
        $newPeriodStart = $paidAt ? Carbon::parse((string) $paidAt)->setTimezone('UTC') : BillingTime::nowUtc();
        $newPeriodEnd = $newIntervalCode === 'yearly'
            ? $newPeriodStart->copy()->addYear()
            : $newPeriodStart->copy()->addMonth();

        $invoicePeriodStart = $startsNewPeriod ? $newPeriodStart : $billable->getBillingPeriodStartsAt();
        $invoicePeriodEnd = $startsNewPeriod ? $newPeriodEnd : $billable->nextBillingDate();

        $lineItems = (array) ($pending['charge_lines'] ?? $metadata['line_items'] ?? []);

        if ($startsNewPeriod && $invoicePeriodStart !== null && $invoicePeriodEnd !== null) {
            $startIso = $invoicePeriodStart->toIso8601String();
            $endIso = $invoicePeriodEnd->toIso8601String();
            $lineItems = array_map(static function (array $line) use ($startIso, $endIso): array {
                $line['period_start'] = $startIso;
                $line['period_end'] = $endIso;
                return $line;
            }, $lineItems);
        }

        try {
            $chargeInvoice = $this->invoiceService->createInvoice(
                billable: $billable,
                kind: InvoiceKind::Subscription,
                molliePaymentId: (string) $payment->id,
                mollieSubscriptionId: $payment->subscriptionId ?? null,
                lineItems: $lineItems,
                periodStart: $invoicePeriodStart,
                periodEnd: $invoicePeriodEnd,
            );
            event(new PaymentSucceeded($billable, $chargeInvoice));
            $this->support->notifyInvoiceAvailable($billable, $chargeInvoice);
        } catch (\Throwable $e) {
            Log::error('Failed to persist prorata_charge invoice', [
                'billable' => $billable->getKey(),
                'payment_id' => (string) $payment->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        try {
            $intent = PlanChangeIntent::fromArray($pending['intent']);
            $this->patcher->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            Log::warning('Mollie subscription PATCH failed in Phase 2 — will be inconsistent until next recurring run', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $refundLinesData = (array) ($pending['refund_lines'] ?? []);
        if (! empty($refundLinesData)) {
            $refundLines = array_map(
                fn (array $data) => ProrataLine::fromArray($data),
                $refundLinesData,
            );
            try {
                $this->invoiceService->createRefund($billable, $refundLines, 'Plan change refund');
            } catch (\Throwable $e) {
                Log::error('Failed to create refund invoice in Phase 2', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $billable->refresh();
        $wasPastDue = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue;
        if ($intentData !== null) {
            $meta = $billable->getBillingSubscriptionMeta();

            $derivedFromCharge = isset($chargeInvoice) ? $chargeInvoice->deriveSeatCount() : null;
            $meta['seat_count'] = $derivedFromCharge ?? (int) ($intentData['new_seats'] ?? $billable->getBillingSeatCount());

            unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);

            if ($wasPastDue) {
                unset($meta['payment_failure'], $meta['past_due_since']);
            }

            $billable->forceFill([
                'subscription_plan_code' => $newPlanCode,
                'subscription_interval' => $newIntervalCode,
                'active_addon_codes' => array_keys((array) ($intentData['new_addons'] ?? [])),
                'subscription_meta' => $meta,
                ...($startsNewPeriod ? ['subscription_period_starts_at' => $newPeriodStart] : []),
                ...($wasPastDue ? [
                    'subscription_status' => SubscriptionStatus::Active,
                    'subscription_ends_at' => null,
                ] : []),
            ])->save();
        }

        $billable->refresh();
        if ($startsNewPeriod) {
            try {
                $this->walletAdjuster->adjust($billable, $oldPlan, $oldInterval, $newPlanCode, $newIntervalCode);
            } catch (\Throwable $e) {
                Log::warning('Wallet adjust during prorata charge phase 2 failed', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Charge-Webhook failed: Pending-State löschen, kein PATCH-Rollback nötig (PATCH lief noch nicht).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $pending = $meta['pending_prorata_change'] ?? null;

        // If the local pending state is already gone (user cancelled the pending
        // change before Mollie reported the final state), the failed webhook is
        // a no-op — don't set plan_change_failed_at, don't notify, don't fire
        // PlanChangeFailed. The user already decided this charge should not
        // apply; we shouldn't surface a "plan change failed" toast for it.
        if ($pending === null) {
            return;
        }

        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );

        $reason = (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown');
        $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
        $meta['plan_change_failed_reason'] = $reason;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        event(new PlanChangeFailed($billable, $pending, (string) $payment->id, $reason));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
        }
    }
}

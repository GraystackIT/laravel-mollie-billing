<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SingleChargeHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly UpdateSubscription $updateSubscription,
        protected readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for single charge, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
                'type' => $type,
            ]);
            return;
        }

        $lineItems = (array) ($metadata['line_items'] ?? []);
        if (empty($lineItems)) {
            $actual = $this->support->amountFromMolliePayment($payment);
            $lineItems = [[
                'kind' => $type,
                'label' => ucfirst($type).' charge',
                'quantity' => 1,
                'unit_price_net' => $actual,
                'unit_price' => $actual,
                'total_net' => $actual,
            ]];
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, $type, $lineItems, $billable);

        if ($type === 'overage') {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage'], $meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            $this->settleOverageWallets($billable, $lineItems);

            event(new OverageCharged($billable, $invoice, $lineItems));
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true)) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['prorata_pending_payment_id']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            $billable->refresh();
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;

            Log::info('Prorata payment processed, checking for pending plan change', [
                'billable' => $billable->getKey(),
                'has_pending' => ! empty($pendingChange),
                'pending_plan' => $pendingChange['plan_code'] ?? null,
            ]);

            if (! empty($pendingChange)) {
                try {
                    $this->updateSubscription->applyPendingPlanChange($billable, $invoice);
                } catch (\Throwable $e) {
                    Log::error('Failed to apply pending plan change after prorata payment', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);

                    $this->updateSubscription->clearPendingPlanChange($billable);
                    $billable->refresh();

                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                    $meta['plan_change_failed_reason'] = $e->getMessage();
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $e->getMessage()));

                    $recipients = MollieBilling::notifyBillingAdmins($billable);
                    if (! empty($recipients)) {
                        Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
                    }
                }
            }
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    /**
     * The overage charge settled, so the units the customer just paid for are no
     * longer owed: credit them back so the negative balance disappears. Without
     * this the customer pays cash for the units and still loses them from the
     * next period's quota (rollover types add the new quota on top of the
     * negative balance), a plan change in the same window feeds the already-paid
     * negative balance into WalletPlanChangeAdjuster and charges it a second
     * time, and for non-rollover types the renewal reset makes a failed charge
     * indistinguishable from a paid one.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    protected function settleOverageWallets(Billable $billable, array $lineItems): void
    {
        foreach ($lineItems as $line) {
            if (($line['kind'] ?? 'overage') !== 'overage') {
                continue;
            }

            $usageType = (string) ($line['type'] ?? $line['code'] ?? '');
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($usageType === '' || $quantity <= 0) {
                continue;
            }

            try {
                $this->walletService->credit($billable, $usageType, $quantity, 'overage_settlement');
            } catch (\Throwable $e) {
                Log::error('Wallet credit after a paid overage charge failed — negative balance stays and would be charged again', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'usage_type' => $usageType,
                    'quantity' => $quantity,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof Model) {
            // The FQCN, not getMorphClass(): RetryUsageOverageChargeJob resolves
            // the billable via `class_exists($this->billableClass)` and returns
            // silently otherwise, so a morph alias would make the retry (and with
            // it the attempt counting, past_due transition and notification) a
            // permanent no-op. Mirrors PrepareUsageOverageJob.
            RetryUsageOverageChargeJob::dispatch(
                $billable::class,
                $billable->getKey(),
                1,
            );
            return;
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true) && $billable instanceof Model) {
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
            $reason = (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown');

            $this->updateSubscription->clearPendingPlanChange($billable);

            if ($pendingChange) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                $meta['plan_change_failed_reason'] = $reason;
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $reason));

                $recipients = MollieBilling::notifyBillingAdmins($billable);
                if (! empty($recipients)) {
                    Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
                }
            }
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }
}

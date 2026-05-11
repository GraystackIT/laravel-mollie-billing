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
                        Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                    }
                }
            }
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    public function failed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof Model) {
            RetryUsageOverageChargeJob::dispatch(
                $billable->getMorphClass(),
                $billable->getKey(),
                1,
            );
            return;
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true) && $billable instanceof Model) {
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
            $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');

            $this->updateSubscription->clearPendingPlanChange($billable);

            if ($pendingChange) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                $meta['plan_change_failed_reason'] = $reason;
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $reason));

                $recipients = MollieBilling::notifyBillingAdmins($billable);
                if (! empty($recipients)) {
                    Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                }
            }
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }
}

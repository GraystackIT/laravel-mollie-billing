<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WebhookSupport
{
    public function invoiceAlreadyExistsForPayment(object $payment): bool
    {
        $paymentId = (string) ($payment->id ?? '');
        if ($paymentId === '') {
            return false;
        }

        return BillingInvoice::query()
            ->where('mollie_payment_id', $paymentId)
            ->exists();
    }

    public function amountFromMolliePayment(object $payment): int
    {
        return (int) round(((float) ($payment->amount->value ?? 0)) * 100);
    }

    public function hasRefunds(object $payment): bool
    {
        if (property_exists($payment, 'amountRefunded') && is_object($payment->amountRefunded)) {
            return ((float) ($payment->amountRefunded->value ?? 0)) > 0;
        }

        return false;
    }

    public function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, new InvoiceAvailableNotification($billable, $invoice));
    }

    public function reportSubscriptionActivationFailure(
        Billable $billable,
        string $planCode,
        string $interval,
        BillingInvoice $invoice,
        object $payment,
        string $logMessage,
        \Throwable $error,
    ): void {
        $paymentId = (string) ($payment->id ?? '');

        Log::warning($logMessage, [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'plan_code' => $planCode,
            'interval' => $interval,
            'payment_id' => $paymentId,
            'invoice_id' => (int) $invoice->id,
            'error' => $error->getMessage(),
        ]);

        event(new SubscriptionActivationFailed(
            $billable,
            $planCode,
            $interval,
            $paymentId,
            (int) $invoice->id,
            $error->getMessage(),
        ));

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                new AdminPlanChangeFailedNotification(
                    reason: 'Subscription activation after first payment failed — Mollie subscription was not created',
                    context: [
                        'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                        'plan_code' => $planCode,
                        'interval' => $interval,
                        'payment_id' => $paymentId,
                        'invoice_id' => (int) $invoice->id,
                        'error' => $error->getMessage(),
                    ],
                ),
            );
        }
    }
}

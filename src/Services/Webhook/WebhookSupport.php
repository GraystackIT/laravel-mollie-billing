<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
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
        return $this->refundedAmount($payment) > 0;
    }

    /**
     * Total amount refunded on this payment, in cents. Monotonic per payment
     * (Mollie reports the sum of all its refunds), which makes it usable as the
     * refund fingerprint in the webhook dedup signature.
     */
    public function refundedAmount(object $payment): int
    {
        $refunded = $payment->amountRefunded ?? null;

        if (! is_object($refunded)) {
            return 0;
        }

        return (int) round(((float) ($refunded->value ?? 0)) * 100);
    }

    /**
     * Reconcile webhook-built line items with the amount Mollie actually
     * collected. Handlers that rebuild their line items from the catalog at
     * webhook time can drift from the checkout: a coupon that expired in the
     * meantime, a price that changed, a discount the checkout applied but the
     * handler cannot reconstruct. The money that cleared is the source of truth,
     * so on a difference we fire {@see PaymentAmountMismatch} and append an
     * adjustment line that pulls the invoice total onto the paid amount — header,
     * line items, PDF and OSS export then all agree with the payment, and the
     * refundable base stays within what Mollie can actually refund.
     *
     * `$vatRate` must come from the country lookup (never be reverse-engineered
     * from the gross) — it is only used to split the paid gross back into net.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    public function reconcileWithPaidAmount(
        object $payment,
        Billable $billable,
        array $lineItems,
        int $expectedNet,
        int $expectedGross,
        float $vatRate,
    ): array {
        $actualGross = $this->amountFromMolliePayment($payment);

        // 1 cent of slack: gross is rounded per invoice, so an exact match is not
        // guaranteed even when nothing is wrong.
        if (abs($actualGross - $expectedGross) <= 1) {
            return $lineItems;
        }

        event(new PaymentAmountMismatch($billable, (string) ($payment->id ?? ''), $expectedGross, $actualGross));

        $actualNet = $vatRate > 0
            ? (int) round($actualGross / (1 + $vatRate / 100))
            : $actualGross;
        $delta = $actualNet - $expectedNet;

        Log::warning('Webhook invoice amount differs from the amount paid — reconciling to the paid amount', [
            'payment_id' => $payment->id ?? null,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'expected_gross' => $expectedGross,
            'actual_gross' => $actualGross,
            'adjustment_net' => $delta,
        ]);

        if ($delta === 0) {
            return $lineItems;
        }

        $lineItems[] = [
            'kind' => 'adjustment',
            'code' => null,
            'label' => 'Payment adjustment',
            'quantity' => 1,
            'unit_price' => $delta,
            'unit_price_net' => $delta,
            'total_net' => $delta,
        ];

        return $lineItems;
    }

    public function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, MollieBilling::resolveNotification(InvoiceAvailableNotification::class, $billable, $invoice));
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
                MollieBilling::resolveNotification(
                    AdminPlanChangeFailedNotification::class,
                    'Subscription activation after first payment failed — Mollie subscription was not created',
                    [
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

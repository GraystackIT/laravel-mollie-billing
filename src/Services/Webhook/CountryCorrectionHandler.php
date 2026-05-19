<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\CountryMismatchReissueFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CountryCorrectionHandler
{
    public function __construct(
        protected readonly InvoiceService $salesInvoiceService,
    ) {
    }

    /**
     * Country-mismatch correction reissue: create the BillingInvoice for a
     * confirmed correction payment, link it back to the mismatch's audit log,
     * and clear the pending state. Idempotent.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');
        $mismatchId = (int) ($metadata['mismatch_id'] ?? 0);
        $originalInvoiceId = (int) ($metadata['original_invoice_id'] ?? 0);

        if ($mismatchId === 0 || $originalInvoiceId === 0) {
            Log::warning('country_correction webhook missing metadata', [
                'payment_id' => $paymentId,
                'metadata' => $metadata,
            ]);
            return;
        }

        $existing = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();
        if ($existing !== null) {
            return;
        }

        $original = BillingInvoice::query()->whereKey($originalInvoiceId)->first();
        if ($original === null) {
            Log::warning('country_correction webhook: original invoice not found', [
                'payment_id' => $paymentId,
                'original_invoice_id' => $originalInvoiceId,
            ]);
            return;
        }

        try {
            $invoice = $this->salesInvoiceService->createCorrectionInvoice($payment, $original, $billable);
        } catch (\Throwable $e) {
            Log::error('country_correction reissue failed during webhook', [
                'payment_id' => $paymentId,
                'mismatch_id' => $mismatchId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        event(new PaymentSucceeded($billable, $invoice));
    }

    /**
     * Country-mismatch correction reissue failed at Mollie. Roll the mismatch
     * back to Pending so the user can retry the resolve flow, drop the pending
     * country_corrections entry so a future retry isn't double-counted, and
     * notify the billable's admins so manual follow-up is possible. Idempotent.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');
        $mismatchId = (int) ($metadata['mismatch_id'] ?? 0);
        $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');

        $meta = $billable->getBillingSubscriptionMeta();
        if (isset($meta['country_corrections'][$paymentId])) {
            unset($meta['country_corrections'][$paymentId]);
            if (empty($meta['country_corrections'])) {
                unset($meta['country_corrections']);
            }
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        if ($mismatchId === 0) {
            return;
        }

        $mismatch = BillingCountryMismatch::query()->whereKey($mismatchId)->first();
        if ($mismatch === null) {
            return;
        }

        $mismatch->forceFill([
            'status' => CountryMismatchStatus::Pending,
        ])->save();

        try {
            app(CancelSubscription::class)
                ->handle($billable, immediately: false);
        } catch (\Throwable $e) {
            Log::warning('Country correction failed: cancel-at-period-end failed', [
                'billable_id' => (string) ($billable instanceof Model ? $billable->getKey() : ''),
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('country_correction reissue failed at Mollie', [
            'mismatch_id' => $mismatchId,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send(
                $recipients,
                MollieBilling::resolveNotification(CountryMismatchReissueFailedNotification::class, $billable, $mismatch, $reason),
            );
        }
    }
}

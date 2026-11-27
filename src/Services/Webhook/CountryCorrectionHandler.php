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
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CountryCorrectionHandler
{
    use MutatesBillingMeta;

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
            // Booked already; make sure nothing still calls it pending. Normally the entry
            // went with the booking, but one brought back by a stale writer (or left by a
            // booking that crashed after its insert) kept the dashboard's "re-charge
            // pending" callout up for good and had the sweeper poll this payment hourly.
            $this->forgetPendingCorrection($billable, $paymentId);

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

            // Rethrown, not swallowed: the customer has paid and no reissue invoice exists.
            // Returned, the webhook answered 200 and recorded the outcome as handled, so
            // Mollie never re-delivered and the money stayed on no document. Thrown, the
            // controller answers 5xx and the re-delivery books it — the invoice guard above
            // keeps that idempotent.
            throw $e;
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

        $this->forgetPendingCorrection($billable, $paymentId);

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

    /**
     * Drop one payment's `country_corrections` entry, under the row lock.
     *
     * Never by saving the instance this handler was given: the sweeper hands over a billable
     * loaded a chunk and several Mollie round trips earlier, and saving its whole column
     * deleted every key written since — a correction a re-resolve had just issued among
     * them, whose payment then arrived with no pending state to book it from.
     */
    private function forgetPendingCorrection(Model $billable, string $paymentId): void
    {
        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId): ?array {
            if (! isset($meta['country_corrections'][$paymentId])) {
                return null;
            }

            unset($meta['country_corrections'][$paymentId]);
            if (empty($meta['country_corrections'])) {
                unset($meta['country_corrections']);
            }

            return $meta;
        });
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\CountryMismatchFlagged;
use GraystackIT\MollieBilling\Events\CountryMismatchResolved;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\CountryMismatchSelfNotification;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Three-way country reconciliation for B2C billables.
 *
 * Compares user-declared, payment-method, and IP-derived country codes. If
 * the user's declared country matches at least one of the other signals, the
 * billable is consistent. Otherwise a {@see BillingCountryMismatch} is opened,
 * the subscription is scheduled for cancel-at-period-end, and the billable is
 * notified by email so the user can self-correct via the dashboard modal.
 *
 * B2B billables (those with a non-empty `vat_number`) are skipped: VIES is the
 * authoritative signal there, and the bank/card country is fiscally irrelevant
 * under the reverse-charge mechanism.
 */
class CountryMatchService
{
    public function __construct(
        private readonly CancelSubscription $cancelSubscription,
        private readonly RefundInvoiceService $refunds,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * Run the three-way check. Returns the freshly flagged mismatch (or the
     * existing pending one if already flagged), or null if everything is fine
     * or the billable is B2B / has insufficient signals.
     */
    public function check(Billable $billable): ?BillingCountryMismatch
    {
        /** @var Model $model */
        $model = $billable;

        $vatNumber = trim((string) ($model->getAttribute('vat_number') ?? ''));
        if ($vatNumber !== '') {
            return null;
        }

        $user = $this->normalize($model->getAttribute('tax_country_user'));
        $payment = $this->normalize($model->getAttribute('tax_country_payment'));
        $ip = $this->normalize($model->getAttribute('tax_country_ip'));

        if ($user === null) {
            return null;
        }

        $signals = array_values(array_filter([$payment, $ip]));
        if ($signals === []) {
            return null;
        }

        if (in_array($user, $signals, true)) {
            return null;
        }

        return $this->flag($billable, [
            'tax_country_user' => $user,
            'tax_country_payment' => $payment,
            'tax_country_ip' => $ip,
        ]);
    }

    /**
     * Idempotent flag: if a Pending mismatch already exists for this billable,
     * return it without resending notifications, re-marking invoices, or
     * re-cancelling the subscription.
     *
     * @param  array{tax_country_user:?string,tax_country_payment:?string,tax_country_ip:?string}  $countries
     */
    public function flag(Billable $billable, array $countries): BillingCountryMismatch
    {
        /** @var Model $model */
        $model = $billable;

        $existing = BillingCountryMismatch::query()
            ->where('billable_type', $model->getMorphClass())
            ->where('billable_id', $model->getKey())
            ->where('status', CountryMismatchStatus::Pending)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $mismatch = BillingCountryMismatch::create([
            'billable_type' => $model->getMorphClass(),
            'billable_id' => $model->getKey(),
            'tax_country_user' => $countries['tax_country_user'] ?? '',
            'tax_country_payment' => $countries['tax_country_payment'] ?? null,
            'tax_country_ip' => $countries['tax_country_ip'] ?? null,
            'status' => CountryMismatchStatus::Pending,
        ]);

        BillingInvoice::query()
            ->where('billable_type', $model->getMorphClass())
            ->where('billable_id', $model->getKey())
            ->where('invoice_kind', '!=', InvoiceKind::Refund)
            ->where('amount_net', '>', 0)
            ->whereNull('mismatch_id')
            ->update(['mismatch_id' => $mismatch->id]);

        $model->forceFill(['country_mismatch_flagged_at' => BillingTime::nowUtc()])->save();

        try {
            $this->cancelSubscription->handle($billable, immediately: false);
        } catch (\Throwable $e) {
            Log::warning('Country mismatch: cancel-at-period-end failed', [
                'billable_id' => (string) $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        CountryMismatchFlagged::dispatch($billable, $mismatch);

        $this->notifyBillable($billable, $mismatch);

        $mismatch->forceFill(['notified_at' => BillingTime::nowUtc()])->save();

        return $mismatch;
    }

    /**
     * Resolve a mismatch end-to-end: refund all linked invoices, update the
     * billable's country, mark the mismatch as Resolved, and trigger Mollie
     * re-charges with the new country's VAT rate.
     *
     * Subscription is NOT auto-resumed — the user must explicitly resubscribe
     * from the dashboard banner.
     *
     * Idempotency: invoices that are already fully refunded are skipped. Any
     * `country_corrections` pending entry for an original invoice is reused
     * rather than duplicated.
     */
    public function resolve(Billable $billable, BillingCountryMismatch $mismatch, string $newCountry, mixed $resolvedBy = null): void
    {
        /** @var Model $model */
        $model = $billable;

        $newCountry = strtoupper(trim($newCountry));
        if (strlen($newCountry) !== 2) {
            throw new \InvalidArgumentException("Invalid ISO-2 country: {$newCountry}");
        }

        if ($mismatch->status === CountryMismatchStatus::Resolved && $mismatch->chosen_country === $newCountry) {
            // Already resolved to the same country — only retry pending re-charges.
            $this->retryPendingReissues($billable, $mismatch, $newCountry);
            return;
        }

        $invoices = $mismatch->invoices()
            ->where('invoice_kind', '!=', InvoiceKind::Refund)
            ->where('amount_net', '>', 0)
            ->get();

        $resolvedById = $resolvedBy instanceof Model ? $resolvedBy->getKey() : $resolvedBy;

        $creditNoteSerialsByOriginal = [];
        foreach ($invoices as $invoice) {
            if ((int) $invoice->refunded_net >= (int) $invoice->amount_net) {
                continue;
            }

            try {
                $creditNote = $this->refunds->refundFully(
                    $invoice,
                    RefundReasonCode::BillingError,
                    'Country mismatch correction'
                );
                $creditNoteSerialsByOriginal[(int) $invoice->id] = (string) ($creditNote->serial_number ?? '');
            } catch (\Throwable $e) {
                Log::error('Country mismatch resolve: refund failed', [
                    'mismatch_id' => $mismatch->id,
                    'invoice_id' => (int) $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        DB::transaction(function () use ($model, $newCountry, $mismatch, $resolvedById): void {
            $model->forceFill([
                'tax_country_user' => $newCountry,
                'billing_country' => $newCountry,
            ])->save();

            $mismatch->forceFill([
                'status' => CountryMismatchStatus::Resolved,
                'chosen_country' => $newCountry,
                'resolved_at' => BillingTime::nowUtc(),
                'resolved_by_user_id' => $resolvedById,
            ])->save();
        });

        foreach ($invoices as $invoice) {
            $serial = $creditNoteSerialsByOriginal[(int) $invoice->id] ?? null;

            if ($this->hasPendingReissue($billable, (int) $invoice->id)) {
                continue;
            }

            try {
                $this->invoices->issueCorrectionCharge(
                    $invoice,
                    $billable,
                    $newCountry,
                    (int) $mismatch->id,
                    $serial,
                );
            } catch (\Throwable $e) {
                Log::error('Country mismatch resolve: re-charge failed', [
                    'mismatch_id' => $mismatch->id,
                    'original_invoice_id' => (int) $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                // Do not abort: the mismatch is already Resolved locally;
                // the failed Mollie call will be retried via the same
                // resolve() entry point or surfaced via the webhook
                // failure handler.
            }
        }

        CountryMismatchResolved::dispatch($billable, $mismatch, $resolvedBy);
    }

    /**
     * Retry path for an already-resolved mismatch where some Mollie re-charges
     * never confirmed. Refunds are not repeated; only missing pending entries
     * are re-issued.
     */
    private function retryPendingReissues(Billable $billable, BillingCountryMismatch $mismatch, string $newCountry): void
    {
        $invoices = $mismatch->invoices()
            ->where('invoice_kind', '!=', InvoiceKind::Refund)
            ->where('amount_net', '>', 0)
            ->get();

        foreach ($invoices as $invoice) {
            if ($this->hasPendingReissue($billable, (int) $invoice->id)) {
                continue;
            }

            // Lookup the credit note serial for this original via line_items.parent_invoice_id.
            $serial = BillingInvoice::query()
                ->where('billable_type', $invoice->billable_type)
                ->where('billable_id', $invoice->billable_id)
                ->where('invoice_kind', InvoiceKind::Refund)
                ->whereJsonContains('line_items', [['parent_invoice_id' => (int) $invoice->id]])
                ->orderByDesc('id')
                ->value('serial_number');

            try {
                $this->invoices->issueCorrectionCharge(
                    $invoice,
                    $billable,
                    $newCountry,
                    (int) $mismatch->id,
                    is_string($serial) ? $serial : null,
                );
            } catch (\Throwable $e) {
                Log::error('Country mismatch retry: re-charge failed', [
                    'mismatch_id' => $mismatch->id,
                    'original_invoice_id' => (int) $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function hasPendingReissue(Billable $billable, int $originalInvoiceId): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $pending = (array) ($meta['country_corrections'] ?? []);
        foreach ($pending as $entry) {
            if ((int) ($entry['original_invoice_id'] ?? 0) === $originalInvoiceId) {
                return true;
            }
        }
        return false;
    }

    private function notifyBillable(Billable $billable, BillingCountryMismatch $mismatch): void
    {
        $notification = MollieBilling::resolveNotification(CountryMismatchSelfNotification::class, $billable, $mismatch);

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, $notification);
            return;
        }

        $email = $billable->getBillingEmail();
        if (is_string($email) && $email !== '') {
            Notification::route('mail', $email)->notify($notification);
        }
    }

    private function normalize(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return strtoupper((string) $code);
    }
}

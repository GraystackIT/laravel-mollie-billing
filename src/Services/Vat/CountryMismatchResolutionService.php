<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\CountryMismatchStrategy;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\CountryMismatchNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Auto-resolves country mismatches with the decision hierarchy
 *   VIES > Payment > User
 *
 * - Re-validates the billable's VAT number against VIES first (if any), so a
 *   user who corrected a typo since the original flag is detected as a no-op.
 * - If the chosen country differs from the user-declared one, executes a
 *   refund-and-reissue flow over Mollie for every paid invoice created since
 *   the mismatch was flagged. The mismatch keeps its `Pending` status (with a
 *   `failure_reason`) when auto-resolve cannot proceed.
 *
 * The heavy lifting (refund + reissue) lives in `executeCorrection()` and runs
 * outside any wrapping DB transaction — see plan/design notes.
 */
class CountryMismatchResolutionService
{
    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly CountryMatchService $matchService,
        private readonly InvoiceService $invoices,
        private readonly RefundInvoiceService $refundService,
    ) {}

    public function attemptAutoResolve(BillingCountryMismatch $mismatch): CountryMismatchResolutionResult
    {
        if (! config('mollie-billing.country_mismatch_auto_resolve_enabled', true)) {
            return CountryMismatchResolutionResult::skipped('auto_resolve_disabled');
        }

        $maxAttempts = (int) config('mollie-billing.country_mismatch_max_auto_attempts', 5);

        // Short transaction just to lock the row and bump the attempt counter.
        // No Mollie API calls happen inside this transaction.
        $proceed = DB::transaction(function () use ($mismatch, $maxAttempts): bool {
            /** @var BillingCountryMismatch|null $fresh */
            $fresh = BillingCountryMismatch::query()->whereKey($mismatch->getKey())->lockForUpdate()->first();
            if ($fresh === null || $fresh->status !== CountryMismatchStatus::Pending) {
                return false;
            }
            if ((int) $fresh->auto_resolve_attempts >= $maxAttempts) {
                return false;
            }

            $fresh->forceFill([
                'auto_resolve_attempts' => (int) $fresh->auto_resolve_attempts + 1,
                'last_auto_attempt_at' => BillingTime::nowUtc(),
            ])->save();

            $mismatch->refresh();

            return true;
        });

        if (! $proceed) {
            return CountryMismatchResolutionResult::skipped('not_pending_or_attempts_exhausted');
        }

        /** @var (Billable&Model)|null $billable */
        $billable = $mismatch->billable()->first();
        if ($billable === null) {
            return $this->markPermanentFailure($mismatch, 'billable_missing');
        }

        // Step 1: VIES re-check (only if billable has a VAT number).
        $vatNumber = (string) ($billable->vat_number ?? '');
        if ($vatNumber !== '') {
            try {
                $this->vat->validateAndPersist($billable, $vatNumber);
            } catch (ViesUnavailableException $e) {
                return CountryMismatchResolutionResult::transientFail('vies_unavailable: '.$e->getMessage());
            } catch (\Throwable $e) {
                // Unknown VIES failure — treat as transient so we retry next sweep.
                return CountryMismatchResolutionResult::transientFail('vies_error: '.$e->getMessage());
            }
        }

        // Step 2: Decide country. VIES > Payment > (nothing).
        $chosen = $this->decideCountry($billable, $mismatch);
        if ($chosen === null) {
            return $this->markPermanentFailure($mismatch, 'insufficient_signal');
        }

        // Pre-check: chosen country must have a resolvable VAT rate, otherwise
        // refund-and-reissue would explode in the middle of the flow.
        try {
            $this->vat->vatRateFor($chosen);
        } catch (\Throwable $e) {
            return $this->markPermanentFailure($mismatch, 'non_eu_no_rate: '.$chosen);
        }

        // Step 3: NoOp branch — re-check confirms the user was right.
        $userCountry = strtoupper((string) ($mismatch->tax_country_user ?? ''));
        $strategy = $this->strategyFor($billable, $chosen);

        if ($chosen === $userCountry) {
            $this->markResolved($mismatch, CountryMismatchStrategy::AutoNoop, $chosen, []);

            return CountryMismatchResolutionResult::resolved(CountryMismatchStrategy::AutoNoop, $chosen, noop: true);
        }

        // Step 4: Correction flow (refund + reissue). Implemented in step 4 of the plan.
        return $this->executeCorrection($mismatch, $billable, $chosen, $strategy);
    }

    /**
     * Manual override from the admin UI. Skips VIES re-check — admin's choice wins.
     */
    public function resolveManually(BillingCountryMismatch $mismatch, string $chosenCountry, mixed $resolvedBy): CountryMismatchResolutionResult
    {
        $chosen = strtoupper(trim($chosenCountry));
        if (strlen($chosen) !== 2) {
            return CountryMismatchResolutionResult::permanentFail('invalid_country_code');
        }

        if ($mismatch->status !== CountryMismatchStatus::Pending) {
            return CountryMismatchResolutionResult::skipped('not_pending');
        }

        /** @var (Billable&Model)|null $billable */
        $billable = $mismatch->billable()->first();
        if ($billable === null) {
            return $this->markPermanentFailure($mismatch, 'billable_missing');
        }

        try {
            $this->vat->vatRateFor($chosen);
        } catch (\Throwable $e) {
            return $this->markPermanentFailure($mismatch, 'non_eu_no_rate: '.$chosen);
        }

        $userCountry = strtoupper((string) ($mismatch->tax_country_user ?? ''));
        if ($chosen === $userCountry) {
            $this->markResolved($mismatch, CountryMismatchStrategy::Manual, $chosen, [], $resolvedBy);

            return CountryMismatchResolutionResult::resolved(CountryMismatchStrategy::Manual, $chosen, noop: true);
        }

        return $this->executeCorrection($mismatch, $billable, $chosen, CountryMismatchStrategy::Manual, $resolvedBy);
    }

    /**
     * VIES > Payment > null.
     *
     * - VIES wins when a current validation exists with valid=true.
     * - Otherwise the payment country wins (if known and not the placeholder '?').
     * - Otherwise null — caller treats this as insufficient signal.
     */
    private function decideCountry(Billable $billable, BillingCountryMismatch $mismatch): ?string
    {
        $validation = $billable->currentVatValidation();
        if ($validation !== null && $validation->valid === true) {
            $code = strtoupper((string) ($validation->country_code ?? ''));
            if (strlen($code) === 2) {
                return $code;
            }
        }

        $payment = strtoupper((string) ($mismatch->tax_country_payment ?? ''));
        if ($payment !== '' && $payment !== '?') {
            return $payment;
        }

        return null;
    }

    private function strategyFor(Billable $billable, string $chosen): CountryMismatchStrategy
    {
        $validation = $billable->currentVatValidation();
        $viesCountry = $validation !== null && $validation->valid === true
            ? strtoupper((string) ($validation->country_code ?? ''))
            : null;

        return $viesCountry === $chosen ? CountryMismatchStrategy::AutoVies : CountryMismatchStrategy::AutoPayment;
    }

    /**
     * @param  array<string, mixed>  $correctionLog
     */
    private function markResolved(
        BillingCountryMismatch $mismatch,
        CountryMismatchStrategy $strategy,
        string $chosenCountry,
        array $correctionLog = [],
        mixed $resolvedBy = null,
    ): void {
        $mismatch->forceFill([
            'chosen_country' => $chosenCountry,
            'resolved_strategy' => $strategy,
            'correction_invoices' => $correctionLog === [] ? null : $correctionLog,
            'failure_reason' => null,
        ])->save();

        $billable = $mismatch->billable()->first();
        if ($billable !== null) {
            $this->matchService->resolve($billable, $mismatch, $resolvedBy, skipLegacyCreditNote: true);
        }
    }

    private function markPermanentFailure(BillingCountryMismatch $mismatch, string $reason): CountryMismatchResolutionResult
    {
        $mismatch->forceFill(['failure_reason' => $reason])->save();

        return CountryMismatchResolutionResult::permanentFail($reason);
    }

    /**
     * Refund-and-reissue flow. Runs OUTSIDE any wrapping DB transaction because
     * each step makes Mollie API calls and we cannot roll those back. Crash
     * safety comes from persisting `correction_invoices` after every step and
     * being idempotent on re-run.
     *
     * Scope: ALL paid, not-fully-refunded invoices on this billable that were
     * issued under the now-wrong country. We deliberately do NOT bound by time
     * — the nightly sweep keeps the backlog small in normal operation, and a
     * narrow time window would silently miss invoices issued before the
     * mismatch was first flagged (which is precisely the common case, since
     * the mismatch is flagged in reaction to those invoices).
     */
    private function executeCorrection(
        BillingCountryMismatch $mismatch,
        Billable $billable,
        string $chosenCountry,
        CountryMismatchStrategy $strategy,
        mixed $resolvedBy = null,
    ): CountryMismatchResolutionResult {
        /** @var Model&Billable $billable */
        $oldCountry = strtoupper((string) ($mismatch->tax_country_user ?? ''));

        // refunded_net is a *net* counter, so the "still refundable" predicate is
        // refunded_net < amount_net (not amount_gross).
        $invoices = $billable->billingInvoices()
            ->where('status', InvoiceStatus::Paid)
            ->where('invoice_kind', '!=', InvoiceKind::Refund)
            ->where('country', $oldCountry)
            ->whereColumn('refunded_net', '<', 'amount_net')
            ->orderBy('id')
            ->get();

        $log = $mismatch->correctionInvoicesData();

        // Step 1: Refund every paid invoice still bearing the old (wrong) country.
        foreach ($invoices as $invoice) {
            if ($this->alreadyRefunded($log, (int) $invoice->id)) {
                continue;
            }

            $reason = $this->creditNoteReason($invoice, $oldCountry, $chosenCountry, $strategy);

            try {
                if (empty($invoice->mollie_payment_id)) {
                    // Local-Sub: no Mollie refund possible, only a local credit note.
                    $cn = $this->invoices->createCreditNote(
                        $invoice,
                        (int) $invoice->amount_net,
                        null,
                        $reason,
                    );
                    $log['refunded'][] = [
                        'invoice_id' => (int) $invoice->id,
                        'credit_note_id' => (int) $cn->id,
                        'credit_note_serial' => (string) ($cn->serial_number ?? ''),
                        'mollie_refund_id' => null,
                    ];
                } else {
                    $cn = $this->refundService->refundFully(
                        $invoice,
                        RefundReasonCode::Other,
                        $reason,
                    );
                    // mollie_refund_id is set later by the handlePaymentRefunded webhook;
                    // best-effort lookup at the time of creation.
                    $refundId = null;
                    $cnLines = (array) ($cn->line_items ?? []);
                    if (isset($cnLines[0]['mollie_refund_id'])) {
                        $refundId = (string) $cnLines[0]['mollie_refund_id'];
                    }
                    $log['refunded'][] = [
                        'invoice_id' => (int) $invoice->id,
                        'credit_note_id' => (int) $cn->id,
                        'credit_note_serial' => (string) ($cn->serial_number ?? ''),
                        'mollie_refund_id' => $refundId,
                    ];
                }
            } catch (\Throwable $e) {
                $this->persistLog($mismatch, $log);
                Log::warning('Country mismatch refund failed', [
                    'mismatch_id' => $mismatch->id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                return CountryMismatchResolutionResult::transientFail('refund_failed: '.$e->getMessage());
            }

            $this->persistLog($mismatch, $log);
        }

        // Step 2: Update billable's country fields BEFORE re-charge so the new
        // payment computes VAT against the corrected country.
        // tax_country_user AND billing_country must be in sync — getBillingCountry()
        // reads billing_country, not tax_country_user.
        if ($billable instanceof Model) {
            $billable->forceFill([
                'tax_country_user' => $chosenCountry,
                'billing_country' => $chosenCountry,
            ])->save();
        }

        // Step 3: Re-charge each Mollie-payable invoice with the corrected VAT rate.
        // Local-sub invoices were already credited in Step 1 — no re-charge needed.
        foreach ($log['refunded'] as $entry) {
            $originalId = (int) ($entry['invoice_id'] ?? 0);
            if ($originalId === 0) {
                continue;
            }

            $original = BillingInvoice::query()->whereKey($originalId)->first();
            if ($original === null || empty($original->mollie_payment_id)) {
                continue;
            }

            if ($this->alreadyReissued($log, $originalId)) {
                continue;
            }

            try {
                $payment = $this->invoices->issueCorrectionCharge(
                    $original,
                    $billable,
                    $chosenCountry,
                    (int) $mismatch->id,
                );
                $paymentId = is_object($payment) ? (string) ($payment->id ?? '') : '';
                $log['reissued'][] = [
                    'original_invoice_id' => $originalId,
                    'mollie_payment_id' => $paymentId,
                    'invoice_id' => null, // filled by webhook when payment confirms
                ];
            } catch (\Throwable $e) {
                $this->persistLog($mismatch, $log);
                $reason = 'reissue_charge_failed: '.$e->getMessage();
                $mismatch->forceFill(['failure_reason' => $reason])->save();

                $recipients = MollieBilling::notifyAdmin();
                if (! empty($recipients)) {
                    Notification::send($recipients, new CountryMismatchNotification($billable, $mismatch));
                }

                Log::warning('Country mismatch reissue charge failed', [
                    'mismatch_id' => $mismatch->id,
                    'invoice_id' => $originalId,
                    'error' => $e->getMessage(),
                ]);

                return CountryMismatchResolutionResult::transientFail($reason);
            }

            $this->persistLog($mismatch, $log);
        }

        // Step 4: Mark resolved.
        $this->markResolved($mismatch, $strategy, $chosenCountry, $log, $resolvedBy);

        return CountryMismatchResolutionResult::resolved($strategy, $chosenCountry);
    }

    /**
     * @param  array{refunded: array<int, array<string, mixed>>, reissued: array<int, array<string, mixed>>}  $log
     */
    private function alreadyRefunded(array $log, int $invoiceId): bool
    {
        foreach ($log['refunded'] as $entry) {
            if ((int) ($entry['invoice_id'] ?? 0) === $invoiceId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array{refunded: array<int, array<string, mixed>>, reissued: array<int, array<string, mixed>>}  $log
     */
    private function alreadyReissued(array $log, int $originalInvoiceId): bool
    {
        foreach ($log['reissued'] as $entry) {
            if ((int) ($entry['original_invoice_id'] ?? 0) === $originalInvoiceId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array{refunded: array<int, array<string, mixed>>, reissued: array<int, array<string, mixed>>}  $log
     */
    private function persistLog(BillingCountryMismatch $mismatch, array $log): void
    {
        $mismatch->forceFill(['correction_invoices' => $log])->save();
    }

    /**
     * Customer-facing explanation rendered on the credit note PDF (and stored in
     * refund_reason_text). Spells out which country was wrong, which is now
     * being used, why the change was made, and who to contact for objections.
     */
    private function creditNoteReason(
        BillingInvoice $original,
        string $oldCountry,
        string $newCountry,
        CountryMismatchStrategy $strategy,
    ): string {
        return (string) __('billing::portal.country_correction_credit_note_reason', [
            'original_serial' => (string) ($original->serial_number ?? $original->id),
            'old_country' => $oldCountry,
            'new_country' => $newCountry,
            'old_rate' => $this->vatRateLabel($oldCountry),
            'new_rate' => $this->vatRateLabel($newCountry, $original),
            'strategy' => __('billing::portal.country_correction_strategy_'.$strategy->value),
            'support_email' => $this->supportEmail(),
        ]);
    }

    /**
     * Format a VAT rate for display. Falls back to the original invoice's
     * plan-line rate when vatRateFor() can't resolve (e.g. reverse-charge will
     * collapse the new rate to 0% but the old rate is whatever was billed).
     */
    private function vatRateLabel(string $country, ?BillingInvoice $reference = null): string
    {
        try {
            $rate = $this->vat->vatRateFor($country);
        } catch (\Throwable $e) {
            // Fall back to the rate used on the reference invoice's first line.
            $rate = 0.0;
            if ($reference !== null) {
                $first = ((array) $reference->line_items)[0] ?? [];
                $rate = (float) ($first['vat_rate'] ?? 0.0);
            }
        }

        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private function supportEmail(): string
    {
        $candidates = [
            config('mollie-billing.invoices.seller.email'),
            config('mail.from.address'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'support';
    }
}

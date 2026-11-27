<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\CountryMismatchFlagged;
use GraystackIT\MollieBilling\Events\CountryMismatchResolved;
use GraystackIT\MollieBilling\Exceptions\InvalidBillingInputException;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\CountryMismatchSelfNotification;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
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
    use MutatesBillingMeta;

    public function __construct(
        private readonly CancelSubscription $cancelSubscription,
        private readonly RefundInvoiceService $refunds,
        private readonly InvoiceService $invoices,
        private readonly VatCalculationService $vat,
    ) {
    }

    /**
     * Meta key holding the durable ledger of correction charges already issued,
     * keyed by original invoice id. Unlike `country_corrections` (which
     * InvoiceService clears once the webhook lands), this survives success and
     * failure alike, so "already corrected" stays distinguishable from
     * "never issued".
     */
    private const ISSUED_KEY = 'country_corrections_issued';

    /**
     * Meta key holding the accepted resolution of a past mismatch: the country
     * confirmed as correct together with the signals it was confirmed against.
     */
    private const VERIFIED_KEY = 'tax_country_verified';

    /**
     * The reason text on the credit note a correction writes for each original. Together
     * with `RefundReasonCode::BillingError` it tells the correction's own refund apart from
     * one made before the mismatch was resolved: the first is re-charged at the new rate,
     * the second stays refunded ({@see InvoiceService::creditedOutsideCorrection()}).
     */
    public const CORRECTION_REFUND_REASON = 'Country mismatch correction';

    /**
     * Run the three-way check. Returns the freshly flagged mismatch (or the
     * existing pending one if already flagged), or null if everything is fine
     * or the billable is B2B / has insufficient signals.
     *
     * A country confirmed by an earlier `resolve()` is honoured as long as the
     * payment/IP signals have not changed since — otherwise every renewal would
     * re-flag a mismatch that support already settled.
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

        if ($this->isVerifiedCountry($billable, $user, $payment, $ip)) {
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
     * Idempotency, per original invoice:
     *  - invoices that are already fully refunded are not refunded again;
     *  - a `country_corrections` pending entry means a correction payment is in
     *    flight, so nothing is re-issued;
     *  - a completed correction (its reissue invoice exists) is never re-issued;
     *  - reissue invoices themselves are excluded from the target set, so they can
     *    never be refunded and re-charged as if they were originals.
     *
     * `$newCountry` must be one of the mismatch's own signals (payment or IP).
     * Any other country needs a non-empty `$justification`, which is persisted
     * with the resolution — otherwise `check()` would simply re-flag the same
     * mismatch on the next payment, forever.
     *
     * @param  string|null  $justification  required when resolving to a country that matches neither signal
     */
    public function resolve(
        Billable $billable,
        BillingCountryMismatch $mismatch,
        string $newCountry,
        mixed $resolvedBy = null,
        ?string $justification = null,
    ): void {
        /** @var Model $model */
        $model = $billable;

        $newCountry = strtoupper(trim($newCountry));
        if (strlen($newCountry) !== 2) {
            throw new InvalidBillingInputException("Invalid ISO-2 country: {$newCountry}", 'billing::exceptions.country.invalid', ['country' => $newCountry]);
        }

        $justification = $justification === null ? null : trim($justification);
        $this->assertCountryIsResolvable($mismatch, $newCountry, $justification);
        $this->assertCountryIsInvoiceable($billable, $newCountry);

        if ($mismatch->status === CountryMismatchStatus::Resolved) {
            if ($mismatch->chosen_country !== $newCountry) {
                // The originals are already refunded and re-invoiced at
                // `chosen_country`. Correcting them a second time would refund the
                // valid reissues and bill the customer twice for one supply period;
                // a genuinely new country needs a new mismatch.
                throw new InvalidBillingInputException(
                    "Mismatch {$mismatch->id} is already resolved to {$mismatch->chosen_country}; "
                    ."flag a new mismatch to correct it to {$newCountry}.",
                    'billing::exceptions.country.mismatch_already_resolved',
                    ['chosen' => (string) $mismatch->chosen_country, 'country' => $newCountry],
                );
            }

            // Already resolved to the same country — only retry pending re-charges.
            $this->retryPendingReissues($billable, $mismatch, $newCountry);
            return;
        }

        $invoices = $this->correctionTargets($billable, $mismatch);

        $resolvedById = $resolvedBy instanceof Model ? $resolvedBy->getKey() : $resolvedBy;

        $creditNoteSerialsByOriginal = [];
        foreach ($invoices as $invoice) {
            // Effective, not the raw column: a credit note written by a path that
            // never updated `refunded_net` (legacy pro-rata refunds) leaves the
            // column at 0, and refundFully() then rejects the second refund with
            // "Refund amount must be positive" — which is rethrown below and
            // aborts the whole resolve, leaving the mismatch Pending forever.
            if ($invoice->remainingRefundableNet() <= 0) {
                continue;
            }

            try {
                // Money only, never wallet units: `refundFully()` hands an overage invoice's
                // units back, but a correction re-issues the tax document of usage that was
                // consumed and is charged again right below — crediting the units gave the
                // customer that usage a second time for free.
                $creditNote = $this->refunds->refund($invoice, [
                    'amount_net' => null,
                    'wallet_credits' => [],
                    'reason_code' => RefundReasonCode::BillingError,
                    'reason_text' => self::CORRECTION_REFUND_REASON,
                ]);
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

        DB::transaction(function () use ($billable, $model, $newCountry, $mismatch, $resolvedById, $justification): void {
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

            $this->rememberVerifiedCountry($billable, $mismatch, $newCountry, $justification);
        });

        foreach ($invoices as $invoice) {
            $serial = $creditNoteSerialsByOriginal[(int) $invoice->id] ?? null;

            $this->issueCorrectionOnce($billable, $mismatch, $invoice, $newCountry, $serial);
        }

        CountryMismatchResolved::dispatch($billable, $mismatch, $resolvedBy, $justification);
    }

    /**
     * What resolving this mismatch will refund, per invoice: the invoices `resolve()`
     * corrects that still have something left, each with the net and gross its refund
     * pays back — the remainder at the invoice's own rates, as `RefundInvoiceService`
     * computes it for a refund without an amount.
     *
     * The portal's confirmation reads this, so the figure it names cannot drift from the
     * money `resolve()` moves: it summed the linked invoices' original gross and counted
     * every one of them, which promised a refund made before the mismatch a second time
     * and named an invoice refunded in full that nothing happens to.
     *
     * @return list<array{invoice: BillingInvoice, net: int, gross: int}>
     */
    public function refundsOnResolve(Billable $billable, BillingCountryMismatch $mismatch): array
    {
        $refunds = [];

        $invoices = $this->correctionTargets($billable, $mismatch);

        // Every invoice reads its remaining net and its credit VAT off the billable's credit
        // notes, and each instance loaded that whole set itself — one identical query per
        // linked invoice on every dashboard render. Read-only, so one load serves them all.
        BillingInvoice::shareRefundChildren($invoices);

        foreach ($invoices as $invoice) {
            $net = $invoice->remainingRefundableNet();
            if ($net <= 0) {
                continue;
            }

            $refunds[] = [
                'invoice' => $invoice,
                'net' => $net,
                'gross' => $net + $this->invoices->creditNoteVat($invoice, $net),
            ];
        }

        return $refunds;
    }

    /**
     * Retry path for an already-resolved mismatch where some Mollie re-charges
     * never confirmed. Refunds are not repeated; corrections that already
     * produced a reissue invoice are not repeated either.
     */
    private function retryPendingReissues(Billable $billable, BillingCountryMismatch $mismatch, string $newCountry): void
    {
        foreach ($this->correctionTargets($billable, $mismatch) as $invoice) {
            // Lookup the credit note serial for this original via line_items.parent_invoice_id.
            $serial = BillingInvoice::query()
                ->where('billable_type', $invoice->billable_type)
                ->where('billable_id', $invoice->billable_id)
                ->where('invoice_kind', InvoiceKind::Refund)
                ->whereJsonContains('line_items', [['parent_invoice_id' => (int) $invoice->id]])
                ->orderByDesc('id')
                ->value('serial_number');

            $this->issueCorrectionOnce(
                $billable,
                $mismatch,
                $invoice,
                $newCountry,
                is_string($serial) ? $serial : null,
            );
        }
    }

    /**
     * Issue the correction charge for one original invoice unless it is already
     * in flight or already completed, and record it in the durable ledger so a
     * later pass can tell the two apart.
     */
    private function issueCorrectionOnce(
        Billable $billable,
        BillingCountryMismatch $mismatch,
        BillingInvoice $invoice,
        string $newCountry,
        ?string $creditNoteSerial,
    ): void {
        $originalId = (int) $invoice->id;

        if (! $this->needsCorrection($billable, $originalId)) {
            return;
        }

        // Refunded in full before the mismatch was resolved (a cancellation, an unwanted
        // payment): nothing remained paid, so there is nothing to re-issue. Charged anyway,
        // the customer paid again for a supply they had been given their money back for.
        if ($this->invoices->creditedOutsideCorrection($invoice)['total'] >= $invoice->refundableBaseNet()) {
            return;
        }

        try {
            $payment = $this->invoices->issueCorrectionCharge(
                $invoice,
                $billable,
                $newCountry,
                (int) $mismatch->id,
                $creditNoteSerial,
            );

            $this->recordIssuedCorrection($billable, $originalId, (int) $mismatch->id, $newCountry, $payment);
        } catch (\Throwable $e) {
            // Do not abort: the mismatch is already Resolved locally; the failed
            // Mollie call is retried via the same resolve() entry point or
            // surfaced by the webhook failure handler.
            Log::error('Country mismatch: correction re-charge failed', [
                'mismatch_id' => $mismatch->id,
                'original_invoice_id' => $originalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The invoices a mismatch has to correct: the originals it was flagged
     * against, never the reissues produced by an earlier correction.
     *
     * `InvoiceService::createCorrectionInvoice()` anchors the reissue to the same
     * `mismatch_id` for audit traceability, so `$mismatch->invoices()` returns
     * both. Without this filter a second resolve pass would refund a valid,
     * already-paid reissue and charge the customer for it all over again.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BillingInvoice>
     */
    private function correctionTargets(Billable $billable, BillingCountryMismatch $mismatch)
    {
        $query = $mismatch->invoices()
            ->where('invoice_kind', '!=', InvoiceKind::Refund)
            ->where('amount_net', '>', 0);

        $reissuePaymentIds = $this->issuedCorrectionPaymentIds($billable);
        if ($reissuePaymentIds !== []) {
            $query->whereNotIn('mollie_payment_id', $reissuePaymentIds);
        }

        return $query->get();
    }

    /**
     * Whether a correction charge still has to be issued for an original invoice.
     *
     * False when a correction payment is in flight (pending entry) or when a
     * previously issued correction has already produced its reissue invoice.
     * True when the ledger holds an entry whose payment never settled — that is
     * the case `CountryCorrectionHandler::failed()` leaves behind and the one a
     * retry is supposed to pick up.
     */
    private function needsCorrection(Billable $billable, int $originalInvoiceId): bool
    {
        if ($this->hasPendingReissue($billable, $originalInvoiceId)) {
            return false;
        }

        $entry = $this->issuedCorrections($billable)[(string) $originalInvoiceId] ?? null;
        $paymentId = is_array($entry) ? (string) ($entry['payment_id'] ?? '') : '';

        if ($paymentId === '') {
            return true;
        }

        return ! BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists();
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function issuedCorrections(Billable $billable): array
    {
        return (array) ($billable->getBillingSubscriptionMeta()[self::ISSUED_KEY] ?? []);
    }

    /**
     * @return list<string>
     */
    private function issuedCorrectionPaymentIds(Billable $billable): array
    {
        $ids = [];
        foreach ($this->issuedCorrections($billable) as $entry) {
            $paymentId = is_array($entry) ? (string) ($entry['payment_id'] ?? '') : '';
            if ($paymentId !== '') {
                $ids[] = $paymentId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function recordIssuedCorrection(
        Billable $billable,
        int $originalInvoiceId,
        int $mismatchId,
        string $newCountry,
        mixed $payment,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $paymentId = is_object($payment) ? (string) ($payment->id ?? '') : '';
        if ($paymentId === '') {
            return;
        }

        // Locked read-modify-write, not a plain re-read: `issueCorrectionCharge()` has just
        // written its own `country_corrections` entry to this same column, and the customer
        // can pay that charge immediately — so the webhook handler, in another process, is
        // clearing that key while we are between our read and our save. Whoever saved second
        // used to discard the other's key. Losing `country_corrections` makes
        // `needsCorrection()` re-issue a charge that is already in flight, and the customer
        // pays twice for one supply period.
        $this->mutateBillingMeta($billable, function (array $meta) use ($originalInvoiceId, $mismatchId, $newCountry, $paymentId): array {
            $issued = (array) ($meta[self::ISSUED_KEY] ?? []);
            $issued[(string) $originalInvoiceId] = [
                'payment_id' => $paymentId,
                'mismatch_id' => $mismatchId,
                'country' => $newCountry,
                'issued_at' => BillingTime::nowUtc()->toIso8601String(),
            ];
            $meta[self::ISSUED_KEY] = $issued;

            return $meta;
        });
    }

    /**
     * Refuse a country the package cannot price, before a single refund goes out.
     *
     * `resolve()` refunds every affected invoice first and only then issues the
     * correction charge, and `issueCorrectionOnce()` swallows whatever that throws. A
     * country outside the EU (and outside `additional_countries`) is exactly such a
     * country — and it is reachable, because the portal modal and the admin screen offer
     * the mismatch's own payment/IP signals, which is where a UK-issued card or a Swiss
     * IP lands. The customer was then fully refunded, never re-charged, and left with a
     * `billing_country` that makes every later checkout, resubscribe and renewal throw.
     *
     * The probe goes through `VatCalculationService::calculate()` rather than
     * re-implementing its rule: it is the single owner of "is this country billable",
     * and it reads only persisted state, so this costs no external call.
     *
     * @throws \GraystackIT\MollieBilling\Exceptions\NonEuCountryException
     */
    private function assertCountryIsInvoiceable(Billable $billable, string $newCountry): void
    {
        $this->vat->calculate($newCountry, 1, $billable);
    }

    /**
     * A resolution may only pick a country the mismatch actually saw, unless the
     * operator states why. Anything else silently re-opens on the next payment
     * because `check()` keeps comparing against the unchanged signals.
     *
     * The user-declared country counts as a signal. It has to: a mismatch exists
     * precisely because the signals disagree, so `tax_country_user` never equals
     * payment or IP — and it is one of the two options both the portal modal and the
     * admin screen offer. Omitting it made every choice the UI presents throw, leaving
     * the mismatch Pending with no path to resolve it and the subscription cancelling
     * at period end.
     */
    private function assertCountryIsResolvable(
        BillingCountryMismatch $mismatch,
        string $newCountry,
        ?string $justification,
    ): void {
        $signals = array_values(array_filter([
            $this->normalize($mismatch->tax_country_user),
            $this->normalize($mismatch->tax_country_payment),
            $this->normalize($mismatch->tax_country_ip),
        ]));

        if (in_array($newCountry, $signals, true)) {
            return;
        }

        if ($justification !== null && $justification !== '') {
            return;
        }

        throw new InvalidBillingInputException(
            sprintf(
                'Country %s matches neither the payment (%s) nor the IP signal (%s) of mismatch %s. '
                .'Pass a justification to record it as verified anyway.',
                $newCountry,
                $mismatch->tax_country_payment ?? '—',
                $mismatch->tax_country_ip ?? '—',
                (string) $mismatch->id,
            ),
            'billing::exceptions.country.needs_justification',
            ['country' => $newCountry, 'payment' => $mismatch->tax_country_payment ?? '—', 'ip' => $mismatch->tax_country_ip ?? '—'],
        );
    }

    /**
     * Persist the accepted resolution together with the signals it was accepted
     * against, so `check()` stops re-flagging it. A later change in either signal
     * is new evidence and does re-flag.
     */
    private function rememberVerifiedCountry(
        Billable $billable,
        BillingCountryMismatch $mismatch,
        string $newCountry,
        ?string $justification,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        // Under the row lock for the same reason as `recordIssuedCorrection()`: this runs
        // inside `resolve()`'s transaction, which makes the write atomic but does not stop a
        // concurrent writer from having read the column before it and saving after it.
        $this->mutateBillingMeta($billable, function (array $meta) use ($mismatch, $newCountry, $justification): array {
            $meta[self::VERIFIED_KEY] = [
                'country' => $newCountry,
                'tax_country_payment' => $this->normalize($mismatch->tax_country_payment),
                'tax_country_ip' => $this->normalize($mismatch->tax_country_ip),
                'mismatch_id' => (int) $mismatch->id,
                'justification' => $justification === '' ? null : $justification,
                'verified_at' => BillingTime::nowUtc()->toIso8601String(),
            ];

            return $meta;
        });
    }

    /**
     * Whether the declared country was already confirmed against exactly these
     * signals. Any new or changed signal invalidates the confirmation.
     */
    private function isVerifiedCountry(Billable $billable, string $user, ?string $payment, ?string $ip): bool
    {
        $verified = (array) ($billable->getBillingSubscriptionMeta()[self::VERIFIED_KEY] ?? []);

        if ($verified === [] || $this->normalize($verified['country'] ?? null) !== $user) {
            return false;
        }

        return $this->normalize($verified['tax_country_payment'] ?? null) === $payment
            && $this->normalize($verified['tax_country_ip'] ?? null) === $ip;
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

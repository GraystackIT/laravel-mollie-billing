<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Exceptions\InvalidBillingInputException;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsLineAmountException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Notifications\RefundProcessedNotification;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Exceptions\RefundInProgressException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieRefusal;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue refunds against paid Mollie invoices.
 *
 * This is the primary entry point for programmatic refunds. Each public method
 * delegates to {@see refund()}, which handles the Mollie API call, credit-note
 * creation, cumulative refund tracking, events, and notifications.
 *
 * For wallet-only credits (no Mollie refund, no credit note), use
 * {@see \GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::credit()} directly.
 */
class RefundInvoiceService
{
    use MutatesBillingMeta;

    /**
     * Longest reason text sent along as refund metadata. Mollie takes 1 KB of metadata in
     * all; a longer operator note is left out (the reason code still goes).
     */
    private const METADATA_REASON_TEXT_MAX_BYTES = 500;

    /**
     * The metadata the refund being posted carries — set for the length of one
     * {@see callMollieRefund()} only, so that method's signature (an override seam) stays
     * as it is.
     *
     * @var array<string, string>|null
     */
    private ?array $refundMetadata = null;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly WalletUsageService $wallet,
    ) {
    }

    /**
     * Core refund method. Validates the invoice, calls the Mollie refund API,
     * creates a credit note, updates cumulative refund tracking on the original,
     * optionally credits wallet units, and dispatches events + notifications.
     *
     * All other public methods on this service delegate here.
     *
     * Everything up to and including the credit note happens in one transaction
     * (see {@see performRefund()}); only the admin alert for a failed Mollie call
     * is sent from here, after that transaction has rolled back.
     *
     * @param  array{amount_net?: ?int, wallet_credits?: array<string,int>, reason_code: RefundReasonCode, reason_text?: ?string, notify_user?: bool, line_items?: ?array, line_index?: ?int}  $request
     *
     * @throws InvalidRefundTargetException        Invoice is not paid or has no Mollie payment.
     * @throws RefundExceedsInvoiceAmountException  Requested amount exceeds remaining refundable balance.
     * @throws \InvalidArgumentException            Missing reason_code, or reason_text required for Other.
     */
    public function refund(BillingInvoice $invoice, array $request): BillingInvoice
    {
        $reasonCode = $request['reason_code'] ?? throw new InvalidBillingInputException('reason_code is required.', 'billing::exceptions.refund.reason_required');
        $reasonText = $request['reason_text'] ?? null;
        $walletCredits = $request['wallet_credits'] ?? [];
        $notifyUser = $request['notify_user'] ?? true;
        $lineItems = $request['line_items'] ?? null;
        $lineIndex = isset($request['line_index']) ? (int) $request['line_index'] : null;

        if ($lineIndex !== null && $lineItems !== null) {
            throw new InvalidBillingInputException('line_index and line_items cannot be combined: a refund by line builds its own credit line.', 'billing::exceptions.refund.line_and_items');
        }

        if ($reasonCode === RefundReasonCode::Other && ($reasonText === null || trim($reasonText) === '')) {
            throw new InvalidBillingInputException('reason_text is required when reason_code is Other.', 'billing::exceptions.refund.reason_text_required');
        }

        if ($invoice->status !== InvoiceStatus::Paid) {
            throw (new InvalidRefundTargetException('Only paid invoices can be refunded.'))
                ->withTranslation('billing::exceptions.refund.only_paid');
        }

        // Its own payment, or — for a settled plan-switch document — the payment its charges
        // were paid out of. That is where the money is, and where `createRefund()` already
        // posts a plan change's refund of such a document; the panel offers the refund on
        // the same condition. Refusing it here left the operator a button that could only
        // answer with an error.
        if ($invoice->refundablePaymentId() === null) {
            throw (new InvalidRefundTargetException('Invoice has no Mollie payment to refund against: it collected none, and no invoice it was settled out of did.'))
                ->withTranslation('billing::exceptions.refund.no_payment');
        }

        // The admin alert for a failed Mollie refund has to survive the rollback
        // the failure triggers: the notification is queued, and a job row
        // inserted inside the transaction disappears with it — nobody would ever
        // be told the refund failed. It is therefore collected here and sent from
        // the rethrow site below, once the transaction is gone.
        $mollieFailure = null;

        try {
            return $this->performRefund($invoice, $request, $reasonCode, $reasonText, $walletCredits, $notifyUser, $lineItems, $lineIndex, $mollieFailure);
        } catch (\Throwable $e) {
            if ($mollieFailure !== null) {
                $this->notifyAdminsOfFailedRefund(
                    $mollieFailure['billable'],
                    $mollieFailure['exception'],
                    $mollieFailure['invoice'],
                );
            }

            throw $e;
        }
    }

    /**
     * The body of {@see refund()}, in three phases with the Mollie call between them.
     *
     * The whole thing used to be one transaction: row lock, validate, call Mollie, write the
     * credit note (serial allocation, DOMPDF render, disk write), bump `refunded_net`, credit
     * wallets. Two things followed from that. Anything throwing after the Mollie call rolled
     * the credit note and the `refunded_net` increment back while the money had already left
     * — and the admin's retry click then paid it out a second time, still inside the payment
     * total, so Mollie accepted it. And the invoice row lock plus the serial range were held
     * across an HTTP round trip and a PDF render, which is what made a lock-wait timeout
     * against a concurrent refund realistic in the first place.
     *
     * So: validate under the row lock in a short transaction, call Mollie with nothing held,
     * then persist in a second short transaction. A cache lock around all three serialises
     * refunds of the same invoice — the double-click and the two-tabs case — without a
     * database transaction spanning the network.
     *
     * `$mollieFailure` is an out-parameter: when the Mollie call fails it receives the
     * billable, the exception and the invoice, so `refund()` can alert the admins. The
     * invoice has to travel with it — an operator diagnosing the failure needs the refund
     * target, and the alert's own fallback can only guess at it.
     *
     * @param  array{amount_net?: ?int, wallet_credits?: array<string,int>, reason_code: RefundReasonCode, reason_text?: ?string, notify_user?: bool, line_items?: ?array, line_index?: ?int}  $request
     * @param  array<string,int>  $walletCredits
     * @param  array{billable: Billable, exception: \Throwable, invoice: BillingInvoice}|null  $mollieFailure
     */
    private function performRefund(
        BillingInvoice $invoice,
        array $request,
        RefundReasonCode $reasonCode,
        ?string $reasonText,
        array $walletCredits,
        bool $notifyUser,
        ?array $lineItems,
        ?int $lineIndex,
        ?array &$mollieFailure,
    ): BillingInvoice {
        // Serialised per invoice with a cache lock rather than a transaction: a double-click
        // or two tabs are the realistic concurrency here, and this keeps them apart without a
        // database transaction spanning the Mollie round trip. Non-blocking on purpose — the
        // loser is told to wait rather than sitting on a worker.
        //
        // Keyed on the invoice that holds the payment. For a settled plan-switch document
        // that is the invoice it was settled out of: the refund moves that payment's money,
        // and the webhook for that payment — which books refunds it does not recognise —
        // serialises on that invoice's lock.
        $lockInvoice = InvoiceService::settlementRoot($invoice) ?? $invoice;
        $paymentId = (string) $invoice->refundablePaymentId();
        $lock = Cache::lock('billing:invoice-refund:'.$lockInvoice->id, 120);

        if (! $lock->get()) {
            throw new RefundInProgressException($invoice);
        }

        try {
            // ── Phase 1: decide, under the row lock, in a transaction of its own ──────────
            /** @var array{net:int, gross:int, description:string, billable:Billable|null, already_refunded_net:int, line_items:?array} $plan */
            $plan = DB::transaction(function () use ($invoice, $request, $reasonCode, $reasonText, $lineItems, $lineIndex): array {
                $fresh = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                // A refund by line defaults to what is left of that line, not of the invoice.
                $lineRemaining = null;
                if ($lineIndex !== null) {
                    foreach ($this->invoices->refundableLines($fresh) as $line) {
                        if ($line['index'] === $lineIndex) {
                            $lineRemaining = $line['remaining_net'];
                        }
                    }
                }

                $refundAmountNet = $request['amount_net'] ?? $lineRemaining ?? $fresh->remainingRefundableNet();

                if ($refundAmountNet <= 0) {
                    throw new InvalidBillingInputException('Refund amount must be positive.', 'billing::exceptions.refund.amount_positive');
                }

                if ($refundAmountNet > $fresh->remainingRefundableNet()) {
                    throw new RefundExceedsInvoiceAmountException(
                        "Refund of {$refundAmountNet} exceeds remaining refundable amount {$fresh->remainingRefundableNet()}."
                    );
                }

                // A refund by line: the credit line is built here, from the row just locked,
                // so the line's budget it is measured against is the committed one — not
                // what the operator's screen showed before somebody else refunded.
                if ($lineIndex !== null) {
                    $lineItems = [$this->invoices->creditLineFor($fresh, $lineIndex, $refundAmountNet)];
                }

                // Per-item VAT is the source of truth: the credit note taxes every
                // original line at its own rate, so the gross we refund at Mollie has
                // to be built the same way or bank and credit note drift apart on
                // mixed-VAT invoices.
                $refundVat = $this->invoices->creditNoteVat($fresh, $refundAmountNet, $lineItems);

                $description = 'Refund: '.$reasonCode->label();
                if ($reasonText !== null && trim($reasonText) !== '') {
                    $description .= ' — '.$reasonText;
                }

                return [
                    'net' => $refundAmountNet,
                    'gross' => $refundAmountNet + $refundVat,
                    'description' => $description,
                    'billable' => $fresh->billable()->first(),
                    // What had already been credited back when this attempt was planned —
                    // the idempotency key in Phase 2 is built on it.
                    'already_refunded_net' => (int) $fresh->effectiveRefundedNet(),
                    'line_items' => $lineItems,
                ];
            });

            // ── Phase 2: the money, with no lock and no transaction held ─────────────────
            //
            // Keyed, like every other refund the package posts. Without it a call that
            // timed out on the wire but arrived at Mollie could be repeated — by the
            // operator clicking again on the error, or by a queued retry — and paid the
            // customer a second time: Phase 3 never ran, so `refunded_net` was untouched
            // and the amount validated as fully refundable all over again.
            //
            // The key includes how much was already refunded when this attempt was
            // planned, which is exactly what separates a retry from a deliberate second
            // refund of the same amount: a retry of a failed attempt sees the same figure,
            // a genuine second refund sees the first one's increment. So Mollie replays
            // the original refund for the former and creates a new one for the latter.
            $stableKey = sprintf(
                // The payment id is in the key because the invoice's row id means nothing to
                // Mollie: two installations on one Mollie account (staging beside production,
                // a restored database) both have an invoice 1, and Mollie answers the second
                // one's refund with 400 "idempotency key used before on a different URL".
                'invoice-refund:%s:%d:%d:%d:%s',
                $paymentId,
                (int) $invoice->getKey(),
                (int) $plan['gross'],
                (int) $plan['already_refunded_net'],
                $reasonCode->value,
            );

            // …moved on by every refusal Mollie gave this same refund. A 4xx binds its key to
            // the refusal for an hour (measured 26 November 2026), so the operator's retry
            // once the balance is topped up got the refusal replayed. A timeout, a 5xx or a
            // lost answer leaves the count alone: that retry has to replay. See MollieRefusal.
            $refusals = MollieRefusal::refusalsFor(
                self::refusalRecords($plan['billable'])[(string) $invoice->getKey()] ?? null,
                $stableKey,
            );
            $idempotencyKey = MollieRefusal::key($stableKey, $refusals);

            // The reason goes to Mollie with the refund. When this call's answer is lost (or
            // Phase 3 fails), Mollie's payment webhook books the refund instead — and without
            // it booked every such refund as "synced from Mollie dashboard", reason Other.
            // A country correction's own refund then read as one made *before* the
            // correction, and the retry charged nothing again — see
            // InvoiceService::creditedOutsideCorrection().
            $this->refundMetadata = self::metadataFor($reasonCode, $reasonText);

            try {
                Mollie::setIdempotencyKey($idempotencyKey);

                $mollieRefundId = $this->callMollieRefund(
                    $paymentId,
                    $plan['gross'],
                    $plan['description'],
                    InvoiceService::normalizeCurrency($invoice->currency),
                );
            } catch (\Throwable $e) {
                Log::warning('Mollie refund failed for invoice '.$invoice->id, ['exception' => $e->getMessage()]);

                if (MollieRefusal::isDefinite($e)) {
                    $this->recordRefusal($plan['billable'], $invoice, $stableKey);
                }

                if ($plan['billable'] !== null) {
                    $mollieFailure = ['billable' => $plan['billable'], 'exception' => $e, 'invoice' => $invoice];
                }

                throw $e;
            } finally {
                $this->refundMetadata = null;

                // Whatever happened. The SDK clears the key from a *response*, and a 4xx is
                // converted to a throw before that runs — so a rejected refund left its key
                // on the shared client and the next mutating request through it carried it,
                // getting the rejection replayed as its own answer.
                InvoiceService::forgetIdempotencyKey();
            }

            // ── Phase 3: book it. The money has moved, so this stays short ───────────────
            $creditNote = DB::transaction(function () use ($invoice, $plan, $reasonCode, $reasonText, $walletCredits, $mollieRefundId): BillingInvoice {
                $fresh = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                // Snapshot what was effectively refunded BEFORE we persist the new
                // credit note — otherwise effectiveRefundedNet() would already
                // include the fresh credit note and double-count it on top of the
                // increment below. The reconciliation closes the gap for legacy
                // refunds (e.g. plan-change pro-rata) that historically wrote the
                // credit note without touching the cache column.
                $reconciledBaseNet = $fresh->effectiveRefundedNet();

                // `deferDocument`: the DOMPDF render and the disk write happen after this
                // commits, so the row lock and the serial range are not held across them.
                $creditNote = $this->invoices->createCreditNote(
                    $fresh,
                    $plan['net'],
                    $plan['line_items'],
                    $reasonText,
                    deferDocument: true,
                );
                $creditNote->refund_reason_code = $reasonCode;
                // mollie_refund_id is the only key RefundHandler dedups on. Without it
                // a webhook re-delivery (or replay tooling) treats this refund as
                // unseen and books a second credit note for the same money.
                $creditNote->line_items = self::stampRefundId((array) $creditNote->line_items, $mollieRefundId);
                // Remember which wallet units this refund already credited so a later
                // refundFully() does not hand them out a second time.
                $creditNote->line_items = self::stampWalletCredits((array) $creditNote->line_items, $walletCredits);
                $creditNote->save();

                // Clamped: between phase 1 and here another path (a plan-change prorata
                // refund, which clamps against the same figure) may have credited part of
                // this invoice. The money is out either way, so the document stands and the
                // cache column just may not exceed what was charged.
                $fresh->refunded_net = min($fresh->refundableBaseNet(), $reconciledBaseNet + $plan['net']);
                $fresh->save();

                if ($plan['billable'] !== null) {
                    // Wallet credits stay inside: units handed out without the credit note
                    // that justifies them would be free service.
                    foreach ($walletCredits as $usageType => $units) {
                        $this->wallet->credit($plan['billable'], (string) $usageType, (int) $units);
                    }
                }

                return $creditNote;
            });

            if ($refusals > 0) {
                $this->forgetRefusals($plan['billable'], $invoice);
            }

            // ── Phase 4: the document, then the announcements ────────────────────────────
            // Both outside every transaction. The money has left Mollie by now, so anything
            // throwing here — a render failure, an app listener on InvoiceRefunded pushing to
            // a CRM — must not be able to undo the booking. It used to roll back the credit
            // note and the `refunded_net` increment while the customer kept the refund, and
            // the admin's retry click then paid it out a second time.
            //
            // Nor reach the caller: the refund succeeded, and a throw here told the admin
            // screen and SupersededRenewalRefund it had failed — the operator retried under a
            // new key (`already_refunded_net` had moved) and paid again, and the customer was
            // never told. Each announcement is isolated, so one failing does not skip the next.
            $this->invoices->issueCreditNoteDocument($creditNote, $invoice->refresh());

            if ($plan['billable'] !== null) {
                InvoiceService::announceBooked(new InvoiceRefunded($plan['billable'], $invoice, $creditNote, $request));

                if ($notifyUser) {
                    try {
                        $recipients = MollieBilling::notifyBillingAdmins($plan['billable']);

                        if (! empty($recipients)) {
                            Notification::send($recipients, MollieBilling::resolveNotification(RefundProcessedNotification::class, $plan['billable'], $creditNote));
                        }
                    } catch (\Throwable $e) {
                        rescue(static fn () => report($e), null, false);
                    }
                }
            }

            return $creditNote;
        } finally {
            $lock->release();
        }
    }

    /**
     * `subscription_meta` key: invoice id => the refusal record of its refund in progress
     * (`['key' => stable key, 'refusals' => n, 'at' => …]`, {@see MollieRefusal}).
     */
    public const REFUSALS_META_KEY = 'refund_key_refusals';

    /** A record older than this is dropped on the next write — Mollie keeps a key an hour. */
    private const REFUSAL_RECORD_TTL_HOURS = 24;

    /** @return array<string, mixed> */
    private static function refusalRecords(?Billable $billable): array
    {
        return $billable === null ? [] : (array) ($billable->getBillingSubscriptionMeta()[self::REFUSALS_META_KEY] ?? []);
    }

    /**
     * Count a definite refusal of this refund where the next attempt reads it.
     *
     * Never throws: the refusal itself is what the caller has to see. Without a billable to
     * keep it on, the next attempt keeps the old key — refused again inside the hour, as
     * before this rule, and never paid twice.
     */
    private function recordRefusal(?Billable $billable, BillingInvoice $invoice, string $stableKey): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        rescue(function () use ($billable, $invoice, $stableKey): void {
            $this->mutateBillingMeta($billable, static function (array $meta) use ($invoice, $stableKey): array {
                $cutoff = BillingTime::nowUtc()->subHours(self::REFUSAL_RECORD_TTL_HOURS);
                $records = array_filter(
                    (array) ($meta[self::REFUSALS_META_KEY] ?? []),
                    static fn ($record): bool => is_array($record) && BillingTime::toUtc((string) ($record['at'] ?? '1970-01-01'))->greaterThan($cutoff),
                );

                $id = (string) $invoice->getKey();
                $records[$id] = MollieRefusal::recordRefusal($records[$id] ?? null, $stableKey);
                $meta[self::REFUSALS_META_KEY] = $records;

                return $meta;
            });
        }, null, true);
    }

    /** The refund went through: its refusals are history. */
    private function forgetRefusals(?Billable $billable, BillingInvoice $invoice): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        rescue(function () use ($billable, $invoice): void {
            $this->mutateBillingMeta($billable, static function (array $meta) use ($invoice): ?array {
                $records = (array) ($meta[self::REFUSALS_META_KEY] ?? []);

                if (! array_key_exists((string) $invoice->getKey(), $records)) {
                    return null;
                }

                unset($records[(string) $invoice->getKey()]);

                if ($records === []) {
                    unset($meta[self::REFUSALS_META_KEY]);
                } else {
                    $meta[self::REFUSALS_META_KEY] = $records;
                }

                return $meta;
            });
        }, null, true);
    }

    /**
     * Alert the configured admins that a Mollie refund call failed.
     *
     * Must be called with no transaction open: the notification is queued, so a
     * job row written inside the transaction the failure rolls back would vanish
     * together with it.
     */
    private function notifyAdminsOfFailedRefund(Billable $billable, \Throwable $exception, ?BillingInvoice $invoice = null): void
    {
        $recipients = MollieBilling::notifyAdmin();

        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, MollieBilling::resolveNotification(
            AdminRefundFailedNotification::class,
            $billable,
            $exception,
            $invoice,
        ));
    }

    /**
     * Refund the full remaining amount of an invoice. For overage invoices, the
     * wallet units that have not been credited back by an earlier partial refund
     * are credited automatically — the money is capped by
     * {@see BillingInvoice::remainingRefundableNet()}, the units by what previous
     * refunds already returned.
     */
    public function refundFully(BillingInvoice $invoice, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        $walletCredits = [];

        if ($invoice->invoice_kind === InvoiceKind::Overage) {
            foreach ((array) $invoice->line_items as $item) {
                $type = $item['code'] ?? $item['type'] ?? null;
                $qty = (int) ($item['quantity'] ?? 0);

                if ($type !== null && $qty > 0) {
                    $walletCredits[$type] = ($walletCredits[$type] ?? 0) + $qty;
                }
            }

            $alreadyCredited = $this->alreadyCreditedUnits($invoice);

            foreach ($walletCredits as $type => $units) {
                $remaining = $units - (int) ($alreadyCredited[$type] ?? 0);

                if ($remaining > 0) {
                    $walletCredits[$type] = $remaining;
                } else {
                    unset($walletCredits[$type]);
                }
            }
        }

        return $this->refund($invoice, [
            'amount_net' => null,
            'wallet_credits' => $walletCredits,
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    /**
     * Refund a specific net amount. Use this for any partial refund where
     * wallet units do not need to be credited back (e.g. billing errors,
     * goodwill credits, or overage money-only refunds).
     *
     * @param  array<int, array<string, mixed>>|null  $lineItems  Custom line items for the credit note.
     */
    public function refundPartially(BillingInvoice $invoice, int $amountNet, RefundReasonCode $reason, ?string $reasonText = null, ?array $lineItems = null): BillingInvoice
    {
        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'wallet_credits' => [],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
            'line_items' => $lineItems,
        ]);
    }

    /**
     * Refund part or all of ONE line of an invoice — the addon that was never used, the
     * seats booked by mistake.
     *
     * The credit note carries one line pointing at that original line
     * (`parent_line_item_index`), taxed at its rate and inside its remaining VAT. The
     * amount may not exceed what is left of the line ({@see InvoiceService::refundableLines()}),
     * which is checked against the row locked for the refund. Discount lines are folded
     * into the line they reduced, so a line refunds what was actually paid for it.
     *
     * Money only: an overage line gives no wallet units back — that is
     * {@see refundOverageUnits()}.
     *
     * @throws InvalidRefundTargetException      the index names no line that can be refunded on its own
     * @throws RefundExceedsLineAmountException  more than is left of the line
     */
    public function refundLine(BillingInvoice $invoice, int $lineIndex, int $amountNet, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'line_index' => $lineIndex,
            'wallet_credits' => [],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    /**
     * Refund a specific number of overage units for a single usage type.
     * The refund amount is computed from the unit price on the invoice.
     * The corresponding wallet units are credited back automatically.
     *
     * @throws InvalidRefundTargetException  Invoice is not an overage invoice or usage type not found.
     */
    public function refundOverageUnits(BillingInvoice $invoice, string $usageType, int $units, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        if ($invoice->invoice_kind !== InvoiceKind::Overage) {
            throw (new InvalidRefundTargetException('refundOverageUnits only applies to overage invoices.'))
                ->withTranslation('billing::exceptions.refund.only_overage');
        }

        $this->assertRefundableUnits($invoice, [$usageType => $units]);

        $unitPrice = $this->unitPriceFor($invoice, $usageType);

        return $this->refund($invoice, [
            'amount_net' => $unitPrice * $units,
            'wallet_credits' => [$usageType => $units],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    /**
     * Refund overage units across multiple usage types in a single call.
     * The refund amount is the sum of (unit price * units) per type.
     * All corresponding wallet units are credited back automatically.
     *
     * @param  array<string,int>  $unitsPerType  Map of usage type => units to refund.
     *
     * @throws InvalidRefundTargetException  Invoice is not an overage invoice or usage type not found.
     */
    public function refundOverageUnitsBulk(BillingInvoice $invoice, array $unitsPerType, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        if ($invoice->invoice_kind !== InvoiceKind::Overage) {
            throw (new InvalidRefundTargetException('refundOverageUnitsBulk only applies to overage invoices.'))
                ->withTranslation('billing::exceptions.refund.only_overage');
        }

        $this->assertRefundableUnits($invoice, $unitsPerType);

        $amountNet = 0;
        foreach ($unitsPerType as $type => $units) {
            $amountNet += $this->unitPriceFor($invoice, (string) $type) * (int) $units;
        }

        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'wallet_credits' => $unitsPerType,
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    /**
     * Every requested unit count must be positive and within what the invoice billed for
     * that type, less what earlier refunds already credited back.
     *
     * The same map prices the refund and credits the wallet, and the wallet ignores a
     * non-positive quantity — so a negative entry for one type used to pay for a positive
     * one on another, and the money cap (which only sees the sum) let it through. The
     * per-type cap closes the other half: the invoice's total covers 2000 units of one
     * type when two types were billed at 1000 each.
     *
     * @param  array<string, int>  $unitsPerType
     */
    private function assertRefundableUnits(BillingInvoice $invoice, array $unitsPerType): void
    {
        $billed = [];
        foreach ((array) $invoice->line_items as $item) {
            $type = $item['code'] ?? $item['type'] ?? null;
            if ($type !== null) {
                $billed[(string) $type] = ($billed[(string) $type] ?? 0) + (int) ($item['quantity'] ?? 0);
            }
        }

        $credited = $this->alreadyCreditedUnits($invoice);

        foreach ($unitsPerType as $type => $units) {
            if (! is_int($units) || $units <= 0) {
                throw new InvalidBillingInputException("Units to refund for {$type} must be a positive integer.", 'billing::exceptions.refund.units_positive', ['type' => (string) $type]);
            }

            $refundable = ($billed[(string) $type] ?? 0) - (int) ($credited[(string) $type] ?? 0);

            if ($units > $refundable) {
                throw new InvalidBillingInputException(
                    "Cannot refund {$units} units of {$type}: only {$refundable} remain refundable on this invoice.",
                    'billing::exceptions.refund.units_exceed',
                    ['units' => $units, 'type' => (string) $type, 'remaining' => max(0, $refundable)],
                );
            }
        }
    }

    private function unitPriceFor(BillingInvoice $invoice, string $usageType): int
    {
        foreach ((array) $invoice->line_items as $item) {
            $type = $item['code'] ?? $item['type'] ?? null;
            if ($type === $usageType) {
                return (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);
            }
        }

        throw (new InvalidRefundTargetException("Usage type {$usageType} not found in invoice line items."))
            ->withTranslation('billing::exceptions.refund.usage_type_not_found', ['type' => $usageType]);
    }

    /**
     * Wallet units already credited back by earlier refunds of this invoice,
     * read from the `wallet_credits` snapshot the refund path writes onto the
     * credit note's line items.
     *
     * @return array<string, int>
     */
    private function alreadyCreditedUnits(BillingInvoice $invoice): array
    {
        $credited = [];

        $creditNotes = BillingInvoice::query()
            ->where('billable_type', $invoice->billable_type)
            ->where('billable_id', $invoice->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($creditNotes as $creditNote) {
            foreach ((array) ($creditNote->line_items ?? []) as $line) {
                if ((int) ($line['parent_invoice_id'] ?? 0) !== (int) $invoice->getKey()) {
                    continue;
                }

                foreach ((array) ($line['wallet_credits'] ?? []) as $type => $units) {
                    $credited[(string) $type] = ($credited[(string) $type] ?? 0) + (int) $units;
                }
            }
        }

        return $credited;
    }

    /**
     * Write the Mollie refund id onto every credit-note line that does not carry
     * one yet.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    private static function stampRefundId(array $lineItems, ?string $refundId): array
    {
        if ($refundId === null) {
            return $lineItems;
        }

        foreach ($lineItems as $index => $line) {
            if (($line['mollie_refund_id'] ?? null) === null) {
                $lineItems[$index]['mollie_refund_id'] = $refundId;
            }
        }

        return $lineItems;
    }

    /**
     * Record the credited wallet units on the first credit-note line. They are
     * an attribute of the refund as a whole, so one line carries them.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, int>  $walletCredits
     * @return array<int, array<string, mixed>>
     */
    private static function stampWalletCredits(array $lineItems, array $walletCredits): array
    {
        if ($walletCredits === [] || $lineItems === []) {
            return $lineItems;
        }

        $first = array_key_first($lineItems);
        $lineItems[$first]['wallet_credits'] = array_map('intval', $walletCredits);

        return $lineItems;
    }

    /**
     * Post the refund at Mollie and return its refund id.
     *
     * `$currency` must be the currency of the ORIGINAL payment — Mollie rejects
     * anything else, so it cannot be read from the config: that value changes
     * over time while historical payments keep the currency they were made in.
     */
    protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
    {
        $refund = MollieCall::send(new CreatePaymentRefundRequest(
            paymentId: $paymentId,
            description: $description,
            amount: new Money($currency, InvoiceService::molliePrice($grossCents, $currency)),
            metadata: $this->refundMetadata,
        ));

        return is_object($refund) ? ($refund->id ?? null) : null;
    }

    /**
     * The refund metadata that lets `RefundHandler` book a refund of ours under its own
     * reason when it is the one to book it ({@see \GraystackIT\MollieBilling\Services\Webhook\RefundHandler::reasonOf()}).
     *
     * @return array<string, string>
     */
    private static function metadataFor(RefundReasonCode $reasonCode, ?string $reasonText): array
    {
        $metadata = ['reason_code' => $reasonCode->value];
        $reasonText = $reasonText === null ? '' : trim($reasonText);

        if ($reasonText !== '' && strlen($reasonText) <= self::METADATA_REASON_TEXT_MAX_BYTES) {
            $metadata['reason_text'] = $reasonText;
        }

        return $metadata;
    }
}

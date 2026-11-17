<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\RefundReversed;
use GraystackIT\MollieBilling\Exceptions\RefundInProgressException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\MolliePaymentCredits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class RefundHandler
{
    /**
     * Refund statuses that mean the money did not reach the customer.
     *
     * `queued`, `pending`, `processing` and `refunded` are all booked: a refund is booked
     * when it is created (RefundInvoiceService and the plan-change path do exactly that),
     * and Mollie is not guaranteed to call again for every step in between. What makes
     * that safe is the other half — a booked refund that ends up here is reversed.
     */
    private const NOT_PAID_OUT = ['failed', 'canceled'];

    private const UNKNOWN = 'unknown';

    private const BOOKED = 'booked';

    private const REVERSED = 'reversed';

    public function __construct(
        protected readonly InvoiceService $salesInvoiceService,
    ) {
    }

    /**
     * Sync refunds initiated via the Mollie dashboard into local credit notes.
     *
     * Each Mollie refund has a unique ID (e.g. re_xxx). We store it in the
     * credit note's line_items so we can deduplicate by refund ID rather than
     * by amount (which would fail for multiple partial refunds of the same value).
     *
     * The refund amount arrives as a gross decimal; the local net/VAT split is
     * derived from the original invoice's line composition
     * (see {@see InvoiceService::creditNetForGross()}), never from one line's
     * VAT rate applied to the whole gross.
     */
    public function handle(object $payment, Billable $billable): void
    {
        // Rethrown, not swallowed. `refunds()` is a live HTTP call against Mollie, and
        // returning here let `__invoke()` record the outcome signature — which already
        // encodes the refunded total — as processed and answer 200. Mollie then never
        // calls again for that refund state: no credit note, `refunded_net` still 0, the
        // invoice reading as fully paid, and the VAT on the refund declared in the OSS
        // export. Nothing reconciles dashboard refunds afterwards. Both handlers here are
        // idempotent (dedup on the refund/chargeback id), so a 503 and a re-delivery are
        // strictly better than a lie.
        //
        // Every page, through MollieCall — not `$payment->refunds()`, which went past the
        // one door and read the first page only.
        try {
            $refunds = app(MolliePaymentCredits::class)->refunds($payment);
        } catch (\Throwable $e) {
            Log::error('Could not load refunds for a payment that reports some', [
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $original = BillingInvoice::query()
            ->where('mollie_payment_id', (string) $payment->id)
            ->first();

        if ($original === null) {
            return;
        }

        // The same lock RefundInvoiceService holds from deciding a refund to booking it.
        // That service creates the refund at Mollie and stamps its id onto the credit note
        // a moment later — and Mollie calls this webhook as soon as the refund exists. In
        // that window the id is not on record yet, so this handler booked a "synced from
        // dashboard" credit note and the service then booked a second one for the same
        // money. Refusing here makes the delivery fail and Mollie re-deliver once the
        // service has committed, when the dedup below recognises the refund.
        $lock = Cache::lock('billing:invoice-refund:'.$original->id, 120);

        if (! $lock->get()) {
            throw new RefundInProgressException($original);
        }

        try {
            $this->syncRefunds($refunds, $original, $billable);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  iterable<int, object>  $refunds
     */
    private function syncRefunds(iterable $refunds, BillingInvoice $original, Billable $billable): void
    {
        foreach ($refunds as $refund) {
            $refundId = (string) ($refund->id ?? '');
            if ($refundId === '') {
                continue;
            }

            $status = strtolower((string) ($refund->status ?? ''));
            $booking = $this->bookingState($original->billable_type, $original->billable_id, $refundId);

            // A refund that did not pay out is not a refund. Every entry Mollie lists used
            // to be booked whatever its status, so a refund the bank bounced (`failed`) or
            // one withdrawn while still queued (`canceled`) got a credit note for money
            // that never left: 5.00 returned and 5.95 failed booked 10.95 of credit,
            // revenue and VAT under-declared, and `refunded_net` then refused the retry of
            // the very refund that had failed.
            if (in_array($status, self::NOT_PAID_OUT, true)) {
                // Booked earlier, while it was still on its way — by this handler, by
                // RefundInvoiceService or by a plan change, all of which book at creation.
                // The money is back, so the credit comes out of the books again.
                if ($booking === self::BOOKED) {
                    $this->reverse($refundId, $status, $original, $billable);
                }

                continue;
            }

            if ($booking !== self::UNKNOWN) {
                continue;
            }

            $refundAmountCents = InvoiceService::mollieAmountToMinor(
                $refund->amount->value ?? null,
                $refund->amount->currency ?? $original->currency,
            );

            // Mollie returns a GROSS amount; the net/VAT split is ours. Backing
            // the net out of that gross with line[0]'s rate under-credits every
            // invoice whose lines are not uniformly taxed and leaves
            // `refunded_net` short of `amount_net` forever, so the net is
            // resolved against the invoice's own line composition instead —
            // and clamped to what is still refundable.
            $netAmount = $this->salesInvoiceService->creditNetForGross($original, $refundAmountCents);

            if ($netAmount <= 0) {
                Log::warning('Mollie refund carries no creditable net', [
                    'invoice_id' => $original->id,
                    'mollie_refund_id' => $refundId,
                    'refund_gross' => $refundAmountCents,
                    'remaining_refundable_net' => $original->remainingRefundableNet(),
                ]);

                continue;
            }

            // Snapshot the effective refunded net BEFORE the credit note exists:
            // afterwards effectiveRefundedNet() already derives this refund from
            // the new credit note's lines and the increment below would count it
            // twice. Starting from the effective value (rather than the cached
            // column) reconciles invoices whose credit notes were written by a
            // legacy path that never touched the cache.
            $reconciledBaseNet = $original->effectiveRefundedNet();

            // The refund id goes in BEFORE the row is committed. Stamping it in a second
            // save left a window — the note was already persisted and the synchronous
            // `CreditNoteIssued` event had run — in which a throwing app listener or a DB
            // blip produced a 500, and the re-delivery then found no stamp and wrote a
            // SECOND credit note for the same refund: `refunded_net` double-booked, local
            // VAT under-declared, later legitimate refunds refused as "exceeds remaining".
            // Every line is stamped, not just the first: a mixed-rate note has one line
            // per credited original line and the dedup check scans them all.
            $creditNote = $this->salesInvoiceService->createCreditNote(
                $original,
                $netAmount,
                externalReference: ['key' => 'mollie_refund_id', 'value' => $refundId],
            );

            $creditNote->refund_reason_code = RefundReasonCode::Other;
            $creditNote->refund_reason_text = 'synced from Mollie dashboard';
            $creditNote->save();

            $original->refunded_net = $reconciledBaseNet + $netAmount;
            $original->save();

            event(new InvoiceRefunded($billable, $original, $creditNote, [
                'reason_code' => RefundReasonCode::Other,
                'reason_text' => 'synced from Mollie dashboard',
                'mollie_refund_id' => $refundId,
            ]));
        }
    }

    /**
     * Undo the credit note of a refund Mollie now reports as not paid out.
     */
    private function reverse(string $refundId, string $status, BillingInvoice $original, Billable $billable): void
    {
        $reason = sprintf(
            'Reversal: Mollie reports refund %s as %s — the amount was not paid out',
            $refundId,
            $status,
        );

        $reversal = $this->salesInvoiceService->reverseBookedCredit($billable, 'mollie_refund_id', $refundId, $reason, $original);

        if ($reversal === null) {
            return;
        }

        $reversal->refund_reason_code = RefundReasonCode::Other;
        $reversal->save();

        Log::warning('A booked refund did not pay out — its credit note was reversed', [
            'invoice_id' => $original->id,
            'reversal_id' => $reversal->id,
            'mollie_refund_id' => $refundId,
            'status' => $status,
        ]);

        event(new RefundReversed($billable, $reversal, $refundId, $status));

        // Somebody has to act on this: the customer was told about a refund that never
        // arrived, and any wallet units handed back with it are still in the wallet.
        $admins = MollieBilling::notifyAdmin();

        if (! empty($admins)) {
            Notification::send($admins, MollieBilling::resolveNotification(
                AdminRefundFailedNotification::class,
                $billable,
                new \RuntimeException($reason),
                $original,
            ));
        }
    }

    /**
     * Whether any booked refund of this payment is still standing — booked and not
     * reversed.
     *
     * The controller asks before deciding there is nothing to sync: once a refund fails,
     * Mollie's `amountRefunded` drops back (to zero, if it was the only one), and "no
     * refunds on the payment" was exactly the delivery that has to undo a booking.
     */
    public function hasStandingRefundBookings(object $payment): bool
    {
        $paymentId = (string) ($payment->id ?? '');

        if ($paymentId === '') {
            return false;
        }

        $original = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();

        if ($original === null) {
            return false;
        }

        // Its own lines, and those of documents settled out of its payment (the
        // plan-switch invoice refunds through its root).
        $invoiceIds = BillingInvoice::query()
            ->where('settled_by_invoice_id', $original->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->push((int) $original->getKey())
            ->all();

        $state = [];

        foreach ($this->creditNotes($original->billable_type, $original->billable_id) as $note) {
            foreach ((array) ($note->line_items ?? []) as $line) {
                $refundId = $line['mollie_refund_id'] ?? null;

                if (! is_string($refundId) || $refundId === ''
                    // A credit note written from custom line items names no parent; it
                    // counts, since asking Mollie once too often costs nothing.
                    || ! in_array((int) ($line['parent_invoice_id'] ?? 0), [0, ...$invoiceIds], true)
                ) {
                    continue;
                }

                $state[$refundId] = ($state[$refundId] ?? false) || BillingInvoice::creditSign($line) === -1;
            }
        }

        return in_array(false, $state, true);
    }

    /**
     * Idempotency check: does a refund invoice exist for this billable that carries
     * this mollie_refund_id in any of its line_items?
     */
    public function refundIdAlreadyPersisted(string $billableType, mixed $billableId, string $refundId): bool
    {
        return $this->bookingState($billableType, $billableId, $refundId) !== self::UNKNOWN;
    }

    /**
     * What the books know about a Mollie refund: nothing, a credit note, or a credit note
     * that has since been reversed.
     */
    private function bookingState(string $billableType, mixed $billableId, string $refundId): string
    {
        $state = self::UNKNOWN;

        foreach ($this->creditNotes($billableType, $billableId) as $note) {
            foreach ((array) ($note->line_items ?? []) as $line) {
                if (($line['mollie_refund_id'] ?? null) !== $refundId) {
                    continue;
                }

                if (BillingInvoice::creditSign($line) === -1) {
                    return self::REVERSED;
                }

                $state = self::BOOKED;
            }
        }

        return $state;
    }

    /**
     * @return \Illuminate\Support\Collection<int, BillingInvoice>
     */
    private function creditNotes(string $billableType, mixed $billableId): \Illuminate\Support\Collection
    {
        return BillingInvoice::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);
    }
}

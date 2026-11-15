<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\ChargebackReversed;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Books a chargeback: money the customer's bank pulled back after the payment had
 * already settled.
 *
 * Chargebacks were entirely invisible. In the v2 payments API a chargeback leaves the
 * payment's status at `paid` and changes only `amountChargedBack` / `_links.chargebacks`
 * — so the re-delivery's outcome signature was byte-identical to the `…:paid` already on
 * record and the controller deduped it away with a 200. The result: the money was gone
 * while the invoice stayed fully paid (output VAT and the OSS return over-declared by
 * that amount), no credit note existed, the billable stayed Active and the customer kept
 * access indefinitely. For SEPA Direct Debit this is not exotic — the payer has an
 * eight-week, no-questions right to reverse the collection.
 *
 * Deliberately mirrors {@see RefundHandler} rather than reinventing the bookkeeping: a
 * chargeback is a refund the bank made on the customer's behalf, so the same credit-note
 * plumbing applies. Two things differ. The dedup key is the chargeback id (Mollie can
 * issue several against one payment, and a later reversal arrives as its own delivery),
 * and the subscription is put into `PastDue`: money that was collected and taken back is
 * a failed payment, and the past-due ladder already owns what follows — dunning, and the
 * auto-cancel after `past_due_max_days`. Cancelling the Mollie subscription here as well
 * would take that decision away from the ladder and from the operator.
 */
class ChargebackHandler
{
    private const UNKNOWN = 'unknown';

    private const BOOKED = 'booked';

    private const REVERSED = 'reversed';

    public function __construct(
        protected readonly InvoiceService $salesInvoiceService,
    ) {
    }

    public function handle(object $payment, Billable $billable): void
    {
        $chargebacks = [];

        try {
            /** @phpstan-ignore-next-line — SDK magic. */
            $chargebacks = $payment->chargebacks();
        } catch (\Throwable $e) {
            // Rethrown — see RefundHandler. Returning here made `__invoke()` answer 200
            // for a delivery that booked nothing, and a chargeback that goes unnoticed
            // leaves the customer subscribed on money the bank already pulled back.
            Log::error('Could not load chargebacks for a payment that reports some', [
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $original = BillingInvoice::query()
            ->where('mollie_payment_id', (string) $payment->id)
            ->first();

        if ($original === null) {
            // No invoice means nothing was booked for this payment in the first place,
            // so there is nothing to credit — but the money did move, so say so.
            Log::error('Chargeback on a payment with no local invoice', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            ]);

            return;
        }

        $booked = 0;

        foreach ($chargebacks as $chargeback) {
            $chargebackId = (string) ($chargeback->id ?? '');

            if ($chargebackId === '') {
                continue;
            }

            $booking = $this->bookingState($original->billable_type, $original->billable_id, $chargebackId);

            // A reversed chargeback: the dispute was won and the money is back. Never
            // booked if it is first seen in that state; taken out of the books again if
            // it was booked while it stood.
            if (! empty($chargeback->reversedAt ?? null)) {
                if ($booking === self::BOOKED) {
                    $this->reverse($chargebackId, $original, $billable);
                }

                continue;
            }

            if ($booking !== self::UNKNOWN) {
                continue;
            }

            $grossCents = InvoiceService::mollieAmountToMinor(
                $chargeback->amount->value ?? null,
                $chargeback->amount->currency ?? $original->currency,
            );

            // Mollie reports a chargeback's amount as a NEGATIVE gross (it is money
            // leaving), while the credit-note machinery works in positive magnitudes.
            $grossCents = abs($grossCents);

            // Same net/VAT resolution as a refund: against the invoice's own line
            // composition, never one line's rate applied to the whole gross.
            $netAmount = $this->salesInvoiceService->creditNetForGross($original, $grossCents);

            if ($netAmount <= 0) {
                Log::warning('Chargeback carries no creditable net', [
                    'invoice_id' => $original->id,
                    'mollie_chargeback_id' => $chargebackId,
                    'chargeback_gross' => $grossCents,
                    'remaining_refundable_net' => $original->remainingRefundableNet(),
                ]);

                continue;
            }

            // Snapshot before the credit note exists — afterwards effectiveRefundedNet()
            // already derives this amount from the new note's lines. Same reasoning as
            // RefundHandler.
            $reconciledBaseNet = $original->effectiveRefundedNet();

            // Stamped before the row is committed — same reasoning as RefundHandler: the
            // dedup key has to exist by the time the synchronous CreditNoteIssued event
            // can throw, or a re-delivery writes a second note for the same chargeback.
            $creditNote = $this->salesInvoiceService->createCreditNote(
                $original,
                $netAmount,
                externalReference: ['key' => 'mollie_chargeback_id', 'value' => $chargebackId],
            );

            $creditNote->refund_reason_code = RefundReasonCode::Chargeback;
            $creditNote->refund_reason_text = 'chargeback reported by Mollie';
            $creditNote->save();

            $original->refunded_net = $reconciledBaseNet + $netAmount;
            $original->save();

            $booked++;

            event(new InvoiceRefunded($billable, $original, $creditNote, [
                'reason_code' => RefundReasonCode::Chargeback,
                'reason_text' => 'chargeback reported by Mollie',
                'mollie_chargeback_id' => $chargebackId,
            ]));
        }

        if ($booked === 0) {
            return;
        }

        $this->markPastDue($billable, $original, $booked);
    }

    /**
     * Money collected and then taken back is a failed payment, so the billable enters
     * the same state a failed renewal produces — and the existing ladder takes it from
     * there: dunning, then the auto-cancel after `past_due_max_days`.
     *
     * A billable that is already PastDue keeps its original `past_due_since`: the clock
     * belongs to the episode, and restarting it on a second chargeback would extend the
     * grace period every time the bank pulled more money back.
     */
    protected function markPastDue(Billable $billable, BillingInvoice $invoice, int $chargebacks): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        // Only from a running paid subscription. A Cancelled or Expired row has nothing
        // left to dun, and forcing PastDue there would resurrect an ended subscription
        // into the auto-cancel ladder.
        $status = $billable->getBillingSubscriptionStatus();

        if (in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::PastDue], true)) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['past_due_since'] ??= BillingTime::nowUtc()->toIso8601String();
            $meta['payment_failure'] = [
                'reason' => 'chargeback',
                'invoice_id' => (int) $invoice->id,
                'at' => BillingTime::nowUtc()->toIso8601String(),
            ];

            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::PastDue,
                'subscription_meta' => $meta,
            ])->save();

            // The same transition a failed renewal produces, announced the same way.
            // `SubscriptionPaymentHandler::failed()` dispatches PaymentFailed for it, so an
            // app that hangs its dunning off that event saw renewal failures and silently
            // missed every chargeback-driven episode — while the past-due ladder here runs
            // to auto-cancel regardless.
            //
            // Dispatched on a repeat chargeback too, even though the status does not move:
            // a second reversal is a second failed payment, and `past_due_since` staying
            // put is about the grace clock, not about the event.
            event(new PaymentFailed($billable, (string) $invoice->mollie_payment_id, 'chargeback'));
        }

        Log::warning('Chargeback booked — billable is past due', [
            'billable_id' => $billable->getKey(),
            'invoice_id' => (int) $invoice->id,
            'chargebacks' => $chargebacks,
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            'A payment was charged back — a credit note was issued and the subscription is past due',
            [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'invoice_id' => (int) $invoice->id,
                'mollie_payment_id' => (string) $invoice->mollie_payment_id,
                'chargebacks' => $chargebacks,
            ],
        ));
    }

    /**
     * Undo a chargeback's credit note, and the dunning episode it started.
     *
     * The status is restored only when this chargeback is what the billable is past due
     * for — the marker `markPastDue()` wrote still names it. A renewal that failed in the
     * meantime owns the episode by then, and the payment that stands again is not that one.
     */
    protected function reverse(string $chargebackId, BillingInvoice $original, Billable $billable): void
    {
        $reversal = $this->salesInvoiceService->reverseBookedCredit(
            $billable,
            'mollie_chargeback_id',
            $chargebackId,
            sprintf('Reversal: chargeback %s was reversed — the payment stands', $chargebackId),
            $original,
        );

        if ($reversal === null) {
            return;
        }

        $reversal->refund_reason_code = RefundReasonCode::Chargeback;
        $reversal->save();

        $restored = false;

        if ($billable instanceof Model && $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue) {
            $failure = $billable->getBillingSubscriptionMeta()['payment_failure'] ?? null;

            if (is_array($failure)
                && ($failure['reason'] ?? null) === 'chargeback'
                && (int) ($failure['invoice_id'] ?? 0) === (int) $original->id
            ) {
                $billable->forceFill([
                    'subscription_status' => SubscriptionStatus::Active,
                    'subscription_meta' => SubscriptionMetaHygiene::withoutDunningEpisode($billable->getBillingSubscriptionMeta()),
                ])->save();

                $restored = true;
            }
        }

        Log::warning('Chargeback reversed — its credit note was taken out of the books again', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'invoice_id' => (int) $original->id,
            'reversal_id' => (int) $reversal->id,
            'mollie_chargeback_id' => $chargebackId,
            'subscription_restored' => $restored,
        ]);

        event(new ChargebackReversed($billable, $original, $reversal, $chargebackId));

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            $restored
                ? 'A chargeback was reversed — its credit note was reversed and the subscription is active again'
                : 'A chargeback was reversed — its credit note was reversed; the subscription status was left as it is',
            [
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'invoice_id' => (int) $original->id,
                'mollie_payment_id' => (string) $original->mollie_payment_id,
                'mollie_chargeback_id' => $chargebackId,
            ],
        ));
    }

    /**
     * Whether a chargeback of this payment is still standing in the books — booked and
     * not reversed. The controller asks before deciding there is nothing to look at.
     */
    public function hasStandingChargebackBookings(object $payment): bool
    {
        $paymentId = (string) ($payment->id ?? '');

        if ($paymentId === '') {
            return false;
        }

        $original = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();

        if ($original === null) {
            return false;
        }

        $state = [];

        foreach ($this->creditNotes($original->billable_type, $original->billable_id) as $note) {
            foreach ((array) ($note->line_items ?? []) as $line) {
                $id = $line['mollie_chargeback_id'] ?? null;

                if (! is_string($id) || $id === '' || (int) ($line['parent_invoice_id'] ?? 0) !== (int) $original->getKey()) {
                    continue;
                }

                $state[$id] = ($state[$id] ?? false) || BillingInvoice::creditSign($line) === -1;
            }
        }

        return in_array(false, $state, true);
    }

    /**
     * Whether a credit note for this chargeback id already exists.
     *
     * Keyed on the id rather than the amount, because Mollie can report several
     * chargebacks of the same value against one payment and re-delivers each of them.
     */
    public function chargebackAlreadyPersisted(string $billableType, mixed $billableId, string $chargebackId): bool
    {
        return $this->bookingState($billableType, $billableId, $chargebackId) !== self::UNKNOWN;
    }

    private function bookingState(string $billableType, mixed $billableId, string $chargebackId): string
    {
        $state = self::UNKNOWN;

        foreach ($this->creditNotes($billableType, $billableId) as $note) {
            foreach ((array) ($note->line_items ?? []) as $line) {
                if (($line['mollie_chargeback_id'] ?? null) !== $chargebackId) {
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

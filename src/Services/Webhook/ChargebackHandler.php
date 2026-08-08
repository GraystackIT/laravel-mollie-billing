<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
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
            Log::warning('Could not load chargebacks for a payment that reports some', [
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return;
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

            if ($this->chargebackAlreadyPersisted($original->billable_type, $original->billable_id, $chargebackId)) {
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

            $creditNote = $this->salesInvoiceService->createCreditNote($original, $netAmount);

            $lines = (array) $creditNote->line_items;
            foreach (array_keys($lines) as $index) {
                $lines[$index]['mollie_chargeback_id'] = $chargebackId;
            }
            $creditNote->line_items = $lines;
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
     * Whether a credit note for this chargeback id already exists.
     *
     * Keyed on the id rather than the amount, because Mollie can report several
     * chargebacks of the same value against one payment and re-delivers each of them.
     */
    public function chargebackAlreadyPersisted(string $billableType, mixed $billableId, string $chargebackId): bool
    {
        $notes = BillingInvoice::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($notes as $note) {
            foreach ((array) ($note->line_items ?? []) as $line) {
                if (($line['mollie_chargeback_id'] ?? null) === $chargebackId) {
                    return true;
                }
            }
        }

        return false;
    }
}

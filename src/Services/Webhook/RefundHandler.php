<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use Illuminate\Support\Facades\Log;

class RefundHandler
{
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
        $refunds = [];
        try {
            /** @phpstan-ignore-next-line — SDK magic. */
            $refunds = $payment->refunds();
        } catch (\Throwable) {
            return;
        }

        $original = BillingInvoice::query()
            ->where('mollie_payment_id', (string) $payment->id)
            ->first();

        if ($original === null) {
            return;
        }

        foreach ($refunds as $refund) {
            $refundId = (string) ($refund->id ?? '');
            if ($refundId === '') {
                continue;
            }

            if ($this->refundIdAlreadyPersisted($original->billable_type, $original->billable_id, $refundId)) {
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
     * Idempotency check: does a refund invoice exist for this billable that carries
     * this mollie_refund_id in any of its line_items?
     */
    public function refundIdAlreadyPersisted(string $billableType, mixed $billableId, string $refundId): bool
    {
        $refunds = BillingInvoice::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($refunds as $refund) {
            foreach ((array) ($refund->line_items ?? []) as $line) {
                if (($line['mollie_refund_id'] ?? null) === $refundId) {
                    return true;
                }
            }
        }
        return false;
    }
}

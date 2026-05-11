<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;

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

            $refundAmountCents = (int) round(((float) ($refund->amount->value ?? 0)) * 100);

            $originalLines = (array) ($original->line_items ?? []);
            $firstLine = $originalLines[0] ?? null;
            $rate = $firstLine !== null && isset($firstLine['vat_rate']) ? (float) $firstLine['vat_rate'] : 0.0;
            $netAmount = $rate > 0
                ? (int) round($refundAmountCents / (1 + $rate / 100))
                : $refundAmountCents;

            $creditNote = $this->salesInvoiceService->createCreditNote($original, $netAmount);
            $lines = (array) $creditNote->line_items;
            if (isset($lines[0])) {
                $lines[0]['mollie_refund_id'] = $refundId;
                $creditNote->line_items = $lines;
            }
            $creditNote->refund_reason_code = RefundReasonCode::Other;
            $creditNote->refund_reason_text = 'synced from Mollie dashboard';
            $creditNote->save();

            $original->refunded_net = (int) $original->refunded_net + $netAmount;
            $original->save();

            event(new InvoiceRefunded($billable, $original, $creditNote, [
                'reason_code' => RefundReasonCode::Other,
                'reason_text' => 'synced from Mollie dashboard',
                'mollie_refund_id' => $refundId,
            ]));
        }
    }

    /**
     * Idempotenz-Check: existiert eine Refund-Invoice für diesen Billable, die diese mollie_refund_id
     * in einem ihrer line_items trägt?
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

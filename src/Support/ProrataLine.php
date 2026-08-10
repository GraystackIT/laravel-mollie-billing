<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;

/**
 * Represents a planned charge or refund line item within a plan change operation.
 *
 * Refund lines carry original invoice references + a snapshot of the original line item's VAT.
 * Charge lines work with list prices + live VAT derived from the billable.
 */
final class ProrataLine
{
    public ?string $mollieRefundId = null; // set by InvoiceService after the Mollie refund call

    /**
     * Note for the UI when the refund amount had to be capped against the remaining
     * amount of the original invoice (e.g. after goodwill refunds were already granted).
     * `null` when nothing was capped.
     *
     * @var array{
     *   alreadyRefundedNet: int,
     *   originalAmountNet: int,
     *   uncappedRefundNet: int,
     *   cappedRefundNet: int
     * }|null
     */
    public ?array $refundCapNote = null;

    /**
     * Idempotency token for this line's Mollie refund, minted once and then carried
     * through every retry.
     *
     * Mollie deduplicates refunds on (payment_id, amount) unless an explicit key is
     * supplied, which would reject two legitimately identical refunds on one payment
     * (reduce a seat, re-add it, reduce it again) as duplicates. The key used to end in
     * fresh randomness on every attempt instead, which solved that and broke the other
     * half: a refund whose HTTP response was lost is created at Mollie but throws here,
     * so the line goes to `pending_refund_retries` and the retry arrives with a new key —
     * a second payout rather than a replay of the first.
     *
     * A token that lives on the line, is serialised with it and survives the round trip
     * through the retry marker gives both: distinct refunds get distinct keys, retries of
     * one refund replay.
     */
    public ?string $refundToken = null;

    public function __construct(
        public readonly ?BillingInvoice $originalInvoice,
        public readonly ?int $originalLineItemIndex,
        public readonly string $kind,           // 'plan' | 'seats' | 'addon' | 'coupon'
        public readonly ?string $code,          // plan_code | null (seats) | addon_code | coupon_code
        public readonly string $label,
        public readonly int $quantity,
        public readonly int $amountNet,         // negative refund / positive charge / 0 coupon-covered
        public readonly float $vatRate,         // refund: from original line; charge: live
        public readonly int $amountVat,
        public readonly int $amountGross,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly int $daysActive,
        public readonly int $daysRemaining,
        public readonly bool $isCouponCovered,
        public readonly string $direction,      // 'refund' | 'charge'
    ) {}

    /**
     * Serialized for preview output AND persistence in the line_items JSON field.
     * For charge lines the parent_* fields are null, for refund lines they are set.
     *
     * `amount_net` is authoritative — it is the pro-rated total that was charged.
     * `unit_price_net` is a display value derived from it and rounded to the
     * nearest cent, so quantity × unit_price_net can be off by a cent or two;
     * consumers that need the line total must read `amount_net`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $unitPriceNet = $this->quantity > 0 ? (int) round($this->amountNet / $this->quantity) : $this->amountNet;

        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unit_price_net' => $unitPriceNet,
            'amount_net' => $this->amountNet,
            'vat_rate' => $this->vatRate,
            'vat_amount' => $this->amountVat,
            'amount_gross' => $this->amountGross,
            'period_start' => $this->periodStart->toIso8601String(),
            'period_end' => $this->periodEnd->toIso8601String(),
            'days_active' => $this->daysActive,
            'days_remaining' => $this->daysRemaining,
            'is_coupon_covered' => $this->isCouponCovered,
            'direction' => $this->direction,
            'parent_invoice_id' => $this->originalInvoice?->getKey(),
            'parent_line_item_index' => $this->originalLineItemIndex,
            'mollie_refund_id' => $this->mollieRefundId,
            'refund_cap_note' => $this->refundCapNote,
            'refund_token' => $this->refundToken,
        ];
    }

    /**
     * Creates a ProrataLine from serialized pending state.
     * The original invoice is resolved via find($id).
     *
     * Everything {@see toArray()} writes is read back verbatim. Payloads that
     * predate a field — or that a retry job hand-assembled from a stored refund
     * marker — get it derived instead of crashing on an undefined key: a
     * RetryRefundLineJob must be able to retry the refund, not die on a missing
     * display label. `amount_net` is the one exception: money cannot be guessed,
     * so a payload without it is rejected outright.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('amount_net', $data)) {
            throw new \InvalidArgumentException('ProrataLine::fromArray() requires an amount_net key.');
        }

        $originalInvoice = isset($data['parent_invoice_id'])
            ? BillingInvoice::find($data['parent_invoice_id'])
            : null;

        $kind = (string) ($data['kind'] ?? 'plan');
        $amountNet = (int) $data['amount_net'];
        $amountVat = (int) ($data['vat_amount'] ?? 0);
        $now = BillingTime::nowUtc();

        $line = new self(
            originalInvoice: $originalInvoice,
            originalLineItemIndex: $data['parent_line_item_index'] ?? null,
            kind: $kind,
            code: $data['code'] ?? null,
            label: (string) ($data['label'] ?? $kind),
            quantity: (int) ($data['quantity'] ?? 1),
            amountNet: $amountNet,
            vatRate: (float) ($data['vat_rate'] ?? 0.0),
            amountVat: $amountVat,
            amountGross: (int) ($data['amount_gross'] ?? $amountNet + $amountVat),
            periodStart: isset($data['period_start'])
                ? \Carbon\Carbon::parse((string) $data['period_start'])->setTimezone('UTC')
                : ($originalInvoice?->period_start ?? $now),
            periodEnd: isset($data['period_end'])
                ? \Carbon\Carbon::parse((string) $data['period_end'])->setTimezone('UTC')
                : ($originalInvoice?->period_end ?? $now),
            daysActive: (int) ($data['days_active'] ?? 0),
            daysRemaining: (int) ($data['days_remaining'] ?? 0),
            isCouponCovered: (bool) ($data['is_coupon_covered'] ?? false),
            direction: (string) ($data['direction'] ?? ($amountNet < 0 ? 'refund' : 'charge')),
        );

        if (isset($data['mollie_refund_id'])) {
            $line->mollieRefundId = $data['mollie_refund_id'];
        }

        if (isset($data['refund_cap_note']) && is_array($data['refund_cap_note'])) {
            $line->refundCapNote = $data['refund_cap_note'];
        }

        if (isset($data['refund_token']) && is_string($data['refund_token'])) {
            $line->refundToken = $data['refund_token'];
        }

        return $line;
    }
}

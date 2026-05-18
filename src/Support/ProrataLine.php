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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $unitPriceNet = $this->quantity > 0 ? intdiv($this->amountNet, $this->quantity) : $this->amountNet;

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
        ];
    }

    /**
     * Creates a ProrataLine from serialized pending state.
     * The original invoice is resolved via find($id).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $originalInvoice = isset($data['parent_invoice_id'])
            ? BillingInvoice::find($data['parent_invoice_id'])
            : null;

        $line = new self(
            originalInvoice: $originalInvoice,
            originalLineItemIndex: $data['parent_line_item_index'] ?? null,
            kind: $data['kind'],
            code: $data['code'] ?? null,
            label: $data['label'],
            quantity: (int) $data['quantity'],
            amountNet: (int) $data['amount_net'],
            vatRate: (float) $data['vat_rate'],
            amountVat: (int) $data['vat_amount'],
            amountGross: (int) $data['amount_gross'],
            periodStart: \Carbon\Carbon::parse((string) $data['period_start'])->setTimezone('UTC'),
            periodEnd: \Carbon\Carbon::parse((string) $data['period_end'])->setTimezone('UTC'),
            daysActive: (int) ($data['days_active'] ?? 0),
            daysRemaining: (int) ($data['days_remaining'] ?? 0),
            isCouponCovered: (bool) ($data['is_coupon_covered'] ?? false),
            direction: $data['direction'],
        );

        if (isset($data['mollie_refund_id'])) {
            $line->mollieRefundId = $data['mollie_refund_id'];
        }

        if (isset($data['refund_cap_note']) && is_array($data['refund_cap_note'])) {
            $line->refundCapNote = $data['refund_cap_note'];
        }

        return $line;
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;

/**
 * Repräsentiert eine geplante Charge- oder Refund-Position innerhalb einer Plan-Change-Operation.
 *
 * Refund-Lines tragen Original-Invoice-Referenzen + Original-Line-Item-Snapshot der VAT.
 * Charge-Lines arbeiten mit Listenpreisen + live-VAT aus Billable.
 */
final class ProrataLine
{
    public ?string $mollieRefundId = null; // wird vom InvoiceService nach Mollie-Refund-Call gesetzt

    /**
     * Notiz für die UI, wenn die Refund-Höhe gegen den Restbetrag der Original-Invoice
     * gekürzt werden musste (z.B. nachdem schon Kulanz-Refunds gewährt wurden).
     * `null` wenn nichts gekürzt wurde.
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
        public readonly int $amountNet,         // negativ Refund / positiv Charge / 0 coupon-covered
        public readonly float $vatRate,         // Refund: aus Original-Line; Charge: live
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
     * Serialisiert für Preview-Output UND Persistierung im line_items JSON-Feld.
     * Bei Charge-Lines sind die parent_*-Felder null, bei Refund-Lines gesetzt.
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
     * Erzeugt eine ProrataLine aus serialisiertem Pending-State.
     * Original-Invoice wird per find($id) aufgelöst.
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

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum InvoiceKind: string
{
    case Subscription = 'subscription';      // Recurring + Mid-cycle + Plan-Change-Charge
    case OneTimeOrder = 'one_time_order';
    case Overage = 'overage';
    case Refund = 'refund';                  // Plan-Change-Refunds + Admin-Refunds (Overage/OneTimeOrder)

    public function label(): string
    {
        return __('billing::enums.invoice_kind.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Subscription => 'blue',
            self::OneTimeOrder => 'emerald',
            self::Overage => 'red',
            self::Refund => 'zinc',
        };
    }

    public function isRefund(): bool
    {
        return $this === self::Refund;
    }

    public function metadataType(): string
    {
        return $this->value;
    }

    public static function fromMetadataType(string $type): self
    {
        return self::from($type);
    }
}

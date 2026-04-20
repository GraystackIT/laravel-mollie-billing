<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Open = 'open';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return __('billing::enums.invoice_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Open => 'amber',
            self::Failed => 'red',
            self::Refunded => 'zinc',
        };
    }
}

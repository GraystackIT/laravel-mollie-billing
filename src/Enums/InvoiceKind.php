<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum InvoiceKind: string
{
    case Subscription = 'subscription';
    case Prorata = 'prorata';
    case Addon = 'addon';
    case Seats = 'seats';
    case Overage = 'overage';
    case OneTimeOrder = 'one_time_order';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return __('billing::enums.invoice_kind.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Subscription => 'blue',
            self::Prorata => 'violet',
            self::Addon => 'cyan',
            self::Seats => 'amber',
            self::Overage => 'red',
            self::OneTimeOrder => 'emerald',
            self::CreditNote => 'zinc',
        };
    }
}

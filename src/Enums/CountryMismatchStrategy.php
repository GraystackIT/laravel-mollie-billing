<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum CountryMismatchStrategy: string
{
    case AutoVies = 'auto_vies';
    case AutoPayment = 'auto_payment';
    case AutoNoop = 'auto_noop';
    case Manual = 'manual';

    public function label(): string
    {
        return __('billing::enums.country_mismatch_strategy.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::AutoVies => 'sky',
            self::AutoPayment => 'indigo',
            self::AutoNoop => 'zinc',
            self::Manual => 'amber',
        };
    }
}

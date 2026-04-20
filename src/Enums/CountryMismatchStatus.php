<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum CountryMismatchStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';

    public function label(): string
    {
        return __('billing::enums.country_mismatch_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Resolved => 'green',
        };
    }
}

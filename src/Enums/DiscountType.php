<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return __('billing::enums.discount_type.'.$this->value);
    }

    public function color(): string
    {
        return 'zinc';
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum CountryMismatchStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
}

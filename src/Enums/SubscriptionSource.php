<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum SubscriptionSource: string
{
    case None = 'none';
    case Local = 'local';
    case Mollie = 'mollie';
}

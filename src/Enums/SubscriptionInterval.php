<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}

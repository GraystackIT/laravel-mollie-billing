<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum SubscriptionStatus: string
{
    case New = 'new';
    case Active = 'active';
    case Trial = 'trial';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}

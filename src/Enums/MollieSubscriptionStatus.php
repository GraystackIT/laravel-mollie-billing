<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum MollieSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Canceled = 'canceled';
    case Suspended = 'suspended';
    case Completed = 'completed';
}

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

    public function label(): string
    {
        return __('billing::enums.mollie_subscription_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Pending => 'amber',
            self::Suspended => 'red',
            self::Canceled, self::Completed => 'zinc',
        };
    }
}

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

    public function label(): string
    {
        return __('billing::enums.subscription_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'amber',
            self::Active => 'green',
            self::Trial => 'blue',
            self::PastDue => 'red',
            self::Cancelled, self::Expired => 'zinc',
        };
    }
}

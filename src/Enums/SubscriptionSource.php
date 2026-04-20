<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum SubscriptionSource: string
{
    case None = 'none';
    case Local = 'local';
    case Mollie = 'mollie';

    public function label(): string
    {
        return __('billing::enums.subscription_source.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Mollie => 'blue',
            self::Local => 'emerald',
            self::None => 'zinc',
        };
    }
}

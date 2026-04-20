<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return __('billing::enums.subscription_interval.'.$this->value);
    }

    public function color(): string
    {
        return 'zinc';
    }
}

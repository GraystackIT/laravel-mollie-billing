<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum PlanChangeMode: string
{
    case Immediate = 'immediate';
    case EndOfPeriod = 'end_of_period';
    case UserChoice = 'user_choice';

    public function label(): string
    {
        return __('billing::enums.plan_change_mode.'.$this->value);
    }
}

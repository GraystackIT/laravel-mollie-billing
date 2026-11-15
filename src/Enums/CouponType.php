<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum CouponType: string
{
    case SinglePayment = 'single_payment';
    case Recurring = 'recurring';
    case Credits = 'credits';
    case TrialExtension = 'trial_extension';
    case AccessGrant = 'access_grant';
    case PeriodExtension = 'period_extension';

    /** Waives the plan's base price for a number of billing periods; extras stay paid. */
    case PlanWaiver = 'plan_waiver';

    public function label(): string
    {
        return __('billing::enums.coupon_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::SinglePayment => 'blue',
            self::Recurring => 'violet',
            self::Credits => 'emerald',
            self::TrialExtension => 'amber',
            self::AccessGrant => 'cyan',
            self::PeriodExtension => 'sky',
            self::PlanWaiver => 'teal',
        };
    }
}

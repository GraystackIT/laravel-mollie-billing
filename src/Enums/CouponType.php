<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum CouponType: string
{
    case FirstPayment = 'first_payment';
    case Recurring = 'recurring';
    case Credits = 'credits';
    case TrialExtension = 'trial_extension';
    case AccessGrant = 'access_grant';
}

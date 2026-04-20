<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum RefundReasonCode: string
{
    case ServiceOutage = 'service_outage';
    case BillingError = 'billing_error';
    case Goodwill = 'goodwill';
    case Chargeback = 'chargeback';
    case Cancellation = 'cancellation';
    case PlanDowngrade = 'plan_downgrade';
    case Other = 'other';

    public function label(): string
    {
        return __('billing::enums.refund_reason_code.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::ServiceOutage, self::Chargeback => 'red',
            self::BillingError => 'amber',
            self::Goodwill => 'blue',
            self::Cancellation, self::PlanDowngrade, self::Other => 'zinc',
        };
    }
}

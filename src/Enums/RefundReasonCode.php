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
    case Other = 'other';
}

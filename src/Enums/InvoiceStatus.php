<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Open = 'open';
    case Failed = 'failed';
    case Refunded = 'refunded';
}

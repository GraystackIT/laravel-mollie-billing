<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

class PaymentNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $paymentId)
    {
        parent::__construct("Mollie payment {$paymentId} not found.");
    }
}

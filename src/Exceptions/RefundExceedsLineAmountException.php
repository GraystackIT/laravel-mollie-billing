<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

/**
 * A refund by line asked for more than is left of that line.
 *
 * A subclass of the invoice-level exception, so a caller that only knows "too much" keeps
 * catching it; one that offered a line can say how much that line has left.
 */
class RefundExceedsLineAmountException extends RefundExceedsInvoiceAmountException
{
    public function __construct(
        public readonly int $lineIndex,
        public readonly int $remainingNet,
        int $requestedNet,
    ) {
        parent::__construct("Refund of {$requestedNet} exceeds the remaining {$remainingNet} of line {$lineIndex}.");
    }
}

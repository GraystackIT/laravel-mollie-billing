<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use RuntimeException;

/**
 * Thrown when a refund for an invoice is attempted while another one is still running.
 *
 * `RefundInvoiceService` serialises refunds of the same invoice with a cache lock rather
 * than holding a database transaction across the Mollie round trip. The loser is refused
 * outright instead of queueing behind the winner: it would otherwise sit on a worker for
 * the length of an HTTP call plus a PDF render, and by the time it got its turn the amount
 * it validated is stale anyway.
 */
class RefundInProgressException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.refund_in_progress';
    }

    public function __construct(public readonly BillingInvoice $invoice)
    {
        parent::__construct("A refund for invoice {$invoice->id} is already in progress.");
    }
}

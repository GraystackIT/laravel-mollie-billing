<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

/**
 * Outcome of the short gate transaction that precedes a subscription
 * activation. The gate runs under a row lock on the billable and only decides
 * whether this delivery may activate; the caller reacts to the outcome OUTSIDE
 * the transaction, so no app listener and no Mollie call ever runs while the
 * row lock is held or inside a region a failure could roll back.
 */
enum ActivationGate
{
    /** The delivery claimed the activation and must run it, then release the claim. */
    case Proceed;

    /**
     * An invoice for this exact payment already exists: a re-delivery after a
     * crash mid-activation, never a duplicate payment. Nothing left to do.
     */
    case AlreadyBooked;

    /**
     * The billable already has an accessible subscription — a different payment
     * won the race. After the transaction committed, a payment that collected money
     * is booked and refunded ({@see WebhookSupport::settleDuplicatePayment()}), and
     * {@see \GraystackIT\MollieBilling\Events\DuplicatePaymentReceived} is dispatched.
     */
    case Duplicate;
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

/**
 * Another payment's activation claim on this billable is still fresh: that
 * delivery is (or moments ago was) between "claim committed" and "activation
 * finished", and starting a second activation now could create a second Mollie
 * subscription. A delivery that hits this must NOT be recorded as processed —
 * the webhook controller answers 503 so Mollie re-delivers after the holder
 * has either finished (the duplicate/invoice guards then apply) or died (the
 * claim then reads as stale and is taken over).
 */
class ActivationClaimHeldException extends \RuntimeException
{
    public function __construct(string $billableKey, string $holderPaymentId, string $paymentId)
    {
        parent::__construct(sprintf(
            'Activation of billable %s is claimed by payment %s — payment %s must retry later.',
            $billableKey,
            $holderPaymentId,
            $paymentId,
        ));
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Listeners;

use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;

/**
 * Revokes the previous Mollie mandate after a billable swapped payment methods.
 */
class RevokePreviousMandate
{
    public function handle(MandateUpdated $event): void
    {
        $previous = $event->previousMandateId;
        $current = $event->newMandateId;

        if ($previous === null || $previous === '') {
            return;
        }

        if ($previous === $current) {
            return;
        }

        $customerId = $event->billable->getMollieCustomerId();
        if ($customerId === null || $customerId === '') {
            return;
        }

        RevokeMollieMandateJob::dispatch($customerId, $previous);
    }
}

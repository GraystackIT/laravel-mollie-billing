<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Thrown when a free / Local subscription tries to add paid add-ons or paid extra seats.
 *
 * Free plans cannot collect money — the customer must upgrade to a paid plan
 * first via the UpgradeLocalToMollie path before paid extras can be activated.
 */
class LocalSubscriptionDoesNotSupportPaidExtrasException extends RuntimeException
{
    /**
     * @param  array<int, string>  $paidAddonCodes
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly array $paidAddonCodes = [],
        public readonly int $paidExtraSeats = 0,
    ) {
        $parts = [];
        if ($paidAddonCodes !== []) {
            $parts[] = 'paid add-ons ('.implode(', ', $paidAddonCodes).')';
        }
        if ($paidExtraSeats > 0) {
            $parts[] = "{$paidExtraSeats} paid extra seat(s)";
        }

        $details = $parts !== [] ? implode(' and ', $parts) : 'paid extras';

        parent::__construct(
            "Free / local subscriptions cannot include {$details}. Upgrade to a paid plan first."
        );
    }
}

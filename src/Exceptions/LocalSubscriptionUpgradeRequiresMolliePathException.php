<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Thrown when something tries to switch a Local subscription to a paid plan
 * via the regular UpdateSubscription path.
 *
 * Such a switch must go through UpgradeLocalToMollie, which obtains a Mollie
 * mandate and creates a real Mollie subscription. Calling UpdateSubscription
 * directly would silently set the new plan code without any payment flow —
 * a bug guarded by this exception.
 */
class LocalSubscriptionUpgradeRequiresMolliePathException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly string $targetPlanCode,
    ) {
        parent::__construct(
            "Switching a local subscription to paid plan '{$targetPlanCode}' must go through UpgradeLocalToMollie."
        );
    }
}

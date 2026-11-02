<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use RuntimeException;

/**
 * Thrown when an addon is requested that the target plan does not list in its
 * `allowed_addons` — including a code the catalog does not know at all.
 *
 * The portal only offers allowed addons, but the code arrives from outside, and the
 * services are entry points of their own (`enableBillingAddon()`,
 * `UpdateSubscription::update()`, `StartSubscriptionCheckout::handle()`). An addon
 * reserved for a higher tier would otherwise be written into `active_addon_codes`, where
 * FeatureAccess grants its features; an unknown code stayed as a junk entry priced at 0.
 *
 * Only *newly added* codes are judged. An addon the billable already holds was sold
 * under the rules of its time and is not taken away by a later catalog change.
 */
class AddonNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly string $planCode,
        public readonly string $addonCode,
    ) {
        parent::__construct("The addon '{$addonCode}' is not available on the plan '{$planCode}'.");
    }
}

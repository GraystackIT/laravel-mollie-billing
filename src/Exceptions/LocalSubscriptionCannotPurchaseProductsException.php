<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Thrown when a free / Local subscription tries to purchase a one-time product.
 *
 * Local subscriptions have no Mollie mandate. While Mollie technically accepts
 * one-off card payments without a mandate, allowing purchases on a free plan
 * conflicts with the package's contract: paid extras (add-ons, seats, products)
 * require an upgrade to a paid plan via UpgradeLocalToMollie first.
 */
class LocalSubscriptionCannotPurchaseProductsException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly string $productCode,
    ) {
        parent::__construct(
            "Free / local subscriptions cannot purchase products (\"{$productCode}\"). Upgrade to a paid plan first."
        );
    }
}

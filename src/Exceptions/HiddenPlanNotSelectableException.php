<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Thrown when something tries to move a billable onto a hidden plan.
 *
 * Hidden plans (internal, supplier, grandfathered, legacy) are unlisted in
 * checkout and in the portal's plan-change screen. Because hiding a card in a
 * view is not enforcement, every path that accepts a plan code from the outside
 * re-checks it: a forged plan-change or checkout request naming a hidden plan
 * would otherwise hand a paying customer a free or internal plan.
 *
 * What this exception does *not* block:
 *
 * - Staying on a hidden plan, including changing interval within it — a
 *   supplier on a hidden plan can still switch monthly/yearly.
 * - Programmatic activation via ActivateLocalSubscription, which is how hidden
 *   plans are assigned in the first place.
 * - Lookups by code (planName(), planFeatures(), …), so the portal renders the
 *   subscriber's own hidden plan like any other.
 *
 * Apps that legitimately need to move an existing billable onto a hidden plan
 * pass a typed SubscriptionUpdateRequest with `allowHiddenPlan: true` to
 * UpdateSubscription. The override is deliberately not readable from array
 * payloads, so request data can never enable it.
 */
class HiddenPlanNotSelectableException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly string $targetPlanCode,
    ) {
        parent::__construct(
            "Plan '{$targetPlanCode}' is hidden and cannot be selected as a change target."
        );
    }
}

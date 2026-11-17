<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * A change that costs money — or moves the plan — was asked for on a subscription that
 * has been cancelled (and runs out its paid period) or has expired.
 *
 * A settled prorata charge is never applied to a subscription that no longer runs
 * (`ProrataChargeHandler::APPLICABLE_STATES`): the plan is not switched and the money is
 * returned in full. Accepting the change anyway therefore charged the customer, refunded
 * them, and left the plan where it was — and for a seat or a paid addon, which are active
 * as soon as they are ordered, the refund left them with it for free. The change is
 * refused before anything is charged; the way back is to reactivate the subscription.
 *
 * Taking a seat or an addon away is not this case: it charges nothing and credits what
 * was paid.
 *
 * The message is the customer-facing text, translated.
 */
class SubscriptionEndedChangeNotAllowedException extends RuntimeException
{
    public function __construct(public readonly Billable $billable)
    {
        parent::__construct((string) __('billing::portal.error_subscription_ended'));
    }
}

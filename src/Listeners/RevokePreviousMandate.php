<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Listeners;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Revokes the previous Mollie mandate after a billable swapped payment methods.
 *
 * A running subscription is moved onto the new mandate first. `CreateSubscription` binds
 * the subscription to the mandate of its checkout, and revoking that mandate cancels the
 * subscription at Mollie or leaves it uncollectable — with no webhook to say so, or with a
 * failed charge that sends a customer who had just given a working payment method into
 * dunning. When the move fails the old mandate is kept: a second valid mandate costs
 * nothing, a subscription on a revoked one costs the customer their subscription.
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

        if ($current !== null && $current !== '' && $this->runsOnAMandate($event->billable)) {
            try {
                app(MollieSubscriptionPatcher::class)->rebindMandate($event->billable, $current);
            } catch (\Throwable $e) {
                Log::warning('Running subscription could not be moved to the new mandate — the previous mandate is kept', [
                    'billable_id' => $event->billable instanceof Model ? $event->billable->getKey() : null,
                    'previous_mandate_id' => $previous,
                    'new_mandate_id' => $current,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        }

        RevokeMollieMandateJob::dispatch($customerId, $previous);
    }

    /**
     * Whether a Mollie subscription that charges a mandate is running for this billable.
     *
     * A checkout that recovers an ended subscription also replaces the mandate; the old
     * subscription is over there, and the new one is created on the new mandate.
     *
     * Access is not the question. A renewal that failed leaves the billable past due and
     * without access while Mollie still runs (or has suspended) the subscription on the
     * old mandate — and that is the moment the customer is told to review their payment
     * method. Without access, Mollie is asked: `hasLiveMollieSubscription()` drops an id
     * Mollie reports cancelled, completed or gone, and only that answer means "over". A
     * lookup that failed leaves the id in place and is no answer; the PATCH is tried, and
     * when it fails too the old mandate is kept, as for any failed rebinding.
     */
    private function runsOnAMandate(Billable $billable): bool
    {
        if ($billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value
            || $this->subscriptionId($billable) === '') {
            return false;
        }

        if ($billable->hasAccessibleBillingSubscription()
            || app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($billable)) {
            return true;
        }

        return $this->subscriptionId($billable) !== '';
    }

    private function subscriptionId(Billable $billable): string
    {
        return (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
    }
}

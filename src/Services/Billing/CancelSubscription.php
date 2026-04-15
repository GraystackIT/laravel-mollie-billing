<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

class CancelSubscription
{
    public function __construct(
        private readonly ChargeUsageOverageDirectly $overageService,
    ) {
    }

    public function handle(Billable $billable, bool $immediately = false): void
    {
        /** @var Model&Billable $billable */
        $isLocal = $billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value
            || $billable->getBillingSubscriptionSource() === null
            || $billable->getBillingSubscriptionSource() === SubscriptionSource::None->value;

        if ($isLocal) {
            $endsAt = $immediately
                ? now()
                : ($billable->getBillingSubscriptionEndsAt() ?: now()->addDays(30));

            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Cancelled,
                'subscription_ends_at' => $endsAt,
            ])->save();

            SubscriptionCancelled::dispatch($billable, $immediately);

            return;
        }

        // Mollie subscription path.
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null;

        if ($customerId !== null && $subscriptionId !== null) {
            try {
                Mollie::api()->subscriptions->cancelForId($customerId, $subscriptionId);
            } catch (\Throwable $e) {
                // Swallow — Mollie may already have cancelled it; we still need to update local state.
            }
        }

        if ($immediately) {
            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Cancelled,
                'subscription_ends_at' => now(),
            ])->save();

            try {
                $this->overageService->handle($billable);
            } catch (\Throwable $e) {
                // Overage charge failures are surfaced via OverageChargeFailed event in the service.
            }
        } else {
            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Cancelled,
                'subscription_ends_at' => $billable->nextBillingDate() ?: now()->addDays(30),
            ])->save();
        }

        SubscriptionCancelled::dispatch($billable, $immediately);

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new SubscriptionCancelledNotification($billable));
        }
    }
}

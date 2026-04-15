<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionResumed;
use Illuminate\Database\Eloquent\Model;

class ResubscribeSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly CreateSubscription $createSubscription,
    ) {
    }

    public function handle(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable->getBillingSubscriptionStatus() !== SubscriptionStatus::Cancelled) {
            throw new \RuntimeException('Cannot resubscribe: subscription is not in a cancelled state.');
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();
        if ($endsAt === null || ! $endsAt->isFuture()) {
            throw new \RuntimeException('Cannot resubscribe: grace period has already ended.');
        }

        $isLocal = $billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value;

        if ($isLocal) {
            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_ends_at' => null,
            ])->save();

            SubscriptionResumed::dispatch($billable);

            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode();
        $interval = $billable->getBillingSubscriptionInterval();
        if ($planCode === null || $interval === null) {
            throw new \RuntimeException('Cannot resubscribe: plan or interval missing.');
        }

        $amountGross = $this->catalog->basePriceNet($planCode, $interval);

        $this->createSubscription->handle($billable, [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $billable->getActiveBillingAddonCodes(),
            'extra_seats' => $billable->getExtraBillingSeats(),
            'amount_gross' => $amountGross,
        ]);

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_ends_at' => null,
        ])->save();

        SubscriptionResumed::dispatch($billable);
    }
}

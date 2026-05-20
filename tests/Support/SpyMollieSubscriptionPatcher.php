<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;

/**
 * Spy patcher: records every Mollie-subscription PATCH / cancel call without hitting Mollie.
 *
 * Bound in the test container via bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class).
 */
class SpyMollieSubscriptionPatcher extends MollieSubscriptionPatcher
{
    /** @var array<int, array<int, mixed>> */
    public static array $calls = [];

    public function updateForIntent(Billable $billable, PlanChangeIntent $intent): void
    {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');

        if ($catalog->isFreePlan($intent->newPlan, $intent->newInterval)) {
            self::$calls[] = ['cancel', $customerId, $subscriptionId];
            SpyUpdateSubscription::$calls[] = ['cancel', $customerId, $subscriptionId];
            return;
        }

        $payload = [
            'plan_code' => $intent->newPlan,
            'interval' => $intent->newInterval,
            'addons' => array_keys($intent->newAddons),
            'seats' => $intent->newSeats,
        ];
        self::$calls[] = ['update', $customerId, $subscriptionId, $payload];
        SpyUpdateSubscription::$calls[] = ['update', $customerId, $subscriptionId, $payload];
    }

    public function cancelForFreeDowngrade(Billable $billable): void
    {
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');
        self::$calls[] = ['cancel', $customerId, $subscriptionId];
        SpyUpdateSubscription::$calls[] = ['cancel', $customerId, $subscriptionId];
    }

    public function updateRecurringAmount(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        bool $intervalChanged = false,
        int $couponDiscountNet = 0,
        bool $forceResetStartDate = false,
    ): void {
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');
        $payload = [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addons' => $addons,
            'extra_seats' => $extraSeats,
            'interval_changed' => $intervalChanged,
            'coupon_discount_net' => $couponDiscountNet,
            'force_reset_start_date' => $forceResetStartDate,
        ];
        self::$calls[] = ['update', $customerId, $subscriptionId, $payload];
        SpyUpdateSubscription::$calls[] = ['update', $customerId, $subscriptionId, $payload];
    }

    public function pushNextChargeDate(Billable $billable, int $days): void
    {
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');
        self::$calls[] = ['push_next_charge_date', $customerId, $subscriptionId, ['days' => $days]];
        SpyUpdateSubscription::$calls[] = ['push_next_charge_date', $customerId, $subscriptionId, ['days' => $days]];

        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $current = $billable->nextBillingDate() ?? \GraystackIT\MollieBilling\Support\BillingTime::nowUtc();
            $newDate = $current->copy()->addDays($days);
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['next_charge_date_override'] = $newDate->toIso8601String();
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }

    public function setNextChargeDate(Billable $billable, \Carbon\CarbonInterface $target): void
    {
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');
        $iso = $target->copy()->toIso8601String();
        self::$calls[] = ['set_next_charge_date', $customerId, $subscriptionId, ['target' => $iso]];
        SpyUpdateSubscription::$calls[] = ['set_next_charge_date', $customerId, $subscriptionId, ['target' => $iso]];

        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['next_charge_date_override'] = $iso;
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }
}

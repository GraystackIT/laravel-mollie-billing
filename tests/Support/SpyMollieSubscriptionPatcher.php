<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;

/**
 * Spy-Patcher: zeichnet alle Mollie-Subscription-PATCH/Cancel-Calls auf, ohne Mollie zu treffen.
 *
 * Wird vom Test-Container über bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class) eingehängt.
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
    ): void {
        $customerId = $billable->getMollieCustomerId() ?? 'cust_test';
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_test');
        self::$calls[] = ['update', $customerId, $subscriptionId, [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addons' => $addons,
            'extra_seats' => $extraSeats,
        ]];
        SpyUpdateSubscription::$calls[] = ['update', $customerId, $subscriptionId, [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addons' => $addons,
            'extra_seats' => $extraSeats,
        ]];
    }
}

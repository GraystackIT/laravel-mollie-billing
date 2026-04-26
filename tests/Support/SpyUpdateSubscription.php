<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionChangeContext;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Illuminate\Database\Eloquent\Model;

/**
 * Subclass that intercepts every Mollie API call and prorata side-effect so
 * that subscription-update tests can run against an in-memory database
 * without booting the real Mollie HTTP client. Calls are recorded in
 * `self::$calls` for assertions.
 */
class SpyUpdateSubscription extends UpdateSubscription
{
    /** @var array<int, array<int, mixed>> */
    public static array $calls = [];

    protected function mollieCancelSubscription(string $customerId, string $subscriptionId): void
    {
        self::$calls[] = ['cancel', $customerId, $subscriptionId];
    }

    protected function mollieCreateSubscription(string $customerId, array $payload): object
    {
        self::$calls[] = ['create', $customerId, $payload];

        return (object) ['id' => 'sub_new_'.uniqid()];
    }

    protected function chargeProrataImmediate(Billable $billable, int $prorataChargeNet, ?SubscriptionChangeContext $context = null): void
    {
        self::$calls[] = ['prorata_charge', $prorataChargeNet];

        if ($billable instanceof Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['prorata_pending_payment_id'] = 'tr_test_'.uniqid();
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }

    protected function refundProrataCredit(Billable $billable, int $prorataCreditNet, ?SubscriptionChangeContext $context = null): void
    {
        self::$calls[] = ['prorata_refund', $prorataCreditNet];
    }

    protected function mollieUpdateSubscription(string $customerId, string $subscriptionId, array $payload): object
    {
        self::$calls[] = ['update', $customerId, $subscriptionId, $payload];

        return (object) ['id' => $subscriptionId];
    }
}

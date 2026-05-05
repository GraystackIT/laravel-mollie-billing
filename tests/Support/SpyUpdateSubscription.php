<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionChangeContext;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;

/**
 * Subclass that intercepts the new pro-rata side-effects so that subscription-update
 * tests can run against an in-memory database without booting the real Mollie HTTP
 * client. Calls are recorded in `self::$calls` for assertions.
 *
 * The Mollie-Subscription-PATCH/Cancel side-effects are handled by SpyMollieSubscriptionPatcher,
 * which is bound separately in the test bootstrap.
 *
 * Recorded shapes:
 *   ['prorata_charge', $chargeNet]
 *   ['prorata_refund', $refundNet]
 *   plus everything SpyMollieSubscriptionPatcher records (cancel/update/create).
 */
class SpyUpdateSubscription extends UpdateSubscription
{
    /** @var array<int, array<int, mixed>> */
    public static array $calls = [];

    protected function applyProrata(Billable $billable, SubscriptionChangeContext $context, array $resolvedCoupons = []): array
    {
        if ($context->prorataChargeNet > 0) {
            self::$calls[] = ['prorata_charge', $context->prorataChargeNet];

            // Mirror the real ProrataExecutor behavior for charge-flows: persist a pending state
            // marker so the UpdateSubscription::update() path can detect a deferred upgrade
            // and short-circuit before flipping the local plan.
            if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_prorata_change'] = [
                    'charge_payment_id' => 'tr_test_'.uniqid(),
                    'charge_lines' => array_map(
                        fn ($c) => [
                            'kind' => 'coupon',
                            'code' => (string) $c->code,
                            'amount_net' => -1, // sentinel; tests that care override this directly
                        ],
                        $resolvedCoupons,
                    ),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }

            return ['path' => 'deferred_charge', 'invoice' => null];
        }

        if ($context->prorataCreditNet > 0) {
            self::$calls[] = ['prorata_refund', $context->prorataCreditNet];
        }

        // Trigger the MollieSubscriptionPatcher for the new plan/seats/addons,
        // so that downstream test assertions ("cancel"/"update") can fire via the SpyPatcher.
        $intent = new PlanChangeIntent(
            billable: $billable,
            currentPlan: $context->currentPlan,
            newPlan: $context->newPlan,
            currentInterval: $context->currentInterval,
            newInterval: $context->newInterval,
            currentSeats: $context->currentSeats,
            newSeats: $context->newSeats,
            currentAddons: array_fill_keys($context->currentAddons, 1),
            newAddons: array_fill_keys($context->newAddons, 1),
        );

        app(MollieSubscriptionPatcher::class)->updateForIntent($billable, $intent);

        return ['path' => 'noop', 'invoice' => null];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Creates a Mollie subscription and updates the billable to reflect the new
 * Mollie-source state. Wallet hydration is the caller's responsibility — see
 * the three call sites for the right hydration strategy in each context:
 *
 *  - First-time activation (MollieWebhookController::handleFirstPaymentPaid):
 *    credit `included_usages` directly into the wallet.
 *  - Local→Mollie upgrade (MollieWebhookController::handleLocalToMollieUpgrade):
 *    use WalletPlanChangeAdjuster to rebalance plan credits while preserving
 *    purchased balance.
 *  - Resubscribe in grace period (ResubscribeSubscription): do nothing — the
 *    wallet already holds the current period's credits.
 */
class CreateSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,amount_gross:int,mandate_id?:string}  $spec
     */
    public function handle(Billable $billable, array $spec): void
    {
        /** @var Model&Billable $billable */
        $planCode = $spec['plan_code'];
        $interval = $spec['interval'];
        $addonCodes = $spec['addon_codes'] ?? [];
        $extraSeats = (int) ($spec['extra_seats'] ?? 0);
        $amountGross = (int) $spec['amount_gross'];
        $currency = (string) config('mollie-billing.currency', 'EUR');

        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: $billable->getMollieCustomerId(),
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            interval: $interval === 'yearly' ? '12 months' : '1 month',
            description: "{$planCode} subscription",
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'plan_code' => $planCode,
                'interval' => $interval,
            ],
            webhookUrl: route(BillingRoute::webhook()),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);
        $meta['mollie_subscription_id'] = (string) ($subscription->id ?? '');

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'active_addon_codes' => $addonCodes,
            'subscription_meta' => $meta,
            'subscription_period_starts_at' => now(),
            'subscription_ends_at' => null,
        ])->save();

        SubscriptionCreated::dispatch($billable, $planCode, $interval);
    }
}

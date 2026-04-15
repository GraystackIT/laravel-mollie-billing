<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Database\Eloquent\Model;
use Mollie\Laravel\Facades\Mollie;

class CreateSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly WalletUsageService $walletService,
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

        $customer = Mollie::api()->customers->get($billable->getMollieCustomerId());

        $customer->createSubscription([
            'amount' => [
                'currency' => $currency,
                'value' => number_format($amountGross / 100, 2, '.', ''),
            ],
            'interval' => $interval === 'yearly' ? '12 months' : '1 month',
            'description' => "{$planCode} subscription",
            'webhookUrl' => route('billing.webhook'),
            'metadata' => [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'plan_code' => $planCode,
                'interval' => $interval,
            ],
        ]);

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);

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

        $includedUsages = (array) app('config')->get(
            'mollie-billing-plans.plans.'.$planCode.'.included_usages',
            []
        );

        foreach ($includedUsages as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity);
            }
        }

        SubscriptionCreated::dispatch($billable, $planCode, $interval);
    }
}

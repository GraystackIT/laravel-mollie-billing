<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Convert a local (free / coupon-granted) subscription into a Mollie subscription.
 *
 * Acts as a thin adapter to the Mollie API: VAT, coupon discount and the gross
 * amount are computed up-front by the calling UI (typically PreviewService) —
 * the service receives a ready `amount_gross`. The first payment carries the
 * `upgrade_from_local: true` metadata flag so the webhook handler can take the
 * wallet-preserving conversion path instead of the standard "first activation"
 * flow.
 */
class UpgradeLocalToMollie
{
    public function __construct(
        private readonly MollieCustomerResolver $customerResolver,
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws LocalSubscriptionUpgradeRequiresMolliePathException When called on a non-local subscription
     * @throws HiddenPlanNotSelectableException When the target plan is hidden
     */
    public function handle(Billable $billable, array $request): array
    {
        if (! $billable->isLocalBillingSubscription()) {
            throw new LocalSubscriptionUpgradeRequiresMolliePathException(
                $billable,
                (string) $request['plan_code'],
            );
        }

        // The Local→Mollie upgrade is a customer-driven plan choice made in the
        // portal, so it obeys the same rule as checkout. Staying on the current
        // plan is exempt — a hidden free plan converting to paid on the same
        // plan code is a mandate change, not a move onto a hidden plan.
        $targetPlan = (string) $request['plan_code'];
        if ($targetPlan !== $billable->getBillingSubscriptionPlanCode()
            && $this->catalog->planIsHidden($targetPlan)) {
            throw new HiddenPlanNotSelectableException($billable, $targetPlan);
        }

        $customerId = $this->customerResolver->resolve($billable);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountGross = (int) $request['amount_gross'];
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $payment = Mollie::send(new CreatePaymentRequest(
            description: "Upgrade to {$request['plan_code']}",
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            locale: MollieBilling::mollieLocale(),
            cancelUrl: route(BillingRoute::name('plan'), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'subscription',
                'plan_code' => $request['plan_code'],
                'interval' => $request['interval'],
                'addon_codes' => $request['addon_codes'] ?? [],
                'extra_seats' => (int) ($request['extra_seats'] ?? 0),
                'coupon_code' => $request['coupon_code'] ?? null,
                'upgrade_from_local' => true,
            ],
            sequenceType: 'first',
            customerId: $customerId,
        ));

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
        ];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\MollieBilling;
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
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws LocalSubscriptionUpgradeRequiresMolliePathException When called on a non-local subscription
     */
    public function handle(Billable $billable, array $request): array
    {
        if (! $billable->isLocalBillingSubscription()) {
            throw new LocalSubscriptionUpgradeRequiresMolliePathException(
                $billable,
                (string) $request['plan_code'],
            );
        }

        $customerId = $this->customerResolver->resolve($billable);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountGross = (int) $request['amount_gross'];
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $payment = Mollie::send(new CreatePaymentRequest(
            description: "Upgrade to {$request['plan_code']}",
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
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

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

class StartMandateCheckout
{
    /**
     * @param  ?string  $redirectUrl  Optional override for the post-checkout redirect URL.
     *                                Defaults to the package "return" route.
     * @param  ?array{plan_code?:string,interval?:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,trial_days?:int}  $subscriptionSpec
     *         When set, the resulting Mandate-Only payment carries the spec in its
     *         metadata under `pending_subscription_*` keys. The webhook then activates
     *         a Mollie subscription after the mandate is captured. Two activation
     *         flows share this transport:
     *           - 100%-single_payment-coupon checkout where the first charge is 0 €.
     *           - Trial checkout (`trial_days > 0`) where the first interval is free.
     * @return array{checkout_url:?string,payment_id:string}
     */
    public function handle(Billable $billable, ?string $redirectUrl = null, ?array $subscriptionSpec = null): array
    {
        /** @var Model&Billable $billable */
        $customerId = $this->ensureMollieCustomer($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $metadata = [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'type' => 'mandate_only',
        ];

        if ($subscriptionSpec !== null) {
            if (isset($subscriptionSpec['plan_code'])) {
                $metadata['pending_subscription_plan_code'] = (string) $subscriptionSpec['plan_code'];
            }
            if (isset($subscriptionSpec['interval'])) {
                $metadata['pending_subscription_interval'] = (string) $subscriptionSpec['interval'];
            }
            if (isset($subscriptionSpec['addon_codes'])) {
                $metadata['pending_subscription_addon_codes'] = (array) $subscriptionSpec['addon_codes'];
            }
            if (isset($subscriptionSpec['extra_seats'])) {
                $metadata['pending_subscription_extra_seats'] = (int) $subscriptionSpec['extra_seats'];
            }
            if (! empty($subscriptionSpec['coupon_code'])) {
                $metadata['pending_subscription_coupon_code'] = (string) $subscriptionSpec['coupon_code'];
            }
            if (isset($subscriptionSpec['trial_days'])) {
                $metadata['pending_subscription_trial_days'] = (int) $subscriptionSpec['trial_days'];
            }
            // The free plan the webhook will find is the one being replaced, not a
            // duplicate subscription — see MandateOnlyPaymentHandler::replacesLocalPlan().
            if (! empty($subscriptionSpec['upgrade_from_local'])) {
                $metadata['pending_subscription_upgrade_from_local'] = true;
            }
        }

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: 'Payment method authorisation',
            // Zero still has to be spelled with the currency's own number of
            // decimals — Mollie rejects "0.00" for a 0-decimal currency like JPY.
            amount: new Money($currency, BillingMoney::mollieAmount(0, $currency)),
            redirectUrl: $redirectUrl ?? route(BillingRoute::name('return'), $urlParams),
            locale: MollieBilling::mollieLocale(),
            cancelUrl: route(BillingRoute::checkout(), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: $metadata,
            sequenceType: 'first',
            customerId: $customerId,
        ));

        // A mandate payment that carries a subscription is a checkout (trial, fully
        // covered first period). One without is a change of payment method.
        if ($subscriptionSpec !== null) {
            event(new \GraystackIT\MollieBilling\Events\CheckoutStarted($billable));
        }

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
        ];
    }

    private function ensureMollieCustomer(Billable $billable): string
    {
        /** @var Model&Billable $billable */
        $existing = $billable->getMollieCustomerId();
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $customer = MollieCall::send(new CreateCustomerRequest(
            name: $billable->getBillingName(),
            email: $billable->getBillingEmail(),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ));

        $billable->forceFill(['mollie_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class StartMandateCheckout
{
    /**
     * @param  ?string  $redirectUrl  Optional override for the post-checkout redirect URL.
     *                                Defaults to the package "return" route.
     * @return array{checkout_url:?string,payment_id:string}
     */
    public function handle(Billable $billable, ?string $redirectUrl = null): array
    {
        /** @var Model&Billable $billable */
        $customerId = $this->ensureMollieCustomer($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $payment = Mollie::send(new CreatePaymentRequest(
            description: 'Payment method authorisation',
            amount: new Money($currency, '0.00'),
            redirectUrl: $redirectUrl ?? route(BillingRoute::name('return'), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'mandate_only',
            ],
            sequenceType: 'first',
            customerId: $customerId,
        ));

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

        $customer = Mollie::send(new CreateCustomerRequest(
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

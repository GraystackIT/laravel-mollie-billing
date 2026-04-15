<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;
use Mollie\Laravel\Facades\Mollie;

class StartMandateCheckout
{
    /**
     * @return array{checkout_url:?string,payment_id:string}
     */
    public function handle(Billable $billable): array
    {
        /** @var Model&Billable $billable */
        $customerId = $this->ensureMollieCustomer($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');

        $payment = Mollie::api()->payments->create([
            'amount' => ['currency' => $currency, 'value' => '0.00'],
            'description' => 'Payment method authorisation',
            'redirectUrl' => route('billing.return'),
            'webhookUrl' => route('billing.webhook'),
            'customerId' => $customerId,
            'sequenceType' => 'first',
            'metadata' => [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'mandate_only',
            ],
        ]);

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

        $customer = Mollie::api()->customers->create([
            'name' => $billable->getBillingName(),
            'email' => $billable->getBillingEmail(),
            'metadata' => [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ]);

        $billable->forceFill(['mollie_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}

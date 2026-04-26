<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\UpdateCustomerRequest;
use Mollie\Laravel\Facades\Mollie;

class MollieCustomerResolver
{
    public function resolve(Billable $billable): string
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

    /**
     * Push the billable's current name + email to the Mollie customer record.
     * No-op when no Mollie customer exists yet (resolve() will create one with fresh data).
     * Mollie does not store address/VAT on the customer — those flow into each
     * payment/invoice via InvoiceService and the live Billable accessors.
     */
    public function sync(Billable $billable): void
    {
        $customerId = $billable->getMollieCustomerId();
        if ($customerId === null || $customerId === '') {
            return;
        }

        Mollie::send(new UpdateCustomerRequest(
            id: $customerId,
            name: $billable->getBillingName(),
            email: $billable->getBillingEmail(),
        ));
    }
}

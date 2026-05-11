<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Support\Facades\Log;

class FirstPaymentArtifacts
{
    public function __construct(
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly InvoiceService $salesInvoiceService,
    ) {
    }

    /**
     * Persist mandate, customer ID, country, and create the invoice for a first
     * payment (real first-time activation or local→Mollie upgrade).
     *
     * @param  array<int, string>  $addonCodes
     */
    public function persist(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): BillingInvoice {
        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed', ['error' => $e->getMessage()]);
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);

        return $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
    }
}

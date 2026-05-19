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
     * The country-match check is intentionally NOT run here: callers must run it
     * AFTER the subscription has been fully activated, otherwise a mismatch-driven
     * cancel-at-period-end would be overwritten by the activation flow's final
     * status=Active forceFill. See {@see runCountryMatchCheck()}.
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

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);

        return $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
    }

    /**
     * Run the three-way country reconciliation. Call this only after the
     * subscription has been fully activated so a mismatch-triggered
     * cancel-at-period-end survives.
     */
    public function runCountryMatchCheck(Billable $billable): void
    {
        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed', ['error' => $e->getMessage()]);
        }
    }
}

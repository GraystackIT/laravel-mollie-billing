<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Support\Facades\Log;

class FirstPaymentArtifacts
{
    public function __construct(
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly WebhookSupport $support,
        protected readonly VatCalculationService $vatService,
    ) {
    }

    /**
     * Persist mandate, customer ID, country, and create the invoice for a first
     * payment (real first-time activation or local→Mollie upgrade).
     *
     * `$extraLineItems` carries everything the plan/seat/addon lines cannot
     * express — above all the coupon discount the checkout actually charged.
     * Callers MUST price their coupon before calling this: the invoice header is
     * derived from the line items, so a discount that arrives afterwards can no
     * longer be booked and the invoice would overstate net + VAT.
     *
     * Whatever the lines add up to is finally compared against the amount Mollie
     * collected, and reconciled to it — see
     * {@see WebhookSupport::reconcileWithPaidAmount()}.
     *
     * The country-match check is intentionally NOT run here: callers must run it
     * AFTER the subscription has been fully activated, otherwise a mismatch-driven
     * cancel-at-period-end would be overwritten by the activation flow's final
     * status=Active forceFill. See {@see runCountryMatchCheck()}.
     *
     * @param  array<int, string>  $addonCodes
     * @param  array<int, array<string, mixed>>  $extraLineItems
     */
    public function persist(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        array $extraLineItems = [],
    ): BillingInvoice {
        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        $lineItems = array_merge(
            SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes),
            $extraLineItems,
        );

        $expectedNet = 0;
        foreach ($lineItems as $line) {
            $expectedNet += (int) ($line['total_net'] ?? 0);
        }

        $vat = $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $expectedNet,
            $billable,
        );

        $lineItems = $this->support->reconcileWithPaidAmount(
            $payment,
            $billable,
            $lineItems,
            $expectedNet,
            (float) ($vat['rate'] ?? 0.0),
        );

        return $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
    }

    /**
     * Negative discount line for a priced first-payment coupon. Mirrors the
     * shape MandateOnlyPaymentHandler writes so every invoice kind carries the
     * same coupon line keys.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $pricedCoupon
     * @return array<int, array<string, mixed>>
     */
    public function couponLineItems(?array $pricedCoupon): array
    {
        $discountNet = (int) ($pricedCoupon['discount_net'] ?? 0);

        if ($pricedCoupon === null || $discountNet <= 0) {
            return [];
        }

        return [[
            'kind' => 'coupon',
            'code' => (string) $pricedCoupon['coupon']->code,
            'label' => 'Coupon '.$pricedCoupon['coupon']->code,
            'quantity' => 1,
            'unit_price' => -$discountNet,
            'unit_price_net' => -$discountNet,
            'total_net' => -$discountNet,
        ]];
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

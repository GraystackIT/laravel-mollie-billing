<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class ChargeUsageOverageDirectly
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly MollieSalesInvoiceService $invoiceService,
    ) {
    }

    /**
     * Iterate the billable's wallets, build line items from any negative
     * balances, and create a Mollie payment for the gross amount. The
     * resulting BillingInvoice is created later, in the webhook.
     */
    public function handle(Billable $billable): ?BillingInvoice
    {
        $lineItems = $this->buildLineItemsFromWallets($billable);

        if ($lineItems === []) {
            return null;
        }

        return $this->charge($billable, $lineItems);
    }

    /**
     * Same as {@see handle()} but with caller-supplied line items. Used by
     * ChangePlan for downgrade-overage where wallets have already been
     * settled.
     *
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    public function handleExplicit(Billable $billable, array $lineItems): ?BillingInvoice
    {
        if ($lineItems === []) {
            return null;
        }

        return $this->charge($billable, $lineItems);
    }

    /**
     * @return array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>
     */
    private function buildLineItemsFromWallets(Billable $billable): array
    {
        if (! $billable instanceof Model) {
            return [];
        }

        $items = [];
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval();

        // Force a fresh fetch — wallets is a MorphMany.
        $wallets = $billable->wallets()->get();

        foreach ($wallets as $wallet) {
            $balance = (int) $wallet->balanceInt;

            if ($balance >= 0) {
                continue;
            }

            $slug = (string) $wallet->slug;
            $quantity = abs($balance);
            $unitPrice = (int) ($this->catalog->usageOveragePrice($planCode, $interval, $slug) ?? 0);

            if ($unitPrice <= 0) {
                continue;
            }

            $items[] = [
                'type' => $slug,
                'quantity' => $quantity,
                'unit_price_net' => $unitPrice,
                'total_net' => $quantity * $unitPrice,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    private function charge(Billable $billable, array $lineItems): ?BillingInvoice
    {
        $totalNet = array_sum(array_column($lineItems, 'total_net'));

        if ($totalNet <= 0) {
            return null;
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vatNumber = $billable instanceof Model ? ($billable->vat_number ?? null) : null;

        $vat = $this->vatService->calculate($country, $totalNet, $vatNumber);

        $payment = $this->createMolliePayment($billable, $vat, $lineItems);

        // Persist the pending overage on subscription_meta so the webhook can
        // create the BillingInvoice when the payment confirms.
        if ($billable instanceof Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['usage_overage'] = [
                'payment_id' => is_object($payment) ? ($payment->id ?? null) : null,
                'line_items' => $lineItems,
                'amount_net' => $vat['net'],
                'amount_gross' => $vat['gross'],
                'vat_amount' => $vat['vat'],
                'vat_rate' => $vat['rate'],
                'currency' => (string) config('mollie-billing.currency', 'EUR'),
                'created_at' => now()->toIso8601String(),
            ];
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        // The actual BillingInvoice is created in the webhook handler when
        // the payment moves to "paid" state.
        return null;
    }

    /**
     * Create a one-off Mollie payment for the overage gross amount.
     *
     * Uses Mollie\Laravel\Facades\Mollie if available; otherwise expects a
     * `mollie.api`/`Mollie\Api\MollieApiClient` binding in the container.
     *
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function createMolliePayment(Billable $billable, array $vat, array $lineItems): object
    {
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountValue = number_format($vat['gross'] / 100, 2, '.', '');

        return Mollie::send(new CreatePaymentRequest(
            description: 'Usage overage',
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => 'overage',
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'line_items' => $lineItems,
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
        ));
    }
}

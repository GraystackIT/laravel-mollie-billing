<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class StartOneTimeOrderCheckout
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {
    }

    /**
     * @param  array{product_code:string,metadata?:array}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws \InvalidArgumentException if the product code does not exist in the catalog
     * @throws \RuntimeException if the product has a zero price
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        $productCode = $request['product_code'];
        $product = $this->catalog->product($productCode);

        if ($product === []) {
            throw new \InvalidArgumentException("Unknown product code: {$productCode}");
        }

        $priceNet = $this->catalog->productPriceNet($productCode);
        if ($priceNet <= 0) {
            throw new \RuntimeException("Product \"{$productCode}\" has a zero or negative price.");
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vatResult = $this->vatService->calculate($country, $priceNet, $billable->vat_number ?? null);
        $amountGross = $vatResult['gross'];

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);
        $customMetadata = $request['metadata'] ?? [];

        $payment = Mollie::send(new CreatePaymentRequest(
            description: $this->catalog->productName($productCode) ?? $productCode,
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            redirectUrl: route(BillingRoute::name('return'), array_merge($urlParams, ['origin' => 'products'])),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: array_merge($customMetadata, [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'one_time_order',
                'product_code' => $productCode,
            ]),
        ));

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
        ];
    }
}

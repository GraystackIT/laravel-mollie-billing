<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Mollie\Laravel\Facades\Mollie;

class StartSubscriptionCheckout
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        $couponCode = $request['coupon_code'] ?? null;
        if ($couponCode === null || $couponCode === '') {
            $auto = Session::get('billing.auto_apply_coupon');
            if ($auto) {
                $couponCode = (string) $auto;
            }
        }

        $customerId = $this->ensureMollieCustomer($billable);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountGross = (int) $request['amount_gross'];

        $payment = Mollie::api()->payments->create([
            'amount' => [
                'currency' => $currency,
                'value' => number_format($amountGross / 100, 2, '.', ''),
            ],
            'description' => "Subscription {$request['plan_code']}",
            'redirectUrl' => route('billing.return'),
            'webhookUrl' => route('billing.webhook'),
            'customerId' => $customerId,
            'sequenceType' => 'first',
            'metadata' => [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'subscription',
                'plan_code' => $request['plan_code'],
                'interval' => $request['interval'],
                'addon_codes' => $request['addon_codes'] ?? [],
                'extra_seats' => $request['extra_seats'] ?? 0,
                'coupon_code' => $couponCode,
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

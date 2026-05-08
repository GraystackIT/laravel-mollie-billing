<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class StartSubscriptionCheckout
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly MollieCustomerResolver $customerResolver,
        private readonly StartMandateCheckout $mandateCheckout,
        private readonly ActivateLocalSubscription $activateLocal,
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws \RuntimeException if the billable already has an accessible subscription
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        if ($billable->hasAccessibleBillingSubscription()) {
            throw new \RuntimeException('Billable already has an active subscription.');
        }

        $couponCode = $request['coupon_code'] ?? null;
        if ($couponCode === null || $couponCode === '') {
            $auto = Session::get('billing.auto_apply_coupon');
            if ($auto) {
                $couponCode = (string) $auto;
            }
        }

        $amountGross = (int) $request['amount_gross'];
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        // AccessGrant coupons activate a Local subscription directly via the
        // CouponService — no Mollie mandate, no Mollie subscription. The grant's
        // duration_days defines the access window; the customer must re-checkout
        // after it expires.
        if ($couponCode !== null && $couponCode !== '') {
            $couponLookup = \GraystackIT\MollieBilling\Models\Coupon::query()
                ->whereRaw('UPPER(code) = ?', [strtoupper($couponCode)])
                ->first();

            if ($couponLookup !== null && $couponLookup->type === CouponType::AccessGrant) {
                // The strict-match check (plan/interval/addons/extra_seats must
                // match the grant) is enforced by validate() itself — passing
                // extraSeats + addonCodes here is what makes that work. Anything
                // beyond the grant is rejected before redeem(), since the local
                // subscription would otherwise be activated without ever
                // charging for the user's extras.
                $coupon = $this->couponService->validate($couponCode, $billable, [
                    'planCode' => $request['plan_code'],
                    'interval' => $request['interval'],
                    'addonCodes' => $request['addon_codes'] ?? [],
                    'extraSeats' => (int) ($request['extra_seats'] ?? 0),
                    'orderAmountNet' => $amountGross,
                    'allowed_types' => [CouponType::AccessGrant],
                ]);

                $this->couponService->redeem($coupon, $billable, []);

                return ['checkout_url' => null, 'payment_id' => ''];
            }
        }

        if ($amountGross === 0) {
            $planIsFree = $this->catalog->basePriceNet($request['plan_code'], $request['interval']) === 0;
            $hasCoupon = $couponCode !== null && $couponCode !== '';

            // Free plan with no coupon → activate as a Local subscription directly.
            // No Mollie mandate is required; the dashboard is reachable immediately.
            if ($planIsFree && ! $hasCoupon) {
                $this->activateLocal->handle(
                    $billable,
                    planCode: $request['plan_code'],
                    interval: $request['interval'],
                    addonCodes: $request['addon_codes'] ?? [],
                );

                return ['checkout_url' => null, 'payment_id' => ''];
            }

            // Full-coverage single_payment coupon → first charge is 0 €. Mollie rejects
            // 0-EUR subscription charges, so we route to the Mandate-Only flow instead:
            // a 0-EUR mandate-collection payment, with the subscription spec embedded in
            // metadata. The webhook activates the subscription once the mandate is captured.
            // Note: auto-apply coupons (PromotionController) only set the session code —
            // they don't recompute amount_gross, so a 100%-single_payment auto-apply does
            // not currently end up here. Out of scope for this implementation.
            return $this->mandateCheckout->handle(
                $billable,
                redirectUrl: route(BillingRoute::name('return'), $urlParams),
                subscriptionSpec: [
                    'plan_code' => $request['plan_code'],
                    'interval' => $request['interval'],
                    'addon_codes' => $request['addon_codes'] ?? [],
                    'extra_seats' => $request['extra_seats'] ?? 0,
                    'coupon_code' => $couponCode,
                ],
            );
        }

        $customerId = $this->customerResolver->resolve($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');

        $payment = Mollie::send(new CreatePaymentRequest(
            description: "Subscription {$request['plan_code']}",
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            cancelUrl: route(BillingRoute::checkout(), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'subscription',
                'plan_code' => $request['plan_code'],
                'interval' => $request['interval'],
                'addon_codes' => $request['addon_codes'] ?? [],
                'extra_seats' => $request['extra_seats'] ?? 0,
                'coupon_code' => $couponCode,
            ],
            sequenceType: 'first',
            customerId: $customerId,
        ));

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
        ];
    }
}

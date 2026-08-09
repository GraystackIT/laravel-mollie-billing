<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

class StartSubscriptionCheckout
{
    /**
     * Session key naming the billable this browser checked out for.
     *
     * Written by the checkout component, read by the return page. An anonymous signup's
     * billable has no owner yet, so `MollieBilling::authorizes()` — which fails closed —
     * answers false for the customer who just paid; the return page needs some claim
     * about who it is showing, and a session entry is bound to this browser in a way a
     * `?organization=` query parameter is not. Mirrors ORDER_TOKEN_PARAM, which does the
     * same job for one-time orders through the URL.
     */
    public const CHECKOUT_SUBJECT_SESSION_KEY = 'billing.checkout_subject';

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly MollieCustomerResolver $customerResolver,
        private readonly StartMandateCheckout $mandateCheckout,
        private readonly ActivateLocalSubscription $activateLocal,
        private readonly CouponService $couponService,
        private readonly \GraystackIT\MollieBilling\Services\Vat\VatCalculationService $vatService,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws \RuntimeException if the billable already has an accessible subscription
     * @throws HiddenPlanNotSelectableException if the requested plan is hidden
     * @throws IntervalNotOfferedException if the plan or an addon is not sold in the requested interval
     */
    public function handle(Billable $billable, array $request): array
    {
        /** @var Model&Billable $billable */
        if ($billable->hasAccessibleBillingSubscription()) {
            throw new \RuntimeException('Billable already has an active subscription.');
        }

        // Hidden plans are never bookable through checkout — not via the plan
        // step, not via `?plan=` on the checkout URL, and not via an AccessGrant
        // coupon pointing at one. Hidden plans are assigned programmatically
        // (ActivateLocalSubscription) or by an admin issuing a grant directly.
        if ($this->catalog->planIsHidden((string) $request['plan_code'])) {
            throw new HiddenPlanNotSelectableException($billable, (string) $request['plan_code']);
        }

        // An interval the plan does not sell has no price, and "no price" reads as
        // 0 all the way down — which the zero-amount branch below then treats as a
        // free plan and activates locally. That handed out a paid plan, with every
        // feature, for nothing. Checked here as well as in the UI because this is
        // the seam an app calling the service directly also passes through.
        if (! $this->catalog->planOffersInterval((string) $request['plan_code'], (string) $request['interval'])) {
            throw new IntervalNotOfferedException(
                (string) $request['plan_code'],
                (string) $request['interval'],
            );
        }

        foreach ((array) ($request['addon_codes'] ?? []) as $addonCode) {
            if (! $this->catalog->addonOffersInterval((string) $addonCode, (string) $request['interval'])) {
                throw new IntervalNotOfferedException((string) $addonCode, (string) $request['interval'], 'addon');
            }
        }

        $amountGross = (int) $request['amount_gross'];

        $couponCode = $request['coupon_code'] ?? null;
        $couponFromSession = false;

        if ($couponCode === null || $couponCode === '') {
            $auto = Session::get('billing.auto_apply_coupon');

            if ($auto) {
                $couponCode = (string) $auto;
                $couponFromSession = true;
            }
        }
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
                    // Net, not the gross the caller handed us: validate() compares this
                    // against `minimum_order_amount_net`, so a VAT-inflated gross would
                    // admit every order within the VAT rate below the configured floor.
                    'orderAmountNet' => $this->orderAmountNet($billable, $request),
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

        // Trial branch: when the (plan, interval) carries a positive trialDays
        // and the billable has not yet captured a Mollie mandate, route to the
        // Mandate-Only flow. The mandate is captured at 0 EUR; the actual Mollie
        // subscription is created in the webhook with startDate = now + trialDays
        // (no charge during the trial window). Trials only fire on first checkout —
        // the `! hasMollieMandate()` guard prevents granting a fresh trial to a
        // returning customer who has previously paid.
        $trialDays = $this->catalog->trialDays($request['plan_code'], $request['interval']);
        if ($trialDays > 0 && ! $billable->hasMollieMandate()) {
            return $this->mandateCheckout->handle(
                $billable,
                redirectUrl: route(BillingRoute::name('return'), $urlParams),
                subscriptionSpec: [
                    'plan_code' => $request['plan_code'],
                    'interval' => $request['interval'],
                    'addon_codes' => $request['addon_codes'] ?? [],
                    'extra_seats' => $request['extra_seats'] ?? 0,
                    'coupon_code' => $couponCode,
                    'trial_days' => $trialDays,
                ],
            );
        }

        // Everything above either charges nothing now (trial, mandate-only) or is a
        // grant. From here real money is collected, and this is where a session coupon
        // has to prove itself: a promotion link puts the code in the session, and it was
        // spliced into the payment metadata regardless — while `amount_gross` came from a
        // form that knew nothing about it. So the customer paid FULL price, the webhook
        // then priced the coupon, booked a discount line that reconcileWithPaidAmount
        // immediately cancelled out (firing PaymentAmountMismatch on a perfectly good
        // payment), and redeem() burned their one per-billable slot plus the campaign
        // counter for a discount they never received. Paying full price and losing the
        // coupon is strictly worse than not having applied it, so on a mismatch the code
        // is dropped and the customer can still enter it by hand.
        if ($couponFromSession
            && $couponCode !== null
            && ! $this->amountReflectsCoupon($billable, $request, $couponCode, $amountGross)
        ) {
            Log::info('Auto-apply coupon dropped — the submitted amount was not priced with it', [
                'billable_id' => $billable->getKey(),
                'coupon_code' => $couponCode,
                'amount_gross' => $amountGross,
            ]);

            $couponCode = null;
        }

        $customerId = $this->customerResolver->resolve($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: "Subscription {$request['plan_code']}",
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            locale: MollieBilling::mollieLocale(),
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

    /**
     * The requested subscription's recurring net (plan + seats + addons), which is the
     * basis every `orderAmountNet` consumer in CouponService expects.
     *
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int}  $request
     */
    private function orderAmountNet(Billable $billable, array $request): int
    {
        $planCode = (string) $request['plan_code'];
        $interval = (string) $request['interval'];
        $totalSeats = $this->catalog->includedSeats($planCode) + max(0, (int) ($request['extra_seats'] ?? 0));

        return SubscriptionAmount::net(
            $this->catalog,
            $billable,
            $planCode,
            $interval,
            $totalSeats,
            $request['addon_codes'] ?? [],
        );
    }

    /**
     * Whether `amount_gross` looks like it was priced WITH this coupon.
     *
     * Compared in net space, against a 1-cent tolerance: the caller may round its VAT
     * per line while this compares totals, and the question is only "discounted or
     * not", where the two candidates are a whole discount apart.
     *
     * Coupon types that carry no monetary discount (TrialExtension, AccessGrant,
     * Credits, PeriodExtension) are admitted unconditionally — there is nothing for
     * the amount to reflect, and AccessGrant is handled before this point anyway.
     *
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int}  $request
     */
    private function amountReflectsCoupon(Billable $billable, array $request, string $couponCode, int $amountGross): bool
    {
        $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($couponCode)])
            ->first();

        if ($coupon === null) {
            return false;
        }

        if (! in_array($coupon->type, [CouponType::SinglePayment, CouponType::Recurring], true)) {
            return true;
        }

        $net = $this->orderAmountNet($billable, $request);
        $discount = $this->couponService->computeRecurringDiscount($coupon, $net);

        if ($discount <= 0) {
            return true;
        }

        $country = (string) ($billable->getBillingCountry() ?? config('mollie-billing.checkout_country_fallback', 'DE'));

        try {
            $vat = $this->vatService->calculate($country, max(0, $net - $discount), $billable);
        } catch (\Throwable) {
            // VAT unavailable: fall back to a net-space comparison rather than
            // guessing, and let the amount decide.
            return $amountGross <= max(0, $net - $discount) + 1;
        }

        return abs($amountGross - (int) $vat['gross']) <= 1;
    }
}

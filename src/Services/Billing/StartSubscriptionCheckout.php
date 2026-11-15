<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
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

    /** Session key a promotion link stores its coupon code under. See forgetSessionCoupon(). */
    public const AUTO_APPLY_COUPON_SESSION_KEY = 'billing.auto_apply_coupon';

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
            // The component only lists the plan's allowed addons; this is the seam an app
            // calling the service directly passes through, and activation persists the
            // codes verbatim into active_addon_codes, where FeatureAccess grants them.
            if (! $this->catalog->planAllowsAddon((string) $request['plan_code'], (string) $addonCode)) {
                throw new AddonNotAllowedException((string) $request['plan_code'], (string) $addonCode);
            }

            if (! $this->catalog->addonOffersInterval((string) $addonCode, (string) $request['interval'])) {
                throw new IntervalNotOfferedException((string) $addonCode, (string) $request['interval'], 'addon');
            }
        }

        // Stated, not inferred. `handle()` is a documented entry point and its signature
        // says this key is required — but reading it straight out of the array turned a
        // caller's omission into `(int) null`, which is 0, and 0 on a *paid* plan falls
        // through to the €0 mandate-only branch below: a mandate is captured, the
        // subscription is activated, and the first period is never charged. A price that
        // was never supplied is not a price of zero.
        if (! array_key_exists('amount_gross', $request) || ! is_numeric($request['amount_gross'])) {
            throw new \InvalidArgumentException(
                'StartSubscriptionCheckout::handle() requires an `amount_gross` (in minor units) — '
                .'it is what Mollie collects. Compute it from the catalog, including seats, addons, '
                .'the coupon and VAT, the way the checkout component does.'
            );
        }

        $amountGross = (int) $request['amount_gross'];

        $couponCode = $request['coupon_code'] ?? null;
        $couponFromSession = false;

        if ($couponCode === null || $couponCode === '') {
            $auto = Session::get(self::AUTO_APPLY_COUPON_SESSION_KEY);

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

                $this->forgetSessionCoupon($couponFromSession);

                return ['checkout_url' => null, 'payment_id' => ''];
            }
        }

        $planIsFree = $this->catalog->basePriceNet($request['plan_code'], $request['interval']) === 0;
        $hasCoupon = $couponCode !== null && $couponCode !== '';

        if ($amountGross === 0 && $planIsFree) {
            // Free plan → activate as a Local subscription directly. No Mollie mandate
            // is required; the dashboard is reachable immediately.
            //
            // A coupon does not change that when the order itself costs nothing: there
            // is no amount for it to discount. The branch used to read "free AND no
            // coupon", and a promotion link leaves its code in the session for every
            // checkout that follows — so a customer who arrived through one and picked
            // the free plan was sent to Mollie to authorise a payment method for a plan
            // that costs nothing, and the webhook then refused the activation because a
            // coupon cannot cover 100 % of 0. Mandate captured, no subscription, and the
            // mandate cost them the trial on any later paid checkout.
            //
            // A free base with priced extras and a coupon is still the full-coverage
            // case below: there the coupon is what makes the total zero.
            $orderIsFree = $this->orderAmountNet($billable, $request) === 0;

            if (! $hasCoupon || $orderIsFree) {
                if ($hasCoupon) {
                    Log::info('Coupon ignored — the order is free, there is nothing to discount', [
                        'billable_id' => $billable->getKey(),
                        'coupon_code' => $couponCode,
                    ]);
                }

                $this->activateLocal->handle(
                    $billable,
                    planCode: $request['plan_code'],
                    interval: $request['interval'],
                    addonCodes: $request['addon_codes'] ?? [],
                );

                $this->forgetSessionCoupon($couponFromSession);

                return ['checkout_url' => null, 'payment_id' => ''];
            }
        }

        // From here on a payment is created at Mollie, so the country has to be one the
        // webhook can write an invoice for. See assertCountryIsInvoiceable().
        $this->assertCountryIsInvoiceable($billable);

        // Trial branch: when the (plan, interval) carries a positive trialDays
        // and the billable has not yet captured a Mollie mandate, route to the
        // Mandate-Only flow. The mandate is captured at 0 EUR; the actual Mollie
        // subscription is created in the webhook with startDate = now + trialDays
        // (no charge during the trial window). Trials only fire on first checkout —
        // the `! hasMollieMandate()` guard prevents granting a fresh trial to a
        // returning customer who has previously paid.
        //
        // Decided BEFORE the zero-amount branch, so the trial wins over a coupon that
        // covers the whole first payment. In the other order a 100 % coupon dropped
        // `trial_days`: the confirm step had announced "14 days free, first charge on
        // <date>", and the customer got no trial, an Active subscription at once and
        // the coupon spent on the period that was supposed to be free. What the coupon
        // then does is the trial path's own, documented rule (docs/coupons.md): a
        // Recurring one sets its marker and defers Mollie's start date to the later of
        // trial end and coupon end; a SinglePayment one is parked, not consumed.
        $trialDays = $this->catalog->trialDays($request['plan_code'], $request['interval']);
        if ($trialDays > 0
            && ! $billable->hasMollieMandate()
            // A trial is for something that costs money. `amount_gross` alone cannot say
            // so any more — it is 0 under a full-coverage coupon — so ask the catalog.
            && ($amountGross > 0 || $this->orderAmountNet($billable, $request) > 0)
        ) {
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

        if ($amountGross === 0) {
            // Full-coverage coupon with no trial to start → first charge is 0 €. Mollie rejects
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
            $this->forgetSessionCoupon(true);
        }

        // Judged against this billable, not just the campaign. The component validated
        // without one, which cannot see the per-billable limit — so a returning customer's
        // spent coupon priced the payment, the webhook (which does check per billable)
        // rejected it, booked the shortfall as an adjustment and activated the subscription
        // at the discounted price regardless. Refused here, before any money moves.
        if ($couponCode !== null && $couponCode !== '') {
            $couponCode = $this->couponEligibleForPaidCheckout($billable, $request, $couponCode, $couponFromSession);
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
     * Drop the promotion coupon from the session once it has reached a definitive end:
     * redeemed (an access grant), or shown to be unusable for this checkout.
     *
     * `PromotionController` writes the key and nothing ever removed it, so one promotion
     * link steered every later checkout of that session — including ones the code could
     * do nothing for. It is deliberately NOT cleared when a payment has merely been
     * created: a customer who cancels on Mollie's page and comes back must still find
     * their promotion applied.
     */
    private function forgetSessionCoupon(bool $fromSession): void
    {
        if ($fromSession) {
            Session::forget(self::AUTO_APPLY_COUPON_SESSION_KEY);
        }
    }

    /**
     * Refuse to open a payment for a country no invoice can be written for.
     *
     * The webhook prices the paid payment through `VatCalculationService::calculate()`
     * with no catch, so a billing country that is neither an EU member nor declared under
     * `additional_countries` made every delivery throw: money collected, no invoice, no
     * subscription. The probe goes through `calculate()` itself — the one owner of "is
     * this country billable" — and reads only persisted state.
     *
     * A billable without a country is not refused here: the webhook adopts
     * `checkout_country_fallback` for it (WebhookSupport::ensureBillingCountry()).
     *
     * @throws \GraystackIT\MollieBilling\Exceptions\NonEuCountryException
     */
    private function assertCountryIsInvoiceable(Billable $billable): void
    {
        $country = (string) ($billable->getBillingCountry() ?? '');

        if ($country === '') {
            $country = (string) config('mollie-billing.checkout_country_fallback', 'DE');
        }

        $this->vatService->calculate($country, 1, $billable);
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
    /**
     * The coupon code to embed in a paid first payment, after the billable-aware check
     * FirstPaymentCouponPricing::price() will apply when the payment settles.
     *
     * Only the types that change the amount are judged — the others move no money here.
     * A typed code that fails is an error the customer can act on; a code that arrived
     * from a promotion link is dropped instead, for the reason the mismatch branch above
     * gives.
     *
     * @param  array<string, mixed>  $request
     */
    private function couponEligibleForPaidCheckout(Billable $billable, array $request, string $couponCode, bool $fromSession): ?string
    {
        $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($couponCode)])
            ->first();

        if ($coupon === null || ! in_array($coupon->type, [CouponType::SinglePayment, CouponType::Recurring], true)) {
            return $couponCode;
        }

        try {
            $this->couponService->validate($couponCode, $billable, [
                'planCode' => $request['plan_code'],
                'interval' => $request['interval'],
                'addonCodes' => $request['addon_codes'] ?? [],
                'orderAmountNet' => $this->orderAmountNet($billable, $request),
                'trial_starting' => false,
                'allowed_types' => [CouponType::SinglePayment, CouponType::Recurring],
            ]);
        } catch (InvalidCouponException $e) {
            if (! $fromSession) {
                throw $e;
            }

            Log::info('Auto-apply coupon dropped — not redeemable for this billable', [
                'billable_id' => $billable->getKey(),
                'coupon_code' => $couponCode,
                'reason' => $e->reason(),
            ]);

            $this->forgetSessionCoupon(true);

            return null;
        }

        return $couponCode;
    }

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

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\HiddenPlanNotSelectableException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Convert a local (free / coupon-granted) subscription into a Mollie subscription.
 *
 * Acts as a thin adapter to the Mollie API: VAT, coupon discount and the gross
 * amount are computed up-front by the calling UI (typically PreviewService) —
 * the service receives a ready `amount_gross`. The first payment carries the
 * `upgrade_from_local: true` metadata flag so the webhook handler can take the
 * wallet-preserving conversion path instead of the standard "first activation"
 * flow.
 */
class UpgradeLocalToMollie
{
    public function __construct(
        private readonly MollieCustomerResolver $customerResolver,
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross:int}  $request
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws LocalSubscriptionUpgradeRequiresMolliePathException When called on a non-local subscription
     * @throws HiddenPlanNotSelectableException When the target plan is hidden
     */
    public function handle(Billable $billable, array $request): array
    {
        if (! $billable->isLocalBillingSubscription()) {
            throw new LocalSubscriptionUpgradeRequiresMolliePathException(
                $billable,
                (string) $request['plan_code'],
            );
        }

        // The Local→Mollie upgrade is a customer-driven plan choice made in the
        // portal, so it obeys the same rule as checkout. Staying on the current
        // plan is exempt — a hidden free plan converting to paid on the same
        // plan code is a mandate change, not a move onto a hidden plan.
        $targetPlan = (string) $request['plan_code'];
        if ($targetPlan !== $billable->getBillingSubscriptionPlanCode()
            && $this->catalog->planIsHidden($targetPlan)) {
            throw new HiddenPlanNotSelectableException($billable, $targetPlan);
        }

        // Extra seats on a plan that does not sell them price at 0 — the payment would
        // carry them in its metadata and CreateSubscription store them as paid capacity.
        // The same rule ValidateSubscriptionChange applies to every other seat change.
        $extraSeats = max(0, (int) ($request['extra_seats'] ?? 0));
        if ($extraSeats > 0 && $this->catalog->seatPriceNet($targetPlan, (string) $request['interval']) === null) {
            $included = $this->catalog->includedSeats($targetPlan);

            throw new SeatDowngradeRequiredException($billable, $included + $extraSeats, $included);
        }

        $amountGross = (int) $request['amount_gross'];
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        if ($amountGross <= 0) {
            return $this->startFullCoverageUpgrade($billable, $request, $extraSeats, $urlParams);
        }

        $customerId = $this->customerResolver->resolve($billable);

        $currency = (string) config('mollie-billing.currency', 'EUR');

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: "Upgrade to {$request['plan_code']}",
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            locale: MollieBilling::mollieLocale(),
            cancelUrl: route(BillingRoute::name('plan'), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'subscription',
                'plan_code' => $request['plan_code'],
                'interval' => $request['interval'],
                'addon_codes' => $request['addon_codes'] ?? [],
                'extra_seats' => $extraSeats,
                'coupon_code' => $request['coupon_code'] ?? null,
                'upgrade_from_local' => true,
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
     * An upgrade nothing is owed for today: a code covers the whole price.
     *
     * A paid plan still needs a mandate, and a mandate needs a first payment — so this is
     * the checkout's own answer to the same order: a €0 authorisation (`mandate_only`),
     * with the order riding in the metadata. `MandateOnlyPaymentHandler` activates it,
     * exactly as it does for a checkout; the `upgrade_from_local` flag only tells it that
     * the free plan it finds is the one being replaced, not a duplicate.
     *
     * Refused before anything is sent to Mollie when there is no code, or the code does
     * not cover the order in full: the handler would capture the mandate and then decline
     * to activate, which is a customer left with nothing and no explanation.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $urlParams
     * @return array{checkout_url:?string,payment_id:string}
     *
     * @throws \InvalidArgumentException
     */
    private function startFullCoverageUpgrade(Billable $billable, array $request, int $extraSeats, array $urlParams): array
    {
        $planCode = (string) $request['plan_code'];
        $interval = (string) $request['interval'];
        $addonCodes = array_values((array) ($request['addon_codes'] ?? []));
        $couponCode = trim((string) ($request['coupon_code'] ?? ''));

        if ($couponCode === '') {
            throw new \InvalidArgumentException('Nothing in this upgrade has to be paid for, and no code explains why — it cannot be started.');
        }

        $priced = app(\GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing::class)
            ->price($billable, $couponCode, $planCode, $interval, $addonCodes, $extraSeats);

        if ($priced === null || $priced['order_amount_net'] <= 0 || $priced['discount_net'] !== $priced['order_amount_net']) {
            throw new \InvalidArgumentException("Code [{$couponCode}] does not cover this upgrade in full — it has to be paid for.");
        }

        return app(StartMandateCheckout::class)->handle(
            $billable,
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            subscriptionSpec: [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'coupon_code' => $couponCode,
                'upgrade_from_local' => true,
            ],
        );
    }

    /**
     * Start the purchase of paid addons or extra seats by a tenant who was *given* their
     * plan, keeping the gift.
     *
     * The plan (and any addons the grant itself included) stays free for as long as the
     * grant runs; only what is added is paid. This is the one path where the amount is
     * computed here rather than handed in: the price depends on what the gift waives, and
     * that must not be something a form can submit.
     *
     * The first payment covers one full period of the paid extras and starts that period
     * now. The webhook turns the rest of the grant into a plan waiver on the new Mollie
     * subscription — see `LocalToMollieUpgradeHandler` and `PlanWaiver`.
     *
     * @param  array<int, string>  $addonCodes  the complete addon set after the purchase
     * @param  int  $seats  the complete seat count after the purchase
     * @return array{checkout_url:?string,payment_id:string,amount_gross:int,amount_net:int}
     *
     * @throws LocalSubscriptionUpgradeRequiresMolliePathException when there is no running grant to keep
     * @throws SeatDowngradeRequiredException when the plan does not sell the requested seats
     * @throws \InvalidArgumentException when nothing in the request has to be paid for
     */
    public function handleKeepingGrant(Billable $billable, array $addonCodes, int $seats): array
    {
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');
        $grantEndsAt = $billable->getBillingSubscriptionEndsAt();

        if (
            ! $billable->isLocalBillingSubscription()
            || $planCode === ''
            || $grantEndsAt === null
            || ! $grantEndsAt->isFuture()
            || $billable->getBillingSubscriptionStatus() !== \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active
        ) {
            throw new LocalSubscriptionUpgradeRequiresMolliePathException($billable, $planCode);
        }

        $grantedAddons = array_values($billable->getActiveBillingAddonCodes());
        $addonCodes = array_values(array_unique(array_map('strval', $addonCodes)));

        foreach (array_diff($addonCodes, $grantedAddons) as $code) {
            if (! $this->catalog->planAllowsAddon($planCode, $code)) {
                throw new \GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException($planCode, $code);
            }

            if (! $this->catalog->addonOffersInterval($code, $interval)) {
                throw new \GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException($code, $interval, 'addon');
            }
        }

        $included = $this->catalog->includedSeats($planCode);
        $seats = max($seats, $included, $billable->getUsedBillingSeats());
        $extraSeats = $seats - $included;

        if ($extraSeats > 0 && $this->catalog->seatPriceNet($planCode, $interval) === null) {
            throw new SeatDowngradeRequiredException($billable, $seats, $included);
        }

        if ($seats > (int) config('mollie-billing.max_seats', 1000)) {
            throw new \GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException(
                $billable,
                $seats,
                (int) config('mollie-billing.max_seats', 1000),
            );
        }

        $fullNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);
        $waived = min($fullNet, app(PlanWaiver::class)->waivedNet(
            $billable,
            $planCode,
            $interval,
            $seats,
            $grantedAddons,
            $addonCodes,
        ));
        $netDue = $fullNet - $waived;

        if ($netDue <= 0) {
            throw new \InvalidArgumentException('Nothing in this change has to be paid for — it does not need a Mollie subscription.');
        }

        // A gifted tenant may never have been through a checkout, so there may be no
        // billing address at all. That is not "a country we cannot invoice" — it is a
        // tenant who has to fill in their billing details first, and the portal says so.
        $country = trim((string) ($billable->getBillingCountry() ?? ''));

        if ($country === '') {
            throw new \GraystackIT\MollieBilling\Exceptions\BillingCountryRequiredException;
        }

        $vat = app(VatCalculationService::class)->calculate($country, $netDue, $billable);

        // Per line, like every other first payment: the invoice the webhook writes sums
        // per-line VAT, and the Mollie subscription is created from the same lines.
        $amountGross = BillingMoney::grossWithLineVat(
            MollieSubscriptionPatcher::recurringLineNets(
                $this->catalog,
                $billable,
                $planCode,
                $interval,
                $addonCodes,
                $extraSeats,
                $netDue,
            ),
            (float) $vat['rate'],
        );

        $customerId = $this->customerResolver->resolve($billable);
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $urlParams = MollieBilling::resolveUrlParameters($billable);

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: "Extras on {$planCode}",
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            redirectUrl: route(BillingRoute::name('return'), $urlParams),
            locale: MollieBilling::mollieLocale(),
            cancelUrl: route(BillingRoute::name('index'), $urlParams),
            webhookUrl: route(BillingRoute::webhook()),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'type' => 'subscription',
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'coupon_code' => null,
                'upgrade_from_local' => true,
                // The webhook turns the remaining grant into a plan waiver. The granted
                // addons are recorded here, at the moment the customer agreed to the
                // price — they are what was free before anything was bought.
                'keep_grant' => true,
                'granted_addon_codes' => $grantedAddons,
            ],
            sequenceType: 'first',
            customerId: $customerId,
        ));

        return [
            'checkout_url' => method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null,
            'payment_id' => $payment->id,
            'amount_gross' => $amountGross,
            'amount_net' => $netDue,
        ];
    }
}

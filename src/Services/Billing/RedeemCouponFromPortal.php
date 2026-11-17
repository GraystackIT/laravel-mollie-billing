<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\AccessGrantConflictsWithMollieSubscriptionException;
use GraystackIT\MollieBilling\Exceptions\AccessGrantRequiresActiveSubscriptionException;
use GraystackIT\MollieBilling\Exceptions\CouponNotStackableException;
use GraystackIT\MollieBilling\Exceptions\CouponRequiresTrialException;
use GraystackIT\MollieBilling\Exceptions\GrantMismatchException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;

/**
 * The portal's one "redeem a code" field.
 *
 * The customer has a code and no idea which of seven coupon types it is. The dashboard
 * used to redeem four of them and answer the rest with "enter this code when changing
 * your plan, seats, or addons" — which for a recurring discount meant making a change
 * nobody wanted in order to get a discount, and for a single-payment code was simply
 * wrong. This recognises the type and does what that type means for THIS billable, or
 * says in a sentence the customer can act on why the code does not work here:
 *
 *  - credits, trial / period extension, plan waiver: redeemed at once, as before;
 *  - recurring: applied to the running Mollie subscription — marker, Mollie amount, and
 *    a redemption row without a discount (nothing is charged now; the discount arrives
 *    with the renewals). This is the "PATCH-only" case of docs/coupons.md;
 *  - single payment: never redeemable on a running subscription — the answer says what
 *    the code is for;
 *  - access grant: activates the gifted plan for a billable without a subscription, or
 *    extends a gift of the same plan; otherwise the reason.
 *
 * Authorisation is the caller's (the Livewire component): this service is also what an
 * app's own UI or API calls.
 *
 * No Mollie call happens inside a transaction here: `CouponService::redeem()` commits
 * first, the PATCH follows, and a PATCH that fails is queued rather than thrown — the
 * discount has been granted, Mollie only has to catch up.
 */
class RedeemCouponFromPortal
{
    public function __construct(
        private readonly CouponService $coupons,
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly PlanWaiver $waiver,
    ) {
    }

    public function handle(Billable $billable, string $code): PortalCouponOutcome
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return PortalCouponOutcome::refused(__('billing::checkout.coupon_empty'), 'empty');
        }

        $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [$code])->first();

        if ($coupon === null) {
            return PortalCouponOutcome::refused(self::reasonText('not_found'), 'not_found');
        }

        try {
            return match ($coupon->type) {
                CouponType::Recurring => $this->recurring($billable, $coupon),
                CouponType::SinglePayment => $this->singlePayment($billable, $coupon),
                CouponType::AccessGrant => $this->accessGrant($billable, $coupon),
                default => $this->immediate($billable, $coupon),
            };
        } catch (InvalidCouponException $e) {
            return PortalCouponOutcome::refused(self::reasonText($e->reason()), $e->reason(), $coupon);
        } catch (CouponRequiresTrialException) {
            return PortalCouponOutcome::refused(__('billing::portal.coupon_requires_running_trial'), 'requires_trial', $coupon);
        } catch (AccessGrantConflictsWithMollieSubscriptionException) {
            return PortalCouponOutcome::refused(__('billing::portal.coupon_grant_has_paid_subscription'), 'grant_has_paid_subscription', $coupon);
        } catch (AccessGrantRequiresActiveSubscriptionException) {
            return PortalCouponOutcome::refused(__('billing::portal.coupon_grant_needs_free_subscription'), 'grant_needs_free_subscription', $coupon);
        } catch (GrantMismatchException) {
            return PortalCouponOutcome::refused(__('billing::portal.coupon_grant_other_plan'), 'grant_other_plan', $coupon);
        } catch (CouponNotStackableException) {
            return PortalCouponOutcome::refused(__('billing::checkout.coupon_not_stackable_with_current'), 'not_stackable', $coupon);
        }
    }

    /**
     * The sentence for a validation reason. One table, shared with the dashboard.
     */
    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'not_found' => __('billing::checkout.coupon_not_found'),
            'inactive' => __('billing::checkout.coupon_inactive'),
            'not_yet_valid' => __('billing::checkout.coupon_not_yet_valid'),
            'expired' => __('billing::checkout.coupon_expired'),
            'globally_exhausted' => __('billing::checkout.coupon_exhausted'),
            'plan_not_applicable' => __('billing::checkout.coupon_plan_mismatch'),
            'interval_not_applicable' => __('billing::checkout.coupon_interval_mismatch'),
            'addon_not_applicable' => __('billing::checkout.coupon_addon_mismatch'),
            'product_not_applicable' => __('billing::checkout.coupon_product_mismatch'),
            'usage_not_applicable' => __('billing::checkout.coupon_usage_mismatch'),
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
            'recurring_conflict' => __('billing::checkout.coupon_recurring_conflict'),
            'requires_active_subscription' => __('billing::checkout.coupon_requires_active_subscription'),
            'too_close_to_charge' => __('billing::checkout.coupon_too_close_to_charge'),
            'per_billable_limit_reached' => __('billing::checkout.coupon_per_billable_limit_reached'),
            'full_coverage_use_access_grant' => __('billing::checkout.coupon_full_coverage_use_access_grant'),
            'recurring_already_active' => __('billing::checkout.coupon_recurring_already_active'),
            'type_not_allowed_in_context' => __('billing::checkout.coupon_type_not_allowed_in_context'),
            'grant_plan_mismatch', 'grant_interval_mismatch' => __('billing::portal.coupon_grant_other_plan'),
            default => __('billing::portal.coupon_redeem_failed'),
        };
    }

    /**
     * The order a coupon is validated against: what the billable is billed for today.
     *
     * @return array{planCode: ?string, interval: string, addonCodes: array<int, string>, orderAmountNet: int}
     */
    private function context(Billable $billable): array
    {
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');
        $addons = $billable->getActiveBillingAddonCodes();

        $seats = $planCode !== ''
            ? $this->catalog->includedSeats($planCode) + max(0, $billable->getExtraBillingSeats())
            : 0;

        return [
            'planCode' => $planCode !== '' ? $planCode : null,
            'interval' => $interval,
            'addonCodes' => $addons,
            'orderAmountNet' => $planCode !== ''
                ? SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addons)
                : 0,
        ];
    }

    /** Credits, trial extension, period extension, plan waiver: they act at once. */
    private function immediate(Billable $billable, Coupon $coupon): PortalCouponOutcome
    {
        $context = $this->context($billable);

        $this->coupons->validate((string) $coupon->code, $billable, $context + [
            'allowed_types' => [
                CouponType::Credits,
                CouponType::TrialExtension,
                CouponType::PeriodExtension,
                CouponType::PlanWaiver,
            ],
        ]);

        $this->coupons->redeem($coupon, $billable, [
            'planCode' => $context['planCode'],
            'interval' => $context['interval'],
            'orderAmountNet' => $context['orderAmountNet'],
        ]);

        return PortalCouponOutcome::redeemed(
            __('billing::portal.coupon_redeem_success', ['code' => $coupon->code]),
            $coupon,
        );
    }

    private function recurring(Billable $billable, Coupon $coupon): PortalCouponOutcome
    {
        // A recurring discount reduces what Mollie collects at every renewal. Without a
        // running Mollie subscription there is nothing for it to reduce.
        $status = $billable->getBillingSubscriptionStatus();
        $meta = $billable->getBillingSubscriptionMeta();

        if (
            ! $billable instanceof Model
            || $billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value
            || ! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)
            || empty($meta['mollie_subscription_id'])
        ) {
            // The code itself may be the problem (expired, switched off): say that first.
            $this->coupons->validateWithoutBillable((string) $coupon->code, $this->context($billable));

            return PortalCouponOutcome::refused(
                __('billing::portal.coupon_recurring_needs_paid_subscription'),
                'recurring_needs_paid_subscription',
                $coupon,
            );
        }

        $context = $this->context($billable);

        // A plan waiver holds the one recurring-discount slot. validate() says
        // `recurring_conflict` for it too; the customer is better served by which one.
        if ($this->waiver->marker($billable) !== null) {
            return PortalCouponOutcome::refused(
                __('billing::portal.coupon_recurring_conflict_plan_waiver'),
                'recurring_conflict',
                $coupon,
            );
        }

        $this->coupons->validate((string) $coupon->code, $billable, $context + [
            'allowed_types' => [CouponType::Recurring],
        ]);

        // A change that is waiting for its payment will PATCH Mollie to ITS amount when
        // it settles, computed before this discount existed.
        foreach (['prorata_pending_payment_id', 'pending_prorata_change', 'pending_plan_change'] as $key) {
            if (! empty($meta[$key])) {
                return PortalCouponOutcome::refused(
                    __('billing::portal.coupon_recurring_change_in_progress'),
                    'change_in_progress',
                    $coupon,
                );
            }
        }

        // The discount's first charge is the next one, so its lifetime is counted from
        // there — not from today, which would end a "3 periods" code typed in on the
        // first day of a period one day before its third charge.
        // Counted in the subscription's own periods, on its billing day — which the next
        // charge may only show shortened (28 February of a cycle on the 31st).
        $firstCharge = ChargeDeferral::undeferredNextChargeDate($billable) ?? BillingTime::nowUtc();
        $until = $this->coupons->recurringMarkerValidUntilOnCycle(
            $coupon,
            $context['interval'],
            $firstCharge,
            $this->coupons->recurringAnchorDay($billable, $context['interval'], $firstCharge),
        );

        if ($until !== null && $until->lessThanOrEqualTo($firstCharge)) {
            return PortalCouponOutcome::refused(
                __('billing::portal.coupon_recurring_ends_before_next_charge'),
                'ends_before_next_charge',
                $coupon,
            );
        }

        // Nothing is charged now, so nothing is discounted now: the row records the
        // grant (and occupies the customer's slot), the discount arrives with the
        // renewals. Commits before Mollie is called.
        $this->coupons->redeem($coupon, $billable, [
            'planCode' => $context['planCode'],
            'interval' => $context['interval'],
            'orderAmountNet' => $context['orderAmountNet'],
            'discount_amount_net' => 0,
            'lifetime_from' => $firstCharge,
        ]);

        // Mollie's amount follows the marker. Queued for the hourly sweep when it does
        // not land — the same re-price a plan waiver uses, which prices the billable
        // from whatever marker it holds.
        $billable->refresh();
        $this->waiver->syncMollieOrQueue($billable, (int) $coupon->id);
        $billable->refresh();

        return PortalCouponOutcome::redeemed(
            $this->recurringMessage($billable, $coupon, $firstCharge, $until),
            $coupon,
        );
    }

    private function recurringMessage(
        Billable $billable,
        Coupon $coupon,
        CarbonInterface $firstCharge,
        ?CarbonInterface $until,
    ): string {
        $date = static fn (CarbonInterface $at): string => (string) BillingTime::display($at, $billable)?->translatedFormat('d. M Y');

        // The discount covers everything: Mollie's charge was moved behind it. What the
        // customer wants to know is when money is next collected.
        $deferred = ChargeDeferral::until($billable);

        if ($deferred !== null) {
            return __('billing::portal.coupon_recurring_applied_free', [
                'code' => $coupon->code,
                'until' => $date($deferred),
            ]);
        }

        $periods = (int) ($coupon->discount_periods ?? 0);
        $dated = $coupon->valid_until !== null;

        $key = match (true) {
            $periods > 0 && $dated => 'coupon_recurring_applied_both',
            $periods > 0 => 'coupon_recurring_applied_periods',
            default => 'coupon_recurring_applied_until',
        };

        return __('billing::portal.'.$key, [
            'code' => $coupon->code,
            'from' => $date($firstCharge),
            'count' => $periods,
            'until' => $coupon->valid_until !== null ? $date($coupon->valid_until) : ($until !== null ? $date($until) : ''),
        ]);
    }

    /**
     * A one-off discount belongs to a first payment or a product purchase. A running
     * subscription has neither, and its recurring amount is not something a one-charge
     * discount can live on — so the answer is what the code IS for.
     */
    private function singlePayment(Billable $billable, Coupon $coupon): PortalCouponOutcome
    {
        // Expired, switched off, used up: that is the more useful answer.
        $now = BillingTime::nowUtc();

        if (! $coupon->active) {
            return PortalCouponOutcome::refused(self::reasonText('inactive'), 'inactive', $coupon);
        }

        if ($coupon->valid_from !== null && $coupon->valid_from->isAfter($now)) {
            return PortalCouponOutcome::refused(self::reasonText('not_yet_valid'), 'not_yet_valid', $coupon);
        }

        if ($coupon->valid_until !== null && $coupon->valid_until->isBefore($now)) {
            return PortalCouponOutcome::refused(self::reasonText('expired'), 'expired', $coupon);
        }

        if (! $coupon->hasGlobalRedemptionsLeft()) {
            return PortalCouponOutcome::refused(self::reasonText('globally_exhausted'), 'globally_exhausted', $coupon);
        }

        // Already used as often as this customer may use it: telling them where to
        // enter it would send them to a refusal.
        if (! $this->coupons->hasPerBillableRedemptionLeft($coupon, $billable)) {
            return PortalCouponOutcome::refused(self::reasonText('per_billable_limit_reached'), 'per_billable_limit_reached', $coupon);
        }

        $products = array_values(array_filter(array_map(
            fn ($code): ?string => $this->catalog->productName((string) $code) ?? (string) $code,
            (array) ($coupon->applicable_products ?? []),
        )));

        if ($products !== []) {
            return PortalCouponOutcome::refused(
                __('billing::portal.coupon_single_payment_products', ['products' => implode(', ', $products)]),
                'single_payment_products',
                $coupon,
            );
        }

        return PortalCouponOutcome::refused(
            __('billing::portal.coupon_single_payment_checkout'),
            'single_payment_checkout',
            $coupon,
        );
    }

    private function accessGrant(Billable $billable, Coupon $coupon): PortalCouponOutcome
    {
        // No plan context: the grant brings its own. validate() refuses a Mollie
        // subscription, another plan and an addon-only grant without a free
        // subscription — each with its own exception, turned into a sentence above.
        $this->coupons->validate((string) $coupon->code, $billable, [
            'allowed_types' => [CouponType::AccessGrant],
        ]);

        $this->coupons->redeem($coupon, $billable, []);

        if ($billable instanceof Model) {
            $billable->refresh();
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();
        $planCode = (string) ($coupon->grant_plan_code ?? '');

        if ($planCode === '' || $endsAt === null) {
            return PortalCouponOutcome::redeemed(
                __('billing::portal.coupon_redeem_success', ['code' => $coupon->code]),
                $coupon,
            );
        }

        return PortalCouponOutcome::redeemed(
            __('billing::portal.coupon_grant_activated', [
                'code' => $coupon->code,
                'plan' => $this->catalog->planName($planCode) ?: $planCode,
                'until' => (string) BillingTime::display($endsAt, $billable)?->translatedFormat('d. M Y'),
            ]),
            $coupon,
        );
    }
}

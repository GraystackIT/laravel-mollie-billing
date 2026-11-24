<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\CouponRedeemed;
use GraystackIT\MollieBilling\Events\GrantRevoked;
use GraystackIT\MollieBilling\Events\SubscriptionExtended;
use GraystackIT\MollieBilling\Exceptions\AccessGrantConflictsWithMollieSubscriptionException;
use GraystackIT\MollieBilling\Exceptions\AccessGrantRequiresActiveSubscriptionException;
use GraystackIT\MollieBilling\Exceptions\CouponInUseException;
use GraystackIT\MollieBilling\Exceptions\CouponNotStackableException;
use GraystackIT\MollieBilling\Exceptions\CouponRequiresTrialException;
use GraystackIT\MollieBilling\Exceptions\GrantMismatchException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingAnchor;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CouponService
{
    public function __construct(
        private readonly \GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface $catalog,
        private readonly WalletUsageService $walletService,
    ) {
    }

    public function create(array $attributes): Coupon
    {
        if (! isset($attributes['code']) || trim((string) $attributes['code']) === '') {
            throw new \InvalidArgumentException('Please enter a coupon code.');
        }

        $attributes['code'] = strtoupper(trim((string) $attributes['code']));

        // Said in a sentence. The unique index refuses a second coupon under the same
        // code either way, but as a QueryException — which the admin form could only
        // show as "something went wrong (23000)".
        if (Coupon::query()->whereRaw('UPPER(code) = ?', [$attributes['code']])->exists()) {
            throw new \InvalidArgumentException("A coupon with the code {$attributes['code']} already exists.");
        }

        if (! isset($attributes['name']) || $attributes['name'] === null || $attributes['name'] === '') {
            $attributes['name'] = $attributes['code'];
        }

        $generateToken = (bool) ($attributes['generate_token'] ?? false);
        unset($attributes['generate_token']);

        if ($generateToken && empty($attributes['auto_apply_token'])) {
            $attributes['auto_apply_token'] = Str::lower(Str::random(12));
        }

        $type = $attributes['type'] instanceof CouponType
            ? $attributes['type']
            : CouponType::from((string) $attributes['type']);

        $attributes = $this->withLegacyDiscountPeriods($type, $attributes);

        $this->validateRequiredFieldsForType($type, $attributes);

        return Coupon::create($attributes);
    }

    /**
     * A recurring coupon created the way it used to be.
     *
     * `max_redemptions_per_billable` was the number of discounted charges for this type
     * until `discount_periods` took that meaning over. Every app that creates coupons in
     * code still passes it that way, so a value given WITHOUT `discount_periods` is read
     * as the period count it was written as and moved over; the per-customer limit
     * becomes 1, which is what it effectively was. As soon as `discount_periods` is
     * present — even as null — both fields mean what they say.
     *
     * One case is not moved: a limit of 1 next to a `valid_until`. 1 is the column's
     * default and carries no intent, and the date is a complete lifetime on its own.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withLegacyDiscountPeriods(CouponType $type, array $attributes): array
    {
        if ($type !== CouponType::Recurring || array_key_exists('discount_periods', $attributes)) {
            return $attributes;
        }

        $perBillable = (int) ($attributes['max_redemptions_per_billable'] ?? 0);
        $hasValidUntil = ($attributes['valid_until'] ?? null) !== null && ($attributes['valid_until'] ?? '') !== '';

        if ($perBillable > 1 || ($perBillable === 1 && ! $hasValidUntil)) {
            $attributes['discount_periods'] = $perBillable;
            $attributes['max_redemptions_per_billable'] = 1;
        }

        return $attributes;
    }

    public function update(Coupon $coupon, array $attributes): Coupon
    {
        // Rows, not `redemptions_count`: the counter is decremented on revoke, so a
        // fully revoked coupon would read as never redeemed — while the invoices and
        // PDFs of those redemptions still carry the old code.
        if (
            isset($attributes['code'])
            && strtoupper(trim((string) $attributes['code'])) !== $coupon->code
            && $coupon->redemptions()->exists()
        ) {
            throw new \InvalidArgumentException(
                'Cannot change the code of a coupon that has already been redeemed.'
            );
        }

        if (isset($attributes['code'])) {
            $attributes['code'] = strtoupper(trim((string) $attributes['code']));
        }

        // Same shape rules as create(). Without this an admin could raise a Percentage
        // coupon above 100% after creation, and for a Recurring coupon that value flows
        // uncapped into the `active_recurring_coupon` marker — computeMarkerDiscount()'s
        // percentage branch has no cap at the net amount, so the renewal invoice would
        // carry a discount line larger than the plan line it discounts.
        //
        // Validation runs against the *post-update* shape: the coupon's current values for
        // every field the type rules read, with the patch merged on top.
        $type = array_key_exists('type', $attributes) && $attributes['type'] !== null
            ? ($attributes['type'] instanceof CouponType ? $attributes['type'] : CouponType::from((string) $attributes['type']))
            : $coupon->type;

        // A redeemed coupon keeps its type. A running recurring marker re-applies "its
        // coupon" through redeem() at every renewal; with the type changed underneath
        // it, that call ran the NEW type's effect — a recurring coupon turned into a
        // credits coupon topped up the wallet of every customer holding it, at every
        // renewal — and a revoke looked for a snapshot the new type never writes.
        if ($type !== $coupon->type && $coupon->redemptions()->exists()) {
            throw new \InvalidArgumentException(
                'Cannot change the type of a coupon that has already been redeemed.'
            );
        }

        $this->validateRequiredFieldsForType($type, array_merge($this->typeRuleFields($coupon), $attributes));

        $coupon->update($attributes);

        return $coupon->refresh();
    }

    /**
     * The coupon's current values for exactly the fields validateRequiredFieldsForType()
     * inspects, read through the casts so JSON columns arrive as arrays.
     *
     * Deliberately narrow rather than `attributesToArray()`: that would round-trip every
     * attribute through its cast, and the UtcDatetime cast cannot re-read its own output.
     *
     * @return array<string, mixed>
     */
    private function typeRuleFields(Coupon $coupon): array
    {
        return [
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'discount_periods' => $coupon->discount_periods,
            'max_redemptions_per_billable' => $coupon->max_redemptions_per_billable,
            'valid_until' => $coupon->valid_until,
            'credits_payload' => $coupon->credits_payload,
            'trial_extension_days' => $coupon->trial_extension_days,
            'grant_plan_code' => $coupon->grant_plan_code,
            'grant_interval' => $coupon->grant_interval,
            'grant_duration_days' => $coupon->grant_duration_days,
            'grant_addon_codes' => $coupon->grant_addon_codes,
            'waiver_periods' => $coupon->waiver_periods,
        ];
    }

    public function deactivate(Coupon $coupon): void
    {
        $coupon->update(['active' => false]);
    }

    public function delete(Coupon $coupon): void
    {
        // Rows, not the counter: revoked redemptions keep their row for audit and
        // would be orphaned by a delete the decremented counter waved through.
        if ($coupon->redemptions()->exists()) {
            throw new CouponInUseException(
                'Cannot delete a coupon that has been redeemed; deactivate it instead.'
            );
        }

        $coupon->delete();
    }

    public function validate(string $code, Billable $billable, array $context): Coupon
    {
        $coupon = $this->resolveAndValidateShared($code, $billable, $context);

        // Per-billable count. Same rule as assertPerBillableLimitLeft(), which
        // re-checks it under the coupon lock — the two must agree or the UI and
        // the write path disagree about whether a slot is free.
        $this->assertPerBillableLimitLeft($coupon, $billable);

        if ($coupon->type === CouponType::Recurring) {
            $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

            // A discount that has run out does not hold the slot. Its marker is only
            // cleared by the renewal after its end, and that renewal can be weeks away
            // (extension days keeping the charge deferred) — or, for a deactivated
            // coupon, a period away. Until then the customer was told "another
            // recurring coupon is already active" about one that discounts nothing.
            // The new redemption overwrites it.
            if (is_array($marker) && ! $this->isMarkerStillRedeemable($marker, $billable)) {
                $marker = null;
            }

            if (is_array($marker)) {
                $markerCouponId = (int) ($marker['coupon_id'] ?? 0);
                if ($markerCouponId !== $coupon->id) {
                    // Different recurring coupon already active — only one at a time.
                    throw new InvalidCouponException($billable, (string) $coupon->code, 'recurring_conflict');
                }

                // Same coupon is already active on this subscription — re-applying
                // it via UI would create a duplicate redemption record. Renewals go
                // through redeemRecurringCouponForRenewal() which doesn't pass through
                // validate().
                throw new InvalidCouponException($billable, (string) $coupon->code, 'recurring_already_active');
            }

            // Note: a 100% recurring discount (or fixed >= orderAmount) is intentionally
            // allowed here. The Mollie-Subscription is created/patched with the full price
            // and a deferred startDate covering the discount lifetime — see
            // CreateSubscription / MollieSubscriptionPatcher / ResubscribeSubscription.
        }

        if ($coupon->type === CouponType::SinglePayment) {
            // 100%-coverage on SinglePayment is supported on two paths:
            //   - Subscription Checkout: StartSubscriptionCheckout routes amount_gross=0
            //     to the Mandate-Only flow; the webhook activates the subscription with
            //     startDate=now+1 interval so Mollie charges full price from period 2.
            //   - One-Time-Order: StartOneTimeOrderCheckout writes a local 0-EUR invoice
            //     inline, with no Mollie roundtrip.
            // A Fixed-amount discount that EXCEEDS the order is still nonsensical — reject
            // strictly greater. Equal (= 100%) is allowed and handled by the paths above.
            $orderAmount = (int) ($context['orderAmountNet'] ?? 0);
            if ($orderAmount > 0) {
                $discount = $this->computeRecurringDiscount($coupon, $orderAmount);
                if ($discount > $orderAmount) {
                    throw new InvalidCouponException($billable, (string) $coupon->code, 'full_coverage_use_access_grant');
                }
            }
        }

        if ($coupon->type === CouponType::TrialExtension) {
            // An active trial always qualifies. A *planned* one only counts where a
            // trial is genuinely about to start — the caller says so with
            // `trial_starting`, and only the signup path does. Deciding it from the
            // plan alone would let an active, paying subscriber on a trial-offering
            // plan redeem an extension from the dashboard: extendBillingTrialUntil()
            // would flip them back to Trial and PATCH Mollie's next charge to the new
            // trial end, i.e. charge them again within days.
            //
            // When it does count, the length comes from the catalog keyed by
            // (plan, interval). Trials are configured per interval, and plan-level
            // `trial_days` is not supported at all (check-config rejects it) — reading
            // the plan root meant the planned-trial branch could never be true, so a
            // fresh checkout could never accept one of these codes.
            $planCode = (string) ($context['planCode'] ?? '');
            $interval = (string) ($context['interval'] ?? 'monthly');
            $trialStarting = (bool) ($context['trial_starting'] ?? false);

            $plannedTrialDays = ($trialStarting && $planCode !== '')
                ? $this->catalog->trialDays($planCode, $interval)
                : 0;

            if (! $billable->isOnBillingTrial() && $plannedTrialDays <= 0) {
                throw new CouponRequiresTrialException(
                    "Coupon {$coupon->code} requires an active or planned trial."
                );
            }
        }

        if ($coupon->type === CouponType::PeriodExtension) {
            $source = $billable->getBillingSubscriptionSource();
            if (
                $source !== SubscriptionSource::Local->value
                && $source !== SubscriptionSource::Mollie->value
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'requires_active_subscription');
            }

            $extendsTrial = $this->periodExtensionExtendsTrial($billable);

            // A Local subscription without an end date has no date to move. Writing
            // `now + N days` into it — which is what the apply step did — turned a
            // perpetual free plan into one that expires in N days.
            if (
                $source === SubscriptionSource::Local->value
                && ! $extendsTrial
                && $billable->getBillingSubscriptionEndsAt() === null
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'requires_active_subscription');
            }

            // A cancelled Mollie subscription has no charge left to push: the PATCH is
            // sent to a subscription Mollie has cancelled, the grace end does not move,
            // and the redemption says the customer was given days they never got.
            if (
                $source === SubscriptionSource::Mollie->value
                && $billable->getBillingSubscriptionStatus() === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'requires_active_subscription');
            }

            // The date money is actually collected, not the period end: under a fully
            // covering coupon the period turns over every interval while the charge sits
            // months away, and judging by the period end refused the extension on every
            // last day of a period for a charge that was not coming. A trial's first
            // charge is its end, whatever the period anchor says.
            $nextBilling = $extendsTrial
                ? $billable->getBillingTrialEndsAt()
                : ChargeDeferral::nextChargeDate($billable);
            if ($source === SubscriptionSource::Mollie->value
                && $nextBilling !== null
                && $nextBilling->lessThanOrEqualTo(BillingTime::nowUtc()->addDay())
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'too_close_to_charge');
            }
        }

        if ($coupon->type === CouponType::PlanWaiver) {
            $source = $billable->getBillingSubscriptionSource();

            if (
                ! $billable->hasAccessibleBillingSubscription()
                || ! in_array($source, [SubscriptionSource::Local->value, SubscriptionSource::Mollie->value], true)
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'requires_active_subscription');
            }

            // Free periods on a Local subscription are free time, and only a Local
            // subscription that ends has any to extend.
            if ($source === SubscriptionSource::Local->value && $billable->getBillingSubscriptionEndsAt() === null) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'requires_active_subscription');
            }

            // One recurring-discount slot. Free periods are appended to a waiver that is
            // already running; next to an ordinary recurring coupon they are refused,
            // because two discounts repricing the same charge with separate lifetimes is
            // not something the renewal can book unambiguously.
            $marker = $this->getActiveRecurringCouponMarker($billable);

            if (
                $source === SubscriptionSource::Mollie->value
                && $marker !== null
                && ! PlanWaiver::isWaiver($marker)
                && $this->isMarkerStillRedeemable($marker, $billable)
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'recurring_conflict');
            }
        }

        if ($coupon->type === CouponType::AccessGrant) {
            $hasFullPlan = ! empty($coupon->grant_plan_code);
            $addonOnly = ! $hasFullPlan && ! empty($coupon->grant_addon_codes);
            $source = $billable->getBillingSubscriptionSource();

            if ($addonOnly) {
                if ($source !== SubscriptionSource::Local->value) {
                    if ($source === SubscriptionSource::Mollie->value) {
                        throw new AccessGrantConflictsWithMollieSubscriptionException(
                            "Coupon {$coupon->code} cannot be applied to a Mollie subscription."
                        );
                    }
                    throw new AccessGrantRequiresActiveSubscriptionException(
                        "Coupon {$coupon->code} requires an active local subscription."
                    );
                }
            }

            if ($hasFullPlan && $source === SubscriptionSource::Local->value) {
                $currentPlan = $billable->getBillingSubscriptionPlanCode();
                $currentInterval = $billable->getBillingSubscriptionInterval();
                if (
                    $currentPlan !== null
                    && $currentPlan !== $coupon->grant_plan_code
                ) {
                    throw new GrantMismatchException(
                        "Coupon grant plan {$coupon->grant_plan_code} does not match active plan {$currentPlan}."
                    );
                }
                if (
                    $currentInterval !== null
                    && $coupon->grant_interval !== null
                    && $currentInterval !== $coupon->grant_interval
                ) {
                    throw new GrantMismatchException(
                        "Coupon grant interval {$coupon->grant_interval} does not match active interval {$currentInterval}."
                    );
                }
            }

            if ($hasFullPlan && $source === SubscriptionSource::Mollie->value) {
                throw new AccessGrantConflictsWithMollieSubscriptionException(
                    "Coupon {$coupon->code} cannot be applied to a Mollie subscription."
                );
            }
        }

        return $coupon;
    }

    /**
     * Validate a coupon code without a Billable (e.g. during checkout for a
     * not-yet-created account). Applies all billable-independent checks.
     * AccessGrant skips source-based checks since no subscription exists yet.
     */
    public function validateWithoutBillable(string $code, array $context): Coupon
    {
        $coupon = $this->resolveAndValidateShared($code, null, $context);

        if ($coupon->type === CouponType::TrialExtension) {
            // The billable-dependent half of the trial check (is this account on a
            // trial right now?) cannot be answered here, but the other half can:
            // the chosen (plan, interval) either offers a trial or it does not, and
            // that is the half that matters for a fresh signup. Rejecting the type
            // outright meant a checkout could never accept one even though it lists
            // TrialExtension among its allowed types — a promotion of the "14 + 7
            // days free" kind was unusable at the moment it is meant to be used.
            //
            // A context without a plan cannot be judged at all, so it still refuses.
            $planCode = (string) ($context['planCode'] ?? '');
            $interval = (string) ($context['interval'] ?? 'monthly');

            if ($planCode === '') {
                throw new InvalidCouponException(null, (string) $coupon->code, 'requires_billable');
            }

            if ($this->catalog->trialDays($planCode, $interval) <= 0) {
                throw new InvalidCouponException(null, (string) $coupon->code, 'requires_trial');
            }
        }

        return $coupon;
    }

    private function resolveAndValidateShared(string $code, ?Billable $billable, array $context): Coupon
    {
        $code = strtoupper(trim($code));
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->first();

        if ($coupon === null) {
            throw new InvalidCouponException($billable, $code, 'not_found');
        }

        if (! $coupon->active) {
            throw new InvalidCouponException($billable, $code, 'inactive');
        }

        // Context-restricted types: each entry point passes an `allowed_types` list
        // of CouponType cases (or their string values) — the coupon must be one of
        // them. This is independent of the per-coupon `applicable_*` filters and
        // exists to prevent semantically-wrong types being redeemed at the wrong
        // place (e.g. a `credits` coupon on a plan-change action, or an
        // `access_grant` on a one-time-order purchase).
        $allowedTypes = (array) ($context['allowed_types'] ?? []);
        if ($allowedTypes !== []) {
            $allowedValues = array_map(
                fn ($t) => $t instanceof CouponType ? $t->value : (string) $t,
                $allowedTypes,
            );
            if (! in_array($coupon->type->value, $allowedValues, true)) {
                throw new InvalidCouponException($billable, $code, 'type_not_allowed_in_context');
            }
        }

        $now = BillingTime::nowUtc();

        if ($coupon->valid_from !== null && $coupon->valid_from->isAfter($now)) {
            throw new InvalidCouponException($billable, $code, 'not_yet_valid');
        }

        if ($coupon->valid_until !== null && $coupon->valid_until->isBefore($now)) {
            throw new InvalidCouponException($billable, $code, 'expired');
        }

        if ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions) {
            throw new InvalidCouponException($billable, $code, 'globally_exhausted');
        }

        $planCode = $context['planCode'] ?? null;
        $interval = $context['interval'] ?? null;
        $addonCodes = (array) ($context['addonCodes'] ?? []);
        $orderAmount = (int) ($context['orderAmountNet'] ?? 0);
        $existingCouponIds = (array) ($context['existingCouponIds'] ?? []);

        $productCodes = (array) ($context['productCodes'] ?? []);

        // A set filter is a positive requirement, not a filter that only applies when the
        // caller happens to describe the thing it restricts.
        //
        // These all used to be skipped when the corresponding context key was absent, and
        // the entry points do not overlap: subscription checkout passes no `productCodes`
        // and a product purchase passes no `planCode`. So a coupon restricted to
        // `token-pack` halved a €500/yr signup, and a coupon restricted to plan `pro`
        // took 50 % off any product — both from customer-facing coupon fields.
        //
        // The message names the missing dimension, so an operator reading a rejected
        // coupon can tell "wrong plan" from "not usable here at all".
        if (! empty($coupon->applicable_plans)) {
            if ($planCode === null || ! in_array($planCode, (array) $coupon->applicable_plans, true)) {
                throw new InvalidCouponException($billable, $code, 'plan_not_applicable');
            }
        }

        if (! empty($coupon->applicable_intervals)) {
            if ($interval === null || ! in_array($interval, (array) $coupon->applicable_intervals, true)) {
                throw new InvalidCouponException($billable, $code, 'interval_not_applicable');
            }
        }

        if (! empty($coupon->applicable_addons)) {
            // Addons are the one dimension where "none selected" is a legitimate order
            // rather than a missing context key — a plan can be bought without addons —
            // but a coupon restricted to addons still has nothing to apply to then.
            if ($addonCodes === []) {
                throw new InvalidCouponException($billable, $code, 'addon_not_applicable');
            }

            $allowed = (array) $coupon->applicable_addons;
            foreach ($addonCodes as $addon) {
                if (! in_array($addon, $allowed, true)) {
                    throw new InvalidCouponException($billable, $code, 'addon_not_applicable');
                }
            }
        }

        if (! empty($coupon->applicable_products)) {
            if ($productCodes === []) {
                throw new InvalidCouponException($billable, $code, 'product_not_applicable');
            }

            $allowedProducts = (array) $coupon->applicable_products;
            foreach ($productCodes as $product) {
                if (! in_array($product, $allowedProducts, true)) {
                    throw new InvalidCouponException($billable, $code, 'product_not_applicable');
                }
            }
        }

        // The one dimension no path can satisfy — and therefore the one that has to say so.
        // `applicable_usages` restricts a coupon to specific metered usage types, but no
        // charge in this package carries a usage dimension a coupon could be scoped to:
        // overage is charged by `ChargeUsageOverageDirectly`, which builds its line items
        // from wallet balances and never consults a coupon. Skipping the check meant the
        // field did the exact opposite of what an operator setting it intends — "tokens
        // only" made the coupon apply to *everything*, at full discount value. Rejecting is
        // conservative and visible: the coupon is unusable until a path passes `usageTypes`.
        if (! empty($coupon->applicable_usages)) {
            $usageTypes = (array) ($context['usageTypes'] ?? []);

            if ($usageTypes === []) {
                throw new InvalidCouponException($billable, $code, 'usage_not_applicable');
            }

            $allowedUsages = (array) $coupon->applicable_usages;
            foreach ($usageTypes as $usageType) {
                if (! in_array($usageType, $allowedUsages, true)) {
                    throw new InvalidCouponException($billable, $code, 'usage_not_applicable');
                }
            }
        }

        if (
            $coupon->minimum_order_amount_net !== null
            && $orderAmount < (int) $coupon->minimum_order_amount_net
        ) {
            throw new InvalidCouponException($billable, $code, 'min_order_not_met');
        }

        // AccessGrant strict-match: the grant defines exactly what the local
        // subscription will be activated with — anything the user picked beyond
        // that would be silently free. Reject upfront so the checkout UI can
        // surface the mismatch before submit. Only applies when the consumer
        // passes the relevant context keys (planCode/interval/addonCodes/extraSeats).
        if ($coupon->type === CouponType::AccessGrant) {
            $hasFullPlan = ! empty($coupon->grant_plan_code);

            if ($hasFullPlan && $planCode !== null && $coupon->grant_plan_code !== $planCode) {
                throw new InvalidCouponException($billable, $code, 'grant_plan_mismatch');
            }

            if (
                $hasFullPlan
                && $interval !== null
                && $coupon->grant_interval !== null
                && $coupon->grant_interval !== $interval
            ) {
                throw new InvalidCouponException($billable, $code, 'grant_interval_mismatch');
            }

            $grantedAddons = (array) ($coupon->grant_addon_codes ?? []);
            foreach ($addonCodes as $addon) {
                if (! in_array($addon, $grantedAddons, true)) {
                    throw new InvalidCouponException($billable, $code, 'grant_addons_exceeded');
                }
            }

            if ((int) ($context['extraSeats'] ?? 0) > 0) {
                throw new InvalidCouponException($billable, $code, 'grant_seats_not_supported');
            }
        }

        if ($coupon->type !== CouponType::AccessGrant && $existingCouponIds !== []) {
            // The new coupon itself is not stackable → it can't be combined with anything else.
            if (! $coupon->stackable) {
                $other = Coupon::query()->whereIn('id', $existingCouponIds)->first();
                if ($other !== null) {
                    throw new CouponNotStackableException(
                        "Coupon {$coupon->code} is not stackable with {$other->code}."
                    );
                }
            }

            // An already-applied coupon is not stackable → no further coupons may follow.
            $nonStackableExisting = Coupon::query()
                ->whereIn('id', $existingCouponIds)
                ->where('stackable', false)
                ->first();
            if ($nonStackableExisting !== null) {
                throw new CouponNotStackableException(
                    "Coupon {$nonStackableExisting->code} is not stackable with {$coupon->code}."
                );
            }

            // Only ONE recurring coupon per subscription, even within a single stacked
            // apply-set. `active_recurring_coupon` is a single slot, so a second
            // Recurring code simply overwrote the first: the preview and the prorata
            // charge honoured both discounts, then every renewal honoured only the last
            // one while both per-billable slots had been consumed. The stacking rules
            // above cannot catch it — they run before any redeem, so the marker the
            // `recurring_conflict` check reads does not exist yet.
            if ($coupon->type === CouponType::Recurring) {
                $otherRecurring = Coupon::query()
                    ->whereIn('id', $existingCouponIds)
                    ->where('type', CouponType::Recurring->value)
                    ->first();

                if ($otherRecurring !== null) {
                    throw new InvalidCouponException(
                        $billable,
                        (string) $coupon->code,
                        'recurring_conflict',
                    );
                }
            }
        }

        return $coupon;
    }

    public function resolveByAutoApplyToken(string $token): ?Coupon
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->whereNotNull('auto_apply_token')
            ->whereRaw('LOWER(auto_apply_token) = ?', [strtolower($token)])
            ->first();

        if ($coupon === null) {
            $coupon = Coupon::query()
                ->whereRaw('UPPER(code) = ?', [strtoupper($token)])
                ->first();
        }

        if ($coupon === null) {
            return null;
        }

        if (! $coupon->isWithinValidity(BillingTime::nowUtc()) || ! $coupon->hasGlobalRedemptionsLeft()) {
            return null;
        }

        return $coupon;
    }

    /**
     * Write the redemption row and apply the coupon's effect.
     *
     * Both redemption limits are (re-)checked here, under `Coupon::lockForUpdate()`:
     * `validate()` checks them too, but outside any transaction, so a double-clicked
     * Credits coupon would credit the wallet twice and write two redemption rows before
     * either request saw the other.
     *
     * `$context['renewal'] = true` marks a periodic re-application of an
     * already-granted Recurring coupon (see redeemRecurringCouponForRenewal). Those are
     * exempt from *both* global rules:
     *
     *  - the per-billable cap, because `max_redemptions_per_billable` says how often a
     *    customer may *redeem* the code, and a renewal is not a redemption — enforcing
     *    it here would abort the renewals `discount_periods` authorises;
     *  - `max_redemptions` and its counter, because that cap sizes the campaign in
     *    *customers*. Charging renewals against it exhausted a 50-redemption coupon with
     *    20 monthly subscribers within months, after which new customers were rejected
     *    while the existing ones kept their discount anyway — `computeMarkerDiscount()`
     *    reads only `active` + `valid_until`, so the invoice still carried the discount
     *    line, `redeem()` threw `globally_exhausted`, the caller logged it, and the
     *    redemption rows silently stopped being written.
     *
     * So `redemptions_count` counts grants, and the redemption rows count applications;
     * a recurring coupon has more of the latter by design.
     */
    public function redeem(Coupon $coupon, Billable $billable, array $context): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $billable, $context): CouponRedemption {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

            // Only a Recurring coupon can be renewed. The exemption below is justified by
            // what a renewal is for that type (a further charge of a grant that already
            // exists) and nothing else, and `redeem()` is public API taking a
            // caller-supplied array — so a `renewal` flag on a SinglePayment or Credits
            // coupon bought a second redemption past an exhausted campaign. The internal
            // caller resolves its coupon from `active_recurring_coupon`, so this is a
            // no-op for it.
            //
            // And only a grant that exists can be re-applied: the billable must already hold
            // this coupon's marker. Without that, the same flag let a billable that never
            // had a grant skip both caps and — with `skip_marker` omitted — write itself a
            // fresh one past an exhausted campaign.
            //
            // A first-payment coupon carried over a trial is the one other grant a renewal
            // applies: its marker was written by the trial signup, which also counted the
            // grant, and the charge at the trial's end is the one application it was given.
            $liveMarker = $this->getActiveRecurringCouponMarker($billable);
            $isRenewal = ($context['renewal'] ?? false) === true
                && $liveMarker !== null
                && (int) ($liveMarker['coupon_id'] ?? 0) === (int) $coupon->id
                && (
                    $coupon->type === CouponType::Recurring
                    || ($coupon->type === CouponType::SinglePayment && self::isFirstChargeOnly($liveMarker))
                );

            if (! $isRenewal) {
                if (
                    $coupon->max_redemptions !== null
                    && $coupon->redemptions_count >= $coupon->max_redemptions
                ) {
                    throw new InvalidCouponException(
                        $billable,
                        (string) $coupon->code,
                        'globally_exhausted',
                    );
                }

                $this->assertPerBillableLimitLeft($coupon, $billable);

                $coupon->increment('redemptions_count');
                $coupon->refresh();
            }

            /** @var Model $billableModel */
            $billableModel = $billable;

            $redemption = new CouponRedemption();
            $redemption->coupon_id = $coupon->id;
            $redemption->billable_type = $billableModel->getMorphClass();
            $redemption->billable_id = $billableModel->getKey();
            $redemption->applied_at = BillingTime::nowUtc();
            $redemption->discount_amount_net = 0;

            switch ($coupon->type) {
                case CouponType::SinglePayment:
                    // Caller-set `discount_amount_net` (even 0) is taken verbatim — at
                    // path-orchestration sites (UpdateSubscription, OneTimeOrderWebhook)
                    // we always pass the *actually billed* discount and 0 means "no charge
                    // attached, redemption only sets the audit marker". Only when the key
                    // is fully missing we fall back to computeRecurringDiscount(orderAmount)
                    // for backward compatibility with simpler call sites.
                    if (array_key_exists('discount_amount_net', $context)) {
                        $redemption->discount_amount_net = (int) $context['discount_amount_net'];
                    } elseif (isset($context['orderAmountNet'])) {
                        $redemption->discount_amount_net = $this->computeRecurringDiscount(
                            $coupon,
                            (int) $context['orderAmountNet'],
                        );
                    }
                    if (isset($context['invoice_id'])) {
                        $redemption->invoice_id = (int) $context['invoice_id'];
                    }
                    // The trial-end charge of a code carried over a trial: the grant row
                    // the signup wrote is the redemption, this one its application.
                    if ($isRenewal) {
                        $redemption->grant_applied_snapshot = ['mode' => 'recurring_renewal'];
                    }
                    break;

                case CouponType::Recurring:
                    if (array_key_exists('discount_amount_net', $context)) {
                        $redemption->discount_amount_net = (int) $context['discount_amount_net'];
                    } elseif (isset($context['orderAmountNet'])) {
                        $redemption->discount_amount_net = $this->computeRecurringDiscount(
                            $coupon,
                            (int) $context['orderAmountNet'],
                        );
                    }
                    if (isset($context['invoice_id'])) {
                        $redemption->invoice_id = (int) $context['invoice_id'];
                    }
                    // Marked, so the per-customer limit can tell a further charge of a
                    // grant from a second redemption of the code.
                    if ($isRenewal) {
                        $redemption->grant_applied_snapshot = ['mode' => 'recurring_renewal'];
                    }
                    // A renewal re-applies the grant the marker describes; rewriting it
                    // would reset the base amount and lifetime the grant was locked to.
                    if (($context['skip_marker'] ?? false) !== true && ! $isRenewal) {
                        // The marker locks the discount to the recurring net the
                        // coupon was applied against — seats/addons added later
                        // are billed at full price.
                        $baseAmount = (int) ($context['orderAmountNet'] ?? 0);
                        // The context's interval, not the billable's: `update()` redeems
                        // before it persists a changed interval, so reading it off the
                        // model computed the discount's lifetime in the wrong unit.
                        $markerInterval = isset($context['interval']) ? (string) $context['interval'] : null;
                        $this->setActiveRecurringCouponMarker(
                            $coupon,
                            $billable,
                            $baseAmount,
                            $markerInterval,
                            // Where the discounted periods start: the period this very
                            // charge pays for when it carries the discount, otherwise
                            // the next charge (a code applied to a running subscription
                            // without a charge of its own) — see recurringLifetimeFrom().
                            ($context['lifetime_from'] ?? null) instanceof CarbonInterface
                                ? $context['lifetime_from']
                                : $this->recurringLifetimeFrom($billable, (int) $redemption->discount_amount_net > 0, $markerInterval),
                        );
                    }
                    break;

                case CouponType::PeriodExtension:
                    $this->applyPeriodExtension($coupon, $billable, $redemption);
                    break;

                case CouponType::Credits:
                    $payload = (array) ($coupon->credits_payload ?? []);
                    $applied = [];
                    foreach ($payload as $type => $quantity) {
                        $qty = (int) $quantity;
                        if ($qty <= 0) {
                            continue;
                        }
                        $this->walletService->credit($billable, (string) $type, $qty, 'coupon_credit');
                        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
                            $wallet = $billable->getWallet((string) $type);
                            if ($wallet !== null) {
                                WalletUsageService::addPurchasedBalance($wallet, $qty);
                            }
                        }
                        $applied[$type] = $qty;
                    }
                    $redemption->credits_applied = $applied;
                    break;

                case CouponType::TrialExtension:
                    $days = (int) ($coupon->trial_extension_days ?? 0);
                    $current = $billable->getBillingTrialEndsAt();
                    $newEnd = ($current && $current->isFuture() ? $current->copy() : BillingTime::nowUtc())->addDays($days);
                    // extendBillingTrialUntil() also patches Mollie's startDate
                    // for Mollie-source subscriptions — no extra sync here.
                    $billable->extendBillingTrialUntil($newEnd);
                    $redemption->trial_days_added = $days;
                    break;

                case CouponType::AccessGrant:
                    $this->applyAccessGrant($coupon, $billable, $redemption);
                    break;

                case CouponType::PlanWaiver:
                    $this->applyPlanWaiver($coupon, $billable, $redemption);
                    break;
            }

            $redemption->save();

            event(new CouponRedeemed($billable, $coupon, $redemption));

            return $redemption;
        });
    }

    /**
     * The per-billable cap, used both by validate() and inside redeem()'s transaction
     * against the locked coupon row.
     *
     * Source of truth is the redemption rows, not `redemptions_count`: that counter
     * sizes the *campaign* in customers (see redeem()) and is shared by every
     * billable. Only live rows count — a revoked grant frees its slot, which is what
     * revokeGrant()'s counter decrement already implies. Both readings must apply the
     * same filter, or a revoked grant leaves the coupon permanently unusable for that
     * billable in the UI while redeem() would happily take it.
     *
     * @throws InvalidCouponException
     */
    /**
     * Whether this billable may still redeem the coupon — the per-billable cap, as a
     * question. For callers that explain a code instead of validating an order with it.
     */
    public function hasPerBillableRedemptionLeft(Coupon $coupon, Billable $billable): bool
    {
        try {
            $this->assertPerBillableLimitLeft($coupon, $billable);

            return true;
        } catch (InvalidCouponException) {
            return false;
        }
    }

    /**
     * Whether `redeem()` would take this coupon for this billable right now: the campaign
     * cap and the per-billable cap, the two limits it enforces itself. A question asked
     * without a lock — `redeem()` asks it again under `lockForUpdate`, and a race between
     * the two is still possible.
     */
    public function hasRedemptionLeft(Coupon $coupon, Billable $billable): bool
    {
        $coupon = $coupon->fresh() ?? $coupon;

        if ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions) {
            return false;
        }

        return $this->hasPerBillableRedemptionLeft($coupon, $billable);
    }

    private function assertPerBillableLimitLeft(Coupon $coupon, Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $perBillable = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            // A plan waiver and a recurring coupon write a row for every renewal they
            // discount. Those are the grant's history, not further redemptions of the
            // code: counted here, the first discounted renewal used up the second slot
            // of a code that may be redeemed twice. (Renewal rows of a recurring coupon
            // written before they were marked still count — nothing tells them from a
            // grant — which errs on the side of refusing a second redemption.)
            ->where(function ($q): void {
                $q->whereNull('grant_applied_snapshot')
                    ->orWhereNull('grant_applied_snapshot->mode')
                    ->orWhereNotIn('grant_applied_snapshot->mode', ['plan_waiver_renewal', 'recurring_renewal']);
            })
            ->count();

        if ($perBillable >= (int) ($coupon->max_redemptions_per_billable ?? 1)) {
            throw new InvalidCouponException($billable, (string) $coupon->code, 'per_billable_limit_reached');
        }
    }

    public function computeRecurringDiscount(Coupon $coupon, int $netAmount): int
    {
        if ($netAmount <= 0) {
            return 0;
        }

        $type = $coupon->discount_type;
        $value = (int) ($coupon->discount_value ?? 0);

        if ($type === DiscountType::Percentage) {
            return (int) round($netAmount * $value / 100);
        }

        if ($type === DiscountType::Fixed) {
            return min($value, $netAmount);
        }

        return 0;
    }

    public function applyCreditsToWallets(Billable $billable, CouponRedemption $redemption): void
    {
        $payload = (array) ($redemption->credits_applied ?? $redemption->coupon?->credits_payload ?? []);

        foreach ($payload as $type => $quantity) {
            $qty = (int) $quantity;
            if ($qty > 0) {
                $this->walletService->credit($billable, (string) $type, $qty, 'coupon_credit');
                if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
                    $wallet = $billable->getWallet((string) $type);
                    if ($wallet !== null) {
                        WalletUsageService::addPurchasedBalance($wallet, $qty);
                    }
                }
            }
        }
    }

    public function creditsCoupon(string $code, array $payload): Coupon
    {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::Credits,
            'credits_payload' => $payload,
            'active' => true,
        ]);
    }

    public function trialExtensionCoupon(string $code, int $days): Coupon
    {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::TrialExtension,
            'trial_extension_days' => $days,
            'active' => true,
        ]);
    }

    public function accessGrantCoupon(
        string $code,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $durationDays,
        ?string $onExpiry = null,
        ?int $maxRedemptions = null,
    ): Coupon {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::AccessGrant,
            // Null leaves the code open to everybody who knows it (a campaign). A grant
            // made for one tenant passes 1.
            'max_redemptions' => $maxRedemptions,
            'grant_plan_code' => $planCode,
            'grant_interval' => $interval,
            'grant_addon_codes' => $addonCodes,
            'grant_duration_days' => $durationDays,
            // What happens once the free time is over and the tenant has bought paid
            // extras in the meantime — see PlanWaiver. Default: the subscription ends.
            'grant_on_expiry' => PlanWaiver::normalizeOnExpiry($onExpiry),
            'active' => true,
        ]);
    }

    public function addonGrantCoupon(string $code, array $addonCodes, ?int $maxRedemptions = null): Coupon
    {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::AccessGrant,
            'max_redemptions' => $maxRedemptions,
            'grant_plan_code' => null,
            'grant_interval' => null,
            'grant_duration_days' => null,
            'grant_addon_codes' => $addonCodes,
            'active' => true,
        ]);
    }

    /**
     * Revoke a previously applied access grant. Reverses the state changes
     * recorded in the redemption's grant_applied_snapshot:
     *
     *  - addon-only grant: remove the granted addon codes from the billable
     *    (only those that are not also granted by another still-active grant).
     *  - full grant on a local subscription: subtract grant_days_added from
     *    subscription_ends_at; if the grant was the only thing keeping the
     *    subscription alive (ends_at <= now after subtraction), reset the
     *    subscription source to None.
     *
     * A revoked redemption keeps its row for audit; redemptions_count on the
     * coupon is decremented so the slot becomes available again.
     */
    public function revokeGrant(CouponRedemption $redemption, ?string $reason = null): void
    {
        if ($redemption->isRevoked()) {
            return;
        }

        $coupon = $redemption->coupon;
        if ($coupon === null || ! in_array($coupon->type, [CouponType::AccessGrant, CouponType::PlanWaiver], true)) {
            throw new \InvalidArgumentException('Only access grants and plan waivers can be revoked.');
        }

        $billable = $redemption->billable;
        if (! $billable instanceof Billable) {
            throw new \RuntimeException('Redemption has no resolvable billable.');
        }

        DB::transaction(function () use ($redemption, $coupon, $billable, $reason): void {
            $snapshot = (array) ($redemption->grant_applied_snapshot ?? []);
            $mode = $snapshot['mode'] ?? null;

            if ($mode === 'addon_only') {
                $this->revokeAddonOnlyGrant($billable, $redemption);
            } elseif ($mode === 'full') {
                $this->revokeFullGrant($billable, $redemption);
            } elseif ($mode === 'plan_waiver') {
                $this->revokePlanWaiverPeriods($billable, $redemption);
            }

            $redemption->forceFill([
                'revoked_at' => BillingTime::nowUtc(),
                'revoked_reason' => $reason,
            ])->save();

            if ((int) $coupon->redemptions_count > 0) {
                $coupon->decrement('redemptions_count');
            }

            // A code that could only ever be redeemed once was made for this one
            // redemption (the admin panel's free-time actions and its "issue grant"
            // screen create exactly that). Freeing its slot put it back on offer: the
            // code of a waiver is printed on the invoice it reduced, the dashboard
            // accepts plan-waiver codes, and the tenant could type a revoked gift back
            // in. A shared campaign code keeps the documented behaviour — its slot is
            // freed for the next customer.
            if ((int) ($coupon->max_redemptions ?? 0) === 1 && $coupon->active) {
                $coupon->forceFill(['active' => false])->save();
            }

            event(new GrantRevoked($billable, $coupon, $redemption, $reason));
        });
    }

    private function revokeAddonOnlyGrant(Billable $billable, CouponRedemption $redemption): void
    {
        $snapshot = (array) ($redemption->grant_applied_snapshot ?? []);
        $granted = (array) ($snapshot['addon_codes'] ?? []);
        if ($granted === []) {
            return;
        }

        $otherActiveAddons = $this->addonsFromOtherActiveGrants($billable, $redemption->id);
        $current = $billable->getActiveBillingAddonCodes();
        $remaining = array_values(array_filter(
            $current,
            fn (string $code) => ! in_array($code, $granted, true) || in_array($code, $otherActiveAddons, true),
        ));

        if ($billable instanceof Model) {
            $billable->forceFill(['active_addon_codes' => $remaining])->save();
        }
    }

    /**
     * Take back free plan periods. Periods already waived stay waived — an invoice that
     * was issued at the reduced amount is not reopened.
     *
     * On a Mollie subscription the waiver marker goes and the amount returns to the full
     * price from the next regular date. On a Local subscription the periods had been
     * added to its end date, and are taken off it again.
     */
    private function revokePlanWaiverPeriods(Billable $billable, CouponRedemption $redemption): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        if ($billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value) {
            // The whole waiver, also when later periods were appended to it: they share
            // one marker, and a partial take-back is not something an invoice can state.
            if (app(PlanWaiver::class)->remove($billable)) {
                $this->repriceAfterCommit($billable, (int) $redemption->coupon_id);
            }

            return;
        }

        $days = (int) ($redemption->grant_days_added ?? 0);
        $currentEnd = $billable->getBillingSubscriptionEndsAt();

        if ($days <= 0 || $currentEnd === null) {
            return;
        }

        $newEnd = $currentEnd->copy()->subDays($days);
        $now = BillingTime::nowUtc();

        // Never into the past: that would end the subscription retroactively.
        $billable->forceFill([
            'subscription_ends_at' => $newEnd->lessThan($now) ? $now : $newEnd,
        ])->save();
    }

    private function revokeFullGrant(Billable $billable, CouponRedemption $redemption): void
    {
        // The grant has been superseded by a paid Mollie subscription — a Local→Mollie
        // upgrade leaves `subscription_ends_at` null, so subtracting the grant days below
        // yields null, hits the wipe branch and force-fills source = None, plan_code =
        // null, status = Expired. The customer would lose all access while Mollie keeps
        // charging them, and the next renewal webhook would arrive at a billable with no
        // plan code. Nothing about a paid subscription is the grant's to unwind: the
        // caller still marks the redemption revoked and frees the coupon slot, which is
        // what stops the admin UI from listing it as active.
        if ($billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value) {
            // …unless the grant lives on as a plan waiver on that subscription — the tenant
            // bought paid extras and kept the gift. Then revoking takes the waiver away:
            // the plan becomes chargeable from the next regular date, and what was
            // already waived stays waived. The Mollie PATCH runs after this transaction
            // commits; a PATCH that fails is left for the hourly sweep, the same way a
            // coupon-expiry reset is.
            $waiver = app(PlanWaiver::class)->marker($billable);

            if ($waiver !== null && (int) ($waiver['coupon_id'] ?? 0) === (int) $redemption->coupon_id) {
                app(PlanWaiver::class)->remove($billable);
                $this->repriceAfterCommit($billable, (int) $redemption->coupon_id);

                return;
            }

            \Illuminate\Support\Facades\Log::info('Access grant revoked as a record only — a paid Mollie subscription has superseded it', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'redemption' => $redemption->id,
            ]);

            return;
        }

        $days = (int) ($redemption->grant_days_added ?? 0);
        $currentEnd = $billable->getBillingSubscriptionEndsAt();
        $now = BillingTime::nowUtc();
        $newEnd = $currentEnd?->copy()->subDays($days);

        if (! $billable instanceof Model) {
            return;
        }

        if ($newEnd === null || $newEnd->lessThanOrEqualTo($now)) {
            $billable->forceFill([
                'subscription_source' => SubscriptionSource::None,
                'subscription_plan_code' => null,
                'subscription_interval' => null,
                'subscription_ends_at' => null,
                'subscription_period_starts_at' => null,
                'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Expired,
            ])->save();
        } else {
            $billable->forceFill([
                'subscription_ends_at' => $newEnd,
            ])->save();
        }

        $snapshot = (array) ($redemption->grant_applied_snapshot ?? []);
        $grantedAddons = (array) ($snapshot['addon_codes'] ?? []);
        if ($grantedAddons !== []) {
            $otherActiveAddons = $this->addonsFromOtherActiveGrants($billable, $redemption->id);
            $current = $billable->getActiveBillingAddonCodes();
            $remaining = array_values(array_filter(
                $current,
                fn (string $code) => ! in_array($code, $grantedAddons, true) || in_array($code, $otherActiveAddons, true),
            ));
            $billable->forceFill(['active_addon_codes' => $remaining])->save();
        }
    }

    /**
     * Bring Mollie's recurring amount in line with the billable once the surrounding
     * transaction has committed. Never inside it: an HTTP call cannot be rolled back.
     */
    private function repriceAfterCommit(Billable $billable, int $couponId): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        DB::afterCommit(function () use ($billable, $couponId): void {
            // A failure is queued under its own reason — see syncMollieOrQueue().
            app(PlanWaiver::class)->syncMollieOrQueue($billable->refresh(), $couponId);
        });
    }

    /**
     * @return array<int, string>
     */
    private function addonsFromOtherActiveGrants(Billable $billable, int $excludeRedemptionId): array
    {
        if (! $billable instanceof Model) {
            return [];
        }

        $rows = CouponRedemption::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            ->where('id', '!=', $excludeRedemptionId)
            ->get(['grant_applied_snapshot']);

        $addons = [];
        foreach ($rows as $row) {
            $snap = (array) ($row->grant_applied_snapshot ?? []);
            foreach ((array) ($snap['addon_codes'] ?? []) as $code) {
                $addons[] = (string) $code;
            }
        }

        return array_values(array_unique($addons));
    }

    /**
     * Reject discount-coupon shapes that don't make sense for the given type:
     *
     *  - Both Recurring and SinglePayment: > 100% is rejected (semantically
     *    nonsensical — discount can never exceed the order).
     *  - 100% is allowed for both types. Recurring uses a deferred Mollie-Subscription
     *    startDate over the discount lifetime; SinglePayment uses the Mandate-Only
     *    flow on the Subscription Checkout (and an inline 0-EUR path on
     *    One-Time-Orders), so the first charge never hits Mollie at 0 €.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardAgainstFullCoverageDiscount(array $attributes): void
    {
        $discountType = $attributes['discount_type'] ?? null;
        if ($discountType instanceof DiscountType) {
            $discountType = $discountType->value;
        }

        $discountValue = (int) ($attributes['discount_value'] ?? 0);

        // Checked for both discount types, because the sign is what matters and the
        // pricing services subtract it unexamined. A Fixed `discount_value = -500`
        // *increased* the charge by €5 — a coupon that costs the customer money —
        // and a 0 is a coupon that does nothing while consuming a redemption slot.
        if ($discountValue <= 0) {
            throw new \InvalidArgumentException(
                'A discount value must be greater than zero.'
            );
        }

        if ($discountType !== DiscountType::Percentage->value) {
            return;
        }

        if ($discountValue > 100) {
            throw new \InvalidArgumentException(
                'A discount value greater than 100% is not allowed.'
            );
        }
    }

    private function validateRequiredFieldsForType(CouponType $type, array $attributes): void
    {
        $missing = function (string $key) use ($attributes): bool {
            return ! isset($attributes[$key]) || $attributes[$key] === null || $attributes[$key] === '';
        };

        switch ($type) {
            case CouponType::SinglePayment:
                if ($missing('discount_type') || $missing('discount_value')) {
                    throw new \InvalidArgumentException(
                        'Please choose a discount type and enter a discount value.'
                    );
                }
                $this->guardAgainstFullCoverageDiscount($attributes);
                break;

            case CouponType::Recurring:
                if ($missing('discount_type') || $missing('discount_value')) {
                    throw new \InvalidArgumentException(
                        'Please choose a discount type and enter a discount value.'
                    );
                }
                // The lifetime: a number of discounted charges, a date, or both (whichever
                // ends first). `max_redemptions_per_billable` is not one — it says how
                // often a customer may redeem the code.
                if (isset($attributes['discount_periods']) && $attributes['discount_periods'] !== '' && (int) $attributes['discount_periods'] <= 0) {
                    throw new \InvalidArgumentException(
                        'The number of discounted billing periods must be greater than zero.'
                    );
                }
                $hasPeriods = isset($attributes['discount_periods'])
                    && (int) $attributes['discount_periods'] > 0;
                $hasValidUntil = isset($attributes['valid_until']) && $attributes['valid_until'] !== null && $attributes['valid_until'] !== '';
                if (! $hasPeriods && ! $hasValidUntil) {
                    throw new \InvalidArgumentException(
                        'Recurring coupons need a limit. Set either "Discount applies for … periods" (number of discounted charges) or a "Valid until" date.'
                    );
                }
                $this->guardAgainstFullCoverageDiscount($attributes);
                break;

            case CouponType::Credits:
                $payload = $attributes['credits_payload'] ?? [];
                if (! is_array($payload) || $payload === []) {
                    throw new \InvalidArgumentException(
                        'Credits coupons require a credits payload.'
                    );
                }
                break;

            case CouponType::TrialExtension:
                $days = (int) ($attributes['trial_extension_days'] ?? 0);
                if ($days <= 0) {
                    throw new \InvalidArgumentException(
                        'Please enter how many days the trial should be extended by.'
                    );
                }
                break;

            case CouponType::AccessGrant:
                $hasFull = ! empty($attributes['grant_plan_code']);
                $hasAddonOnly = empty($attributes['grant_plan_code'])
                    && empty($attributes['grant_interval'])
                    && empty($attributes['grant_duration_days'])
                    && ! empty($attributes['grant_addon_codes']);

                if ($hasFull) {
                    if (
                        $missing('grant_plan_code')
                        || $missing('grant_interval')
                        || $missing('grant_duration_days')
                    ) {
                        throw new \InvalidArgumentException(
                            'Please pick a plan, an interval and enter a duration in days.'
                        );
                    }
                } elseif ($hasAddonOnly) {
                    // ok
                } else {
                    throw new \InvalidArgumentException(
                        'Please pick a plan with interval and duration, or select at least one addon for an addon-only grant.'
                    );
                }
                break;

            case CouponType::PeriodExtension:
                if ($missing('grant_duration_days') || (int) $attributes['grant_duration_days'] <= 0) {
                    throw new \InvalidArgumentException(
                        'Please enter how many days the billing period should be extended by.'
                    );
                }
                break;

            case CouponType::PlanWaiver:
                if ($missing('waiver_periods') || (int) $attributes['waiver_periods'] <= 0) {
                    throw new \InvalidArgumentException(
                        'Please enter for how many billing periods the plan should be free.'
                    );
                }
                break;
        }
    }

    /**
     * Set `subscription_meta['active_recurring_coupon']` on initial apply.
     *
     * The marker's lifetime is locked here as `valid_until`, computed as the
     * earliest of:
     *   - the coupon's own valid_until (if set)
     *   - the day before `from + discount_periods` billing periods of the subscription
     *     (calendar months or years on its billing day — see recurringMarkerValidUntil())
     *
     * The validator in validateRequiredFieldsForType() guarantees at least one
     * of those two gates is set on a recurring coupon.
     *
     * `$baseAmountNet` is the recurring net the coupon was applied against at
     * apply-time (= plan + seats + addons at that moment). Future renewals
     * compute the discount against MIN(base, current charge) so seats/addons
     * added after the apply are NOT silently discounted along — only the
     * subscription state the user originally agreed to is covered.
     */
    /**
     * Public entry-point for setting the recurring-coupon marker without going
     * through redeem(). Used by the trial-activation webhook path: when a trial
     * is started together with a recurring coupon, the marker is set immediately
     * so that `computeMarkerDiscount()` already applies on the first charge after
     * the trial. The redemption row is created on that first charge through the
     * existing renewal pipeline; setting the marker here does not redeem.
     */
    public function applyRecurringMarker(
        Coupon $coupon,
        Billable $billable,
        int $baseAmountNet,
        ?string $interval = null,
    ): void {
        $this->setActiveRecurringCouponMarker($coupon, $billable, $baseAmountNet, $interval);
    }

    /**
     * {@see applyRecurringMarker()} for a discount whose first period does not start now —
     * a trial signup's starts with the first charge, at the trial's end.
     *
     * A method of its own rather than a further parameter: apps override
     * `applyRecurringMarker()` with its existing signature, and PHP refuses an override
     * that has fewer parameters than the method it replaces.
     */
    public function applyRecurringMarkerFrom(
        Coupon $coupon,
        Billable $billable,
        int $baseAmountNet,
        string $interval,
        CarbonInterface $lifetimeFrom,
    ): void {
        $this->setActiveRecurringCouponMarker($coupon, $billable, $baseAmountNet, $interval, $lifetimeFrom);
    }

    /**
     * Carry a first-payment (`single_payment`) coupon over a trial: the discount lands on
     * the first charge, at the trial's end, and on no other.
     *
     * A trial collects no first payment, and Mollie's charge at the trial's end is the
     * subscription's recurring amount — so the only place a one-charge discount can live is
     * the recurring amount itself, for exactly one period. That is what a `recurring` marker
     * with one period describes, and everything that prices, defers, resets and books such a
     * marker already exists: Mollie is created at the discounted amount (or, at 100 %, with
     * its start date one period past the trial), the first renewal books the discount line
     * and the redemption, and the same renewal PATCHes Mollie back to the full price.
     *
     * The marker is the coupon's {@see firstChargeView()} plus `first_charge_only`, which
     * keeps the parts of the recurring rules that do not fit a promise already made at
     * signup from applying to it — see {@see isFirstChargeOnly()}.
     */
    public function applyFirstChargeMarkerFrom(
        Coupon $coupon,
        Billable $billable,
        int $baseAmountNet,
        string $interval,
        CarbonInterface $lifetimeFrom,
    ): void {
        if (! $billable instanceof Model) {
            return;
        }

        $marker = $this->recurringMarkerFor($this->firstChargeView($coupon), $billable, $baseAmountNet, $interval, $lifetimeFrom);
        $marker['first_charge_only'] = true;

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['active_recurring_coupon'] = $marker;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Move a coupon carried over a trial onto a new trial end.
     *
     * A trial signup's coupon discounts the charges after the trial, and its window — one
     * period for a first-payment code, `discount_periods` for a recurring one — was measured
     * from the trial end it was written for. A trial extended past part of that window lost
     * the discounted charges it overlapped: a first-payment code expired before the charge it
     * was for, a two-period code discounted one, and an amount PATCH in between (a seat bought
     * during the trial) sent Mollie the full price already. The promise is "the first N
     * charges", and those move with the trial — so the window does, and at 100 % the deferral
     * that skips those charges does as well: it was the window's end, and is moved only while
     * it still is (anything else that deferred the charge stands).
     *
     * A recurring code's own `valid_until` still caps the window, as it did when the marker
     * was written. A plan waiver is not touched: it is a gift that ends on a day.
     *
     * Only for a marker none of whose discounted charges has been booked — a trial whose
     * first charge failed (past due) still has all of them ahead — and only for one whose
     * window was measured from the trial end it replaces (`$previousTrialEnd`): a code whose
     * discount was already running when the trial was set (redeemed on a paid period, say)
     * has its own start, and keeps it.
     */
    public function moveTrialCouponMarkerTo(Billable $billable, CarbonInterface $previousTrialEnd, CarbonInterface $trialEnd): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $marker = $meta['active_recurring_coupon'] ?? null;
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if (! is_array($marker) || PlanWaiver::isWaiver($marker) || $interval === '') {
            return;
        }

        if (self::isFirstChargeOnly($marker)) {
            // One period, without the code's own end date — what the marker was written from
            // (firstChargeView()), independent of later edits of the code.
            $view = new Coupon;
            $view->discount_periods = 1;
            $view->valid_until = null;
        } else {
            $couponId = (int) ($marker['coupon_id'] ?? 0);
            $coupon = $couponId > 0 ? Coupon::query()->find($couponId) : null;

            if ($coupon === null || $coupon->type !== CouponType::Recurring) {
                return;
            }

            // The number of charges and the end date this grant was given, kept with it
            // (recurringMarkerFor()) — not what the offer says now. A marker written before
            // the end date was kept reads the code's.
            $view = clone $coupon;

            if (array_key_exists('discount_periods', $marker)) {
                $view->discount_periods = $marker['discount_periods'] !== null ? (int) $marker['discount_periods'] : null;
            }

            if (array_key_exists('coupon_valid_until', $marker)) {
                try {
                    $view->valid_until = $marker['coupon_valid_until'] !== null
                        ? Carbon::parse((string) $marker['coupon_valid_until'])
                        : null;
                } catch (\Throwable) {
                    return;
                }
            }
        }

        // A discounted charge already booked: the window has begun, and is not the trial's
        // to move any more.
        if ($this->markerHasBookedApplication($marker, $billable)) {
            return;
        }

        // Measured from the trial end it is moved away from? Otherwise its window has a
        // start of its own, and an extended trial does not move it.
        $previousDay = $this->recurringAnchorDay($billable, $interval, $previousTrialEnd);
        $previousValidUntil = $this->recurringMarkerValidUntilOnCycle($view, $interval, $previousTrialEnd, $previousDay);

        try {
            $writtenValidUntil = ! empty($marker['valid_until'])
                ? Carbon::parse((string) $marker['valid_until'])->setTimezone('UTC')
                : null;
        } catch (\Throwable) {
            return;
        }

        if ($previousValidUntil === null || $writtenValidUntil === null
            || ! Carbon::instance($previousValidUntil)->setTimezone('UTC')->isSameDay($writtenValidUntil)
        ) {
            return;
        }

        $day = $this->recurringAnchorDay($billable, $interval, $trialEnd);
        $validUntil = $this->recurringMarkerValidUntilOnCycle($view, $interval, $trialEnd, $day);
        $resumesAt = $validUntil !== null
            ? $this->recurringChargeResumesAt($view, $interval, $trialEnd, $day)
            : null;

        // A window without an end (an open-ended recurring code) has nothing to move.
        if ($validUntil === null || $resumesAt === null) {
            return;
        }

        $previousResumesAt = self::markerChargeResumesAt($marker);
        $storedDeferral = $meta[ChargeDeferral::META_KEY] ?? null;

        // The deferral moves by as much as the window did, wherever it stood from the window's
        // end on: days appended to it (a period extension during the trial) stay on top. Left
        // where it was, it fell inside the moved window and Mollie collected the full price
        // for a charge still promised free. A deferral ending before the window is not this
        // coupon's and stands.
        if ($previousResumesAt !== null && is_string($storedDeferral) && $storedDeferral !== '') {
            try {
                $stored = Carbon::parse($storedDeferral)->setTimezone('UTC');

                if (! $stored->copy()->startOfDay()->lessThan($previousResumesAt->copy()->startOfDay())) {
                    $shifted = $stored->copy()->addSeconds($resumesAt->getTimestamp() - $previousResumesAt->getTimestamp());
                    $meta[ChargeDeferral::META_KEY] = BillingTime::latest($shifted, $resumesAt)->toIso8601String();
                }
            } catch (\Throwable) {
                // An unreadable stored date is left as it is.
            }
        }

        $marker['valid_until'] = Carbon::instance($validUntil)->setTimezone('UTC')->toIso8601String();
        $marker['charge_resumes_at'] = $resumesAt->toIso8601String();
        $meta['active_recurring_coupon'] = $marker;

        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Whether this grant has discounted a charge yet: a redemption row with a discount, of
     * its coupon, written since the grant (loosened by a minute — a coupon's first row is
     * written a moment before its marker, see isMarkerStillRedeemable()). The zero-discount
     * grant row a trial signup writes does not count.
     *
     * @param  array<string, mixed>  $marker
     */
    private function markerHasBookedApplication(array $marker, Model $billable): bool
    {
        $couponId = (int) ($marker['coupon_id'] ?? 0);

        if ($couponId <= 0) {
            return false;
        }

        $since = $this->markerAppliedAt($marker);

        return CouponRedemption::query()
            ->where('coupon_id', $couponId)
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            ->where('discount_amount_net', '>', 0)
            ->when($since !== null, fn ($q) => $q->where('applied_at', '>=', $since->copy()->subMinute()))
            ->exists();
    }

    /**
     * A first-payment coupon seen as the one-period recurring discount it becomes on a
     * trial signup — for the arithmetic only (window, resume date), never saved.
     *
     * One period, and no end date: a `single_payment` code's `valid_until` is the last day
     * it may be *redeemed*, not the day its discount stops. The customer redeemed it at
     * signup; the trial ending after that day must not take the discount back.
     */
    public function firstChargeView(Coupon $coupon): Coupon
    {
        $view = clone $coupon;
        $view->discount_periods = 1;
        $view->valid_until = null;

        return $view;
    }

    /**
     * Whether a marker carries a first-payment coupon over a trial
     * ({@see applyFirstChargeMarkerFrom()}).
     *
     * @param  array<string, mixed>  $marker
     */
    public static function isFirstChargeOnly(array $marker): bool
    {
        return ($marker['first_charge_only'] ?? false) === true;
    }

    /**
     * @param  ?string  $interval  The interval the discount will actually run at. Pass it
     *                             whenever the billable's own interval is about to change
     *                             — see the lifetime computation below.
     */
    private function setActiveRecurringCouponMarker(
        Coupon $coupon,
        Billable $billable,
        int $baseAmountNet,
        ?string $interval = null,
        ?CarbonInterface $lifetimeFrom = null,
    ): void {
        if (! $billable instanceof Model) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['active_recurring_coupon'] = $this->recurringMarkerFor($coupon, $billable, $baseAmountNet, $interval, $lifetimeFrom);

        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * The `active_recurring_coupon` marker `redeem()` would write for this coupon — built,
     * not written.
     *
     * For the caller that has to price Mollie BEFORE the redemption is written:
     * `UpdateSubscription::update()` PATCHes the recurring amount ahead of its local
     * writes (no Mollie call may sit inside the transaction that holds them), and the
     * redemption belongs in that transaction. Pricing the PATCH from this marker sends the
     * discounted amount the first time, without a redemption that a failed change would
     * leave behind. One builder for both, so the amount sent and the marker written cannot
     * come apart.
     *
     * @return array<string, mixed>
     */
    public function recurringMarkerFor(
        Coupon $coupon,
        Billable $billable,
        int $baseAmountNet,
        ?string $interval = null,
        ?CarbonInterface $lifetimeFrom = null,
    ): array {
        $now = BillingTime::nowUtc();

        // The interval the coupon will be charged at, which is not always the one on
        // the billable right now: `UpdateSubscription::update()` redeems before it
        // saves the new interval, so a yearly→monthly switch computed the lifetime in
        // YEARS. A "2 periods" coupon then covered 731 days of monthly renewals — 24
        // discounted periods instead of 2. The reverse direction truncated it to 61
        // days, so the first yearly renewal got nothing.
        $interval ??= $billable->getBillingSubscriptionInterval();
        $from = $lifetimeFrom ?? $now;
        $day = $this->recurringAnchorDay($billable, (string) $interval, $lifetimeFrom);

        // The window ends the day before the first charge it no longer covers, counted in
        // the subscription's own billing periods (see recurringMarkerValidUntil()). What
        // stops the discount at the promised number of applications is the count check
        // in {@see isMarkerStillRedeemable()} as well: a charge Mollie makes later than
        // its cycle date still falls inside the window.
        $markerValidUntil = $this->recurringMarkerValidUntilOnCycle($coupon, (string) $interval, $from, $day);
        $chargeResumesAt = $markerValidUntil !== null
            ? $this->recurringChargeResumesAt($coupon, (string) $interval, $from, $day)
            : null;

        return [
            'coupon_id' => $coupon->id,
            'code' => (string) $coupon->code,
            'discount_type' => $coupon->discount_type?->value,
            'discount_value' => (int) ($coupon->discount_value ?? 0),
            'valid_until' => $markerValidUntil?->toIso8601String(),
            'base_amount_net' => max(0, $baseAmountNet),
            'first_applied_at' => $now->toIso8601String(),
            // The number of discounted charges this grant was given, kept with it like the
            // value and the window: `update()` may still change a redeemed coupon's
            // `discount_periods`, and that is a change of the offer, not of what a customer
            // who already holds it was promised.
            'discount_periods' => $coupon->discount_periods !== null ? (int) $coupon->discount_periods : null,
            // The first charge the discount no longer covers — on the subscription's cycle.
            // Where Mollie is told to start charging when the discount covers everything
            // (CreateSubscription / MollieSubscriptionPatcher::fullCoverageStartDate()).
            // Absent on markers written before 17 November 2026: those keep the date they
            // were given (`valid_until + 1 day`), nothing running is moved.
            'charge_resumes_at' => $chargeResumesAt?->toIso8601String(),
            // The code's own end date as it capped this window, kept like `discount_periods`:
            // the window is moved with a trial extension (moveTrialCouponMarkerTo()), and a
            // date the operator gives the offer later must not cap — or, by no longer matching,
            // stop — the move of a grant made before it.
            'coupon_valid_until' => $coupon->valid_until !== null
                ? Carbon::instance($coupon->valid_until)->setTimezone('UTC')->toIso8601String()
                : null,
        ];
    }

    /**
     * Until when a recurring coupon whose first discounted period starts at `$from`
     * discounts: the earlier of the coupon's own `valid_until` and the day before
     * `from + discount_periods` billing periods. Null when neither limit is set.
     *
     * The periods are the subscription's own — calendar months or years on its billing
     * day (`$anchorDay`, kept through the months that do not have it, see
     * `Support\BillingAnchor`), not 30- or 365-day blocks. Three periods from 31 January
     * end before 30 April, the fourth charge. In 30-day blocks they ended on 1 May and the
     * first charge after a fully covering code fell on 3 May — and stayed on the 3rd.
     *
     * The day before, because a renewal is discounted while the discount is valid at the
     * start of its day: the charge on that day is the first one that is not.
     *
     * Public because the checkout has to name the date money is first collected
     * ({@see recurringChargeResumesAt()}). A second copy of the arithmetic in the form is
     * how a shown date and a charged date come apart.
     *
     * The periods run on `$from`'s own day; {@see recurringMarkerValidUntilOnCycle()}
     * takes the billing day of a cycle `$from` shows shortened.
     */
    public function recurringMarkerValidUntil(Coupon $coupon, string $interval, ?CarbonInterface $from = null): ?CarbonInterface
    {
        return $this->recurringMarkerValidUntilOnCycle($coupon, $interval, $from, null);
    }

    /**
     * {@see recurringMarkerValidUntil()} on a cycle whose billing day is `$anchorDay` —
     * for a `$from` that is the shortened day of a cycle on the 29th–31st (28 February of
     * a cycle on the 31st). A method of its own, not a further parameter, so an app that
     * overrides `recurringMarkerValidUntil()` keeps a compatible signature.
     */
    public function recurringMarkerValidUntilOnCycle(Coupon $coupon, string $interval, ?CarbonInterface $from, ?int $anchorDay): ?CarbonInterface
    {
        $from = $from !== null ? BillingTime::toUtc($from) : BillingTime::nowUtc();
        $periods = (int) ($coupon->discount_periods ?? 0);

        $durationValidUntil = $periods > 0
            ? Carbon::instance(BillingAnchor::advance($from, $interval, $periods, $anchorDay)->subDay())->setTimezone('UTC')
            : null;

        $couponValidUntil = $coupon->valid_until;

        return match (true) {
            $couponValidUntil !== null && $durationValidUntil !== null
                => $couponValidUntil->lessThan($durationValidUntil) ? $couponValidUntil : $durationValidUntil,
            $couponValidUntil !== null => $couponValidUntil,
            $durationValidUntil !== null => $durationValidUntil,
            default => null,
        };
    }

    /**
     * The first charge a fully covering recurring coupon leaves to Mollie: the first date
     * of the subscription's cycle — from `$from`, the first discounted period, on
     * `$anchorDay` — that its window no longer covers. Null when the coupon has no limit.
     *
     * For a count of periods that is `from + discount_periods` periods, the regular charge
     * date. For a coupon that ends on a date (`valid_until`) it is the first regular charge
     * on or after that day — not the day after it, which lies inside a period and moved the
     * billing day of every such customer for good.
     */
    public function recurringChargeResumesAt(Coupon $coupon, string $interval, ?CarbonInterface $from = null, ?int $anchorDay = null): ?Carbon
    {
        $from = $from !== null ? BillingTime::toUtc($from) : BillingTime::nowUtc();
        $validUntil = $this->recurringMarkerValidUntilOnCycle($coupon, $interval, $from, $anchorDay);

        if ($validUntil === null) {
            return null;
        }

        return Carbon::instance(BillingAnchor::firstOnOrAfter($from, $interval, $anchorDay, $validUntil))->setTimezone('UTC');
    }

    /**
     * The date a recurring coupon marker sends the charge to when it covers everything,
     * or null for a marker that does not carry one (written before 17 November 2026, or
     * a plan waiver, which has its own — PlanWaiver::chargeResumesAt()).
     *
     * @param  array<string, mixed>  $marker
     */
    public static function markerChargeResumesAt(array $marker): ?Carbon
    {
        $value = $marker['charge_resumes_at'] ?? null;

        if (! is_string($value) || $value === '' || PlanWaiver::isWaiver($marker)) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The billing day a recurring coupon's periods are counted on.
     *
     * Applied with a period (checkout, upgrade, change), `$from` is the start of that
     * period and its own day is the billing day. Applied from a later charge (the next
     * renewal on the portal, a trial's end), that charge may be the shortened day of a
     * cycle on the 29th–31st — 28 February of a cycle on the 31st — and the day of the
     * cycle is the one it was shortened from (BillingAnchor::forCycle()).
     */
    public function recurringAnchorDay(Billable $billable, string $interval, ?CarbonInterface $from): ?int
    {
        if ($from === null) {
            return null;
        }

        $from = BillingTime::toUtc($from);
        $periodStart = $billable->getBillingPeriodStartsAt();

        // The running period itself: its day is the one recorded for it, if any.
        if ($periodStart !== null && BillingTime::toUtc($periodStart)->toDateString() === $from->toDateString()) {
            return BillingAnchor::day($billable) ?? $from->day;
        }

        return BillingAnchor::forCycle($billable, $periodStart, $from, $interval);
    }

    /**
     * Where the periods of a recurring code start when it is applied to a subscription
     * that runs on its own cycle — null for "now" (a checkout, whose first period starts
     * with the payment).
     *
     * A discount counts in charges: the period whose charge carries it is its first.
     * When the code discounts a charge made now for the running period (the prorata of a
     * plan change), that period is the first of the N; otherwise the first is the one the
     * next charge opens. An interval change starts a new cycle now. Counted from "now"
     * instead, a code entered in the middle of a period ended on a day off the cycle, and
     * a fully covering one sent Mollie there.
     */
    public function recurringLifetimeFrom(Billable $billable, bool $discountsCurrentPeriod, ?string $interval = null): ?CarbonInterface
    {
        if ($billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value) {
            return null;
        }

        $current = $billable->getBillingSubscriptionInterval();

        if ($interval !== null && $current !== null && $interval !== $current) {
            $now = BillingTime::nowUtc();

            return $discountsCurrentPeriod ? $now : BillingTime::addInterval($now, $interval);
        }

        if ($discountsCurrentPeriod) {
            // The running period — when the anchor still names one that contains today. A
            // stale anchor (nothing turned it over) is no period to count from.
            $start = $billable->getBillingPeriodStartsAt();

            return $start !== null
                && BillingAnchor::next($start, $current ?? $interval, BillingAnchor::day($billable))->greaterThan(BillingTime::nowUtc())
                    ? $start
                    : null;
        }

        return ChargeDeferral::undeferredNextChargeDate($billable);
    }

    /**
     * Returns the active recurring coupon marker for a billable, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveRecurringCouponMarker(Billable $billable): ?array
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        return is_array($marker) ? $marker : null;
    }

    /**
     * Compute the discount that the active recurring coupon marker applies
     * to a given net amount. Returns 0 if no marker is set or the underlying
     * coupon is no longer active / no longer in its validity window.
     *
     * The discount applies only to the *base amount* the coupon was originally
     * applied against — seats/addons added later don't enlarge the discount.
     * If the user later REDUCES seats/addons below that base, the discount
     * is capped at the actual current charge so it can never exceed it.
     */
    /**
     * `$target` describes the configuration being priced when it is not the billable's
     * current one — a plan change prices the plan it is moving *to*. Only a plan waiver
     * reads it (its ceiling follows the plan line); an ordinary coupon is locked to an
     * amount and needs nothing but `$netAmount`.
     *
     * @param  array{plan_code?: ?string, interval?: ?string, addon_codes?: ?array<int, string>, seats?: ?int}  $target
     */
    public function computeMarkerDiscount(Billable $billable, int $netAmount, array $target = [], ?CarbonInterface $asOf = null): int
    {
        return $this->discountOfMarker($this->getActiveRecurringCouponMarker($billable), $billable, $netAmount, $target, $asOf);
    }

    /**
     * {@see computeMarkerDiscount()} for a marker that is about to be written
     * ({@see recurringMarkerFor()}) instead of the one the billable holds.
     *
     * @param  array<string, mixed>  $marker
     * @param  array{plan_code?: ?string, interval?: ?string, addon_codes?: ?array<int, string>, seats?: ?int}  $target
     */
    public function computeDiscountOfMarker(Billable $billable, array $marker, int $netAmount, array $target = []): int
    {
        return $this->discountOfMarker($marker, $billable, $netAmount, $target, null);
    }

    /**
     * {@see computeDiscountOfMarker()} judged for the day the amount is collected on
     * (`$asOf`, null for now) — what a PATCH of Mollie's recurring amount has to ask: the
     * next charge, not today. A method of its own so an override of
     * `computeDiscountOfMarker()` keeps a compatible signature.
     *
     * @param  array<string, mixed>  $marker
     * @param  array{plan_code?: ?string, interval?: ?string, addon_codes?: ?array<int, string>, seats?: ?int}  $target
     */
    public function computeDiscountOfMarkerOn(Billable $billable, array $marker, int $netAmount, array $target, ?CarbonInterface $asOf): int
    {
        return $this->discountOfMarker($marker, $billable, $netAmount, $target, $asOf);
    }

    /**
     * @param  ?array<string, mixed>  $marker
     * @param  array{plan_code?: ?string, interval?: ?string, addon_codes?: ?array<int, string>, seats?: ?int}  $target
     */
    private function discountOfMarker(?array $marker, Billable $billable, int $netAmount, array $target, ?CarbonInterface $asOf): int
    {
        if ($marker === null || $netAmount <= 0) {
            return 0;
        }

        // `$asOf` is for the renewal webhook, which prices a charge Mollie created
        // earlier than it is heard about: the discount that applied is the one valid
        // when the charge was created, not when its money settled.
        if (! $this->isMarkerStillRedeemable($marker, $billable, $asOf)) {
            return 0;
        }

        // A plan waiver takes off the plan line and nothing else: capped at what was
        // waived when it was given AND at what those lines cost in this configuration.
        if (PlanWaiver::isWaiver($marker)) {
            $ceiling = app(PlanWaiver::class)->ceiling(
                $billable,
                $marker,
                $target['plan_code'] ?? null,
                $target['interval'] ?? null,
                $target['addon_codes'] ?? null,
                $target['seats'] ?? null,
            );

            return max(0, min($ceiling, $netAmount));
        }

        $type = (string) ($marker['discount_type'] ?? '');
        $value = (int) ($marker['discount_value'] ?? 0);

        // Cap the discount basis at the original base_amount_net so additions
        // (seats, addons) after coupon-apply don't get silently discounted.
        // Markers persisted before this field existed (legacy) fall back to
        // the live netAmount — matching the prior behaviour for that case.
        $baseAmount = isset($marker['base_amount_net'])
            ? min((int) $marker['base_amount_net'], $netAmount)
            : $netAmount;

        if ($type === DiscountType::Percentage->value) {
            return (int) round($baseAmount * $value / 100);
        }

        if ($type === DiscountType::Fixed->value) {
            return min($value, $baseAmount);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $marker
     * @param  ?CarbonInterface  $asOf  The moment to judge the clock at. Defaults to now;
     *                                  `markerExpired()` passes the *next* charge date so
     *                                  the reset PATCH can be posted before that charge
     *                                  leaves Mollie. The campaign count is deliberately
     *                                  read as of now either way — the redemptions written
     *                                  so far are the applications actually spent.
     */
    private function isMarkerStillRedeemable(array $marker, ?Billable $billable = null, ?CarbonInterface $asOf = null): bool
    {
        $couponId = (int) ($marker['coupon_id'] ?? 0);
        $coupon = $couponId > 0 ? Coupon::query()->find($couponId) : null;
        if ($coupon === null) {
            return false;
        }

        // Deactivating a recurring coupon ends the discount for everyone who holds it —
        // that is the documented way to resolve a `recurring_conflict`. A plan waiver is
        // not a campaign: it is what remains of a gift this one tenant was given, or
        // free periods an operator granted. Switching the code off stops NEW redemptions
        // and leaves a Local grant untouched, so it must leave the same grant untouched
        // once the tenant has bought extras on top — otherwise the next renewal charged
        // the plan in full and, with `on_expiry = end`, cancelled the subscription.
        // Taking a gift back is `revokeGrant()`.
        //
        // A first-payment coupon carried over a trial is not a campaign running on either:
        // it is the discount the customer was shown and accepted at signup, deferred only
        // because the trial collects nothing. Switching the code off afterwards stops new
        // redemptions, as it does for a first payment that was collected at once.
        if (! $coupon->active && ! PlanWaiver::isWaiver($marker) && ! self::isFirstChargeOnly($marker)) {
            return false;
        }

        $at = $asOf !== null ? Carbon::instance($asOf) : BillingTime::nowUtc();
        $validUntil = isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;

        // A waiver ends on a day, not at the hour it was given — see PlanWaiver::endDay().
        if ($validUntil !== null && PlanWaiver::isWaiver($marker)) {
            $validUntil = PlanWaiver::endDay($validUntil);
        }

        if ($validUntil !== null && $validUntil->lessThanOrEqualTo($at)) {
            return false;
        }

        // Count as well as clock. The window used to be `max × 30 (+1)` days, which is
        // shorter than the calendar wherever February falls: a 3-period coupon redeemed
        // on 1 December ran to day 91 while Mollie's FOURTH charge landed 1 March — day
        // 90, inside the window — so the customer received four discounted periods. The
        // window is counted in billing periods now, and markers written before that keep
        // their date; and a charge can still be created later than its cycle date. The
        // promise is a number of applications, so that is what is enforced as well.
        //
        // Only rows that carried a discount count. A zero-discount grant row exists
        // solely to occupy the campaign slot (the trial path writes one, since its
        // discount arrives with the later charges), and letting it eat an application
        // would give trial signups one discounted period fewer than paid ones.
        if ($billable instanceof Model) {
            // A plan waiver carries its own count: the coupon behind it is a one-off
            // grant whose `max_redemptions_per_billable` is 1 and means "issued once",
            // not "one free period". Null there is "the date alone ends it".
            $isWaiver = PlanWaiver::isWaiver($marker);
            // An ordinary coupon's count as it was when the grant was given; a marker
            // written before the count was kept with it reads the coupon.
            $max = match (true) {
                $isWaiver => (int) ($marker['max_applications'] ?? 0),
                array_key_exists('discount_periods', $marker) => (int) ($marker['discount_periods'] ?? 0),
                default => (int) ($coupon->discount_periods ?? 0),
            };

            // Of THIS grant. A code that may be redeemed again
            // (`max_redemptions_per_billable > 1`) starts a new count with each
            // redemption; without a lower bound the charges an earlier grant discounted
            // would use up the new one before it had discounted anything. A waiver's
            // rows are all written after its marker. A coupon's first row is written a
            // moment BEFORE the marker in the same redeem(), so its bound is loosened by
            // a minute — and only applied where a second grant is possible at all, so a
            // marker written by an older release is counted exactly as before.
            $since = $this->markerAppliedAt($marker);

            if (! $isWaiver) {
                $since = $since !== null && (int) ($coupon->max_redemptions_per_billable ?? 1) > 1
                    ? $since->copy()->subMinute()
                    : null;
            }

            if ($max > 0) {
                $applied = CouponRedemption::query()
                    ->where('coupon_id', $coupon->id)
                    ->where('billable_type', $billable->getMorphClass())
                    ->where('billable_id', $billable->getKey())
                    ->whereNull('revoked_at')
                    ->where('discount_amount_net', '>', 0)
                    ->when($isWaiver, fn ($q) => $q->where('grant_applied_snapshot->mode', 'plan_waiver_renewal'))
                    // Of THIS waiver. The same code can be redeemed again once a waiver
                    // has run out (`max_redemptions_per_billable > 1`); the renewals the
                    // earlier one waived would otherwise use up the new one before it
                    // had waived anything, with the redemption slot already spent.
                    ->when($since !== null, fn ($q) => $q->where('applied_at', '>=', $since))
                    ->count();

                if ($applied >= $max) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $marker
     */
    private function markerAppliedAt(array $marker): ?Carbon
    {
        $value = $marker['first_applied_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Apply a recurring-coupon discount during a Mollie renewal webhook:
     * write a CouponRedemption with the given invoice_id and return it (or null
     * if no marker / coupon is no longer redeemable). Caller is responsible for
     * the Mollie-Subscription PATCH if the marker is now expired (use markerExpired()).
     */
    public function redeemRecurringCouponForRenewal(
        Billable $billable,
        int $netAmount,
        int $invoiceId,
        ?CarbonInterface $asOf = null,
    ): ?CouponRedemption {
        $marker = $this->getActiveRecurringCouponMarker($billable);
        if ($marker === null || ! $this->isMarkerStillRedeemable($marker, $billable, $asOf)) {
            return null;
        }

        $couponId = (int) ($marker['coupon_id'] ?? 0);
        $coupon = Coupon::query()->find($couponId);
        if ($coupon === null) {
            return null;
        }

        // Use the marker-aware computation so the redemption record matches what
        // was actually billed on the invoice (capped at base_amount_net, not the
        // current charge net which may include seats/addons added after apply).
        $discount = $this->computeMarkerDiscount($billable, $netAmount, [], $asOf);

        // A waived period is recorded as a plain row, never through redeem(): that
        // would re-run the coupon's own effect — for an access grant, the grant itself.
        if (PlanWaiver::isWaiver($marker)) {
            if (! $billable instanceof Model) {
                return null;
            }

            $redemption = new CouponRedemption();
            $redemption->coupon_id = $coupon->id;
            $redemption->billable_type = $billable->getMorphClass();
            $redemption->billable_id = $billable->getKey();
            $redemption->applied_at = BillingTime::nowUtc();
            $redemption->discount_amount_net = $discount;
            $redemption->invoice_id = $invoiceId;
            $redemption->grant_applied_snapshot = ['mode' => 'plan_waiver_renewal'];
            $redemption->save();

            event(new CouponRedeemed($billable, $coupon, $redemption));

            return $redemption;
        }

        return $this->redeem($coupon, $billable, [
            'discount_amount_net' => $discount,
            'invoice_id' => $invoiceId,
            'skip_marker' => true,
            // Renewals re-apply a grant that was already counted; see redeem().
            'renewal' => true,
        ]);
    }

    /**
     * Returns true if the marker has reached its limit (applied_count >= max_count
     * or valid_until <= now or coupon deactivated). Used by the Renewal-Webhook
     * AFTER a successful redeem to decide whether to PATCH Mollie back to full price.
     *
     * `$nextChargeAt` is what makes the reset land in time. The PATCH is what actually
     * stops the discount — the marker only governs our own bookkeeping — and Mollie
     * charges the amount it currently holds, so asking "is the marker expired *now*"
     * one period too early is the difference between a discount ending and a discount
     * running one period over. On the count axis the two coincide (the redemption for
     * this period is already written when the caller asks, so the limit is reached at
     * the same moment either way), but on the clock axis they do not: a coupon whose
     * `valid_until` falls mid-period is still live at this webhook, so nothing was
     * PATCHed, and the next charge left Mollie at the discounted amount. It arrived as
     * an ordinary renewal, the marker was expired by then so no redemption was written,
     * and the reset only happened afterwards. One free discounted period per campaign
     * with a date gate, invisible in the redemption trail. Pass the end of the period
     * this payment just bought and the reset is posted while it still matters.
     */
    public function markerExpired(Billable $billable, ?CarbonInterface $nextChargeAt = null): bool
    {
        $marker = $this->getActiveRecurringCouponMarker($billable);
        if ($marker === null) {
            return false;
        }

        return ! $this->isMarkerStillRedeemable($marker, $billable, $nextChargeAt);
    }

    public function clearActiveRecurringCouponMarker(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        if (! isset($meta['active_recurring_coupon'])) {
            return;
        }

        unset($meta['active_recurring_coupon']);
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Apply a PeriodExtension coupon: extend subscription_ends_at on Local subs,
     * push the next-charge date on Mollie subs.
     */
    private function applyPeriodExtension(Coupon $coupon, Billable $billable, CouponRedemption $redemption): void
    {
        $days = (int) ($coupon->grant_duration_days ?? 0);
        if ($days <= 0) {
            return;
        }

        $source = $billable->getBillingSubscriptionSource();

        if ($this->periodExtensionExtendsTrial($billable)) {
            // While a trial runs, "everything is free for N more days" IS a longer
            // trial. The charge was moved off the period anchor instead (mandate capture
            // plus one interval), which is not when a trial is charged: the charge went
            // to day 40 while the trial still ended on day 14, and a mandate-holding
            // trial with no charge 14 days after its end is flipped to past due.
            // extendBillingTrialUntil() moves the trial end and Mollie's start date
            // together, after this transaction commits.
            $billable->extendBillingTrialUntil($billable->getBillingTrialEndsAt()->copy()->addDays($days));

            $redemption->grant_days_added = $days;
            $redemption->trial_days_added = $days;
            $redemption->grant_applied_snapshot = [
                'mode' => 'period_extension',
                'duration_days' => $days,
                'source' => $source,
                'extended' => 'trial',
            ];

            return;
        }

        if ($source === SubscriptionSource::Local->value) {
            $currentEnd = $billable->getBillingSubscriptionEndsAt();
            $now = BillingTime::nowUtc();
            $newEnd = ($currentEnd && $currentEnd->isFuture() ? $currentEnd->copy() : $now->copy())->addDays($days);

            if ($billable instanceof Model) {
                $billable->forceFill(['subscription_ends_at' => $newEnd])->save();
            }

            event(new SubscriptionExtended($billable, $currentEnd, $newEnd, $coupon));
        } elseif ($source === SubscriptionSource::Mollie->value) {
            // Absolute target, computed here and PATCHed after this redemption commits —
            // see MollieSubscriptionPatcher::deferNextChargeDate(). Posting it from inside
            // the transaction meant a later throw rolled the redemption back while Mollie
            // had already moved the charge: a free period, a campaign slot still open, and
            // a second extension on the retry.
            //
            // Counted from the date Mollie will really charge. With the charge deferred
            // past a fully covering coupon that is NOT the period end: `period end + N`
            // lies before the deferred date, and Mollie — which holds the full price
            // there — collected it while the coupon was still running. An extension
            // only ever moves the charge later; under a deferral the days are appended
            // to it and the period keeps its regular cycle.
            $deferral = ChargeDeferral::until($billable);
            $from = $deferral ?? $billable->nextBillingDate() ?? BillingTime::nowUtc();
            $target = $from->copy()->addDays($days);
            app(MollieSubscriptionPatcher::class)->deferNextChargeDate(
                $billable,
                $target,
                periodFollows: $deferral === null,
            );
        }

        $redemption->grant_days_added = $days;
        $redemption->grant_applied_snapshot = [
            'mode' => 'period_extension',
            'duration_days' => $days,
            'source' => $source,
        ];
    }

    /**
     * Whether a period extension is, for this billable, an extension of its trial.
     *
     * A running trial whose charge follows the trial end. One whose charge is deferred
     * past it (a fully covering coupon or waiver) is extended like any other deferred
     * subscription: the days are appended to the deferral.
     */
    private function periodExtensionExtendsTrial(Billable $billable): bool
    {
        $trialEndsAt = $billable->getBillingTrialEndsAt();

        return $billable->getBillingSubscriptionStatus() === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Trial
            && $trialEndsAt !== null
            && $trialEndsAt->isFuture()
            && ChargeDeferral::until($billable) === null;
    }

    /**
     * Apply a PlanWaiver coupon: N billing periods with the plan line waived.
     *
     * On a Mollie subscription that is a {@see PlanWaiver} marker — started fresh, or
     * appended to one already running. On a Local subscription nothing is paid in the
     * first place, so the periods are simply added to its end date.
     *
     * A waiver that came from this coupon type continues as a paid plan when it runs
     * out: unlike an access grant it is given to a customer who is already paying.
     */
    private function applyPlanWaiver(Coupon $coupon, Billable $billable, CouponRedemption $redemption): void
    {
        $periods = (int) ($coupon->waiver_periods ?? 0);

        if ($periods <= 0 || ! $billable instanceof Model) {
            return;
        }

        $source = $billable->getBillingSubscriptionSource();
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');

        $redemption->grant_applied_snapshot = [
            'mode' => 'plan_waiver',
            'periods' => $periods,
            'source' => $source,
        ];

        if ($source === SubscriptionSource::Local->value) {
            $currentEnd = $billable->getBillingSubscriptionEndsAt();
            $newEnd = BillingTime::toUtc($currentEnd && $currentEnd->isFuture() ? $currentEnd : BillingTime::nowUtc());

            for ($i = 0; $i < $periods; $i++) {
                $newEnd = \Carbon\Carbon::instance(BillingTime::addInterval($newEnd, $interval));
            }

            $billable->forceFill(['subscription_ends_at' => $newEnd])->save();
            $redemption->grant_days_added = (int) round(($currentEnd ?? BillingTime::nowUtc())->diffInDays($newEnd, true));

            event(new SubscriptionExtended($billable, $currentEnd, $newEnd, $coupon));

            return;
        }

        $waiver = app(PlanWaiver::class);

        if ($waiver->marker($billable) !== null) {
            $waiver->extendByPeriods($billable, $periods);
        } else {
            $waiver->grant(
                $billable,
                $coupon,
                $planCode,
                $interval,
                [],
                null,
                $periods,
                PlanWaiver::ON_EXPIRY_CONTINUE_PAID,
            );
        }

        $this->repriceAfterCommit($billable, (int) $coupon->id);
    }

    private function applyAccessGrant(Coupon $coupon, Billable $billable, CouponRedemption $redemption): void
    {
        $hasFullPlan = ! empty($coupon->grant_plan_code);
        $addonOnly = ! $hasFullPlan && ! empty($coupon->grant_addon_codes);
        $now = BillingTime::nowUtc();

        if ($addonOnly) {
            $existing = $billable->getActiveBillingAddonCodes();
            $merged = array_values(array_unique(array_merge(
                $existing,
                (array) $coupon->grant_addon_codes,
            )));

            if ($billable instanceof Model) {
                $billable->forceFill(['active_addon_codes' => $merged])->save();
            }

            $redemption->grant_applied_snapshot = [
                'mode' => 'addon_only',
                'addon_codes' => $coupon->grant_addon_codes,
            ];
            $redemption->grant_days_added = null;

            event(new SubscriptionExtended(
                $billable,
                $billable->getBillingSubscriptionEndsAt(),
                $billable->getBillingSubscriptionEndsAt() ?? $now,
                $coupon,
            ));

            return;
        }

        // Full grant — Local subscription.
        $days = (int) ($coupon->grant_duration_days ?? 0);
        $currentEnd = $billable->getBillingSubscriptionEndsAt();
        $existingPlan = $billable->getBillingSubscriptionPlanCode();
        $isFirstActivation = $existingPlan === null
            || $billable->getBillingSubscriptionSource() === SubscriptionSource::None->value;

        if ($isFirstActivation) {
            $activator = app(ActivateLocalSubscription::class);

            // Best-effort call: ActivateLocalSubscription is filled in a later
            // phase but this service must remain forward-compatible.
            try {
                $activator->handle(
                    $billable,
                    (string) $coupon->grant_plan_code,
                    (string) $coupon->grant_interval,
                    (array) ($coupon->grant_addon_codes ?? []),
                    $days,
                );
            } catch (\RuntimeException $e) {
                // Fallback: write the local subscription state directly so the
                // coupon redemption is functional even without the activator.
                if ($billable instanceof Model) {
                    $billable->forceFill([
                        'subscription_source' => SubscriptionSource::Local,
                        'subscription_plan_code' => (string) $coupon->grant_plan_code,
                        'subscription_interval' => (string) $coupon->grant_interval,
                        'subscription_period_starts_at' => $now,
                        'subscription_ends_at' => $now->copy()->addDays($days),
                        'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
                        'active_addon_codes' => array_values(array_unique(array_merge(
                            $billable->getActiveBillingAddonCodes(),
                            (array) ($coupon->grant_addon_codes ?? []),
                        ))),
                    ])->save();
                }
            }

            // Not dispatched here: ActivateLocalSubscription — the only path that gets
            // this far — ends with SubscriptionCreated itself. Firing it again wrote a
            // second `subscription_created` audit row at the same second and ran every app
            // listener twice, provisioning whatever they provision a second time.
        } else {
            // Extend existing local subscription.
            $newEnd = ($currentEnd && $currentEnd->isFuture()
                ? $currentEnd->copy()
                : $now->copy()
            )->addDays($days);

            if ($billable instanceof Model) {
                $billable->forceFill([
                    'subscription_ends_at' => $newEnd,
                ])->save();
            }

            event(new SubscriptionExtended($billable, $currentEnd, $newEnd, $coupon));
        }

        $redemption->grant_days_added = $days;
        $redemption->grant_applied_snapshot = [
            'mode' => 'full',
            'plan_code' => $coupon->grant_plan_code,
            'interval' => $coupon->grant_interval,
            'addon_codes' => $coupon->grant_addon_codes,
            'duration_days' => $days,
        ];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\CouponRedeemed;
use GraystackIT\MollieBilling\Events\GrantRevoked;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
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
use GraystackIT\MollieBilling\Support\BillingTime;
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

        $this->validateRequiredFieldsForType($type, $attributes);

        return Coupon::create($attributes);
    }

    public function update(Coupon $coupon, array $attributes): Coupon
    {
        if (
            isset($attributes['code'])
            && strtoupper(trim((string) $attributes['code'])) !== $coupon->code
            && (int) $coupon->redemptions_count > 0
        ) {
            throw new \InvalidArgumentException(
                'Cannot change the code of a coupon that has already been redeemed.'
            );
        }

        if (isset($attributes['code'])) {
            $attributes['code'] = strtoupper(trim((string) $attributes['code']));
        }

        $coupon->update($attributes);

        return $coupon->refresh();
    }

    public function deactivate(Coupon $coupon): void
    {
        $coupon->update(['active' => false]);
    }

    public function delete(Coupon $coupon): void
    {
        if ((int) $coupon->redemptions_count > 0) {
            throw new CouponInUseException(
                'Cannot delete a coupon that has been redeemed; deactivate it instead.'
            );
        }

        $coupon->delete();
    }

    public function validate(string $code, Billable $billable, array $context): Coupon
    {
        $coupon = $this->resolveAndValidateShared($code, $billable, $context);

        // Per-billable count
        if ($billable instanceof Model) {
            $perBillable = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey())
                ->count();

            if ($perBillable >= (int) ($coupon->max_redemptions_per_billable ?? 1)) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'per_billable_limit_reached');
            }
        }

        if ($coupon->type === CouponType::Recurring) {
            $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
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
            $planCode = $context['planCode'] ?? null;
            $planTrialDays = (int) (config('mollie-billing-plans.plans.'.$planCode.'.trial_days') ?? 0);
            $onTrial = $billable->isOnBillingTrial();

            if (! $onTrial && $planTrialDays <= 0) {
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

            $nextBilling = $billable->nextBillingDate();
            if ($source === SubscriptionSource::Mollie->value
                && $nextBilling !== null
                && $nextBilling->lessThanOrEqualTo(BillingTime::nowUtc()->addDay())
            ) {
                throw new InvalidCouponException($billable, (string) $coupon->code, 'too_close_to_charge');
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
     * TrialExtension is rejected because it requires a billable; AccessGrant
     * skips source-based checks since no subscription exists yet.
     */
    public function validateWithoutBillable(string $code, array $context): Coupon
    {
        $coupon = $this->resolveAndValidateShared($code, null, $context);

        if ($coupon->type === CouponType::TrialExtension) {
            throw new InvalidCouponException(null, (string) $coupon->code, 'requires_billable');
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

        if (
            ! empty($coupon->applicable_plans)
            && $planCode !== null
            && ! in_array($planCode, (array) $coupon->applicable_plans, true)
        ) {
            throw new InvalidCouponException($billable, $code, 'plan_not_applicable');
        }

        if (
            ! empty($coupon->applicable_intervals)
            && $interval !== null
            && ! in_array($interval, (array) $coupon->applicable_intervals, true)
        ) {
            throw new InvalidCouponException($billable, $code, 'interval_not_applicable');
        }

        if (! empty($coupon->applicable_addons) && $addonCodes !== []) {
            $allowed = (array) $coupon->applicable_addons;
            foreach ($addonCodes as $addon) {
                if (! in_array($addon, $allowed, true)) {
                    throw new InvalidCouponException($billable, $code, 'addon_not_applicable');
                }
            }
        }

        $productCodes = (array) ($context['productCodes'] ?? []);
        if (! empty($coupon->applicable_products) && $productCodes !== []) {
            $allowedProducts = (array) $coupon->applicable_products;
            foreach ($productCodes as $product) {
                if (! in_array($product, $allowedProducts, true)) {
                    throw new InvalidCouponException($billable, $code, 'product_not_applicable');
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

    public function redeem(Coupon $coupon, Billable $billable, array $context): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $billable, $context): CouponRedemption {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

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

            $coupon->increment('redemptions_count');
            $coupon->refresh();

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
                    if (($context['skip_marker'] ?? false) !== true) {
                        // The marker locks the discount to the recurring net the
                        // coupon was applied against — seats/addons added later
                        // are billed at full price.
                        $baseAmount = (int) ($context['orderAmountNet'] ?? 0);
                        $this->setActiveRecurringCouponMarker($coupon, $billable, $baseAmount);
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
                    $billable->extendBillingTrialUntil($newEnd);

                    // Mollie has no concept of a trial — our trial is a Mollie
                    // subscription with a deferred startDate (see CreateSubscription).
                    // The startDate must follow the new trial end, else Mollie
                    // would charge at the originally scheduled date.
                    if ($billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value) {
                        app(MollieSubscriptionPatcher::class)->setNextChargeDate($billable, $newEnd);
                    }

                    $redemption->trial_days_added = $days;
                    break;

                case CouponType::AccessGrant:
                    $this->applyAccessGrant($coupon, $billable, $redemption);
                    break;
            }

            $redemption->save();

            event(new CouponRedeemed($billable, $coupon, $redemption));

            return $redemption;
        });
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
    ): Coupon {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::AccessGrant,
            'grant_plan_code' => $planCode,
            'grant_interval' => $interval,
            'grant_addon_codes' => $addonCodes,
            'grant_duration_days' => $durationDays,
            'active' => true,
        ]);
    }

    public function addonGrantCoupon(string $code, array $addonCodes): Coupon
    {
        return $this->create([
            'code' => $code,
            'name' => 'Auto: '.$code,
            'type' => CouponType::AccessGrant,
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
        if ($coupon === null || $coupon->type !== CouponType::AccessGrant) {
            throw new \InvalidArgumentException('Only access grants can be revoked.');
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
            }

            $redemption->forceFill([
                'revoked_at' => BillingTime::nowUtc(),
                'revoked_reason' => $reason,
            ])->save();

            if ((int) $coupon->redemptions_count > 0) {
                $coupon->decrement('redemptions_count');
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

    private function revokeFullGrant(Billable $billable, CouponRedemption $redemption): void
    {
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
        if ($discountType !== DiscountType::Percentage->value) {
            return;
        }

        $discountValue = (int) ($attributes['discount_value'] ?? 0);

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
                $hasMax = isset($attributes['max_redemptions_per_billable'])
                    && (int) $attributes['max_redemptions_per_billable'] > 0;
                $hasValidUntil = isset($attributes['valid_until']) && $attributes['valid_until'] !== null && $attributes['valid_until'] !== '';
                if (! $hasMax && ! $hasValidUntil) {
                    throw new \InvalidArgumentException(
                        'Recurring coupons need a limit. Set either "Max per billable" (number of billing periods the discount applies) or a "Valid until" date.'
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
        }
    }

    /**
     * Set `subscription_meta['active_recurring_coupon']` on initial apply.
     *
     * The marker's lifetime is locked here as `valid_until`, computed as the
     * earliest of:
     *   - the coupon's own valid_until (if set)
     *   - now + max_redemptions_per_billable × intervalDays + 1d (1d buffer so
     *     a charge that lands a few hours after the mathematical period end is
     *     still within the discount window)
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
    public function applyRecurringMarker(Coupon $coupon, Billable $billable, int $baseAmountNet): void
    {
        $this->setActiveRecurringCouponMarker($coupon, $billable, $baseAmountNet);
    }

    private function setActiveRecurringCouponMarker(Coupon $coupon, Billable $billable, int $baseAmountNet): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $now = BillingTime::nowUtc();
        $intervalDays = $billable->getBillingSubscriptionInterval() === 'yearly' ? 365 : 30;
        $maxRedemptions = (int) ($coupon->max_redemptions_per_billable ?? 0);

        $durationValidUntil = $maxRedemptions > 0
            ? $now->copy()->addDays($maxRedemptions * $intervalDays + 1)
            : null;

        $couponValidUntil = $coupon->valid_until;

        $markerValidUntil = match (true) {
            $couponValidUntil !== null && $durationValidUntil !== null
                => $couponValidUntil->lessThan($durationValidUntil) ? $couponValidUntil : $durationValidUntil,
            $couponValidUntil !== null => $couponValidUntil,
            $durationValidUntil !== null => $durationValidUntil,
            default => null,
        };

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['active_recurring_coupon'] = [
            'coupon_id' => $coupon->id,
            'code' => (string) $coupon->code,
            'discount_type' => $coupon->discount_type?->value,
            'discount_value' => (int) ($coupon->discount_value ?? 0),
            'valid_until' => $markerValidUntil?->toIso8601String(),
            'base_amount_net' => max(0, $baseAmountNet),
            'first_applied_at' => $now->toIso8601String(),
        ];

        $billable->forceFill(['subscription_meta' => $meta])->save();
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
    public function computeMarkerDiscount(Billable $billable, int $netAmount): int
    {
        $marker = $this->getActiveRecurringCouponMarker($billable);
        if ($marker === null || $netAmount <= 0) {
            return 0;
        }

        if (! $this->isMarkerStillRedeemable($marker)) {
            return 0;
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
     */
    private function isMarkerStillRedeemable(array $marker): bool
    {
        $couponId = (int) ($marker['coupon_id'] ?? 0);
        $coupon = $couponId > 0 ? Coupon::query()->find($couponId) : null;
        if ($coupon === null || ! $coupon->active) {
            return false;
        }

        $now = BillingTime::nowUtc();
        $validUntil = isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;
        if ($validUntil !== null && $validUntil->lessThanOrEqualTo($now)) {
            return false;
        }

        return true;
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
    ): ?CouponRedemption {
        $marker = $this->getActiveRecurringCouponMarker($billable);
        if ($marker === null || ! $this->isMarkerStillRedeemable($marker)) {
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
        $discount = $this->computeMarkerDiscount($billable, $netAmount);

        return $this->redeem($coupon, $billable, [
            'discount_amount_net' => $discount,
            'invoice_id' => $invoiceId,
            'skip_marker' => true,
        ]);
    }

    /**
     * Returns true if the marker has reached its limit (applied_count >= max_count
     * or valid_until <= now or coupon deactivated). Used by the Renewal-Webhook
     * AFTER a successful redeem to decide whether to PATCH Mollie back to full price.
     */
    public function markerExpired(Billable $billable): bool
    {
        $marker = $this->getActiveRecurringCouponMarker($billable);
        if ($marker === null) {
            return false;
        }

        return ! $this->isMarkerStillRedeemable($marker);
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

        if ($source === SubscriptionSource::Local->value) {
            $currentEnd = $billable->getBillingSubscriptionEndsAt();
            $now = BillingTime::nowUtc();
            $newEnd = ($currentEnd && $currentEnd->isFuture() ? $currentEnd->copy() : $now->copy())->addDays($days);

            if ($billable instanceof Model) {
                $billable->forceFill(['subscription_ends_at' => $newEnd])->save();
            }

            event(new SubscriptionExtended($billable, $currentEnd, $newEnd, $coupon));
        } elseif ($source === SubscriptionSource::Mollie->value) {
            $patcher = app(MollieSubscriptionPatcher::class);
            $patcher->pushNextChargeDate($billable, $days);
        }

        $redemption->grant_days_added = $days;
        $redemption->grant_applied_snapshot = [
            'mode' => 'period_extension',
            'duration_days' => $days,
            'source' => $source,
        ];
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

            event(new SubscriptionCreated(
                $billable,
                (string) $coupon->grant_plan_code,
                (string) $coupon->grant_interval,
            ));
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

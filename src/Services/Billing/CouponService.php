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
            throw new \InvalidArgumentException('Coupon code is required.');
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

        $now = now();

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

        if (
            $coupon->minimum_order_amount_net !== null
            && $orderAmount < (int) $coupon->minimum_order_amount_net
        ) {
            throw new InvalidCouponException($billable, $code, 'min_order_not_met');
        }

        if ($coupon->type !== CouponType::AccessGrant) {
            if (! $coupon->stackable && $existingCouponIds !== []) {
                $others = Coupon::query()->whereIn('id', $existingCouponIds)->get();
                foreach ($others as $other) {
                    if (! $other->stackable) {
                        throw new CouponNotStackableException(
                            "Coupon {$coupon->code} is not stackable with {$other->code}."
                        );
                    }
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

        if (! $coupon->isWithinValidity(now()) || ! $coupon->hasGlobalRedemptionsLeft()) {
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
            $redemption->applied_at = now();
            $redemption->discount_amount_net = 0;

            switch ($coupon->type) {
                case CouponType::FirstPayment:
                    $redemption->discount_amount_net = (int) ($context['discount_amount_net'] ?? 0);
                    if ($redemption->discount_amount_net === 0 && isset($context['orderAmountNet'])) {
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
                    $redemption->discount_amount_net = (int) ($context['discount_amount_net'] ?? 0);
                    if ($redemption->discount_amount_net === 0 && isset($context['orderAmountNet'])) {
                        $redemption->discount_amount_net = $this->computeRecurringDiscount(
                            $coupon,
                            (int) $context['orderAmountNet'],
                        );
                    }
                    if (isset($context['invoice_id'])) {
                        $redemption->invoice_id = (int) $context['invoice_id'];
                    }
                    break;

                case CouponType::Credits:
                    $payload = (array) ($coupon->credits_payload ?? []);
                    $applied = [];
                    foreach ($payload as $type => $quantity) {
                        $qty = (int) $quantity;
                        if ($qty <= 0) {
                            continue;
                        }
                        $this->walletService->credit($billable, (string) $type, $qty);
                        $applied[$type] = $qty;
                    }
                    $redemption->credits_applied = $applied;
                    break;

                case CouponType::TrialExtension:
                    $days = (int) ($coupon->trial_extension_days ?? 0);
                    $current = $billable->getBillingTrialEndsAt();
                    $newEnd = ($current && $current->isFuture() ? $current->copy() : now())->addDays($days);
                    $billable->extendBillingTrialUntil($newEnd);
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
                $this->walletService->credit($billable, (string) $type, $qty);
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
                'revoked_at' => now(),
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
        $now = now();
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

    private function validateRequiredFieldsForType(CouponType $type, array $attributes): void
    {
        $missing = function (string $key) use ($attributes): bool {
            return ! isset($attributes[$key]) || $attributes[$key] === null || $attributes[$key] === '';
        };

        switch ($type) {
            case CouponType::FirstPayment:
                if ($missing('discount_type') || $missing('discount_value')) {
                    throw new \InvalidArgumentException(
                        'first_payment coupon requires discount_type and discount_value.'
                    );
                }
                break;

            case CouponType::Recurring:
                if ($missing('discount_type') || $missing('discount_value') || $missing('valid_until')) {
                    throw new \InvalidArgumentException(
                        'recurring coupon requires discount_type, discount_value and valid_until.'
                    );
                }
                break;

            case CouponType::Credits:
                $payload = $attributes['credits_payload'] ?? [];
                if (! is_array($payload) || $payload === []) {
                    throw new \InvalidArgumentException(
                        'credits coupon requires a non-empty credits_payload.'
                    );
                }
                break;

            case CouponType::TrialExtension:
                $days = (int) ($attributes['trial_extension_days'] ?? 0);
                if ($days <= 0) {
                    throw new \InvalidArgumentException(
                        'trial_extension coupon requires a positive trial_extension_days.'
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
                            'full access_grant coupon requires grant_plan_code, grant_interval and grant_duration_days.'
                        );
                    }
                } elseif ($hasAddonOnly) {
                    // ok
                } else {
                    throw new \InvalidArgumentException(
                        'access_grant coupon requires either a full grant (plan+interval+duration) or addon-only grant_addon_codes.'
                    );
                }
                break;
        }
    }

    private function applyAccessGrant(Coupon $coupon, Billable $billable, CouponRedemption $redemption): void
    {
        $hasFullPlan = ! empty($coupon->grant_plan_code);
        $addonOnly = ! $hasFullPlan && ! empty($coupon->grant_addon_codes);
        $now = now();

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

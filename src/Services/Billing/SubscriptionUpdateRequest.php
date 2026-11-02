<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

class SubscriptionUpdateRequest
{
    /** @var array<int, string> */
    public readonly array $couponCodes;

    /** 'immediate' | 'end_of_period' */
    public readonly string $applyAt;

    /**
     * Whether the caller actually named an `apply_at`.
     *
     * `plan_change_mode = end_of_period` has to tell "the caller asked for immediate,
     * which this install forbids" apart from "the caller said nothing" — the latter is
     * every internal API (`changeBillingPlan()`, the portal), which would otherwise
     * throw `Immediate plan changes are not allowed.` instead of being scheduled.
     */
    public readonly bool $applyAtExplicit;

    /**
     * @param  array<int, string>|null  $couponCodes  Multiple coupon codes (stackable). Falls back to `couponCode` when null.
     * @param  string|null  $applyAt  'immediate' | 'end_of_period'; null means "no preference".
     */
    public function __construct(
        public readonly ?string $planCode = null,
        public readonly ?string $interval = null,
        public readonly ?int $seats = null,
        public readonly ?array $addons = null,
        public readonly ?string $couponCode = null,
        ?string $applyAt = null,
        public readonly bool $internal = false,
        /**
         * Escape hatch for moving a billable onto a hidden plan (internal /
         * supplier / grandfathered). Settable only through this typed argument
         * (or `withAllowHiddenPlan()`) — `from()` deliberately ignores any
         * `allow_hidden_plan` key in array payloads, so no request data can flip
         * it. Apps use it for programmatic admin moves, e.g. putting an existing
         * tenant on a supplier plan without going through
         * ActivateLocalSubscription.
         */
        public readonly bool $allowHiddenPlan = false,
        ?array $couponCodes = null,
        ?bool $applyAtExplicit = null,
    ) {
        $this->applyAt = $applyAt ?? 'immediate';
        $this->applyAtExplicit = $applyAtExplicit ?? ($applyAt !== null);

        // Normalise into a deduped, non-empty array. The single `couponCode`
        // input is treated as a one-element list when `couponCodes` is null,
        // for backwards-compat with existing call sites.
        $codes = $couponCodes ?? ($couponCode !== null && $couponCode !== '' ? [$couponCode] : []);
        $codes = array_values(array_filter(array_map(
            fn ($code) => is_string($code) ? trim($code) : '',
            $codes,
        ), fn (string $code) => $code !== ''));
        $this->couponCodes = array_values(array_unique(array_map('strtoupper', $codes)));
    }

    /**
     * Build the DTO from a loosely typed payload.
     *
     * `allow_hidden_plan` is intentionally NOT read here. It overrides an
     * authorization check, so it must not be reachable from data: an app that
     * forwards `request()->all()` (or any other array it did not build itself)
     * into `update()` would otherwise let a caller select a hidden plan. The
     * override is only available through the typed API — the constructor's
     * `allowHiddenPlan:` argument or `withAllowHiddenPlan()` — which request
     * data cannot produce.
     */
    public static function from(array|self $request): self
    {
        if ($request instanceof self) {
            return $request;
        }

        $couponCodes = $request['coupon_codes'] ?? $request['couponCodes'] ?? null;
        if (! is_array($couponCodes) && $couponCodes !== null) {
            $couponCodes = null;
        }

        return new self(
            planCode: $request['plan_code'] ?? $request['planCode'] ?? null,
            interval: $request['interval'] ?? null,
            seats: isset($request['seats']) ? (int) $request['seats'] : null,
            addons: $request['addons'] ?? null,
            couponCode: $request['coupon_code'] ?? $request['couponCode'] ?? null,
            applyAt: $request['apply_at'] ?? $request['applyAt'] ?? null,
            // `internal` is not read either, for the same reason as `allow_hidden_plan`:
            // it waives the plan-change mode and the lapsed-period guard, so an array an
            // app forwards from request data could otherwise buy a seat increase prorated
            // to zero on a stalled renewal. See asInternalReentry().
            couponCodes: $couponCodes,
        );
    }

    /**
     * Trusted copy marked as an internal re-entry.
     *
     * Only `ScheduleSubscriptionChange::apply()` uses it: the change it replays was
     * validated when it was scheduled and is applied exactly at the period roll-over,
     * which is what the exemptions keyed on `internal` exist for.
     */
    public function asInternalReentry(): self
    {
        return new self(
            planCode: $this->planCode,
            interval: $this->interval,
            seats: $this->seats,
            addons: $this->addons,
            couponCode: $this->couponCode,
            applyAt: $this->applyAt,
            internal: true,
            allowHiddenPlan: $this->allowHiddenPlan,
            couponCodes: $this->couponCodes,
            applyAtExplicit: $this->applyAtExplicit,
        );
    }

    /**
     * Trusted copy with the hidden-plan override enabled.
     *
     * Two kinds of callers use it: app code performing a deliberate admin move,
     * and the internal re-entry paths that replay a change which already passed
     * the guard — `ScheduleSubscriptionChange::apply()` at period end. Phase 2 of
     * a deferred prorata change goes through
     * `SubscriptionChangeContext::fromPendingArray()` instead, which reads the
     * flag from the payload the package itself wrote into `subscription_meta`.
     */
    public function withAllowHiddenPlan(bool $allow = true): self
    {
        return new self(
            planCode: $this->planCode,
            interval: $this->interval,
            seats: $this->seats,
            addons: $this->addons,
            couponCode: $this->couponCode,
            applyAt: $this->applyAt,
            internal: $this->internal,
            allowHiddenPlan: $allow,
            couponCodes: $this->couponCodes,
            applyAtExplicit: $this->applyAtExplicit,
        );
    }

    /**
     * Serialised representation for persistence (e.g. subscription_meta).
     *
     * The `internal` flag is intentionally omitted — it is a transient marker
     * for the `ScheduleSubscriptionChange::apply()` re-entry path and must not
     * be carried back through stored payloads.
     *
     * `allow_hidden_plan` *is* carried, and only when true: a scheduled or
     * deferred move onto a hidden plan must still pass the hidden-plan guard
     * when it is applied later, otherwise the change would be accepted now and
     * fail at period end (or after the prorata payment already went through).
     */
    public function toArray(): array
    {
        return array_filter([
            'plan_code' => $this->planCode,
            'interval' => $this->interval,
            'seats' => $this->seats,
            'addons' => $this->addons,
            'coupon_code' => $this->couponCode,
            'coupon_codes' => $this->couponCodes !== [] ? $this->couponCodes : null,
            'apply_at' => $this->applyAt,
            'allow_hidden_plan' => $this->allowHiddenPlan ?: null,
        ], fn ($v) => $v !== null);
    }
}

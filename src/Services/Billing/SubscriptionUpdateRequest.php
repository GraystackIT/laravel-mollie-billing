<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

class SubscriptionUpdateRequest
{
    /** @var array<int, string> */
    public readonly array $couponCodes;

    /**
     * @param  array<int, string>|null  $couponCodes  Multiple coupon codes (stackable). Falls back to `couponCode` when null.
     */
    public function __construct(
        public readonly ?string $planCode = null,
        public readonly ?string $interval = null,
        public readonly ?int $seats = null,
        public readonly ?array $addons = null,
        public readonly ?string $couponCode = null,
        public readonly string $applyAt = 'immediate', // 'immediate' | 'end_of_period'
        public readonly bool $internal = false,
        ?array $couponCodes = null,
    ) {
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
            applyAt: $request['apply_at'] ?? $request['applyAt'] ?? 'immediate',
            internal: (bool) ($request['internal'] ?? false),
            couponCodes: $couponCodes,
        );
    }

    /**
     * Serialised representation for persistence (e.g. subscription_meta).
     *
     * The `internal` flag is intentionally omitted — it is a transient marker
     * for the `ScheduleSubscriptionChange::apply()` re-entry path and must not
     * be carried back through stored payloads.
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
        ], fn ($v) => $v !== null);
    }
}

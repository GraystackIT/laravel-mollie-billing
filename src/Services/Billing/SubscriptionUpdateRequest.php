<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

class SubscriptionUpdateRequest
{
    public function __construct(
        public readonly ?string $planCode = null,
        public readonly ?string $interval = null,
        public readonly ?int $seats = null,
        public readonly ?array $addons = null,
        public readonly ?string $couponCode = null,
        public readonly string $applyAt = 'immediate', // 'immediate' | 'end_of_period'
    ) {
    }

    public static function from(array|self $request): self
    {
        if ($request instanceof self) {
            return $request;
        }

        return new self(
            planCode: $request['plan_code'] ?? $request['planCode'] ?? null,
            interval: $request['interval'] ?? null,
            seats: isset($request['seats']) ? (int) $request['seats'] : null,
            addons: $request['addons'] ?? null,
            couponCode: $request['coupon_code'] ?? $request['couponCode'] ?? null,
            applyAt: $request['apply_at'] ?? $request['applyAt'] ?? 'immediate',
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'plan_code' => $this->planCode,
            'interval' => $this->interval,
            'seats' => $this->seats,
            'addons' => $this->addons,
            'coupon_code' => $this->couponCode,
            'apply_at' => $this->applyAt,
        ], fn ($v) => $v !== null);
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

/**
 * Value object that holds all computed values for a subscription change.
 *
 * Built once during UpdateSubscription::update() and passed to
 * ValidateSubscriptionChange for validation. When a deferred upgrade
 * is stored as pending_plan_change, the context is serialized into
 * subscription_meta so that applyPendingPlanChange() can reconstruct it.
 */
class SubscriptionChangeContext
{
    public function __construct(
        public readonly string $currentPlan,
        public readonly string $currentInterval,
        public readonly int $currentSeats,
        /** @var array<int, string> */
        public readonly array $currentAddons,
        public readonly int $currentNet,

        public readonly string $newPlan,
        public readonly string $newInterval,
        public int $newSeats,
        /** @var array<int, string> */
        public array $newAddons,
        public readonly int $newNet,

        public readonly bool $planChanged,
        public readonly bool $intervalChanged,

        public readonly int $prorataChargeNet,
        public readonly int $prorataCreditNet,

        public readonly bool $isMollie,
        public readonly ?string $couponCode = null,
        public readonly bool $seatsExplicit = false,
    ) {}

    /**
     * Serialize the context for storage in subscription_meta['pending_plan_change'].
     *
     * @return array<string, mixed>
     */
    public function toPendingArray(): array
    {
        return [
            'current_plan' => $this->currentPlan,
            'current_interval' => $this->currentInterval,
            'current_seats' => $this->currentSeats,
            'current_addons' => $this->currentAddons,
            'plan_code' => $this->newPlan,
            'interval' => $this->newInterval,
            'seats' => $this->newSeats,
            'addons' => $this->newAddons,
            'new_net' => $this->newNet,
            'prorata_charge_net' => $this->prorataChargeNet,
            'coupon_code' => $this->couponCode,
            'requested_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Reconstruct a context from a stored pending_plan_change array.
     */
    public static function fromPendingArray(array $data, bool $isMollie): self
    {
        return new self(
            currentPlan: (string) ($data['current_plan'] ?? ''),
            currentInterval: (string) ($data['current_interval'] ?? 'monthly'),
            currentSeats: (int) ($data['current_seats'] ?? 0),
            currentAddons: (array) ($data['current_addons'] ?? []),
            currentNet: 0, // Not needed for apply phase

            newPlan: (string) ($data['plan_code'] ?? ''),
            newInterval: (string) ($data['interval'] ?? 'monthly'),
            newSeats: (int) ($data['seats'] ?? 0),
            newAddons: (array) ($data['addons'] ?? []),
            newNet: (int) ($data['new_net'] ?? 0),

            planChanged: ($data['current_plan'] ?? '') !== ($data['plan_code'] ?? ''),
            intervalChanged: ($data['current_interval'] ?? '') !== ($data['interval'] ?? ''),

            prorataChargeNet: 0, // Already charged in Phase 1 — no Mollie readiness check needed
            prorataCreditNet: 0,

            isMollie: $isMollie,
            couponCode: $data['coupon_code'] ?? null,
            seatsExplicit: true, // Seats were pre-computed in Phase 1
        );
    }
}

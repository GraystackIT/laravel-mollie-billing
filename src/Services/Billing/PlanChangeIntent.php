<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Scopes\BillingScope;

/**
 * Composer input. Standalone-constructible — no coupling to SubscriptionChangeContext.
 *
 * Addons are modeled as a `code => quantity` map. Today usually `quantity = 1` (boolean),
 * possibly quantitative in the future (e.g. ['print-gateway' => 3]).
 */
final class PlanChangeIntent
{
    /**
     * @param  array<string, int>  $currentAddons code → quantity
     * @param  array<string, int>  $newAddons code → quantity
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly string $currentPlan,
        public readonly string $newPlan,
        public readonly string $currentInterval,
        public readonly string $newInterval,
        public readonly int $currentSeats,
        public readonly int $newSeats,
        public readonly array $currentAddons,
        public readonly array $newAddons,
        // Past-Due-Reset: when set, the patcher must reset Mollie's recurring
        // schedule to `now + 1 interval` instead of leaving the cadence
        // untouched, so the failed prior charge is not retried at the new
        // price right after the patch.
        public readonly bool $forceResetStartDate = false,
        // The recurring-coupon marker a code entered with this change is about to write
        // (CouponService::recurringMarkerFor()). The PATCH is priced from it instead of
        // the marker the billable holds, because the redemption is written after Mollie
        // has been called. Deliberately NOT serialised: a retry runs once the change has
        // committed and reads the marker that was actually written — or, if the change
        // failed, the absence of one.
        public readonly ?array $couponMarker = null,
    ) {}

    /**
     * Serialized for persistence in the pending state.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // The class name, not getMorphClass(): fromArray() and
            // RetrySubscriptionPatchJob resolve this as a class, and under
            // Relation::enforceMorphMap() an alias fails class_exists() — the retry then
            // no-ops without clearing its marker and Mollie keeps charging the old amount.
            'billable_type' => $this->billable::class,
            'billable_id' => $this->billable->getKey(),
            'current_plan' => $this->currentPlan,
            'new_plan' => $this->newPlan,
            'current_interval' => $this->currentInterval,
            'new_interval' => $this->newInterval,
            'current_seats' => $this->currentSeats,
            'new_seats' => $this->newSeats,
            'current_addons' => $this->currentAddons,
            'new_addons' => $this->newAddons,
            'force_reset_start_date' => $this->forceResetStartDate,
        ];
    }

    /**
     * Deserialisiert aus Pending-State. Billable-Resolve via Klassen-Lookup.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $billableClass = (string) $data['billable_type'];

        // Scope-free: this is reached from RetrySubscriptionPatchJob and from the
        // prorata webhook, neither of which has the request or tenant context an
        // app-defined applyBillingScope() keys off. With the scope applied, findOrFail()
        // throws on every one of the job's attempts until it gives up and clears the
        // marker — so the Mollie PATCH never lands and the stale price is charged with
        // nothing left to retry.
        /** @var Billable $billable */
        $billable = $billableClass::query()
            ->withoutGlobalScope(BillingScope::class)
            ->findOrFail($data['billable_id']);

        return new self(
            billable: $billable,
            currentPlan: (string) $data['current_plan'],
            newPlan: (string) $data['new_plan'],
            currentInterval: (string) $data['current_interval'],
            newInterval: (string) $data['new_interval'],
            currentSeats: (int) $data['current_seats'],
            newSeats: (int) $data['new_seats'],
            currentAddons: (array) ($data['current_addons'] ?? []),
            newAddons: (array) ($data['new_addons'] ?? []),
            forceResetStartDate: (bool) ($data['force_reset_start_date'] ?? false),
        );
    }
}

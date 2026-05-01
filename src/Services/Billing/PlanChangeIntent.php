<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

/**
 * Composer-Input. Standalone konstruierbar — kein Coupling an SubscriptionChangeContext.
 *
 * Addons werden als `code => quantity`-Map modelliert. Heute meist `quantity = 1` (boolean),
 * künftig ggf. quantitativ (z.B. ['print-gateway' => 3]).
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
    ) {}

    /**
     * Serialisiert für Persistierung im Pending-State.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'current_plan' => $this->currentPlan,
            'new_plan' => $this->newPlan,
            'current_interval' => $this->currentInterval,
            'new_interval' => $this->newInterval,
            'current_seats' => $this->currentSeats,
            'new_seats' => $this->newSeats,
            'current_addons' => $this->currentAddons,
            'new_addons' => $this->newAddons,
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
        /** @var Billable $billable */
        $billable = $billableClass::query()->findOrFail($data['billable_id']);

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
        );
    }
}

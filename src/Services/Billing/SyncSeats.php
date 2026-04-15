<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

class SyncSeats
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    public function handle(Billable $billable, int $seats): void
    {
        $this->updateSubscription->update($billable, [
            'seats' => max(0, $seats),
        ]);
    }
}

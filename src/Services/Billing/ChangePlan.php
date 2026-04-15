<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

class ChangePlan
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    public function handle(Billable $billable, string $planCode, string $interval): void
    {
        $this->updateSubscription->update($billable, [
            'plan_code' => $planCode,
            'interval' => $interval,
        ]);
    }
}

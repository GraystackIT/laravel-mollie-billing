<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;
use Laravel\CashierMollie\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'mollie_status' => MollieSubscriptionStatus::class,
        ]);
    }
}

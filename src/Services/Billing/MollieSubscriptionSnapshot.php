<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;

final class MollieSubscriptionSnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly MollieSubscriptionStatus $status,
        public readonly ?CarbonInterface $startDate,
    ) {}
}

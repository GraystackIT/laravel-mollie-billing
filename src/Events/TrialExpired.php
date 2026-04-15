<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrialExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
    ) {
    }
}

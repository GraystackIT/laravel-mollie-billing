<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;

class SyncSeatsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public readonly int $seats,
    ) {
    }

    public function handle(SyncSeats $service): void
    {
        /** @var class-string $class */
        $class = $this->billableClass;

        /** @var Billable|null $billable */
        $billable = $class::find($this->billableId);

        if ($billable === null) {
            return;
        }

        $service->handle($billable, $this->seats);
    }
}

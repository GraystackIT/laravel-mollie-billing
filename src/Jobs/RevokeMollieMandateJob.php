<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mollie\Laravel\Facades\Mollie;

/**
 * Revokes a previously valid Mollie mandate after the customer set up a new one.
 */
class RevokeMollieMandateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $mollieCustomerId,
        public readonly string $mandateId,
    ) {}

    public function handle(): void
    {
        try {
            Mollie::api()->mandates->revokeForId($this->mollieCustomerId, $this->mandateId);
        } catch (\Throwable $e) {
            Log::warning('Failed to revoke Mollie mandate', [
                'customer_id' => $this->mollieCustomerId,
                'mandate_id' => $this->mandateId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMismatchResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Single-mismatch auto-resolve job. Throws on transient failures so the queue
 * retries with exponential backoff. Permanent failures and resolved/skipped
 * outcomes return cleanly — the persisted state on the mismatch row carries
 * the audit trail.
 */
class AutoResolveSingleCountryMismatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $uniqueFor = 1800;

    public int $tries = 3;

    public function __construct(public readonly int $mismatchId)
    {
    }

    public function uniqueId(): string
    {
        return 'auto-resolve-mismatch:'.$this->mismatchId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CountryMismatchResolutionService $resolver): void
    {
        $mismatch = BillingCountryMismatch::query()->whereKey($this->mismatchId)->first();
        if ($mismatch === null) {
            return;
        }

        $result = $resolver->attemptAutoResolve($mismatch);

        if ($result->isTransientFail()) {
            throw new \RuntimeException((string) $result->reason);
        }
    }
}

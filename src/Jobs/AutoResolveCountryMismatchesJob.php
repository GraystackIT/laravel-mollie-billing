<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly sweep over Pending country mismatches that are eligible for an
 * auto-resolve attempt. Each candidate is dispatched as its own
 * AutoResolveSingleCountryMismatchJob so that retries / failures are scoped
 * per mismatch rather than aborting the whole sweep.
 *
 * Eligibility:
 *   - status = Pending
 *   - auto_resolve_attempts < config('mollie-billing.country_mismatch_max_auto_attempts')
 *   - last_auto_attempt_at IS NULL OR <= now() - 2h (cooldown between sweeps)
 */
class AutoResolveCountryMismatchesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'auto-resolve-country-mismatches';
    }

    public function handle(): void
    {
        if (! (bool) config('mollie-billing.country_mismatch_auto_resolve_enabled', true)) {
            return;
        }

        $maxAttempts = (int) config('mollie-billing.country_mismatch_max_auto_attempts', 5);
        $cooldown = BillingTime::nowUtc()->subHours(2);

        BillingCountryMismatch::query()
            ->where('status', CountryMismatchStatus::Pending)
            ->where('auto_resolve_attempts', '<', $maxAttempts)
            ->where(function ($q) use ($cooldown): void {
                $q->whereNull('last_auto_attempt_at')
                    ->orWhere('last_auto_attempt_at', '<=', $cooldown);
            })
            ->orderBy('id')
            ->chunkById(200, function ($mismatches): void {
                foreach ($mismatches as $m) {
                    AutoResolveSingleCountryMismatchJob::dispatch((int) $m->id);
                }
            });
    }
}

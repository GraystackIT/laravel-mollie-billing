<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Revokes a previously valid Mollie mandate after the customer set up a new one.
 *
 * A 404/410 counts as success: the mandate is already gone, which is the goal. Both
 * CleanupOrphanedBillablesJob::revokeMandate() and the RevokePreviousMandate listener
 * can dispatch for the same (customer, mandate) pair, so the second one arriving to
 * find nothing left is expected — re-throwing burned all three tries and filed a
 * failed_jobs row for work that was already done.
 */
class RevokeMollieMandateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $tries = 3;

    public function __construct(
        public readonly string $mollieCustomerId,
        public readonly string $mandateId,
    ) {
        $this->initializeBillingQueue();
    }

    public function handle(): void
    {
        try {
            MollieCall::send(new RevokeMandateRequest($this->mollieCustomerId, $this->mandateId));
        } catch (ApiException $e) {
            if (! in_array($e->getCode(), [404, 410], true)) {
                $this->logFailure($e);

                throw $e;
            }

            Log::info('Mollie mandate was already revoked', [
                'customer_id' => $this->mollieCustomerId,
                'mandate_id' => $this->mandateId,
                'status' => $e->getCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logFailure($e);

            throw $e;
        }
    }

    private function logFailure(\Throwable $e): void
    {
        Log::warning('Failed to revoke Mollie mandate', [
            'customer_id' => $this->mollieCustomerId,
            'mandate_id' => $this->mandateId,
            'error' => $e->getMessage(),
        ]);
    }
}

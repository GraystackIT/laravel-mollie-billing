<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Daily poll for overage charges that were created and then never heard from again.
 *
 * `usage_overage_status = pending` with a `payment_id` is the one overage state nothing
 * owned. Pass 3 of `PrepareUsageOverageJob` skips a snapshot that carries a payment id
 * ("its outcome belongs to the webhook"), and `safeCharge()` skips a billable whose status
 * is `pending` — both correct while a payment really is in flight, and both without an
 * escape hatch. So a lost webhook (Mollie outage, app downtime past its retry window) or a
 * retry chain that exhausted its attempts left the snapshot armed forever:
 *
 * - the charge is never collected,
 * - `hasUnsettledOverageFor()` stays true, so every renewal carries the negative balance
 *   into the new period and the customer permanently loses that much quota,
 * - and every *future* overage for that billable is silenced, which is exactly the harm
 *   the status gate exists to prevent for the failed case.
 *
 * Its two sibling markers — `pending_prorata_change` and `country_corrections` — have had
 * a poll like this all along. This is the same shape: ask Mollie what actually happened,
 * then either finish the delivery that was lost or mark the cycle spent so the next one is
 * free.
 */
class CleanupStalePendingOverageChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * One attempt: every branch below either completes a delivery or writes a terminal
     * status, and a re-run after a partial pass would re-poll rows the first pass already
     * resolved. The next daily pass is the retry.
     */
    public int $tries = 1;

    /** One synchronous Mollie call per stale snapshot, so the run scales with the backlog. */
    public int $timeout = 600;

    /**
     * How long a pending charge may stay unheard-of before it is polled.
     *
     * Mollie's own webhook retries span hours, and a SEPA direct debit legitimately takes
     * days to settle — so this is deliberately generous. It only has to be shorter than a
     * billing period, because that is when the unsettled debt starts costing the customer
     * quota.
     */
    public const STALE_AFTER_HOURS = 72;

    /**
     * Past this, a charge that is still not terminal at Mollie is written off as failed.
     *
     * A payment stuck in `open` for a fortnight is not going to settle, and leaving the
     * snapshot armed costs the customer more than a fresh attempt does.
     */
    public const HARD_LIMIT_DAYS = 14;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function handle(): void
    {
        $billableClass = config('mollie-billing.billable_model');

        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return;
        }

        // chunkById, and scope-free: a worker has no tenant context for an app-defined
        // applyBillingScope() to key off, and the handlers below mutate the very column the
        // filter reads.
        $billableClass::withoutGlobalScope(BillingScope::class)
            ->whereNotNull('subscription_meta')
            ->chunkById(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        $this->processBillable($billable);
                    } catch (\Throwable $e) {
                        // One unresolvable row must not stop the sweep.
                        Log::error('CleanupStalePendingOverageChargeJob: could not process a billable', [
                            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function processBillable(Billable $billable): void
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $snapshot = (array) ($meta[ChargeUsageOverageDirectly::META_KEY] ?? []);
        $paymentId = (string) ($snapshot['payment_id'] ?? '');

        if ($paymentId === '' || ($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
            return;
        }

        $createdAt = isset($snapshot['created_at'])
            ? \Carbon\Carbon::parse((string) $snapshot['created_at'])->setTimezone('UTC')
            // No timestamp is itself a sign of a hand-edited or restored row: treat it as
            // old enough to look at rather than as brand new.
            : BillingTime::nowUtc()->subDays(self::HARD_LIMIT_DAYS + 1);

        if ($createdAt->diffInHours(BillingTime::nowUtc()) < self::STALE_AFTER_HOURS) {
            return;
        }

        // The webhook may well have run and only the snapshot been left behind — in which
        // case there is nothing to poll and nothing to write off.
        if (BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists()) {
            $this->markSpent($billable, $paymentId, 'an invoice for this payment already exists');

            return;
        }

        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            // Mollie unreachable is not a verdict. Tomorrow's pass asks again.
            Log::warning('CleanupStalePendingOverageChargeJob: Mollie unreachable', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $status = (string) ($payment->status ?? '');

        if ($status === 'paid') {
            // Re-runs the delivery that was lost, through the controller so the invoice, the
            // wallet settlement and the events are exactly what the webhook would have
            // produced. Idempotent: the invoice guard short-circuits a duplicate.
            $this->replayPaidCharge($billable, $payment, $paymentId);

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            $this->writeOff($billable, $paymentId, "Mollie reports the charge as {$status}");

            return;
        }

        if ($createdAt->diffInDays(BillingTime::nowUtc()) >= self::HARD_LIMIT_DAYS) {
            $this->writeOff($billable, $paymentId, "the charge has been {$status} at Mollie for over ".self::HARD_LIMIT_DAYS.' days');
        }
    }

    private function replayPaidCharge(Billable $billable, object $payment, string $paymentId): void
    {
        $controller = app(MollieWebhookController::class);
        $method = new \ReflectionMethod($controller, 'route');
        $method->setAccessible(true);
        $method->invoke($controller, $payment, $billable);

        Log::info('CleanupStalePendingOverageChargeJob: replayed a lost overage webhook', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Mark the cycle spent without collecting: the debt stays on the wallet, but the next
     * period may charge for it again instead of being silenced.
     */
    private function writeOff(Billable $billable, string $paymentId, string $reason): void
    {
        $this->mutateBillingMeta($billable, fn (array $meta): array => [
            ...$meta,
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_FAILED,
        ]);

        Log::error('CleanupStalePendingOverageChargeJob: writing off a stale overage charge', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            'An overage charge never settled and has been written off — the wallet debt is still outstanding',
            [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'payment_id' => $paymentId,
                'error' => $reason,
            ],
        ));
    }

    /**
     * Clear a snapshot whose payment was in fact processed.
     *
     * The same keys `SingleChargeHandler` removes on its success path — including the
     * dunning budget, which is a list of payment ids and would otherwise carry this spent
     * cycle's entries into the next one.
     */
    private function markSpent(Billable $billable, string $paymentId, string $reason): void
    {
        $this->mutateBillingMeta($billable, function (array $meta): array {
            unset(
                $meta[ChargeUsageOverageDirectly::META_KEY],
                $meta['usage_overage_status'],
                $meta['usage_overage_attempts'],
                $meta[ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY],
            );

            return $meta;
        });

        Log::info('CleanupStalePendingOverageChargeJob: cleared a spent overage snapshot', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);
    }
}

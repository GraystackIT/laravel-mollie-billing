<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Daily cron: polls Mollie for stuck pending_prorata_change states older than 24h.
 *
 * If the charge webhook never arrives (Mollie outage or webhook misconfiguration),
 * the pending state lingers — and `ValidateSubscriptionChange` then refuses every
 * further plan change for that billable. This job resolves the stuck entry, always
 * asking Mollie what actually happened before acting on the entry's age:
 *
 * - `paid` → clear the pending state so the customer is unblocked, record the payment
 *   as an orphan under `orphaned_prorata_payment` and alert the admins. Phase 2 cannot
 *   be replayed from here: the only replay path is a testing helper that refuses to run
 *   in production, so the reconciliation is manual by design.
 * - `failed`/`canceled`/`expired` → clear the pending state and record the reason.
 * - still unsettled → keep waiting. SEPA direct debit legitimately takes several
 *   business days; past the hard limit this is logged as stuck rather than slow.
 * - Mollie unreachable past the hard limit → clear the pending state and alert, since
 *   the outcome cannot be established.
 *
 * Scheduled daily by the service provider.
 */
class CleanupStalePendingProrataChangeJob implements ShouldQueue
{
    use MutatesBillingMeta;

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * How long a pending prorata change may sit unresolved before the job stops
     * waiting. Only reached when Mollie cannot be asked, or when Mollie itself still
     * reports the payment as unsettled — a paid or failed payment is acted on
     * immediately, regardless of age.
     */
    private const HARD_LIMIT_DAYS = 7;

    public int $tries = 1;

    /**
     * One synchronous Mollie call per stuck entry, so the run scales with the backlog.
     * Capped like CleanupOrphanedBillablesJob: a hung HTTP call must not outlast the
     * queue's visibility window, or the re-delivery fails at once with
     * MaxAttemptsExceededException because `attempts() >= tries`.
     */
    public int $timeout = 600;

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

        $threshold = BillingTime::nowUtc()->subHours(24);

        // chunkById, not get(): loading every billable that carries a `subscription_meta`
        // into memory exhausted the worker on a large table, and with `tries = 1` the run
        // then died before resolving a single stuck change — leaving every affected
        // customer unable to change plan at all. chunkById and not chunk() because
        // processBillable() mutates the column the filter below reads.
        $billableClass::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
            ->whereNotNull('subscription_meta')
            ->chunkById(200, function ($billables) use ($threshold): void {
                foreach ($billables as $billable) {
                    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

                    if (empty($pending)) {
                        continue;
                    }

                    // A marker with no `created_at` is treated as older than the hard limit,
                    // which is what processBillable() already assumes and logs about.
                    // Defaulting to now() meant it was never `lessThan($threshold)`, so the
                    // recovery branch written for it could not be reached — and
                    // ValidateSubscriptionChange refuses every money-moving plan change
                    // while the marker is set, so the customer stayed locked out forever
                    // with no log line and no alert.
                    $createdAt = isset($pending['created_at'])
                        ? \Carbon\Carbon::parse((string) $pending['created_at'])->setTimezone('UTC')
                        : BillingTime::nowUtc()->subDays(self::HARD_LIMIT_DAYS + 1);

                    if (! $createdAt->lessThan($threshold)) {
                        continue;
                    }

                    $this->processBillable($billable);
                }
            });
    }

    private function processBillable($billable): void
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $pending = $meta['pending_prorata_change'] ?? null;
        if (empty($pending)) {
            return;
        }

        $paymentId = (string) ($pending['charge_payment_id'] ?? '');
        if ($paymentId === '') {
            // Pending without payment ID — old and useless, delete it.
            //
            // Key-scoped, not blob-scoped: everything below this line spans a synchronous
            // GetPaymentRequest, so writing back the snapshot read before it reverted whatever
            // another process committed in between. The costly one is `pending_refund_retries`
            // — this sweep's sibling is its only reader, so a line erased here is money that
            // never reaches the customer and never raises a dead letter, and a line resurrected
            // here is a second payout.
            $this->mutateBillingMeta($billable, function (array $current): array {
                unset($current['pending_prorata_change']);

                return $current;
            });

            return;
        }

        if (! isset($pending['created_at'])) {
            // InvoiceService always writes created_at, so its absence means corruption
            // or a manual edit. Treated as old enough to act on, but logged because it
            // points at a bug on the write side.
            Log::warning('CleanupStalePendingProrataChangeJob: pending_prorata_change without created_at — treating as older than the hard limit', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);
        }

        $createdAt = isset($pending['created_at'])
            ? \Carbon\Carbon::parse((string) $pending['created_at'])->setTimezone('UTC')
            : BillingTime::nowUtc()->subDays(self::HARD_LIMIT_DAYS + 1);

        $pastHardLimit = $createdAt->diffInDays(BillingTime::nowUtc()) >= self::HARD_LIMIT_DAYS;

        // Mollie is asked FIRST. Deleting on age alone discarded the pending state of a
        // payment that had in fact been collected: the plan change never happened, no
        // invoice existed, and the admin alert said only "deleted after 7 days without
        // webhook" — never that the money had arrived.
        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::warning('CleanupStalePendingProrataChangeJob: Mollie unreachable for payment '.$paymentId, ['error' => $e->getMessage()]);

            // Only give up once Mollie has been unreachable past the hard limit — that
            // is the case this limit exists for.
            if ($pastHardLimit) {
                $this->mutateBillingMeta($billable, function (array $current): array {
                    unset($current['pending_prorata_change']);

                    return $current;
                });

                $this->notifyAdmins(
                    'pending_prorata_change was dropped after '.self::HARD_LIMIT_DAYS.' days because Mollie stayed unreachable — the payment outcome is unknown and needs checking in the Mollie dashboard',
                    ['payment_id' => $paymentId, 'billable_id' => $billable->getKey()],
                );
            }

            return;
        }

        $status = (string) ($payment->status ?? '');

        if ($status === 'paid') {
            // The charge was collected but its webhook never arrived, so Phase 2 never
            // ran. Replaying the webhook from here is not possible in production (the
            // only replay path is a testing helper), so the pending state is cleared to
            // unblock the customer's future plan changes and the payment is recorded as
            // an orphan for manual reconciliation — the same marker
            // ProrataChargeHandler uses for a payment that outlived its plan change.
            $orphan = [
                'payment_id' => $paymentId,
                'reason' => 'charge paid but its webhook never arrived',
                'cancelled_at' => BillingTime::nowUtc()->toIso8601String(),
                'pending_plan_change' => $meta['pending_plan_change'] ?? null,
                'prorata_charge_lines' => $pending['charge_lines'] ?? null,
            ];

            $this->mutateBillingMeta($billable, function (array $current) use ($orphan): array {
                unset($current['pending_prorata_change']);
                $current['orphaned_prorata_payment'] = $orphan;

                return $current;
            });

            $meta['orphaned_prorata_payment'] = $orphan;

            Log::error('CleanupStalePendingProrataChangeJob: prorata charge was paid but no webhook ever arrived', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);

            $this->notifyAdmins(
                'A prorata charge was paid but its webhook never arrived, so the plan change was never applied and no invoice exists — reconcile this payment manually',
                [
                    'payment_id' => $paymentId,
                    'billable_id' => $billable->getKey(),
                    'intended_plan_change' => $meta['orphaned_prorata_payment']['pending_plan_change'],
                    'charge_lines' => $meta['orphaned_prorata_payment']['prorata_charge_lines'],
                ],
            );

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            $this->mutateBillingMeta($billable, function (array $current) use ($status): array {
                unset($current['pending_prorata_change']);
                $current['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                $current['plan_change_failed_reason'] = "Charge {$status}";

                return $current;
            });
            return;
        }

        // Still open/pending/authorized. SEPA direct debit legitimately sits here for
        // several business days, so this is not an error — but past the hard limit it is
        // stuck rather than slow, and an operator should look at it.
        if ($pastHardLimit) {
            Log::warning('CleanupStalePendingProrataChangeJob: prorata charge still not settled past the hard limit', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'status' => $status,
                'created_at' => $createdAt->toIso8601String(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notifyAdmins(string $reason, array $context): void
    {
        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            $reason,
            $context,
        ));
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Events\OverageChargeFailed;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\UsageOverageChargeOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Re-attempts an overage charge whose payment failed or whose creation call failed.
 *
 * `usage_overage_attempts` counts payments that did NOT settle — reaching
 * MAX_PAYMENT_ATTEMPTS ends the cycle: the billable is past_due, the customer is told, and
 * the debt is left standing for the next renewals (see {@see giveUp()}). Failures
 * to even create the payment are a different thing: they never touch the customer's
 * payment method, so they only consume the queue's `tries` budget and never dun.
 *
 * "Counts payments" literally: each unsettled payment id is counted once, tracked in
 * `usage_overage_counted_payments`. ChargeUsageOverageDirectly::persistPendingCharge()
 * keeps the old id when its Mollie call fails, so every queue attempt re-entered with
 * the same unsettled payment and incremented again — one genuinely failed payment plus
 * two transient Mollie 5xx errors spent the whole budget in ~6 minutes and dunned a
 * payment method that had been touched exactly once.
 */
class RetryUsageOverageChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /** Terminal marker of one cycle: its dunning budget is spent. */
    /** @deprecated Use ChargeUsageOverageDirectly::STATUS_FAILED — kept for app listeners. */
    public const OVERAGE_FAILED = ChargeUsageOverageDirectly::STATUS_FAILED;

    /** Payment ids already charged against the dunning budget. */
    private const COUNTED_PAYMENTS_KEY = ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY;

    private const MAX_PAYMENT_ATTEMPTS = 3;

    /** Create-payment failures in a row on a `sync` queue, where no worker counts them. */
    public const INLINE_FAILURES_KEY = 'usage_overage_inline_failures';

    public int $uniqueFor = 3600;

    public int $tries = 3;

    /**
     * Bounded because this job creates real auto-collected payments.
     *
     * `ShouldBeUnique` guards dispatches, not re-deliveries: with no timeout, a hung
     * `CreatePaymentRequest` (the SDK's own default is very generous) outlived the
     * queue's visibility window, the job was re-delivered to a second worker, and
     * `pendingChargeIsSettled()` was still false — so both workers created a recurring
     * payment against the mandate and both collected. Each `paid` webhook then passed
     * the invoice guard on its own payment id, so two invoices were written and
     * `settleOverageWallets()` credited the quantity twice. Every sibling job that walks
     * tables got an explicit timeout for exactly this physics; the most
     * money-dangerous one had none.
     *
     * Must stay below the queue connection's `retry_after`.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public int $attempt = 1,
    ) {
        $this->initializeBillingQueue();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Belt to the timeout's braces: a re-delivery that arrives anyway waits for the
        // in-flight run instead of racing it to Mollie. Mirrors RetryRefundLineJob,
        // which protects the same class of hazard on the refund side.
        // `dontRelease()` rather than `releaseAfter()`: every release consumes one of the three
        // attempts, so a job blocked for 90 seconds burned its whole budget without ever
        // reaching Mollie and landed in `failed()` — an admin dead-letter mail for a charge that
        // was never attempted. Dropping the blocked delivery is safe here because the run
        // holding the lock is charging the same debt for the same billable; if it fails, its own
        // retry ladder continues, and pass 3 re-dispatches nightly regardless.
        return [
            (new WithoutOverlapping($this->billableClass.':'.$this->billableId.':overage'))
                ->dontRelease()
                ->expireAfter(600),
        ];
    }

    public function uniqueId(): string
    {
        return $this->billableClass.':'.$this->billableId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ChargeUsageOverageDirectly $service): void
    {
        if (! class_exists($this->billableClass)) {
            return;
        }

        $billable = $this->billableClass::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)->find($this->billableId);

        if ($billable === null) {
            return;
        }

        // An overage payment that settled clears the whole snapshot in
        // SingleChargeHandler::paid(). A snapshot that still carries a payment id for
        // which no invoice exists therefore describes a payment that did NOT settle —
        // that is the outcome the dunning budget is about. Counting API acceptances
        // instead (and clearing the counter on each one) made the chain unbounded: the
        // failure webhook dispatched a fresh retry, the retry created another payment,
        // the counter went back to zero, and `past_due` was never reached. Each such
        // payment counts exactly once — countUnsettledPayment() explains why that has to
        // be tracked by id rather than by queue attempt.
        $pending = (array) ($service->pendingCharge($billable) ?? []);
        $previousPaymentId = (string) ($pending['payment_id'] ?? '');

        if ($previousPaymentId !== '' && ! $service->pendingChargeIsSettled($billable)) {
            $attempts = $this->countUnsettledPayment(
                $billable,
                (string) ($pending['created_at'] ?? ''),
                $previousPaymentId,
            );

            // Evaluated even when this attempt did not count — a re-entry once the
            // budget is spent must dun rather than fall through to another charge.
            if ($attempts >= self::MAX_PAYMENT_ATTEMPTS) {
                $this->giveUp($billable, $billable->getBillingSubscriptionMeta(), new RuntimeException(sprintf(
                    'Overage payment [%s] did not settle after %d attempts.',
                    $previousPaymentId,
                    $attempts,
                )));

                return;
            }
        }

        try {
            // retry(), not handle(): the snapshot is the source of truth. Rebuilding
            // from wallet balances cannot work once they have been rebalanced, and
            // retry() reports AlreadySettled instead of charging twice.
            $result = $service->retry($billable);
        } catch (Throwable $e) {
            // An API-level error means nothing was charged, so it must not consume the
            // dunning budget — marking a customer past_due over three transient network
            // errors dunned a card that was never touched. The queue's own backoff and
            // tomorrow's PrepareUsageOverageJob pass own the retry.
            $inline = \GraystackIT\MollieBilling\Support\BillingQueue::isSyncRun($this->job);
            $budget = $this->tries;

            $this->mutateBillingMeta($billable, static function (array $meta) use ($inline, $budget): array {
                $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;

                // On `sync` there is no queue ladder: count the failed attempts here, so
                // the operator is told after as many of them as a worker would make.
                if ($inline) {
                    $failures = (int) ($meta[self::INLINE_FAILURES_KEY] ?? 0) + 1;
                    $meta[self::INLINE_FAILURES_KEY] = $failures >= $budget ? 0 : $failures;
                }

                return $meta;
            });

            event(new OverageChargeFailed(
                $billable,
                (int) ($billable->getBillingSubscriptionMeta()['usage_overage_attempts'] ?? 0),
                $e,
            ));

            // On `sync` a throw is final: `failed()` would mail the admins on the first
            // Mollie error, with no retry at all. Until the budget of failed attempts is
            // spent the snapshot stays `pending` instead, and the nightly
            // PrepareUsageOverageJob pass dispatches the retry again — once a night where
            // a worker would have tried three times within the quarter hour.
            if ($inline && (int) ($billable->refresh()->getBillingSubscriptionMeta()[self::INLINE_FAILURES_KEY] ?? 0) > 0) {
                Log::warning('RetryUsageOverageChargeJob: overage charge could not be created — left for the nightly pass (sync queue)', [
                    'billable_id' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }

        $this->mutateBillingMeta($billable, static function (array $meta): ?array {
            if (! array_key_exists(self::INLINE_FAILURES_KEY, $meta)) {
                return null;
            }

            unset($meta[self::INLINE_FAILURES_KEY]);

            return $meta;
        });

        // Nothing owed, or the previous payment turned out to be settled after all:
        // no charge is outstanding, so the markers go away.
        if ($result->outcome !== UsageOverageChargeOutcome::Charged) {
            $this->mutateBillingMeta($billable, static function (array $meta): array {
                unset(
                    $meta['usage_overage'],
                    $meta['usage_overage_status'],
                    $meta['usage_overage_attempts'],
                    $meta[self::COUNTED_PAYMENTS_KEY],
                    $meta[ChargeUsageOverageDirectly::RECOLLECTIONS_KEY],
                );

                return $meta;
            });

            // No debt, no lock.
            $service->releasePastDueForDebt($billable);
        }

        // Charged: a payment exists and the webhook owns its outcome. The attempt
        // counter is deliberately left in place.
    }

    /**
     * Reached when `tries` runs out — three create-payment failures in a row with the
     * snapshot's `payment_id` still empty. Nothing was collected and nothing was dunned,
     * so the status stays `pending` for tomorrow's PrepareUsageOverageJob pass to pick
     * up; without this the job just landed in failed_jobs and the negative wallet balance
     * went unbilled with nobody told.
     */
    public function failed(?Throwable $exception): void
    {
        if (! class_exists($this->billableClass)) {
            return;
        }

        $billable = $this->billableClass::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
            ->find($this->billableId);

        if (! $billable instanceof Model) {
            return;
        }

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminOverageBillingFailedNotification::class,
                $billable,
                $exception ?? new RuntimeException('Overage charge retries exhausted without reaching Mollie.'),
            ),
        );
    }

    /**
     * Charge one unsettled payment against the dunning budget, at most once, and
     * return the budget consumed so far.
     *
     * `usage_overage_attempts` is kept in sync because that is the number the events
     * and the admin views read, but the id list is what decides: the service clears the
     * plain counter every time it starts a new payment, so deriving the budget from the
     * counted ids is what keeps it attached to distinct failed collections.
     *
     * The list is scoped to the snapshot it belongs to (`usage_overage.created_at`,
     * which persistPendingCharge() carries across attempts and SingleChargeHandler drops
     * together with the snapshot on `paid`). Without that scope a spent budget would
     * outlive its dunning cycle and the next period's very first failure would dun
     * immediately.
     */
    private function countUnsettledPayment(Model $billable, string $cycle, string $paymentId): int
    {
        $counted = $this->countedPayments($billable->getBillingSubscriptionMeta(), $cycle);

        if (in_array($paymentId, $counted, true)) {
            return count($counted);
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($cycle, $paymentId): ?array {
            $counted = $this->countedPayments($meta, $cycle);

            if (in_array($paymentId, $counted, true)) {
                return null;
            }

            $counted[] = $paymentId;
            $meta[self::COUNTED_PAYMENTS_KEY] = ['cycle' => $cycle, 'payment_ids' => $counted];
            $meta['usage_overage_attempts'] = count($counted);

            return $meta;
        });

        return count($this->countedPayments($billable->getBillingSubscriptionMeta(), $cycle));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function countedPayments(array $meta, string $cycle): array
    {
        $stored = (array) ($meta[self::COUNTED_PAYMENTS_KEY] ?? []);

        if ((string) ($stored['cycle'] ?? '') !== $cycle) {
            return [];
        }

        return array_values(array_filter(
            (array) ($stored['payment_ids'] ?? []),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));
    }

    /**
     * The dunning budget of this cycle is spent. What becomes of the debt is decided by
     * {@see ChargeUsageOverageDirectly::deferFailedCycle()}:
     *
     *  - while renewals still have attempts left it **stands**, and the next renewal
     *    collects it again on its own. The customer is told (and asked to look at their
     *    payment method); nobody in operations is, because there is nothing for them to do;
     *  - once those attempts are spent as well it is written off, and that is the one
     *    moment an operator hears about it.
     *
     * While the debt stands the subscription is **past due** — the maintainer's rule: a
     * customer who owes money that most likely cannot be collected any more books
     * nothing new (`PastDueExpansionNotAllowedException`). `deferFailedCycle()` sets that
     * state and takes it away again; it records it under its own key and never starts
     * the `past_due_since` clock, because that one ends in a cancellation after
     * `past_due_max_days` — long before a later renewal could collect, and for a
     * customer whose renewals are all paid.
     *
     * @param  array<string, mixed>  $meta
     */
    private function giveUp(Model $billable, array $meta, Throwable $reason): void
    {
        /** @var Model&\GraystackIT\MollieBilling\Contracts\Billable $billable */
        // Built first: both mails read the amount off the snapshot, and a write-off
        // removes it.
        $customerMail = MollieBilling::resolveNotification(OverageBillingFailedNotification::class, $billable);
        $adminMail = MollieBilling::resolveNotification(AdminOverageBillingFailedNotification::class, $billable, $reason);

        $outcome = app(ChargeUsageOverageDirectly::class)->deferFailedCycle($billable);

        event(new OverageChargeFailed($billable, (int) ($meta['usage_overage_attempts'] ?? 0), $reason));

        if (property_exists($customerMail, 'willRetry')) {
            $customerMail->willRetry = $outcome === 'deferred';
        }

        $billingAdmins = MollieBilling::notifyBillingAdmins($billable);
        $billingAdmins = is_array($billingAdmins) ? $billingAdmins : iterator_to_array($billingAdmins);
        if ($billingAdmins !== []) {
            Notification::send($billingAdmins, $customerMail);
        }

        if ($outcome !== 'written_off') {
            return;
        }

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send($admins, $adminMail);
        }
    }
}

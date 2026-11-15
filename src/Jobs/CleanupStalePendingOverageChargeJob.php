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
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\MollieCall;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;

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
     * How long a charge whose create-payment answer was *lost* may wait before it is
     * reconciled — much shorter, because nothing is being waited for.
     *
     * The 72 hours above buy patience for a delivery that is still coming: Mollie retries its
     * webhook for hours and a SEPA debit takes days. A snapshot marked
     * {@see ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY} has no delivery to wait for — the
     * question "does this payment exist?" is answerable the moment we can reach Mollie, and
     * until it is answered nobody may charge this billable's overage at all. Waiting three
     * days would therefore hold the debt *and* every later overage for it hostage to patience
     * that buys nothing.
     *
     * One hour, so the two things that legitimately run first are done: Mollie's idempotency
     * window (a key is cached for an hour) and `RetryUsageOverageChargeJob`'s ladder
     * (`[60, 300, 900]`, ~21 minutes). Either of those may still turn the unknown into a known
     * payment id on its own, which is the cheaper resolution.
     */
    public const RECONCILE_UNKNOWN_AFTER_HOURS = 1;

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

        if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
            return;
        }

        $createdAt = isset($snapshot['created_at'])
            ? \Carbon\Carbon::parse((string) $snapshot['created_at'])->setTimezone('UTC')
            // No timestamp is itself a sign of a hand-edited or restored row: treat it as
            // old enough to look at rather than as brand new.
            : BillingTime::nowUtc()->subDays(self::HARD_LIMIT_DAYS + 1);

        // A lost answer is reconciled on its own, much shorter clock: there is no delivery to
        // be patient for, and the debt plus every later overage for this billable is frozen
        // until the question is settled. See RECONCILE_UNKNOWN_AFTER_HOURS.
        $unknownPayment = $paymentId === '' && $this->overageService()->paymentMayExist($billable);

        $waitHours = $unknownPayment ? self::RECONCILE_UNKNOWN_AFTER_HOURS : self::STALE_AFTER_HOURS;

        if ($createdAt->diffInHours(BillingTime::nowUtc()) < $waitHours) {
            return;
        }

        // Pending with no payment id at all: `CreatePaymentRequest` itself kept failing, which
        // is what Mollie answers once the mandate behind a recurring payment is gone (revoked
        // SEPA mandate, expired card). That state had no terminal path anywhere — the dunning
        // budget is only charged when a previous payment id exists, so `giveUp()` never ran and
        // the billable never reached PastDue; this job returned immediately, so the write-off
        // was unreachable; and `safeCharge()` skips a billable whose status is still `pending`,
        // so *every later period's overage was silenced too*. The debt was carried forward by
        // `preserveDebt`, shrinking the customer's usable quota each period while they were
        // billed nothing and kept full access, and pass 3 re-dispatched the retry ladder daily
        // — three failed Mollie calls and one admin mail per day, indefinitely.
        //
        // There is nothing to poll here, so the hard limit is the only sensible gate: past it,
        // write the debt off, which alerts an operator and frees the next cycle to charge again.
        if ($paymentId === '') {
            // …unless the request *did* leave this process and only its answer was lost. Then
            // the debt may already be being collected, and "no id" must not be read as "no
            // payment": that is what let the next sweep charge it a second time. Mollie's own
            // payment list is the only place that knows, so this is the one branch that asks.
            if ($unknownPayment) {
                $this->reconcileUnknownPayment($billable, $snapshot, $createdAt);

                return;
            }

            if ($createdAt->diffInDays(BillingTime::nowUtc()) >= self::HARD_LIMIT_DAYS) {
                $this->writeOff($billable, '', 'no payment could be created for over '.self::HARD_LIMIT_DAYS.' days — the mandate is most likely gone');
            }

            return;
        }

        // The webhook may well have run and only the snapshot been left behind — in which
        // case there is nothing to poll and nothing to write off.
        if (BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists()) {
            $this->markSpent($billable, $paymentId, 'an invoice for this payment already exists');

            return;
        }

        try {
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));
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

        // The age of THIS payment, not of the snapshot. `created_at` on the snapshot is the
        // dunning cycle's start: it is inherited from payment to payment so the budget stays
        // attached. With SEPA every failure takes days to arrive, so the third payment of a
        // cycle is routinely created when the cycle is already twelve or thirteen days old —
        // and was written off two days later as "pending for over 14 days". A write-off
        // frees the slot, the next sweep charges the same units again, and then the payment
        // that was written off settles after all: the customer has paid twice.
        $paymentCreatedAt = $this->paymentCreatedAt($payment) ?? $createdAt;

        if ($paymentCreatedAt->diffInDays(BillingTime::nowUtc()) >= self::HARD_LIMIT_DAYS) {
            $this->writeOff($billable, $paymentId, "the charge has been {$status} at Mollie for over ".self::HARD_LIMIT_DAYS.' days');
        }
    }

    /**
     * When Mollie created the payment, or null when the answer does not say.
     */
    private function paymentCreatedAt(object $payment): ?CarbonInterface
    {
        $createdAt = (string) ($payment->createdAt ?? '');

        if ($createdAt === '') {
            return null;
        }

        try {
            return BillingTime::toUtc($createdAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function overageService(): ChargeUsageOverageDirectly
    {
        return app(ChargeUsageOverageDirectly::class);
    }

    /**
     * Ask Mollie whether the payment we lost the answer to exists, and act on the answer.
     *
     * The snapshot says a `CreatePaymentRequest` left this process without a reply, so locally
     * "was this debt charged?" is unanswerable — and both wrong answers cost money. Reading it
     * as *charged* silences the debt forever; reading it as *not charged* charges the customer
     * twice, because the payment settles into its own invoice under the webhook's per-payment
     * idempotency and, for a plan-change line, the wallet is never credited back
     * (`settles_wallet: false`). Mollie's payment list for the customer is the only place that
     * holds the answer, which is why this branch spends an API call on it.
     *
     * Three outcomes, and the third one matters as much as the others:
     *
     *  - the payment exists → adopt its id, and the ordinary flow takes it from there (poll the
     *    status, replay a lost `paid` delivery, write off a `failed` one);
     *  - Mollie has no such payment → the request never arrived, so the marker is dropped and
     *    the debt becomes an ordinary un-charged one again, retryable by the next cycle;
     *  - Mollie cannot be reached → nothing is decided. Tomorrow's run asks again. An
     *    unanswered question is not evidence of absence.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function reconcileUnknownPayment(Billable $billable, array $snapshot, CarbonInterface $chargedAt): void
    {
        $customerId = (string) ($billable->getMollieCustomerId() ?? '');

        if ($customerId === '') {
            // Nothing to ask with. A recurring payment always had a customer, so an empty id
            // means the customer was deleted at Mollie — and with it any payment it held. The
            // hard-limit write-off below is then the honest terminal state.
            Log::warning('CleanupStalePendingOverageChargeJob: cannot reconcile a lost overage payment without a Mollie customer', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            ]);

            $this->overageService()->forgetUnknownPayment($billable);

            return;
        }

        try {
            $payments = MollieCall::send(new GetPaginatedCustomerPaymentsRequest($customerId, limit: 50));
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            // 404 and 410 are *answers*, not failures — the same reading
            // `CleanupOrphanedBillablesJob` applies: Mollie says 404 for a customer id it does
            // not know and **410 Gone** once the customer has been deleted. Either way there is
            // no customer, so there is no payment to protect, and leaving the marker in place
            // would silence this debt and every later overage for this billable forever.
            if (in_array($e->getCode(), [404, 410], true)) {
                Log::info('CleanupStalePendingOverageChargeJob: the Mollie customer is gone, so the lost payment cannot exist', [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'status' => $e->getCode(),
                ]);

                $this->overageService()->forgetUnknownPayment($billable);

                return;
            }

            Log::warning('CleanupStalePendingOverageChargeJob: Mollie unreachable while reconciling a lost overage payment', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (\Throwable $e) {
            Log::warning('CleanupStalePendingOverageChargeJob: Mollie unreachable while reconciling a lost overage payment', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $match = $this->matchLostPayment($billable, $payments, $snapshot, $chargedAt);

        if ($match === null) {
            Log::warning('CleanupStalePendingOverageChargeJob: Mollie holds no payment for this overage charge — treating it as never created', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'charged_at' => $chargedAt->toIso8601String(),
            ]);

            $this->overageService()->forgetUnknownPayment($billable);

            return;
        }

        $paymentId = (string) ($match->id ?? '');

        Log::warning('CleanupStalePendingOverageChargeJob: adopted the overage payment whose response was lost', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
        ]);

        $this->overageService()->adoptUnknownPayment($billable, $paymentId);

        // Straight on with the ordinary flow — the snapshot now carries an id, so this second
        // pass takes the polling branch. It cannot come back here: `paymentMayExist()` is false
        // once the marker is gone.
        $this->processBillable($billable);
    }

    /**
     * The payment in Mollie's list that belongs to this snapshot, if any.
     *
     * Deliberately strict on all four counts. The metadata says it is one of ours and whose;
     * the amount says it is *this* debt rather than another charge for the same customer; and
     * the timestamp keeps a previous cycle's payment of a coincidentally equal amount from
     * being adopted into this one, which would silence a debt that is genuinely still owed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function matchLostPayment(Billable $billable, mixed $payments, array $snapshot, CarbonInterface $chargedAt): ?object
    {
        $expectedAmount = BillingMoney::mollieAmount(
            (int) ($snapshot['amount_gross'] ?? 0),
            (string) ($snapshot['currency'] ?? config('mollie-billing.currency', 'EUR')),
        );

        // A payment created just before the snapshot was written is still ours: the two happen
        // in the same call, and only the clocks differ.
        $notBefore = $chargedAt->copy()->subMinutes(5);

        foreach ($this->iterablePayments($payments) as $payment) {
            $metadata = (array) json_decode((string) json_encode($payment->metadata ?? []), true);

            if (($metadata['type'] ?? null) !== 'overage') {
                continue;
            }

            if ((string) ($metadata['billable_id'] ?? '') !== (string) $billable->getKey()) {
                continue;
            }

            if ((string) ($payment->amount->value ?? '') !== $expectedAmount) {
                continue;
            }

            $createdAt = (string) ($payment->createdAt ?? '');

            if ($createdAt !== '' && BillingTime::toUtc($createdAt)->lessThan($notBefore)) {
                continue;
            }

            return $payment;
        }

        return null;
    }

    /**
     * @return iterable<int, object>
     */
    private function iterablePayments(mixed $payments): iterable
    {
        if (is_iterable($payments)) {
            return $payments;
        }

        // A paginated collection that only exposes its items through a property, depending on
        // the SDK's response shape.
        foreach (['data', 'payments'] as $property) {
            $items = $payments->{$property} ?? null;

            if (is_iterable($items)) {
                return $items;
            }
        }

        return [];
    }

    private function replayPaidCharge(Billable $billable, object $payment, string $paymentId): void
    {
        app(MollieWebhookController::class)->replayDelivery($payment, $billable);

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

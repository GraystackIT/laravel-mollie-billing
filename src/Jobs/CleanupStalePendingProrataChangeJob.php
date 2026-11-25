<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

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
 * Two further loose ends are tied up on the same pass, for billables with or without a
 * pending change:
 *
 * - An `orphaned_prorata_payment` recorded with reason `lookup_failed` — a withdrawn
 *   change whose payment could not be cancelled because Mollie could not be asked. Asked
 *   again here: cancelled if Mollie still allows it, forgotten if it is dead, marked
 *   `not_cancelable` if it is under way. A payment that settles is returned by the webhook
 *   in any case; cancelling it spares the customer the debit and the refund.
 * - A `prorata_charge_in_creation` note older than CHARGE_IN_CREATION_LIMIT_HOURS: a
 *   payment whose creation went unanswered and that no attempt has taken over since. The
 *   payment is cancelled where possible and the note dropped, so a late settlement is
 *   returned rather than carrying out a change nobody has asked for again in two days.
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

    /**
     * How long a paid charge whose webhook run died part-way is left for Mollie's
     * re-delivery to resume, counted from the run's start. Mollie retries a failed webhook
     * for about a day; past this the run is reported and the change dropped.
     */
    public const RESUME_WINDOW_HOURS = 48;

    /** Past one nightly retry of a scheduled change, with a day to spare. */
    public const CHARGE_IN_CREATION_LIMIT_HOURS = 48;

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
                    $this->retryCancelOfWithdrawnPayment($billable);
                    $this->expireChargeInCreation($billable);

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

    /**
     * Ask Mollie again about the payment of a withdrawn change it could not be asked about.
     */
    private function retryCancelOfWithdrawnPayment($billable): void
    {
        $orphan = $billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null;

        if (! is_array($orphan) || ($orphan['reason'] ?? null) !== 'lookup_failed' || empty($orphan['payment_id'])) {
            return;
        }

        $paymentId = (string) $orphan['payment_id'];

        try {
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));
            $status = (string) ($payment->status ?? '');
            $cancelled = false;

            if (! in_array($status, ['paid', 'authorized', 'failed', 'canceled', 'expired'], true) && ($payment->isCancelable ?? false) === true) {
                MollieCall::send(new \Mollie\Api\Http\Requests\CancelPaymentRequest($paymentId));
                $cancelled = true;
            }
        } catch (\Throwable $e) {
            Log::warning('CleanupStalePendingProrataChangeJob: Mollie still unreachable for the payment of a withdrawn change', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId, $status, $cancelled): ?array {
            if ((string) ($meta['orphaned_prorata_payment']['payment_id'] ?? '') !== $paymentId) {
                return null;
            }

            if ($cancelled || in_array($status, ['failed', 'canceled', 'expired'], true)) {
                // Nothing will arrive.
                unset($meta['orphaned_prorata_payment']);
            } elseif (! in_array($status, ['paid', 'authorized'], true)) {
                // Under way and past stopping: it is returned when it settles.
                $meta['orphaned_prorata_payment']['reason'] = 'not_cancelable';
            } else {
                $meta['orphaned_prorata_payment']['reason'] = 'already_settled';
            }

            return $meta;
        });
    }

    /**
     * Drop a note of a payment whose creation went unanswered and that nothing has taken
     * over within CHARGE_IN_CREATION_LIMIT_HOURS — cancelling the payment where Mollie
     * still allows it.
     */
    private function expireChargeInCreation($billable): void
    {
        $key = \GraystackIT\MollieBilling\Services\Billing\InvoiceService::CHARGE_IN_CREATION_KEY;
        $marker = $billable->getBillingSubscriptionMeta()[$key] ?? null;

        if (! is_array($marker)) {
            return;
        }

        $createdAt = isset($marker['created_at']) ? BillingTime::toUtc((string) $marker['created_at']) : null;

        if ($createdAt !== null && $createdAt->greaterThan(BillingTime::nowUtc()->subHours(self::CHARGE_IN_CREATION_LIMIT_HOURS))) {
            return;
        }

        $invoices = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class);

        try {
            $payment = $invoices->resolveChargeInCreation($billable, $marker);

            if ($payment !== null && ($payment->isCancelable ?? false) === true) {
                MollieCall::send(new \Mollie\Api\Http\Requests\CancelPaymentRequest((string) $payment->id));
            }
        } catch (\Throwable $e) {
            // Not a reason to keep the note: with nothing claiming it, a payment that
            // settles is returned by the webhook.
            Log::warning('CleanupStalePendingProrataChangeJob: could not ask Mollie about an unanswered prorata payment — dropping its note, it is returned if it settles', [
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $invoices->forgetChargeInCreation($billable, (string) ($marker['token'] ?? ''));
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
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::warning('CleanupStalePendingProrataChangeJob: Mollie unreachable for payment '.$paymentId, ['error' => $e->getMessage()]);

            // Only give up once Mollie has been unreachable past the hard limit — that
            // is the case this limit exists for.
            if ($pastHardLimit) {
                // Whether Mollie answers right now must not decide what the paid branch
                // below decides differently: a run the webhook may still finish is left to it.
                if ($this->leftForWebhookRun($billable, $paymentId, $pending)) {
                    return;
                }

                $dropped = false;
                $this->mutateBillingMeta($billable, function (array $current) use ($paymentId, &$dropped): ?array {
                    if (self::runMayStillFinish($current['pending_prorata_change'] ?? null, $paymentId)) {
                        return null;
                    }

                    $dropped = true;

                    return self::withoutPendingChange($current, $paymentId, 'Payment outcome unknown — Mollie unreachable');
                });

                if (! $dropped) {
                    return;
                }

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
            //
            // Or the webhook did arrive and its run died part-way, and the run could not be
            // resumed (ProrataChargeHandler::reportInterruptedRun()). Then the payment is
            // booked — the invoice exists — and the operators have already been told; the
            // alert said "no invoice exists" and came a second time.
            $invoiced = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists();
            $progress = is_array($pending['progress'] ?? null) ? $pending['progress'] : [];
            $alreadyReported = ! empty($progress['interrupted_reported_at']);

            if ($this->leftForWebhookRun($billable, $paymentId, $pending)) {
                return;
            }

            $orphan = [
                'payment_id' => $paymentId,
                'reason' => $invoiced
                    ? 'charge paid and booked, but its plan change was never completed'
                    : 'charge paid but its webhook never arrived',
                'cancelled_at' => BillingTime::nowUtc()->toIso8601String(),
                // A run that got past the switch has removed the top-level key; it noted the
                // change in its progress before the invoice.
                'pending_plan_change' => $meta['pending_plan_change']
                    ?? (is_array($progress['pending_plan_change'] ?? null) ? $progress['pending_plan_change'] : null),
                'prorata_charge_lines' => $pending['charge_lines'] ?? null,
            ];

            // Asked again under the row lock: a webhook run that started after this job read
            // the meta — its context is written before its invoice — must not lose its change.
            $dropped = false;
            $this->mutateBillingMeta($billable, function (array $current) use ($orphan, $paymentId, &$dropped): ?array {
                if (self::runMayStillFinish($current['pending_prorata_change'] ?? null, $paymentId)) {
                    return null;
                }

                $dropped = true;
                $current = self::withoutPendingChange($current, $paymentId, 'Payment received, change not completed');
                $current['orphaned_prorata_payment'] = $orphan;

                return $current;
            });

            if (! $dropped) {
                return;
            }

            $meta['orphaned_prorata_payment'] = $orphan;

            Log::error($invoiced
                ? 'CleanupStalePendingProrataChangeJob: prorata charge was paid and booked, but its plan change was never completed'
                : 'CleanupStalePendingProrataChangeJob: prorata charge was paid but no webhook ever arrived', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'already_reported' => $alreadyReported,
                ]);

            if ($alreadyReported) {
                return;
            }

            $this->notifyAdmins(
                $invoiced
                    ? 'A prorata charge was paid and booked (its invoice exists), but the plan change was never completed — check the completed steps, then finish the change or refund the payment manually'
                    : 'A prorata charge was paid but its webhook never arrived, so the plan change was never applied and no invoice exists — reconcile this payment manually',
                [
                    'payment_id' => $paymentId,
                    'billable_id' => $billable->getKey(),
                    'invoice_exists' => $invoiced,
                    'completed_steps' => array_values((array) ($progress['done'] ?? [])),
                    'intended_plan_change' => $meta['orphaned_prorata_payment']['pending_plan_change'],
                    'charge_lines' => $meta['orphaned_prorata_payment']['prorata_charge_lines'],
                ],
            );

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            // Through the handler the webhook would have used, not a private copy of half
            // of it. A seat or addon change is applied locally — and PATCHed at Mollie —
            // before its prorata charge clears, so "the charge failed" has three things to
            // undo: the pending marker, the seats/addons, and Mollie's recurring amount.
            // This branch only did the first. The customer kept capacity they had not
            // paid for and was billed for it from the next renewal on, with nothing but a
            // `plan_change_failed_at` stamp to say the charge that was supposed to start
            // it never happened. It also tells the billing admins, which the webhook path
            // always did and this one never had.
            app(\GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler::class)->failed(
                (object) [
                    'id' => $paymentId,
                    'status' => $status,
                    'details' => is_object($payment) ? ($payment->details ?? null) : null,
                ],
                $billable,
                ['type' => 'prorata_charge'],
            );

            // The reason this job has always recorded, for whoever reads the marker: the
            // handler stores Mollie's bare status when it has no failure detail.
            $this->mutateBillingMeta($billable, function (array $current) use ($status): ?array {
                if (($current['plan_change_failed_reason'] ?? null) !== $status) {
                    return null;
                }

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
     * Drop the waiting change. All of it: `pending_plan_change` and
     * `prorata_pending_payment_id` alone keep ValidateSubscriptionChange refusing every
     * further change, and the portal announcing one that waits for a payment. Dropping
     * only `pending_prorata_change` left the customer blocked for good — the opposite of
     * what this job is for. The two are left alone when they name another payment.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withoutPendingChange(array $meta, string $paymentId, string $failedReason): array
    {
        // Only this payment's: a change withdrawn while this job ran may have made room for
        // another one, and that one's markers name its own payment.
        $owner = (string) ($meta['pending_prorata_change']['charge_payment_id'] ?? '');

        if ($owner === '' || $owner === $paymentId) {
            unset($meta['pending_prorata_change']);
        }

        $marker = (string) ($meta['prorata_pending_payment_id'] ?? '');

        if (($marker === '' || $marker === $paymentId) && isset($meta['pending_plan_change'])) {
            unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);

            // A plan change that never switched is a failed one to the plan page, which
            // otherwise reads "pending → gone" as the webhook having applied it and
            // congratulates the customer on a change they did not get.
            $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
            $meta['plan_change_failed_reason'] = $failedReason;
        } elseif ($marker === '' || $marker === $paymentId) {
            unset($meta['prorata_pending_payment_id']);
        }

        return $meta;
    }

    /**
     * Whether the webhook may still finish this payment's run — leave it alone if so.
     *
     * A run records its context (`anchor`, `started_at`) before its invoice and every step
     * after it; the next delivery resumes it, and Mollie re-delivers a webhook that failed
     * for about a day. `created_at` is no measure of that: with SEPA the change is days old
     * when its webhook first arrives, and dropping it then made the re-delivery skip the
     * payment — the paid change lost after all. Not once the run has been reported as one
     * that will not be resumed, and not past RESUME_WINDOW_HOURS from its start.
     */
    private static function runMayStillFinish(mixed $pending, string $paymentId): bool
    {
        if (! is_array($pending) || (string) ($pending['charge_payment_id'] ?? '') !== $paymentId) {
            return false;
        }

        $progress = is_array($pending['progress'] ?? null) ? $pending['progress'] : [];

        if (! array_key_exists('anchor', $progress) || ! empty($progress['interrupted_reported_at'])
            || ! is_string($progress['started_at'] ?? null)
        ) {
            return false;
        }

        try {
            $startedAt = \Carbon\Carbon::parse($progress['started_at'])->setTimezone('UTC');
        } catch (\Throwable) {
            return false;
        }

        return $startedAt->greaterThan(BillingTime::nowUtc()->subHours(self::RESUME_WINDOW_HOURS));
    }

    /** {@see runMayStillFinish()}, logged. */
    private function leftForWebhookRun(Model $billable, string $paymentId, mixed $pending): bool
    {
        if (! self::runMayStillFinish($pending, $paymentId)) {
            return false;
        }

        Log::info('CleanupStalePendingProrataChangeJob: paid prorata charge whose run the webhook may still finish — left alone', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $paymentId,
            'run_started_at' => $pending['progress']['started_at'] ?? null,
            'completed_steps' => array_values((array) ($pending['progress']['done'] ?? [])),
        ]);

        return true;
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

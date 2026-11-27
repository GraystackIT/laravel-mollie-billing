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
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;

/**
 * Runs an activation again that did not finish the first time.
 *
 * A customer has paid, or handed over a mandate, and the subscription is not there: the
 * Mollie call that creates it failed, the process died between the invoice and that call,
 * or the activation was refused for a state that has since cleared. Each of these used to
 * end in a mail to an operator. Nearly all of them are a Mollie hiccup that is gone a
 * minute later — so the activation is simply run again, by replaying the payment through
 * the very code path its webhook took, with `ActivationRetry::resuming()` set.
 *
 * That replay goes through the ordinary two-phase gate (row lock, claim, activation
 * outside any transaction), and before it creates a Mollie subscription it looks for one
 * the first attempt may already have created — see `WebhookSupport::createSubscription()`.
 *
 * When every attempt is spent: a payment that collected money is refunded in full
 * (`UnwantedPaymentRefund`: invoice, credit note, a mail to the customer), and an operator
 * is told — once, here, and nowhere before.
 *
 * When another payment activated the subscription meanwhile (a second checkout tab), this
 * payment is a duplicate: refunded the same way, as `duplicate_payment`, with no operator
 * mail — see `finishCompleted()`.
 */
class RetrySubscriptionActivationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public const FIRST_RETRY_SECONDS = 60;

    public int $tries = 6;

    /**
     * Just past the whole ladder (7 h 22 min). The lock is released when the job finishes
     * or fails for good; this figure only matters for a job that was LOST, and it decides
     * how soon SweepPendingRetriesJob can put a new one in its place.
     */
    public int $uniqueFor = 28800;

    public function __construct(
        public readonly string $paymentId,
        public readonly string $billableClass,
        public readonly string|int $billableId,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return 'activation-retry:'.$this->paymentId;
    }

    /**
     * How long the whole ladder takes — after this, a marker that is still there has no
     * job behind it any more (a flushed queue, a worker that died, a sync app).
     */
    public const LADDER_SECONDS = self::FIRST_RETRY_SECONDS + 60 + 300 + 900 + 3600 + 21600;

    /**
     * Whether a dispatch would run this job at once, in the caller's own process.
     *
     * `sync` ignores the delay. Dispatched from the webhook, the retry then ran INSIDE the
     * delivery it is meant to repeat: it met that delivery's own reservation, could not
     * finish and threw — the delivery answered 500 — and because a sync job that throws
     * has failed for good, `failed()` refunded the payment seconds after a hiccup this
     * ladder exists to sit out. Such an app gets its retry from SweepPendingRetriesJob.
     */
    public static function runsInline(): bool
    {
        return \GraystackIT\MollieBilling\Support\BillingQueue::runsInline();
    }

    /** @return list<int> one minute, five, fifteen, an hour, six hours */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(MollieWebhookController $webhook, WebhookSupport $support): void
    {
        $billable = $this->billable();

        if ($billable === null || ! $this->isPending($billable)) {
            return;
        }

        if ($support->activationCompleted($billable)) {
            $this->finishCompleted($billable, $support);

            return;
        }

        try {
            $payment = MollieCall::send(new GetPaymentRequest($this->paymentId));

            if ((string) ($payment->status ?? '') !== 'paid') {
                // Nothing to activate on: a payment that is no longer paid bought nothing.
                $this->clearMarker($billable);

                return;
            }

            ActivationRetry::whileResuming(fn () => $webhook->replayDelivery($payment, $billable));

            $billable->refresh();

            if (! $support->activationCompleted($billable)) {
                throw new \RuntimeException("Activation for payment {$this->paymentId} is still incomplete.");
            }
        } catch (\Throwable $e) {
            // On `sync` a throw is final: `failed()` would refund the payment at the first
            // attempt the sweep makes. Until the ladder a worker would have climbed is over,
            // the marker stays and the next hourly sweep runs the activation again; the run
            // after that is the last, and its failure is final as on a real queue.
            if (\GraystackIT\MollieBilling\Support\BillingQueue::isSyncRun($this->job) && $this->withinLadder($billable)) {
                Log::warning('Activation retry did not finish — left for the hourly sweep (sync queue)', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $this->paymentId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }

        // Complete — but possibly by another payment that activated while this one was
        // waiting (the resumed activation then stands down, see claimForResume()).
        if ($support->activatedByAnotherPayment($billable, $this->paymentId)) {
            $this->finishCompleted($billable, $support);

            return;
        }

        $this->clearMarker($billable);

        if ($billable->getPendingFirstPaymentId() === $this->paymentId) {
            $billable->clearPendingFirstPayment();
        }

        Log::info('Subscription activation completed on retry', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $this->paymentId,
        ]);
    }

    /**
     * The retries are spent. Give the money back if any was collected, and tell an
     * operator what happened and what was done about it.
     */
    public function failed(?\Throwable $exception): void
    {
        $billable = $this->billable();

        if ($billable === null || ! $this->isPending($billable)) {
            return;
        }

        if (app(WebhookSupport::class)->activationCompleted($billable)) {
            $this->finishCompleted($billable, app(WebhookSupport::class));

            return;
        }

        $marker = (array) ($billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY][$this->paymentId] ?? []);
        $invoice = BillingInvoice::query()->where('mollie_payment_id', $this->paymentId)->first();
        $refunding = $invoice !== null && (int) $invoice->amount_gross > 0;

        if ($refunding) {
            // The invoice exists, so the service needs no more of the payment than its id.
            app(UnwantedPaymentRefund::class)->settle(
                (object) ['id' => $this->paymentId],
                $billable,
                UnwantedPaymentRefund::REASON_ACTIVATION_FAILED,
                'Subscription could not be activated',
            );
        }

        $this->clearMarker($billable);

        app(WebhookSupport::class)->safely(
            fn () => $this->giveBackMandate($billable),
            'activation_failed.revoke_mandate',
            $billable,
            ['payment_id' => $this->paymentId],
        );

        Log::error('Subscription activation failed for good', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $this->paymentId,
            'context' => $marker['context'] ?? null,
            'refunding' => $refunding,
            'error' => $exception?->getMessage(),
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            $refunding
                ? 'A paid subscription could not be activated after every retry — the payment is being refunded automatically and the customer has been told'
                : 'A mandate was captured but the subscription could not be activated after every retry — the customer is left with nothing',
            [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'payment_id' => $this->paymentId,
                'context' => $marker['context'] ?? null,
                'invoice_id' => $invoice?->getKey(),
                'invoice_serial' => $invoice?->serial_number,
                'amount_gross' => $invoice !== null ? (int) $invoice->amount_gross : null,
                'refunding' => $refunding,
                'error' => (string) ($exception?->getMessage() ?? ($marker['reason'] ?? '')),
            ],
        ));
    }

    /**
     * Take back the mandate this payment captured for a subscription that never came.
     *
     * The first payment (or the €0 authorisation of a trial) saved its mandate onto the
     * billable before the subscription was created. Left there, it denied every later
     * checkout the trial the customer never had — `StartSubscriptionCheckout` and the
     * checkout's `trialApplies()` grant one only without a mandate — and charged the first
     * period in full instead. The same reason `GrantExtrasPurchaseRefund` gives its mandate
     * back: the customer is left as they would be without the purchase.
     *
     * Only this payment's own mandate, and only while no Mollie subscription is on record
     * that could be charged against it. A refund still goes through on a revoked mandate
     * (measured in the sandbox, see docs/decisions.md).
     */
    private function giveBackMandate(Model $billable): void
    {
        /** @var Model&Billable $billable */
        $mandateId = (string) ($billable->getMollieMandateId() ?? '');
        $customerId = (string) ($billable->getMollieCustomerId() ?? '');

        if ($mandateId === '' || $customerId === ''
            || (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '') !== ''
        ) {
            return;
        }

        $payment = MollieCall::send(new GetPaymentRequest($this->paymentId));

        if ((string) ($payment->mandateId ?? '') !== $mandateId) {
            return;
        }

        // The lookup above ran with nothing held — no Mollie call inside a transaction — so
        // what was checked before it may be stale: a second checkout can have activated
        // meanwhile and saved a new mandate, or adopted this one, for a subscription that
        // now exists. Checked again on the locked row; only an unchanged billable gives the
        // mandate back. Otherwise it is left alone — and not revoked either, because Mollie
        // cancels every subscription of a revoked mandate.
        $givenBack = DB::transaction(function () use ($billable, $mandateId, $customerId): bool {
            /** @var (Model&Billable)|null $locked */
            $locked = $billable->newQuery()
                ->withoutGlobalScope(BillingScope::class)
                ->whereKey($billable->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || (string) ($locked->getMollieMandateId() ?? '') !== $mandateId
                || (string) ($locked->getMollieCustomerId() ?? '') !== $customerId
                || (string) ($locked->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '') !== ''
            ) {
                return false;
            }

            $locked->forceFill(['mollie_mandate_id' => null])->save();

            return true;
        });

        $billable->refresh();

        if ($givenBack) {
            RevokeMollieMandateJob::dispatch($customerId, $mandateId);
        }
    }

    /**
     * The billable's subscription is complete: end the ladder. When the subscription was
     * activated by ANOTHER payment, this payment's booked invoice is money nobody wants —
     * it is returned in full as a duplicate (`WebhookSupport::settleDuplicatePayment()`),
     * before the marker goes, so a failure on the way leaves the marker for the next run.
     * Only a payment that collected money has anything to return.
     *
     * "Complete" used to be read billable-wide and the marker simply cleared, so the
     * invoice of the payment that lost the race was kept and never refunded. A
     * subscription without the record of which payment activated it (from before the
     * record existed) is still treated that way: see `activatedByAnotherPayment()`.
     */
    private function finishCompleted(Model $billable, WebhookSupport $support): void
    {
        /** @var Model&Billable $billable */
        if ($support->activatedByAnotherPayment($billable, $this->paymentId)) {
            $invoice = BillingInvoice::query()->where('mollie_payment_id', $this->paymentId)->first();

            if ($invoice !== null && (int) $invoice->amount_gross > 0) {
                Log::warning('Activation retry found the subscription activated by another payment — refunding this one as a duplicate', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $this->paymentId,
                    'invoice_id' => $invoice->getKey(),
                ]);

                // The invoice exists, so the service needs no more of the payment than its id.
                $support->settleDuplicatePayment((object) ['id' => $this->paymentId], $billable);
            }
        }

        $this->clearMarker($billable);
    }

    /** Whether the marker's first failure is younger than the whole ladder. */
    private function withinLadder(Model $billable): bool
    {
        /** @var Model&Billable $billable */
        $since = $billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY][$this->paymentId]['first_failed_at'] ?? null;

        if (! is_string($since) || $since === '') {
            return false;
        }

        return \GraystackIT\MollieBilling\Support\BillingTime::toUtc($since)->addSeconds(self::LADDER_SECONDS)
            ->gt(\GraystackIT\MollieBilling\Support\BillingTime::nowUtc());
    }

    /** @return (Model&Billable)|null */
    private function billable(): ?Model
    {
        if (! class_exists($this->billableClass)) {
            return null;
        }

        $billable = $this->billableClass::withoutGlobalScope(BillingScope::class)->find($this->billableId);

        return $billable instanceof Model && $billable instanceof Billable ? $billable : null;
    }

    private function isPending(Model $billable): bool
    {
        /** @var Model&Billable $billable */
        return array_key_exists($this->paymentId, (array) ($billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? []));
    }

    private function clearMarker(Model $billable): void
    {
        $paymentId = $this->paymentId;

        $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId): array {
            unset($meta[ActivationRetry::META_KEY][$paymentId]);

            if (empty($meta[ActivationRetry::META_KEY])) {
                unset($meta[ActivationRetry::META_KEY]);
            }

            return $meta;
        });

        $billable->refresh();
    }
}

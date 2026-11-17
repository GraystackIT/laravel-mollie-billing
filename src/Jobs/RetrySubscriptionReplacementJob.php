<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Support\BillingQueue;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Finishes a replacement of a Mollie subscription that was started and not completed
 * (`MollieSubscriptionReplacer`, marker `pending_subscription_replacement`).
 *
 * The marker is written before the old subscription is cancelled and holds the complete
 * specification of its successor, so whatever stopped the first attempt — Mollie not
 * answering the cancel, the create, or the process dying in between — this picks up
 * where it stopped: cancel the old one if that has not been confirmed, then create the
 * successor (adopting one an earlier attempt created whose answer was lost). Dispatched
 * a minute after the failure, and by `SweepPendingRetriesJob` every hour for as long as
 * the marker stands.
 *
 * It does not give up. Between the cancel and the create the customer has no running
 * subscription, so nothing is charged — dropping the marker would make that permanent
 * and invisible. The admins are told once, when the replacement has not finished after
 * 24 hours; the retries go on.
 */
class RetrySubscriptionReplacementJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public const FIRST_RETRY_SECONDS = 60;

    /** After this long the admins are told — once. The retries continue. */
    public const NOTIFY_AFTER_HOURS = 24;

    public int $uniqueFor = 3600;

    public int $tries = 6;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return $this->billableClass.':'.$this->billableId.':replace';
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(MollieSubscriptionReplacer $replacer): void
    {
        $billable = $this->billable();

        if ($billable === null || ! MollieSubscriptionReplacer::pending($billable)) {
            return;
        }

        try {
            $newId = MollieSubscriptionReplacer::withLock($billable, fn (): ?string => $replacer->resume($billable));

            Log::info('RetrySubscriptionReplacementJob: replacement finished', [
                'billable_id' => $this->billableId,
                'mollie_subscription_id' => $newId,
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($billable, $e);

            // On `sync` a throw is final; the hourly sweep is the ladder there.
            if (BillingQueue::isSyncRun($this->job)) {
                return;
            }

            throw $e;
        }
    }

    /** The marker stays: the hourly sweep goes on. */
    public function failed(?Throwable $exception): void
    {
        $billable = $this->billable();

        if ($billable !== null && $exception !== null) {
            $this->recordFailure($billable, $exception);
        }
    }

    private function recordFailure(Model $billable, Throwable $e): void
    {
        $notify = false;

        $this->mutateBillingMeta($billable, static function (array $meta) use ($e, &$notify): ?array {
            $marker = $meta[MollieSubscriptionReplacer::META_KEY] ?? null;

            if (! is_array($marker)) {
                return null;
            }

            $marker['last_error'] = mb_substr($e->getMessage(), 0, 500);

            $since = (string) ($marker['first_attempt_at'] ?? '');

            if (! isset($marker['admin_notified_at'])
                && $since !== ''
                && BillingTime::toUtc($since)->diffInHours(BillingTime::nowUtc()) >= self::NOTIFY_AFTER_HOURS
            ) {
                $marker['admin_notified_at'] = BillingTime::nowUtc()->toIso8601String();
                $notify = true;
            }

            $meta[MollieSubscriptionReplacer::META_KEY] = $marker;

            return $meta;
        });

        Log::warning('RetrySubscriptionReplacementJob: replacement still not finished', [
            'billable_id' => $this->billableId,
            'error' => $e->getMessage(),
        ]);

        if (! $notify) {
            return;
        }

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        $marker = (array) ($billable->getBillingSubscriptionMeta()[MollieSubscriptionReplacer::META_KEY] ?? []);

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            ($marker['old_cancelled'] ?? false) === true
                ? 'A Mollie subscription was cancelled to move its charge date and its successor could not be created for 24 hours — nothing is being charged; retried hourly'
                : 'A Mollie subscription could not be replaced to move its charge date for 24 hours — retried hourly',
            [
                'billable_type' => $this->billableClass,
                'billable_id' => $this->billableId,
                'old_subscription_id' => $marker['old_subscription_id'] ?? null,
                'start_date' => $marker['start_date'] ?? null,
                'first_attempt_at' => $marker['first_attempt_at'] ?? null,
                'last_error' => $e->getMessage(),
            ],
        ));
    }

    private function billable(): (Model&\GraystackIT\MollieBilling\Contracts\Billable)|null
    {
        if (! class_exists($this->billableClass)) {
            return null;
        }

        $billable = $this->billableClass::withoutGlobalScope(BillingScope::class)->find($this->billableId);

        return $billable instanceof Model && $billable instanceof \GraystackIT\MollieBilling\Contracts\Billable ? $billable : null;
    }
}

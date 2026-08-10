<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Throwable;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Retries a Mollie subscription cancellation that could not be delivered.
 *
 * `CancelSubscription` ends the local subscription regardless of whether Mollie
 * acknowledged the cancel, and records a `pending_subscription_cancel` marker when it
 * did not. Until that cancel lands, Mollie keeps charging a customer whose access has
 * already ended and the recurring webhook books those charges as ordinary renewals —
 * so this is the most expensive of the retry markers to leave unread.
 *
 * Backoff: 1min, 5min, 30min, 2h, then every 2h. Hard limit 24h, or an exhausted
 * `tries` budget; either way the marker is dropped and the admins are told, because a
 * lingering marker would make SweepPendingRetriesJob restart the ladder hourly.
 *
 * A 404/410 from Mollie counts as success: the subscription is already gone.
 */
class RetrySubscriptionCancelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * Must outlive the whole ladder, not just the 24h hard limit: the limit only fires
     * on the first attempt *after* 24h, and with `tries = 30` the backoff below spans
     * ~52h. A `uniqueFor` equal to the limit expired mid-ladder, so the hourly sweep
     * started a second chain that gave up at once — clearing the marker and mailing the
     * admins, with the original chain mailing them a second time later.
     */
    public int $uniqueFor = 259200;

    public int $tries = 30;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public readonly string $mollieCustomerId,
        public readonly string $mollieSubscriptionId,
        public readonly string $firstAttemptAt,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return $this->billableClass.':'.$this->billableId.':cancel:'.$this->mollieSubscriptionId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 7200, 7200];
    }

    public function handle(): void
    {
        $firstAttempt = \Carbon\Carbon::parse($this->firstAttemptAt)->setTimezone('UTC');

        if ($firstAttempt->diffInHours(BillingTime::nowUtc()) >= 24) {
            $this->giveUp('Mollie subscription cancel kept failing for over 24h — the subscription may still be charging');

            return;
        }

        if ($this->mollieCustomerId === '' || $this->mollieSubscriptionId === '') {
            $this->clearMarker();

            return;
        }

        try {
            MollieCall::send(new CancelSubscriptionRequest($this->mollieCustomerId, $this->mollieSubscriptionId));
        } catch (ApiException $e) {
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
            // Already gone at Mollie — nothing left to cancel.
        }

        Log::info('RetrySubscriptionCancelJob: Mollie subscription cancelled', [
            'billable_id' => $this->billableId,
            'mollie_subscription_id' => $this->mollieSubscriptionId,
        ]);

        $this->clearMarker();
    }

    /**
     * Reached when `tries` runs out before the 24h window does.
     */
    public function failed(?Throwable $exception): void
    {
        $this->giveUp('Mollie subscription cancel kept failing (retry budget exhausted) — the subscription may still be charging');
    }

    private function giveUp(string $reason): void
    {
        $this->clearMarker();

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            $reason,
            [
                'billable_type' => $this->billableClass,
                'billable_id' => $this->billableId,
                'mollie_customer_id' => $this->mollieCustomerId,
                'mollie_subscription_id' => $this->mollieSubscriptionId,
                'first_attempt_at' => $this->firstAttemptAt,
            ],
        ));
    }

    private function clearMarker(): void
    {
        if (! class_exists($this->billableClass)) {
            return;
        }

        $billable = $this->billableClass::withoutGlobalScope(BillingScope::class)->find($this->billableId);

        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, static function (array $meta): ?array {
            if (! array_key_exists('pending_subscription_cancel', $meta)) {
                return null;
            }

            unset($meta['pending_subscription_cancel']);

            return $meta;
        });
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\OverageChargeFailed;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\UsageOverageChargeOutcome;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Re-attempts an overage charge whose payment failed or whose creation call failed.
 *
 * `usage_overage_attempts` counts payments that did NOT settle — reaching
 * MAX_PAYMENT_ATTEMPTS marks the billable past_due and duns the customer. Failures
 * to even create the payment are a different thing: they never touch the customer's
 * payment method, so they only consume the queue's `tries` budget and never dun.
 */
class RetryUsageOverageChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /** Terminal marker: the dunning budget is spent, the billable is past_due. */
    public const OVERAGE_FAILED = 'failed';

    private const MAX_PAYMENT_ATTEMPTS = 3;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public int $attempt = 1,
    ) {
        $this->initializeBillingQueue();
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
        // the counter went back to zero, and `past_due` was never reached.
        $previousPaymentId = (string) ($service->pendingCharge($billable)['payment_id'] ?? '');

        if ($previousPaymentId !== '' && ! $service->pendingChargeIsSettled($billable)) {
            $meta = $billable->getBillingSubscriptionMeta();
            $attempts = (int) ($meta['usage_overage_attempts'] ?? 0) + 1;
            $meta['usage_overage_attempts'] = $attempts;
            $billable->forceFill(['subscription_meta' => $meta])->save();

            if ($attempts >= self::MAX_PAYMENT_ATTEMPTS) {
                $this->giveUp($billable, $meta, new RuntimeException(sprintf(
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
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;
            $billable->forceFill(['subscription_meta' => $meta])->save();

            event(new OverageChargeFailed(
                $billable,
                (int) ($meta['usage_overage_attempts'] ?? 0),
                $e,
            ));

            throw $e;
        }

        // Nothing owed, or the previous payment turned out to be settled after all:
        // no charge is outstanding, so the markers go away.
        if ($result->outcome !== UsageOverageChargeOutcome::Charged) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage'], $meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        // Charged: a payment exists and the webhook owns its outcome. The attempt
        // counter is deliberately left in place.
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function giveUp(Model $billable, array $meta, Throwable $reason): void
    {
        $meta['usage_overage_status'] = self::OVERAGE_FAILED;
        $meta['past_due_since'] = $meta['past_due_since']
            ?? BillingTime::nowUtc()->toIso8601String();
        $billable->forceFill([
            'subscription_meta' => $meta,
            'subscription_status' => SubscriptionStatus::PastDue,
        ])->save();

        event(new OverageChargeFailed($billable, (int) ($meta['usage_overage_attempts'] ?? 0), $reason));

        $billingAdmins = MollieBilling::notifyBillingAdmins($billable);
        $billingAdmins = is_array($billingAdmins) ? $billingAdmins : iterator_to_array($billingAdmins);
        if ($billingAdmins !== []) {
            Notification::send(
                $billingAdmins,
                MollieBilling::resolveNotification(OverageBillingFailedNotification::class, $billable),
            );
        }

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                MollieBilling::resolveNotification(AdminOverageBillingFailedNotification::class, $billable, $reason),
            );
        }
    }
}

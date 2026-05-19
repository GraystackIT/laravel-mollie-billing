<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\OverageChargeFailed;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RetryUsageOverageChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

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

        $billable = $this->billableClass::find($this->billableId);

        if ($billable === null) {
            return;
        }

        try {
            $invoice = $service->handle($billable);

            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            if ($invoice !== null) {
                event(new OverageCharged($billable, $invoice, $meta['usage_overage']['line_items'] ?? []));
            }
        } catch (Throwable $e) {
            $meta = $billable->getBillingSubscriptionMeta();
            $attempts = (int) ($meta['usage_overage_attempts'] ?? 0) + 1;
            $meta['usage_overage_attempts'] = $attempts;
            $billable->forceFill(['subscription_meta' => $meta])->save();

            event(new OverageChargeFailed($billable, $attempts, $e));

            if ($attempts >= 3) {
                $meta['usage_overage_status'] = 'failed';
                $meta['past_due_since'] = $meta['past_due_since']
                    ?? BillingTime::nowUtc()->toIso8601String();
                $billable->forceFill([
                    'subscription_meta' => $meta,
                    'subscription_status' => SubscriptionStatus::PastDue,
                ])->save();

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
                        MollieBilling::resolveNotification(AdminOverageBillingFailedNotification::class, $billable, $e),
                    );
                }

                return;
            }

            // Let the queue retry.
            throw $e;
        }
    }
}

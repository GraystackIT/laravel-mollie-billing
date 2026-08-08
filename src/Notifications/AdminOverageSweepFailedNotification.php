<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The nightly overage pass exhausted its attempts.
 *
 * Not about one billable — `PrepareUsageOverageJob` walks the whole table, so a pass that
 * dies part-way has collected some of the night's metered usage and none of the rest, and
 * which is which is only visible in the log. Every other money job notifies an operator when
 * it gives up; without this, three failed attempts left a `failed_jobs` row and nothing else,
 * and a night that billed nothing looked exactly like a night with nothing to bill.
 *
 * The pass is idempotent by design (its own docblock explains how each of the five passes
 * filters out what a previous run already did), so the action is to re-run
 * `billing:prepare-overage` once the cause is fixed.
 */
class AdminOverageSweepFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly string $reason,
    ) {
        $this->initializeBillingQueue();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Overage preparation pass failed')
            ->line('The nightly usage-overage pass exhausted its retries and stopped part-way.')
            ->line('Some billables due to renew tomorrow may not have been charged for their metered overage, and their negative wallet balances will be carried into the next period.')
            ->line('Reason: '.$this->reason)
            ->line('The pass is safe to re-run: `php artisan billing:prepare-overage`.');
    }
}

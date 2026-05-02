<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Retry für Mollie-Subscription-PATCH (für künftige Recurring-Perioden).
 *
 * Backoff: 1min, 5min, 30min, 2h, dann jede 2h.
 * Hard-Limit: 24h. Danach Admin-Notification + Job stoppt.
 *
 * Selbst-heilende Eigenschaft: der Recurring-Webhook nutzt live Billable-State,
 * also ist der Mollie-Subscription-Betrag nur ein Anzeige-Issue, kein Geld-Issue.
 */
class RetrySubscriptionPatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 30; // ~24h mit Backoff

    public function __construct(
        public readonly array $intentData,
        public readonly string $firstAttemptAt,
    ) {}

    public function uniqueId(): string
    {
        return ($this->intentData['billable_type'] ?? '').':'.($this->intentData['billable_id'] ?? '').':patch';
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 7200, 7200];
    }

    public function handle(MollieSubscriptionPatcher $patcher): void
    {
        // Hard-Limit 24h.
        $firstAttempt = \Carbon\Carbon::parse((string) $this->firstAttemptAt)->setTimezone('UTC');
        if ($firstAttempt->diffInHours(BillingTime::nowUtc()) >= 24) {
            $this->notifyAdminOfDeadLetter();
            return;
        }

        try {
            $intent = PlanChangeIntent::fromArray($this->intentData);
            $patcher->updateForIntent($intent->billable, $intent);
        } catch (Throwable $e) {
            throw $e; // Job-Retry mit Backoff
        }
    }

    private function notifyAdminOfDeadLetter(): void
    {
        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            new AdminPlanChangeFailedNotification(
                reason: 'Mollie Subscription PATCH dauerhaft fehlgeschlagen (>24h Retries)',
                context: $this->intentData,
            ),
        );
    }
}

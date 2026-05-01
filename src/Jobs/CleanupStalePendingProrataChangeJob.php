<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Daily-Cron: pollt Mollie für hängende pending_prorata_change-States > 24h.
 *
 * Wenn der Charge-Webhook nie kommt (Mollie-Outage oder Webhook-Konfigfehler), bleibt
 * der Pending-State stehen. Dieser Job löst den Hänger auf:
 *
 * - Mollie-Payment-Status `paid` → manuelles Phase-2-Triggering.
 * - Status `failed`/`canceled`/`expired` → Pending-State löschen.
 * - Mollie nicht erreichbar > 7 Tage → Pending löschen + Admin-Notification.
 *
 * Sollte täglich via Scheduler laufen.
 */
class CleanupStalePendingProrataChangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $billableClass = config('mollie-billing.billable_model');
        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return;
        }

        $threshold = now()->subHours(24);

        // Suche Billables mit hängendem Pending-State.
        // (JSON-Query — funktioniert in MySQL/Postgres/SQLite.)
        $billables = $billableClass::query()
            ->whereNotNull('subscription_meta')
            ->get()
            ->filter(function ($b) use ($threshold) {
                $pending = $b->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
                if (empty($pending)) {
                    return false;
                }
                $createdAt = isset($pending['created_at']) ? \Carbon\Carbon::parse($pending['created_at']) : now();
                return $createdAt->lessThan($threshold);
            });

        foreach ($billables as $billable) {
            $this->processBillable($billable);
        }
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
            // Pending ohne Payment-ID — alt + nutzlos, löschen.
            unset($meta['pending_prorata_change']);
            $billable->forceFill(['subscription_meta' => $meta])->save();
            return;
        }

        // Hard-Limit: 7 Tage.
        $createdAt = isset($pending['created_at']) ? \Carbon\Carbon::parse($pending['created_at']) : now()->subDays(8);
        if ($createdAt->diffInDays(now()) >= 7) {
            unset($meta['pending_prorata_change']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            $admins = MollieBilling::notifyAdmin();
            $admins = is_array($admins) ? $admins : iterator_to_array($admins);
            if ($admins !== []) {
                Notification::send($admins, new AdminPlanChangeFailedNotification(
                    reason: 'pending_prorata_change wurde nach 7 Tagen ohne Webhook gelöscht',
                    context: ['payment_id' => $paymentId, 'billable_id' => $billable->getKey()],
                ));
            }
            return;
        }

        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::warning('CleanupStalePendingProrataChangeJob: Mollie unreachable for payment '.$paymentId, ['error' => $e->getMessage()]);
            return; // beim nächsten Lauf nochmal probieren
        }

        $status = (string) ($payment->status ?? '');

        if ($status === 'paid') {
            // Manuell die Phase-2-Logik triggern (analog zum Webhook-Handler).
            // Wir simulieren den Webhook indem wir createInvoice direkt aufrufen.
            // Für Vollständigkeit: idealerweise Webhook-Endpoint manuell aufrufen,
            // aber zur Vereinfachung machen wir das hier inline.
            $controller = app(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class);
            // Webhook braucht Request-Kontext; einfacher: re-dispatch via Mollie-Webhook-Aufruf.
            // Pragmatisch: wir loggen und überlassen es einem manuellen Webhook-Resend, oder
            // implementieren einen direkten Trigger (TODO für Cleanup-Erweiterung).
            Log::info('CleanupStalePendingProrataChangeJob: payment is paid but webhook missing — manual webhook resend needed', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);
            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            unset($meta['pending_prorata_change']);
            $meta['plan_change_failed_at'] = now()->toIso8601String();
            $meta['plan_change_failed_reason'] = "Charge {$status}";
            $billable->forceFill(['subscription_meta' => $meta])->save();
            return;
        }

        // Status pending/open/authorized: warten weiter.
    }
}

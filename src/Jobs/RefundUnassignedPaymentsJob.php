<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;

/**
 * Returns paid payments that could not be assigned to anybody.
 *
 * A payment settles whose metadata names a billable that does not exist — deleted between
 * checkout and webhook, or never created. The webhook can book nothing for it: no
 * billable, so no invoice, no wallet, no subscription. It tells an operator, once, and
 * leaves a trace row (`…:@unassigned` in `billing_processed_webhooks`).
 *
 * This job is the follow-up. After {@see GRACE_DAYS} — the package's fourteen days, the
 * same figure the other "an operator had their chance" limits use — a payment that is
 * still unassigned is refunded at Mollie in full. If its billable has turned up in the
 * meantime the payment is booked through the ordinary webhook path instead.
 *
 * **There is no invoice and no credit note for such a refund**, and that is not an
 * oversight: both are addressed to a billable, and there is none. The trace row and the
 * log are the record; the bank statement shows a debit and a credit of the same amount.
 *
 * A refund Mollie refuses is tried again on the next run. An operator is told after
 * {@see REPORT_AFTER_FAILED_DAYS} further days, once.
 */
class RefundUnassignedPaymentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public const GRACE_DAYS = 14;

    public const REPORT_AFTER_FAILED_DAYS = 3;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function handle(MollieWebhookController $webhook): void
    {
        $due = BillingProcessedWebhook::query()
            ->where('event_signature', 'like', '%'.BillingProcessedWebhook::UNASSIGNED_SUFFIX)
            ->whereNull('processed_at')
            ->where('received_at', '<=', BillingTime::nowUtc()->subDays(self::GRACE_DAYS))
            ->orderBy('id')
            ->get();

        foreach ($due as $trace) {
            try {
                $this->resolve($trace, $webhook);
            } catch (\Throwable $e) {
                // One payment that cannot be resolved must not stop the others.
                $this->reportFailure($trace, $e);
            }
        }
    }

    private function resolve(BillingProcessedWebhook $trace, MollieWebhookController $webhook): void
    {
        $paymentId = (string) $trace->mollie_payment_id;

        // Booked after all — somebody assigned it by hand.
        if (BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists()) {
            $this->close($trace);

            return;
        }

        try {
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));
        } catch (ApiException $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                $this->close($trace);

                return;
            }

            throw $e;
        }

        if ((string) ($payment->status ?? '') !== 'paid') {
            $this->close($trace);

            return;
        }

        // The billable exists now: this is an ordinary payment that arrived early.
        $billable = $this->billableFor($payment);

        if ($billable !== null) {
            $webhook->replayDelivery($payment, $billable);
            $this->close($trace);

            Log::info('Unassigned payment booked — its billable exists now', ['payment_id' => $paymentId]);

            return;
        }

        $currency = BillingMoney::normalizeCurrency($payment->amount->currency ?? null);
        $remaining = BillingMoney::minorFromMollieAmount($payment->amount->value ?? null, $currency)
            - BillingMoney::minorFromMollieAmount($payment->amountRefunded->value ?? null, $currency)
            - abs(BillingMoney::minorFromMollieAmount($payment->amountChargedBack->value ?? null, $currency));

        if ($remaining > 0) {
            // Keyed on the payment and the amount: a run whose answer was lost is
            // replayed by Mollie instead of paid out a second time.
            MollieCall::send(
                new CreatePaymentRefundRequest(
                    paymentId: $paymentId,
                    description: 'Automatic refund: payment could not be assigned',
                    amount: new Money($currency, BillingMoney::mollieAmount($remaining, $currency)),
                ),
                'unassigned-refund:'.$paymentId.':'.$remaining,
            );

            Log::warning('Unassigned payment refunded automatically', [
                'payment_id' => $paymentId,
                'amount_cents' => $remaining,
                'currency' => $currency,
                'unassigned_since' => (string) $trace->received_at,
            ]);
        }

        $this->close($trace);
    }

    private function billableFor(object $payment): ?Billable
    {
        $metadata = is_object($payment->metadata ?? null)
            ? (json_decode((string) json_encode($payment->metadata), true) ?: [])
            : (array) ($payment->metadata ?? []);

        $type = (string) ($metadata['billable_type'] ?? '');
        $id = $metadata['billable_id'] ?? null;

        if ($type !== '' && ! class_exists($type)) {
            $type = (string) (Relation::getMorphedModel($type) ?? '');
        }

        if ($type === '' || $id === null || ! class_exists($type)) {
            return null;
        }

        $billable = $type::withoutGlobalScope(BillingScope::class)->find($id);

        return $billable instanceof Billable ? $billable : null;
    }

    private function close(BillingProcessedWebhook $trace): void
    {
        $trace->forceFill(['processed_at' => BillingTime::nowUtc()])->save();
    }

    /**
     * Logged every time, mailed once — and only when the refund has been failing for
     * days, not on the first hiccup.
     */
    private function reportFailure(BillingProcessedWebhook $trace, \Throwable $e): void
    {
        $paymentId = (string) $trace->mollie_payment_id;

        Log::error('Automatic refund of an unassigned payment failed — it is tried again on the next run', [
            'payment_id' => $paymentId,
            'error' => $e->getMessage(),
        ]);

        $failingSince = BillingTime::toUtc($trace->received_at)->addDays(self::GRACE_DAYS + self::REPORT_AFTER_FAILED_DAYS);

        if ($failingSince->isFuture()) {
            return;
        }

        $reported = BillingProcessedWebhook::query()->firstOrCreate(
            ['mollie_payment_id' => $paymentId, 'event_signature' => $paymentId.BillingProcessedWebhook::UNASSIGNED_REFUND_FAILED_SUFFIX],
            ['received_at' => BillingTime::nowUtc(), 'processed_at' => BillingTime::nowUtc()],
        );

        if (! $reported->wasRecentlyCreated) {
            return;
        }

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            'A payment that could not be assigned to anybody could not be refunded automatically — it needs refunding by hand',
            [
                'payment_id' => $paymentId,
                'unassigned_since' => (string) $trace->received_at,
                'error' => $e->getMessage(),
            ],
        ));
    }
}

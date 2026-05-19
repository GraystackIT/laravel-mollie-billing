<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use RuntimeException;

/**
 * Drives subscription-lifecycle transitions deterministically on staging/test systems.
 *
 * Most flows mutate DB fields so that the matching daily job (PrepareUsageOverageJob or
 * ProcessTrialLifecycleJob) picks the billable up on a synchronous dispatch. The renewal
 * flow is the exception: it creates a real recurring payment in the Mollie sandbox and
 * then invokes our webhook controller with that payment id.
 *
 * Not for production: callers must gate on app environment before using.
 */
class LifecycleSimulator
{
    public const FLOWS = [
        'trial-ending-soon',
        'trial-expired',
        'renewal',
        'apply-scheduled-change',
        'overage-charge',
        'past-due-auto-cancel',
        'cancelled-to-expired',
    ];

    public function resolveBillable(int|string $id): Billable
    {
        $class = (string) config('mollie-billing.billable_model');
        if ($class === '' || ! class_exists($class)) {
            throw new RuntimeException('mollie-billing.billable_model is not configured.');
        }

        $billable = $class::query()->find($id);
        if ($billable === null) {
            throw new RuntimeException("Billable [{$class}] with id [{$id}] not found.");
        }

        if (! $billable instanceof Billable) {
            throw new RuntimeException("Model [{$class}] does not implement Billable contract.");
        }

        return $billable;
    }

    public function trialEndingSoon(Billable $billable): void
    {
        $this->force($billable, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Carbon::tomorrow('UTC')->addHours(6),
        ]);

        ProcessTrialLifecycleJob::dispatchSync();
    }

    public function trialExpired(Billable $billable): void
    {
        $this->force($billable, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Carbon::yesterday('UTC'),
        ]);

        ProcessTrialLifecycleJob::dispatchSync();
    }

    public function applyScheduledChange(Billable $billable, ?string $planCode = null): void
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $existing = $meta['scheduled_change'] ?? null;

        if ($planCode !== null) {
            $meta['scheduled_change'] = [
                'plan_code' => $planCode,
                'scheduled_at' => BillingTime::nowUtc()->subMinute()->toIso8601String(),
            ];
        } elseif (! is_array($existing) || empty($existing['plan_code'])) {
            throw new RuntimeException(
                'Billable has no scheduled change. Provide a plan code or schedule one first '
                .'via ScheduleSubscriptionChange.'
            );
        }

        $this->force($billable, [
            'scheduled_change_at' => BillingTime::nowUtc()->subMinute(),
            'subscription_meta' => $meta,
        ]);

        PrepareUsageOverageJob::dispatchSync();
    }

    public function overageCharge(Billable $billable, string $usageType, int $units): void
    {
        if ($units <= 0) {
            throw new RuntimeException('Withdraw units must be > 0.');
        }

        $billable->recordBillingUsage($usageType, $units, 'lifecycle_simulator');

        // Pass 1 only charges billables whose next billing date is tomorrow.
        $this->force($billable, [
            'subscription_period_starts_at' => BillingTime::nowUtc()
                ->subMonth()
                ->addDay()
                ->subDay(), // period started ~1 month ago, so next billing ≈ tomorrow
        ]);

        PrepareUsageOverageJob::dispatchSync();
    }

    public function pastDueAutoCancel(Billable $billable): void
    {
        $maxDays = (int) config('mollie-billing.past_due_max_days', 30);
        $since = BillingTime::nowUtc()->subDays($maxDays + 1)->toIso8601String();

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['past_due_since'] = $since;

        $this->force($billable, [
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ]);

        PrepareUsageOverageJob::dispatchSync();
    }

    public function cancelledToExpired(Billable $billable): void
    {
        $this->force($billable, [
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_ends_at' => BillingTime::nowUtc()->subMinute(),
        ]);

        PrepareUsageOverageJob::dispatchSync();
    }

    /**
     * Create a real recurring payment in the Mollie sandbox, wait until it's paid,
     * then invoke our webhook controller with that payment id.
     *
     * @return array{payment_id:string, status:string, invoice_id:int|null}
     */
    public function renewal(Billable $billable, ?float $amountGrossOverride = null): array
    {
        $mandateId = $billable->getMollieMandateId();
        $customerId = $billable->getMollieCustomerId();

        if ($mandateId === null || $customerId === null) {
            throw new RuntimeException(
                'Billable has no Mollie mandate or customer id. Complete a checkout first.'
            );
        }

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $gross = $amountGrossOverride
            ?? $this->estimateRenewalGross($billable);
        $amountValue = number_format($gross, 2, '.', '');

        $created = Mollie::send(new CreatePaymentRequest(
            description: 'Simulated subscription renewal',
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => 'subscription_renewal_sim',
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            ],
            sequenceType: 'recurring',
            mandateId: $mandateId,
            customerId: $customerId,
            webhookUrl: route(BillingRoute::webhook()),
        ));

        $paymentId = (string) ($created->id ?? '');
        if ($paymentId === '') {
            throw new RuntimeException('Mollie did not return a payment id.');
        }

        $payment = $this->waitForPaid($paymentId);

        $this->invokeWebhook($paymentId);

        $invoice = BillingInvoice::query()
            ->where('mollie_payment_id', $paymentId)
            ->first();

        return [
            'payment_id' => $paymentId,
            'status' => (string) ($payment->status ?? 'unknown'),
            'invoice_id' => $invoice?->id !== null ? (int) $invoice->id : null,
        ];
    }

    /**
     * Replay a previously processed Mollie payment through the webhook handler.
     * Returns true if the payment was re-fetched and routed.
     */
    public function replayWebhook(string $paymentId, bool $forceReset = false): bool
    {
        if ($forceReset) {
            BillingInvoice::query()->where('mollie_payment_id', $paymentId)->delete();
        }

        BillingProcessedWebhook::query()->where('mollie_payment_id', $paymentId)->delete();

        $this->invokeWebhook($paymentId);

        return true;
    }

    private function invokeWebhook(string $paymentId): void
    {
        $controller = app(MollieWebhookController::class);
        $request = Request::create(route(BillingRoute::webhook()), 'POST', ['id' => $paymentId]);

        $controller->__invoke($request);
    }

    private function waitForPaid(string $paymentId, int $timeoutSeconds = 30): object
    {
        $deadline = time() + $timeoutSeconds;
        $payment = null;

        while (time() < $deadline) {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
            $status = (string) ($payment->status ?? '');

            if (in_array($status, ['paid', 'failed', 'canceled', 'expired'], true)) {
                return $payment;
            }

            sleep(2);
        }

        if ($payment === null) {
            throw new RuntimeException("Payment [{$paymentId}] did not reach a final status within {$timeoutSeconds}s.");
        }

        return $payment;
    }

    private function estimateRenewalGross(Billable $billable): float
    {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $vatService = app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class);

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = method_exists($billable, 'getExtraBillingSeats')
            ? (int) $billable->getExtraBillingSeats()
            : 0;
        $seats = $catalog->includedSeats($planCode) + $extraSeats;

        $net = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
            $catalog, $billable, $planCode, $interval, $seats, $addonCodes
        );
        $vat = $vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $net,
            $billable,
        );

        return ((int) $vat['gross']) / 100;
    }

    private function force(Billable $billable, array $attributes): void
    {
        if ($billable instanceof Model) {
            $billable->forceFill($attributes)->save();
        }
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\OneTimeOrderFailed;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Exceptions\PaymentNotFoundException;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

class MollieWebhookController extends Controller
{
    public function __construct(
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly VatCalculationService $vatService,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CreateSubscription $createSubscription,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly WalletUsageService $walletService,
        protected readonly CouponService $couponService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $paymentId = (string) $request->input('id', '');

        Log::info('Webhook received', ['payment_id' => $paymentId]);

        if ($paymentId === '') {
            return response('', 200);
        }

        $reservation = $this->reserve($paymentId);
        if ($reservation === null) {
            Log::info('Webhook skipped — already processed or in progress', ['payment_id' => $paymentId]);
            return response('', 200);
        }

        try {
            $payment = $this->fetchPayment($paymentId);
        } catch (PaymentNotFoundException) {
            $reservation->delete();
            return response('', 200);
        } catch (\Throwable $e) {
            Log::warning('Mollie API lookup failed during webhook', ['id' => $paymentId, 'error' => $e->getMessage()]);
            $reservation->delete();
            return response('', 503);
        }

        $billable = $this->resolveBillableFromMetadata($payment);

        try {
            $this->route($payment, $billable);

            $reservation->update([
                'event_signature' => BillingProcessedWebhook::finalSignature($paymentId, (string) ($payment->status ?? 'unknown')),
                'processed_at' => now(),
            ]);

            return response('', 200);
        } catch (\Throwable $e) {
            Log::error('Billing webhook handler failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $reservation->delete();
            return response('', 500);
        }
    }

    protected function reserve(string $paymentId): ?BillingProcessedWebhook
    {
        $signature = BillingProcessedWebhook::pendingSignature($paymentId);

        $existingFinal = BillingProcessedWebhook::query()
            ->where('mollie_payment_id', $paymentId)
            ->where('event_signature', '!=', $signature)
            ->whereNotNull('processed_at')
            ->exists();

        if ($existingFinal) {
            return null;
        }

        $reservation = BillingProcessedWebhook::firstOrCreate(
            ['mollie_payment_id' => $paymentId, 'event_signature' => $signature],
            ['received_at' => now()],
        );

        if (! $reservation->wasRecentlyCreated) {
            return null;
        }

        return $reservation;
    }

    protected function fetchPayment(string $paymentId): object
    {
        try {
            return Mollie::send(new \Mollie\Api\Http\Requests\GetPaymentRequest($paymentId));
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            if (method_exists($e, 'getCode') && $e->getCode() === 404) {
                throw new PaymentNotFoundException($paymentId);
            }
            throw $e;
        }
    }

    protected function route(object $payment, ?Billable $billable): void
    {
        $status = (string) ($payment->status ?? 'unknown');
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['type'] ?? '');
        $subscriptionId = (string) ($payment->subscriptionId ?? '');

        Log::info('Webhook routing', [
            'payment_id' => $payment->id ?? null,
            'status' => $status,
            'type' => $type,
            'subscription_id' => $subscriptionId,
            'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
        ]);

        if ($status === 'paid') {
            if ($billable === null) {
                Log::warning('Paid webhook with unresolvable billable', ['id' => $payment->id]);
                return;
            }

            if ($type === 'mandate_only') {
                $this->handleMandateOnlyPaid($payment, $billable);
                return;
            }

            if ($type === 'one_time_order') {
                $this->handleOneTimeOrderPaid($payment, $billable, $metadata);
                return;
            }

            if (in_array($type, ['overage', 'prorata', 'addon', 'seats'], true)) {
                $this->handleSingleChargePaid($payment, $billable, $type, $metadata);
                // Fall through to check for refunds on the same payment below.
            } elseif ($subscriptionId !== '') {
                $this->handleSubscriptionPaymentPaid($payment, $billable, $metadata);
            } elseif (($metadata['upgrade_from_local'] ?? false) === true) {
                $this->handleLocalToMollieUpgrade($payment, $billable, $metadata);
            } else {
                $this->handleFirstPaymentPaid($payment, $billable, $metadata);
            }

            // Sync any refunds that were issued via the Mollie dashboard.
            // This runs *after* the normal paid-flow so that a payment that
            // is both paid and partially refunded is fully processed.
            if ($this->hasRefunds($payment)) {
                $this->handleRefundWebhook($payment, $billable);
            }

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            if ($billable === null) {
                return;
            }

            if ($type === 'one_time_order') {
                $this->handleOneTimeOrderFailed($payment, $billable, $metadata);
                return;
            }

            if (in_array($type, ['overage', 'prorata', 'addon', 'seats'], true)) {
                $this->handleSingleChargeFailed($payment, $billable, $type);
                return;
            }

            // First checkout payment that never resulted in a subscription.
            if ($subscriptionId === '' && ! $billable->hasAccessibleBillingSubscription()) {
                MollieBilling::runAfterCheckout($billable, false);
                return;
            }

            $this->handleSubscriptionPaymentFailed($payment, $billable);
        }
    }

    protected function handleMandateOnlyPaid(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $previousMandate = $billable->mollie_mandate_id;

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
        ])->save();

        event(new MandateUpdated($billable, $previousMandate, $billable->mollie_mandate_id));
    }

    protected function handleFirstPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        if ($billable->hasAccessibleBillingSubscription()) {
            Log::warning('Duplicate first-payment received — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
            ]);

            DuplicatePaymentReceived::dispatch($billable, (string) ($payment->id ?? ''));

            return;
        }

        $invoice = $this->persistFirstPaymentArtifacts($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'amount_gross' => $this->amountFromMolliePayment($payment),
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CreateSubscription during webhook failed', ['error' => $e->getMessage()]);
        }

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => now(),
        ])->save();

        // Hydrate wallets with the new plan's included usages. CreateSubscription
        // intentionally does not touch the wallet — that responsibility belongs
        // to the caller, since first-payment, Local→Mollie upgrade and resubscribe
        // each need different hydration semantics.
        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        $this->applyPendingCreditsCoupons($billable);

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * Convert a local subscription to a Mollie subscription after the first
     * payment has been confirmed. Reuses the local plan/interval as the *old*
     * state and rebalances the wallet so purchased credits and any remaining
     * plan quota are preserved across the conversion.
     */
    protected function handleLocalToMollieUpgrade(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        $oldPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $oldInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        $invoice = $this->persistFirstPaymentArtifacts($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'amount_gross' => $this->amountFromMolliePayment($payment),
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CreateSubscription during local upgrade failed', ['error' => $e->getMessage()]);
        }

        // Rebalance wallets: plan credits proratised against actual usage,
        // purchased credits preserved, overage charged via mandate if needed.
        if ($oldPlan !== '') {
            app(\GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster::class)
                ->adjust($billable, $oldPlan, $oldInterval, $planCode, $interval);
        }

        $this->applyPendingCreditsCoupons($billable);

        event(new \GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal(
            $billable,
            $oldPlan,
            $oldInterval,
            $planCode,
            $interval,
        ));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * Persist mandate, customer ID, country, and create the invoice for a first
     * payment (real first-time activation or local→Mollie upgrade).
     */
    protected function persistFirstPaymentArtifacts(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): \GraystackIT\MollieBilling\Models\BillingInvoice {
        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed', ['error' => $e->getMessage()]);
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);

        return $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
    }

    protected function handleSubscriptionPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? (string) ($metadata['plan_code'] ?? '');
        $interval = $billable->getBillingSubscriptionInterval() ?? (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();

        $seats = $this->catalog->includedSeats($planCode) + $extraSeats;
        $expectedNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);
        $vat = $this->vatService->calculate((string) ($billable->getBillingCountry() ?? ''), $expectedNet, $billable->vat_number);
        $actualGross = $this->amountFromMolliePayment($payment);

        if (abs($actualGross - (int) $vat['gross']) > 1) {
            event(new PaymentAmountMismatch($billable, (string) $payment->id, (int) $vat['gross'], $actualGross));
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);

        foreach ($addonCodes as $ignored) {
            // placeholder — line item construction already accounts for addons.
        }

        // Recharge wallets: reset or add based on rollover configuration.
        $rollover = $this->catalog->usageRollover($planCode);

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                if ($rollover) {
                    // Update purchased balance before adding new plan quota — the
                    // current balance reflects consumption from the past period.
                    $wallet = $billable->getWallet((string) $type);
                    if ($wallet !== null) {
                        $purchasedRemaining = WalletUsageService::computePurchasedRemaining(
                            WalletUsageService::getPurchasedBalance($wallet),
                            (int) $wallet->balanceInt,
                        );
                        WalletUsageService::setPurchasedBalance($wallet, $purchasedRemaining);
                    }

                    $this->walletService->credit($billable, (string) $type, (int) $units, 'subscription_renewal_rollover');
                } else {
                    // resetAndCredit handles purchased balance internally.
                    $this->walletService->resetAndCredit($billable, (string) $type, (int) $units, 'subscription_renewal');
                }
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $paidAt = $payment->paidAt ?? null;
        $periodStartsAt = $paidAt ? \Carbon\Carbon::parse((string) $paidAt) : now();

        $billable->forceFill([
            'subscription_period_starts_at' => $periodStartsAt,
        ])->save();

        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSingleChargePaid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $lineItems = (array) ($metadata['line_items'] ?? []);
        if (empty($lineItems)) {
            $actual = $this->amountFromMolliePayment($payment);
            $lineItems = [[
                'kind' => $type,
                'label' => ucfirst($type).' charge',
                'quantity' => 1,
                'unit_price_net' => $actual,
                'unit_price' => $actual,
                'total_net' => $actual,
            ]];
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, $type, $lineItems, $billable);

        if ($type === 'overage') {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage'], $meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            event(new OverageCharged($billable, $invoice, $lineItems));
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true)) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['prorata_pending_payment_id']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            // Apply the deferred plan change now that payment succeeded.
            $billable->refresh();
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;

            Log::info('Prorata payment processed, checking for pending plan change', [
                'billable' => $billable->getKey(),
                'has_pending' => ! empty($pendingChange),
                'pending_plan' => $pendingChange['plan_code'] ?? null,
            ]);

            if (! empty($pendingChange)) {
                try {
                    app(UpdateSubscription::class)->applyPendingPlanChange($billable);
                } catch (\Throwable $e) {
                    Log::error('Failed to apply pending plan change after prorata payment', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);

                    // Clear pending state so the UI stops polling and shows the failure.
                    app(UpdateSubscription::class)->clearPendingPlanChange($billable);
                    $billable->refresh();

                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['plan_change_failed_at'] = now()->toIso8601String();
                    $meta['plan_change_failed_reason'] = $e->getMessage();
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $e->getMessage()));

                    $recipients = MollieBilling::notifyBillingAdmins($billable);
                    if (! empty($recipients)) {
                        Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                    }
                }
            }
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSubscriptionPaymentFailed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['payment_failure'] = [
            'payment_id' => (string) $payment->id,
            'failed_at' => now()->toIso8601String(),
            'reason' => (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown'),
        ];

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentFailed($billable, (string) $payment->id, $meta['payment_failure']['reason']));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new SubscriptionPaymentFailedNotification($billable, (string) $payment->id));
        }
    }

    protected function handleSingleChargeFailed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof \Illuminate\Database\Eloquent\Model) {
            RetryUsageOverageChargeJob::dispatch(
                $billable->getMorphClass(),
                $billable->getKey(),
                1,
            );
            return;
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true) && $billable instanceof \Illuminate\Database\Eloquent\Model) {
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
            $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');

            app(UpdateSubscription::class)->clearPendingPlanChange($billable);

            if ($pendingChange) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['plan_change_failed_at'] = now()->toIso8601String();
                $meta['plan_change_failed_reason'] = $reason;
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $reason));

                $recipients = MollieBilling::notifyBillingAdmins($billable);
                if (! empty($recipients)) {
                    Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                }
            }
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }

    /**
     * Sync refunds initiated via the Mollie dashboard into local credit notes.
     *
     * Each Mollie refund has a unique ID (e.g. re_xxx). We store it in the
     * credit note's mollie_payment_id as "{paymentId}:re:{refundId}" so we
     * can deduplicate by refund ID rather than by amount (which would fail
     * for multiple partial refunds of the same value).
     */
    protected function handleRefundWebhook(object $payment, Billable $billable): void
    {
        $refunds = [];
        try {
            /** @phpstan-ignore-next-line — SDK magic. */
            $refunds = $payment->refunds();
        } catch (\Throwable) {
            return;
        }

        $original = BillingInvoice::query()
            ->where('mollie_payment_id', (string) $payment->id)
            ->first();

        if ($original === null) {
            return;
        }

        foreach ($refunds as $refund) {
            $refundId = (string) ($refund->id ?? '');
            if ($refundId === '') {
                continue;
            }

            // Deduplicate by Mollie refund ID — safe for multiple partial
            // refunds of the same amount on the same payment.
            $creditNotePaymentId = $payment->id.':re:'.$refundId;

            $exists = BillingInvoice::query()
                ->where('mollie_payment_id', $creditNotePaymentId)
                ->exists();

            if ($exists) {
                continue;
            }

            $refundAmountCents = (int) round(((float) ($refund->amount->value ?? 0)) * 100);

            // Convert gross refund to net using the original invoice rate.
            $rate = (float) $original->vat_rate;
            $netAmount = $rate > 0
                ? (int) round($refundAmountCents / (1 + $rate / 100))
                : $refundAmountCents;

            $creditNote = $this->salesInvoiceService->createCreditNote($original, $netAmount);
            $creditNote->mollie_payment_id = $creditNotePaymentId;
            $creditNote->refund_reason_code = RefundReasonCode::Other;
            $creditNote->refund_reason_text = 'synced from Mollie dashboard';
            $creditNote->save();

            $original->refunded_net = (int) $original->refunded_net + $netAmount;
            $original->save();

            event(new InvoiceRefunded($billable, $original, $creditNote, [
                'reason_code' => RefundReasonCode::Other,
                'reason_text' => 'synced from Mollie dashboard',
                'mollie_refund_id' => $refundId,
            ]));
        }
    }

    protected function resolveBillableFromMetadata(object $payment): ?Billable
    {
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['billable_type'] ?? '');
        $id = $metadata['billable_id'] ?? null;

        if ($type === '' || $id === null || ! class_exists($type)) {
            return null;
        }

        return $type::find($id);
    }

    protected function applyPendingCreditsCoupons(Billable $billable): void
    {
        $recent = $billable->redeemedBillingCoupons()
            ->with('coupon')
            ->where('applied_at', '>=', now()->subHour())
            ->get();

        foreach ($recent as $redemption) {
            $coupon = $redemption->coupon;
            if ($coupon && $coupon->type?->value === 'credits') {
                try {
                    $this->couponService->applyCreditsToWallets($billable, $redemption);
                } catch (\Throwable $e) {
                    Log::warning('Failed to apply credits coupon', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    protected function amountFromMolliePayment(object $payment): int
    {
        return (int) round(((float) ($payment->amount->value ?? 0)) * 100);
    }

    protected function hasRefunds(object $payment): bool
    {
        if (property_exists($payment, 'amountRefunded') && is_object($payment->amountRefunded)) {
            return ((float) ($payment->amountRefunded->value ?? 0)) > 0;
        }

        return false;
    }

    protected function handleOneTimeOrderPaid(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');
        if ($productCode === '') {
            Log::warning('One-time order webhook with missing product_code', ['id' => $payment->id]);

            return;
        }

        $priceNet = $this->catalog->productPriceNet($productCode);
        $lineItems = [[
            'kind' => 'one_time_order',
            'code' => $productCode,
            'label' => $this->catalog->productName($productCode) ?? $productCode,
            'quantity' => 1,
            'unit_price' => $priceNet,
            'unit_price_net' => $priceNet,
            'total_net' => $priceNet,
        ]];

        $invoice = $this->salesInvoiceService->createForPayment($payment, 'one_time_order', $lineItems, $billable);

        $usageType = $this->catalog->productUsageType($productCode);
        $quantity = $this->catalog->productQuantity($productCode);

        Log::info('One-time order: checking wallet credit', [
            'product_code' => $productCode,
            'usage_type' => $usageType,
            'quantity' => $quantity,
            'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
        ]);

        if ($usageType !== null && $quantity !== null && $quantity > 0) {
            $this->walletService->credit($billable, $usageType, $quantity, 'one_time_order:'.$productCode);

            // Track purchased credits separately so they survive period resets and plan changes.
            if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
                $wallet = $billable->getWallet($usageType);
                if ($wallet !== null) {
                    WalletUsageService::addPurchasedBalance($wallet, $quantity);
                }
            }

            Log::info('One-time order: wallet credited', [
                'product_code' => $productCode,
                'usage_type' => $usageType,
                'quantity' => $quantity,
            ]);
        }

        event(new OneTimeOrderCompleted($billable, $invoice, $productCode, $metadata));
        event(new PaymentSucceeded($billable, $invoice));
        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleOneTimeOrderFailed(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');

        event(new OneTimeOrderFailed(
            $billable,
            $productCode,
            (string) ($payment->id ?? ''),
            (string) ($payment->status ?? 'unknown'),
        ));
    }

    protected function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, new InvoiceAvailableNotification($billable, $invoice));
    }
}

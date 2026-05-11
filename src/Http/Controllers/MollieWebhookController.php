<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\PaymentNotFoundException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPaidWithoutBillableNotification;
use GraystackIT\MollieBilling\Services\Webhook\CountryCorrectionHandler;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentArtifacts;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\LocalToMollieUpgradeHandler;
use GraystackIT\MollieBilling\Services\Webhook\MandateOnlyPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\OneTimeOrderHandler;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Services\Webhook\SingleChargeHandler;
use GraystackIT\MollieBilling\Services\Webhook\SubscriptionPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

class MollieWebhookController extends Controller
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly OneTimeOrderHandler $oneTimeOrderHandler,
        protected readonly RefundHandler $refundHandler,
        protected readonly CountryCorrectionHandler $countryCorrectionHandler,
        protected readonly ProrataChargeHandler $prorataChargeHandler,
        protected readonly SingleChargeHandler $singleChargeHandler,
        protected readonly SubscriptionPaymentHandler $subscriptionPaymentHandler,
        protected readonly LocalToMollieUpgradeHandler $localToMollieUpgradeHandler,
        protected readonly FirstPaymentHandler $firstPaymentHandler,
        protected readonly MandateOnlyPaymentHandler $mandateOnlyPaymentHandler,
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
                'processed_at' => BillingTime::nowUtc(),
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
            ['received_at' => BillingTime::nowUtc()],
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

    /**
     * Returns true if an invoice already exists for this payment. Mollie may re-deliver
     * the same webhook after a transient handler failure (the reservation row is then
     * deleted in __invoke() and Mollie retries). Without this guard, the second run
     * would hit the mollie_payment_id unique index on billing_invoices and trap the
     * webhook in a 500-retry loop. Handlers must short-circuit on true to avoid running
     * non-idempotent side-effects (coupon redemptions, wallet credits, Mollie API calls)
     * a second time.
     */
    protected function invoiceAlreadyExistsForPayment(object $payment): bool
    {
        return $this->support->invoiceAlreadyExistsForPayment($payment);
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

        // Clear the pending-first-payment marker as soon as Mollie reports a
        // terminal state for that exact payment, regardless of branch. The
        // "waiting for payment" return page reads this to decide whether to
        // keep polling — leaving it set after a final state would extend the
        // poll loop unnecessarily.
        if ($billable !== null && in_array($status, ['paid', 'failed', 'canceled', 'expired'], true)) {
            $pendingId = $billable->getPendingFirstPaymentId();
            if ($pendingId !== null && $pendingId === (string) ($payment->id ?? '')) {
                $billable->clearPendingFirstPayment();
            }
        }

        if ($status === 'paid') {
            if ($billable === null) {
                $this->reportPaidWithoutBillable($payment, $metadata);
                return;
            }

            if ($type === 'mandate_only') {
                $this->handleMandateOnlyPaid($payment, $billable, $metadata);
                return;
            }

            if ($type === 'one_time_order') {
                $this->handleOneTimeOrderPaid($payment, $billable, $metadata);
                return;
            }

            // Neuer Plan-Change-Pfad (Multi-VAT-Sammel-Charge).
            if ($type === 'prorata_charge') {
                $this->handleProrataChargePaid($payment, $billable, $metadata);
                if ($this->hasRefunds($payment)) {
                    $this->handleRefundWebhook($payment, $billable);
                }
                return;
            }

            if ($type === 'country_correction') {
                $this->handleCountryCorrectionPaid($payment, $billable, $metadata);
                if ($this->hasRefunds($payment)) {
                    $this->handleRefundWebhook($payment, $billable);
                }
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

            if ($type === 'prorata_charge') {
                $this->handleProrataChargeFailed($payment, $billable, $metadata);
                return;
            }

            if ($type === 'country_correction') {
                $this->handleCountryCorrectionFailed($payment, $billable, $metadata);
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function handleMandateOnlyPaid(object $payment, Billable $billable, array $metadata = []): void
    {
        $this->mandateOnlyPaymentHandler->paid($payment, $billable, $metadata);
    }

    protected function handleFirstPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        $this->firstPaymentHandler->handle($payment, $billable, $metadata);
    }

    /**
     * Convert a local subscription to a Mollie subscription after the first
     * payment has been confirmed. Reuses the local plan/interval as the *old*
     * state and rebalances the wallet so purchased credits and any remaining
     * plan quota are preserved across the conversion.
     */
    protected function handleLocalToMollieUpgrade(object $payment, Billable $billable, array $metadata): void
    {
        $this->localToMollieUpgradeHandler->handle($payment, $billable, $metadata);
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    protected function persistFirstPaymentArtifacts(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): \GraystackIT\MollieBilling\Models\BillingInvoice {
        return $this->firstPaymentArtifacts->persist($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);
    }

    protected function handleSubscriptionPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        $this->subscriptionPaymentHandler->paid($payment, $billable, $metadata);
    }

    protected function handleSingleChargePaid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        $this->singleChargeHandler->paid($payment, $billable, $type, $metadata);
    }

    protected function handleSubscriptionPaymentFailed(object $payment, Billable $billable): void
    {
        $this->subscriptionPaymentHandler->failed($payment, $billable);
    }

    protected function handleSingleChargeFailed(object $payment, Billable $billable, string $type): void
    {
        $this->singleChargeHandler->failed($payment, $billable, $type);
    }

    protected function handleProrataChargePaid(object $payment, Billable $billable, array $metadata): void
    {
        $this->prorataChargeHandler->paid($payment, $billable, $metadata);
    }

    protected function handleProrataChargeFailed(object $payment, Billable $billable, array $metadata): void
    {
        $this->prorataChargeHandler->failed($payment, $billable, $metadata);
    }

    /**
     * Public so CleanupStalePendingCountryCorrectionJob can re-run the same
     * code path when the webhook never arrives. Idempotent.
     */
    public function handleCountryCorrectionPaid(object $payment, Billable $billable, array $metadata): void
    {
        $this->countryCorrectionHandler->paid($payment, $billable, $metadata);
    }

    /**
     * Public so CleanupStalePendingCountryCorrectionJob can re-run the same
     * code path when the webhook never arrives. Idempotent.
     */
    public function handleCountryCorrectionFailed(object $payment, Billable $billable, array $metadata): void
    {
        $this->countryCorrectionHandler->failed($payment, $billable, $metadata);
    }

    protected function handleRefundWebhook(object $payment, Billable $billable): void
    {
        $this->refundHandler->handle($payment, $billable);
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

    /**
     * Mollie reports a successful payment whose metadata points to a billable
     * we can no longer resolve locally (record deleted between checkout and
     * webhook, or stale metadata). The money already cleared at Mollie but we
     * cannot run any of the normal handlers — no invoice, no wallet credit,
     * no subscription transition. Surface this so admins can reconcile.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function reportPaidWithoutBillable(object $payment, array $metadata): void
    {
        $paymentId = (string) ($payment->id ?? '');
        $billableType = isset($metadata['billable_type']) ? (string) $metadata['billable_type'] : null;
        $billableId = isset($metadata['billable_id']) ? (string) $metadata['billable_id'] : null;
        $amountCents = $this->amountFromMolliePayment($payment);
        $currency = isset($payment->amount->currency) ? (string) $payment->amount->currency : null;

        Log::warning('Paid webhook with unresolvable billable', [
            'payment_id' => $paymentId,
            'billable_type' => $billableType,
            'billable_id' => $billableId,
            'amount_cents' => $amountCents,
            'currency' => $currency,
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                new AdminPaidWithoutBillableNotification(
                    paymentId: $paymentId,
                    billableType: $billableType,
                    billableId: $billableId,
                    amountCents: $amountCents,
                    currency: $currency,
                ),
            );
        }
    }

    /**
     * The first payment was received but creating the corresponding Mollie
     * subscription afterwards failed. The customer paid, so we keep the
     * invoice — but we must NOT mark the billable as Active, hydrate wallets
     * or fire SubscriptionCreated, because there is no Mollie subscription to
     * back any of that. We surface the failure via event + admin notification
     * so it can be reconciled manually.
     */
    protected function reportSubscriptionActivationFailure(
        Billable $billable,
        string $planCode,
        string $interval,
        \GraystackIT\MollieBilling\Models\BillingInvoice $invoice,
        object $payment,
        string $logMessage,
        \Throwable $error,
    ): void {
        $this->support->reportSubscriptionActivationFailure(
            $billable,
            $planCode,
            $interval,
            $invoice,
            $payment,
            $logMessage,
            $error,
        );
    }

    /**
     * Validate the first-payment coupon and pre-compute its discount, but do
     * NOT consume the redemption yet. The redemption must happen only after
     * the new Mollie subscription has been created — otherwise the coupon
     * could be permanently consumed against a subscription that never came
     * into existence.
     *
     * @return array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}|null
     */
    protected function priceFirstPaymentCoupon(
        Billable $billable,
        string $couponCode,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): ?array {
        return $this->firstPaymentCouponPricing->price(
            $billable, $couponCode, $planCode, $interval, $addonCodes, $extraSeats,
        );
    }

    /**
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $priced
     */
    protected function redeemPricedFirstPaymentCoupon(
        Billable $billable,
        array $priced,
        string $planCode,
        string $interval,
        int $invoiceId,
    ): void {
        $this->firstPaymentCouponPricing->redeem(
            $billable, $priced, $planCode, $interval, $invoiceId,
        );
    }

    protected function amountFromMolliePayment(object $payment): int
    {
        return $this->support->amountFromMolliePayment($payment);
    }

    protected function hasRefunds(object $payment): bool
    {
        return $this->support->hasRefunds($payment);
    }

    protected function handleOneTimeOrderPaid(object $payment, Billable $billable, array $metadata): void
    {
        $this->oneTimeOrderHandler->paid($payment, $billable, $metadata);
    }

    protected function handleOneTimeOrderFailed(object $payment, Billable $billable, array $metadata): void
    {
        $this->oneTimeOrderHandler->failed($payment, $billable, $metadata);
    }

    protected function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }
}

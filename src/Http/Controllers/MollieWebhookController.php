<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\PaymentNotFoundException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPaidWithoutBillableNotification;
use GraystackIT\MollieBilling\Services\Webhook\ActivationClaimHeldException;
use GraystackIT\MollieBilling\Services\Webhook\CountryCorrectionHandler;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentArtifacts;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\LocalToMollieUpgradeHandler;
use GraystackIT\MollieBilling\Services\Webhook\MandateOnlyPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\OneTimeOrderHandler;
use GraystackIT\MollieBilling\Services\Webhook\ChargebackHandler;
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
use GraystackIT\MollieBilling\Support\MollieCall;

class MollieWebhookController extends Controller
{
    /**
     * How long a still-pending reservation is believed to be in flight.
     *
     * Past this, {@see reserve()} treats it as abandoned and takes it over — see the
     * reasoning there. Longer than any legitimate delivery (invoice PDF plus several
     * Mollie round-trips) and far shorter than the 180-day retention that used to be
     * the only thing clearing a leaked row.
     */
    public const RESERVATION_TTL_MINUTES = 15;

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly OneTimeOrderHandler $oneTimeOrderHandler,
        protected readonly RefundHandler $refundHandler,
        protected readonly ChargebackHandler $chargebackHandler,
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
            // 503, not 200: this delivery may carry information the in-flight one
            // cannot see. Mollie re-calls the same payment when a refund is created,
            // and the country-mismatch flow refunds the very payment being processed —
            // so the holder fetched it before that refund existed, records `…:paid`,
            // and answering 200 here told Mollie the refund delivery was handled.
            // `refunded_net` then stayed 0 and the books over-stated revenue.
            //
            // Safe to retry because reserve() now takes over an abandoned reservation
            // after RESERVATION_TTL_MINUTES, so this cannot loop forever. The
            // claim-held branch below already answers 503 for exactly this reason.
            Log::info('Webhook deferred — another delivery for this payment is in progress', ['payment_id' => $paymentId]);

            return response('', 503);
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

        $outcome = $this->outcomeSignatureFor($payment, $paymentId);

        // Non-terminal status (open/pending/authorized): route() matches no
        // branch, so nothing happened and nothing may be recorded as processed —
        // otherwise the genuine terminal delivery would look like a duplicate
        // and the payment would never be booked.
        if ($outcome === null) {
            Log::info('Webhook ignored — payment not in a terminal state yet', [
                'payment_id' => $paymentId,
                'status' => $payment->status ?? null,
            ]);
            $reservation->delete();
            return response('', 200);
        }

        if ($this->outcomeAlreadyProcessed($paymentId, $outcome)) {
            Log::info('Webhook skipped — this outcome was already processed', [
                'payment_id' => $paymentId,
                'outcome' => $outcome,
            ]);
            $reservation->delete();
            return response('', 200);
        }

        $billable = $this->resolveBillableFromMetadata($payment);

        try {
            $this->route($payment, $billable);

            // Only now is a paid first payment truly done. Clearing the marker
            // earlier made a billable whose activation threw look abandoned to
            // CleanupOrphanedBillablesJob.
            $this->clearPendingFirstPaymentAfterSuccess($payment, $billable);

            $reservation->update([
                'event_signature' => $outcome,
                'processed_at' => BillingTime::nowUtc(),
            ]);

            return response('', 200);
        } catch (ActivationClaimHeldException $e) {
            // Benign back-off, not a failure: a competing payment's activation
            // is in flight for this billable. Nothing is recorded as processed
            // and 503 makes Mollie re-deliver once the holder has finished (the
            // duplicate/invoice guards then apply) or its claim went stale.
            Log::info('Webhook deferred — a competing activation holds the claim for this billable', [
                'payment_id' => $paymentId,
                'reason' => $e->getMessage(),
            ]);

            $reservation->delete();
            return response('', 503);
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

    /**
     * Drop the "waiting for payment" marker the return page polls, once the paid
     * handlers have actually run. Non-terminal states never reach here and the
     * failed states are cleared in route(); this covers `paid`, where clearing
     * too early is what let CleanupOrphanedBillablesJob delete a paying customer
     * whose activation had thrown.
     */
    protected function clearPendingFirstPaymentAfterSuccess(object $payment, ?Billable $billable): void
    {
        if ($billable === null || ($payment->status ?? null) !== 'paid') {
            return;
        }

        $pendingId = $billable->getPendingFirstPaymentId();

        if ($pendingId !== null && $pendingId === (string) ($payment->id ?? '')) {
            $billable->clearPendingFirstPayment();
        }
    }

    /**
     * Claim the right to process this payment id right now. The row is written
     * with the pending signature and rewritten to the delivery's outcome
     * signature once processing succeeded, so only one delivery of a given
     * payment can be in flight at a time while later deliveries that carry NEW
     * information (a terminal status after a non-terminal one, or a refund that
     * appeared after the paid run) can still claim their turn.
     */
    protected function reserve(string $paymentId): ?BillingProcessedWebhook
    {
        $reservation = BillingProcessedWebhook::firstOrCreate(
            [
                'mollie_payment_id' => $paymentId,
                'event_signature' => BillingProcessedWebhook::pendingSignature($paymentId),
            ],
            ['received_at' => BillingTime::nowUtc()],
        );

        if ($reservation->wasRecentlyCreated) {
            return $reservation;
        }

        // A pending row this old is not "in flight", it is abandoned. Nothing unwinds
        // the reservation when the process dies outright — an OOM or max_execution_time
        // fatal during PDF generation or the Mollie/VIES calls, a SIGKILL on deploy, or
        // the delete() in __invoke()'s own catch failing (a lost DB connection is
        // exactly what got us into that catch). The row then survived forever with the
        // pending signature, and because a non-created row means "skip", every later
        // delivery of that payment was answered 200: Mollie marked the webhook
        // delivered and stopped. A first payment was never activated — money collected,
        // no subscription, no invoice — and a renewal never booked, never advanced the
        // period and never recharged the wallets, for the 180 days until
        // PruneProcessedWebhooksJob removed the row.
        //
        // Same reasoning as the activation claim's TTL takeover, which was given one
        // for this failure mode; the reservation was not.
        if ($this->reservationIsAbandoned($reservation)) {
            // Conditional on the value we read, so exactly one delivery wins. `firstOrCreate`
            // is race-safe (the loser gets `wasRecentlyCreated === false` and a 503), but the
            // takeover was not: two deliveries could both see the same stale row, both call
            // it abandoned and both proceed. The activation claim and the
            // `billing_invoices.mollie_payment_id` unique index absorb that for the
            // activation and invoicing paths — but a credit note carries
            // `mollie_payment_id = null`, so nothing stopped the refund handler from writing
            // two for one refund, after which `effectiveRefundedNet()` read the invoice as
            // fully refunded and refused every later legitimate refund.
            $previous = $reservation->received_at;

            $won = $reservation->newQuery()
                ->whereKey($reservation->getKey())
                ->where('received_at', $previous)
                ->update(['received_at' => BillingTime::nowUtc()]);

            if ($won === 0) {
                return null;
            }

            Log::warning('Reclaiming an abandoned webhook reservation — a previous delivery died without unwinding it', [
                'payment_id' => $paymentId,
                'received_at' => (string) ($previous ?? ''),
            ]);

            return $reservation->refresh();
        }

        return null;
    }

    /**
     * Whether a still-pending reservation is old enough to be treated as dead.
     *
     * Generous on purpose: a legitimate delivery holds the row for as long as its
     * handler runs, which includes PDF generation and several Mollie calls. Reclaiming
     * one that is merely slow would reintroduce the double-activation race the
     * reservation prevents — so the window has to be longer than any real delivery,
     * and the activation claim guards that race independently anyway.
     */
    protected function reservationIsAbandoned(BillingProcessedWebhook $reservation): bool
    {
        $receivedAt = $reservation->received_at;

        if ($receivedAt === null) {
            return true;
        }

        return BillingTime::toUtc($receivedAt)
            ->addMinutes(self::RESERVATION_TTL_MINUTES)
            ->isPast();
    }

    /**
     * Signature describing what this delivery would do: the terminal status plus
     * the refunded total. Returns null while Mollie reports a non-terminal
     * status, because such a delivery has no outcome to deduplicate on.
     */
    protected function outcomeSignatureFor(object $payment, string $paymentId): ?string
    {
        $status = (string) ($payment->status ?? '');

        if (! in_array($status, ['paid', 'failed', 'canceled', 'expired'], true)) {
            return null;
        }

        return BillingProcessedWebhook::outcomeSignature(
            $paymentId,
            $status,
            $this->refundedAmount($payment),
            // A chargeback leaves the status at `paid` and shows up nowhere else, so
            // without this component its delivery was byte-identical to the paid one
            // already on record and was deduped away.
            $this->support->chargedBackAmount($payment),
        );
    }

    /**
     * True when an earlier delivery already finished processing exactly this
     * outcome — a genuine duplicate that must not run its side-effects twice.
     */
    protected function outcomeAlreadyProcessed(string $paymentId, string $outcome): bool
    {
        return BillingProcessedWebhook::query()
            ->where('mollie_payment_id', $paymentId)
            ->where('event_signature', $outcome)
            ->whereNotNull('processed_at')
            ->exists();
    }

    protected function fetchPayment(string $paymentId): object
    {
        try {
            return MollieCall::send(new \Mollie\Api\Http\Requests\GetPaymentRequest($paymentId));
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

        // The pending-first-payment marker survives EVERY webhook outcome here.
        // On `paid` deliberately: the paid handlers still have to activate the
        // subscription, and if that throws we return 200 anyway — a billable left
        // at New/None with a paid invoice and a live mandate then looks abandoned
        // to CleanupOrphanedBillablesJob, which used to delete it and revoke the
        // mandate; the handler clears it once activation succeeded. And on
        // `failed`/`canceled`/`expired` too: the webhook nearly always beats the
        // customer's redirect, and the return page's failure probe finds the
        // payment through exactly this marker — clearing it here left the customer
        // staring at the "activating…" spinner until the poll timeout instead of
        // seeing "payment failed, try again". The return page clears the marker
        // itself once it has shown the failure, and CleanupOrphanedBillablesJob
        // treats a billable WITH the marker more carefully, not less: it polls
        // Mollie and only cleans on a terminal failure status.

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
                $this->syncMollieCredits($payment, $billable);
                return;
            }

            // Neuer Plan-Change-Pfad (Multi-VAT-Sammel-Charge).
            if ($type === 'prorata_charge') {
                $this->handleProrataChargePaid($payment, $billable, $metadata);
                $this->syncMollieCredits($payment, $billable);
                return;
            }

            if ($type === 'country_correction') {
                $this->handleCountryCorrectionPaid($payment, $billable, $metadata);
                $this->syncMollieCredits($payment, $billable);
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
            $this->syncMollieCredits($payment, $billable);

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            if ($billable === null) {
                return;
            }

            // Aborted €0 mandate authorisation (payment-method change or
            // trial/coupon checkout) — never a subscription payment failure.
            if ($type === 'mandate_only') {
                $this->handleMandateOnlyFailed($payment, $billable, $metadata);
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

            // Aborted checkout payment: a first checkout, a Local→Mollie upgrade
            // or a stale earlier attempt. None of them carries a Mollie
            // subscriptionId and none of them can be a recurring-payment
            // failure — no charge was ever attempted against an existing
            // subscription. Marking the billable PastDue here would lock the
            // customer out of the free Local plan they still hold (aborted
            // upgrade) or out of the subscription a later attempt already
            // activated (stale first attempt).
            if ($subscriptionId === '') {
                if (($metadata['upgrade_from_local'] ?? false) === true
                    || ! $billable->hasAccessibleBillingSubscription()
                ) {
                    MollieBilling::runAfterCheckout($billable, false);
                    return;
                }

                Log::info('Aborted checkout payment for a billable that already has an accessible subscription — not treated as a payment failure', [
                    'payment_id' => $payment->id ?? null,
                    'status' => $status,
                    'type' => $type,
                ]);

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

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function handleMandateOnlyFailed(object $payment, Billable $billable, array $metadata = []): void
    {
        $this->mandateOnlyPaymentHandler->failed($payment, $billable, $metadata);
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

    protected function handleChargebackWebhook(object $payment, Billable $billable): void
    {
        $this->chargebackHandler->handle($payment, $billable);
    }

    /**
     * Book whatever money has left this payment since it settled.
     *
     * Both kinds arrive as a re-delivery of the *same* payment, and both can be present
     * at once (a partial refund and then a chargeback of the rest), so they are checked
     * independently rather than as an either/or.
     */
    protected function syncMollieCredits(object $payment, Billable $billable): void
    {
        if ($this->hasRefunds($payment)) {
            $this->handleRefundWebhook($payment, $billable);
        }

        if ($this->support->hasChargebacks($payment)) {
            $this->handleChargebackWebhook($payment, $billable);
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

        // Every checkout writes `getMorphClass()` into the metadata, which is a
        // morph-map alias in apps that register one — resolve it back to the FQCN
        // before touching the class.
        if ($type !== '' && ! class_exists($type)) {
            $type = (string) (\Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? '');
        }

        if ($type === '' || $id === null || ! class_exists($type)) {
            return null;
        }

        // Bypass the app-defined billing scope so a webhook for a billable
        // that no longer matches the scope (e.g. a tenant flag flipped) still
        // resolves — losing a payment webhook is worse than enforcing the filter.
        return $type::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)->find($id);
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
                MollieBilling::resolveNotification(
                    AdminPaidWithoutBillableNotification::class,
                    $paymentId,
                    $billableType,
                    $billableId,
                    $amountCents,
                    $currency,
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

    protected function refundedAmount(object $payment): int
    {
        return $this->support->refundedAmount($payment);
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

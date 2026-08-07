<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WebhookSupport
{
    /**
     * subscription_meta key under which an in-flight activation parks its claim.
     */
    public const ACTIVATION_CLAIM_META_KEY = 'activation_claim';

    /**
     * Minutes before a foreign activation claim is considered abandoned and may
     * be taken over. Must comfortably exceed the longest plausible in-flight
     * activation (invoice + PDF, the Mollie CreateSubscription call, a
     * country-mismatch refund + re-issue, synchronous notifications) so a claim
     * only ever reads as stale once its owner is truly gone — a live owner whose
     * Mollie call is still in flight must never be raced.
     */
    public const ACTIVATION_CLAIM_TTL_MINUTES = 10;
    public function invoiceAlreadyExistsForPayment(object $payment): bool
    {
        $paymentId = (string) ($payment->id ?? '');
        if ($paymentId === '') {
            return false;
        }

        return BillingInvoice::query()
            ->where('mollie_payment_id', $paymentId)
            ->exists();
    }

    /**
     * The gross Mollie collected, in the payment's own minor units.
     *
     * Scaled by the payment's currency, not by a hardcoded 100: the number of minor
     * units per major unit is a property of the currency, so ¥500 read as 50 000
     * would enter the invoice, the mismatch guard and the wallet settlement at a
     * hundred times its value. The payment's own currency is used rather than the
     * configured one, because a webhook can arrive for a payment booked before a
     * `BILLING_CURRENCY` change.
     */
    public function amountFromMolliePayment(object $payment): int
    {
        return BillingMoney::minorFromMollieAmount(
            $payment->amount->value ?? null,
            $payment->amount->currency ?? null,
        );
    }

    public function hasRefunds(object $payment): bool
    {
        return $this->refundedAmount($payment) > 0;
    }

    /**
     * Total amount refunded on this payment, in cents. Monotonic per payment
     * (Mollie reports the sum of all its refunds), which makes it usable as the
     * refund fingerprint in the webhook dedup signature.
     */
    public function refundedAmount(object $payment): int
    {
        $refunded = $payment->amountRefunded ?? null;

        if (! is_object($refunded)) {
            return 0;
        }

        // Scaled by the refund's own currency, like its sibling above. Harmless today
        // (this feeds only the dedup signature and hasRefunds()), but it was the last
        // hardcoded ×100 left in the package and one refactor away from a booking.
        return BillingMoney::minorFromMollieAmount(
            $refunded->value ?? null,
            $refunded->currency ?? $payment->amount->currency ?? null,
        );
    }

    /**
     * Reconcile webhook-built line items with the amount Mollie actually
     * collected. Handlers that rebuild their line items from the catalog at
     * webhook time can drift from the checkout: a coupon that expired in the
     * meantime, a price that changed, a discount the checkout applied but the
     * handler cannot reconstruct. The money that cleared is the source of truth,
     * so on a difference we fire {@see PaymentAmountMismatch} and append an
     * adjustment line that pulls the invoice total onto the paid amount — header,
     * line items, PDF and OSS export then all agree with the payment, and the
     * refundable base stays within what Mollie can actually refund.
     *
     * `$vatRate` must come from the country lookup (never be reverse-engineered
     * from the gross) — it is only used to split the paid gross back into net.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    /**
     * Summed net of a set of invoice line items.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function netFromLineItems(array $lineItems): int
    {
        $net = 0;

        foreach ($lineItems as $line) {
            $net += (int) ($line['total_net'] ?? 0);
        }

        return $net;
    }

    /**
     * The gross a set of invoice line items adds up to, VAT rounded per line.
     *
     * Deliberately derived here rather than accepted as a parameter: every caller
     * used to compute it as one rounding over the summed net, while
     * `InvoiceService::createForPayment()` derives the invoice header by summing
     * per-line VAT and the charge sites now collect the same per-line sum. Three
     * places computing "the expected gross" produced two different numbers, so the
     * guard reported a mismatch on renewals where nothing was wrong. One source.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function grossForLineItems(array $lineItems, float $vatRate): int
    {
        return BillingMoney::grossWithLineVat(
            array_map(static fn (array $line): int => (int) ($line['total_net'] ?? 0), $lineItems),
            $vatRate,
        );
    }

    /**
     * Back out the net from a gross Mollie already collected.
     *
     * Only correct for a single-rate document, which is what every caller here is:
     * a webhook charge carries one country's rate. A rate of 0 — reverse charge, or
     * a country without VAT — makes net and gross the same number.
     */
    public function netFromGross(int $gross, float $vatRate): int
    {
        return $vatRate > 0
            ? (int) round($gross / (1 + $vatRate / 100))
            : $gross;
    }

    public function reconcileWithPaidAmount(
        object $payment,
        Billable $billable,
        array $lineItems,
        int $expectedNet,
        float $vatRate,
    ): array {
        $actualGross = $this->amountFromMolliePayment($payment);
        $expectedGross = $this->grossForLineItems($lineItems, $vatRate);

        // 1 cent of slack: gross is rounded per invoice, so an exact match is not
        // guaranteed even when nothing is wrong.
        if (abs($actualGross - $expectedGross) <= 1) {
            return $lineItems;
        }

        event(new PaymentAmountMismatch($billable, (string) ($payment->id ?? ''), $expectedGross, $actualGross));

        $actualNet = $this->netFromGross($actualGross, $vatRate);
        $delta = $actualNet - $expectedNet;

        Log::warning('Webhook invoice amount differs from the amount paid — reconciling to the paid amount', [
            'payment_id' => $payment->id ?? null,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'expected_gross' => $expectedGross,
            'actual_gross' => $actualGross,
            'adjustment_net' => $delta,
        ]);

        if ($delta === 0) {
            return $lineItems;
        }

        $lineItems[] = [
            'kind' => 'adjustment',
            'code' => null,
            'label' => 'Payment adjustment',
            'quantity' => 1,
            'unit_price' => $delta,
            'unit_price_net' => $delta,
            'total_net' => $delta,
        ];

        return $lineItems;
    }

    /**
     * Take the billable's row lock for an activation gate, ignoring the app's
     * BillingScope.
     *
     * `newQuery()` applies that scope, and the webhook controller deliberately
     * resolves billables *without* it ("losing a payment webhook is worse than
     * enforcing the filter"). For a billable the app's `applyBillingScope()`
     * filters out, the locking `first()` therefore matched nothing and **no lock
     * was taken at all** — silently, since nobody inspects the result. Two
     * deliveries for different payments could then interleave through the gate,
     * both find no claim, and create two Mollie subscriptions: exactly the race
     * the gate exists to prevent, reopened for every multi-tenant app that
     * defines a scope.
     *
     * Must run inside the caller's transaction.
     *
     * @throws \RuntimeException when the row is gone — a caller holding no lock
     *                           must not proceed to activate.
     */
    public function lockBillableRow(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $locked = $billable->newQueryWithoutScopes()
            ->whereKey($billable->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new \RuntimeException(
                'Cannot lock billable '.$billable->getKey().' for activation — row not found.'
            );
        }

        $billable->refresh();
    }

    /**
     * Durably claim the right to activate a subscription for this billable.
     *
     * Must run inside the caller's row-lock transaction, after the invoice and
     * duplicate guards: the committed claim is what a concurrent delivery for a
     * DIFFERENT payment sees while our activation — whose own writes are not
     * yet visible — is still in flight, making it back off (via
     * {@see ActivationClaimHeldException}) instead of creating a second Mollie
     * subscription. The claim deliberately does not share the activation's
     * fate: it survives a crash, so the same payment's re-delivery can re-claim
     * immediately and a sibling payment can take over once the claim is older
     * than {@see self::ACTIVATION_CLAIM_TTL_MINUTES}. Callers remove it via
     * {@see releaseActivationClaim()} on both the success and the failure path.
     */
    public function claimActivation(Billable $billable, string $paymentId): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $claim = $meta[self::ACTIVATION_CLAIM_META_KEY] ?? null;

        if (is_array($claim)
            && (string) ($claim['payment_id'] ?? '') !== $paymentId
            && $this->claimIsFresh($claim)
        ) {
            throw new ActivationClaimHeldException(
                (string) $billable->getKey(),
                (string) ($claim['payment_id'] ?? ''),
                $paymentId,
            );
        }

        $meta[self::ACTIVATION_CLAIM_META_KEY] = [
            'payment_id' => $paymentId,
            'claimed_at' => BillingTime::nowUtc()->toIso8601String(),
        ];
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Drop the claim once the in-flight activation has ended — successfully or
     * with a throw. Only $paymentId's own claim is removed: a zombie delivery
     * whose stale claim was already taken over must not release the new
     * holder's shield. Failures are swallowed — an unreleased claim merely
     * delays a competing payment by the TTL, which is never worth masking the
     * activation's actual outcome.
     */
    public function releaseActivationClaim(Billable $billable, string $paymentId): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        try {
            // Refresh first: activation steps and app listeners may have
            // written subscription_meta through other instances of the model.
            $billable->refresh();

            $meta = $billable->getBillingSubscriptionMeta();
            $claim = $meta[self::ACTIVATION_CLAIM_META_KEY] ?? null;

            if (! is_array($claim) || (string) ($claim['payment_id'] ?? '') !== $paymentId) {
                return;
            }

            unset($meta[self::ACTIVATION_CLAIM_META_KEY]);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        } catch (\Throwable $e) {
            Log::warning('Activation claim could not be released — competing activations are delayed until it expires', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    protected function claimIsFresh(array $claim): bool
    {
        $claimedAt = $claim['claimed_at'] ?? null;

        if (! is_string($claimedAt) || $claimedAt === '') {
            // A claim we cannot date is treated as stale: the format is our
            // own, so this only happens on manual meta edits — liveness wins.
            return false;
        }

        try {
            $claimed = BillingTime::toUtc($claimedAt);
        } catch (\Throwable) {
            return false;
        }

        return $claimed->gt(BillingTime::nowUtc()->subMinutes(self::ACTIVATION_CLAIM_TTL_MINUTES));
    }

    public function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, MollieBilling::resolveNotification(InvoiceAvailableNotification::class, $billable, $invoice));
    }

    /**
     * Whether an activation actually finished, as opposed to merely having written
     * its invoice.
     *
     * The gates treat "this payment's invoice exists" as "nothing left to do", which
     * only holds for a crash *after* the Mollie subscription was created — that call
     * is what sets the billable Active. Between the invoice being committed and that
     * call being reached, the invoice exists and nothing else does.
     */
    public function activationCompleted(Billable $billable): bool
    {
        if ($billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value) {
            return $billable->hasAccessibleBillingSubscription();
        }

        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        return $subscriptionId !== '' && $billable->hasAccessibleBillingSubscription();
    }

    /**
     * Report a re-delivery that found this payment's invoice but no finished
     * activation.
     *
     * Nothing here retries: the invoice guard makes re-running the activation unsafe
     * without more surgery than a webhook should do, and a mandate plus a paid invoice
     * is a state an operator can resolve in seconds once they know about it. What
     * mattered was that they were never told — this path logged at info level and
     * returned, so a paying customer sat with no subscription indefinitely.
     */
    public function reportIncompleteActivation(Billable $billable, object $payment, string $context): void
    {
        $paymentId = (string) ($payment->id ?? '');
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');
        $reason = 'Activation never completed: this payment has an invoice but the subscription was not created';

        Log::error('Webhook re-delivery found an incomplete activation', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
            'context' => $context,
            'status' => $billable->getBillingSubscriptionStatus()->value,
        ]);

        event(new SubscriptionActivationFailed(
            $billable,
            $planCode,
            $interval,
            $paymentId,
            null,
            $reason,
        ));

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'A paid payment has an invoice but its subscription was never created — the customer is paying for nothing',
                [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'payment_id' => $paymentId,
                    'context' => $context,
                    'plan_code' => $planCode,
                    'interval' => $interval,
                    'status' => $billable->getBillingSubscriptionStatus()->value,
                    'error' => $reason,
                ],
            ),
        );
    }

    public function reportSubscriptionActivationFailure(
        Billable $billable,
        string $planCode,
        string $interval,
        BillingInvoice $invoice,
        object $payment,
        string $logMessage,
        \Throwable $error,
    ): void {
        $paymentId = (string) ($payment->id ?? '');

        Log::warning($logMessage, [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'plan_code' => $planCode,
            'interval' => $interval,
            'payment_id' => $paymentId,
            'invoice_id' => (int) $invoice->id,
            'error' => $error->getMessage(),
        ]);

        event(new SubscriptionActivationFailed(
            $billable,
            $planCode,
            $interval,
            $paymentId,
            (int) $invoice->id,
            $error->getMessage(),
        ));

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                MollieBilling::resolveNotification(
                    AdminPlanChangeFailedNotification::class,
                    'Subscription activation after first payment failed — Mollie subscription was not created',
                    [
                        'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                        'plan_code' => $planCode,
                        'interval' => $interval,
                        'payment_id' => $paymentId,
                        'invoice_id' => (int) $invoice->id,
                        'error' => $error->getMessage(),
                    ],
                ),
            );
        }
    }
}

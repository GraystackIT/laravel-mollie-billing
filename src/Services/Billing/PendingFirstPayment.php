<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;

/**
 * What to do with the first payment already on record before a new one is created.
 *
 * One payment per intent. Every screen that starts a `sequenceType: first` payment — the
 * checkout, the upgrade from a free plan, the purchase of extras on a gift — releases its
 * submit lock as soon as the payment exists, so the lock only serialises clicks that are
 * truly concurrent. "Back from Mollie and confirm again" is sequential, and it minted a
 * second payment that could be paid next to the first: the customer was charged twice and
 * the second payment was kept as a duplicate, with no invoice and no refund. Only the
 * checkout asked, and it asked only "is it still open":
 *
 * - **open, with a checkout link, for the same order** → back to that link;
 * - **open, for a different order** → withdrawn at Mollie, then a new payment. Sending the
 *   customer to it booked the order they had left, at its price, from its metadata. Mollie
 *   refuses to cancel an open first payment (measured), so in practice the customer is
 *   told until when it stays open and offered its checkout link
 *   (`PendingPaymentForAnotherOrderException::$expiresAt` / `$checkoutUrl`);
 * - **pending** (it has left the browser) → the return page, which follows it to its end;
 * - **paid** (the webhook is not here yet) → the return page: that money is about to
 *   become the subscription;
 * - **an activation running or waiting for its retry** → the return page, whether or not
 *   a payment is still on record (the webhook drops the marker once its handlers ran,
 *   finished or not);
 * - anything else, or a Mollie hiccup → a new payment. A customer must never be locked
 *   out of checking out by a probe that failed.
 */
class PendingFirstPayment
{
    /**
     * How long Mollie keeps an open first payment payable when it does not say so itself:
     * 15 minutes after creation (measured, see `SandboxBrowserTestCase::completeMollieCheckout()`).
     * Only the fallback for a payment without `expiresAt`; Mollie's own answer wins.
     */
    public const FIRST_PAYMENT_EXPIRY_MINUTES = 15;

    public function __construct(
        private readonly WebhookSupport $support,
    ) {
    }

    /**
     * Where to send the customer instead of creating a new first payment, or null to go
     * ahead and create it.
     *
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,coupon_code?:?string,amount_gross?:?int,upgrade_from_local?:bool,keep_grant?:bool}  $order
     *         what the new payment would carry. `amount_gross` is compared when given, for
     *         a payment that collects money (a code's effect shows in it); the code itself
     *         only on a €0 authorisation, where the amount cannot tell. `coupon_code` is
     *         the code the new payment would carry, a promotion link's included.
     *
     * @throws PendingPaymentForAnotherOrderException when the open payment is for another
     *         order and Mollie would not withdraw it
     */
    public function redirectFor(Billable $billable, array $order): ?string
    {
        if ($this->support->activationInFlight($billable)) {
            return BillingRoute::url('return', $billable);
        }

        $payment = $this->probe($billable);

        if ($payment === null) {
            return null;
        }

        $paymentId = (string) $billable->getPendingFirstPaymentId();
        $status = (string) ($payment->status ?? '');

        // Paid, and still on record: the webhook has not run its handlers yet (it drops
        // the marker once they have). A second payment now is a second charge for the
        // subscription this one is about to create.
        if ($status === 'paid') {
            return BillingRoute::url('return', $billable);
        }

        if (! in_array($status, ['open', 'pending'], true)) {
            return null;
        }

        $url = method_exists($payment, 'getCheckoutUrl') ? $payment->getCheckoutUrl() : null;

        if ($url) {
            if ($status === 'open' && ! $this->carriesOrder($payment, $order)) {
                $this->withdraw($billable, $paymentId, $payment, $url);

                return null;
            }

            return $url;
        }

        // A `pending` payment has already left the hosted page (SEPA, vouchers), so it
        // carries no checkout link — there is nothing to send the customer back to, but
        // there is also nothing to charge them for twice. Send them to the return page,
        // which is what polls this payment to completion.
        if ($status === 'pending') {
            return BillingRoute::url('return', $billable);
        }

        // `open` without a checkout link: the customer picked a method at Mollie and then
        // left through "previous page". Mollie drops the link at that point (measured
        // 20 November 2026: status `open`, method `creditcard`, no `_links.checkout`), so
        // nobody can pay this payment any more and it only waits for its expiry. Sending the
        // customer to the return page here was a dead end: that page polled the `open`
        // payment, and every new attempt at the checkout landed on it again. A fresh payment
        // is the way out; should the old one settle after all, the activation gate catches it.
        Log::info('Pending first payment can no longer be paid; starting a new one', [
            'billable_id' => $this->billableKey($billable),
            'payment_id' => $paymentId,
        ]);

        return null;
    }

    /**
     * The return page, when money (or a mandate) this billable handed over is on its way to
     * a subscription — whatever order a new checkout would carry. Null otherwise.
     *
     * The order-independent half of {@see redirectFor()}: an activation running or waiting
     * for its retry, or a payment on record that Mollie reports `paid` (the webhook has not
     * run its handlers yet). The checkout asks it when it opens, so a customer sent there by
     * `RequireActiveSubscription` during the activation is not walked through a form whose
     * submit can only lead to the return page. What depends on the order — an `open`
     * payment to go back to or to withdraw, a `pending` one — is left to the submit.
     *
     * A refused €0 authorisation and an activation that failed for good count as nothing in
     * flight (`WebhookSupport::activationInFlight()`), and a probe that fails answers null:
     * a customer must never be kept from the checkout by a lookup.
     */
    public function activationUnderway(Billable $billable): ?string
    {
        if ($this->support->activationInFlight($billable)) {
            return BillingRoute::url('return', $billable);
        }

        $payment = $this->probe($billable);

        return $payment !== null && (string) ($payment->status ?? '') === 'paid'
            ? BillingRoute::url('return', $billable)
            : null;
    }

    /** The first payment on record as Mollie reports it, or null (none, or the lookup failed). */
    private function probe(Billable $billable): ?object
    {
        $paymentId = $billable->getPendingFirstPaymentId();

        if ($paymentId === null || $paymentId === '') {
            return null;
        }

        try {
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));

            return is_object($payment) ? $payment : null;
        } catch (\Throwable $e) {
            Log::warning('Could not probe the pending first payment', [
                'billable_id' => $this->billableKey($billable),
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether the payment on record is for the order about to be placed.
     *
     * Judged on what activation reads: `FirstPaymentHandler` and `MandateOnlyPaymentHandler`
     * book plan, interval, addons, seats and coupon from the payment's own metadata, and the
     * customer is charged the payment's own amount. A payment whose metadata names no plan
     * is not one this package wrote for an order, so there is nothing to compare — it is
     * treated as the same checkout, as before.
     *
     * @param  array<string, mixed>  $order
     */
    private function carriesOrder(object $payment, array $order): bool
    {
        $metadata = is_object($payment->metadata ?? null)
            ? (json_decode((string) json_encode($payment->metadata), true) ?: [])
            : (array) ($payment->metadata ?? []);

        $authorisation = ($metadata['type'] ?? null) === 'mandate_only';
        $prefix = $authorisation ? 'pending_subscription_' : '';

        if ((string) ($metadata[$prefix.'plan_code'] ?? '') === '') {
            return true;
        }

        $stated = $this->normalise([
            'plan_code' => $metadata[$prefix.'plan_code'] ?? '',
            'interval' => $metadata[$prefix.'interval'] ?? '',
            'addon_codes' => $metadata[$prefix.'addon_codes'] ?? [],
            'extra_seats' => $metadata[$prefix.'extra_seats'] ?? 0,
            'upgrade_from_local' => $metadata[$prefix.'upgrade_from_local'] ?? false,
            'keep_grant' => $metadata['keep_grant'] ?? false,
            'coupon_code' => $authorisation ? ($metadata[$prefix.'coupon_code'] ?? null) : null,
        ]);

        $wanted = $this->normalise([
            ...$order,
            'coupon_code' => $authorisation ? ($order['coupon_code'] ?? null) : null,
        ]);

        if ($stated !== $wanted) {
            return false;
        }

        // An authorisation is €0 whatever the order costs; its order is the metadata alone.
        $value = $payment->amount->value ?? null;

        if ($authorisation || ! isset($order['amount_gross']) || $value === null) {
            return true;
        }

        $currency = (string) ($payment->amount->currency ?? config('mollie-billing.currency', 'EUR'));

        return (string) $value === BillingMoney::mollieAmount((int) $order['amount_gross'], $currency);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function normalise(array $order): array
    {
        $addons = array_map('strval', (array) ($order['addon_codes'] ?? []));
        sort($addons);

        return [
            'plan_code' => (string) ($order['plan_code'] ?? ''),
            'interval' => (string) ($order['interval'] ?? ''),
            'addon_codes' => array_values(array_unique($addons)),
            'extra_seats' => max(0, (int) ($order['extra_seats'] ?? 0)),
            'upgrade_from_local' => (bool) ($order['upgrade_from_local'] ?? false),
            'keep_grant' => (bool) ($order['keep_grant'] ?? false),
            'coupon_code' => strtoupper(trim((string) ($order['coupon_code'] ?? ''))),
        ];
    }

    /**
     * Take an open payment for an order the customer has left off the table, so it can
     * never be paid next to the new one.
     *
     * Not every method can be cancelled while open — measured on 26 November 2026, no open
     * first payment can (422, `isCancelable: false`). Then nothing new is created and the
     * customer is told until when the earlier payment stays open, with its checkout link to
     * finish it: a second payment beside a payable first one is exactly the double charge
     * this class exists to prevent. The cancel is sent only when Mollie does not say up front
     * that it would refuse it — one that reports `isCancelable: true` is withdrawn.
     *
     * @throws PendingPaymentForAnotherOrderException
     */
    private function withdraw(Billable $billable, string $paymentId, object $payment, ?string $checkoutUrl): void
    {
        $refused = fn () => new PendingPaymentForAnotherOrderException(
            $billable,
            $paymentId,
            $this->expiresAt($payment),
            $checkoutUrl !== '' ? $checkoutUrl : null,
        );

        if (($payment->isCancelable ?? null) === false) {
            Log::info('Open first payment for another order cannot be withdrawn; the customer waits for its expiry', [
                'billable_id' => $this->billableKey($billable),
                'payment_id' => $paymentId,
            ]);

            throw $refused();
        }

        try {
            MollieCall::send(new CancelPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::info('Open first payment for another order could not be withdrawn', [
                'billable_id' => $this->billableKey($billable),
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            throw $refused();
        }

        Log::info('Open first payment for another order withdrawn before a new one is created', [
            'billable_id' => $this->billableKey($billable),
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Until when the payment stays payable: Mollie's `expiresAt`, or — when the answer has
     * none — its `createdAt` plus {@see FIRST_PAYMENT_EXPIRY_MINUTES}. Null when neither
     * can be read.
     */
    private function expiresAt(object $payment): ?CarbonImmutable
    {
        foreach ([
            [$payment->expiresAt ?? null, 0],
            [$payment->createdAt ?? null, self::FIRST_PAYMENT_EXPIRY_MINUTES],
        ] as [$moment, $minutes]) {
            if (! is_string($moment) || $moment === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($moment)->utc()->addMinutes($minutes);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function billableKey(Billable $billable): mixed
    {
        return $billable instanceof Model ? $billable->getKey() : null;
    }
}

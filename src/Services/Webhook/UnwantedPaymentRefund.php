<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\Concerns\QueuesThrownRefund;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Returns a payment that arrived for something nobody wants any more — automatically.
 *
 * Money keeps arriving after the thing it was for is gone: a prorata debit settles days
 * after the customer cancelled the change, or after the subscription ended. Each of those
 * used to end in an admin mail saying "needs to be refunded manually". This is the one
 * place that does it instead, for any caller with such a payment in hand:
 *
 *     app(UnwantedPaymentRefund::class)->settle($payment, $billable, 'change_cancelled', 'Plan change …');
 *
 * **The payment is booked and credited, never refunded without a trace** — the same rule
 * `GrantExtrasPurchaseRefund` states, and the shape this was generalised from. Every
 * payment the package receives becomes an invoice; the refund becomes its credit note,
 * which carries the `mollie_refund_id` `RefundHandler` dedups on when Mollie reports the
 * refund through the payment webhook afterwards. The two documents net to zero in net and
 * VAT, at whatever rate the payment was collected — the gross is the one fact, and the
 * credit note reverses the invoice line for line.
 *
 * Safe to repeat, which a webhook has to be: the invoice is unique per payment, the
 * refund is skipped once it is credited or queued (`pending_refund_retries`, drained by
 * `RetryRefundLineJob`), and the announcements go out once per payment.
 *
 * Who hears about it: the customer, always (their money moved twice). The admins only
 * when the refund could not be posted — that is the case that may still need a person.
 * The event (`UnwantedPaymentRefunded`) is audited either way.
 *
 * `$reason` is a stable key: it is stored on the invoice line, carried by the event, and
 * selects the customer's sentence (`billing::notifications.unwanted_payment_refunded.reasons.*`,
 * with a generic fallback) — a new caller adds a key and two translations, nothing else.
 */
class UnwantedPaymentRefund
{
    use MutatesBillingMeta;
    use QueuesThrownRefund;

    /** Line flag marking the invoice of a payment that was not applied. */
    public const LINE_FLAG = 'payment_not_applied';

    /** Line kind of that invoice's single line. */
    public const LINE_KIND = 'unwanted_payment';

    /** `subscription_meta` key: payment id => when the refund was announced. */
    public const META_KEY = 'unwanted_payment_refunds';

    public const REASON_CHANGE_CANCELLED = 'change_cancelled';

    public const REASON_SUBSCRIPTION_ENDED = 'subscription_ended';

    /** Mollie collected a renewal for a subscription that no longer runs. */
    public const REASON_RENEWAL_AFTER_END = 'renewal_after_end';

    /** A first payment whose subscription could not be created after every retry. */
    public const REASON_ACTIVATION_FAILED = 'activation_failed';

    /** A second first payment, for a subscription another payment had already activated. */
    public const REASON_DUPLICATE_PAYMENT = 'duplicate_payment';

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $invoices,
        protected readonly VatCalculationService $vat,
    ) {
    }

    /**
     * Book the payment, return it in full, and say so — each at most once.
     *
     * Runs outside any transaction: it talks to Mollie.
     *
     * @param  string  $reason  stable key, e.g. {@see REASON_CHANGE_CANCELLED}
     * @param  string  $label  what the payment was for, as the invoice line states it
     * @param  array<string, mixed>  $context  extra keys for the invoice line (scalars)
     * @return array{invoice: BillingInvoice, credit_note: ?BillingInvoice}
     */
    public function settle(object $payment, Billable $billable, string $reason, string $label, array $context = []): array
    {
        /** @var Model&Billable $billable */
        $paymentId = (string) ($payment->id ?? '');

        $booked = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();
        $invoice = $booked ?? $this->bookPayment($payment, $billable, $reason, $label, $context);

        // An invoice this service booked on an earlier run, with no credit note and nothing
        // queued: that run may have reached Mollie before it died. Asked, not guessed.
        $this->earlierAttempt = self::isUnwanted($booked);
        $creditNote = $this->refund($billable, $invoice);

        $this->announceOnce($paymentId, $billable->refresh(), $invoice->refresh(), $creditNote, $reason);

        return ['invoice' => $invoice, 'credit_note' => $creditNote];
    }

    /**
     * Pick a refund up again on a re-delivery of the same payment.
     *
     * Returns true when the payment is one this service has booked — the caller is done.
     * The refund is posted if it has not been yet (and is not queued), nothing is
     * announced twice.
     */
    public function resume(object $payment, Billable $billable): bool
    {
        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) ($payment->id ?? ''))->first();

        if (! self::isUnwanted($invoice)) {
            return false;
        }

        $line = (array) ($invoice->line_items[0] ?? []);

        $this->settle($payment, $billable, (string) ($line['reason'] ?? 'default'), (string) ($line['label'] ?? ''));

        return true;
    }

    /**
     * The VAT rate on the document for a payment that is being returned.
     *
     * The money is exact whatever the rate — all of it is booked and all of it returned —
     * but the document should say what the customer was charged. Normally that is the rate
     * of the billing country. A billable whose address has been emptied since has none;
     * computing from "no country" took the fallback country's rate (19 % on a payment
     * priced at 20 %). In that case the rate is read off the last document the billable
     * was actually charged with, and only then off the fallback.
     *
     * @param  array<string, mixed>  $context
     */
    protected function rateThePaymentWasChargedAt(Billable $billable, int $gross, array $context): float
    {
        if (isset($context['vat_rate']) && is_numeric($context['vat_rate'])) {
            return (float) $context['vat_rate'];
        }

        $country = (string) ($billable->getBillingCountry() ?? '');

        // The stored country, not `getBillingCountry()`: an application's accessor may
        // already answer with a fallback of its own.
        if ($billable instanceof Model && (string) ($billable->getAttribute('billing_country') ?? '') === '') {
            $last = BillingInvoice::query()
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey())
                ->whereNotNull('mollie_payment_id')
                ->where('amount_gross', '>', 0)
                ->latest('id')
                ->first();

            foreach ((array) ($last?->line_items ?? []) as $line) {
                if (isset($line['vat_rate']) && (int) ($line['amount_net'] ?? $line['total_net'] ?? 0) > 0) {
                    return (float) $line['vat_rate'];
                }
            }
        }

        return (float) $this->vat->calculate($country, max(1, $gross), $billable)['rate'];
    }

    /** Whether this invoice records a payment that was not applied and is returned. */
    public static function isUnwanted(?BillingInvoice $invoice): bool
    {
        foreach ((array) ($invoice?->line_items ?? []) as $line) {
            if (($line[self::LINE_FLAG] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The invoice for the payment that came in: one line, for exactly the amount Mollie
     * collected.
     *
     * @param  array<string, mixed>  $context
     */
    protected function bookPayment(object $payment, Billable $billable, string $reason, string $label, array $context): BillingInvoice
    {
        /** @var Model&Billable $billable */
        // Asked before the fallback country is filled in: afterwards a billable without an
        // address looks like one in the fallback country.
        $gross = $this->support->amountFromMolliePayment($payment);
        $rateBeforeFallback = (string) ($billable->getAttribute('billing_country') ?? '') === ''
            ? $this->rateThePaymentWasChargedAt($billable, $gross, $context)
            : null;

        $this->support->ensureBillingCountry($billable);

        $rate = $rateBeforeFallback ?? $this->rateThePaymentWasChargedAt($billable, $gross, $context);

        // Net from the gross that was collected — the gross is the one fact here. The
        // split only has to be consistent with itself: the credit note reverses it line
        // for line, so net and VAT both come back to zero.
        $net = $this->support->netFromGross($gross, $rate);
        $now = BillingTime::nowUtc();

        return $this->invoices->createInvoice(
            billable: $billable,
            kind: InvoiceKind::Subscription,
            molliePaymentId: (string) $payment->id,
            mollieSubscriptionId: null,
            lineItems: [[
                ...$context,
                'kind' => self::LINE_KIND,
                'code' => null,
                'label' => $label,
                'quantity' => 1,
                'unit_price' => $net,
                'unit_price_net' => $net,
                'amount_net' => $net,
                'vat_rate' => $rate,
                'vat_amount' => $gross - $net,
                'amount_gross' => $gross,
                'period_start' => $now->toIso8601String(),
                'period_end' => $now->toIso8601String(),
                self::LINE_FLAG => true,
                'reason' => $reason,
            ]],
            periodStart: $now,
            periodEnd: $now,
            currency: BillingMoney::normalizeCurrency($payment->amount->currency ?? null),
        );
    }

    /**
     * Return the whole payment through `InvoiceService::createRefund()` — the path that
     * stamps the Mollie refund id onto the credit note and, when Mollie cannot be reached,
     * parks the line under `pending_refund_retries`. Null when no credit note exists yet.
     *
     * When createRefund() itself throws — the lock store, the credit note's write — the
     * line is parked by {@see QueuesThrownRefund} instead, so the retry happens whether or
     * not Mollie calls the webhook again.
     */
    protected function refund(Billable $billable, BillingInvoice $invoice): ?BillingInvoice
    {
        /** @var Model&Billable $billable */
        $existing = $this->creditNoteFor($invoice);

        if ($existing !== null || $invoice->remainingRefundableNet() <= 0 || $this->refundIsQueued($billable->refresh(), $invoice)) {
            return $existing;
        }

        $line = (array) ($invoice->line_items[0] ?? []);
        $now = BillingTime::nowUtc();

        $refundLine = new ProrataLine(
            originalInvoice: $invoice,
            originalLineItemIndex: 0,
            kind: self::LINE_KIND,
            code: null,
            label: (string) ($line['label'] ?? 'Payment not applied'),
            quantity: 1,
            amountNet: -(int) $invoice->amount_net,
            vatRate: (float) ($line['vat_rate'] ?? 0.0),
            amountVat: -(int) $invoice->amount_vat,
            amountGross: -(int) $invoice->amount_gross,
            periodStart: $now,
            periodEnd: $now,
            daysActive: 0,
            daysRemaining: 0,
            isCouponCovered: false,
            direction: 'refund',
        );

        // One refund per payment: every attempt carries the same token, so the same
        // idempotency key and the same metadata to find it by at Mollie.
        $refundLine->refundToken = self::fullRefundToken($invoice);
        $refundLine->refundOutcomeUnknown = $this->earlierAttempt;

        try {
            return $this->invoices->createRefund($billable, [$refundLine], 'Payment not applied — returned in full');
        } catch (\Throwable $e) {
            Log::error('Automatic refund of an unwanted payment threw', [
                'billable_id' => $billable->getKey(),
                'invoice_id' => $invoice->getKey(),
                'error' => $e->getMessage(),
            ]);

            $this->queueThrownRefund($billable, $invoice, $refundLine, $e);

            return null;
        }
    }

    protected function creditNoteFor(BillingInvoice $invoice): ?BillingInvoice
    {
        // Matched in PHP, not with a JSON-contains query: "an array of objects contains an
        // object with this key" is not something every supported database answers the same
        // way, and a billable has a handful of credit notes.
        return BillingInvoice::query()
            ->where('billable_type', $invoice->billable_type)
            ->where('billable_id', $invoice->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->latest('id')
            ->get()
            ->first(function (BillingInvoice $note) use ($invoice): bool {
                foreach ((array) ($note->line_items ?? []) as $line) {
                    if ((int) ($line['parent_invoice_id'] ?? 0) === (int) $invoice->getKey()) {
                        return true;
                    }
                }

                return false;
            });
    }

    protected function refundIsQueued(Billable $billable, BillingInvoice $invoice): bool
    {
        foreach ((array) ($billable->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []) as $entry) {
            $line = is_array($entry) ? (array) ($entry['line'] ?? $entry) : [];

            if ((int) ($line['parent_invoice_id'] ?? 0) === (int) $invoice->getKey()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Event, customer mail, and — only when the refund is not through — the admin mail:
     * once per payment. The marker is written first: a re-delivery arriving while a mailer
     * is slow must not send a second mail, and a mail that failed to send is the lesser
     * harm.
     */
    protected function announceOnce(string $paymentId, Billable $billable, BillingInvoice $invoice, ?BillingInvoice $creditNote, string $reason): void
    {
        /** @var Model&Billable $billable */
        $first = false;

        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId, &$first): array {
            $seen = (array) ($meta[self::META_KEY] ?? []);

            if (! array_key_exists($paymentId, $seen)) {
                $first = true;
                $seen[$paymentId] = BillingTime::nowUtc()->toIso8601String();
                $meta[self::META_KEY] = $seen;
            }

            return $meta;
        });

        if (! $first) {
            return;
        }

        Log::info('Unwanted payment returned automatically', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $paymentId,
            'invoice_id' => $invoice->getKey(),
            'credit_note_id' => $creditNote?->getKey(),
            'reason' => $reason,
        ]);

        $this->support->safely(
            fn () => event(new UnwantedPaymentRefunded($billable, $invoice, $creditNote, $paymentId, $reason)),
            'unwanted_payment_refund.event',
            $billable,
        );

        $this->support->safely(function () use ($billable, $invoice, $reason): void {
            $notification = MollieBilling::resolveNotification(
                UnwantedPaymentRefundedNotification::class,
                $billable,
                (int) $invoice->amount_gross,
                (string) $invoice->currency,
                $reason,
            );

            $recipients = MollieBilling::notifyBillingAdmins($billable);
            $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

            if ($recipients !== []) {
                Notification::send($recipients, $notification);

                return;
            }

            $email = $billable->getBillingEmail();

            if (is_string($email) && $email !== '') {
                Notification::route('mail', $email)->notify($notification);
            }
        }, 'unwanted_payment_refund.customer_notification', $billable);

        // Only when the automatic way is not through: the refund is queued for retry and
        // may end in the dead letter, which is when a person has to look.
        if ($creditNote === null) {
            // The refund did not go through and is queued (`pending_refund_retries`). That
            // is the automatic way still running, not its end: no operator is asked to
            // do anything. The event above carries a null credit note, so the audit trail
            // says "queued"; `RetryRefundLineJob` tells an admin — once — when its
            // retries are used up and the line moves to the dead letter.
            //
            // Said only when it is true: the queue write can fail as well, and then the
            // refund waits for Mollie's next delivery of the webhook (`resume()`).
            $queued = $this->refundIsQueued($billable->refresh(), $invoice);

            Log::warning($queued
                ? 'Automatic refund of an unwanted payment is queued for retry'
                : 'Automatic refund of an unwanted payment is not through and could not be queued — it is posted on the next webhook delivery', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->getKey(),
                    'reason' => $reason,
                ]);
        }
    }
}

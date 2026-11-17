<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\GrantExtrasPurchaseRefunded;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\GrantExtrasPurchaseRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * A purchase of paid extras on a gifted plan whose gift is gone when the payment settles.
 *
 * `UpgradeLocalToMollie::handleKeepingGrant()` prices "the extras, the plan stays free"
 * and the tenant agrees to that. With SEPA the payment arrives days later, and by then the
 * gift can have run out, been revoked, or been expired by the nightly pass. There is
 * nothing left to keep — and carrying the purchase out anyway would create a subscription
 * at the full price: plan and addon from the next period on, which nobody agreed to.
 *
 * So the purchase is not carried out and the money goes back. No subscription, no waiver,
 * no change to plan, addons or seats; the billable is where it would be without the
 * purchase.
 *
 * **The payment is booked and credited, not refunded without a trace.** Every payment the
 * package receives becomes an invoice — that is what `CleanupOrphanedBillablesJob`, the
 * admin panel and the customer's own invoice list read as "money came in" — and the refund
 * becomes its credit note, which carries the `mollie_refund_id` that `RefundHandler`
 * dedups on when Mollie reports the refund through the payment webhook afterwards. The two
 * documents net to zero in net and VAT, so the OSS export nets to zero as well. A silent
 * refund would leave a bank statement with a debit and a credit and no document for
 * either, and the later refund webhook with nothing to recognise the refund by.
 *
 * Every step is safe to repeat: the invoice is unique per payment, the refund is skipped
 * once it is credited or queued, and the announcements are sent once per payment.
 */
class GrantExtrasPurchaseRefund
{
    use MutatesBillingMeta;

    /** Line flag marking the invoice of a purchase that was not carried out. */
    public const LINE_FLAG = 'purchase_not_executed';

    /** `subscription_meta` key: payment id => when the refusal was announced. */
    public const META_KEY = 'grant_extras_refunds';

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $invoices,
        protected readonly VatCalculationService $vat,
    ) {
    }

    /**
     * Whether the gift this purchase was priced on still runs: an active Local
     * subscription that ends in the future, backed by a grant that was not revoked.
     */
    public function grantStillRuns(Billable $billable): bool
    {
        if (! ($billable instanceof Model) || ! $billable->isLocalBillingSubscription()) {
            return false;
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();

        if ($endsAt === null
            || ! $endsAt->isFuture()
            || $billable->getBillingSubscriptionStatus() !== SubscriptionStatus::Active
        ) {
            return false;
        }

        return CouponRedemption::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            ->where('grant_applied_snapshot->mode', 'full')
            ->exists();
    }

    /** Whether this invoice records a purchase that was refused and refunded. */
    public static function isRefusedPurchase(?BillingInvoice $invoice): bool
    {
        foreach ((array) ($invoice?->line_items ?? []) as $line) {
            if (($line[self::LINE_FLAG] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Book the payment, return it, and tell everyone — each of them at most once.
     *
     * Runs outside any transaction: it talks to Mollie.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function settle(object $payment, Billable $billable, array $metadata): void
    {
        /** @var Model&Billable $billable */
        $paymentId = (string) ($payment->id ?? '');

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first()
            ?? $this->bookPayment($payment, $billable, $metadata);

        $creditNote = $this->refund($billable, $invoice);

        $this->announceOnce($payment, $billable->refresh(), $invoice->refresh(), $creditNote);
    }

    /**
     * The invoice for the payment that came in: one line, for exactly the amount Mollie
     * collected. It names what was ordered and says it was not carried out.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function bookPayment(object $payment, Billable $billable, array $metadata): BillingInvoice
    {
        /** @var Model&Billable $billable */
        $this->support->ensureBillingCountry($billable);

        $gross = $this->support->amountFromMolliePayment($payment);
        $rate = (float) $this->vat->calculate((string) $billable->getBillingCountry(), max(1, $gross), $billable)['rate'];

        // Net from the gross that was collected — the gross is the one fact here. The
        // split only has to be consistent with itself: the credit note reverses it line
        // for line, so net and VAT both come back to zero.
        $net = $this->support->netFromGross($gross, $rate);
        $now = BillingTime::nowUtc();
        $planCode = (string) ($metadata['plan_code'] ?? '');

        return $this->invoices->createInvoice(
            billable: $billable,
            kind: InvoiceKind::Subscription,
            molliePaymentId: (string) $payment->id,
            mollieSubscriptionId: null,
            lineItems: [[
                'kind' => 'grant_extras',
                'code' => $planCode !== '' ? $planCode : null,
                'label' => 'Extras on '.($planCode !== '' ? $planCode : 'the gifted plan').' — purchase not carried out, the free period had ended',
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
                'addon_codes' => array_values(array_map('strval', (array) ($metadata['addon_codes'] ?? []))),
                'extra_seats' => (int) ($metadata['extra_seats'] ?? 0),
            ]],
            periodStart: $now,
            periodEnd: $now,
            currency: BillingMoney::normalizeCurrency($payment->amount->currency ?? null),
        );
    }

    /**
     * Return the whole payment through `InvoiceService::createRefund()` — the path that
     * holds the per-invoice refund lock, stamps the Mollie refund id onto the credit note
     * and, when Mollie cannot be reached, parks the line under `pending_refund_retries`
     * for `RetryRefundLineJob`. Null when no credit note exists yet.
     */
    protected function refund(Billable $billable, BillingInvoice $invoice): ?BillingInvoice
    {
        /** @var Model&Billable $billable */
        $existing = $this->creditNoteFor($invoice);

        if ($existing !== null || $invoice->remainingRefundableNet() <= 0 || $this->refundIsQueued($billable, $invoice)) {
            return $existing;
        }

        $line = (array) ($invoice->line_items[0] ?? []);
        $now = BillingTime::nowUtc();

        try {
            return $this->invoices->createRefund($billable, [new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: 0,
                kind: 'grant_extras',
                code: isset($line['code']) ? (string) $line['code'] : null,
                label: (string) ($line['label'] ?? 'Purchase not carried out'),
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
            )], 'Purchase of extras not carried out — the free period had ended');
        } catch (\Throwable $e) {
            Log::error('Refund of a refused grant-extras purchase threw', [
                'billable_id' => $billable->getKey(),
                'invoice_id' => $invoice->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function creditNoteFor(BillingInvoice $invoice): ?BillingInvoice
    {
        return BillingInvoice::query()
            ->where('billable_type', $invoice->billable_type)
            ->where('billable_id', $invoice->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->whereJsonContains('line_items', [['parent_invoice_id' => (int) $invoice->getKey()]])
            ->latest('id')
            ->first();
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
     * Event, customer mail, admin mail, mandate clean-up, after-checkout hook — once per
     * payment. The marker is written first: a re-delivery arriving while a mailer is slow
     * must not send a second mail, and a mail that failed to send is the lesser harm.
     */
    protected function announceOnce(object $payment, Billable $billable, BillingInvoice $invoice, ?BillingInvoice $creditNote): void
    {
        /** @var Model&Billable $billable */
        $paymentId = (string) ($payment->id ?? '');
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

        Log::warning('Grant-extras purchase not carried out — the gift had ended; payment refunded', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $paymentId,
            'invoice_id' => $invoice->getKey(),
            'credit_note_id' => $creditNote?->getKey(),
        ]);

        $this->support->safely(
            fn () => event(new GrantExtrasPurchaseRefunded($billable, $invoice, $creditNote, $paymentId)),
            'grant_extras_refund.event',
            $billable,
        );

        $this->support->safely(function () use ($billable, $invoice): void {
            $notification = MollieBilling::resolveNotification(
                GrantExtrasPurchaseRefundedNotification::class,
                $billable,
                (int) $invoice->amount_gross,
                (string) $invoice->currency,
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
        }, 'grant_extras_refund.customer_notification', $billable);

        // No admin mail here — neither when the refund went through (it resolved itself)
        // nor when it did not: a refund Mollie would not take is queued
        // (`pending_refund_retries`), which is the automatic way still running. The event
        // above carries a null credit note, so the audit trail says "queued", and
        // `RetryRefundLineJob` tells an admin — once — when the retries are used up and the
        // line moves to the dead letter. Same rule as `UnwantedPaymentRefund`.
        if ($creditNote === null) {
            \Illuminate\Support\Facades\Log::warning('Refund of a grant-extras purchase is queued for retry', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'invoice_id' => $invoice->getKey(),
            ]);
        }

        // The first payment captured a mandate at Mollie. The billable does not adopt it —
        // a mandate on file is what denies a later checkout its trial — so it is revoked
        // rather than left behind unreferenced.
        $this->support->safely(function () use ($payment, $billable): void {
            $customerId = (string) ($payment->customerId ?? $billable->getMollieCustomerId() ?? '');
            $mandateId = (string) ($payment->mandateId ?? '');

            if ($customerId !== '' && $mandateId !== '' && $mandateId !== (string) $billable->getMollieMandateId()) {
                RevokeMollieMandateJob::dispatch($customerId, $mandateId);
            }
        }, 'grant_extras_refund.revoke_mandate', $billable);

        $this->support->safely(
            fn () => MollieBilling::runAfterCheckout($billable, false),
            'grant_extras_refund.after_checkout',
            $billable,
        );
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\CarbonInterface;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SingleChargeHandler
{
    use MutatesBillingMeta;

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly UpdateSubscription $updateSubscription,
        protected readonly WalletUsageService $walletService,
        protected readonly VatCalculationService $vatService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for single charge, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
                'type' => $type,
            ]);
            return;
        }

        // The charge was priced with the fallback country when the column was empty; the
        // invoice has to be able to use the same one, or the money is collected and never
        // invoiced. See WebhookSupport::ensureBillingCountry().
        $this->support->ensureBillingCountry($billable);

        $lineItems = (array) ($metadata['line_items'] ?? []);
        if (empty($lineItems)) {
            // No `line_items` metadata means a payment created before this package
            // version — in flight across an upgrade. All it carries is Mollie's
            // GROSS, and `createForPayment()` adds VAT on top of whatever net it is
            // given, so booking the gross as net charged VAT twice: 11.90 collected
            // at 19% became an invoice of net 1190 / VAT 226 / gross 1416.
            $gross = $this->support->amountFromMolliePayment($payment);
            $rate = (float) ($this->vatService->calculate(
                (string) ($billable->getBillingCountry() ?? ''),
                $gross,
                $billable,
            )['rate'] ?? 0.0);
            $net = $this->support->netFromGross($gross, $rate);

            $lineItems = [[
                'kind' => $type,
                'label' => ucfirst($type).' charge',
                'quantity' => 1,
                'unit_price_net' => $net,
                'unit_price' => $net,
                'total_net' => $net,
            ]];
        }

        // Reconciled against the money that cleared, like every sibling handler. This was
        // the one paid path that skipped it: the metadata carries nets only, and
        // `createForPayment()` recomputes VAT from the billable's country *now* — so a
        // customer who validated a VAT number while the SEPA payment settled (days, for
        // SEPA) turned a 10.00 + 19 % = 11.90 charge into an invoice of 10.00/0/10.00
        // against 11.90 collected. 1.90 then sat on no document, `refunded_net` headroom
        // was short of the cash, and the DE→AT variant booked MORE than was collected so
        // a full refund would be rejected. Silently, since nothing fired.
        //
        // Two parts. The rate the charge was computed with is pinned onto the lines, so
        // `createForPayment()` invoices at that rate instead of today's — a DE 19 % charge
        // must not be re-priced at AT 20 % just because the customer moved while the
        // payment settled. And the result is reconciled against the money that cleared,
        // which covers the case the pinning cannot: a country lookup that now says
        // zero-rated forces every line to 0 (deliberately — see createForPayment), so the
        // difference lands as an adjustment line rather than vanishing.
        // Each overage line's usage period comes from the snapshot, matched by position —
        // so before the reconciliation below, which may append an adjustment line and
        // break that one-to-one correspondence.
        if ($type === 'overage') {
            $lineItems = $this->overageLinePeriods($payment, $billable, $lineItems);
        }

        $chargedRate = $this->chargedVatRate($billable, $type);
        $lineItems = $this->pinVatRate($lineItems, $chargedRate);

        $lineItems = $this->support->reconcileWithPaidAmount(
            $payment,
            $billable,
            $lineItems,
            $this->support->netFromLineItems($lineItems),
            $this->effectiveVatRate($billable, $chargedRate),
        );

        [$periodStart, $periodEnd] = $type === 'overage'
            ? $this->overageUsagePeriod($payment, $billable)
            : [null, null];

        $invoice = $this->salesInvoiceService->createForPayment($payment, $type, $lineItems, $billable, $periodStart, $periodEnd);

        if ($type === 'overage') {
            // Locked read-modify-write. `reserve()` serializes only per payment id, and
            // the overage charge settles the day before the renewal *by design* — so this
            // handler and SubscriptionPaymentHandler can run in the same second. Reading
            // the whole meta blob and writing it back let the renewal's save resurrect the
            // keys cleared here, after which PrepareUsageOverageJob treated the paid
            // charge as still in flight and skipped that billable's overage collection
            // indefinitely.
            //
            // `usage_overage_counted_payments` goes too: the dunning budget is a list of
            // payment ids, and while a stale list is ignored (it is scoped to its
            // snapshot's created_at) leaving it behind accumulates dead meta.
            // Wallets first, markers second. A hard kill between the two is not catchable,
            // and the two orders fail very differently: clearing first leaves the debt on the
            // wallet with no snapshot and no marker, so nothing can match this payment again
            // and the next sweep charges the same units a second time. Crediting first leaves
            // a `pending` snapshot whose invoice already exists — which
            // `pendingChargeIsSettled()` and `CleanupStalePendingOverageChargeJob::markSpent()`
            // both already recognise and clean up.
            //
            // Wrapped: the invoice exists by now, so a throw here would be swallowed by
            // the re-delivery's invoice guard and the customer would have paid for units
            // that were never credited.
            try {
                $this->settleOverageWallets($billable, $lineItems);
            } catch (\Throwable $e) {
                Log::error('Wallet settlement after a paid overage charge failed', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $payment->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->mutateBillingMeta($billable, static function (array $meta): array {
                unset(
                    $meta['usage_overage'],
                    $meta['usage_overage_status'],
                    $meta['usage_overage_attempts'],
                    $meta['usage_overage_counted_payments'],
                );

                return $meta;
            });

            // A subscription that was past due because this debt stood is released —
            // unless a renewal is unpaid as well (see releasePastDueForDebt()).
            app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class)->releasePastDueForDebt($billable);

            event(new OverageCharged($billable, $invoice, $lineItems));
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true)) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['prorata_pending_payment_id']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            $billable->refresh();
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;

            Log::info('Prorata payment processed, checking for pending plan change', [
                'billable' => $billable->getKey(),
                'has_pending' => ! empty($pendingChange),
                'pending_plan' => $pendingChange['plan_code'] ?? null,
            ]);

            if (! empty($pendingChange)) {
                try {
                    $this->updateSubscription->applyPendingPlanChange($billable, $invoice);
                } catch (\Throwable $e) {
                    Log::error('Failed to apply pending plan change after prorata payment', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);

                    $this->updateSubscription->clearPendingPlanChange($billable);
                    $billable->refresh();

                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                    $meta['plan_change_failed_reason'] = $e->getMessage();
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $e->getMessage()));

                    $recipients = MollieBilling::notifyBillingAdmins($billable);
                    if (! empty($recipients)) {
                        Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
                    }
                }
            }
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->support->notifyInvoiceAvailable($billable, $invoice);
    }

    /**
     * Stamp a rate onto every line that does not carry one of its own.
     *
     * `createForPayment()` honours a per-line `vat_rate` (that is how mixed-rate
     * invoices work), and the overage metadata carries nets only — so without this the
     * invoice was priced at whatever the billable's country resolves to *now*.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    protected function pinVatRate(array $lineItems, float $rate): array
    {
        return array_map(static function (array $line) use ($rate): array {
            $line['vat_rate'] ??= $rate;

            return $line;
        }, $lineItems);
    }

    /**
     * The rate the invoice will actually be written at.
     *
     * `createForPayment()` clamps every line to 0 when the country lookup says the supply
     * is zero-rated, and that clamp beats a pinned rate on purpose — a webhook must not
     * re-introduce tax the classification ruled out. So the reconciliation has to expect
     * the same 0, or it would compute a gross the invoice never reaches and leave the
     * difference unbooked.
     */
    protected function effectiveVatRate(Billable $billable, float $chargedRate): float
    {
        try {
            $liveRate = (float) ($this->vatService->calculate(
                (string) ($billable->getBillingCountry() ?? ''),
                0,
                $billable,
            )['rate'] ?? 0.0);
        } catch (\Throwable) {
            // An unresolvable country (VIES down, a country the calculator refuses) must
            // not take the webhook down with it — this probe only decides whether the
            // invoice will be zero-rated. Assume it will not, and let the reconciliation
            // catch any resulting difference against the money that cleared.
            return $chargedRate;
        }

        return abs($liveRate) < 0.0001 ? 0.0 : $chargedRate;
    }

    /**
     * The VAT rate this charge was actually computed with.
     *
     * `ChargeUsageOverageDirectly::persistPendingCharge()` snapshots it alongside the
     * amounts, which makes it the right answer even when the billable's classification
     * changed while the payment settled. The live rate is the fallback for charges that
     * carry no snapshot (the legacy mid-cycle types, and payments created before the
     * snapshot existed).
     */
    /**
     * The usage period of each line, from the snapshot of this payment's cycle.
     *
     * The payment's metadata carries the lines without their periods (Mollie caps it at
     * 1024 bytes), the snapshot carries the same lines, in the same order, with them. A
     * charge that collects a standing debt together with new overage has two periods,
     * and each line is invoiced for its own. Lines are matched by position only when both
     * lists have the same length; otherwise the invoice period covers them.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    private function overageLinePeriods(object $payment, Billable $billable, array $lineItems): array
    {
        $snapshot = $this->overageSnapshotFor($payment, $billable);
        $snapshotLines = array_values((array) ($snapshot['line_items'] ?? []));
        $lineItems = array_values($lineItems);

        if ($snapshotLines === [] || count($snapshotLines) !== count($lineItems)) {
            return $lineItems;
        }

        foreach ($lineItems as $index => $line) {
            $stamp = (array) ($snapshotLines[$index] ?? []);

            if (! empty($stamp['period_start']) && ! empty($stamp['period_end']) && empty($line['period_start'])) {
                $lineItems[$index]['period_start'] = (string) $stamp['period_start'];
                $lineItems[$index]['period_end'] = (string) $stamp['period_end'];
            }
        }

        return $lineItems;
    }

    /**
     * The pending overage snapshot, when this payment belongs to its dunning cycle.
     *
     * @return array<string, mixed>|null
     */
    private function overageSnapshotFor(object $payment, Billable $billable): ?array
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $snapshot = (array) ($meta['usage_overage'] ?? []);
        $paymentId = (string) ($payment->id ?? '');
        $cyclePaymentIds = (array) (($meta['usage_overage_counted_payments'] ?? [])['payment_ids'] ?? []);

        $belongsToCycle = $paymentId !== ''
            && (($snapshot['payment_id'] ?? null) === $paymentId || in_array($paymentId, $cyclePaymentIds, true));

        return $belongsToCycle ? $snapshot : null;
    }

    /**
     * The period the overage was used in — not the billable's period when the payment
     * settles. The overage of a period is charged the day before the renewal; a SEPA
     * payment settles days later, after the renewal has moved the period on, and a retry
     * of a failed charge can be created in the next period altogether. Left to
     * `createForPayment()`, both stated the new period.
     *
     * 1. The pending snapshot, when it belongs to this payment's cycle: it records the
     *    period when the cycle starts (ChargeUsageOverageDirectly::persistPendingCharge()).
     * 2. Otherwise — a payment from before the snapshot carried a period — the period the
     *    payment was created in: one interval back from the current anchor if Mollie
     *    created it before that anchor.
     * 3. Otherwise null, and `createForPayment()` falls back to the billable's period.
     *
     * @return array{0:?CarbonInterface, 1:?CarbonInterface}
     */
    private function overageUsagePeriod(object $payment, Billable $billable): array
    {
        $snapshot = $this->overageSnapshotFor($payment, $billable) ?? [];

        if (! empty($snapshot['period_start']) && ! empty($snapshot['period_end'])) {
            try {
                return [BillingTime::toUtc((string) $snapshot['period_start']), BillingTime::toUtc((string) $snapshot['period_end'])];
            } catch (\Throwable) {
                // Unreadable: fall through to the payment's creation time.
            }
        }

        $anchor = $billable->getBillingPeriodStartsAt();
        $createdAt = $payment->createdAt ?? null;

        if ($anchor !== null && is_string($createdAt) && $createdAt !== '') {
            try {
                $created = BillingTime::toUtc($createdAt);
            } catch (\Throwable) {
                return [null, null];
            }

            if ($created->lessThan($anchor)) {
                return [BillingTime::subInterval($anchor, $billable->getBillingSubscriptionInterval()), $anchor->copy()];
            }
        }

        return [null, null];
    }

    protected function chargedVatRate(Billable $billable, string $type): float
    {
        if ($type === 'overage') {
            $snapshot = (array) ($billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? []);

            if (isset($snapshot['vat_rate'])) {
                return (float) $snapshot['vat_rate'];
            }
        }

        try {
            return (float) ($this->vatService->calculate(
                (string) ($billable->getBillingCountry() ?? ''),
                0,
                $billable,
            )['rate'] ?? 0.0);
        } catch (\Throwable) {
            // No snapshot and no resolvable rate: leave the lines unpinned so
            // createForPayment() decides, exactly as before this reconciliation existed.
            return 0.0;
        }
    }

    /**
     * The overage charge settled, so the units the customer just paid for are no
     * longer owed: credit them back so the negative balance disappears. Without
     * this the customer pays cash for the units and still loses them from the
     * next period's quota (rollover types add the new quota on top of the
     * negative balance), a plan change in the same window feeds the already-paid
     * negative balance into WalletPlanChangeAdjuster and charges it a second
     * time, and for non-rollover types the renewal reset makes a failed charge
     * indistinguishable from a paid one.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    protected function settleOverageWallets(Billable $billable, array $lineItems): void
    {
        foreach ($lineItems as $line) {
            if (($line['kind'] ?? 'overage') !== 'overage') {
                continue;
            }

            // A plan-change overage is charged for units the adjuster has already offset
            // against the new quota — it clamps the wallet to 0 in the same breath — so
            // there is no negative balance for this credit to clear. Crediting anyway
            // handed the customer the charged quantity for free, and a rollover type kept
            // it for good. Only a period-end charge, where the balance is still negative,
            // settles a wallet.
            if (($line['settles_wallet'] ?? true) === false) {
                continue;
            }

            $usageType = (string) ($line['type'] ?? $line['code'] ?? '');
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($usageType === '' || $quantity <= 0) {
                continue;
            }

            try {
                $this->walletService->credit($billable, $usageType, $quantity, 'overage_settlement');

                // Units billed from a deferred-overage record are paid for now, so the
                // record goes with them — see WalletUsageService::consumeDeferredOverage().
                if (($line['deferred_price'] ?? false) === true && $billable instanceof Model) {
                    $wallet = $billable->getWallet($usageType);

                    if ($wallet !== null) {
                        WalletUsageService::consumeDeferredOverage($wallet, $quantity, (int) ($line['unit_price_net'] ?? 0));
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Wallet credit after a paid overage charge failed — negative balance stays and would be charged again', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'usage_type' => $usageType,
                    'quantity' => $quantity,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof Model) {
            // The FQCN, not getMorphClass(): RetryUsageOverageChargeJob resolves
            // the billable via `class_exists($this->billableClass)` and returns
            // silently otherwise, so a morph alias would make the retry (and with
            // it the attempt counting, past_due transition and notification) a
            // permanent no-op. Mirrors PrepareUsageOverageJob.
            RetryUsageOverageChargeJob::dispatch(
                $billable::class,
                $billable->getKey(),
                1,
            );
            return;
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true) && $billable instanceof Model) {
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
            $reason = (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown');

            $this->updateSubscription->clearPendingPlanChange($billable);

            if ($pendingChange) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                $meta['plan_change_failed_reason'] = $reason;
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $reason));

                $recipients = MollieBilling::notifyBillingAdmins($billable);
                if (! empty($recipients)) {
                    Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
                }
            }
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }
}

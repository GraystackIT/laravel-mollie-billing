<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\RenewalCollectedForEndedSubscription;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WebhookSupport
{
    use MutatesBillingMeta;

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
    /**
     * Credit a newly paid period's included quota into the wallets.
     *
     * The renewal's own refill, shared so a paid trial conversion lands the customer in
     * exactly the state the first ordinary renewal would have. The conversion used to go
     * through the plan-change adjuster or, when neither plan nor interval moved, through
     * nothing at all — so a customer who changed seats mid-trial paid for a full period
     * and kept the trial's prorated wallet for it.
     *
     * Rollover types add on top; the rest are reset and re-credited, preserving a debt an
     * overage charge is still collecting (see `resetAndCredit`). One failing wallet must
     * not cost the others, so each is isolated.
     *
     * The reset never zeroes a debt that is still owed — see {@see shouldPreserveDebt()}.
     * Callers on a path where a Mollie call is allowed run
     * {@see collectOutstandingOverage()} first, so the debt is normally a charge in
     * flight by the time this looks at it.
     */
    public function creditPeriodQuota(
        Billable $billable,
        string $planCode,
        ?string $interval,
        string $reason,
    ): void {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $walletService = app(WalletUsageService::class);

        foreach ($catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                if ($catalog->usageRollover((string) $type)) {
                    // Clamps the purchased balance before crediting — see creditRollover().
                    $walletService->creditRollover($billable, (string) $type, (int) $units);
                } else {
                    $walletService->resetAndCredit(
                        $billable,
                        (string) $type,
                        (int) $units,
                        $reason,
                        preserveDebt: $this->shouldPreserveDebt($billable, (string) $type),
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Charge whatever overage the wallets still owe, before a period reset looks at them.
     *
     * The period-end sweep is the ordinary collector, and it has exactly one chance: the
     * night before `nextBillingDate()`. Every way of missing that night used to end the
     * same way — the renewal found a negative balance with no charge in flight and reset
     * it to the new quota, so the overage was simply given away:
     *
     *  - a trial. Its `nextBillingDate()` is mandate capture plus one interval, the trial
     *    ends (and converts) long before that, and `debit()` allows overage throughout
     *    because the billable holds a mandate;
     *  - a renewal that settles before the sweep night. The anchor is the previous
     *    renewal's `paidAt`, and with SEPA that trails Mollie's fixed collection date by a
     *    lag that varies — a shorter lag this period puts the webhook ahead of the sweep;
     *  - a night on which the sweep did not run at all.
     *
     * So the renewal collects for itself. `ChargeUsageOverageDirectly::handle()` carries
     * the in-flight guard, which makes this a no-op whenever the sweep did its job; and
     * once the charge exists its snapshot is what `hasUnsettledOverageFor()` reads, so the
     * reset preserves the debt and the settlement clears it — the existing mechanism,
     * entered one step later.
     *
     * Never throws: the renewal it runs inside has already booked its invoice.
     * Posts a Mollie payment, so it must not be called inside a transaction.
     */
    public function collectOutstandingOverage(Billable $billable): void
    {
        if (! $billable instanceof Model || ! $billable->hasMollieMandate()) {
            return;
        }

        try {
            // The guard reads the snapshot off the model, and this one was hydrated before
            // the invoice work — long enough for the nightly sweep to have claimed the slot.
            $fresh = $billable->newQuery()
                ->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
                ->whereKey($billable->getKey())
                ->first();

            if ($fresh instanceof Model) {
                // Only this column, and without marking it dirty: the caller keeps working
                // with the model and must not write this read back over the row.
                $billable->setRawAttributes(array_merge(
                    $billable->getAttributes(),
                    ['subscription_meta' => $fresh->getRawOriginal('subscription_meta')],
                ));
                $billable->syncOriginalAttribute('subscription_meta');
            }

            // A debt left standing by a cycle that gave up is collected again from here:
            // `handle()` starts one fresh cycle for it per renewal, once it has rested
            // (ChargeUsageOverageDirectly::RECOLLECT_AFTER_DAYS), until the renewals'
            // attempts are spent and it is written off.
            app(ChargeUsageOverageDirectly::class)->handle($billable);
        } catch (\Throwable $e) {
            // `charge()` has already recorded what it knows: a snapshot, and the
            // payment-unknown marker if the request left the process. Either keeps the
            // debt through the reset; a throw before that leaves shouldPreserveDebt() to.
            Log::warning('Overage charge at renewal failed — the debt is carried into the new period', [
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether a period reset has to leave this wallet's negative balance standing.
     *
     * Two cases. A charge for these units is in flight — forgiving the debt and then
     * crediting the charged quantity at settlement hands the units out twice. Or nothing
     * is in flight but the debt is one that can still be collected: then zeroing it is
     * simply giving it away, and it is carried instead, where it is paid out of the new
     * period's quota or, if it exceeds that, stays negative for the next sweep.
     *
     * The one debt that is NOT carried is a cycle that already gave up: it was written
     * off with the past-due transition, and preserving it would strand the wallet.
     */
    public function shouldPreserveDebt(Billable $billable, string $usageType): bool
    {
        return $this->hasUnsettledOverageFor($billable, $usageType)
            || $this->owesCollectableOverage($billable, $usageType);
    }

    /**
     * A negative balance nobody has charged yet, on a billable it can be charged to.
     */
    public function owesCollectableOverage(Billable $billable, string $usageType): bool
    {
        if (! $billable instanceof Model || ! $billable->hasMollieMandate()) {
            return false;
        }

        // No exception for a failed cycle any more: its debt has left the wallet (it
        // stands in the snapshot instead), so a negative balance here is new overage.
        $wallet = $billable->getWallet($usageType);

        if ($wallet === null || (int) $wallet->balanceInt >= 0) {
            return false;
        }

        // Debt with a recorded price is owed whatever the current plan charges.
        if (WalletUsageService::deferredOverageQuantity($wallet) > 0) {
            return true;
        }

        return ! $billable->isLocalBillingSubscription()
            && ($billable->billingOveragePrice($usageType) ?? 0) > 0;
    }

    /**
     * Whether an overage charge covering this usage type is created but not settled.
     *
     * Read off the snapshot `ChargeUsageOverageDirectly` persists, which lists exactly
     * the line items it charged for — so a type the charge did not cover still gets its
     * ordinary reset. A snapshot whose cycle already gave up (`STATUS_FAILED`) is not
     * "in flight": its debt has been taken off the wallet to stand as a receivable (see
     * `ChargeUsageOverageDirectly::deferFailedCycle()`), so there is nothing to preserve.
     *
     * A charge for the units being re-credited may still be settling — with SEPA it
     * usually is, since Mollie creates the renewal about two days ahead while the overage
     * charge is created the day before it. Forgiving the debt and then crediting the
     * charged quantity when the overage settles handed the customer that quantity for
     * free, every cycle. Rollover types are unaffected: they never zero the balance.
     */
    public function hasUnsettledOverageFor(Billable $billable, string $usageType): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
            return false;
        }

        $lines = (array) ($meta[ChargeUsageOverageDirectly::META_KEY]['line_items'] ?? []);

        foreach ($lines as $line) {
            // A line that does not settle the wallet has no units on it to preserve: a
            // plan-change remainder, or a standing debt being collected again.
            if (($line['settles_wallet'] ?? true) === false) {
                continue;
            }

            $type = (string) ($line['type'] ?? $line['code'] ?? '');

            if (strcasecmp($type, $usageType) === 0 && (int) ($line['quantity'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

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
     * Total charged back on this payment, in the payment's own minor units.
     *
     * Monotonic per payment like the refunded total, which is what makes it usable in
     * the dedup signature. A chargeback does not change the payment's status — it only
     * appears here — so this value is the only thing that distinguishes a chargeback
     * delivery from the paid delivery that preceded it.
     */
    public function chargedBackAmount(object $payment): int
    {
        $chargedBack = $payment->amountChargedBack ?? null;

        if (! is_object($chargedBack)) {
            return 0;
        }

        return abs(BillingMoney::minorFromMollieAmount(
            $chargedBack->value ?? null,
            $chargedBack->currency ?? $payment->amount->currency ?? null,
        ));
    }

    public function hasChargebacks(object $payment): bool
    {
        return $this->chargedBackAmount($payment) > 0;
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

        if ($actualGross === $expectedGross) {
            return $lineItems;
        }

        // 1 cent of slack: gross is rounded per invoice, so an exact match is not
        // guaranteed even when nothing is wrong. That cent is not a mismatch — no event,
        // no warning — but it is still booked, wherever a document at exactly the paid
        // amount exists. Left as it was, an invoice could state a cent MORE than Mollie
        // collected, on every renewal of a subscription whose amount predates per-line
        // VAT rounding: output VAT declared on money that never arrived, and a full
        // refund that asks Mollie for more than the payment holds, which Mollie refuses.
        if (abs($actualGross - $expectedGross) <= 1) {
            $delta = $this->reconcilingDelta($lineItems, $actualGross, $vatRate);

            if ($delta === 0) {
                return $lineItems;
            }

            $rounded = $lineItems;
            $rounded[] = [
                'kind' => 'adjustment',
                'code' => null,
                'label' => 'Rounding',
                'quantity' => 1,
                'unit_price' => $delta,
                'unit_price_net' => $delta,
                'total_net' => $delta,
            ];

            // Only when it lands exactly: `net + round(net × rate)` skips values, and a
            // rounding line that still misses would be noise on a customer document.
            return $this->grossForLineItems($rounded, $vatRate) === $actualGross ? $rounded : $lineItems;
        }

        event(new PaymentAmountMismatch($billable, (string) ($payment->id ?? ''), $expectedGross, $actualGross));

        $delta = $this->reconcilingDelta($lineItems, $actualGross, $vatRate);

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
     * The adjustment net that makes the invoice header land exactly on the amount paid.
     *
     * `round(gross / (1 + rate))` is the obvious answer and it is off by a cent or two,
     * because it is not the sum the header is actually built from: `InvoiceService` totals
     * the per-line VAT (`Σ round(lineNet × rate)`), which differs from `round(Σ lineNet ×
     * rate)` by the per-line residuals — and the adjustment line contributes a residual of
     * its own. So the document that exists to record "what Mollie actually collected"
     * recorded a gross the customer was never charged, output VAT was declared against it,
     * and `refunded_net` carried headroom Mollie refuses to honour: a full refund of such
     * an invoice fails outright.
     *
     * Solved by measuring instead of deriving: the naive net delta is the starting point,
     * and the candidates around it are re-grossed through the same per-line summation the
     * invoice header uses, keeping whichever lands closest.
     *
     * Exactly is not always reachable. `net + round(net × rate)` skips values — at 20 % it
     * steps from 5102 to 5104, so a payment of 51,03 has no representation as a
     * single-rate document at all — which is why the caller tolerates a cent and why this
     * minimises the gap instead of insisting on zero. What it does remove is the drift
     * that grows with the number of lines: six lines whose VAT rounds up individually put
     * `Σ round(net × rate)` three cents above `round(Σ net × rate)`, and the naive delta
     * inherits all three.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function reconcilingDelta(array $lineItems, int $actualGross, float $vatRate): int
    {
        $naive = $this->netFromGross($actualGross, $vatRate) - $this->netFromLineItems($lineItems);

        $best = $naive;
        $bestGap = null;

        foreach ([0, -1, 1, -2, 2, -3, 3] as $nudge) {
            $candidate = $naive + $nudge;

            $probe = $lineItems;
            if ($candidate !== 0) {
                $probe[] = ['kind' => 'adjustment', 'total_net' => $candidate];
            }

            $gap = abs($this->grossForLineItems($probe, $vatRate) - $actualGross);

            // Ties go to the first candidate seen, and the order above walks outwards from
            // the naive value — so among equally good deltas the smallest adjustment wins.
            if ($bestGap === null || $gap < $bestGap) {
                $best = $candidate;
                $bestGap = $gap;

                if ($gap === 0) {
                    break;
                }
            }
        }

        return $best;
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
     * Whether money (or a mandate) this billable handed over is still on its way to a
     * subscription: an activation running right now (a fresh claim) or one waiting for
     * its retry (`activation_retries`).
     *
     * A new first payment must not be started beside it. The checkout used to look only
     * at "is there an accessible subscription", which is false for exactly this state —
     * so a customer sent back to the checkout during the retry window paid a second time,
     * and whichever payment activated second was kept as a duplicate, unbooked.
     */
    public function activationInFlight(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();

        // A €0 authorisation whose activation was REFUSED (its 100% code lost its cover)
        // carries no money and is refused again on every retry: counting it locked the
        // customer out of buying at full price for the whole ladder. Every other marker
        // counts — a €0 trial whose Mollie call failed will most likely be activated by
        // its retry, and a second checkout beside it (now with a mandate, so no trial)
        // would charge in full for the trial about to start.
        foreach ((array) ($meta[ActivationRetry::META_KEY] ?? []) as $marker) {
            if (! (is_array($marker) && ($marker['refused_without_charge'] ?? false) === true)) {
                return true;
            }
        }

        $claim = $meta[self::ACTIVATION_CLAIM_META_KEY] ?? null;

        return is_array($claim) && $this->claimIsFresh($claim);
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

    /**
     * Run an activation's post-invoice side effects, each isolated from the others.
     *
     * Once the invoice exists, a throw in here is terminal: the re-delivery's gate answers
     * `AlreadyBooked`, `activationCompleted()` is true, and it takes the quiet branch —
     * `Log::info` and 200. Unwrapped, an app listener on `WalletCredited` that threw on the
     * second usage type left the customer Active and paying with half their quota, the
     * three-way country check never run, `SubscriptionCreated` never dispatched for the app
     * to react to, and nobody told. `OneTimeOrderHandler`, `LocalToMollieUpgradeHandler` and
     * `SingleChargeHandler` have wrapped their equivalents all along.
     *
     * @param  iterable<string, int|string>  $includedUsages
     * @param  (callable(): void)|null  $extraStep  Whatever this particular activation owns —
     *         the three-way country check for a first payment, `TrialStarted` for a trial.
     */
    public function completeActivation(
        Billable $billable,
        ?BillingInvoice $invoice,
        iterable $includedUsages,
        ?callable $extraStep = null,
        string $creditReason = 'subscription_activation',
    ): void {
        foreach ($includedUsages as $type => $quantity) {
            if ((int) $quantity <= 0) {
                continue;
            }

            $this->safely(
                fn () => app(WalletUsageService::class)->credit($billable, (string) $type, (int) $quantity, $creditReason),
                'crediting the included usage quota',
                $billable,
                ['usage_type' => (string) $type, 'quantity' => (int) $quantity],
            );
        }

        if ($extraStep !== null) {
            $this->safely($extraStep, 'running the activation-specific step', $billable);
        }

        // A trial activation collects no payment, so it has no invoice to announce.
        if ($invoice !== null) {
            $this->safely(fn () => event(new PaymentSucceeded($billable, $invoice)), 'dispatching PaymentSucceeded', $billable);
            $this->safely(fn () => $this->notifyInvoiceAvailable($billable, $invoice), 'sending the invoice notification', $billable);
        }

        $this->safely(fn () => MollieBilling::runAfterCheckout($billable, true), 'running the after-checkout hook', $billable);
    }

    /**
     * Run one post-invoice step, logging at error level if it fails.
     *
     * Error, not warning: every one of these steps is something a customer paid for.
     *
     * @param  array<string, mixed>  $context
     */
    /**
     * Run a side effect that must not be able to abort the caller.
     *
     * Public because every handler that has already written an invoice needs it: past
     * that point the re-delivery is swallowed by the invoice guard, so anything that
     * throws afterwards is lost work nobody will retry. Events are the documented
     * extension seam, which makes an app listener the likeliest thrower of all.
     *
     * @param  array<string, mixed>  $context
     */
    public function safely(callable $step, string $what, Billable $billable, array $context = []): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::error('Activation side effect failed after the invoice was written', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'step' => $what,
                'error' => $e->getMessage(),
                ...$context,
            ]);
        }
    }

    /**
     * Give a billable without a billing country the one its charge was priced with.
     *
     * The charge sites fall back to `checkout_country_fallback` when the column is empty
     * (`ChargeUsageOverageDirectly::charge()` does, and so does the checkout), but the
     * invoicing side did not: `InvoiceService` read the empty column, `VatCalculationService`
     * refused it, and the webhook 500ed. Mollie then re-delivered the same payment forever —
     * money collected, no invoice, no wallet settlement, a customer stuck. Nothing in the
     * package forbids a null country: the column is nullable and a programmatically
     * provisioned or imported billable simply may not have one.
     *
     * Writing it is deliberate rather than passing it through: the country decided what was
     * charged, so it is now a fact about this billable, not a per-call default.
     */
    public function ensureBillingCountry(Billable $billable): void
    {
        if ((string) ($billable->getBillingCountry() ?? '') !== '' || ! ($billable instanceof Model)) {
            return;
        }

        $fallback = (string) config('mollie-billing.checkout_country_fallback', 'DE');

        Log::warning('Billable has no billing country — adopting the one the charge was priced with', [
            'billable_id' => $billable->getKey(),
            'country' => $fallback,
        ]);

        $billable->forceFill(['billing_country' => $fallback])->save();
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
            // A free plan is "active" before, during and after a failed upgrade to a paid
            // one. While an upgrade away from it is on record as unfinished — a retry
            // marker, or the origin a full-coverage upgrade remembers — that accessible
            // local plan is the starting point, not the result: calling it complete made
            // the retry job clear its marker and leave the customer on the free plan with
            // a mandate, an invoice and no subscription.
            $meta = $billable->getBillingSubscriptionMeta();

            if (! empty($meta[\GraystackIT\MollieBilling\Support\ActivationRetry::META_KEY]) || isset($meta['upgrade_from_local_origin'])) {
                return false;
            }

            return $billable->hasAccessibleBillingSubscription();
        }

        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        return $subscriptionId !== '' && $billable->hasAccessibleBillingSubscription();
    }

    /**
     * Whether the subscription that runs was activated by a payment other than this one.
     *
     * Reads the record `CreateSubscription` writes in the same save as the subscription id
     * (`CreateSubscription::ACTIVATED_BY_META_KEY`). False when no Mollie subscription is
     * complete, when this payment is the one on record, and when there is no record at all —
     * a subscription activated before the record existed. That last case cannot be told
     * apart from "this payment activated it", and refunding a payment that bought the
     * running subscription is the worse mistake, so it is left as it was.
     */
    public function activatedByAnotherPayment(Billable $billable, string $paymentId): bool
    {
        if ($billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value
            || ! $this->activationCompleted($billable)
        ) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $key = \GraystackIT\MollieBilling\Services\Billing\CreateSubscription::ACTIVATED_BY_META_KEY;

        return array_key_exists($key, $meta) && (string) ($meta[$key] ?? '') !== $paymentId;
    }

    /**
     * A first payment for a billable whose subscription another payment already activated:
     * booked and returned in full through `UnwantedPaymentRefund` (invoice, credit note,
     * customer mail), then `DuplicatePaymentReceived` for the app.
     *
     * flows.md §13: every payment becomes an invoice; a payment nobody wants becomes that
     * invoice's credit note. It used to be dispatched as an event and kept, unbooked.
     *
     * Runs outside any transaction (it talks to Mollie). Safe to repeat: a re-delivery of
     * the same payment meets the invoice guard and `UnwantedPaymentRefund::resume()`, and
     * the event goes out only on the run that booked it — after the booking, so a
     * listener that throws cannot cost the customer their refund.
     */
    public function settleDuplicatePayment(object $payment, Billable $billable): void
    {
        app(UnwantedPaymentRefund::class)->settle(
            $payment,
            $billable,
            UnwantedPaymentRefund::REASON_DUPLICATE_PAYMENT,
            'Duplicate payment — the subscription was already active',
        );

        $this->safely(
            fn () => \GraystackIT\MollieBilling\Events\DuplicatePaymentReceived::dispatch($billable, (string) ($payment->id ?? '')),
            'duplicate_payment.event',
            $billable,
        );
    }

    /**
     * A recurring charge arrived for a subscription which no longer runs — settle it.
     *
     * Mollie keeps charging a subscription whose local cancel never reached it. The
     * invoice has been written by the caller: the money did arrive, and it is what the
     * refund is credited against. Nothing that treats the charge as a live renewal has run.
     *
     * This used to stop at a mail asking an operator to refund the payment and cancel the
     * Mollie subscription by hand. Both are done here now: the Mollie subscription is
     * cancelled first (so no further period is collected while the refund is under way),
     * then the payment goes back in full through `UnwantedPaymentRefund` — credit note,
     * net zero — and the customer is told. An operator hears about it only when one of
     * the two cannot be done: a refund Mollie would not take is queued under
     * `pending_refund_retries` and reported by `UnwantedPaymentRefund`, a cancel that
     * keeps failing by `RetrySubscriptionCancelJob`.
     *
     * Idempotent by the caller's invoice guard: a re-delivery never gets here.
     */
    public function reportRenewalForEndedSubscription(Billable $billable, object $payment, BillingInvoice $invoice): void
    {
        $paymentId = (string) ($payment->id ?? '');
        $status = $billable->getBillingSubscriptionStatus()->value;

        Log::warning('Recurring charge collected for an ended subscription — refunding it and cancelling at Mollie', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
            'invoice_id' => $invoice->getKey(),
            'status' => $status,
        ]);

        $this->safely(fn () => event(new RenewalCollectedForEndedSubscription(
            $billable,
            $paymentId,
            (int) $invoice->getKey(),
            (int) $invoice->amount_gross,
            $status,
        )), 'ended_renewal.event', $billable);

        $this->cancelMollieSubscriptionOfEndedBillable($billable, $payment);

        app(UnwantedPaymentRefund::class)->settle(
            $payment,
            $billable,
            UnwantedPaymentRefund::REASON_RENEWAL_AFTER_END,
            'Renewal collected after the subscription had ended',
        );
    }

    /**
     * Stop Mollie from collecting again. A failure is parked under
     * `pending_subscription_cancel`, the marker `SweepPendingRetriesJob` turns into a
     * `RetrySubscriptionCancelJob` — the same ladder a failed customer cancel takes.
     */
    protected function cancelMollieSubscriptionOfEndedBillable(Billable $billable, object $payment): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $subscriptionId = (string) ($payment->subscriptionId ?? '');
        $customerId = (string) ($payment->customerId ?? $billable->getMollieCustomerId() ?? '');

        $this->cancelMollieSubscriptionById($billable, $customerId, $subscriptionId);
    }

    /**
     * Cancel one Mollie subscription of the billable, by id. A subscription that is gone
     * already — 404, 410, or Mollie's 422 "The subscription has been cancelled." for one
     * cancelled before (a subscription replaced to move its charge date is such a one:
     * see `MollieSubscriptionReplacer`) — is the outcome wanted. Any other failure is
     * parked under `pending_subscription_cancel` for `RetrySubscriptionCancelJob`.
     */
    public function cancelMollieSubscriptionById(Billable $billable, string $customerId, string $subscriptionId): void
    {
        if (! ($billable instanceof Model) || $subscriptionId === '' || $customerId === '') {
            return;
        }

        try {
            MollieCall::send(new \Mollie\Api\Http\Requests\CancelSubscriptionRequest($customerId, $subscriptionId));

            return;
        } catch (\Throwable $e) {
            if (\GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer::cancelledAlready($e)) {
                return;
            }

            $error = $e->getMessage();
        }

        Log::error('Could not cancel the Mollie subscription of an ended subscription — queued for retry', [
            'billable_id' => $billable->getKey(),
            'mollie_subscription_id' => $subscriptionId,
            'error' => $error,
        ]);

        $this->mutateBillingMeta($billable, static function (array $meta) use ($customerId, $subscriptionId, $error): array {
            $meta['pending_subscription_cancel'] ??= [
                'mollie_customer_id' => $customerId,
                'mollie_subscription_id' => $subscriptionId,
                'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                'last_error' => $error,
            ];

            return $meta;
        });
    }

    /**
     * Create the Mollie subscription of an activation — or, on a retry, adopt the one
     * the first attempt already created.
     *
     * `CreateSubscription` can fail AFTER Mollie created the subscription: the answer is
     * lost, or the local write behind it throws. A blind second create is the defect the
     * two-phase activation exists to prevent — two subscriptions charging one customer
     * every period, one of them with nothing local pointing at it. So a retry asks Mollie
     * first, and an existing subscription for this billable and configuration is taken
     * over: `CreateSubscription` then writes the local state and skips the call.
     *
     * A lookup that fails is not treated as "none": it throws, and the retry comes back.
     *
     * @param  array<string, mixed>  $spec
     */
    public function createSubscription(\GraystackIT\MollieBilling\Services\Billing\CreateSubscription $service, Billable $billable, array $spec): void
    {
        if (ActivationRetry::resuming()) {
            $existing = $this->existingMollieSubscription($billable, $spec);

            if ($existing !== null) {
                Log::warning('Activation retry found the Mollie subscription the first attempt created — adopting it', [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'mollie_subscription_id' => $existing->id ?? null,
                ]);

                $spec['adopt_subscription'] = $existing;
            }
        }

        $service->handle($billable, $spec);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    protected function existingMollieSubscription(Billable $billable, array $spec): ?object
    {
        $customerId = (string) ($billable->getMollieCustomerId() ?? '');

        if ($customerId === '' || ! ($billable instanceof Model)) {
            return null;
        }

        $subscriptions = MollieCall::send(new \Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest($customerId));

        foreach ($subscriptions as $subscription) {
            if (! in_array((string) ($subscription->status ?? ''), ['active', 'pending'], true)) {
                continue;
            }

            $metadata = is_object($subscription->metadata ?? null)
                ? (json_decode((string) json_encode($subscription->metadata), true) ?: [])
                : (array) ($subscription->metadata ?? []);

            if ((string) ($metadata['billable_id'] ?? '') === (string) $billable->getKey()
                && (string) ($metadata['plan_code'] ?? '') === (string) ($spec['plan_code'] ?? '')
                && (string) ($metadata['interval'] ?? '') === (string) ($spec['interval'] ?? '')
            ) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * Take the activation claim for a retry that resumes a booked payment.
     *
     * The gate answered `AlreadyBooked`, which claims nothing — so the resumed activation
     * claims here, under the same row lock, before it runs outside any transaction.
     * Throws ActivationClaimHeldException while a different payment's activation is in
     * flight; the retry job comes back.
     *
     * Returns false — nothing claimed, nothing to run — when the activation is complete by
     * the time the row lock is held: another payment activated in the meantime. Resuming
     * then would create a second Mollie subscription over the one that runs; the retry job
     * refunds this payment as a duplicate instead.
     */
    public function claimForResume(Billable $billable, string $paymentId): bool
    {
        return DB::transaction(function () use ($billable, $paymentId): bool {
            $this->lockBillableRow($billable);

            if ($this->activationCompleted($billable)) {
                return false;
            }

            $this->claimActivation($billable, $paymentId);

            return true;
        });
    }

    /**
     * Hand an unfinished activation to `RetrySubscriptionActivationJob`.
     *
     * Returns true when this call started the retry ladder — the caller announces the
     * failure then, once. False when the ladder is already running (a re-delivery), when
     * this very call IS the retry, or when the payment has already been given back.
     *
     * `$refused` marks an activation that was refused rather than broken off; for a payment
     * that collected nothing, the marker then does not hold the checkout
     * (`activationInFlight()`).
     */
    public function scheduleActivationRetry(Billable $billable, object $payment, string $context, string $reason, bool $refused = false): bool
    {
        $paymentId = (string) ($payment->id ?? '');
        $refusedWithoutCharge = $refused && $this->amountFromMolliePayment($payment) <= 0;

        if (! ($billable instanceof Model) || $paymentId === '' || ActivationRetry::resuming()) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();

        if (array_key_exists($paymentId, (array) ($meta[UnwantedPaymentRefund::META_KEY] ?? []))) {
            return false;
        }

        $started = false;

        $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId, $context, $reason, $refusedWithoutCharge, &$started): array {
            $pending = (array) ($meta[ActivationRetry::META_KEY] ?? []);

            if (! array_key_exists($paymentId, $pending)) {
                $started = true;
                $pending[$paymentId] = [
                    'context' => $context,
                    'reason' => mb_substr($reason, 0, 500),
                    'first_failed_at' => BillingTime::nowUtc()->toIso8601String(),
                ];

                if ($refusedWithoutCharge) {
                    $pending[$paymentId]['refused_without_charge'] = true;
                }

                $meta[ActivationRetry::META_KEY] = $pending;
            }

            return $meta;
        });

        $billable->refresh();

        if ($started && ! RetrySubscriptionActivationJob::runsInline()) {
            // Never what fails the delivery: the marker is written, and
            // SweepPendingRetriesJob picks up a marker whose job never ran.
            $this->safely(function () use ($paymentId, $billable): void {
                // The configured FQCN, not getMorphClass(): the job resolves by class name.
                RetrySubscriptionActivationJob::dispatch($paymentId, $billable::class, $billable->getKey())
                    ->delay(BillingTime::nowUtc()->addSeconds(RetrySubscriptionActivationJob::FIRST_RETRY_SECONDS));
            }, 'activation_retry.dispatch', $billable, ['payment_id' => $paymentId]);
        }

        return $started;
    }

    /**
     * An activation was refused because the subscription is not in a state it may be
     * activated from, or its coupon no longer covers what the checkout promised.
     *
     * By the time this is reachable the mandate has been saved, so the customer has
     * handed over a payment method and received nothing for it. The activation is tried
     * again on its own; an operator is told when those attempts are spent.
     */
    public function reportSkippedActivation(Billable $billable, object $payment, string $context, ?string $reason = null): void
    {
        $status = $billable->getBillingSubscriptionStatus()->value;
        $reason ??= 'Mandate captured but the subscription was not activated: status '.$status.' is not activatable';

        $this->announceActivationFailure($billable, $payment, $context, $reason, null, refused: true);
    }

    /**
     * A payment has its invoice but no finished activation: the run died between the
     * two, or the Mollie subscription could not be created. Retried on its own — see
     * `RetrySubscriptionActivationJob`, which resumes exactly here.
     */
    public function reportIncompleteActivation(Billable $billable, object $payment, string $context, ?string $reason = null): void
    {
        $this->announceActivationFailure(
            $billable,
            $payment,
            $context,
            $reason ?? 'Activation never completed: this payment has an invoice but the subscription was not created',
            null,
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
        $this->announceActivationFailure($billable, $payment, $logMessage, $error->getMessage(), $invoice, $planCode, $interval);
    }

    /**
     * Log, queue the retry, and dispatch `SubscriptionActivationFailed` — the event once
     * per payment, when the retry ladder starts. No operator mail: that is the job's to
     * send, at the end.
     */
    protected function announceActivationFailure(
        Billable $billable,
        object $payment,
        string $context,
        string $reason,
        ?BillingInvoice $invoice,
        ?string $planCode = null,
        ?string $interval = null,
        bool $refused = false,
    ): void {
        $paymentId = (string) ($payment->id ?? '');

        Log::warning('Subscription activation did not finish — it will be retried', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'payment_id' => $paymentId,
            'context' => $context,
            'status' => $billable->getBillingSubscriptionStatus()->value,
            'invoice_id' => $invoice?->getKey(),
            'retrying' => ActivationRetry::resuming(),
            'error' => $reason,
        ]);

        if (! $this->scheduleActivationRetry($billable, $payment, $context, $reason, $refused)) {
            return;
        }

        $this->safely(fn () => event(new SubscriptionActivationFailed(
            $billable,
            $planCode ?? (string) ($billable->getBillingSubscriptionPlanCode() ?? ''),
            $interval ?? (string) ($billable->getBillingSubscriptionInterval() ?? ''),
            $paymentId,
            $invoice !== null ? (int) $invoice->id : null,
            $reason,
        )), 'activation_failure.event', $billable);
    }
}

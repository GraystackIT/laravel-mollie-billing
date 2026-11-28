<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MollieRefusal;
use GraystackIT\MollieBilling\Support\TrialQuota;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Rebalances wallets when a subscription's plan or interval changes.
 *
 * Plan-only credits are prorated against actual usage; purchased credits
 * (one-time orders, coupon credits) are preserved across the change.
 * Unresolvable overage (negative balance after rebalancing) is charged
 * via Mollie when a mandate is available.
 */
class WalletPlanChangeAdjuster
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly WalletUsageService $walletService,
        private readonly ChargeUsageOverageDirectly $overageService,
    ) {
    }

    /**
     * Rebalance the wallets for a plan/interval change.
     *
     * The proration baseline is the **old** billing period. Callers save the new
     * plan, the new interval and sometimes a new period start *before* calling
     * this, so it cannot be read back off the model — pass `$oldPeriodStart` and
     * `$oldPeriodEnd` explicitly.
     *
     * Fallbacks when they are omitted:
     *   - `$oldPeriodStart` falls back to the model's `subscription_period_starts_at`;
     *   - `$oldPeriodEnd` is derived from that start plus **`$oldInterval`**, never
     *     from `nextBillingDate()` (which would already use the new interval);
     *   - if the derived period turns out not to have elapsed at all, the excess
     *     computation is skipped entirely instead of treating a whole period's
     *     legitimate in-quota usage as excess. A reset `subscription_period_starts_at`
     *     is the usual cause, and the real baseline is then unrecoverable — billing
     *     a charge out of missing data is never the safe direction.
     *
     * @param  CarbonInterface|null  $oldPeriodStart  start of the period being left
     * @param  CarbonInterface|null  $oldPeriodEnd  end of the period being left
     * @param  bool  $deferOverageCharge  Return the unresolved overage instead of charging it.
     *                                    Callers inside a transaction MUST set this and pass the
     *                                    result to {@see chargeDeferredOverage()} after the
     *                                    commit — the charge posts a real Mollie payment, and a
     *                                    rollback then leaves money collected with no snapshot
     *                                    that any retry or sweep can find.
     * @return array<int, array<string, mixed>> the deferred overage line items, empty otherwise
     */
    public function adjust(
        Billable $billable,
        string $oldPlan,
        string $oldInterval,
        string $newPlan,
        string $newInterval,
        ?CarbonInterface $oldPeriodStart = null,
        ?CarbonInterface $oldPeriodEnd = null,
        bool $deferOverageCharge = false,
    ): array {
        if (! ($billable instanceof Model)) {
            return [];
        }

        $boundariesExplicit = $oldPeriodStart !== null;
        $periodStart = $oldPeriodStart ?? $billable->getBillingPeriodStartsAt();
        $periodEnd = $oldPeriodEnd ?? $this->derivePeriodEnd(
            $periodStart,
            $oldInterval,
            // The billable's own period ends on its anchor day (BillingAnchor).
            $boundariesExplicit ? null : \GraystackIT\MollieBilling\Support\BillingAnchor::day($billable),
        );

        $prorationUsable = $this->prorationIsUsable(
            $billable,
            $boundariesExplicit,
            $periodStart,
            $periodEnd,
        );

        $overageLineItems = [];

        // A trial was credited only its prorated share, and both sides of the rebalance
        // have to say so. Read from the catalog, the old baseline was the plan's full
        // period quota — a quota the trial never received — so the untouched remainder
        // counted as consumption: `excess` came out at the whole un-granted difference,
        // which is deducted from the new plan's quota and, past that, billed as overage
        // the customer never used. The new side is prorated for the same reason, so long
        // as the trial continues; the paths where the trial *ends* here (the paid
        // conversion) credit a full period instead and do not call this at all.
        $oldGranted = TrialQuota::grantedFromMeta($billable->getBillingSubscriptionMeta(), $oldPlan, $oldInterval);
        $staysOnTrial = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Trial;
        $trialDays = $staysOnTrial
            ? (TrialQuota::grantedTrialDays($billable->getBillingSubscriptionMeta())
                ?? $this->catalog->trialDays($newPlan, $newInterval))
            : 0;
        $regrantedTrialQuota = [];

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $rollover = $this->catalog->usageRollover($slug);
            $oldIncluded = $oldGranted !== null
                ? (int) ($oldGranted[$slug] ?? 0)
                : $this->catalog->includedUsage($oldPlan, $oldInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);

            if ($staysOnTrial) {
                $newIncluded = TrialQuota::prorate($newIncluded, $trialDays, $newInterval);

                if ($newIncluded > 0) {
                    $regrantedTrialQuota[$slug] = $newIncluded;
                }
            }

            $balance = (int) $wallet->balanceInt;

            // Separate purchased credits from plan credits. Purchased credits
            // are consumed last — plan quota first. The remaining purchased
            // balance is preserved across the plan change.
            $purchasedBalance = WalletUsageService::getPurchasedBalance($wallet);
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
            $planOnlyBalance = $balance - $purchasedRemaining;

            // Units an in-flight period-end overage charge is already collecting.
            //
            // They are part of `$excess` (the wallet is negative by exactly them), so
            // charging them again here would bill the same consumption twice — and the debt
            // itself has to survive the rebalance, because `settleOverageWallets()` credits
            // the quantity back when that charge settles. Clearing the debt first made the
            // credit land on a non-negative balance and handed the customer free capacity,
            // permanently for a rollover type.
            //
            // Plus plan-change excess from an EARLIER change that is still waiting to be
            // charged — see carriedDebtUnits().
            $pendingOverage = $this->carriedDebtUnits($billable, $wallet);

            // Not gated on the old plan including any of this type. A usage type may be
            // priced without an included quota at all — every unit is billed and the wallet
            // only ever goes negative — and that shape computed no excess, so the rebalance
            // below reset the wallet to the new plan's quota and the consumed, unbilled debt
            // disappeared. A plan change was a way to use the metered feature for free.
            // With `$oldIncluded === 0` the prorated entitlement is 0 and the excess is the
            // whole debt, which is exactly right; whether it can be billed is decided by
            // the overage price further down, as it always was.
            $excess = 0;
            if ($prorationUsable) {
                /** @var CarbonInterface $periodStart */
                /** @var CarbonInterface $periodEnd */
                $result = BillingPolicy::computeUsageOverageForPlanChange(
                    $oldIncluded,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = max(0, $result['excess'] - $pendingOverage);
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $oldIncluded) : 0;
            $targetBalance = $newIncluded + $rolloverCredits + $purchasedRemaining - $excess;

            if ($targetBalance < 0) {
                $unresolvedOverage = abs($targetBalance);
                $targetBalance = 0;

                $overagePrice = (int) ($this->catalog->usageOveragePrice($oldPlan, $oldInterval, $slug) ?? 0);
                if ($overagePrice > 0) {
                    $overageLineItems[] = [
                        'kind' => 'overage',
                        'code' => $slug,
                        'type' => $slug,
                        // The wallet is clamped to 0 right here, so these units are already
                        // accounted for — the customer is paying for consumption that has
                        // happened, not buying capacity. `settleOverageWallets()` credits
                        // `quantity` back for a period-end charge, where the balance is still
                        // negative and needs clearing; doing that here would land the credit
                        // on 0 and hand out free units (permanently, for a rollover type).
                        'settles_wallet' => false,
                        'label' => (string) __('billing::portal.invoice_line_overage', [
                            'type' => $this->catalog->usageTypeName($slug),
                        ]),
                        'quantity' => $unresolvedOverage,
                        'unit_price_net' => $overagePrice,
                        'total_net' => $unresolvedOverage * $overagePrice,
                    ];
                }
            }

            if ($balance > 0) {
                $wallet->forceWithdraw($balance, ['type' => $slug, 'reason' => 'plan_change_reset']);
            } elseif ($balance < 0) {
                $wallet->deposit(abs($balance), ['type' => $slug, 'reason' => 'plan_change_reset']);
            }

            // The in-flight charge's debt is carried into the new period rather than
            // forgiven — see `$pendingOverage` above.
            $finalBalance = $targetBalance - $pendingOverage;

            if ($finalBalance > 0) {
                $wallet->deposit($finalBalance, ['type' => $slug, 'reason' => 'plan_change_credit']);
            } elseif ($finalBalance < 0) {
                $wallet->forceWithdraw(abs($finalBalance), ['type' => $slug, 'reason' => 'plan_change_pending_overage']);
            }

            // purchased_balance must not exceed the actual wallet balance.
            WalletUsageService::setPurchasedBalance($wallet, min($purchasedRemaining, max(0, $finalBalance)));

            // The carried debt may have been netted against the new quota; the price record
            // follows the balance.
            WalletUsageService::syncDeferredOverage($wallet);
        }

        // Create wallets for usage types that are new in the target plan.
        $newUsages = $this->catalog->includedUsages($newPlan, $newInterval);
        foreach ($newUsages as $type => $quantity) {
            $quantity = (int) $quantity;

            if ($staysOnTrial) {
                $quantity = TrialQuota::prorate($quantity, $trialDays, $newInterval);

                if ($quantity > 0) {
                    $regrantedTrialQuota[(string) $type] = $quantity;
                }
            }

            if ($quantity > 0 && $billable->getWallet($type) === null) {
                $this->walletService->credit($billable, (string) $type, $quantity, 'plan_change_credit');
            }
        }

        // The grant moved with the plan, so the record of it has to move too — it is what
        // `grantedBillingQuota()` answers with for the rest of the trial, and a marker
        // still naming the old plan would send every meter back to re-deriving.
        if ($staysOnTrial) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['trial_quota'] = TrialQuota::marker($newPlan, $newInterval, $trialDays, $regrantedTrialQuota);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        if ($overageLineItems === []) {
            return [];
        }

        if ($deferOverageCharge) {
            return $overageLineItems;
        }

        $this->chargeDeferredOverage($billable, $overageLineItems);

        return [];
    }

    /**
     * Charge the overage `adjust()` could not resolve against the new plan's quota.
     *
     * Split out so a caller that rebalances inside a transaction can commit first: this
     * posts a real Mollie payment, and a rollback afterwards would drop the `usage_overage`
     * snapshot and the `pending` status the payment is reconciled through — leaving a
     * charge that settles into an invoice nothing can match, while the (restored) negative
     * balance is charged again by the next nightly sweep.
     *
     * @param  array<int, array<string, mixed>>  $overageLineItems
     */
    public function chargeDeferredOverage(Billable $billable, array $overageLineItems): void
    {
        if ($overageLineItems === [] || ! ($billable instanceof Model)) {
            return;
        }

        if (! $billable->hasMollieMandate()) {
            $this->restoreUnchargedExcess($billable, $overageLineItems, 'no_mandate');

            return;
        }

        // The wallets are already zeroed at this point, so the charge can no
        // longer be reconstructed from them. handleExplicit() persists the
        // line items and marks the charge pending, which is what makes a
        // declined payment recoverable via ChargeUsageOverageDirectly::retry().
        try {
            $result = $this->overageService->handleExplicit($billable, $overageLineItems);
        } catch (\Throwable $e) {
            // Restoring the excess assumes the charge did not happen. After a throw that is not
            // known: `charge()` posts the payment before it can store the id, so a read timeout
            // on the response leaves a payment Mollie may well be collecting. Putting the debt
            // back on the wallet then had the next nightly sweep charge it a second time while
            // the first payment settled into its own invoice — and for a plan-change line the
            // webhook does not credit the units back (`settles_wallet: false`), so nothing
            // cancelled it out. The customer paid twice.
            //
            // The debt stays where `handleExplicit()` left it — in the pending snapshot, marked
            // as unknown — and `CleanupStalePendingOverageChargeJob` asks Mollie which of the
            // two happened.
            //
            // A definite refusal (a 4xx, `MollieRefusal::isDefinite()`) is known to have charged
            // nothing, so it carries no marker — but the debt is in the pending snapshot all the
            // same, and the retry ladder charges it from there. Handing it back to the wallet as
            // well is the double record the marker case above guards against.
            if (MollieRefusal::isDefinite($e) && $this->overageService->pendingCharge($billable) !== null) {
                Log::warning('Plan-change overage charge refused by Mollie — the debt stays in the pending snapshot for the retry ladder', [
                    'billable_id' => $billable->getKey(),
                    'line_items' => $overageLineItems,
                    'exception' => $e->getMessage(),
                ]);

                return;
            }

            if ($this->overageService->paymentMayExist($billable)) {
                Log::error('Plan-change overage charge failed after the payment was posted — leaving the debt for reconciliation', [
                    'billable_id' => $billable->getKey(),
                    'line_items' => $overageLineItems,
                    'exception' => $e->getMessage(),
                ]);

                return;
            }

            $this->restoreUnchargedExcess($billable, $overageLineItems, 'exception: '.$e->getMessage());

            return;
        }

        if (! $result->wasCharged()) {
            Log::warning('Plan-change overage was not charged', [
                'billable_id' => $billable->getKey(),
                'outcome' => $result->outcome->value,
                'line_items' => $overageLineItems,
            ]);

            $this->restoreUnchargedExcess($billable, $overageLineItems, $result->outcome->value);
        }
    }

    /**
     * Put excess back on the wallet when the charge for it did not happen.
     *
     * `adjust()` clamps the wallet to 0 and hands the excess over as line items, on the
     * assumption that a charge collects them. When it does not — another charge is still in
     * flight (the snapshot is a single slot), no mandate, an API failure — those units were
     * simply gone: zero balance, nothing in the snapshot, no sweep able to find them. That
     * is silent revenue loss, and it contradicts what `handleExplicit()` promises ("the
     * excess stays on the wallet as a negative balance, which the next period-end sweep
     * charges once the slot is free").
     *
     * @param  array<int, array<string, mixed>>  $overageLineItems
     */
    private function restoreUnchargedExcess(Billable $billable, array $overageLineItems, string $reason): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $restored = [];

        $skipped = [];

        foreach ($overageLineItems as $line) {
            $type = (string) ($line['type'] ?? $line['code'] ?? '');
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($type === '' || $quantity <= 0) {
                $skipped[] = ['type' => $type, 'quantity' => $quantity, 'why' => 'malformed_line'];

                continue;
            }

            $wallet = $billable->getWallet($type);

            if ($wallet === null) {
                $skipped[] = ['type' => $type, 'quantity' => $quantity, 'why' => 'wallet_missing'];

                continue;
            }

            $wallet->forceWithdraw($quantity, [
                'type' => $type,
                'reason' => 'plan_change_excess_uncharged',
            ]);

            // With the price they were quoted at. The wallet only holds units, and the next
            // sweep prices a bare negative balance from the plan the billable is on *then*
            // — the new one. These were consumed on the old one.
            WalletUsageService::addDeferredOverage($wallet, $quantity, (int) ($line['unit_price_net'] ?? 0));

            $restored[$type] = $quantity;
        }

        if ($restored !== []) {
            Log::warning('Plan-change overage left on the wallet for the next period-end sweep', [
                'billable_id' => $billable->getKey(),
                'reason' => $reason,
                'units' => $restored,
            ]);
        }

        // A line this method cannot put back is revenue that is simply gone — the wallet was
        // already clamped to 0 by `adjust()`, and nothing else holds these units. The
        // warning above fires only when at least one line *was* restored, so a wholly
        // unrestorable set used to pass in total silence: no balance, no snapshot, no log.
        if ($skipped !== []) {
            Log::error('Plan-change overage could not be restored — these units are lost', [
                'billable_id' => $billable->getKey(),
                'reason' => $reason,
                'skipped' => $skipped,
            ]);
        }
    }

    /**
     * Units of a wallet's negative balance that are debt, not consumption on the plan being
     * left — `adjust()` keeps them out of the excess it prices, and the plan-change preview
     * (`PreviewService`) has to do the same or it quotes them a second time:
     *
     *  - units an in-flight period-end overage charge is already collecting;
     *  - plan-change excess from an EARLIER change that is still waiting to be charged (see
     *    `restoreUnchargedExcess()`). It is debt with a recorded price — read as consumption,
     *    a second plan change would prorate it against the wrong quota and re-price whatever
     *    was left at yet another plan's rate. What a pending charge already lists is counted
     *    with the in-flight units, not twice.
     */
    public function carriedDebtUnits(Billable $billable, \Bavix\Wallet\Models\Wallet $wallet): int
    {
        $slug = (string) $wallet->slug;

        return $this->pendingOverageQuantityFor($billable, $slug)
            + max(
                0,
                WalletUsageService::deferredOverageQuantity($wallet)
                    - $this->pendingOverageQuantityFor($billable, $slug, deferredOnly: true),
            );
    }

    /**
     * Units of `$usageType` an in-flight period-end overage charge is already collecting.
     *
     * Only a `pending` snapshot counts, and only lines that settle the wallet: a
     * plan-change line carries `settles_wallet: false` because its units were already
     * cleared off the balance, so it must not be subtracted a second time here.
     */
    private function pendingOverageQuantityFor(Billable $billable, string $usageType, bool $deferredOnly = false): int
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
            return 0;
        }

        $snapshot = (array) ($meta[ChargeUsageOverageDirectly::META_KEY] ?? []);
        $lines = (array) ($snapshot['line_items'] ?? []);
        $total = 0;

        foreach ($lines as $line) {
            if (! is_array($line) || ($line['settles_wallet'] ?? true) === false) {
                continue;
            }

            if ($deferredOnly && ($line['deferred_price'] ?? false) !== true) {
                continue;
            }

            $type = (string) ($line['type'] ?? $line['code'] ?? '');

            if (strcasecmp($type, $usageType) !== 0) {
                continue;
            }

            $total += max(0, (int) ($line['quantity'] ?? 0));
        }

        return $total;
    }

    /**
     * End of the period being left: start + the *old* interval.
     */
    private function derivePeriodEnd(?CarbonInterface $periodStart, string $oldInterval, ?int $anchorDay = null): ?CarbonInterface
    {
        if ($periodStart === null) {
            return null;
        }

        return \GraystackIT\MollieBilling\Support\BillingAnchor::next(
            $periodStart,
            strtolower($oldInterval) === SubscriptionInterval::Yearly->value ? 'yearly' : 'monthly',
            $anchorDay,
        );
    }

    /**
     * Whether the resolved boundaries can carry a prorated entitlement.
     *
     * Derived boundaries with a zero elapsed fraction mean the period start was
     * overwritten before this ran: the prorated entitlement would be 0 and every
     * unit consumed in the old period would be billed as excess.
     */
    private function prorationIsUsable(
        Model $billable,
        bool $boundariesExplicit,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
    ): bool {
        if ($periodStart === null || $periodEnd === null) {
            return false;
        }

        if ($boundariesExplicit) {
            return true;
        }

        $elapsedFraction = round(1.0 - BillingPolicy::prorataFactor($periodStart, $periodEnd), 6);

        if ($elapsedFraction > 0.0) {
            return true;
        }

        Log::warning(
            'WalletPlanChangeAdjuster: no elapsed time in the derived old period — skipping usage proration. '
            .'Pass $oldPeriodStart/$oldPeriodEnd from the caller to bill plan-change excess.',
            [
                'billable_id' => $billable->getKey(),
                'period_start' => $periodStart->toIso8601String(),
                'period_end' => $periodEnd->toIso8601String(),
            ]
        );

        return false;
    }
}

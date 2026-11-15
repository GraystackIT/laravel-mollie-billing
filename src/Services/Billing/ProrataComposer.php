<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;

/**
 * Pure computation of pro-rata lines for a plan-change intent.
 *
 * Returns a mixed list of charge and refund lines.
 * Charges before refunds; within the same direction: plan, seats, addons.
 *
 * Free-plan origin on a Local subscription: empty list — Free→Mollie runs through UpgradeLocalToMollie.
 * A free plan that bills paid addons through Mollie is priced like any other plan.
 * Mollie→Free: the old configuration is credited; whatever the free target still bills
 * (paid addons it keeps) is charged, so a target that bills nothing yields refunds only.
 * Credits are prorated from what was actually paid for a line, not from its list price
 * ({@see paidLineNet()}).
 * Mollie→Mollie: plan switch = refund of the old + charge of the new plan, separately.
 *
 * Pricing follows BillingPolicy::computeProrata(): within one interval both sides are
 * scaled by the remaining fraction of the current window; across an interval change the
 * new lines are charged in full (a full new period is granted) and the unused remainder
 * of the old period is credited.
 */
class ProrataComposer
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {}

    /**
     * @return list<ProrataLine>
     */
    public function compose(PlanChangeIntent $intent): array
    {
        $billable = $intent->billable;

        // Free origin on a Local subscription: nothing at all (Free→Mollie runs through
        // UpgradeLocalToMollie, which collects a full first payment).
        //
        // Scoped to Local on purpose. `isFreePlan()` looks at base and seat price only, so
        // it also says yes for a free plan that carries a paid addon — and that one bills
        // through a Mollie subscription like any paid plan. Short-circuiting it here meant
        // an addon added mid-period was never charged until the renewal, and one removed
        // mid-period was never credited.
        if ($this->catalog->isFreePlan($intent->currentPlan, $intent->currentInterval)
            && $billable->isLocalBillingSubscription()
        ) {
            return [];
        }

        // Past-Due-Reset: the last recurring charge failed, so the current
        // period was never paid. A plan change here is semantically a fresh
        // start, not a mid-period adjustment — charge the full first period
        // of the new plan, refund nothing (nothing was paid that could be
        // refunded). Period window is `now → now + 1 interval` so the
        // existing chargeLine helpers compute factor=1.0 automatically.
        //
        // The plan/interval condition is what makes this a *reset*, and it lives in
        // BillingPolicy because buildContext() and the preview decide it too. This copy
        // used to omit it, so a past-due customer adding one seat was charged the full
        // list price of plan + seats + addons instead of the prorated delta — while the
        // preview, which had the condition, quoted the delta.
        if (BillingPolicy::isPastDueReset($billable, $intent->currentPlan !== $intent->newPlan, $intent->currentInterval !== $intent->newInterval)
            && ! $this->catalog->isFreePlan($intent->newPlan, $intent->newInterval)
        ) {
            // A full first period, priced as a renewal would price it.
            return $this->withMarkerDiscount($intent, $this->composePastDueReset($intent), 1.0, wholeConfiguration: true);
        }

        // Plan switch
        $planChanged = $intent->currentPlan !== $intent->newPlan;
        $intervalChanged = $intent->currentInterval !== $intent->newInterval;

        // Free while the trial runs: no charge for what it adds, no credit for what it
        // takes away. See BillingPolicy::isFreeTrialAdjustment() — a plan or interval
        // change is charged, because it ends the trial.
        if (BillingPolicy::isFreeTrialAdjustment($billable, $planChanged, $intervalChanged)) {
            return [];
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();
        if ($periodStart === null || $periodEnd === null) {
            return [];
        }

        $charges = [];
        $refunds = [];

        if ($planChanged || $intervalChanged) {
            // Plan or interval change → close out the entire current period
            // (plan + currently-active extra seats + currently-active addons
            // get a pro-rata refund) and open a fresh window with the new
            // plan's catalog prices. This avoids "old seats survive plan
            // change at old price/VAT" drift.
            //
            // Quantity is bounded by the currently-active state (seat_count
            // and currentAddons), NOT by the sum of remaining_quantity across
            // historical invoices — those can include stale leftovers from
            // mid-cycle adjustments that no longer reflect what the user
            // actually has access to today.
            $refundLine = $this->planRefundLine($intent, $periodStart, $periodEnd);
            if ($refundLine !== null) {
                $refunds[] = $refundLine;
            }

            $currentExtraSeats = max(0, $intent->currentSeats - $this->catalog->includedSeats($intent->currentPlan));
            if ($currentExtraSeats > 0) {
                $refunds = array_merge($refunds, $this->seatsRefundLines($billable, $currentExtraSeats));
            }

            foreach ($intent->currentAddons as $code => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $refunds = array_merge($refunds, $this->addonRefundLines($billable, (string) $code, $qty));
                $refunds = array_merge($refunds, $this->addonSeatsRefundLines(
                    $billable,
                    (string) $code,
                    $this->currentAddonSeats($intent, (string) $code),
                ));
            }

            // An interval change grants a FULL new period — ProrataChargeHandler opens a
            // window of `paidAt → paidAt + 1 new interval` and MollieSubscriptionPatcher
            // resets Mollie's startDate to `now + 1 new interval`. So the new lines are
            // charged in full and the unused remainder of the old period is credited via
            // the refund lines above: exactly BillingPolicy::computeProrata()'s
            // interval-change rule ("full new price − unused credit"), which is the single
            // pricing rule the `$hasProrata` gate, the returned `prorataChargeNet` and the
            // PlanChangePending payload already use. Pricing the new interval with the OLD
            // window's remaining fraction instead collected ~7% of a year's price on day
            // 28 of a month while granting all twelve months.
            //
            // A plain plan change inside the same interval keeps the remaining-window
            // factor: there the period boundary does not move.
            [$chargeStart, $chargeEnd] = $intervalChanged
                ? $this->newIntervalWindow($intent)
                : [$periodStart, $periodEnd];

            // No `isFreePlan(newPlan)` gate around this block. A free target still bills
            // whatever paid addons it keeps, and the refund side above has just credited
            // every current addon — so skipping the charges handed a kept, paid addon out
            // for free until the renewal. The helpers already return null for a component
            // the target does not price (base 0, no seat price), so a target that bills
            // nothing still produces no charge line.
            $chargeLine = $this->planChargeLine($intent, $chargeStart, $chargeEnd);
            if ($chargeLine !== null) {
                $charges[] = $chargeLine;
            }

            $newExtraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
            if ($newExtraSeats > 0) {
                $chargeLine = $this->seatsChargeLine($intent, $newExtraSeats, $chargeStart, $chargeEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }
            }

            foreach ($intent->newAddons as $code => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $chargeLine = $this->addonChargeLine($intent, (string) $code, $qty, $chargeStart, $chargeEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }

                $chargeLine = $this->addonSeatsChargeLine($intent, (string) $code, $this->newAddonSeats($intent, (string) $code), $chargeStart, $chargeEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }
            }

            $refunds = $this->applyRemainingRefundablePool($refunds);

            ['factor' => $chargeFactor] = $this->periodMath($chargeStart, $chargeEnd);
            $charges = $this->withMarkerDiscount($intent, $charges, $chargeFactor, wholeConfiguration: true);

            return array_merge($charges, $refunds);
        }

        // Mid-cycle change (same plan, same interval) — only diff what's actually
        // different. Existing seats and addons stay at the price they were bought.

        // Seat change — we only consider EXTRA seats (beyond includedSeats).
        // Included seats are part of the plan price and are already billed via the
        // plan refund/charge line — they must not appear twice.
        $currentExtraSeats = max(0, $intent->currentSeats - $this->catalog->includedSeats($intent->currentPlan));
        $newExtraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        $seatDiff = $newExtraSeats - $currentExtraSeats;

        if ($seatDiff < 0) {
            $refunds = array_merge($refunds, $this->seatsRefundLines($billable, abs($seatDiff)));
        } elseif ($seatDiff > 0) {
            $chargeLine = $this->seatsChargeLine($intent, $seatDiff, $periodStart, $periodEnd);
            if ($chargeLine !== null) {
                $charges[] = $chargeLine;
            }
        }

        // Addon changes
        $currentAddons = $intent->currentAddons;
        $newAddons = $intent->newAddons;
        $allCodes = array_unique(array_merge(array_keys($currentAddons), array_keys($newAddons)));

        foreach ($allCodes as $code) {
            $currentQty = (int) ($currentAddons[$code] ?? 0);
            $newQty = (int) ($newAddons[$code] ?? 0);
            $diff = $newQty - $currentQty;

            if ($diff < 0) {
                $refunds = array_merge($refunds, $this->addonRefundLines($billable, $code, abs($diff)));
            } elseif ($diff > 0) {
                $chargeLine = $this->addonChargeLine($intent, $code, $diff, $periodStart, $periodEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }
            }

            // The per-seat part of the addon, which moves on its own axis: with the addon
            // itself (on → every charged seat, off → every one back) and with the seat
            // count while the addon stays (only the difference in CHARGED seats — the
            // addon's free seats absorb a change below them, so 4 → 6 seats with 5 free
            // charges exactly one). The base line above is untouched by a seat change.
            $currentSeatUnits = $currentQty > 0 ? $this->currentAddonSeats($intent, (string) $code) : 0;
            $newSeatUnits = $newQty > 0 ? $this->newAddonSeats($intent, (string) $code) : 0;
            $seatUnitDiff = $newSeatUnits - $currentSeatUnits;

            if ($seatUnitDiff < 0) {
                $refunds = array_merge($refunds, $this->addonSeatsRefundLines($billable, (string) $code, abs($seatUnitDiff)));
            } elseif ($seatUnitDiff > 0) {
                $chargeLine = $this->addonSeatsChargeLine($intent, (string) $code, $seatUnitDiff, $periodStart, $periodEnd);
                if ($chargeLine !== null) {
                    $charges[] = $chargeLine;
                }
            }
        }

        $refunds = $this->applyRemainingRefundablePool($refunds);

        ['factor' => $chargeFactor] = $this->periodMath($periodStart, $periodEnd);
        $charges = $this->withMarkerDiscount($intent, $charges, $chargeFactor, wholeConfiguration: false);

        return array_merge($charges, $refunds);
    }

    /**
     * Carry the running recurring discount — a coupon or a plan waiver — onto the charge.
     *
     * The discount lives in one slot (`active_recurring_coupon`) and every *recurring*
     * amount honours it: the renewal invoice, the Mollie subscription amount, the preview.
     * The prorata charge did not. It priced the new configuration at list, while the
     * credit for the old one is the unused share of what was actually paid — so across a
     * change the customer paid the discount back for the rest of the period:
     *
     * - Plan free under a waiver, Pro (50.00) → Enterprise (200.00) on day 11 of 31:
     *   nothing was paid for Pro, so nothing is credited; Enterprise was charged
     *   200.00 × 21/31 = 135.48. The recurring amount is 150.00 — the waiver keeps taking
     *   the 50.00 it was given for off — so the remaining days cost 150.00 × 21/31 =
     *   101.61. The customer paid 33.87 for a plan that is theirs for free.
     * - A granted addon switched off and on again was charged pro-rata, although the next
     *   renewal waives it again.
     *
     * What the change owes is the difference between two recurring amounts for the days
     * that are left, so the same discount has to be on both sides:
     *
     * - A change that credits the whole old configuration and charges the whole new one
     *   (plan, interval, past-due reset) takes the discount the NEW configuration gets.
     * - A seat or addon change charges only what was added, so it takes only what the
     *   discount GROWS by. For a coupon locked to its base amount that is nothing — a seat
     *   added on top is paid in full, now and at every renewal. For a waiver it is the
     *   granted addon coming back.
     *
     * Never more than the charge itself: a discount does not turn a change into a payout.
     *
     * @param  list<ProrataLine>  $charges
     * @return list<ProrataLine>
     */
    private function withMarkerDiscount(PlanChangeIntent $intent, array $charges, float $factor, bool $wholeConfiguration): array
    {
        $chargeNet = 0;
        foreach ($charges as $line) {
            $chargeNet += $line->amountNet;
        }

        if ($charges === [] || $chargeNet <= 0 || $factor <= 0) {
            return $charges;
        }

        $coupons = app(CouponService::class);
        $marker = $coupons->getActiveRecurringCouponMarker($intent->billable);

        if (! is_array($marker)) {
            return $charges;
        }

        $newAddons = array_values(array_map('strval', array_keys(array_filter($intent->newAddons, fn ($q): bool => (int) $q > 0))));
        $newNet = SubscriptionAmount::net($this->catalog, $intent->billable, $intent->newPlan, $intent->newInterval, $intent->newSeats, $newAddons);

        $discount = $coupons->computeMarkerDiscount($intent->billable, $newNet, [
            'plan_code' => $intent->newPlan,
            'interval' => $intent->newInterval,
            'addon_codes' => $newAddons,
            'seats' => $intent->newSeats,
        ]);

        if (! $wholeConfiguration) {
            $currentAddons = array_values(array_map('strval', array_keys(array_filter($intent->currentAddons, fn ($q): bool => (int) $q > 0))));
            $currentNet = SubscriptionAmount::net($this->catalog, $intent->billable, $intent->currentPlan, $intent->currentInterval, $intent->currentSeats, $currentAddons);

            $discount -= $coupons->computeMarkerDiscount($intent->billable, $currentNet, [
                'plan_code' => $intent->currentPlan,
                'interval' => $intent->currentInterval,
                'addon_codes' => $currentAddons,
                'seats' => $intent->currentSeats,
            ]);
        }

        $discountNet = min((int) round($discount * $factor), $chargeNet);

        if ($discountNet <= 0) {
            return $charges;
        }

        // Fully covered: there is nothing to pay, so there is nothing to charge — and no
        // pair of lines that cancel each other to preview, send or book. Returning them
        // made the executor take the "charge" path for a charge of 0.00, which creates no
        // payment and therefore never reaches the Phase 2 that pays the credits out.
        if ($discountNet >= $chargeNet) {
            return [];
        }

        $first = $charges[0];
        $vatRate = (float) $first->vatRate;
        $discountVat = (int) round($discountNet * $vatRate / 100);
        $code = (string) ($marker['code'] ?? '');

        $line = new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'coupon',
            code: $code !== '' ? $code : null,
            label: __('billing::portal.coupon_label', ['code' => $code]),
            quantity: 1,
            amountNet: -$discountNet,
            vatRate: $vatRate,
            amountVat: -$discountVat,
            amountGross: -($discountNet + $discountVat),
            periodStart: $first->periodStart->copy(),
            periodEnd: $first->periodEnd->copy(),
            daysActive: $first->daysActive,
            daysRemaining: $first->daysRemaining,
            isCouponCovered: false,
            // Netted into the Mollie charge, like a coupon entered with the change.
            direction: 'charge',
        );

        // A waiver names the lines it takes its amount off, exactly as on a renewal, so a
        // later credit of this charge leaves the free plan alone and credits the paid
        // extras in full ({@see paidLineNet()}).
        if (PlanWaiver::isWaiver($marker)) {
            $line->appliesTo = PlanWaiver::appliesTo($marker, $newAddons);
        }

        return [...$charges, $line];
    }

    /**
     * Cap refund lines against the remaining-refundable budget of each original
     * invoice. Multiple refund lines on the same invoice (plan + seats + addons)
     * share one budget; otherwise a partial pre-refund (e.g. a goodwill credit)
     * would be ignored by all but the first line.
     *
     * Lines are processed in input order. When a line exceeds the remaining
     * budget it is reduced to fit; when the budget is exhausted, follow-up lines
     * on that invoice are dropped entirely. Lines without an original invoice or
     * that are coupon-covered are passed through unchanged.
     *
     * The (possibly reduced) line carries a `refundCapNote` so the UI can render
     * "reduced because X already refunded".
     *
     * @param  list<ProrataLine>  $refundLines
     * @return list<ProrataLine>
     */
    private function applyRemainingRefundablePool(array $refundLines): array
    {
        $pools = [];   // [invoice_id] => remaining net budget
        $totals = [];  // [invoice_id] => original total budget at start (for the note)
        $out = [];

        foreach ($refundLines as $line) {
            $invoice = $line->originalInvoice;
            if ($invoice === null || $line->isCouponCovered) {
                $out[] = $line;
                continue;
            }

            $invoiceId = $invoice->getKey();
            if (! array_key_exists($invoiceId, $pools)) {
                $pools[$invoiceId] = (int) $invoice->remainingRefundableNet();
                $totals[$invoiceId] = (int) $invoice->amount_net;
            }

            $uncappedNet = abs($line->amountNet);
            $available = $pools[$invoiceId];

            if ($uncappedNet <= $available) {
                $pools[$invoiceId] = $available - $uncappedNet;
                $out[] = $line;
                continue;
            }

            if ($available <= 0) {
                continue; // budget exhausted on this invoice — drop the line
            }

            // Partial fit: clamp this line to the remaining budget. We keep the
            // original VAT rate and recompute VAT proportionally so per-line
            // gross stays consistent.
            $cappedNet = $available;
            $vatRate = $line->vatRate;
            $cappedVat = (int) round($cappedNet * $vatRate / 100);
            $capped = new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: $line->originalLineItemIndex,
                kind: $line->kind,
                code: $line->code,
                label: $line->label,
                quantity: $line->quantity,
                amountNet: -$cappedNet,
                vatRate: $vatRate,
                amountVat: -$cappedVat,
                amountGross: -($cappedNet + $cappedVat),
                periodStart: $line->periodStart,
                periodEnd: $line->periodEnd,
                daysActive: $line->daysActive,
                daysRemaining: $line->daysRemaining,
                isCouponCovered: false,
                direction: 'refund',
            );
            $capped->refundCapNote = [
                'alreadyRefundedNet' => (int) $invoice->effectiveRefundedNet(),
                'originalAmountNet' => $totals[$invoiceId],
                'uncappedRefundNet' => $uncappedNet,
                'cappedRefundNet' => $cappedNet,
            ];
            $out[] = $capped;
            $pools[$invoiceId] = 0;
        }

        return $out;
    }

    private function planRefundLine(PlanChangeIntent $intent, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $candidates = BillingInvoice::currentPeriodLines($intent->billable, 'plan', $intent->currentPlan);
        if (empty($candidates)) {
            // No open plan line in the current period — e.g. when the original
            // subscription invoice was fully refunded as a goodwill credit. In that
            // case there is nothing left to refund; the new plan is simply charged
            // without a pro-rata counter-entry. Free→* was already caught above.
            return null;
        }

        $first = $candidates[0];
        /** @var BillingInvoice $invoice */
        $invoice = $first['invoice'];
        $idx = $first['line_index'];
        $line = $invoice->lineItem($idx);
        if ($line === null) {
            return null;
        }

        [$lineStart, $lineEnd] = $this->resolveLinePeriod($line, $invoice, $periodStart, $periodEnd);

        $cashNet = $this->paidLineNet($invoice, $line);
        $vatRate = (float) ($line['vat_rate'] ?? 0);

        ['days' => $days, 'factor' => $factor] = $this->periodMath($lineStart, $lineEnd);

        $planName = $this->catalog->planName($intent->currentPlan) ?? $intent->currentPlan;
        $label = __('billing::portal.prorata_label_plan_refund', ['plan' => $planName]);

        $isCouponCovered = $cashNet === 0;
        if ($isCouponCovered) {
            return new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: $idx,
                kind: 'plan',
                code: $intent->currentPlan,
                label: $label,
                quantity: 1,
                amountNet: 0,
                vatRate: $vatRate,
                amountVat: 0,
                amountGross: 0,
                periodStart: $lineStart,
                periodEnd: $lineEnd,
                daysActive: $days['total'],
                daysRemaining: $days['remaining'],
                isCouponCovered: true,
                direction: 'refund',
            );
        }

        // Capped at the line's own net, like buildRefundLine(). periodMath() clamps
        // `remaining` at >= 0 but never at <= total, so a period whose start lies in
        // the future yields a factor > 1 and refunds more than the line was ever
        // paid. The per-invoice pool below bounds the invoice total, not the single
        // line: on a plan 2900 + seats 1000 invoice a factor of 1.033 sends 2997 for
        // a plan line worth 2900 and the pool never notices.
        $refundNet = min((int) round($cashNet * $factor), $cashNet);
        if ($refundNet <= 0) {
            return null;
        }

        $refundVat = (int) round($refundNet * $vatRate / 100);
        $refundGross = $refundNet + $refundVat;

        // Beyond that cap the composer applies a per-invoice remaining-pool cap at
        // the end (see applyRemainingRefundablePool()) so multiple refund lines on
        // the same original invoice (plan + seats + addons) share a single budget
        // instead of each independently reading the full remainder.
        return new ProrataLine(
            originalInvoice: $invoice,
            originalLineItemIndex: $idx,
            kind: 'plan',
            code: $intent->currentPlan,
            label: $label,
            quantity: 1,
            amountNet: -$refundNet,
            vatRate: $vatRate,
            amountVat: -$refundVat,
            amountGross: -$refundGross,
            periodStart: $lineStart,
            periodEnd: $lineEnd,
            daysActive: $days['total'],
            daysRemaining: $days['remaining'],
            isCouponCovered: false,
            direction: 'refund',
        );
    }

    /**
     * What was actually paid for one line of an invoice — the base every credit is
     * prorated from.
     *
     * A line states the LIST price of its component. Whatever reduced the payment sits on
     * a line of its own: a recurring coupon (`kind = coupon`) or the `adjustment` that
     * books an invoice down to the amount Mollie really collected. Prorating the credit
     * from the list price therefore credited value that was never paid: on a period bought
     * with a 50 % coupon (plan 2900, coupon −1450) a downgrade on day 16 of 31 credited
     * 2900 × 16/31 = 1497, capped by the per-invoice pool at 1450 — the whole payment, for
     * a period that was half used. The pool bounds the invoice total, not the share.
     *
     * A reduction that carries `applies_to` (a plan waiver) is taken off the lines it names
     * first; see the comment in the body.
     *
     * The remaining reductions are spread over the charge lines in proportion: each line is worth
     * `list × (charges − reductions) / charges`. Lines carrying a `parent_invoice_id` are
     * credits against ANOTHER invoice (the saldo-zero plan-switch document) and reduce
     * nothing here. A positive adjustment — Mollie collected more than the lines state —
     * never raises a line above its list price; that surplus is not this component's.
     *
     * @param  array<string, mixed>  $line
     */
    private function paidLineNet(BillingInvoice $invoice, array $line): int
    {
        $lineNet = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);

        if ($lineNet <= 0) {
            return $lineNet;
        }

        // A reduction that names what it reduces is taken off exactly those lines first.
        //
        // A plan waiver is one: the plan (and the addons the gift included) is free, the
        // extras bought on top are paid in full. Spreading it over every line like an
        // ordinary coupon would call a fully paid addon two-thirds unpaid — its credit on
        // removal would shrink accordingly — and would call the free plan partly paid,
        // so a later plan change credited money nobody had paid for it.
        $own = self::lineKey($line);
        $ownAfterTargeted = $lineNet;
        $charges = 0;
        $reductions = 0;

        /** @var array<string, int> $chargeByKey */
        $chargeByKey = [];
        /** @var list<array{amount: int, targets: list<string>}> $targeted */
        $targeted = [];

        foreach ((array) ($invoice->line_items ?? []) as $other) {
            if (! is_array($other) || ($other['parent_invoice_id'] ?? null) !== null) {
                continue;
            }

            $net = (int) ($other['amount_net'] ?? $other['total_net'] ?? 0);

            if ($net > 0) {
                // A positive adjustment is money collected on top of the components, not
                // a component a reduction could be spread over.
                if (($other['kind'] ?? null) !== 'adjustment') {
                    $charges += $net;
                    $key = self::lineKey($other);
                    $chargeByKey[$key] = ($chargeByKey[$key] ?? 0) + $net;
                }

                continue;
            }

            $targets = array_values(array_filter(array_map('strval', (array) ($other['applies_to'] ?? []))));

            if ($targets !== []) {
                $targeted[] = ['amount' => -$net, 'targets' => $targets];
            } else {
                $reductions += -$net;
            }
        }

        foreach ($targeted as $reduction) {
            $base = 0;
            foreach ($reduction['targets'] as $key) {
                $base += $chargeByKey[$key] ?? 0;
            }

            // Whatever it cannot place on its own lines is an ordinary reduction.
            $placed = min($reduction['amount'], $base);
            $reductions += $reduction['amount'] - $placed;

            if ($placed <= 0 || $base <= 0) {
                continue;
            }

            $charges -= $placed;

            if (in_array($own, $reduction['targets'], true)) {
                // This line's share of the targeted reduction, in proportion to the
                // lines it names.
                $ownAfterTargeted -= (int) round($placed * $lineNet / $base);
            }
        }

        $ownAfterTargeted = max(0, $ownAfterTargeted);

        if ($reductions <= 0 || $charges <= 0) {
            return $ownAfterTargeted;
        }

        $paid = max(0, $charges - $reductions);

        return (int) round($ownAfterTargeted * $paid / $charges);
    }

    /**
     * How a reduction line refers to the charge line it reduces: `plan`, `seats`,
     * `addon:<code>`, `addon_seats:<code>`.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineKey(array $line): string
    {
        $kind = (string) ($line['kind'] ?? '');
        $kind = $kind === 'seat' ? 'seats' : $kind;

        return in_array($kind, ['addon', 'addon_seats'], true)
            ? $kind.':'.(string) ($line['code'] ?? '')
            : $kind;
    }

    /**
     * Resolve the period for a line item (line → invoice → fallback period).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function resolveLinePeriod(array $line, BillingInvoice $invoice, CarbonInterface $fallbackStart, CarbonInterface $fallbackEnd): array
    {
        $startRaw = $line['period_start'] ?? $invoice->period_start ?? null;
        $endRaw = $line['period_end'] ?? $invoice->period_end ?? null;

        // Period values may arrive as a CarbonImmutable (UtcDatetime cast),
        // a DateTimeInterface, or an ISO string from a JSON line_items column.
        // Branching on type avoids a (string)-cast that would drop the offset
        // and let app.timezone reinterpret an already-UTC value.
        $start = $startRaw !== null ? BillingTime::toUtc($startRaw) : $fallbackStart->copy();
        $end = $endRaw !== null ? BillingTime::toUtc($endRaw) : $fallbackEnd->copy();

        return [$start, $end];
    }

    /**
     * The window a *charge* line is stamped with, as opposed to the one it is priced from.
     *
     * A mid-period change is priced against the whole period — `newNet × remaining/total` —
     * but what it buys is only the remaining days. Stamping the line with the full window
     * recorded a number that bought 21 days as if it had bought 31, and that stamp is what
     * a *later* change in the same period reads: `buildRefundLine()` derives its credit as
     * `amount_net × remaining/total` from the line's own period, so it divided a 21-day
     * amount by 31 days. An upgrade on day 11 of 31 charged 3997; downgrading back on day
     * 20 credited 1547 instead of 2284 — 737 net kept from the customer, and the same 737
     * overcharged when the second change goes the other way.
     *
     * The interval-change path passes a window that already starts now, so it is
     * unaffected; `ProrataChargeHandler` re-stamps only there, which is why the same-
     * interval case had nothing to correct it.
     *
     * @return array{0: CarbonInterface, 1: array{total: int, remaining: int}}
     */
    private function chargeStamp(CarbonInterface $pricingStart, CarbonInterface $pricingEnd): array
    {
        $now = BillingTime::nowUtc();

        $stampStart = $now->greaterThan($pricingStart) && $now->lessThan($pricingEnd)
            ? $now
            : $pricingStart;

        ['days' => $days] = $this->periodMath($stampStart, $pricingEnd);

        return [$stampStart, $days];
    }

    private function planChargeLine(PlanChangeIntent $intent, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $listPrice = $this->catalog->basePriceNet($intent->newPlan, $intent->newInterval);
        if ($listPrice <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($listPrice * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        [$stampStart, $stampDays] = $this->chargeStamp($periodStart, $periodEnd);

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'plan',
            code: $intent->newPlan,
            label: __('billing::portal.prorata_label_plan_charge', ['plan' => $planName]),
            quantity: 1,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            // Priced from the period, stamped from now — see chargeStamp().
            periodStart: $stampStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $stampDays['total'],
            daysRemaining: $stampDays['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * Past-Due reset path: charge the full first period of the new plan, no
     * refund lines (nothing was paid that could be refunded). Uses the
     * standard charge-line helpers with a synthetic `now → now + 1 interval`
     * window so they compute factor = 1.0 and produce list-price amounts.
     *
     * @return list<ProrataLine>
     */
    private function composePastDueReset(PlanChangeIntent $intent): array
    {
        $periodStart = BillingTime::nowUtc();
        $periodEnd = $intent->newInterval === 'yearly'
            ? BillingTime::addInterval($periodStart, 'yearly')
            : BillingTime::addInterval($periodStart, 'monthly');

        $charges = [];

        $planLine = $this->planChargeLine($intent, $periodStart, $periodEnd);
        if ($planLine !== null) {
            $charges[] = $planLine;
        }

        $newExtraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        if ($newExtraSeats > 0) {
            $seatsLine = $this->seatsChargeLine($intent, $newExtraSeats, $periodStart, $periodEnd);
            if ($seatsLine !== null) {
                $charges[] = $seatsLine;
            }
        }

        foreach ($intent->newAddons as $code => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $addonLine = $this->addonChargeLine($intent, (string) $code, $qty, $periodStart, $periodEnd);
            if ($addonLine !== null) {
                $charges[] = $addonLine;
            }

            $addonSeatsLine = $this->addonSeatsChargeLine($intent, (string) $code, $this->newAddonSeats($intent, (string) $code), $periodStart, $periodEnd);
            if ($addonSeatsLine !== null) {
                $charges[] = $addonSeatsLine;
            }
        }

        return $charges;
    }

    /**
     * The window a full new interval covers: `now → now + 1 new interval`, which makes
     * the charge-line helpers compute factor = 1.0 and emit list prices. Same trick as
     * composePastDueReset(), and the same window ProrataChargeHandler persists as the
     * invoice period once the charge clears.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function newIntervalWindow(PlanChangeIntent $intent): array
    {
        $start = BillingTime::nowUtc();

        return [
            $start,
            BillingTime::addInterval($start, $intent->newInterval),
        ];
    }

    /**
     * Single source of truth for pro-rata day math.
     *
     * @return array{days: array{total: int, remaining: int}, factor: float}
     */
    private function periodMath(CarbonInterface $start, CarbonInterface $end): array
    {
        $days = BillingPolicy::prorataPeriodDays($start, $end);
        $factor = $days['total'] > 0 ? round($days['remaining'] / $days['total'], 6) : 0.0;

        return ['days' => $days, 'factor' => $factor];
    }

    /**
     * @return list<ProrataLine>
     */
    private function seatsRefundLines(Billable $billable, int $reduction): array
    {
        if ($reduction <= 0) {
            return [];
        }

        $candidates = BillingInvoice::currentPeriodLines($billable, 'seats', null);
        if (empty($candidates)) {
            return []; // no original seat lines (e.g. plan without extra seats)
        }

        $remaining = $reduction;
        $lines = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            /** @var BillingInvoice $invoice */
            $invoice = $candidate['invoice'];
            $idx = $candidate['line_index'];
            $availableQty = (int) $candidate['remaining_quantity'];
            $takeQty = min($remaining, $availableQty);

            // Decremented only once a line exists. `buildRefundLine()` returns null for a
            // candidate with nothing left to credit, and charging the reduction against it
            // anyway meant the next candidate — the invoice that does hold the money — was
            // never asked for those units.
            $line = $this->buildRefundLine($invoice, $idx, 'seats', null, $takeQty);
            if ($line !== null) {
                $remaining -= $takeQty;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function seatsChargeLine(PlanChangeIntent $intent, int $extraSeats, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $seatPrice = $this->catalog->seatPriceNet($intent->newPlan, $intent->newInterval);
        if ($seatPrice === null || $seatPrice <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($seatPrice * $extraSeats * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        [$stampStart, $stampDays] = $this->chargeStamp($periodStart, $periodEnd);

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'seats',
            code: null,
            label: trans_choice('billing::portal.prorata_label_seats_charge', $extraSeats, ['count' => $extraSeats, 'plan' => $planName]),
            quantity: $extraSeats,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            // Priced from the period, stamped from now — see chargeStamp().
            periodStart: $stampStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $stampDays['total'],
            daysRemaining: $stampDays['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * @return list<ProrataLine>
     */
    private function addonRefundLines(Billable $billable, string $code, int $reduction): array
    {
        if ($reduction <= 0) {
            return [];
        }

        $candidates = BillingInvoice::currentPeriodLines($billable, 'addon', $code);
        if (empty($candidates)) {
            return [];
        }

        $remaining = $reduction;
        $lines = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            /** @var BillingInvoice $invoice */
            $invoice = $candidate['invoice'];
            $idx = $candidate['line_index'];
            $availableQty = (int) $candidate['remaining_quantity'];
            $takeQty = min($remaining, $availableQty);

            // See seatsRefundLines(): the quantity is consumed only once a line exists.
            $line = $this->buildRefundLine($invoice, $idx, 'addon', $code, $takeQty);
            if ($line !== null) {
                $remaining -= $takeQty;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function addonChargeLine(PlanChangeIntent $intent, string $code, int $quantity, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $price = $this->catalog->addonPriceNet($code, $intent->newInterval);
        if ($price <= 0) {
            return null;
        }

        ['days' => $days, 'factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($price * $quantity * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        $addonName = $this->catalog->addonName($code) ?? $code;
        $planName = $this->catalog->planName($intent->newPlan) ?? $intent->newPlan;

        [$stampStart, $stampDays] = $this->chargeStamp($periodStart, $periodEnd);

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'addon',
            code: $code,
            label: __('billing::portal.prorata_label_addon_charge', ['addon' => $addonName, 'plan' => $planName]),
            quantity: $quantity,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            // Priced from the period, stamped from now — see chargeStamp().
            periodStart: $stampStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $stampDays['total'],
            daysRemaining: $stampDays['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /** Seats the addon's per-seat price is charged for in the configuration being left. */
    private function currentAddonSeats(PlanChangeIntent $intent, string $code): int
    {
        return SubscriptionAmount::addonChargedSeats(
            $this->catalog, $code, $intent->currentPlan, $intent->currentInterval, $intent->currentSeats,
        );
    }

    /** Seats the addon's per-seat price is charged for in the configuration being entered. */
    private function newAddonSeats(PlanChangeIntent $intent, string $code): int
    {
        return SubscriptionAmount::addonChargedSeats(
            $this->catalog, $code, $intent->newPlan, $intent->newInterval, $intent->newSeats,
        );
    }

    /**
     * Credit `$reduction` charged addon seats, newest line first — the per-seat
     * counterpart of {@see seatsRefundLines()}.
     *
     * @return list<ProrataLine>
     */
    private function addonSeatsRefundLines(Billable $billable, string $code, int $reduction): array
    {
        if ($reduction <= 0) {
            return [];
        }

        $remaining = $reduction;
        $lines = [];

        foreach (BillingInvoice::currentPeriodLines($billable, 'addon_seats', $code) as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            $takeQty = min($remaining, (int) $candidate['remaining_quantity']);

            // See seatsRefundLines(): the quantity is consumed only once a line exists.
            $line = $this->buildRefundLine($candidate['invoice'], $candidate['line_index'], 'addon_seats', $code, $takeQty);
            if ($line !== null) {
                $remaining -= $takeQty;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Charge `$seats` addon seats for the window — the per-seat counterpart of
     * {@see seatsChargeLine()}. Null when the addon has no per-seat price in the new
     * interval or nothing is charged.
     */
    private function addonSeatsChargeLine(PlanChangeIntent $intent, string $code, int $seats, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ProrataLine
    {
        $seatPrice = $this->catalog->addonSeatPriceNet($code, $intent->newInterval);
        if ($seats <= 0 || $seatPrice === null || $seatPrice <= 0) {
            return null;
        }

        ['factor' => $factor] = $this->periodMath($periodStart, $periodEnd);

        $chargeNet = (int) round($seatPrice * $seats * $factor);
        if ($chargeNet === 0) {
            return null;
        }

        $vat = $this->liveVat($intent->billable, $chargeNet);

        [$stampStart, $stampDays] = $this->chargeStamp($periodStart, $periodEnd);

        return new ProrataLine(
            originalInvoice: null,
            originalLineItemIndex: null,
            kind: 'addon_seats',
            code: $code,
            label: trans_choice('billing::portal.prorata_label_addon_seats_charge', $seats, [
                'count' => $seats,
                'addon' => $this->catalog->addonName($code) ?? $code,
                'plan' => $this->catalog->planName($intent->newPlan) ?? $intent->newPlan,
            ]),
            quantity: $seats,
            amountNet: $chargeNet,
            vatRate: $vat['rate'],
            amountVat: $vat['amount'],
            amountGross: $chargeNet + $vat['amount'],
            // Priced from the period, stamped from now — see chargeStamp().
            periodStart: $stampStart->copy(),
            periodEnd: $periodEnd->copy(),
            daysActive: $stampDays['total'],
            daysRemaining: $stampDays['remaining'],
            isCouponCovered: false,
            direction: 'charge',
        );
    }

    /**
     * Builds a refund line for a specific original line with a given quantity reduction.
     *
     * Returns null if nothing is left open on the original invoice (e.g. after a
     * previous goodwill full refund). Callers filter null.
     */
    private function buildRefundLine(BillingInvoice $invoice, int $idx, string $kind, ?string $code, int $quantity): ?ProrataLine
    {
        $line = $invoice->lineItem($idx);
        if ($line === null) {
            throw new \RuntimeException("Original-Line-Item $idx nicht gefunden in Invoice {$invoice->id}.");
        }

        $originalQty = max(1, (int) ($line['quantity'] ?? 1));
        $originalCashNet = $this->paidLineNet($invoice, $line);
        $vatRate = (float) ($line['vat_rate'] ?? 0);

        // Resolve period with fallback to billable period (for legacy invoices without line/invoice period).
        /** @var Billable&\Illuminate\Database\Eloquent\Model $billable */
        $billable = $invoice->billable()->first();
        $billableStart = $billable?->getBillingPeriodStartsAt() ?? BillingTime::nowUtc();
        $billableEnd = $billable?->nextBillingDate() ?? BillingTime::addInterval(BillingTime::nowUtc(), 'monthly');
        [$lineStart, $lineEnd] = $this->resolveLinePeriod($line, $invoice, $billableStart, $billableEnd);

        ['days' => $days, 'factor' => $factor] = $this->periodMath($lineStart, $lineEnd);
        $itemDaysActive = $days['total'];
        $itemDaysRemaining = $days['remaining'];

        $label = $this->buildRefundLabel($invoice, $kind, $code, $quantity);

        $isCouponCovered = $originalCashNet === 0;

        if ($isCouponCovered) {
            return new ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: $idx,
                kind: $kind,
                code: $code,
                label: $label,
                quantity: $quantity,
                amountNet: 0,
                vatRate: $vatRate,
                amountVat: 0,
                amountGross: 0,
                periodStart: $lineStart,
                periodEnd: $lineEnd,
                daysActive: $itemDaysActive,
                daysRemaining: $itemDaysRemaining,
                isCouponCovered: true,
                direction: 'refund',
            );
        }

        // Refund a `quantity/originalQty` share of the cash actually booked on the line —
        // never a rounded per-unit price times quantity. For an original whose amount_net
        // is not divisible by its quantity (the normal case for a prorated mid-cycle seat
        // invoice) rounding the unit up first refunds more than was ever paid: 1001 over
        // 2 seats becomes 501 per seat, and a full-quantity refund at factor 1 sends
        // 1002. The per-invoice pool cap bounds the invoice total, not the single line,
        // so on a multi-line invoice those cents really do leave for Mollie and inflate
        // `refunded_net`.
        // Capped at the share being refunded, not at the whole line. `periodMath()` clamps
        // `remaining` at zero but never at the total, so a line whose `period_start` lies in
        // the future yields a factor above 1 — and bounding one seat against all three let
        // it be credited for more than it cost: quantity 3 at 1500, factor 30/27, one seat
        // out came to 556 for a seat that was paid 500. The plan path already caps at the
        // right granularity ({@see planRefundLine()}); the per-invoice pool bounds the
        // invoice total, so it does not catch a single inflated unit either.
        $refundableShare = (int) floor($originalCashNet * $quantity / $originalQty);

        $refundNet = min(
            (int) round($originalCashNet * $quantity / $originalQty * $factor),
            $refundableShare,
        );
        if ($refundNet <= 0) {
            return null;
        }

        $refundVat = (int) round($refundNet * $vatRate / 100);
        $refundGross = $refundNet + $refundVat;

        // No per-line cap here — the composer applies a per-invoice remaining-pool
        // cap at the end so plan + seats + addons on the same invoice share a budget.
        return new ProrataLine(
            originalInvoice: $invoice,
            originalLineItemIndex: $idx,
            kind: $kind,
            code: $code,
            label: $label,
            quantity: $quantity,
            amountNet: -$refundNet,
            vatRate: $vatRate,
            amountVat: -$refundVat,
            amountGross: -$refundGross,
            periodStart: $lineStart,
            periodEnd: $lineEnd,
            daysActive: $itemDaysActive,
            daysRemaining: $itemDaysRemaining,
            isCouponCovered: false,
            direction: 'refund',
        );
    }

    /**
     * Builds a consistent label for a refund line, with plan context from the original invoice.
     *
     * The plan code is read from the first plan line item of the original invoice — if none
     * exists (e.g. mid-cycle seat-increase invoice without a plan line), it falls back to the
     * current plan of the billable.
     */
    private function buildRefundLabel(BillingInvoice $invoice, string $kind, ?string $code, int $quantity): string
    {
        $planCode = $this->resolveOriginalPlanCode($invoice);
        $planName = $planCode !== null
            ? ($this->catalog->planName($planCode) ?? $planCode)
            : ($invoice->billable()->first()?->getCurrentBillingPlanName() ?? '');

        return match ($kind) {
            'seats' => trans_choice('billing::portal.prorata_label_seats_refund', $quantity, ['count' => $quantity, 'plan' => $planName]),
            'addon_seats' => trans_choice('billing::portal.prorata_label_addon_seats_refund', $quantity, [
                'count' => $quantity,
                'addon' => $code !== null ? ($this->catalog->addonName($code) ?? $code) : '',
                'plan' => $planName,
            ]),
            'addon' => __('billing::portal.prorata_label_addon_refund', [
                'addon' => $code !== null ? ($this->catalog->addonName($code) ?? $code) : '',
                'plan' => $planName,
            ]),
            default => $planName,
        };
    }

    /**
     * Reads the plan code from the first plan line item of the invoice. Mid-cycle charge invoices
     * (e.g. seat increase only) often carry no plan line — then return null.
     */
    private function resolveOriginalPlanCode(BillingInvoice $invoice): ?string
    {
        foreach ((array) ($invoice->line_items ?? []) as $line) {
            if (($line['kind'] ?? null) === 'plan' && ! empty($line['code'])) {
                return (string) $line['code'];
            }
        }

        return null;
    }

    /**
     * Live-VAT-Berechnung aus Billable-Status.
     *
     * @return array{rate: float, amount: int}
     */
    private function liveVat(Billable $billable, int $netAmount): array
    {
        $country = (string) ($billable->getBillingCountry() ?? (string) config('mollie-billing.checkout_country_fallback', 'DE'));

        $vat = $this->vatService->calculate($country, $netAmount, $billable);

        return [
            'rate' => (float) $vat['rate'],
            'amount' => (int) $vat['vat'],
        ];
    }
}

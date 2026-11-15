<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;

class PreviewService
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly CouponService $couponService,
    ) {
    }

    public function previewPlanChange(Billable $billable, string $planCode, string $interval): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            planCode: $planCode,
            interval: $interval,
        ));
    }

    public function previewSeatChange(Billable $billable, int $seats): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            seats: $seats,
        ));
    }

    public function previewAddonChange(Billable $billable, array $addons): array
    {
        return $this->previewUpdate($billable, new SubscriptionUpdateRequest(
            addons: $addons,
        ));
    }

    public function previewUpdate(Billable $billable, array|SubscriptionUpdateRequest $request): array
    {
        $dto = SubscriptionUpdateRequest::from($request);

        $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
        $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $currentSeats = $billable->getBillingSeatCount();
        $currentAddons = $this->currentAddonQuantities($billable);

        $newPlan = $dto->planCode ?? $currentPlan;
        $newInterval = $dto->interval ?? $currentInterval;
        $planChanged = $dto->planCode !== null && $dto->planCode !== $currentPlan;

        // Auto-filter incompatible addons when the plan changes.
        $incompatibleAddons = [];
        // Normalised first. `SubscriptionUpdateRequest` passes `addons` through untouched
        // and `UpdateSubscription::normalizeAddonCodes()` documents support for both a
        // list of codes and a code => quantity map — and the package's own addons screen
        // passes the list form. Read as a map, its integer keys made every addon vanish
        // from `newPriceNet`, from the line items and from the intent: the coupon discount
        // was quoted against the plan alone (−5,80 shown, −6,80 charged), and with another
        // addon already active the composer emitted refund lines for one the customer was
        // keeping.
        $newAddons = self::normalizeAddonQuantities($dto->addons) ?? $currentAddons;

        if ($planChanged) {
            $filteredAddons = [];
            foreach ($newAddons as $code => $qty) {
                if ($this->catalog->planAllowsAddon($newPlan, (string) $code)) {
                    $filteredAddons[$code] = $qty;
                } else {
                    $incompatibleAddons[] = (string) $code;
                }
            }
            $newAddons = $filteredAddons;
        }

        // Auto-derive seats with A+C strategy.
        $usedSeats = $billable->getUsedBillingSeats();
        $newIncludedSeats = $this->catalog->includedSeats($newPlan);
        $seatPriceNet = $this->catalog->seatPriceNet($newPlan, $newInterval);
        // A plan change lands on the seats in use or included by the new plan; anything
        // else keeps the count. See BillingPolicy::derivedSeats().
        $newSeats = $dto->seats ?? BillingPolicy::derivedSeats($currentSeats, $usedSeats, $newIncludedSeats, $planChanged);

        $warnings = [];
        $errors = [];

        // Seat validation: block if plan doesn't support extra seats.
        if ($dto->seats === null && $seatPriceNet === null) {
            if ($usedSeats > $newIncludedSeats) {
                // Real team members exceed the new plan's quota — caller must
                // remove members manually before downgrading.
                $errors[] = [
                    'type' => 'seats_exceed_plan',
                    'used' => $usedSeats,
                    'included' => $newIncludedSeats,
                ];
            } elseif (! $planChanged && $currentSeats > $newIncludedSeats) {
                // Paid (but unused) extra seats would be lost on a plan that
                // can't sell them. Resolvable by activating the drop-extra-seats
                // toggle in the UI (sets `seats` explicitly).
                $errors[] = [
                    'type' => 'paid_seats_lost',
                    'current' => $currentSeats,
                    'included' => $newIncludedSeats,
                    'lost' => $currentSeats - $newIncludedSeats,
                ];
            }
        }

        $currentNet = $this->computeAmountNet(
            $billable,
            $currentPlan,
            $currentInterval,
            $currentSeats,
            $currentAddons,
        );

        $newNet = $this->computeAmountNet(
            $billable,
            $newPlan,
            $newInterval,
            $newSeats,
            $newAddons,
        );

        $lineItems = $this->buildLineItems(
            $billable,
            $newPlan,
            $newInterval,
            $newSeats,
            $newAddons,
        );

        // Coupon handling — iterate over all applied coupon codes (stackable).
        // We split the resolution into two separate discount totals:
        //   - $couponDiscountNet         applies to the recurring price (only Recurring-type)
        //   - $prorataCouponDiscountNet  applies to the prorata charge ("due now"), fed by
        //                                the same Recurring coupons. SinglePayment codes are
        //                                refused by validate() below (`allowed_types`), the
        //                                same way UpdateSubscription refuses them — so the
        //                                branch that used to collect them here was
        //                                unreachable, and it was also the copy missing the
        //                                `min(…, $remainingProrata)` clamp its live twin has.
        // Other coupon types (Credits, TrialExtension, AccessGrant, PeriodExtension)
        // have no monetary effect on plan changes and are silently skipped here —
        // they get redeemed in `UpdateSubscription::update()` for their side effects.
        $couponDiscountNet = 0;
        $prorataCouponDiscountNet = 0;
        $existingCouponIds = [];
        $remainingRecurring = $newNet;
        /** @var list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}> */
        $resolvedRecurringCoupons = [];
        foreach ($dto->couponCodes as $code) {
            try {
                $coupon = $this->couponService->validate(
                    $code,
                    $billable,
                    [
                        'planCode' => $newPlan,
                        'interval' => $newInterval,
                        'addonCodes' => array_keys($newAddons),
                        'orderAmountNet' => $remainingRecurring,
                        'existingCouponIds' => $existingCouponIds,
                        // Mirrors UpdateSubscription — only Recurring is valid on
                        // plan-change / seat-sync / addon-enable.
                        'allowed_types' => [\GraystackIT\MollieBilling\Enums\CouponType::Recurring],
                    ],
                );
                $existingCouponIds[] = $coupon->id;

                if ($coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::Recurring) {
                    $thisDiscount = $this->couponService->computeRecurringDiscount($coupon, $remainingRecurring);
                    $couponDiscountNet += $thisDiscount;
                    $remainingRecurring = max(0, $remainingRecurring - $thisDiscount);
                    $resolvedRecurringCoupons[] = ['coupon' => $coupon, 'discount' => $thisDiscount];

                    if ($thisDiscount > 0) {
                        $lineItems[] = [
                            'kind' => 'coupon',
                            'label' => 'Coupon '.$coupon->code,
                            'code' => (string) $coupon->code,
                            'quantity' => 1,
                            'unit_price_net' => -$thisDiscount,
                            'total_net' => -$thisDiscount,
                        ];
                    }
                }
            } catch (InvalidCouponException $e) {
                $warnings[] = 'Coupon '.$code.' is not applicable: '.$e->getMessage();
            } catch (\RuntimeException $e) {
                $warnings[] = 'Coupon '.$code.' cannot be applied: '.$e->getMessage();
            }
        }

        // Seat-downgrade warning
        if ($dto->seats !== null) {
            $includedSeats = $this->catalog->includedSeats($newPlan);
            $minimumRequired = max($usedSeats, $includedSeats);
            if ($dto->seats < $minimumRequired) {
                $warnings[] = 'seats below active count';
            }
        }

        // Prorata
        $intervalChanged = $dto->interval !== null && $dto->interval !== $currentInterval;

        // Adding seats or a paid addon while past due is refused by the validator; the
        // preview says so as a blocking error instead of quoting "0.00 due now".
        try {
            app(ValidateSubscriptionChange::class)->assertNoPastDueExpansion(
                $billable,
                $planChanged || $intervalChanged,
                $dto->seats !== null,
                $currentSeats,
                $newSeats,
                array_values(array_diff(array_map('strval', array_keys(array_filter($newAddons, fn ($q): bool => (int) $q > 0))), array_keys($currentAddons))),
                $newInterval,
            );
        } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException $e) {
            $errors[] = ['type' => 'past_due_expansion', 'message' => $e->getMessage()];
        }

        // The change itself is refused (ValidateSubscriptionChange::assertIntervalChangeAllowed);
        // the preview says so instead of quoting a price for it.
        if ($intervalChanged && app(PlanWaiver::class)->marker($billable) !== null) {
            $errors[] = ['type' => 'interval_change_under_waiver'];
        }
        $prorataFactor = 0.0;
        $prorataChargeNet = 0;
        $prorataCreditNet = 0;
        $prorataRemainingDays = 0;
        $prorataTotalDays = 0;
        $isPastDueReset = BillingPolicy::isPastDueReset($billable, $planChanged, $intervalChanged);

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();

        if ($isPastDueReset) {
            // Past-Due-Reset: the current period was never paid (last charge
            // failed), so a plan change here is a fresh start. Charge the full
            // first period of the new plan, no credit (nothing was paid).
            // Mirrors ProrataComposer::composePastDueReset().
            $prorataFactor = 1.0;
            $prorataChargeNet = $newNet;
            $prorataCreditNet = 0;
        } elseif (
            ($planChanged || $intervalChanged)
            && $periodStart !== null
            && $periodEnd !== null
        ) {
            $prorata = BillingPolicy::computeProrata($currentNet, $newNet, $intervalChanged, $periodStart, $periodEnd);
            $prorataFactor = $prorata['factor'];
            $prorataChargeNet = $prorata['charge_net'];
            $prorataCreditNet = $prorata['credit_net'];

            $days = BillingPolicy::prorataPeriodDays($periodStart, $periodEnd);
            $prorataRemainingDays = $days['remaining'];
            $prorataTotalDays = $days['total'];
        }

        // Coupon discounts on the prorata charge ("due now"):
        //   - A Recurring coupon's rate applies directly against the prorata charge net
        //     (the actual money flowing "now"), not against the full recurring net. Its
        //     ongoing discount on future renewals is independent and lives in the
        //     active_recurring_coupon marker.
        //   - All discounts are capped at the remaining prorata charge net so
        //     the line can never go negative.
        $remainingProrata = $prorataChargeNet;
        foreach ($resolvedRecurringCoupons as &$entry) {
            if ($remainingProrata <= 0) {
                break;
            }
            $thisDiscount = $this->couponService->computeRecurringDiscount($entry['coupon'], $remainingProrata);
            $thisDiscount = min($thisDiscount, $remainingProrata);
            if ($thisDiscount > 0) {
                $entry['prorataDiscount'] = $thisDiscount;
                $prorataCouponDiscountNet += $thisDiscount;
                $remainingProrata -= $thisDiscount;
            }
        }
        unset($entry);
        unset($entry);

        $prorataChargeNet = max(0, $prorataChargeNet - $prorataCouponDiscountNet);

        // VAT on recurring price.
        // Display-only: trust that a stored vat_number means reverse-charge.
        // The actual VIES check happens at checkout / billing-data save — by the
        // time we render the plan-change preview, that decision is already made
        // and persisted as a BillingVatValidation. calculate() reads it directly.
        $country = $billable->getBillingCountry() ?? (string) config('mollie-billing.checkout_country_fallback', 'DE');

        // Apply an existing active recurring coupon marker to the preview as well —
        // the user keeps seeing the discount they signed up for across plan/seat
        // changes, capped at the original base_amount_net (no extra rabatt for
        // additions made after coupon-apply).
        // Skip this when the user just entered the SAME coupon again in this update —
        // that case is already covered by $couponDiscountNet from the loop above.
        $existingMarker = $this->couponService->getActiveRecurringCouponMarker($billable);
        $existingMarkerCouponId = is_array($existingMarker) ? (int) ($existingMarker['coupon_id'] ?? 0) : 0;
        $alreadyCountedAsNew = false;
        foreach ($resolvedRecurringCoupons as $entry) {
            if ((int) $entry['coupon']->id === $existingMarkerCouponId && $existingMarkerCouponId > 0) {
                $alreadyCountedAsNew = true;
                break;
            }
        }
        $existingMarkerDiscountNet = (! $alreadyCountedAsNew)
            ? $this->couponService->computeMarkerDiscount($billable, $newNet, [
                // The configuration being previewed: a plan waiver's ceiling follows the
                // plan line of the plan the customer is moving TO.
                'plan_code' => $newPlan,
                'interval' => $newInterval,
                'addon_codes' => array_map('strval', array_keys($newAddons)),
                'seats' => $newSeats,
            ])
            : 0;

        // A change that opens a new period (an interval switch, a past-due reset) puts the
        // next recurring charge a full new interval away. This is the price of THAT
        // charge, so the discount counts only if it is still running then — the same
        // judgement ProrataChargeHandler makes before it PATCHes Mollie. A coupon with
        // two months left does not discount the renewal of a year bought today.
        if ($existingMarkerDiscountNet > 0
            && ($intervalChanged || $isPastDueReset)
            && is_array($existingMarker)
            && ! PlanWaiver::isWaiver($existingMarker)
            && $this->couponService->markerExpired($billable, BillingTime::addInterval(BillingTime::nowUtc(), $newInterval))
        ) {
            $existingMarkerDiscountNet = 0;
        }
        if ($existingMarkerDiscountNet > 0) {
            $couponDiscountNet += $existingMarkerDiscountNet;
            $lineItems[] = [
                'kind' => 'coupon',
                'label' => 'Coupon '.($existingMarker['code'] ?? ''),
                'code' => (string) ($existingMarker['code'] ?? ''),
                'quantity' => 1,
                'unit_price_net' => -$existingMarkerDiscountNet,
                'total_net' => -$existingMarkerDiscountNet,
            ];
        }

        $netForRecurring = max(0, $newNet - $couponDiscountNet);

        try {
            $vat = $this->vatService->calculate($country, $netForRecurring, $billable);
        } catch (\Throwable $e) {
            $vat = ['net' => $netForRecurring, 'vat' => 0, 'gross' => $netForRecurring, 'rate' => 0.0];
            $warnings[] = 'VAT calculation unavailable: '.$e->getMessage();
        }

        // The gross of the recurring total, with VAT per line — not one rounding over
        // the summed net that `calculate()` returns.
        //
        // `grossTotal` is not only displayed: the Local→Mollie upgrade charges exactly
        // this figure (`plan-change.blade.php::confirmAndPay()`). The webhook that
        // books it rebuilds the same lines and sums their per-line VAT, so the two
        // roundings disagreed — 4 lines at 5.50 € and 19 % charge 26.18 while the
        // invoice books 26.20. That fired PaymentAmountMismatch on a legitimate
        // payment and, worse, left `refundFully()` asking Mollie for more than the
        // payment held, so the refund was rejected and the invoice could never be
        // closed out. `recurringLineNets()` is the declared authority for this
        // breakdown (see CreateSubscription), so the charge, the Mollie subscription
        // amount and the invoice now all round the same way.
        $recurringGross = BillingMoney::grossWithLineVat(
            MollieSubscriptionPatcher::recurringLineNets(
                $this->catalog,
                $billable,
                $newPlan,
                $newInterval,
                // Codes, not the code => quantity map: SubscriptionAmount::lineItems()
                // iterates the values and reads each quantity off the billable.
                array_keys($newAddons),
                max(0, $newSeats - $newIncludedSeats),
                $netForRecurring,
            ),
            (float) $vat['rate'],
        );

        // Two rates, because the two sides of a prorata are two different supplies.
        //
        // The credit reverses part of an invoice that was already issued, so it carries that
        // invoice's line rate — a customer who has since become reverse-charge-eligible still
        // gets back the VAT they actually paid. The charge is a *new* supply and is priced by
        // `ProrataComposer::planChargeLine()` through `liveVat()`, i.e. the billable's
        // current country and reverse-charge state. Applying the old invoice's rate to it
        // reported VAT on a supply that carries none (and the wrong amount whenever a rate
        // changed), which the aggregate figures rendered as "due now".
        $creditRate = (float) $vat['rate'];
        $candidates = BillingInvoice::currentPeriodLines($billable, 'plan', $currentPlan);

        if (! empty($candidates)) {
            $first = $candidates[0];
            $line = $first['invoice']->lineItem($first['line_index']) ?? [];
            $creditRate = (float) ($line['vat_rate'] ?? 0);
        }

        $prorataVat = ['net' => 0, 'vat' => 0, 'gross' => 0, 'rate' => $creditRate];

        if ($prorataChargeNet > 0) {
            try {
                $prorataVat = $this->vatService->calculate($country, $prorataChargeNet, $billable);
            } catch (\Throwable) {
                $prorataVat = ['net' => $prorataChargeNet, 'vat' => 0, 'gross' => $prorataChargeNet, 'rate' => 0.0];
            }
        }

        // Usage comparison with prorated excess calculation
        $usageChanges = [];
        $usageOverageChargeNet = 0;

        foreach ($this->catalog->allUsageTypes() as $usageType) {
            $rollover = $this->catalog->usageRollover($usageType);
            $currentQuota = $this->catalog->includedUsage($currentPlan, $currentInterval, $usageType);
            $newQuota = $this->catalog->includedUsage($newPlan, $newInterval, $usageType);
            if ($currentQuota <= 0 && $newQuota <= 0) {
                continue;
            }

            $wallet = $billable instanceof \Illuminate\Database\Eloquent\Model
                ? $billable->getWallet($usageType)
                : null;
            $walletBalance = (int) ($wallet?->balanceInt ?? 0);

            // Separate purchased credits from plan credits.
            $purchasedBalance = $wallet !== null ? WalletUsageService::getPurchasedBalance($wallet) : 0;
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $walletBalance);
            $planOnlyBalance = $walletBalance - $purchasedRemaining;

            $excess = 0;
            $proratedOldQuota = 0;
            $actuallyUsed = max(0, $currentQuota - $planOnlyBalance);

            // No `$planChanged` here: included quotas are keyed by (plan, interval), so an
            // interval switch changes the quota exactly as much as a plan switch does.
            // `WalletPlanChangeAdjuster` and `validateWalletUsage()` both gate on the old
            // quota alone and both run for interval-only changes — so the preview was the
            // one path that skipped the calculation, and it is the only one that has to
            // tell the customer. It quoted €0.00 for usage and the change then charged the
            // prorated excess.
            if ($periodStart !== null && $periodEnd !== null && $currentQuota > 0) {
                $usageProrata = BillingPolicy::computeUsageOverageForPlanChange(
                    $currentQuota,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $usageProrata['excess'];
                $proratedOldQuota = $usageProrata['prorated_old_quota'];
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $currentQuota) : 0;
            $effectiveNewQuota = max(0, $newQuota + $rolloverCredits + $purchasedRemaining - $excess);
            $unresolvedOverage = max(0, $excess - $newQuota - $rolloverCredits - $purchasedRemaining);

            $overagePrice = (int) ($this->catalog->usageOveragePrice($currentPlan, $currentInterval, $usageType) ?? 0);
            $overageTotalNet = $unresolvedOverage * $overagePrice;
            $usageOverageChargeNet += $overageTotalNet;

            $usageChanges[$usageType] = [
                'current' => $currentQuota,
                'new' => $newQuota,
                'diff' => $newQuota - $currentQuota,
                'actually_used' => $actuallyUsed,
                'prorated_old_quota' => $proratedOldQuota,
                'excess' => $excess,
                'rollover_credits' => $rolloverCredits,
                'purchased_remaining' => $purchasedRemaining,
                'offset_by_new_plan' => min($excess, $newQuota + $rolloverCredits + $purchasedRemaining),
                'effective_new_quota' => $effectiveNewQuota,
                'unresolved_overage' => $unresolvedOverage,
                'overage_unit_price_net' => $overagePrice,
                'overage_total_net' => $overageTotalNet,
            ];
        }

        // VAT on usage overage — per line, exactly as the charge that follows builds it.
        // `ChargeUsageOverageDirectly` grosses one line per metered usage type through
        // BillingMoney::grossWithLineVat(), so grossing the summed net here quoted a figure
        // the customer would not be charged: with several usage types the per-line residuals
        // add up, and the preview is what the confirm screen shows before the money moves.
        $usageOverageChargeGross = 0;
        if ($usageOverageChargeNet > 0) {
            try {
                $rate = (float) $this->vatService->calculate($country, $usageOverageChargeNet, $billable)['rate'];

                $overageLineNets = array_values(array_filter(
                    array_map(static fn (array $change): int => (int) ($change['overage_total_net'] ?? 0), $usageChanges),
                    static fn (int $net): bool => $net > 0,
                ));

                $usageOverageChargeGross = BillingMoney::grossWithLineVat($overageLineNets, $rate);
            } catch (\Throwable) {
                $usageOverageChargeGross = $usageOverageChargeNet;
            }
        }

        // Seat comparison
        $currentIncludedSeats = $currentPlan !== '' ? $this->catalog->includedSeats($currentPlan) : 0;
        $extraSeatsCharged = max(0, $newSeats - $newIncludedSeats);

        return [
            'currentPlanCode' => $currentPlan,
            'currentPlanName' => $currentPlan !== '' ? ($this->catalog->planName($currentPlan) ?? $currentPlan) : null,
            'currentInterval' => $currentInterval,
            'newPlanCode' => $newPlan,
            'newPlanName' => $newPlan !== '' ? ($this->catalog->planName($newPlan) ?? $newPlan) : null,
            'newInterval' => $newInterval,
            'planChanged' => $planChanged,
            'intervalChanged' => $intervalChanged,
            'usedSeats' => $usedSeats,
            'currentSeats' => $currentSeats,
            'newSeats' => $newSeats,
            'currentIncludedSeats' => $currentIncludedSeats,
            'newIncludedSeats' => $newIncludedSeats,
            'extraSeatsCharged' => $extraSeatsCharged,
            'seatPriceNet' => $seatPriceNet,
            'incompatibleAddons' => $incompatibleAddons,
            'usageChanges' => $usageChanges,
            'currentPriceNet' => $currentNet,
            'newPriceNet' => $newNet,
            'diffNet' => $newNet - $currentNet,
            'prorataFactor' => $prorataFactor,
            'prorataRemainingDays' => $prorataRemainingDays,
            'prorataTotalDays' => $prorataTotalDays,
            'prorataChargeNet' => $prorataChargeNet,
            'prorataChargeGross' => $prorataChargeNet > 0 ? (int) $prorataVat['gross'] : 0,
            'prorataChargeVat' => $prorataChargeNet > 0 ? (int) $prorataVat['vat'] : 0,
            'prorataCreditNet' => $prorataCreditNet,
            'prorataCreditVat' => $prorataCreditNet > 0 ? (int) round($prorataCreditNet * $creditRate / 100) : 0,
            'prorataCreditGross' => $prorataCreditNet > 0
                ? $prorataCreditNet + (int) round($prorataCreditNet * $creditRate / 100)
                : 0,
            'isPastDueReset' => $isPastDueReset,
            // The credit that is actually being given, not "what a full period would be
            // worth". On a past-due reset the factor is forced to 1.0 while
            // `prorataCreditNet` is deliberately 0 — nothing was paid — so this reported a
            // full-period credit in the one case where there is none.
            'currentPeriodCredit' => $isPastDueReset ? 0 : ($periodStart !== null && $periodEnd !== null
                ? (int) round($currentNet * $prorataFactor)
                : 0),
            'usageOverageChargeNet' => $usageOverageChargeNet,
            'usageOverageChargeGross' => $usageOverageChargeGross,
            'couponDiscountNet' => $couponDiscountNet,
            'vatRate' => $vat['rate'],
            'vatAmount' => $recurringGross - $netForRecurring,
            'grossTotal' => $recurringGross,
            'reverseCharge' => $this->vatService->reverseChargeApplies($billable, $country),
            'lineItems' => $lineItems,
            'warnings' => $warnings,
            'errors' => $errors,
            'appliesAt' => $dto->applyAt === 'end_of_period'
                ? ($periodEnd ?? 'immediate')
                : 'immediate',
            'planChangeMode' => config('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice)->value,
            'isUpgrade' => BillingPolicy::isUpgrade(
                $this->catalog, $currentPlan, $currentInterval, $newPlan, $newInterval,
            ),
            'isDowngrade' => BillingPolicy::isDowngrade(
                $this->catalog, $currentPlan, $currentInterval, $newPlan, $newInterval,
            ),
            // Multi-VAT breakdown via Composer (UI source of truth for the new preview).
            // The aggregate fields above (prorataChargeNet etc.) remain for backwards compatibility.
            // Coupon discount lines are appended as negative rows — the Composer
            // itself has no coupon awareness, that happens here.
            ...$this->prorataLinesPreview(
                $billable,
                $currentPlan,
                $newPlan,
                $currentInterval,
                $newInterval,
                $currentSeats,
                $newSeats,
                $currentAddons,
                $newAddons,
                $this->buildProrataCouponDiscountLines($resolvedRecurringCoupons),
            ),
        ];
    }

    /**
     * Build the per-coupon discount lines for the prorata charge ("due now").
     *
     * Renders the discount that was actually applied to the prorata charge — already
     * pre-computed in the loop above as `$entry['prorataDiscount']`. No further scaling
     * here.
     *
     * @param  list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int, prorataDiscount?: int}>  $recurringCoupons
     * @return list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}>
     */
    private function buildProrataCouponDiscountLines(array $recurringCoupons): array
    {
        $out = [];

        foreach ($recurringCoupons as $entry) {
            $proratedDiscount = (int) ($entry['prorataDiscount'] ?? 0);

            if ($proratedDiscount > 0) {
                $out[] = ['coupon' => $entry['coupon'], 'discount' => $proratedDiscount];
            }
        }

        return $out;
    }

    /**
     * Builds the prorataLines via ProrataComposer for the new UI breakdown.
     *
     * @param  array<string, int>  $currentAddons
     * @param  array<string, int>  $newAddons
     * @param  list<array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}>  $couponDiscountLines
     *         Coupon entries with their already-computed discount net amount on the
     *         prorata charge. Rendered as additional negative lines under `kind=coupon`.
     * @return array{prorataLines: list<array<string, mixed>>, prorataTotalNet: int, prorataTotalVat: int, prorataTotalGross: int, prorataRefundCapNotices: list<array<string, mixed>>}
     */
    private function prorataLinesPreview(
        Billable $billable,
        string $currentPlan,
        string $newPlan,
        string $currentInterval,
        string $newInterval,
        int $currentSeats,
        int $newSeats,
        array $currentAddons,
        array $newAddons,
        array $couponDiscountLines = [],
    ): array {
        if ($currentPlan === '' || $newPlan === '') {
            return ['prorataLines' => [], 'prorataTotalNet' => 0, 'prorataTotalVat' => 0, 'prorataTotalGross' => 0, 'prorataRefundCapNotices' => []];
        }

        try {
            $intent = new PlanChangeIntent(
                billable: $billable,
                currentPlan: $currentPlan,
                newPlan: $newPlan,
                currentInterval: $currentInterval,
                newInterval: $newInterval,
                currentSeats: $currentSeats,
                newSeats: $newSeats,
                currentAddons: $currentAddons,
                newAddons: $newAddons,
            );

            $lines = app(ProrataComposer::class)->compose($intent);
        } catch (\Throwable) {
            // On data inconsistency (e.g. missing original lines) return empty.
            // Composer throws RuntimeException — the preview must not crash.
            return ['prorataLines' => [], 'prorataTotalNet' => 0, 'prorataTotalVat' => 0, 'prorataTotalGross' => 0, 'prorataRefundCapNotices' => []];
        }

        $totalNet = 0;
        $totalVat = 0;
        $totalGross = 0;
        $serialized = [];
        $refundCapNotices = [];

        foreach ($lines as $line) {
            $serialized[] = $line->toArray();
            $totalNet += $line->amountNet;
            $totalVat += $line->amountVat;
            $totalGross += $line->amountGross;

            if ($line->refundCapNote !== null) {
                $refundCapNotices[] = [
                    'kind' => $line->kind,
                    'code' => $line->code,
                    'invoiceSerial' => $line->originalInvoice?->serial_number,
                    ...$line->refundCapNote,
                ];
            }
        }

        // Append coupon discount lines (negative amounts) so the "due now" panel
        // mirrors the recurring panel and the user sees exactly what each coupon
        // contributes. VAT rate falls back to the plan-line rate of the new plan.
        $vatRate = 0.0;
        foreach ($lines as $line) {
            if ($line->kind === 'plan' && $line->vatRate > 0) {
                $vatRate = (float) $line->vatRate;
                break;
            }
        }

        foreach ($couponDiscountLines as $entry) {
            $discountNet = (int) ($entry['discount'] ?? 0);
            if ($discountNet <= 0) {
                continue;
            }
            $vatAmount = (int) round($discountNet * $vatRate / 100);
            $serialized[] = [
                'kind' => 'coupon',
                'category' => 'coupon',
                'direction' => 'charge',
                'label' => __('billing::portal.coupon_label', ['code' => (string) $entry['coupon']->code]),
                'amount_net' => -$discountNet,
                'vat_amount' => -$vatAmount,
                'amount_gross' => -($discountNet + $vatAmount),
                'vat_rate' => $vatRate,
                'is_coupon_covered' => false,
                'days_remaining' => 0,
            ];
            $totalNet -= $discountNet;
            $totalVat -= $vatAmount;
            $totalGross -= ($discountNet + $vatAmount);
        }

        return [
            'prorataLines' => $serialized,
            'prorataTotalNet' => $totalNet,
            'prorataTotalVat' => $totalVat,
            'prorataTotalGross' => $totalGross,
            'prorataRefundCapNotices' => $refundCapNotices,
        ];
    }

    /**
     * Net of a configuration — through SubscriptionAmount, the same call the charge, the
     * Mollie subscription amount and the renewal invoice make.
     *
     * This used to be a second implementation of that arithmetic. Two copies of a price
     * rule are two prices the moment one of them learns something new (per-seat addon
     * pricing was the occasion), and the preview is the one that is shown to the customer
     * before the money moves.
     *
     * @param  array<string, int>  $addons  code => quantity; only the codes are used —
     *                                      quantities are read off the billable, as
     *                                      everywhere else
     */
    private function computeAmountNet(
        Billable $billable,
        string $planCode,
        string $interval,
        int $seats,
        array $addons,
    ): int {
        return SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, self::activeCodes($addons));
    }

    /**
     * @param  array<string, int>  $addons
     * @return array<int, array<string, mixed>>
     */
    private function buildLineItems(
        Billable $billable,
        string $planCode,
        string $interval,
        int $seats,
        array $addons,
    ): array {
        if ($planCode === '') {
            return [];
        }

        $extraSeats = max(0, $seats - $this->catalog->includedSeats($planCode));
        $sellsSeats = $this->catalog->seatPriceNet($planCode, $interval) !== null;

        $items = [];

        foreach (SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, self::activeCodes($addons)) as $line) {
            $isSeatLine = $line['kind'] === 'seats';

            // A plan that sells no extra seats shows no seat line, as before.
            if ($isSeatLine && ! $sellsSeats) {
                continue;
            }

            // The shape this preview has always returned for the plan's seat line.
            if ($isSeatLine) {
                $line['kind'] = 'seat';
                $line['code'] = 'seats';
            }

            $items[] = $line;
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $addons
     * @return array<int, string>
     */
    private static function activeCodes(array $addons): array
    {
        return array_values(array_map('strval', array_keys(array_filter($addons, static fn ($qty): bool => (int) $qty > 0))));
    }

    /**
     * Bring an `addons` payload into the one shape this service reads: code => quantity.
     *
     * Mirrors `UpdateSubscription::normalizeAddonCodes()`, which accepts both forms.
     *
     * @param  array<int|string, mixed>|null  $addons
     * @return array<string, int>|null
     */
    private static function normalizeAddonQuantities(?array $addons): ?array
    {
        if ($addons === null) {
            return null;
        }

        if (array_is_list($addons)) {
            return array_fill_keys(array_map('strval', $addons), 1);
        }

        $normalised = [];
        foreach ($addons as $code => $quantity) {
            $normalised[(string) $code] = (int) $quantity;
        }

        return $normalised;
    }

    /**
     * @return array<string, int>
     */
    private function currentAddonQuantities(Billable $billable): array
    {
        $out = [];
        foreach ($billable->getActiveBillingAddonCodes() as $code) {
            $out[$code] = $billable->getBillingAddonQuantity($code) ?: 1;
        }

        return $out;
    }
}

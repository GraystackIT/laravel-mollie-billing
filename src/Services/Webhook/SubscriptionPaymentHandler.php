<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingAnchor;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SubscriptionPaymentHandler
{
    use MutatesBillingMeta;

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly CouponService $couponService,
        protected readonly VatCalculationService $vatService,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly WalletUsageService $walletService,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly MollieSubscriptionPatcher $patcher,
    ) {
    }

    /**
     * Whether an overage charge covering this usage type is created but not settled.
     *
     * Lives on `WebhookSupport` since the paid trial conversion needs the same answer.
     */
    protected function hasUnsettledOverageFor(Billable $billable, string $usageType): bool
    {
        return $this->support->hasUnsettledOverageFor($billable, $usageType);
    }

    /**
     * Whether a recurring charge — settled or failed — still relates to a running
     * subscription.
     *
     * One predicate for both directions. `failed()` has refused non-billable states since
     * a charge for a subscription that had ended could otherwise restart the dunning
     * clock; `paid()` had no equivalent, which is the more expensive half.
     */
    protected function isBillableState(Billable $billable): bool
    {
        return in_array(
            $billable->getBillingSubscriptionStatus(),
            [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::PastDue],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for recurring payment, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        // A change scheduled for this period boundary has to be in place before the
        // renewal is priced — see ScheduleSubscriptionChange::applyAtRenewal(). Never
        // throws; a no-op without a due, pre-announced change.
        if (app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->applyAtRenewal($billable)) {
            $billable->refresh();
        }

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? (string) ($metadata['plan_code'] ?? '');
        $interval = $billable->getBillingSubscriptionInterval() ?? (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();
        // What the lines below are built from — see the write-back at the end.
        $seatCountAtStart = $billable->getBillingSubscriptionMeta()['seat_count'] ?? null;

        $newPaymentCountry = strtoupper((string) ($payment->countryCode ?? ''));
        if ($newPaymentCountry !== '' && $newPaymentCountry !== strtoupper((string) ($billable->tax_country_payment ?? ''))) {
            $billable->forceFill(['tax_country_payment' => $newPaymentCountry])->save();
        }

        // The three-way country check runs at the END of this method, not here — see the
        // call site below. `tax_country_payment` is written now because everything that
        // follows may read it; the *check* must not, because flagging a mismatch cancels
        // the subscription and this renewal would then be treated as money arriving for a
        // subscription that no longer runs.

        $seats = $this->catalog->includedSeats($planCode) + $extraSeats;
        $expectedNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);

        $pendingTrialCoupon = $this->resolvePendingTrialCoupon(
            $billable, $planCode, $interval, $addonCodes, $extraSeats
        );
        $pendingTrialDiscountNet = $pendingTrialCoupon !== null
            ? max(0, (int) $pendingTrialCoupon['discount_net'])
            : 0;

        // A parked single-payment coupon only counts if Mollie actually collected less.
        //
        // The coupon is stashed at trial start, but the Mollie subscription is created at the
        // full price and nothing reduces the trial-end charge — so the discount line was put
        // on the invoice, `reconcileWithPaidAmount()` cancelled it back out with an
        // `adjustment`, `PaymentAmountMismatch` fired, and the coupon was redeemed anyway:
        // the customer paid full price, the campaign lost a slot, and a redemption row
        // claimed a discount nobody received. This is the rule
        // `StartSubscriptionCheckout::amountReflectsCoupon()` applies on its own auto-apply
        // path — keep the coupon usable rather than book a promise that was not kept.
        if ($pendingTrialCoupon !== null
            && $pendingTrialDiscountNet > 0
            && ! $this->paidAmountReflects($payment, $expectedNet - $pendingTrialDiscountNet, $billable)
        ) {
            Log::warning('Parked trial coupon not applied — Mollie collected the undiscounted amount', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'coupon_code' => (string) $pendingTrialCoupon['coupon']->code,
                'discount_net' => $pendingTrialDiscountNet,
            ]);

            // Left in the slot on purpose: the code has not been honoured, so it must stay
            // spendable. The operator hears about it through the warning above.
            $pendingTrialCoupon = null;
            $pendingTrialDiscountNet = 0;
        }

        // The cycle this charge belongs to, and the day its amount was fixed.
        //
        // Mollie collects what the subscription held when it CREATED the charge, and that
        // was decided at the previous renewal by asking whether the discount would still
        // be valid on this cycle's day (`markerExpired()` below). The invoice has to ask
        // the same question about the same day — not about now. With SEPA the money
        // settles days later: a coupon that ended between the charge and its settlement
        // was collected at the discounted amount and then invoiced at full price, with an
        // `adjustment` line taking the difference back off, `PaymentAmountMismatch` fired
        // and no redemption written for a discount the customer did receive. Day
        // granularity on both sides, because Mollie creates the charge at some hour of
        // the cycle date that nobody controls.
        $newPeriodStart = $this->cycleStart($payment);
        // On the subscription's anchor day, so a cycle on the 31st that February moved to
        // the 28th returns to the 31st in March (BillingAnchor).
        $anchorDay = BillingAnchor::forCycle($billable, $billable->getBillingPeriodStartsAt(), $newPeriodStart, $interval);
        $newPeriodEnd = BillingAnchor::next($newPeriodStart, $interval, $anchorDay);
        $chargeDay = $newPeriodStart->copy()->startOfDay();
        $nextChargeDay = Carbon::instance($newPeriodEnd)->setTimezone('UTC')->startOfDay();

        $couponDiscountNet = $this->couponService->computeMarkerDiscount($billable, $expectedNet, [], $chargeDay);
        $netForCharge = max(0, $expectedNet - $couponDiscountNet - $pendingTrialDiscountNet);
        $vat = $this->vatService->calculate((string) ($billable->getBillingCountry() ?? ''), $netForCharge, $billable);

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        if ($couponDiscountNet > 0) {
            $marker = $this->couponService->getActiveRecurringCouponMarker($billable) ?? [];
            $markerCode = (string) ($marker['code'] ?? '');
            $line = $this->couponLine($couponDiscountNet, $markerCode);

            // A plan waiver says which lines it takes off, and until when — so the
            // invoice reads "plan free", and a later prorata credit leaves the free plan
            // alone while crediting the paid extras in full (ProrataComposer::paidLineNet).
            if (\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::isWaiver($marker)) {
                $line['applies_to'] = \GraystackIT\MollieBilling\Services\Billing\PlanWaiver::appliesTo($marker, $addonCodes);

                try {
                    $line['label'] = 'Plan free until '.Carbon::parse((string) $marker['valid_until'])->utc()->toDateString();
                } catch (\Throwable) {
                    // keep the generic label
                }
            }

            $lineItems[] = $line;
        }
        if ($pendingTrialCoupon !== null && $pendingTrialDiscountNet > 0) {
            $lineItems[] = $this->couponLine(
                $pendingTrialDiscountNet,
                (string) $pendingTrialCoupon['coupon']->code,
            );
        }

        // Reconciled, not merely reported. The lines above are rebuilt from the
        // catalog while Mollie charges whatever the subscription was last PATCHed
        // to, so a price change whose PATCH never landed makes them disagree — and
        // the money that cleared is the one fact. Booking the catalog amount instead
        // over-declared output VAT on every renewal and left `refunded_net` headroom
        // Mollie cannot honour, so a full refund would fail.
        //
        // The expected gross comes from the lines themselves: the recurring amount is
        // built from these same lines (MollieSubscriptionPatcher) and the invoice
        // header sums their per-line VAT, so `round(Σ net × rate)` was a third number
        // matching neither — six lines drift past the 1-cent slack every time.
        $lineItems = $this->support->reconcileWithPaidAmount(
            $payment,
            $billable,
            $lineItems,
            $this->support->netFromLineItems($lineItems),
            (float) ($vat['rate'] ?? 0.0),
        );


        // The window this payment bought, computed before the invoice rather than after it.
        //
        // The anchor on the billable still describes the period that just *ended* — it is
        // advanced further down, once the invoice exists — so leaving createForPayment() to
        // read it stamped the renewal invoice with the expired window. `currentPeriodLines()`
        // drops any line whose period_end is more than a day from the billable's own period
        // end, so it could not find the very invoice that paid for the current period: a
        // mid-period downgrade after a renewal composed its refund against nothing and
        // credited 0. MandateOnlyPaymentHandler writes its anchor before invoicing and
        // ProrataChargeHandler stamps the new window explicitly, for the same reason.
        //
        // And it starts on the cycle the charge was created for, not on the day the money
        // settled. For a card the two are the same minute. For SEPA Direct Debit Mollie
        // creates the charge on the cycle date and it settles one to five business days
        // later, a different number every month: anchored on `paidAt` a month that
        // settled faster than the one before overlapped it, a slower one left a hole, and
        // `nextBillingDate()` named a day up to a week after the one Mollie charges on —
        // the date the overage pass, every prorata window and the coupon-expiry check
        // below (`$newPeriodEnd` is "the next charge") all work from.
        // (`$newPeriodStart` / `$newPeriodEnd` are resolved further up, where the discount
        // is priced as of the same cycle.)

        $invoice = $this->salesInvoiceService->createForPayment(
            $payment,
            'subscription',
            $lineItems,
            $billable,
            $newPeriodStart,
            $newPeriodEnd,
        );

        // The same state rule `failed()` applies, and for the same reason — except this is
        // the side money actually arrives on. Nothing here stops Mollie from charging a
        // subscription whose local cancel never landed: CancelSubscription writes the
        // Cancelled status even when cancelAtMollie() fails (it leaves a
        // `pending_subscription_cancel` marker), and RetrySubscriptionCancelJob gives up
        // after 24h with a single admin mail. From then on every period was booked as an
        // ordinary renewal: period anchor advanced, every wallet reset and recharged,
        // "your invoice is ready" mailed to a customer who cancelled and has no access.
        // Silently, forever.
        //
        // The invoice above is kept deliberately: the money did arrive and the customer is
        // entitled to a document for it (and an operator needs something to refund
        // against). Everything that would treat this as a live subscription is skipped,
        // and the operators are told.
        if (! $this->isBillableState($billable)) {
            $this->support->reportRenewalForEndedSubscription($billable, $payment, $invoice);

            return;
        }

        // A renewal Mollie created for a configuration the customer has since left: what
        // it collected beyond the current one is given back, and a payment that bought
        // nothing at all is not booked as a new period. See SupersededRenewalRefund.
        if (app(\GraystackIT\MollieBilling\Services\Billing\SupersededRenewalRefund::class)->settle($billable, $invoice, $metadata)) {
            return;
        }

        // A delivery for an *older* renewal must not be booked as a fresh period. Idempotency
        // here is per payment, so a late delivery for a payment that has no invoice yet used
        // to run the whole body: the anchor went back a month — after which
        // `PrepareUsageOverageJob`'s `renewsTomorrow` never matched again until the next
        // renewal, so that period's overage was never collected — and the wallet loop credited
        // a second full period on top, permanently for a rollover type. The invoice above
        // stays: the money did arrive and the customer is entitled to the document.
        $currentAnchor = $billable->getBillingPeriodStartsAt();

        // Strictly earlier, not "not later": a payment settling in the same second as the
        // anchor (a signup whose first renewal is simulated at the same instant, say) is not
        // out of order, and refusing it would skip a legitimate period's quota.
        if ($currentAnchor !== null && $newPeriodStart->lessThan($currentAnchor)) {
            Log::warning('Renewal delivered out of order — invoiced, but not booked as a new period', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_anchor' => $currentAnchor->toIso8601String(),
                'delivered_paid_at' => $newPeriodStart->toIso8601String(),
            ]);

            return;
        }

        if ($couponDiscountNet > 0) {
            try {
                $this->couponService->redeemRecurringCouponForRenewal(
                    $billable,
                    $expectedNet,
                    (int) $invoice->id,
                    $chargeDay,
                );
            } catch (\Throwable $e) {
                Log::warning('Recurring coupon redemption failed during renewal webhook', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($pendingTrialCoupon !== null) {
            $this->firstPaymentCouponPricing->redeem(
                $billable,
                $pendingTrialCoupon,
                $planCode,
                $interval,
                (int) $invoice->id,
            );
            $this->clearPendingTrialCouponSlot($billable);
        }

        // The charge a deferral was holding back has now been collected, so the deferral
        // is spent — before anything below asks the patcher about it.
        $this->forgetSpentChargeDeferral($billable, $chargeDay);

        // Judged as of the *next* charge, not now — the PATCH is what stops the discount and
        // Mollie charges what it holds, so a date-gated coupon reset a period too late runs
        // one discounted period over. See CouponService::markerExpired().
        //
        // A plan waiver given to *end* is the exception: when it will not cover the next
        // charge there is no full price to return to — the tenant was given this plan and
        // never agreed to pay for it. The subscription is cancelled at the end of the
        // period this payment bought instead (further down, once the anchor has moved).
        $waiverEndsSubscription = app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)
            ->endsSubscription($billable, $nextChargeDay);

        if (! $waiverEndsSubscription && $this->couponService->markerExpired($billable, $nextChargeDay)) {
            try {
                // The amount only: this charge has just been made on its cycle.
                $this->patcher->updateRecurringAmountAfterCharge(
                    billable: $billable,
                    planCode: $planCode,
                    interval: $interval,
                    addons: $addonCodes,
                    extraSeats: $extraSeats,
                    couponDiscountNet: 0,
                );
                $this->couponService->clearActiveRecurringCouponMarker($billable);
            } catch (\Throwable $e) {
                Log::warning('Mollie reset PATCH after coupon expiry failed — queued for retry', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_subscription_patch'] = [
                    'reason' => 'recurring_coupon_expiry_reset',
                    // Which discount this reset ends, so a retry cannot end a newer one.
                    'coupon_id' => $meta['active_recurring_coupon']['coupon_id'] ?? null,
                    'plan_code' => $planCode,
                    'interval' => $interval,
                    'addons' => $addonCodes,
                    'extra_seats' => $extraSeats,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }

        // The next charge on the anchor day, at Mollie too, wherever Mollie's own rule
        // after a month end could put it elsewhere.
        if (! $waiverEndsSubscription) {
            $this->pinNextChargeOnAnchor($billable, $newPeriodStart, $nextChargeDay, $interval);
        }

        // Before the reset, never after: this renewal is the last moment the period that
        // just ended can still be billed for its overage. The sweep normally did that the
        // night before and this is then a no-op — but a trial's first charge, a SEPA
        // renewal that settled ahead of the sweep night and a night the sweep missed all
        // arrive here with a negative wallet and nothing in flight, and the reset below
        // used to zero it. Outside any transaction, like the PATCH above.
        $this->support->collectOutstandingOverage($billable);

        // Shared with the paid trial conversion in ProrataChargeHandler, so a trial that
        // ends by changing plan lands in the same wallet state as one that ends by simply
        // being charged.
        $this->support->creditPeriodQuota($billable, $planCode, $interval, 'subscription_renewal');

        // Already resolved above, where the invoice's period was computed from it.
        $periodStartsAt = $newPeriodStart;

        $derivedSeatCount = $invoice->deriveSeatCount();

        $currentStatus = $billable->getBillingSubscriptionStatus();
        $wasTrial = $currentStatus === SubscriptionStatus::Trial;
        $wasPastDue = $currentStatus === SubscriptionStatus::PastDue;

        $updates = ['subscription_period_starts_at' => $periodStartsAt];

        // A paid renewal ends the *renewal's* dunning episode. A subscription that is
        // past due because an overage debt stands stays so: that lock ends when the debt
        // is paid or written off (ChargeUsageOverageDirectly::releasePastDueForDebt()),
        // and this renewal is what goes on to collect it.
        if ($wasTrial || $wasPastDue) {
            $updates['subscription_status'] = \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::statusAfterRecovery(
                $billable->getBillingSubscriptionMeta(),
                SubscriptionStatus::Active,
            );
        }
        if ($wasTrial) {
            $updates['trial_ends_at'] = null;
        }

        // Locked read-modify-write, touching only the keys this handler owns.
        //
        // The meta was read at the top of paid(), then a wide window of invoice and
        // wallet work followed, and finally the whole blob was written back. Anything
        // that committed in between was reverted: an overage webhook clearing its
        // `usage_overage*` keys in the same second (they settle a day apart *by design*)
        // came back from the dead and PrepareUsageOverageJob then skipped that billable's
        // overage collection indefinitely; a SyncSeats that raised `seat_count` and
        // PATCHed Mollie was rolled back locally, so Mollie billed n+2 seats while the
        // row said n and the next renewal rebuilt its lines from the stale count.
        $this->mutateBillingMeta(
            $billable,
            static function (array $meta) use ($derivedSeatCount, $seatCountAtStart, $wasPastDue, $wasTrial, $periodStartsAt, $anchorDay): array {
                // The anchor day travels with the period start it belongs to.
                $meta = BillingAnchor::record($meta, $anchorDay, $periodStartsAt);

                // Compare-and-set. The count on the invoice is the one this handler read
                // at the top, so writing it back is a no-op — unless somebody changed it
                // in between, and then it is a revert: a seat sync that raised the count
                // and PATCHed Mollie while the invoice was being written was rolled back
                // here, so Mollie billed seven seats while the row said five. Taking the
                // lock around the write does not help when the value written is stale.
                if ($derivedSeatCount !== null && ($meta['seat_count'] ?? null) === $seatCountAtStart) {
                    $meta['seat_count'] = $derivedSeatCount;
                }

                // When the trial ended, as a fact rather than something to infer later.
                // The conversion nulls `trial_ends_at`, so a converted trial leaves every
                // cohort keyed on that column — which made the admin dashboard's
                // trial-conversion tile read 0 % for a business converting everyone.
                if ($wasTrial) {
                    $meta['trial_converted_at'] = $periodStartsAt->toIso8601String();

                    // The grant record describes a trial that no longer runs — see
                    // ProrataChargeHandler, which converts a trial the other way.
                    unset($meta['trial_quota']);
                }

                // A successful recurring charge recovers a PastDue billable — drop the
                // failure markers so the dashboard banner and overage retry job stop
                // treating it as overdue. Mirrors ProrataChargeHandler's cleanup.
                if ($wasPastDue) {
                    $meta = SubscriptionMetaHygiene::withoutDunningEpisode($meta);
                }

                // The override exists to delay ONE charge — a TrialExtension coupon
                // pushing the trial end, a PeriodExtension granting free days. That charge
                // has now happened, so the override is spent:
                // `subscription_period_starts_at` above is the new anchor. Left in place
                // it outranks that anchor in `nextBillingDate()` for as long as it stays
                // in the future, which made a converted trial report a next-billing date a
                // month early — and everything reading that date got it wrong with it: the
                // overage pass fires the day before it, and a prorata plan change measures
                // its factor against it.
                unset($meta['next_charge_date_override']);

                return $meta;
            },
            $updates,
        );

        // The gift ends with this period: cancel now, gracefully. Run only after the anchor
        // above has moved, because the grace boundary is read from it — before, the
        // subscription would have been set to end at the start of the period just paid for.
        if ($waiverEndsSubscription) {
            try {
                app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class)
                    ->handle($billable->refresh(), false, 'grant_expired');
            } catch (\Throwable $e) {
                // Loud: if this does not land, Mollie charges the waived amount's absence —
                // i.e. the discounted price — for a period the gift no longer covers.
                Log::error('Could not end the subscription with its plan waiver', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // AFTER the save, not before. Firing it first meant a throwing app listener left
        // the billable on status Trial with a past `trial_ends_at` — which
        // `hasAccessibleBillingSubscription()` refuses — so a customer who had just paid
        // was locked out for a whole period, and the period anchor was never advanced.
        // The re-delivery could not repair it either: the invoice guard short-circuits.
        if ($wasTrial) {
            event(new TrialConverted($billable, $planCode));
        }

        event(new PaymentSucceeded($billable, $invoice));

        if ($wasTrial) {
            $this->notifyTrialConverted($billable, $invoice);
        }

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        // Last, once the renewal is fully booked — the same ordering
        // `FirstPaymentArtifacts::runCountryMatchCheck()` and
        // `MandateOnlyPaymentHandler::activateAfterMandate()` already use.
        //
        // `check()` is not a read: a mismatch calls `flag()`, which cancels the
        // subscription at period end and writes status Cancelled immediately. Run before
        // the booking — as it was — that made `isBillableState()` false a few lines later,
        // so the renewal returned through `reportRenewalForEndedSubscription()`: an admin
        // alert saying Mollie had collected for a dead subscription, no wallet recharge, no
        // period advance, and a customer who had just paid locked out for the period they
        // bought. And it needs only the ordinary drift the mismatch flow exists for — a
        // declared AT with a card and an IP that both say DE.
        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during recurring', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Drop `charge_deferred_until` once the charge it deferred has arrived.
     *
     * The stored value carries the time of day it was written at, while Mollie's start
     * date is a date: the first charge after a fully covering coupon is created at some
     * hour of that day. Created before the stored hour, the deferral still read as "in
     * the future" inside this very webhook — so the expiry PATCH below believed the
     * charge was still deferred and sent a start date "back on the regular cycle", which
     * by then was the period boundary two days earlier a month on. The customer paid a
     * month on the 12th and was charged the next one on the 10th. Created after the
     * stored hour, nothing of the sort happened. Which of the two a customer got depended
     * on the hour Mollie's scheduler ran.
     */
    protected function forgetSpentChargeDeferral(Billable $billable, Carbon $chargeDay): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $stored = $billable->getBillingSubscriptionMeta()[ChargeDeferral::META_KEY] ?? null;

        if (! is_string($stored) || $stored === '') {
            return;
        }

        try {
            $deferredDay = Carbon::parse($stored)->setTimezone('UTC')->startOfDay();
        } catch (\Throwable) {
            return;
        }

        if ($deferredDay->greaterThan($chargeDay)) {
            return;
        }

        $this->mutateBillingMeta($billable, static function (array $meta): array {
            unset($meta[ChargeDeferral::META_KEY]);

            return $meta;
        });
    }

    /**
     * Send Mollie the next charge date when Mollie's own rule could put it on another day.
     *
     * A subscription anchored on the 29th, 30th or 31st is charged on the last day of a
     * shorter month (28 February). For the charge after it, Mollie may count on from that
     * charge (28 March) or from its start date (31 March) — not documented, measured by
     * the sandbox canary — and its start date may itself be a shortened day (a signup on
     * 31 January is sent 28 February). The period ends on the anchor day
     * (BillingAnchor::next()), so wherever that is not simply one month after this charge,
     * the date is sent. Under either answer Mollie then charges where the period ends.
     *
     * Not for a deferred charge (a date Mollie was given on purpose), not for a date that
     * has passed. Mollie refuses a new start date on a charged subscription, so where its own
     * next date differs the patcher replaces the subscription (MollieSubscriptionReplacer);
     * where it already is the anchor day nothing is sent. A failure is retried
     * (`pending_subscription_patch`, reason `anchor_pin`); until it lands Mollie keeps its own
     * date, and a renewal it makes there is booked from its own charge, as any renewal is.
     */
    protected function pinNextChargeOnAnchor(Model&Billable $billable, Carbon $cycle, Carbon $nextChargeDay, string $interval): void
    {
        $oneIntervalOn = BillingTime::addInterval($cycle, $interval)->utc()->toDateString();

        if ($nextChargeDay->toDateString() === $oneIntervalOn
            || ! $nextChargeDay->greaterThan(BillingTime::nowUtc()->startOfDay())
            || ChargeDeferral::until($billable) !== null
        ) {
            return;
        }

        try {
            $this->patcher->pinNextChargeDate($billable, $nextChargeDay);
        } catch (\Throwable $e) {
            Log::warning('Could not send the next charge date on the anchor day — queued for retry', [
                'billable' => $billable->getKey(),
                'next_charge' => $nextChargeDay->toDateString(),
                'error' => $e->getMessage(),
            ]);

            // Retried by RetrySubscriptionPatchJob through the hourly sweep, until the date
            // has come. Only into a free slot: a plan-change PATCH waiting there carries the
            // amount the customer pays, and that matters more than the day it is collected.
            $this->mutateBillingMeta($billable, static function (array $meta) use ($nextChargeDay, $e): ?array {
                if (! empty($meta['pending_subscription_patch'])) {
                    return null;
                }

                $meta['pending_subscription_patch'] = [
                    'reason' => 'anchor_pin',
                    'target' => $nextChargeDay->toIso8601String(),
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];

                return $meta;
            });
        }
    }

    /**
     * The moment the period a recurring payment bought begins: when Mollie created the
     * charge, i.e. the subscription's cycle date.
     *
     * `paidAt` is the fallback for a payment object without `createdAt` (older stubs and
     * replays), and `now` for one with neither. A `createdAt` later than `paidAt` cannot
     * come from Mollie and is not trusted.
     */
    protected function cycleStart(object $payment): Carbon
    {
        $paidAt = $this->parseMoment($payment->paidAt ?? null);
        $createdAt = $this->parseMoment($payment->createdAt ?? null);

        if ($createdAt !== null && ($paidAt === null || $createdAt->lessThanOrEqualTo($paidAt))) {
            return $createdAt;
        }

        return $paidAt ?? Carbon::instance(BillingTime::nowUtc());
    }

    private function parseMoment(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A recurring charge failed. Only a billable that is actually being billed
     * can go PastDue: `Cancelled` still inside its grace period, `Expired`,
     * `New` and already-`PastDue`-with-markers states must survive untouched —
     * a stale delivery for one of those would otherwise revoke access (or reset
     * `past_due_since` and thus the auto-cancel clock) for a charge that no
     * longer relates to the current subscription.
     */
    public function failed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $status = $billable->getBillingSubscriptionStatus();
        if (! $this->isBillableState($billable)) {
            Log::info('Recurring payment failure ignored — subscription is not in a billable state', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_status' => $status->value,
            ]);

            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['payment_failure'] = [
            'payment_id' => (string) $payment->id,
            'failed_at' => BillingTime::nowUtc()->toIso8601String(),
            'reason' => (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown'),
        ];
        $meta['past_due_since'] = $meta['past_due_since']
            ?? BillingTime::nowUtc()->toIso8601String();

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentFailed($billable, (string) $payment->id, $meta['payment_failure']['reason']));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            // The amount comes from the payment: a failed charge produces no invoice,
            // so the notification has nothing to look up and would say "an unknown
            // amount" (it used to quote an unrelated invoice instead).
            Notification::send($recipients, MollieBilling::resolveNotification(
                SubscriptionPaymentFailedNotification::class,
                $billable,
                (string) $payment->id,
                $this->support->amountFromMolliePayment($payment),
                (string) ($payment->amount->currency ?? config('mollie-billing.currency')),
            ));
        }
    }

    /**
     * The first recurring charge after a trial has settled. `TrialConverted` is
     * the app-facing event; the notification goes to the billable's billing
     * admins like every other customer-facing billing mail. The invoice of this
     * exact charge is handed over so the mail states the amount that was really
     * collected instead of looking up "the latest invoice" later.
     */
    protected function notifyTrialConverted(Billable $billable, \GraystackIT\MollieBilling\Models\BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients === []) {
            return;
        }

        Notification::send(
            $recipients,
            MollieBilling::resolveNotification(TrialConvertedNotification::class, $billable, $invoice),
        );
    }

    /**
     * Negative discount line for a renewal invoice.
     *
     * Emits the canonical key set (`label`, `quantity`, `unit_price`,
     * `unit_price_net`, `total_net`) the other handlers use: InvoiceService
     * derives the header net from `total_net` and cross-checks it against
     * `quantity × unit_price`, so a line missing those keys is silently dropped
     * from both totals — the header would stay undiscounted while the persisted
     * lines carry the discount, and the mismatch guard could never fire. `label`
     * keeps the PDF from rendering the raw kind ("coupon") as the line title.
     *
     * @return array<string, mixed>
     */
    protected function couponLine(int $discountNet, string $couponCode): array
    {
        return [
            'kind' => 'coupon',
            'code' => $couponCode !== '' ? $couponCode : null,
            'label' => $couponCode !== '' ? 'Coupon '.$couponCode : 'Coupon discount',
            'quantity' => 1,
            'unit_price' => -$discountNet,
            'unit_price_net' => -$discountNet,
            'total_net' => -$discountNet,
        ];
    }

    /**
     * Whether what Mollie collected matches a given net, once VAT is added.
     *
     * The comparison has to happen on gross, because that is what the payment carries, and
     * with a cent of slack for the rounding difference between per-line and total VAT.
     */
    protected function paidAmountReflects(object $payment, int $net, Billable $billable): bool
    {
        $currency = BillingMoney::normalizeCurrency($payment->amount->currency ?? null);
        $paidGross = BillingMoney::minorFromMollieAmount((string) ($payment->amount->value ?? '0'), $currency);
        $expectedGross = (int) $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            max(0, $net),
            $billable,
        )['gross'];

        return abs($paidGross - $expectedGross) <= 1;
    }

    /**
     * @param  array<int, string>  $addonCodes
     * @return array{coupon:\GraystackIT\MollieBilling\Models\Coupon, discount_net:int, recurring_discount_net:int, order_amount_net:int}|null
     */
    protected function resolvePendingTrialCoupon(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): ?array {
        $slot = $billable->getBillingSubscriptionMeta()['pending_first_charge_coupon'] ?? null;
        if (! is_array($slot) || empty($slot['code'])) {
            return null;
        }

        try {
            return $this->firstPaymentCouponPricing->price(
                $billable, (string) $slot['code'], $planCode, $interval, $addonCodes, $extraSeats
            );
        } catch (\Throwable $e) {
            Log::warning('Pending trial coupon could not be priced — dropping silently', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => (string) $slot['code'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function clearPendingTrialCouponSlot(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }
        $meta = $billable->getBillingSubscriptionMeta();
        if (! array_key_exists('pending_first_charge_coupon', $meta)) {
            return;
        }
        unset($meta['pending_first_charge_coupon']);
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }
}

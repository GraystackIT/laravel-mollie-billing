<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Responsible for Mollie subscription changes for future periods.
 *
 * Logic was extracted from UpdateSubscription:
 * - cancelAndRecreateMollieSubscription → updateRecurringAmount() (internally: cancel + create)
 * - cancelMollieSubscriptionForFreeDowngrade → cancelForFreeDowngrade()
 * - updateMollieSubscription → in-place PATCH (runs in the phase-2 path)
 */
class MollieSubscriptionPatcher
{
    use \GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {}

    /**
     * Applies a PlanChangeIntent to the Mollie subscription.
     * - newPlan = Free → cancelForFreeDowngrade()
     * - otherwise → updateRecurringAmount() with new plan/seats/addons
     */
    /**
     * @return bool Whether Mollie now reflects the intent. Only the free-downgrade branch
     *              can answer false: the cancel is best-effort and leaves a
     *              `pending_subscription_cancel` marker, and the caller must NOT drop
     *              `mollie_subscription_id` on a subscription that is still live — nothing
     *              could ever target it again, and Mollie keeps charging the old price.
     */
    public function updateForIntent(Billable $billable, PlanChangeIntent $intent): bool
    {
        $extraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        $totalSeats = $this->catalog->includedSeats($intent->newPlan) + $extraSeats;
        $newAddons = array_keys($intent->newAddons);

        // Cancel only when the whole target configuration bills nothing — not merely when
        // the plan's own base and seat prices are zero. `isFreePlan()` answers yes for a
        // free plan that still carries a paid addon, so removing an *addon* from such a
        // subscription cancelled it at Mollie while UpdateSubscription (which asks
        // `planChanged && isFreePlan`) kept source Mollie, status Active and the
        // subscription id: the customer kept the plan and was never billed again, and the
        // next change patched a subscription that no longer existed.
        $targetNet = SubscriptionAmount::net(
            $this->catalog,
            $billable,
            $intent->newPlan,
            $intent->newInterval,
            $totalSeats,
            $newAddons,
        );

        if ($targetNet <= 0) {
            return $this->cancelForFreeDowngrade($billable);
        }

        // Preserve any active recurring coupon discount across plan/seat/addon
        // changes — otherwise the PATCH would silently undo the campaign price.
        // Through CouponService, which is the only place that knows whether the marker
        // is still redeemable. This mirror checked existence only, so a coupon an admin
        // had deactivated — the documented way to resolve a `recurring_conflict` — or
        // one whose window had closed but whose renewal had not run yet was re-applied
        // to Mollie by any seat, addon or plan PATCH. Mollie then collected the
        // discounted amount for at least one more period, the invoice reconciled down to
        // it, and PaymentAmountMismatch fired on a payment nobody had asked to discount.
        $couponDiscountNet = 0;
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        if (is_array($marker)) {
            $couponDiscountNet = app(CouponService::class)->computeMarkerDiscount($billable, $targetNet);
        }

        $this->updateRecurringAmount(
            billable: $billable,
            planCode: $intent->newPlan,
            interval: $intent->newInterval,
            addons: $newAddons,
            extraSeats: $extraSeats,
            intervalChanged: $intent->currentInterval !== $intent->newInterval,
            couponDiscountNet: $couponDiscountNet,
            forceResetStartDate: $intent->forceResetStartDate,
        );

        // `updateRecurringAmount()` throws on failure, so reaching here means Mollie took it.
        return true;
    }

    /**
     * For a 100%-coverage recurring discount, return the Mollie startDate that
     * skips the entire discount lifetime: marker.valid_until + 1 day. Falls
     * back to one period from now if the marker has no valid_until (shouldn't
     * happen — the validator enforces a lifetime gate — but defensive).
     */
    private function fullCoverageStartDate(Billable $billable, string $interval): Date
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        $validUntil = is_array($marker) && isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;

        if ($validUntil !== null) {
            return new Date($validUntil->copy()->addDay());
        }

        return new Date(
            BillingTime::addInterval(BillingTime::nowUtc(), $interval)
        );
    }

    /**
     * Mirrors `CouponService::computeMarkerDiscount()` — the discount is locked
     * to the recurring net at coupon-apply time so additions (seats/addons) made
     * afterwards aren't silently rabattiert.
     *
     * @param  array<string,mixed>  $marker
     */
    /**
     * PATCH the Mollie subscription with the new aggregated recurring amount.
     *
     * Mollie's `PATCH /subscriptions/:id` only changes the amount for the *next* recurring period —
     * it does not trigger an immediate charge. This is exactly the desired behavior: the current
     * period stays untouched, and the new amount applies from the next billing onwards.
     *
     * NO cancel+recreate fallback on PATCH failures: `CreateSubscriptionRequest` would cause Mollie
     * to immediately issue a first charge → double debit. Failures are logged and picked up by the
     * RetrySubscriptionPatchJob when needed.
     *
     * @param  array<int, string>  $addons
     *
     * @throws \Throwable  When the Mollie PATCH call fails (the retry job can react to this).
     */
    public function updateRecurringAmount(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        bool $intervalChanged = false,
        int $couponDiscountNet = 0,
        bool $forceResetStartDate = false,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('updateRecurringAmount skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
                'mollie_customer_id' => $customerId,
                'mollie_subscription_id' => $subscriptionId ?: '(empty)',
            ]);
            return;
        }

        $includedSeats = $this->catalog->includedSeats($planCode);
        $totalSeats = $includedSeats + $extraSeats;
        $baseAmountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addons);
        $netAfterDiscount = max(0, $baseAmountNet - max(0, $couponDiscountNet));

        // 100%-coverage: the discount fully covers the recurring net. Mollie
        // rejects subscriptions with amount = 0, so we instead PATCH with the
        // FULL price and defer the next charge to the day after the marker's
        // valid_until — Mollie won't charge anything during the discount lifetime,
        // and once it does the marker is already expired and the full price is
        // billed naturally.
        $isFullCoverage = $netAfterDiscount === 0 && $couponDiscountNet > 0 && $baseAmountNet > 0;
        $amountNet = $isFullCoverage ? $baseAmountNet : $netAfterDiscount;

        $vat = $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $amountNet,
            $billable,
        );

        // The renewal this PATCH prices is invoiced per line (SubscriptionPaymentHandler
        // → InvoiceService::createForPayment), and that header VAT is the sum of the
        // per-line VAT. The recurring amount therefore has to be built from the same
        // lines — see BillingMoney::grossWithLineVat() for what the two roundings cost.
        $amountGross = BillingMoney::grossWithLineVat(
            self::recurringLineNets($this->catalog, $billable, $planCode, $interval, $addons, $extraSeats, $amountNet),
            (float) $vat['rate'],
        );

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $value = BillingMoney::mollieAmount($amountGross, $currency);

        $metadata = [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $addons,
            'extra_seats' => $extraSeats,
        ];

        $mollieInterval = $interval === 'yearly' ? '12 months' : '1 month';

        // Date precedence:
        //  1. full-coverage discount → defer past marker.valid_until
        //  2. interval change OR past-due reset → reset cadence to now + 1 period
        //     (interval-change: else Mollie keeps old cadence with new amount;
        //      past-due-reset: else Mollie immediately retries the failed charge
        //      at the new price right after PATCH).
        //  3. amount-only change → leave cadence untouched (null)
        $startDate = match (true) {
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $intervalChanged || $forceResetStartDate => new Date(
                BillingTime::addInterval(BillingTime::nowUtc(), $interval)
            ),
            default => null,
        };

        MollieCall::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            amount: new Money($currency, $value),
            description: "{$planCode} subscription",
            interval: $mollieInterval,
            startDate: $startDate,
            metadata: $metadata,
        ));
    }

    /**
     * Net amount per invoice line for a recurring charge of this configuration.
     *
     * Mirrors what SubscriptionPaymentHandler will put on the renewal invoice —
     * one line per plan / seat block / addon, plus a negative coupon line for
     * whatever the discount takes off — so the amount Mollie collects and the
     * amount the invoice books round identically. Shared with CreateSubscription:
     * a subscription created at Σ round(line) and later patched to
     * round(Σ line), or the other way round, would change price by a cent for no
     * reason the customer can see.
     *
     * @param  array<int, string>  $addons
     * @param  int  $chargedNet  the net the subscription is actually billed at
     * @return list<int>
     */
    public static function recurringLineNets(
        SubscriptionCatalogInterface $catalog,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        int $chargedNet,
    ): array {
        /** @var list<int> $lineNets */
        $lineNets = array_map(
            static fn (array $line): int => (int) $line['total_net'],
            SubscriptionAmount::lineItems($catalog, $billable, $planCode, $interval, $extraSeats, $addons),
        );

        $catalogNet = array_sum($lineNets);

        if ($catalogNet === $chargedNet) {
            return $lineNets;
        }

        if ($catalogNet > $chargedNet) {
            $lineNets[] = $chargedNet - $catalogNet;

            return $lineNets;
        }

        // Nothing in the catalog lines accounts for a charge *above* their total, so
        // there is no breakdown to round per line — the total is all we know.
        return [$chargedNet];
    }

    /**
     * Shifts the date of the next Mollie billing into the future by the given
     * number of days. Used by the PeriodExtension coupon type.
     * After a successful PATCH an override is set in the subscription meta so
     * that `nextBillingDate()` returns the shifted value.
     */
    /**
     * Move the next charge to an absolute date: local write now, Mollie PATCH after the
     * caller's transaction commits.
     *
     * The two coupon types that extend a period — PeriodExtension and TrialExtension —
     * both used to PATCH Mollie from inside `CouponService::redeem()`'s transaction.
     * Anything throwing afterwards (the redemption insert, a `CouponRedeemed` listener)
     * rolled back the redemption row, the incremented `redemptions_count` and the local
     * dates — while Mollie had already moved the charge. The customer kept the free
     * period, the campaign slot was still free, and applying the code again extended a
     * second time. Same rule as everywhere else: a transaction can be rolled back, an
     * HTTP call to a payment provider cannot.
     *
     * The target is absolute on purpose. `pushNextChargeDate()` adds days to whatever
     * Mollie is currently set to, so a retry after a partial failure extends twice; an
     * absolute date can be re-sent as often as needed, which is what lets the failure
     * path leave a `pending_subscription_patch` marker for the hourly sweep.
     */
    public function deferNextChargeDate(Billable $billable, \Carbon\CarbonInterface $target): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $utc = Carbon::instance($target)->setTimezone('UTC');

        // Written inside the caller's transaction, so the date the customer is shown and
        // the redemption that bought it stand or fall together.
        $this->mutateBillingMeta($billable, function (array $meta) use ($utc): array {
            $meta['next_charge_date_override'] = $utc->toIso8601String();

            return $meta;
        });

        DB::afterCommit(function () use ($billable, $utc): void {
            try {
                $this->setNextChargeDate($billable, $utc);
            } catch (\Throwable $e) {
                Log::warning('Mollie next-charge-date PATCH failed — queued for retry', [
                    'billable' => $billable->getKey(),
                    'target' => $utc->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);

                // Local state says the charge moved; Mollie still holds the old date and
                // would collect early. SweepPendingRetriesJob picks this up hourly.
                $this->mutateBillingMeta($billable, function (array $meta) use ($utc, $e): array {
                    $meta['pending_subscription_patch'] = [
                        'reason' => 'next_charge_date',
                        'target' => $utc->toIso8601String(),
                        'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                        'last_error' => $e->getMessage(),
                    ];

                    return $meta;
                });
            }
        });
    }

    public function pushNextChargeDate(Billable $billable, int $days): void
    {
        if (! ($billable instanceof Model) || $days <= 0) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('pushNextChargeDate skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
            ]);

            return;
        }

        $current = $billable->nextBillingDate() ?? BillingTime::nowUtc();
        $newDate = $current->copy()->addDays($days);

        MollieCall::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            startDate: new Date($newDate),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['next_charge_date_override'] = $newDate->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Sets the date of the next Mollie billing to an absolute target date.
     * Used by the TrialExtension coupon type: the trial end is computed
     * absolutely (`max(current_trial_end, now) + days`), so the Mollie
     * startDate must follow that same absolute value rather than a relative
     * shift — otherwise a coupon applied after the trial already expired
     * would push the next charge too far into the future.
     * After a successful PATCH an override is set in the subscription meta so
     * that `nextBillingDate()` returns the new value.
     */
    public function setNextChargeDate(Billable $billable, \Carbon\CarbonInterface $target): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            Log::warning('setNextChargeDate skipped — missing Mollie customer or subscription id', [
                'billable' => $billable->getKey(),
            ]);

            return;
        }

        $newDate = $target->copy();

        MollieCall::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            startDate: new Date($newDate),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['next_charge_date_override'] = $newDate->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Cancels the Mollie subscription on a free downgrade. Tolerant of API failures.
     */
    /**
     * Cancel the Mollie subscription a downgrade to a free plan makes obsolete.
     *
     * Returns whether the subscription is definitively gone at Mollie. The caller must
     * keep `mollie_subscription_id` when it is not: this used to swallow every failure
     * and log, while `UpdateSubscription` dropped the id regardless — so with Mollie
     * unreachable for that one call the subscription stayed active, charged the old
     * plan's price every period against a now-free billable, and with the id gone no
     * later cancel could target it. Same failure mode `CancelSubscription` was given a
     * `pending_subscription_cancel` marker and a retry job for; this path had neither.
     */
    public function cancelForFreeDowngrade(Billable $billable): bool
    {
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            return true;
        }

        try {
            MollieCall::send(new CancelSubscriptionRequest($customerId, $subscriptionId));

            return true;
        } catch (ApiException $e) {
            // Already gone is the outcome we wanted.
            if (in_array($e->getCode(), [404, 410], true)) {
                return true;
            }

            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        Log::error('Mollie subscription cancel during free-downgrade failed — it may keep charging', [
            'billable' => $billable instanceof Model ? $billable->getKey() : null,
            'subscription_id' => $subscriptionId,
            'error' => $error,
        ]);

        if (! ($billable instanceof Model)) {
            return false;
        }

        $marker = [
            'mollie_customer_id' => $customerId,
            'mollie_subscription_id' => $subscriptionId,
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            'last_error' => $error,
        ];

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['pending_subscription_cancel'] = $marker;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        // Dispatched as well as marked: the marker alone is only read by the hourly
        // sweep, and every period until then is a period the customer is charged for a
        // plan they no longer have.
        RetrySubscriptionCancelJob::dispatch(
            (string) config('mollie-billing.billable_model'),
            $billable->getKey(),
            $customerId,
            $subscriptionId,
            $marker['first_attempt_at'],
        );

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins !== []) {
            Notification::send($admins, MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Mollie subscription cancel failed during a downgrade to a free plan — it may still be charging',
                $marker + [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                ],
            ));
        }

        return false;
    }
}

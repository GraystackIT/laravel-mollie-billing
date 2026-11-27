<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Events\CouponRedemptionRecordMissing;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class FirstPaymentCouponPricing
{
    /** See afterPayment(). */
    protected bool $paidWithCode = false;

    public function __construct(
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CouponService $couponService,
    ) {
    }

    /**
     * This pricing, for a payment the customer has already made at the amount the checkout
     * priced with the code (a paid first payment — not a €0 mandate, not a checkout still
     * being put together). The code is then honoured even if its campaign filled up, it
     * was switched off or its end date passed while the payment travelled: the checkout
     * was the gate, see `CouponService::resolveAndValidateShared()`.
     *
     * A copy, not a parameter of price(): apps rebind this service, and a subclass
     * overriding price() with its signature of today stays compatible.
     */
    public function afterPayment(): static
    {
        $priced = clone $this;
        $priced->paidWithCode = true;

        return $priced;
    }

    /**
     * Validate the first-payment coupon and pre-compute its discount, but do
     * NOT consume the redemption yet. The redemption must happen only after
     * the new Mollie subscription has been created — otherwise the coupon
     * could be permanently consumed against a subscription that never came
     * into existence.
     *
     * `$trialStarting` says this activation is starting a trial, which is what makes
     * a TrialExtension coupon admissible: outside that, an extension has no trial to
     * extend and applying it would move a paying subscriber's next charge date.
     *
     * `recurring_marker` says whether redeem() may write the `active_recurring_coupon`
     * marker. It is false where the code itself ends its recurring part before the first
     * renewal — switched off, or its `valid_until` before that charge — see below. An
     * array without the key (an app's own price()) keeps the marker, as before.
     *
     * @param  array<int, string>  $addonCodes
     * @return array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int, recurring_marker?: bool}|null
     */
    public function price(
        Billable $billable,
        string $couponCode,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        bool $trialStarting = false,
    ): ?array {
        $couponCode = trim($couponCode);
        if ($couponCode === '') {
            return null;
        }

        $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);
        $orderAmountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addonCodes);

        try {
            $coupon = $this->couponService->validate($couponCode, $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'addonCodes' => $addonCodes,
                'orderAmountNet' => $orderAmountNet,
                'trial_starting' => $trialStarting,
                'paid_with_code' => $this->paidWithCode,
                'allowed_types' => [
                    CouponType::SinglePayment,
                    CouponType::Recurring,
                    CouponType::TrialExtension,
                    CouponType::AccessGrant,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('First-payment coupon validation failed', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $discount = 0;
        if (in_array($coupon->type, [
            CouponType::SinglePayment,
            CouponType::Recurring,
        ], true)) {
            $discount = $this->couponService->computeRecurringDiscount($coupon, $orderAmountNet);
        }

        // The recurring amount Mollie holds must match what the books will honour.
        //
        // `recurring_discount_net` is what `CreateSubscription` prices the Mollie
        // subscription at, and every later renewal is reconciled against
        // `computeMarkerDiscount()` — which stops at `discount_periods`.
        // With one period, this activation *is* the one application: the
        // marker refuses immediately afterwards, so a subscription created at the
        // discounted amount charged a second discounted period that the invoice booked at
        // full net with an `adjustment` line, firing `PaymentAmountMismatch` every time.
        //
        // A trial start is the exception: its redemption row carries no discount and does
        // not consume an application (see `CouponService::isMarkerStillRedeemable()`), so
        // the first *paid* charge is still the first application.
        $recurringDiscount = 0;

        // Whether the redemption may write the `active_recurring_coupon` marker. Not where
        // the code's own state ends its recurring part before the first renewal (below):
        // Mollie is created at the full price then, and a marker would be a discount the
        // books could still find later. Switched off, `isMarkerStillRedeemable()` reads the
        // live `active` flag — so switching the code back on revived a discount Mollie was
        // never told about, and every renewal booked a coupon line, took it back off with an
        // adjustment and fired PaymentAmountMismatch. Ended by its date, the marker's window
        // ends at that hour while the renewal asks at the start of the charge's day, so a
        // code ending on the day of the first renewal was still honoured there. The payment
        // made with the code is still redeemed — only the marker is left out.
        $recurringMarker = true;

        if ($coupon->type === CouponType::Recurring) {
            // The number of discounted charges — `discount_periods`, not the
            // per-customer redemption limit. Null: the date alone ends the discount.
            $max = (int) ($coupon->discount_periods ?? 0);
            $consumedNow = ($trialStarting || $discount <= 0) ? 0 : 1;

            $recurringDiscount = ($max <= 0 || $max - $consumedNow > 0) ? $discount : 0;

            // A code switched off while the payment travelled: the payment made with it is
            // honoured, but switching a code off is how an operator ends its discount at the
            // next renewal (`isMarkerStillRedeemable()` reads `active`). A subscription
            // created at the discounted amount would collect a discount the renewal books at
            // the full price, with an adjustment line every period.
            if (! $coupon->active) {
                $recurringDiscount = 0;
                $recurringMarker = false;
            }

            // The same for the coupon's own end date. The subscription's first charge is
            // one interval away (or at the trial end), and a renewal only honours the
            // marker while `valid_until` is still ahead of it. A yearly plan booked with a
            // code valid for another 100 days was created at the discounted amount all the
            // same: Mollie collected 464.10 instead of 546.00 for a second year the coupon
            // no longer covered, and the invoice carried a payment adjustment for it.
            if ($recurringDiscount > 0 && $coupon->valid_until !== null) {
                $now = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc();
                $nextCharge = $trialStarting
                    ? $now->copy()->addDays(max(0, (int) $this->catalog->trialDays($planCode, $interval)))
                    : \GraystackIT\MollieBilling\Support\BillingTime::addInterval($now, $interval);

                if (\GraystackIT\MollieBilling\Support\BillingTime::toUtc($coupon->valid_until)->lessThanOrEqualTo($nextCharge)) {
                    $recurringDiscount = 0;
                    $recurringMarker = false;
                }
            }
        }

        return [
            'coupon' => $coupon,
            'discount_net' => $discount,
            'recurring_discount_net' => $recurringDiscount,
            'order_amount_net' => $orderAmountNet,
            'recurring_marker' => $recurringMarker,
        ];
    }

    /**
     * Consume the redemption for a coupon previously validated via price().
     * Call only after the new subscription has been created.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int, recurring_marker?: bool}  $priced
     */
    public function redeem(
        Billable $billable,
        array $priced,
        string $planCode,
        string $interval,
        int $invoiceId,
    ): void {
        try {
            $this->couponService->redeem($priced['coupon'], $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'orderAmountNet' => $priced['order_amount_net'],
                'discount_amount_net' => $priced['discount_net'],
                'invoice_id' => $invoiceId,
                // The payment is redeemed; a discount the code no longer carries past it
                // gets no marker — see price().
                'skip_marker' => ($priced['recurring_marker'] ?? true) === false,
            ]);
        } catch (\Throwable $e) {
            $this->compensateFailedRedemption($billable, $priced, $e);
        }
    }

    /**
     * Redemption runs AFTER the Mollie subscription has been created, and for a
     * Recurring coupon that subscription already carries the discounted amount.
     * A failed redemption (global cap consumed by a concurrent redeem, transient
     * DB error) therefore cannot just be logged away: without the
     * `active_recurring_coupon` marker every renewal would compute a 0 discount,
     * invoice the full price and fire PaymentAmountMismatch for the rest of the
     * subscription's life.
     *
     * We self-heal the pricing side by setting the marker the successful
     * redemption would have set. The CouponRedemption itself cannot be created
     * behind the coupon's own limit check; its absence is logged and audited
     * (`CouponRedemptionRecordMissing`). The admins are mailed only when the
     * self-healing fails as well.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int, recurring_marker?: bool}  $priced
     */
    protected function compensateFailedRedemption(Billable $billable, array $priced, \Throwable $error): void
    {
        $coupon = $priced['coupon'];
        $isRecurring = $coupon->type === CouponType::Recurring
            && (int) ($priced['recurring_discount_net'] ?? 0) > 0;

        $markerFailed = false;

        if ($isRecurring) {
            try {
                $this->couponService->applyRecurringMarker(
                    $coupon,
                    $billable,
                    (int) $priced['order_amount_net'],
                );
            } catch (\Throwable $markerError) {
                $markerFailed = true;

                Log::error('First-payment coupon redemption failed AND the recurring marker could not be set — Mollie charges the discount but local pricing does not', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon_code' => (string) $coupon->code,
                    'error' => $markerError->getMessage(),
                ]);
            }
        }

        // The discount is in force either way — it was priced into the payment, and a
        // recurring coupon now has its marker. What is missing is one row in the
        // redemption list, and nobody can add it behind the coupon's own limit check.
        // That is a fact to record, not a task: a log line and an audit entry. It used
        // to be a mail to the admins, who could do nothing with it.
        if (! $markerFailed) {
            Log::warning('First-payment coupon redemption could not be recorded after the subscription was created — the discount is applied, the redemption row is missing', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => (string) $coupon->code,
                'recurring_marker_applied' => $isRecurring,
                'error' => $error->getMessage(),
            ]);

            event(new CouponRedemptionRecordMissing(
                $billable,
                $coupon,
                (int) $priced['discount_net'],
                $error->getMessage(),
            ));

            return;
        }

        // The marker could not be written either: Mollie collects the discounted amount
        // and every renewal will be booked at the full price with a mismatch. Somebody
        // has to look at this one.
        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Coupon redemption failed after the subscription was created, and the recurring discount could not be recorded — Mollie charges the discounted amount, renewals will be booked at full price',
                [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon_code' => (string) $coupon->code,
                    'discount_net' => (int) $priced['discount_net'],
                    'recurring_discount_net' => (int) ($priced['recurring_discount_net'] ?? 0),
                    'error' => $error->getMessage(),
                ],
            ),
        );
    }
}

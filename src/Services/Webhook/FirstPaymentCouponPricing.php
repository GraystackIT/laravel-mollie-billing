<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class FirstPaymentCouponPricing
{
    public function __construct(
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CouponService $couponService,
    ) {
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
     * @param  array<int, string>  $addonCodes
     * @return array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}|null
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
        // `computeMarkerDiscount()` — which stops at `max_redemptions_per_billable`.
        // At the shipped default of 1, this activation *is* the one application: the
        // marker refuses immediately afterwards, so a subscription created at the
        // discounted amount charged a second discounted period that the invoice booked at
        // full net with an `adjustment` line, firing `PaymentAmountMismatch` every time.
        //
        // A trial start is the exception: its redemption row carries no discount and does
        // not consume an application (see `CouponService::isMarkerStillRedeemable()`), so
        // the first *paid* charge is still the first application.
        $recurringDiscount = 0;

        if ($coupon->type === CouponType::Recurring) {
            $max = (int) ($coupon->max_redemptions_per_billable ?? 0);
            $consumedNow = ($trialStarting || $discount <= 0) ? 0 : 1;

            $recurringDiscount = ($max <= 0 || $max - $consumedNow > 0) ? $discount : 0;
        }

        return [
            'coupon' => $coupon,
            'discount_net' => $discount,
            'recurring_discount_net' => $recurringDiscount,
            'order_amount_net' => $orderAmountNet,
        ];
    }

    /**
     * Consume the redemption for a coupon previously validated via price().
     * Call only after the new subscription has been created.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $priced
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
     * redemption would have set, and surface the missing audit row to the admins
     * — the CouponRedemption itself cannot be created behind the coupon's own
     * limit check.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $priced
     */
    protected function compensateFailedRedemption(Billable $billable, array $priced, \Throwable $error): void
    {
        $coupon = $priced['coupon'];
        $isRecurring = $coupon->type === CouponType::Recurring
            && (int) ($priced['recurring_discount_net'] ?? 0) > 0;

        if ($isRecurring) {
            try {
                $this->couponService->applyRecurringMarker(
                    $coupon,
                    $billable,
                    (int) $priced['order_amount_net'],
                );
            } catch (\Throwable $markerError) {
                Log::error('First-payment coupon redemption failed AND the recurring marker could not be set — Mollie charges the discount but local pricing does not', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon_code' => (string) $coupon->code,
                    'error' => $markerError->getMessage(),
                ]);
            }
        }

        Log::error('First-payment coupon redemption failed after the subscription was created', [
            'billable' => $billable instanceof Model ? $billable->getKey() : null,
            'coupon_code' => (string) $coupon->code,
            'recurring_marker_applied' => $isRecurring,
            'error' => $error->getMessage(),
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Coupon redemption failed after the subscription was created — the redemption record is missing',
                [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon_code' => (string) $coupon->code,
                    'discount_net' => (int) $priced['discount_net'],
                    'recurring_discount_net' => (int) ($priced['recurring_discount_net'] ?? 0),
                    'recurring_marker_applied' => $isRecurring,
                    'error' => $error->getMessage(),
                ],
            ),
        );
    }
}

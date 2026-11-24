<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
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
use Carbon\CarbonInterface;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Responsible for Mollie subscription changes for future periods.
 *
 * Every change to a running subscription is first an in-place PATCH
 * (`UpdateSubscriptionRequest`), sent by `sendUpdate()`: the subscription id, its mandate
 * and its cycle stay, only amount, interval, description, metadata and — where asked
 * for — the start date move.
 *
 * The start date is the exception Mollie makes. Once a subscription has been charged it
 * refuses any new `startDate` (422 "…already charged", measured 17 November 2026), while
 * amount, interval, description and metadata are still taken. So a change that has to
 * move the next charge of a charged subscription replaces it: `MollieSubscriptionReplacer`
 * cancels it and creates it again with the same amount, interval, description, metadata,
 * mandate and webhook (or the ones the change asks for), first charged on the new date —
 * never today or earlier. The PATCH is always tried first, so a subscription Mollie has not
 * charged yet (a trial, a deferred start) keeps its id.
 *
 * The other cancel in this class is `cancelForFreeDowngrade()`: a target that bills
 * nothing has no Mollie subscription.
 *
 * - updateForIntent()        → free target: cancelForFreeDowngrade(); otherwise updateRecurringAmount()
 * - updateRecurringAmount()  → PATCH of the recurring amount for a plan/seat/addon configuration
 * - deferNextChargeDate() / pushNextChargeDate() / setNextChargeDate() → PATCH of the start date
 * - pinNextChargeDate()      → the anchor day, only where Mollie's own next date differs
 * - cancelForFreeDowngrade() → CancelSubscriptionRequest
 */
class MollieSubscriptionPatcher
{
    use \GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;

    /**
     * The recurring-coupon marker a change is about to write, for the duration of one
     * updateForIntent() call — see PlanChangeIntent::$couponMarker.
     *
     * @var ?array<string, mixed>
     */
    private ?array $upcomingCouponMarker = null;

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
        // A cancelled or expired subscription has no Mollie subscription left to re-price:
        // it was cancelled there when the customer cancelled, and collects nothing more.
        // A seat or an addon given back in the days that are left is credited and that is
        // all — a PATCH would be refused by Mollie and parked for a retry that can never
        // succeed.
        if (in_array($billable->getBillingSubscriptionStatus(), [
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled,
            \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Expired,
        ], true)) {
            return false;
        }

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
        // A code entered with this very change has not been redeemed yet — its marker is
        // written after this PATCH — so the intent carries the marker it will write.
        $marker = $intent->couponMarker ?? ($billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null);

        if (is_array($marker)) {
            $couponDiscountNet = app(CouponService::class)->computeDiscountOfMarkerOn($billable, $marker, $targetNet, [
                // The configuration being moved TO: a plan waiver's ceiling follows the
                // plan line, and the billable may still carry the old plan here.
                'plan_code' => $intent->newPlan,
                'interval' => $intent->newInterval,
                'addon_codes' => $newAddons,
                'seats' => $totalSeats,
            ], $this->chargeDayForIntent($billable, $intent, $marker));
        }

        // Read by fullCoverageStartDate() for this one call. A property rather than a
        // parameter: updateRecurringAmount() is public and apps (and the test doubles)
        // override it with its existing signature.
        $previousMarker = $this->upcomingCouponMarker;
        $this->upcomingCouponMarker = $intent->couponMarker;

        try {
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
        } finally {
            $this->upcomingCouponMarker = $previousMarker;
        }

        // `updateRecurringAmount()` throws on failure, so reaching here means Mollie took it.
        return true;
    }

    /**
     * The day the amount this PATCH sets is next collected on — the day the discount is
     * judged for, as the renewal will judge it (`SubscriptionPaymentHandler` asks on the
     * charge's day). Judged for today, a code whose window ends before the next charge
     * (one period, counted from the running period whose prorata it discounted) was sent
     * to Mollie discounted, and Mollie collected the discount for a period the books
     * charged in full.
     *
     * Null — judged for now, as before — for a plan waiver, whose end and expiry are the
     * renewal handler's to settle (`on_expiry`).
     *
     * @param  array<string, mixed>  $marker
     */
    private function chargeDayForIntent(Billable $billable, PlanChangeIntent $intent, array $marker): ?CarbonInterface
    {
        if (PlanWaiver::isWaiver($marker)) {
            return null;
        }

        $next = $intent->currentInterval !== $intent->newInterval || $intent->forceResetStartDate
            ? BillingTime::addInterval(BillingTime::nowUtc(), $intent->newInterval)
            : ChargeDeferral::undeferredNextChargeDate($billable);

        return $next !== null ? Carbon::instance($next)->setTimezone('UTC')->startOfDay() : null;
    }

    /**
     * For a 100%-coverage recurring discount, return the Mollie startDate that
     * skips the entire discount lifetime: the first charge on the subscription's cycle
     * the discount no longer covers (`charge_resumes_at`, written with the marker); for
     * a marker written before that was kept, `valid_until + 1 day` as before. Falls
     * back to one period from now if the marker has no valid_until (shouldn't
     * happen — the validator enforces a lifetime gate — but defensive).
     */
    private function fullCoverageStartDate(Billable $billable, string $interval): Carbon
    {
        // The marker about to be written, when the discount comes with this very change.
        $marker = $this->upcomingCouponMarker ?? ($billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null);
        $validUntil = is_array($marker) && isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;

        $coverageEnd = $validUntil !== null
            ? $validUntil->copy()->setTimezone('UTC')->addDay()
            : Carbon::instance(BillingTime::addInterval(BillingTime::nowUtc(), $interval))->setTimezone('UTC');

        // On the cycle — written with the marker since 17 November 2026.
        if (is_array($marker) && ($resumesAt = CouponService::markerChargeResumesAt($marker)) !== null) {
            $coverageEnd = $resumesAt;
        }

        // A plan waiver resumes on a renewal, not on the day after it ends — see
        // PlanWaiver::chargeResumesAt().
        if (is_array($marker) && PlanWaiver::isWaiver($marker)) {
            $regular = ChargeDeferral::undeferredNextChargeDate($billable)
                ?? Carbon::instance(BillingTime::addInterval(BillingTime::nowUtc(), $interval));

            $coverageEnd = PlanWaiver::chargeResumesAt($marker, $regular, $interval, app(CouponService::class)->recurringAnchorDay($billable, $interval, $regular)) ?? $coverageEnd;
        }

        // A deferral already stored later than the coupon's own end is a period
        // extension granted on top of it. Re-deriving from the marker alone would take
        // those days back on the next seat or addon PATCH.
        $stored = $billable->getBillingSubscriptionMeta()[ChargeDeferral::META_KEY] ?? null;

        if (is_string($stored) && $stored !== '') {
            try {
                return BillingTime::latest($coverageEnd, Carbon::parse($stored));
            } catch (\Throwable) {
                // an unreadable stored value falls back to the coupon's own end
            }
        }

        return $coverageEnd;
    }

    /**
     * Whether Mollie's start date is (or may be) sitting past a fully covering coupon.
     *
     * The stored deferral answers it for every subscription deferred since the value
     * exists. For older ones the marker has to: a discount that covers the whole amount
     * it was locked to is the only shape that ever deferred a charge. Checked against the
     * marker's own base rather than the live configuration on purpose — by the time this
     * runs the seat or addon that *ends* the full coverage may already be written, and
     * reading the live amount would then answer "never deferred" for exactly the case
     * this exists to catch.
     */
    private function chargeWasDeferred(Billable $billable): bool
    {
        if (ChargeDeferral::until($billable) !== null) {
            return true;
        }

        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        if (! is_array($marker) || empty($marker['valid_until'])) {
            return false;
        }

        // A plan waiver is younger than the stored deferral: whenever one deferred a
        // charge, the date was recorded. And unlike a coupon it usually does NOT cover
        // the whole amount — it is put on a subscription that has paid extras — so the
        // "covers its own base" reading below would call every waiver deferred and move
        // a charge date that was never anywhere but on its regular cycle.
        if (PlanWaiver::isWaiver($marker)) {
            return false;
        }

        try {
            if (! Carbon::parse((string) $marker['valid_until'])->isFuture()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        // By the marker's own shape, not through `computeMarkerDiscount()`: that answers
        // 0 for a coupon that has since been switched off, and a deferral whose coupon
        // was deactivated is the one that most needs ending. A positive answer for a
        // subscription that has in fact been returned to its regular date is harmless —
        // the date then sent is the one the package believes anyway
        // (`ChargeDeferral::undeferredNextChargeDate()`).
        return self::markerCoveredItsBase($marker);
    }

    /**
     * Whether the marker, as it was written, took off everything it was applied against
     * — the only shape that ever deferred a charge.
     *
     * @param  array<string, mixed>  $marker
     */
    private static function markerCoveredItsBase(array $marker): bool
    {
        $type = (string) ($marker['discount_type'] ?? '');
        $value = (int) ($marker['discount_value'] ?? 0);

        if ($type === 'percentage') {
            return $value >= 100;
        }

        $base = (int) ($marker['base_amount_net'] ?? 0);

        return $type === 'fixed' && $base > 0 && $value >= $base;
    }

    /**
     * Return Mollie to the full price when the next charge has moved past the end of a
     * partial coupon's window — a trial extended beyond the code's own end date.
     *
     * Mollie holds the discounted amount from the moment such a coupon is granted, and
     * returns to the full price only through the renewal that books the coupon's last
     * application. A charge moved out of the window gets no application, so nothing ever
     * reset it: Mollie collected the discounted amount, the invoice reconciled down to it
     * and reported a payment mismatch — for a charge the books price in full. A coupon that
     * covers everything is left alone: Mollie holds the full price there, and the charge
     * date (`deferNextChargeDate()` takes the later of the two) is what decides.
     */
    public function resetCouponMissingCharge(Billable $billable, \Carbon\CarbonInterface $chargeAt): void
    {
        if (! $billable instanceof Model
            || $billable->getBillingSubscriptionSource() !== \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie->value
        ) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $marker = $meta['active_recurring_coupon'] ?? null;
        $base = is_array($marker) ? (int) ($marker['base_amount_net'] ?? 0) : 0;

        if (! is_array($marker) || PlanWaiver::isWaiver($marker) || self::markerCoveredItsBase($marker) || $base <= 0) {
            return;
        }

        $coupons = app(CouponService::class);

        if ($coupons->computeMarkerDiscount($billable, $base, [], $chargeAt) > 0) {
            return;
        }

        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if ($planCode === '' || $interval === '') {
            return;
        }

        $couponId = (int) ($marker['coupon_id'] ?? 0);

        try {
            $this->updateRecurringAmount(
                billable: $billable,
                planCode: $planCode,
                interval: $interval,
                addons: $billable->getActiveBillingAddonCodes(),
                extraSeats: $billable->getExtraBillingSeats(),
                couponDiscountNet: 0,
            );

            $coupons->clearActiveRecurringCouponMarker($billable);
        } catch (\Throwable $e) {
            Log::warning('Could not return Mollie to the full price for a coupon the next charge has left behind — queued for retry', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            // The reset the renewal handler queues when a coupon expires: full price, marker
            // cleared — finished by the retry chain.
            $this->mutateBillingMeta($billable, function (array $meta) use ($couponId, $e): array {
                $meta['pending_subscription_patch'] = [
                    'reason' => 'recurring_coupon_expiry_reset',
                    'coupon_id' => $couponId,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];

                return $meta;
            });
        }
    }

    /**
     * End a deferral whose coupon has been switched off, and report whether there was one.
     *
     * Deactivating a recurring coupon ends its discount "on the next renewal" — that is
     * the documented way to take one back, and for a partial discount the renewal
     * webhook does it. A coupon that covers everything has no renewal: Mollie's start
     * date sits behind the coupon's lifetime, nothing arrives, and the customer stayed
     * free until the coupon's own end whatever the operator did. The nightly pass calls
     * this instead.
     *
     * The period that is running stays as it is; the full price is charged from the next
     * regular date. Only while the coupon's own lifetime is still running — once that has
     * passed, whatever is left of the deferral is a period extension that was given on
     * top, and those days stand.
     */
    public function endDeferralOfDeactivatedCoupon(Billable $billable): bool
    {
        if (! $billable instanceof Model
            || $billable->getBillingSubscriptionSource() !== \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie->value
        ) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $marker = $meta['active_recurring_coupon'] ?? null;

        // A first-payment coupon carried over a trial is not a campaign either: it was
        // redeemed and shown at signup, and switching the code off afterwards stops new
        // redemptions only — the same rule `CouponService::isMarkerStillRedeemable()`
        // applies on the renewal path. Ending its deferral here pulled the full-price
        // charge onto the trial's end, the very charge the customer was promised free.
        if (! is_array($marker)
            || PlanWaiver::isWaiver($marker)
            || CouponService::isFirstChargeOnly($marker)
            || empty($marker['valid_until'])
        ) {
            return false;
        }

        try {
            if (! Carbon::parse((string) $marker['valid_until'])->isFuture()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $couponId = (int) ($marker['coupon_id'] ?? 0);
        $coupon = $couponId > 0 ? \GraystackIT\MollieBilling\Models\Coupon::query()->find($couponId) : null;

        if ($coupon !== null && $coupon->active) {
            return false;
        }

        if (ChargeDeferral::until($billable) === null && ! self::markerCoveredItsBase($marker)) {
            return false;
        }

        // A reset that already failed is the retry chain's to finish.
        $pending = $meta['pending_subscription_patch'] ?? null;

        if (is_array($pending) && ($pending['reason'] ?? null) === 'recurring_coupon_expiry_reset') {
            return true;
        }

        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if ($planCode === '' || $interval === '') {
            return false;
        }

        try {
            $this->updateRecurringAmount(
                billable: $billable,
                planCode: $planCode,
                interval: $interval,
                addons: $billable->getActiveBillingAddonCodes(),
                extraSeats: $billable->getExtraBillingSeats(),
                couponDiscountNet: 0,
            );

            app(CouponService::class)->clearActiveRecurringCouponMarker($billable);
        } catch (\Throwable $e) {
            Log::warning('Could not end the deferral of a deactivated coupon — queued for retry', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            // The same reset the renewal handler queues when a coupon expires: full
            // price, marker cleared. The marker is still on the row when the retry
            // runs, so it sees the deferral and returns the charge to its regular date.
            $this->mutateBillingMeta($billable, function (array $meta) use ($couponId, $e): array {
                $meta['pending_subscription_patch'] = [
                    'reason' => 'recurring_coupon_expiry_reset',
                    'coupon_id' => $couponId,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];

                return $meta;
            });
        }

        return true;
    }

    /**
     * Mirrors `CouponService::computeMarkerDiscount()` — the discount is locked
     * to the recurring net at coupon-apply time so additions (seats/addons) made
     * afterwards aren't silently rabattiert.
     *
     * @param  array<string,mixed>  $marker
     */
    /** Set while updateRecurringAmountAfterCharge() runs. */
    private bool $chargeJustCollected = false;

    /**
     * `updateRecurringAmount()` from the renewal of a charge Mollie has just made: the
     * amount, never a start date.
     *
     * Inside that webhook the period anchor has not moved yet, so the "regular" next
     * charge a deferral would return to is the cycle just charged — a coupon that covered
     * the whole price for one period and ended with this renewal sent today as the start
     * date, and Mollie charged the customer a second time that day.
     *
     * @param  array<int, string>  $addons
     */
    public function updateRecurringAmountAfterCharge(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        int $couponDiscountNet = 0,
    ): void {
        $this->chargeJustCollected = true;

        try {
            $this->updateRecurringAmount(
                billable: $billable,
                planCode: $planCode,
                interval: $interval,
                addons: $addons,
                extraSeats: $extraSeats,
                intervalChanged: false,
                couponDiscountNet: $couponDiscountNet,
            );
        } finally {
            $this->chargeJustCollected = false;
        }
    }

    /**
     * PATCH the Mollie subscription with the new aggregated recurring amount.
     *
     * Mollie's `PATCH /subscriptions/:id` only changes the amount for the *next* recurring period —
     * it does not trigger an immediate charge. This is exactly the desired behavior: the current
     * period stays untouched, and the new amount applies from the next billing onwards.
     *
     * NO cancel+recreate fallback on PATCH failures in general: a create without a future start
     * date would cause Mollie to issue a charge at once → double debit. Failures are logged and
     * picked up by the RetrySubscriptionPatchJob when needed. The one refusal that does lead to a
     * replacement is Mollie's "start date cannot be changed when the subscription is already
     * charged", and only with a start date in the future — see sendUpdate() and
     * MollieSubscriptionReplacer.
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
        //  3. the charge WAS deferred and this amount is no longer fully covered →
        //     back to the regular cycle. Mollie keeps a start date it is not sent, so
        //     without this the deferred date stayed and whatever the customer had just
        //     added was free until the coupon ended.
        //  4. amount-only change → leave cadence untouched (null)
        //
        // Not for the renewal of a charge Mollie has just made
        // (updateRecurringAmountAfterCharge()): whatever deferred it is spent and Mollie
        // counts on from that charge, so a start date sent now could only move the cadence
        // — and inside that webhook the period anchor still names the cycle just charged.
        $wasDeferred = ! $this->chargeJustCollected && $this->chargeWasDeferred($billable);

        $startAt = match (true) {
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $intervalChanged || $forceResetStartDate => Carbon::instance(
                BillingTime::addInterval(BillingTime::nowUtc(), $interval)
            ),
            $wasDeferred => ChargeDeferral::undeferredNextChargeDate($billable),
            default => null,
        };

        // A charged subscription refuses the start date; sendUpdate() then replaces it with
        // one that holds all of this (MollieSubscriptionReplacer).
        $this->sendUpdate($billable, [
            'amount' => new Money($currency, $value),
            'description' => "{$planCode} subscription",
            'interval' => $mollieInterval,
            'metadata' => $metadata,
        ], $startAt, 'amount_update');

        // Recorded only once Mollie has taken it: the stored deferral is a statement
        // about what Mollie holds. Nothing to write when neither side of it moved.
        if ($isFullCoverage || $wasDeferred) {
            $deferredUntil = $isFullCoverage ? $startAt?->toIso8601String() : null;

            $this->mutateBillingMeta($billable, function (array $meta) use ($deferredUntil): ?array {
                if ($deferredUntil === null) {
                    if (! array_key_exists(ChargeDeferral::META_KEY, $meta)) {
                        return null;
                    }

                    unset($meta[ChargeDeferral::META_KEY]);

                    return $meta;
                }

                $meta[ChargeDeferral::META_KEY] = $deferredUntil;

                return $meta;
            });
        }
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

        // Nothing charged is nothing taxed. One discount line over several catalog lines
        // rounds its VAT once where they round theirs each: 29.50 + 11.10 + 15.50 fully
        // covered left a cent of VAT at 19 % — a preview of 0.01 for an order that costs
        // nothing, and a customer sent to pay it.
        if ($chargedNet === 0) {
            return [0];
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
    public function deferNextChargeDate(
        Billable $billable,
        \Carbon\CarbonInterface $target,
        bool $periodFollows = true,
    ): void {
        if (! $billable instanceof Model) {
            return;
        }

        $utc = Carbon::instance($target)->setTimezone('UTC');

        // Never earlier than a deferral already in force. A fully covering coupon holds
        // the FULL price at Mollie and relies on the start date alone to keep it from
        // being collected, so a target computed from the period end — which is what
        // both callers have — would pull the charge forward into the coupon's lifetime.
        $deferral = ChargeDeferral::until($billable);
        $chargeAt = $deferral !== null ? BillingTime::latest($utc, $deferral) : $utc->copy();
        $storeDeferral = $deferral !== null || ! $periodFollows;

        // Written inside the caller's transaction, so the date the customer is shown and
        // the redemption that bought it stand or fall together.
        //
        // `$periodFollows` says whether the billing period itself is being lengthened
        // (a trial extension, an extension of an ordinarily charged period) or only the
        // charge is moving while the period keeps turning over underneath it.
        $this->mutateBillingMeta($billable, function (array $meta) use ($utc, $chargeAt, $periodFollows, $storeDeferral): array {
            if ($periodFollows) {
                $meta['next_charge_date_override'] = $utc->toIso8601String();
            }

            if ($storeDeferral) {
                $meta[ChargeDeferral::META_KEY] = $chargeAt->toIso8601String();
            }

            return $meta;
        });

        DB::afterCommit(function () use ($billable, $chargeAt): void {
            try {
                $this->setNextChargeDate($billable, $chargeAt);
            } catch (\Throwable $e) {
                Log::warning('Mollie next-charge-date PATCH failed — queued for retry', [
                    'billable' => $billable->getKey(),
                    'target' => $chargeAt->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);

                // Local state says the charge moved; Mollie still holds the old date and
                // would collect early. SweepPendingRetriesJob picks this up hourly.
                $this->mutateBillingMeta($billable, function (array $meta) use ($chargeAt, $e): array {
                    $meta['pending_subscription_patch'] = [
                        'reason' => 'next_charge_date',
                        'target' => $chargeAt->toIso8601String(),
                        'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                        'last_error' => $e->getMessage(),
                    ];

                    return $meta;
                });
            }
        });
    }

    /**
     * Tell Mollie the date of the next charge, and nothing else.
     *
     * For the day the package's own period already ends on — no override is written,
     * because `nextBillingDate()` names this date by itself. Used by the renewal to keep a
     * cycle on its anchor day through the short months
     * (SubscriptionPaymentHandler::pinNextChargeOnAnchor(), BillingAnchor): Mollie's rule
     * for the charge after a month end is not something the package relies on.
     */
    public function pinNextChargeDate(Billable $billable, \Carbon\CarbonInterface $date): void
    {
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if (! $billable instanceof Model || $customerId === null || $subscriptionId === '') {
            return;
        }

        $target = Carbon::instance($date)->utc()->startOfDay();

        // Asked first, because the answer decides whether anything has to happen at all.
        // The renewal that calls this has just been charged, and Mollie refuses a new start
        // date on a charged subscription: moving the date means replacing the subscription.
        // If Mollie's own next date already is the anchor day — under "clamp" it is, from
        // the second short month on — there is nothing to replace. Under either cadence the
        // subscription is replaced only where Mollie would otherwise charge on another day:
        // under "clamp" once (its start date was itself a shortened day), under "drift"
        // after every month shorter than the anchor day.
        try {
            $held = MollieCall::send(new GetSubscriptionRequest($customerId, $subscriptionId));
            $next = (string) ($held->nextPaymentDate ?? '');

            if ($next !== '' && $next === $target->toDateString() && ! MollieSubscriptionReplacer::pending($billable)) {
                return;
            }
        } catch (\Throwable $e) {
            // Not knowing what Mollie holds is no reason to leave the anchor: the date is
            // sent, and a failure of that is the caller's to retry.
            Log::info('Could not read Mollie\'s next charge date before pinning it — sending it', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendUpdate($billable, [], $target, 'anchor_pin');
    }

    /**
     * The one door for every PATCH of the billable's Mollie subscription.
     *
     * Holds the billable's subscription lock (`MollieSubscriptionReplacer::withLock()`),
     * finishes a replacement left unfinished before it (its old id is cancelled), and
     * sends the PATCH to the id the row carries now. When Mollie refuses the start date
     * because the subscription has been charged, the subscription is replaced instead,
     * with every field of this PATCH — see `MollieSubscriptionReplacer`.
     *
     * @param  array{amount?: Money, description?: string, interval?: string, metadata?: array<string, mixed>}  $fields
     */
    protected function sendUpdate(Model&Billable $billable, array $fields, ?CarbonInterface $startDate, string $reason): void
    {
        MollieSubscriptionReplacer::withLock($billable, function () use ($billable, $fields, $startDate, $reason): void {
            $replacer = app(MollieSubscriptionReplacer::class);
            $replacer->resume($billable);

            $customerId = (string) ($billable->getMollieCustomerId() ?? '');
            $subscriptionId = MollieSubscriptionReplacer::storedSubscriptionId($billable);

            if ($customerId === '' || $subscriptionId === '') {
                Log::warning('Mollie subscription update skipped — missing Mollie customer or subscription id', [
                    'billable' => $billable->getKey(),
                    'reason' => $reason,
                ]);

                return;
            }

            try {
                MollieCall::send(new MollieUpdateSubscriptionRequest(
                    customerId: $customerId,
                    subscriptionId: $subscriptionId,
                    amount: $fields['amount'] ?? null,
                    description: $fields['description'] ?? null,
                    interval: $fields['interval'] ?? null,
                    startDate: $startDate !== null ? new Date(Carbon::instance($startDate)) : null,
                    metadata: $fields['metadata'] ?? null,
                ));
            } catch (\Throwable $e) {
                if ($startDate === null || ! MollieSubscriptionAnswer::refusesStartDateAfterCharge($e)) {
                    throw $e;
                }

                $replacer->replace($billable, $startDate, $fields, $reason);
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

        $this->sendUpdate($billable, [], $newDate, 'next_charge_date');

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

        $this->sendUpdate($billable, [], $newDate, 'next_charge_date');

        $meta = $billable->getBillingSubscriptionMeta();

        // A date that is the stored charge deferral moves the charge only. Writing it
        // as the period override as well would stretch the billing period to the end
        // of the coupon, and `nextBillingDate()` — quota recharge, prorata windows,
        // the lapsed-period guard — would stand still for as long as the coupon runs.
        $storedDeferral = $meta[ChargeDeferral::META_KEY] ?? null;
        $isDeferralOnly = is_string($storedDeferral)
            && $storedDeferral !== ''
            && Carbon::parse($storedDeferral)->getTimestamp() === $newDate->getTimestamp();

        if ($isDeferralOnly) {
            return;
        }

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
            if (\GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer::cancelledAlready($e)) {
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

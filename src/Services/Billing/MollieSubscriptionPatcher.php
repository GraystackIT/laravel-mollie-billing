<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Verantwortlich für Mollie-Subscription-Änderungen für künftige Perioden.
 *
 * Logik wurde aus UpdateSubscription extrahiert:
 * - cancelAndRecreateMollieSubscription → updateRecurringAmount() (intern: cancel + create)
 * - cancelMollieSubscriptionForFreeDowngrade → cancelForFreeDowngrade()
 * - updateMollieSubscription → in-place PATCH (kommt im Phase-2-Pfad)
 */
class MollieSubscriptionPatcher
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {}

    /**
     * Wendet einen PlanChangeIntent auf die Mollie-Subscription an.
     * - newPlan = Free → cancelForFreeDowngrade()
     * - sonst → updateRecurringAmount() mit neuen Plan/Sitzen/Addons
     */
    public function updateForIntent(Billable $billable, PlanChangeIntent $intent): void
    {
        if ($this->catalog->isFreePlan($intent->newPlan, $intent->newInterval)) {
            $this->cancelForFreeDowngrade($billable);
            return;
        }

        $extraSeats = max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan));
        $totalSeats = $this->catalog->includedSeats($intent->newPlan) + $extraSeats;
        $newAddons = array_keys($intent->newAddons);

        // Preserve any active recurring coupon discount across plan/seat/addon
        // changes — otherwise the PATCH would silently undo the campaign price.
        $couponDiscountNet = 0;
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        if (is_array($marker)) {
            $baseNet = SubscriptionAmount::net(
                $this->catalog,
                $billable,
                $intent->newPlan,
                $intent->newInterval,
                $totalSeats,
                $newAddons,
            );
            $couponDiscountNet = $this->markerDiscount($marker, $baseNet);
        }

        $this->updateRecurringAmount(
            billable: $billable,
            planCode: $intent->newPlan,
            interval: $intent->newInterval,
            addons: $newAddons,
            extraSeats: $extraSeats,
            intervalChanged: $intent->currentInterval !== $intent->newInterval,
            couponDiscountNet: $couponDiscountNet,
        );
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
            $interval === 'yearly' ? BillingTime::nowUtc()->addYear() : BillingTime::nowUtc()->addMonth()
        );
    }

    /**
     * Mirrors `CouponService::computeMarkerDiscount()` — the discount is locked
     * to the recurring net at coupon-apply time so additions (seats/addons) made
     * afterwards aren't silently rabattiert.
     *
     * @param  array<string,mixed>  $marker
     */
    private function markerDiscount(array $marker, int $netAmount): int
    {
        if ($netAmount <= 0) {
            return 0;
        }

        $type = (string) ($marker['discount_type'] ?? '');
        $value = (int) ($marker['discount_value'] ?? 0);

        $baseAmount = isset($marker['base_amount_net'])
            ? min((int) $marker['base_amount_net'], $netAmount)
            : $netAmount;

        if ($type === 'percentage') {
            return (int) round($baseAmount * $value / 100);
        }

        if ($type === 'fixed') {
            return min($value, $baseAmount);
        }

        return 0;
    }

    /**
     * PATCH der Mollie-Subscription mit neuem aggregierten Recurring-Betrag.
     *
     * Mollie's `PATCH /subscriptions/:id` ändert nur den Betrag für die *nächste* Recurring-Periode —
     * es löst keinen sofortigen Charge aus. Das ist genau das gewünschte Verhalten: laufende Periode
     * unangetastet, neuer Betrag ab der nächsten Abrechnung.
     *
     * KEIN cancel+recreate-Fallback bei PATCH-Failures: `CreateSubscriptionRequest` würde Mollie
     * sofort einen ersten Charge senden lassen → doppelte Abbuchung. Failures werden geloggt und
     * bei Bedarf vom RetrySubscriptionPatchJob aufgegriffen.
     *
     * @param  array<int, string>  $addons
     *
     * @throws \Throwable  When the Mollie PATCH call fails (Retry-Job kann darauf reagieren).
     */
    public function updateRecurringAmount(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        bool $intervalChanged = false,
        int $couponDiscountNet = 0,
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

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $value = number_format(((int) $vat['gross']) / 100, 2, '.', '');

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
        //  2. interval change → reset cadence (else Mollie keeps old cadence with new amount)
        //  3. amount-only change → leave cadence untouched (null)
        $startDate = match (true) {
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $intervalChanged => new Date(
                $interval === 'yearly' ? BillingTime::nowUtc()->addYear() : BillingTime::nowUtc()->addMonth()
            ),
            default => null,
        };

        Mollie::send(new MollieUpdateSubscriptionRequest(
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
     * Verschiebt das Datum der nächsten Mollie-Abrechnung um die angegebene
     * Anzahl Tage in die Zukunft. Wird vom PeriodExtension-Coupon-Type genutzt.
     * Nach erfolgreichem PATCH wird ein Override im Subscription-Meta gesetzt,
     * damit `nextBillingDate()` den verschobenen Wert zurückgibt.
     */
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

        Mollie::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            startDate: new Date($newDate),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['next_charge_date_override'] = $newDate->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Cancelt die Mollie-Subscription beim Free-Downgrade. Tolerant für API-Failures.
     */
    public function cancelForFreeDowngrade(Billable $billable): void
    {
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            return;
        }

        try {
            Mollie::send(new CancelSubscriptionRequest($customerId, $subscriptionId));
        } catch (\Throwable $e) {
            Log::warning('Mollie subscription cancel during free-downgrade failed', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

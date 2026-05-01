<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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

        $this->updateRecurringAmount(
            billable: $billable,
            planCode: $intent->newPlan,
            interval: $intent->newInterval,
            addons: array_keys($intent->newAddons),
            extraSeats: max(0, $intent->newSeats - $this->catalog->includedSeats($intent->newPlan)),
        );
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
        $amountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addons);

        $vat = $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $amountNet,
            $billable->vat_number ?? null,
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

        Mollie::send(new MollieUpdateSubscriptionRequest(
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            amount: new Money($currency, $value),
            metadata: $metadata,
        ));
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

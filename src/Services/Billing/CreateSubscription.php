<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use Carbon\Carbon;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Creates a Mollie subscription and updates the billable to reflect the new
 * Mollie-source state. Wallet hydration is the caller's responsibility — see
 * the three call sites for the right hydration strategy in each context:
 *
 *  - First-time activation (MollieWebhookController::handleFirstPaymentPaid):
 *    credit `included_usages` directly into the wallet.
 *  - Local→Mollie upgrade (MollieWebhookController::handleLocalToMollieUpgrade):
 *    use WalletPlanChangeAdjuster to rebalance plan credits while preserving
 *    purchased balance.
 *  - Resubscribe in grace period (ResubscribeSubscription): do nothing — the
 *    wallet already holds the current period's credits.
 *
 * The Mollie-Subscription amount is computed from the catalog (base + seats +
 * addons + VAT), never from the just-paid first-payment — otherwise a SinglePayment
 * coupon would lock the discounted total in as the dauerhafte Recurring-Höhe.
 * If a Recurring coupon applies, the caller passes its discount via
 * `recurring_discount_net`.
 */
class CreateSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
    ) {
    }

    /**
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,recurring_discount_net?:int,mandate_id?:string,trial_days?:int}  $spec
     *         `trial_days` is set exclusively by the trial-activation webhook path.
     *         When present and > 0, the Mollie subscription's startDate is pushed
     *         out to `now + trial_days` (no charge during the trial), the local
     *         status is set to Trial, and `trial_ends_at` is populated.
     */
    public function handle(Billable $billable, array $spec): void
    {
        /** @var Model&Billable $billable */
        $planCode = $spec['plan_code'];
        $interval = $spec['interval'];
        $addonCodes = $spec['addon_codes'] ?? [];
        $extraSeats = (int) ($spec['extra_seats'] ?? 0);
        $recurringDiscountNet = max(0, (int) ($spec['recurring_discount_net'] ?? 0));
        $trialDays = max(0, (int) ($spec['trial_days'] ?? 0));
        $isTrial = $trialDays > 0;
        $currency = (string) config('mollie-billing.currency', 'EUR');

        $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);

        $baseRecurringNet = SubscriptionAmount::net(
            $this->catalog,
            $billable,
            $planCode,
            $interval,
            $totalSeats,
            $addonCodes,
        );
        $netForRecurring = max(0, $baseRecurringNet - $recurringDiscountNet);

        // 100%-coverage: see MollieSubscriptionPatcher for rationale. Mollie can't
        // accept amount = 0, so we send the FULL price and defer startDate past
        // the marker's valid_until — Mollie won't charge during the discount
        // lifetime, the first real charge after that is at full price (marker
        // already expired by then).
        $isFullCoverage = $netForRecurring === 0 && $recurringDiscountNet > 0 && $baseRecurringNet > 0;
        $netForCharge = $isFullCoverage ? $baseRecurringNet : $netForRecurring;

        $vat = $this->vatService->calculate(
            (string) ($billable->getBillingCountry() ?? ''),
            $netForCharge,
            $billable,
        );
        $amountGross = (int) $vat['gross'];

        // Mollie schedules the first recurring charge at $startDate. Default cadence:
        // the first billing period is already paid via the checkout's first-payment,
        // so Mollie's first recurring charge must happen one full period later.
        // Trial override: first charge fires exactly at trial end (now + trialDays).
        // Full-coverage override: skip past the discount lifetime.
        $startDate = match (true) {
            $isTrial => BillingTime::nowUtc()->addDays($trialDays),
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $interval === 'yearly' => BillingTime::nowUtc()->addYear(),
            default => BillingTime::nowUtc()->addMonth(),
        };

        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: $billable->getMollieCustomerId(),
            amount: new Money($currency, number_format($amountGross / 100, 2, '.', '')),
            interval: $interval === 'yearly' ? '12 months' : '1 month',
            description: "{$planCode} subscription",
            startDate: new Date($startDate),
            metadata: [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'plan_code' => $planCode,
                'interval' => $interval,
            ],
            webhookUrl: route(BillingRoute::webhook()),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = $totalSeats;
        $meta['mollie_subscription_id'] = (string) ($subscription->id ?? '');

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => $isTrial ? SubscriptionStatus::Trial : SubscriptionStatus::Active,
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'active_addon_codes' => $addonCodes,
            'subscription_meta' => $meta,
            'subscription_period_starts_at' => BillingTime::nowUtc(),
            'subscription_ends_at' => null,
            'trial_ends_at' => $isTrial ? BillingTime::nowUtc()->addDays($trialDays) : null,
        ])->save();

        SubscriptionCreated::dispatch($billable, $planCode, $interval);
    }

    /**
     * For a 100%-coverage recurring discount, return the Mollie startDate that
     * skips the entire discount lifetime: marker.valid_until + 1 day. Falls
     * back to one period from now if the marker has no valid_until.
     */
    private function fullCoverageStartDate(Billable $billable, string $interval): \Carbon\CarbonInterface
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        $validUntil = is_array($marker) && isset($marker['valid_until']) && $marker['valid_until'] !== null
            ? Carbon::parse((string) $marker['valid_until'])
            : null;

        if ($validUntil !== null) {
            return $validUntil->copy()->addDay();
        }

        return $interval === 'yearly' ? BillingTime::nowUtc()->addYear() : BillingTime::nowUtc()->addMonth();
    }
}

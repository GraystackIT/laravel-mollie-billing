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
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
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
 * coupon would lock the discounted total in as the permanent recurring amount.
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
     * @param  array{plan_code:string,interval:string,addon_codes?:array<int,string>,extra_seats?:int,recurring_discount_net?:int,mandate_id?:string,trial_days?:int,trial_ends_at?:\Carbon\CarbonInterface,paid_period_ends_at?:\Carbon\CarbonInterface}  $spec
     *         `trial_days` is set exclusively by the trial-activation webhook path.
     *         When present and > 0, the Mollie subscription's startDate is pushed
     *         out to `now + trial_days` (no charge during the trial), the local
     *         status is set to Trial, and `trial_ends_at` is populated.
     *
     *         `trial_ends_at` is the same thing with an absolute target instead of a
     *         day count — ResubscribeSubscription uses it to put a cancelled trial
     *         back on its *original* end date rather than granting a fresh trial.
     *
     *         `paid_period_ends_at` anchors Mollie's first recurring charge to the end
     *         of a period the customer has already paid for. Only the checkout callers
     *         may omit it: there the first payment just happened, so `now + 1 interval`
     *         is the correct cadence. ResubscribeSubscription runs mid-grace, where the
     *         paid period ends *earlier* than that — without the anchor the days
     *         between the old period end and the restarted cadence go unbilled.
     *         `subscription_period_starts_at` is back-dated by one interval to match,
     *         so `nextBillingDate()` agrees with Mollie's schedule.
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
        $trialEndsAt = isset($spec['trial_ends_at'])
            ? BillingTime::toUtc($spec['trial_ends_at'])
            : ($trialDays > 0 ? BillingTime::nowUtc()->addDays($trialDays) : null);
        $isTrial = $trialEndsAt !== null;
        $paidPeriodEndsAt = isset($spec['paid_period_ends_at'])
            ? BillingTime::toUtc($spec['paid_period_ends_at'])
            : null;
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

        // VAT per invoice line, not on the total: each renewal this amount pays for
        // is booked line by line (SubscriptionPaymentHandler → InvoiceService), and
        // the two roundings disagree by the per-line residuals. See
        // MollieSubscriptionPatcher::recurringLineNets(), which must stay the
        // authority here so a later PATCH does not shift the price by a cent.
        $amountGross = BillingMoney::grossWithLineVat(
            MollieSubscriptionPatcher::recurringLineNets(
                $this->catalog,
                $billable,
                $planCode,
                $interval,
                $addonCodes,
                max(0, $extraSeats),
                $netForCharge,
            ),
            (float) $vat['rate'],
        );

        // Mollie schedules the first recurring charge at $startDate. Default cadence:
        // the first billing period is already paid via the checkout's first-payment,
        // so Mollie's first recurring charge must happen one full period later.
        // Trial override: first charge fires exactly at trial end.
        // Full-coverage override: skip past the discount lifetime.
        // Anchor override: the caller knows when the already-paid period ends.
        // A trial and a fully-covering recurring coupon can both apply to the same
        // signup, and then neither date alone is right: charging at trial end
        // collects full price during months the coupon promised free, and charging at
        // the coupon's end would bill nothing for a trial that ended earlier. The
        // later of the two is the first moment money is actually owed.
        $startDate = match (true) {
            $isTrial && $isFullCoverage => BillingTime::latest(
                $trialEndsAt,
                $this->fullCoverageStartDate($billable, $interval),
            ),
            $isTrial => $trialEndsAt,
            $isFullCoverage => $this->fullCoverageStartDate($billable, $interval),
            $paidPeriodEndsAt !== null => $paidPeriodEndsAt,
            default => BillingTime::addInterval(BillingTime::nowUtc(), $interval),
        };

        // The mandate all four activation paths already hand us. Without it Mollie binds
        // the subscription to "the customer's first valid mandate", which on a recovery
        // checkout is the *old* one — and MandateUpdated has just queued
        // RevokeMollieMandateJob for exactly that mandate. The worker then revokes what the
        // subscription is bound to, so the first real charge after the trial fails against
        // a revoked mandate and dunning starts for a customer who had just supplied a
        // working payment method.
        $mandateId = (string) ($spec['mandate_id'] ?? $billable->getMollieMandateId() ?? '');

        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: $billable->getMollieCustomerId(),
            amount: new Money($currency, BillingMoney::mollieAmount($amountGross, $currency)),
            interval: $interval === 'yearly' ? '12 months' : '1 month',
            mandateId: $mandateId !== '' ? $mandateId : null,
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

        // A fresh subscription starts a fresh dunning clock. `past_due_since` used to
        // survive a cancellation, so months later ONE failed renewal was measured
        // against the old date and the nightly pass auto-cancelled the same night —
        // a zero-day dunning window, before Mollie had even retried the payment.
        //
        // Only the dunning keys: pricing and scheduling belong to the caller here. The
        // 100 %-coverage coupon path sets `active_recurring_coupon` before this call
        // precisely so the start date below can be deferred past the free periods.
        $meta = SubscriptionMetaHygiene::withoutDunningEpisode($billable->getBillingSubscriptionMeta());
        $meta['seat_count'] = $totalSeats;
        $meta['mollie_subscription_id'] = (string) ($subscription->id ?? '');

        // With an anchor the local period must line up with Mollie's schedule: the
        // period that ends at the anchor started one interval before it, not now.
        // Clamped, because the forward direction is: `nextBillingDate()` derives from this
        // anchor with addMonthNoOverflow(), and plain subMonth() overflows — a 29/30/31 March
        // anchor was back-dated to 1/2/3 March, which reads forward as 1/2/3 April while
        // Mollie charges on the 29th/30th/31st. Three days of disagreement between what the
        // dashboard promises and what is collected, and the overage pass's "renews tomorrow"
        // window matches neither date, so that period's overage is never collected.
        $periodStartsAt = $paidPeriodEndsAt !== null && ! $isTrial
            ? BillingTime::subInterval($paidPeriodEndsAt, $interval)
            : BillingTime::nowUtc();

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => $isTrial ? SubscriptionStatus::Trial : SubscriptionStatus::Active,
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'active_addon_codes' => $addonCodes,
            'subscription_meta' => $meta,
            'subscription_period_starts_at' => $periodStartsAt,
            'subscription_ends_at' => null,
            'trial_ends_at' => $trialEndsAt,
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

        return BillingTime::addInterval(BillingTime::nowUtc(), $interval);
    }
}

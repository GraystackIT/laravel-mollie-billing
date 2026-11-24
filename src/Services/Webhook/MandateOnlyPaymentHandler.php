<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Events\TrialStarted;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Support\TrialQuota;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MandateOnlyPaymentHandler
{
    /**
     * The trial end an activation in flight computed before its Mollie call, for a retry of
     * it to reuse (activateTrialSubscriptionAfterMandate()). Removed once the trial runs.
     */
    public const TRIAL_ENDS_AT_META_KEY = 'trial_activation_ends_at';

    /**
     * Subscription states a mandate-only activation may run from. `New` is the
     * regular first checkout; PastDue, Expired and a Cancelled subscription
     * whose grace period has run out are recovery checkouts — StartSubscriptionCheckout
     * admits all of them (it gates only on `! hasAccessibleBillingSubscription()`)
     * and routes 100%-coupon and trial checkouts through this handler, and
     * FirstPaymentHandler allows the same set. Anything still accessible was
     * already caught by openGate()'s duplicate branch and never reaches the activation.
     */
    protected const ACTIVATABLE_STATES = [
        SubscriptionStatus::New,
        SubscriptionStatus::PastDue,
        SubscriptionStatus::Expired,
        SubscriptionStatus::Cancelled,
    ];

    /**
     * Whether this billable may be activated by the payment we are holding.
     *
     * The enum list above is not the whole answer, and the docblock's claim that
     * "anything still accessible was already caught by openGate()" is what hid the gap:
     * `Active` with a past `subscription_ends_at` is *not* accessible — that is precisely
     * the state an AccessGrant coupon and `ActivateLocalSubscription($durationDays)`
     * leave behind once the grant lapses. The gate let it through, this list did not, and
     * both activation bodies logged and returned.
     *
     * What that cost: the customer was routed into a trial checkout (StartSubscription-
     * Checkout admits them, they have no mandate yet), the €0 mandate payment settled,
     * the mandate was saved — and nothing else happened. No subscription, no invoice, no
     * runAfterCheckout, and the return page span to a timeout. Worse, their second
     * attempt now *had* a mandate, so the trial branch was skipped and they were charged
     * full price for the trial they were offered.
     *
     * So the predicate is the gate's own question, with the enum list kept as the
     * explicit statement of intent for the states that carry no `ends_at`.
     */
    protected function mayActivate(Billable $billable): bool
    {
        if (in_array($billable->getBillingSubscriptionStatus(), self::ACTIVATABLE_STATES, true)) {
            return true;
        }

        return ! $billable->hasAccessibleBillingSubscription();
    }

    /**
     * Whether this authorisation upgrades a free (local) plan under a code that covers
     * the price in full — started on the plan screen, `UpgradeLocalToMollie`.
     *
     * The accessible subscription the gate finds is then the very plan being replaced,
     * not a second subscription. Only a LOCAL one qualifies: once a Mollie subscription
     * runs, the same payment is a duplicate like any other.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function replacesLocalPlan(Billable $billable, array $metadata): bool
    {
        return ($metadata['pending_subscription_upgrade_from_local'] ?? false) === true
            && $billable->isLocalBillingSubscription();
    }

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly CouponService $couponService,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletUsageService $walletService,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata = []): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $previousMandate = $billable->mollie_mandate_id;

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
        ])->save();

        event(new MandateUpdated($billable, $previousMandate, $billable->mollie_mandate_id));

        if (! empty($metadata['pending_subscription_plan_code'])) {
            $this->activateSubscriptionAfterMandate($payment, $billable, $metadata);
        }
    }

    /**
     * A failed/canceled/expired mandate-only payment is not a payment failure:
     * the €0 authorisation was aborted, but any existing subscription and its
     * mandate at Mollie are untouched — the billable must NOT become PastDue.
     * Only when there is no accessible subscription (checkout-time mandate flow
     * for trials / 100%-coupons) does the abort end the checkout.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata = []): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        Log::info('Mandate-only payment did not complete', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $payment->id ?? null,
            'status' => $payment->status ?? null,
        ]);

        if (! $billable->hasAccessibleBillingSubscription()) {
            MollieBilling::runAfterCheckout($billable, false);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function activateSubscriptionAfterMandate(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ((string) ($metadata['pending_subscription_plan_code'] ?? '') === '') {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');

        // Same two-phase split as FirstPaymentHandler, for the same reason: the gate
        // is short and transactional (row lock, guards, durable claim), the activation
        // runs outside it. With the Mollie CreateSubscription call inside the
        // transaction, anything throwing after it — an app listener, the
        // after-checkout hook — rolled back the trial state and
        // `mollie_subscription_id` while the subscription existed at Mollie, and the
        // re-delivery created a second one.
        $gate = $this->openGate($payment, $billable, $paymentId, $metadata);

        if ($gate === ActivationGate::AlreadyBooked) {
            // See FirstPaymentHandler: an invoice without a finished activation is a
            // crash between the two, not a completed run — and silence there left a
            // paying customer with no subscription and nobody informed.
            if (! $this->support->activationCompleted($billable)) {
                // See FirstPaymentHandler: the retry job resumes this state.
                if (ActivationRetry::resuming()) {
                    $this->support->claimForResume($billable, $paymentId);

                    try {
                        $this->activateAfterMandate($payment, $billable, $metadata);
                    } finally {
                        $this->support->releaseActivationClaim($billable, $paymentId);
                    }

                    return;
                }

                $this->support->reportIncompleteActivation($billable, $payment, 'mandate_only');
            } else {
                Log::info('Webhook re-delivery: invoice exists for mandate-only activation, skipping', [
                    'payment_id' => $paymentId,
                    'billable_id' => $billable->getKey(),
                ]);
            }

            return;
        }

        if ($gate === ActivationGate::Duplicate) {
            Log::warning('Mandate-only subscription activation skipped — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'current_status' => $billable->getBillingSubscriptionStatus()->value,
            ]);
            DuplicatePaymentReceived::dispatch($billable, $paymentId);

            return;
        }

        try {
            $this->activateAfterMandate($payment, $billable, $metadata);
        } finally {
            $this->support->releaseActivationClaim($billable, $paymentId);
        }
    }

    /**
     * Decide under the row lock whether this delivery may activate, and claim it.
     *
     * The invoice guard is kept even though the trial branch writes no invoice: a
     * 100%-coupon activation does write one (the local zero-amount audit document),
     * and for the trial branch it simply never matches — the accessible Trial
     * subscription is what stops a re-run there, reported as the duplicate outcome.
     */
    protected function openGate(object $payment, Billable $billable, string $paymentId, array $metadata = []): ActivationGate
    {
        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($payment, $billable, $paymentId, $metadata): ActivationGate {
            $this->support->lockBillableRow($billable);

            if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
                return ActivationGate::AlreadyBooked;
            }

            if ($billable->hasAccessibleBillingSubscription() && ! $this->replacesLocalPlan($billable, $metadata)) {
                return ActivationGate::Duplicate;
            }

            $this->support->claimActivation($billable, $paymentId);

            return ActivationGate::Proceed;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function activateAfterMandate(object $payment, Billable $billable, array $metadata): void
    {
        /** @var Model&Billable $billable */
        $planCode = (string) ($metadata['pending_subscription_plan_code'] ?? '');
        $interval = (string) ($metadata['pending_subscription_interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['pending_subscription_addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['pending_subscription_extra_seats'] ?? 0);
        $couponCode = (string) ($metadata['pending_subscription_coupon_code'] ?? '');
        $trialDays = (int) ($metadata['pending_subscription_trial_days'] ?? 0);

        $billable->forceFill([
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        // Activate FIRST so plan_code/interval/source land on the billable even when the
        // country-match check below cancels the subscription. CancelSubscription only
        // touches status + ends_at, so ResubscribeSubscription can still recover the
        // billable once the user resolves the mismatch.
        if ($trialDays > 0) {
            $this->activateTrialSubscriptionAfterMandate(
                $payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $couponCode, $trialDays
            );
        } else {
            $this->activateCouponSubscriptionAfterMandate(
                $payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $couponCode,
                ($metadata['pending_subscription_upgrade_from_local'] ?? false) === true,
            );
        }

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during mandate-only activation', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    protected function activateCouponSubscriptionAfterMandate(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        string $couponCode,
        bool $upgradeFromLocal = false,
    ): void {
        /** @var Model&Billable $billable */

        // The free plan this upgrade replaces. Remembered on the billable before anything
        // is overwritten, so a retry that resumes after the invoice still knows what the
        // wallets have to be moved away from.
        $origin = null;

        if ($upgradeFromLocal) {
            $origin = $billable->getBillingSubscriptionMeta()['upgrade_from_local_origin'] ?? null;

            if (! is_array($origin) && $billable->isLocalBillingSubscription()) {
                $origin = [
                    'plan' => (string) ($billable->getBillingSubscriptionPlanCode() ?? ''),
                    'interval' => (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly'),
                    'period_start' => $billable->getBillingPeriodStartsAt()?->toIso8601String(),
                    'period_end' => $billable->nextBillingDate()?->toIso8601String(),
                ];

                $billable->forceFill(['subscription_meta' => array_merge(
                    $billable->getBillingSubscriptionMeta(),
                    ['upgrade_from_local_origin' => $origin],
                )])->save();
            }
        }

        if (! $this->mayActivate($billable) && ! is_array($origin)) {
            // A mandate has already been captured at this point, so a skip here is not a
            // no-op — it is a customer who paid (or accepted a €0 authorisation) and got
            // nothing. Reported, not just logged.
            $this->support->reportSkippedActivation($billable, $payment, 'coupon');

            return;
        }

        // A retry resumes after the first attempt already put this code's recurring marker
        // on the billable (it is written before the Mollie call). Validating the code
        // against its own marker answers "already active", so the retry could never
        // price it and never finish. The marker is this activation's own unfinished work:
        // take it off, price, and it is written again below.
        if (ActivationRetry::resuming()) {
            $marker = $this->couponService->getActiveRecurringCouponMarker($billable);

            if (is_array($marker)
                && strcasecmp((string) ($marker['code'] ?? ''), $couponCode) === 0
                && ! $this->support->activationCompleted($billable)
            ) {
                $this->couponService->clearActiveRecurringCouponMarker($billable);
                $billable->refresh();
            }
        }

        $pricedCoupon = $this->firstPaymentCouponPricing->price(
            $billable,
            $couponCode,
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );

        if ($pricedCoupon === null
            || $pricedCoupon['order_amount_net'] <= 0
            || $pricedCoupon['discount_net'] !== $pricedCoupon['order_amount_net']
        ) {
            Log::warning('Mandate-only subscription activation skipped — coupon does not produce 100% coverage', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'coupon_code' => $couponCode,
                'discount_net' => $pricedCoupon['discount_net'] ?? null,
                'order_amount_net' => $pricedCoupon['order_amount_net'] ?? null,
            ]);

            // Reported, for the same reason the status skip above is: the mandate has been
            // captured, so this is a customer who completed a €0 checkout and got nothing.
            // The coupon can lose its cover between checkout and webhook — its redemption
            // cap fills up, an operator deactivates it, `valid_until` passes — and a log
            // line is not something anybody is watching when that happens.
            $this->support->reportSkippedActivation(
                $billable,
                $payment,
                'coupon',
                sprintf(
                    'Mandate captured but the subscription was not activated: coupon [%s] no longer covers the full price (%d of %d net)',
                    $couponCode,
                    (int) ($pricedCoupon['discount_net'] ?? 0),
                    (int) ($pricedCoupon['order_amount_net'] ?? 0),
                ),
            );

            return;
        }

        // On a retry the audit invoice of this payment already exists and is reused.
        $resumedInvoice = ActivationRetry::resuming()
            ? \GraystackIT\MollieBilling\Models\BillingInvoice::query()->where('mollie_payment_id', (string) ($payment->id ?? ''))->first()
            : null;

        if ($resumedInvoice === null && $this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for mandate-only activation, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        if ($resumedInvoice !== null) {
            $invoice = $resumedInvoice;
        } else {
        // A new customer has no plan yet, so the plan can be written ahead of the invoice.
        // An upgrade must not: the customer is on a free plan that is ACTIVE, and writing
        // the paid plan's code onto it before the subscription exists handed them that
        // plan for nothing for as long as Mollie refused to create it. There the plan
        // moves with the subscription (CreateSubscription writes it), and the invoice is
        // given its period explicitly instead of reading it off the billable.
        $periodStart = BillingTime::nowUtc();

        if (! is_array($origin)) {
            $billable->forceFill([
                'subscription_plan_code' => $planCode,
                'subscription_interval' => SubscriptionInterval::from($interval),
                'subscription_period_starts_at' => $periodStart,
            ])->save();
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);

        // One coupon line per charged line, each taking exactly that line off.
        //
        // This document records a payment of 0, and VAT is rounded per line. A single
        // line for the whole discount does not cancel the charges it covers: Pro 29.50
        // and an addon 15.50 at 19 % carry 5.61 + 2.95 = 8.56 of VAT, the one coupon line
        // −45.00 carries −8.55, and the invoice for a €0 payment stated a gross of 0.01.
        // Mirrored line by line the roundings are symmetric and the total is 0 for every
        // rate and any number of lines. The coverage check above guarantees the discount
        // IS the sum of these lines.
        foreach ($lineItems as $line) {
            $lineNet = (int) ($line['total_net'] ?? 0);

            if ($lineNet <= 0) {
                continue;
            }

            $lineItems[] = [
                'kind' => 'coupon',
                'code' => (string) $pricedCoupon['coupon']->code,
                'label' => 'Coupon '.$pricedCoupon['coupon']->code.' — '.(string) ($line['label'] ?? ''),
                'quantity' => 1,
                'unit_price' => -$lineNet,
                'unit_price_net' => -$lineNet,
                'total_net' => -$lineNet,
            ];
        }

        try {
            $invoice = is_array($origin)
                ? $this->salesInvoiceService->createForPayment(
                    $payment, 'subscription', $lineItems, $billable,
                    $periodStart, BillingTime::addInterval($periodStart, $interval),
                )
                : $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
        } catch (\Throwable $e) {
            Log::error('Mandate-only audit invoice creation failed — re-delivery will retry', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            // Rethrown, not swallowed. Returning here let __invoke() record the outcome
            // as processed and answer 200, so Mollie never re-delivered: a transient
            // failure (deadlock, serial collision after its three retries) permanently
            // ended a signup that had already captured a mandate — with no event, no
            // admin notification and, unlike the prorata path, no cleanup job to catch
            // it. The invoice guard plus the activation claim make the retry safe, which
            // is exactly how FirstPaymentHandler handles the same failure.
            throw $e;
        }
        }

        // A Recurring coupon keeps discounting the renewals, so its discount must
        // reach CreateSubscription — and the marker has to exist *before* that
        // call: at 100% coverage Mollie cannot accept amount 0, so CreateSubscription
        // sends the full price and instead defers startDate past the marker's
        // valid_until. Without the marker it falls back to "one period from now"
        // and charges the full price from the second period on. redeem() below
        // re-writes the same marker; that is idempotent.
        $recurringDiscountNet = (int) ($pricedCoupon['recurring_discount_net'] ?? 0);
        if ($recurringDiscountNet > 0) {
            $this->couponService->applyRecurringMarker(
                $pricedCoupon['coupon'],
                $billable,
                (int) $pricedCoupon['order_amount_net'],
                // The interval being BOOKED: on an upgrade the billable still carries the
                // free plan's, and two free years would be counted as two free months.
                $interval,
            );
        }

        try {
            $this->support->createSubscription($this->createSubscription, $billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => $recurringDiscountNet,
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            $this->support->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during mandate-only activation failed',
                $e,
            );

            // Announced when the retry lands — see FirstPaymentHandler.
            return;
        }

        $this->firstPaymentCouponPricing->redeem(
            $billable,
            $pricedCoupon,
            $planCode,
            $interval,
            (int) $invoice->id,
        );

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
        ])->save();

        // Each step isolated — see WebhookSupport::completeActivation(). Once the invoice
        // exists a throw here is terminal: the re-delivery answers AlreadyBooked and takes
        // the quiet branch, so whatever was skipped would never run.
        if (is_array($origin)) {
            // An upgrade, not a first booking: the wallets hold the free plan's quota and
            // are moved to the new plan's (as the paid upgrade does), not credited on top.
            $this->support->completeActivation(
                $billable,
                $invoice,
                [],
                function () use ($billable, $origin, $planCode, $interval): void {
                    if ((string) $origin['plan'] !== '') {
                        app(\GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster::class)->adjust(
                            $billable,
                            (string) $origin['plan'],
                            (string) $origin['interval'],
                            $planCode,
                            $interval,
                            isset($origin['period_start']) ? \Carbon\Carbon::parse((string) $origin['period_start']) : null,
                            isset($origin['period_end']) ? \Carbon\Carbon::parse((string) $origin['period_end']) : null,
                        );
                    }

                    $meta = $billable->getBillingSubscriptionMeta();
                    unset($meta['upgrade_from_local_origin']);
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new \GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal(
                        $billable,
                        (string) $origin['plan'],
                        (string) $origin['interval'],
                        $planCode,
                        $interval,
                    ));
                },
            );

            return;
        }

        $this->support->completeActivation(
            $billable,
            $invoice,
            $this->catalog->includedUsages($planCode, $interval),
        );
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    protected function activateTrialSubscriptionAfterMandate(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        string $couponCode,
        int $trialDays,
    ): void {
        /** @var Model&Billable $billable */

        if (! $this->mayActivate($billable)) {
            $this->support->reportSkippedActivation($billable, $payment, 'trial');

            return;
        }

        $pricedCoupon = null;
        if ($couponCode !== '') {
            try {
                $pricedCoupon = $this->firstPaymentCouponPricing->price(
                    $billable, $couponCode, $planCode, $interval, $addonCodes, $extraSeats,
                    trialStarting: true,
                );
            } catch (\Throwable $e) {
                Log::warning('Trial activation: coupon pricing failed, proceeding without coupon', [
                    'billable_id' => $billable->getKey(),
                    'coupon_code' => $couponCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // The trial end, once: the coupon window, Mollie's start date and `trial_ends_at`
        // must name the same moment. Each used to read the clock on its own — the window
        // before the Mollie call, `trial_ends_at` after it — and an activation that crossed
        // midnight measured them from different days, so a later trial extension no longer
        // recognised the window as the trial's and left it behind.
        //
        // Recorded before the Mollie call, and reused by a retry of this activation: a retry
        // that adopts the subscription the first attempt created (its answer lost) runs
        // hours later, and a fresh clock read put the local trial end, the coupon window and
        // the deferral that much after the start date Mollie holds. Only a boundary still
        // ahead is reused; one that has passed cannot be a start date any more.
        $meta = $billable->getBillingSubscriptionMeta();
        $recorded = $meta[self::TRIAL_ENDS_AT_META_KEY] ?? null;
        $trialEndsAt = null;

        if (ActivationRetry::resuming() && is_string($recorded) && $recorded !== '') {
            try {
                $candidate = \Carbon\Carbon::parse($recorded)->setTimezone('UTC');
                $trialEndsAt = $candidate->copy()->startOfDay()->greaterThan(BillingTime::nowUtc()->startOfDay())
                    ? $candidate
                    : null;
            } catch (\Throwable) {
                $trialEndsAt = null;
            }
        }

        $trialEndsAt ??= BillingTime::nowUtc()->addDays($trialDays);
        $meta[self::TRIAL_ENDS_AT_META_KEY] = $trialEndsAt->toIso8601String();

        $billable->forceFill([
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'subscription_period_starts_at' => BillingTime::nowUtc(),
            'subscription_meta' => $meta,
        ])->save();

        // The marker BEFORE the Mollie call, and the discount passed into it: Mollie
        // collects every charge after the trial on its own, so a subscription created
        // at full price charges full price forever — while our marker keeps promising
        // the discount and the renewal invoice reconciles down to what actually
        // cleared. The customer would pay full price for a coupon the checkout
        // accepted. The non-trial mandate-only path already orders it this way.
        $recurringDiscountNet = 0;
        if ($pricedCoupon !== null && $pricedCoupon['coupon']->type === CouponType::Recurring) {
            $recurringDiscountNet = (int) ($pricedCoupon['recurring_discount_net'] ?? 0);

            if ($recurringDiscountNet > 0) {
                $this->couponService->applyRecurringMarkerFrom(
                    $pricedCoupon['coupon'],
                    $billable,
                    (int) $pricedCoupon['order_amount_net'],
                    $interval,
                    // Its periods are the subscription's, and the first one starts with
                    // the first charge, at the trial's end. Counted from the signup, a
                    // trial ate into the code: "two periods free" behind a 14-day trial
                    // deferred the charge to 62 days after the signup — 48 days after the
                    // trial, on a day off the cycle — and a partial code behind a long
                    // trial could end before its last promised charge.
                    $trialEndsAt,
                );
            }
        }

        // A first-payment coupon discounts the first charge — on a trial, the one at its
        // end. Mollie collects that charge from the subscription's recurring amount, so the
        // discount goes there for one period (CouponService::applyFirstChargeMarkerFrom()),
        // the same way and for the same reason as a recurring code's above. At 100 %
        // CreateSubscription keeps the full price and starts one period after the trial.
        if ($pricedCoupon !== null && $pricedCoupon['coupon']->type === CouponType::SinglePayment) {
            $recurringDiscountNet = max(0, (int) $pricedCoupon['discount_net']);

            if ($recurringDiscountNet > 0) {
                $this->couponService->applyFirstChargeMarkerFrom(
                    $pricedCoupon['coupon'],
                    $billable,
                    (int) $pricedCoupon['order_amount_net'],
                    $interval,
                    $trialEndsAt,
                );
            }
        }

        // A retry whose coupon could not be priced again (switched off since, say) finds the
        // marker the first attempt granted. Mollie is created at the amount that marker
        // promises — at full price beside it, the trial-end charge collected the full price
        // while the books promised the discount. And a retry that adopts the subscription
        // the first attempt created keeps that one's amount, which is the marker's too.
        if ($pricedCoupon === null && ActivationRetry::resuming()) {
            $leftover = $this->couponService->getActiveRecurringCouponMarker($billable);

            if (is_array($leftover) && ! \GraystackIT\MollieBilling\Services\Billing\PlanWaiver::isWaiver($leftover)) {
                $orderNet = SubscriptionAmount::net(
                    $this->catalog,
                    $billable,
                    $planCode,
                    $interval,
                    $this->catalog->includedSeats($planCode) + $extraSeats,
                    $addonCodes,
                );
                $recurringDiscountNet = max(0, $this->couponService->computeMarkerDiscount($billable, $orderNet, [], $trialEndsAt));
            }
        }

        try {
            $this->support->createSubscription($this->createSubscription, $billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => $recurringDiscountNet,
                'mandate_id' => $billable->mollie_mandate_id,
                'trial_days' => $trialDays,
                'trial_ends_at' => $trialEndsAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('CreateSubscription during trial activation failed', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'plan_code' => $planCode,
                'interval' => $interval,
                'error' => $e->getMessage(),
            ]);

            // CreateSubscription can throw AFTER the Mollie call but before its local
            // save, which leaves a live Mollie subscription with no local record of it —
            // the costliest state in the package. The retry looks for that subscription
            // before it creates one (WebhookSupport::createSubscription()). The event is
            // dispatched there too, once per payment.
            $this->support->reportIncompleteActivation($billable, $payment, 'trial_activation', $e->getMessage());

            return;
        }

        // Prorated to the trial length, then handed to completeActivation() so one failing
        // wallet cannot cost the customer the rest of their quota, the TrialStarted event
        // the app provisions on, or the after-checkout hook.
        $trialQuota = TrialQuota::forPlan(
            $this->catalog->includedUsages($planCode, $interval),
            $trialDays,
            $interval,
        );

        // Recorded, not left to be re-derived: what the trial was actually granted is
        // what every usage meter has to measure against, and re-deriving it from
        // `trial_days` goes wrong the moment the plan's configured trial length changes
        // or the trial is extended (`extendBillingTrialUntil()` moves the end date and
        // credits nothing). Without it the portal measured a trial wallet against the
        // full period quota and reported the un-granted remainder as consumption.
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['trial_quota'] = TrialQuota::marker($planCode, $interval, $trialDays, $trialQuota);
        unset($meta[self::TRIAL_ENDS_AT_META_KEY]);

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => $trialEndsAt,
            'subscription_meta' => $meta,
        ])->save();

        if ($pricedCoupon !== null) {
            $this->stashTrialCoupon($billable, $pricedCoupon, $planCode, $interval, $addonCodes, $extraSeats);
            $this->countRecurringGrant($billable, $pricedCoupon, $planCode, $interval);
        }

        $this->support->completeActivation(
            $billable,
            null,
            $trialQuota,
            fn () => event(new TrialStarted($billable, $planCode, $trialDays)),
            'subscription_trial_start',
        );
    }

    /**
     * Count a Recurring coupon's grant on a trial signup.
     *
     * The trial path writes only the marker, and every charge afterwards goes through
     * `redeemRecurringCouponForRenewal()` → `redeem(renewal: true)`, which by design
     * skips the cap checks AND the counter. So no code path ever performed the
     * grant-counting redeem for a trial signup: `redemptions_count` stayed 0 forever,
     * `globally_exhausted` never tripped, and a 50-redemption campaign could be
     * claimed by any number of trial signups. Per-billable limits still worked, since
     * those count rows.
     *
     * Redeemed with a zero discount: the discount belongs to the charges, which book
     * their own renewal redemptions. This row exists to occupy the campaign slot the
     * customer has been promised, which is why it runs only after the Mollie
     * subscription exists — a failed activation must not consume it.
     *
     * @param  array{coupon:\GraystackIT\MollieBilling\Models\Coupon, discount_net:int, recurring_discount_net?:int, order_amount_net:int}  $pricedCoupon
     */
    protected function countRecurringGrant(
        Billable $billable,
        array $pricedCoupon,
        string $planCode,
        string $interval,
    ): void {
        // A first-payment coupon carried over the trial is a grant of the same kind: its
        // marker is set, and its one application is booked by the trial-end charge.
        $carriedOver = $pricedCoupon['coupon']->type === CouponType::SinglePayment
            && (int) $pricedCoupon['discount_net'] > 0;

        if ($pricedCoupon['coupon']->type !== CouponType::Recurring && ! $carriedOver) {
            return;
        }

        try {
            $this->couponService->redeem($pricedCoupon['coupon'], $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'orderAmountNet' => (int) $pricedCoupon['order_amount_net'],
                'discount_amount_net' => 0,
                // The marker is already set — and set BEFORE CreateSubscription on
                // purpose, so Mollie was created at the discounted amount. Re-writing it
                // here would restart its lifetime from now, which is the same instant,
                // but skipping is clearer about which write owns the marker.
                'skip_marker' => true,
            ]);
        } catch (\Throwable $e) {
            // The campaign filling up between price() and here is a legitimate race.
            // The customer keeps the discount (Mollie is already created with it) and
            // the marker keeps honouring it; only the audit row is missing.
            Log::warning('Trial activation: recurring coupon grant could not be counted', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'coupon_code' => (string) $pricedCoupon['coupon']->code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply whatever a trial signup's coupon does at activation time.
     *
     * `$addonCodes` / `$extraSeats` are no longer read here — the Recurring branch
     * that needed them moved ahead of CreateSubscription. They stay in the signature
     * because this method is a protected override seam; dropping them would break an
     * app that overrides it.
     *
     * @param  array{coupon:\GraystackIT\MollieBilling\Models\Coupon, discount_net:int, recurring_discount_net?:int, order_amount_net:int}  $pricedCoupon
     * @param  array<int, string>  $addonCodes
     */
    protected function stashTrialCoupon(
        Billable $billable,
        array $pricedCoupon,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): void {
        /** @var Model&Billable $billable */
        $coupon = $pricedCoupon['coupon'];

        // Recurring is already handled before CreateSubscription — its marker has to
        // exist before Mollie is told the amount. Re-applying it here would be
        // harmless but redundant.
        if ($coupon->type === CouponType::Recurring) {
            return;
        }

        // A first-payment coupon is carried over the trial as a one-period marker, also
        // written before CreateSubscription. It used to be parked here in
        // `pending_first_charge_coupon` while Mollie was created at the full price, so the
        // trial-end charge collected the full price and the code was never honoured. The
        // renewal handler still reads that slot for trials started before.
        if ($coupon->type === CouponType::SinglePayment) {
            return;
        }

        // A TrialExtension buys days, not money, so there is nothing to stash for a
        // later charge — it takes effect now, on the trial this activation just
        // started. Redeemed here rather than at signup's start because
        // extendBillingTrialUntil() also PATCHes the Mollie subscription's next
        // charge date, which requires that subscription to exist.
        //
        // Falling through both branches above was the whole bug: the checkout
        // accepted the code, the customer saw it applied, and the trial ended on the
        // plan's own day with no redemption recorded anywhere.
        if ($coupon->type === CouponType::TrialExtension) {
            try {
                $this->couponService->redeem($coupon, $billable, [
                    'planCode' => $planCode,
                    'interval' => $interval,
                    'orderAmountNet' => (int) $pricedCoupon['order_amount_net'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Trial activation: TrialExtension coupon redemption failed', [
                    'billable_id' => $billable->getKey(),
                    'coupon_code' => (string) $coupon->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

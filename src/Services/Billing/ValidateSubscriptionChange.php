<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralized validation for subscription changes.
 *
 * This service runs all pre-flight checks before a plan change is applied
 * or before a deferred upgrade payment is created. It is called:
 *
 * - In Phase 1 (UpdateSubscription::update()) — to validate for the user
 *   before any payment is created.
 * - In Phase 2 (applyPendingPlanChange()) — to re-validate before applying
 *   a deferred change, in case state changed between payment and webhook.
 *
 * Override: Apps can bind their own implementation to customize validation
 * rules (e.g. grace periods for seats, custom addon rules):
 *
 *   $this->app->bind(ValidateSubscriptionChange::class, MyCustomValidator::class);
 */
class ValidateSubscriptionChange
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly ChargeUsageOverageDirectly $overageService,
    ) {}

    /**
     * Run all validation checks for the given subscription change.
     *
     * Mutates $context->newSeats (auto-derive) and $context->newAddons
     * (strip incompatible) as needed.
     *
     * @throws SeatDowngradeRequiredException
     * @throws DowngradeRequiresMandateException
     * @throws InvalidSubscriptionStateException
     */
    public function validate(Billable $billable, SubscriptionChangeContext $context): void
    {
        $this->validateSeats($billable, $context);
        $this->validateAddons($billable, $context);
        $this->validateWalletUsage($billable, $context);
        $this->validateMollieReadiness($billable, $context);
    }

    /**
     * Validate seat count for the target plan.
     *
     * If the user currently uses more seats than the new plan includes,
     * AND the new plan supports extra seats (seat_price_net !== null),
     * the seat count is automatically increased to cover the used seats.
     * This means the user will be charged for extra seats on the new plan.
     *
     * If the new plan does NOT support extra seats (seat_price_net === null)
     * and the user uses more seats than included, a SeatDowngradeRequiredException
     * is thrown — the user must remove team members before downgrading.
     *
     * Override: Bind your own ValidateSubscriptionChange to change this behavior,
     * e.g. to always block downgrades or to allow grace periods.
     *
     * @throws SeatDowngradeRequiredException When the new plan cannot accommodate the used seats
     */
    protected function validateSeats(Billable $billable, SubscriptionChangeContext $context): void
    {
        // When seats were explicitly provided by the caller, respect the value.
        if ($context->seatsExplicit) {
            return;
        }

        $usedSeats = $billable->getUsedBillingSeats();
        $newIncludedSeats = $this->catalog->includedSeats($context->newPlan);
        $seatPriceNet = $this->catalog->seatPriceNet($context->newPlan, $context->newInterval);

        if ($usedSeats > $newIncludedSeats && $seatPriceNet === null) {
            throw new SeatDowngradeRequiredException($billable, $usedSeats, $newIncludedSeats);
        }

        // Auto-derive: preserve existing seat count across plan changes.
        // Without this, a billable with 14 seats switching plans would be
        // reset to max(usedSeats, includedSeats) — losing paid extra seats.
        // When the caller explicitly sets seats (e.g. dropExtraSeats),
        // seatsExplicit is true and this code is not reached.
        $currentSeatCount = $billable->getBillingSeatCount();
        $context->newSeats = max($currentSeatCount, $usedSeats, $newIncludedSeats);
    }

    /**
     * Strip addons that are incompatible with the new plan.
     *
     * When the plan changes, any addon that the new plan does not allow
     * (as defined by the catalog's planAllowsAddon()) is automatically removed.
     * This prevents billing for addons that make no sense on the target plan.
     *
     * The removed addons are reflected in the change diff and trigger
     * AddonDisabled events. No exception is thrown — removal is automatic.
     *
     * Override: Bind your own ValidateSubscriptionChange to keep all addons
     * or to apply custom compatibility rules.
     */
    protected function validateAddons(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $context->planChanged) {
            return;
        }

        $context->newAddons = array_values(array_filter(
            $context->newAddons,
            fn (string $code) => $this->catalog->planAllowsAddon($context->newPlan, $code),
        ));
    }

    /**
     * Check that wallet usage levels allow the plan change.
     *
     * Uses prorated excess logic: computes how much of the old plan's quota
     * was consumed beyond the prorated entitlement, then checks whether
     * the new plan's quota (+ rollover credits) can absorb the excess.
     *
     * If unresolvable overage remains and the billable has no Mollie mandate,
     * throws DowngradeRequiresMandateException. The actual charging happens
     * later in adjustWalletsForPlanChange().
     *
     * @throws DowngradeRequiresMandateException When unresolvable overage exists but no mandate is available
     */
    protected function validateWalletUsage(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();
        $rollover = $this->catalog->usageRollover($context->currentPlan);

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $oldIncluded = $this->catalog->includedUsage($context->currentPlan, $context->currentInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($context->newPlan, $context->newInterval, $slug);
            $balance = (int) $wallet->balanceInt;

            // Separate purchased credits from plan credits.
            $purchasedBalance = WalletUsageService::getPurchasedBalance($wallet);
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
            $planOnlyBalance = $balance - $purchasedRemaining;

            $excess = 0;
            if ($periodStart !== null && $periodEnd !== null && $oldIncluded > 0) {
                $result = BillingPolicy::computeUsageOverageForPlanChange(
                    $oldIncluded,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $result['excess'];
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $oldIncluded) : 0;
            $targetBalance = $newIncluded + $rolloverCredits + $purchasedRemaining - $excess;

            if ($targetBalance >= 0) {
                continue;
            }

            // Unresolvable overage: mandate is required to charge it.
            if (! $billable->hasMollieMandate()) {
                throw new DowngradeRequiresMandateException($billable, $context->newPlan);
            }
        }
    }

    /**
     * Ensure the billable is ready for a Mollie-based plan change.
     *
     * For deferred upgrades (prorata charge > 0 on a Mollie subscription),
     * the following must be true before creating a payment:
     *
     * 1. The billable has a valid Mollie mandate (hasMollieMandate() === true).
     *    Without a mandate, recurring payments cannot be created.
     *
     * 2. The subscription_meta contains a mollie_subscription_id.
     *    Without this, cancelAndRecreateMollieSubscription() cannot cancel
     *    the old subscription when the change is applied.
     *
     * 3. No pending_plan_change already exists in subscription_meta.
     *    Only one deferred upgrade can be in flight at a time.
     *
     * These checks only apply to Mollie subscriptions with a prorata charge.
     * Local subscriptions and downgrades skip this validation entirely.
     *
     * @throws InvalidSubscriptionStateException When requirements are not met
     */
    protected function validateMollieReadiness(Billable $billable, SubscriptionChangeContext $context): void
    {
        if (! $context->isMollie || $context->prorataChargeNet <= 0) {
            return;
        }

        if (! ($billable instanceof Model)) {
            return;
        }

        if (! $billable->hasMollieMandate()) {
            throw new InvalidSubscriptionStateException(
                'Cannot create a deferred upgrade payment — no Mollie mandate found.'
            );
        }

        $meta = $billable->getBillingSubscriptionMeta();

        if (empty($meta['mollie_subscription_id'])) {
            throw new InvalidSubscriptionStateException(
                'Cannot create a deferred upgrade — no mollie_subscription_id in subscription meta.'
            );
        }

        if (! empty($meta['pending_plan_change'])) {
            throw new InvalidSubscriptionStateException(
                'A plan change is already pending payment confirmation. Cancel it first or wait for the payment to resolve.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionResumed;
use Illuminate\Database\Eloquent\Model;

class ResubscribeSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly CreateSubscription $createSubscription,
    ) {
    }

    public function handle(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable->getBillingSubscriptionStatus() !== SubscriptionStatus::Cancelled) {
            throw new \RuntimeException('Cannot resubscribe: subscription is not in a cancelled state.');
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();
        if ($endsAt === null || ! $endsAt->isFuture()) {
            throw new \RuntimeException('Cannot resubscribe: grace period has already ended.');
        }

        $isLocal = $billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value;

        if ($isLocal) {
            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_ends_at' => null,
            ])->save();

            SubscriptionResumed::dispatch($billable);

            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode();
        $interval = $billable->getBillingSubscriptionInterval();
        if ($planCode === null || $interval === null) {
            throw new \RuntimeException('Cannot resubscribe: plan or interval missing.');
        }

        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
        $recurringDiscountNet = 0;
        if (is_array($marker)) {
            $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $billable->getExtraBillingSeats());
            $baseNet = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
                $this->catalog,
                $billable,
                $planCode,
                $interval,
                $totalSeats,
                $billable->getActiveBillingAddonCodes(),
            );
            $recurringDiscountNet = $this->computeMarkerDiscount($marker, $baseNet);
        }

        $this->createSubscription->handle($billable, [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $billable->getActiveBillingAddonCodes(),
            'extra_seats' => $billable->getExtraBillingSeats(),
            'recurring_discount_net' => $recurringDiscountNet,
        ]);

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_ends_at' => null,
        ])->save();

        SubscriptionResumed::dispatch($billable);
    }

    /**
     * Mirrors `CouponService::computeMarkerDiscount()` — the discount basis is
     * the original recurring net the coupon was applied against, capped to the
     * current charge so reductions are honored.
     *
     * @param  array<string,mixed>  $marker
     */
    private function computeMarkerDiscount(array $marker, int $netAmount): int
    {
        if ($netAmount <= 0) {
            return 0;
        }

        $discountType = (string) ($marker['discount_type'] ?? '');
        $discountValue = (int) ($marker['discount_value'] ?? 0);

        $baseAmount = isset($marker['base_amount_net'])
            ? min((int) $marker['base_amount_net'], $netAmount)
            : $netAmount;

        if ($discountType === 'percentage') {
            return (int) round($baseAmount * $discountValue / 100);
        }

        if ($discountType === 'fixed') {
            return min($discountValue, $baseAmount);
        }

        return 0;
    }
}

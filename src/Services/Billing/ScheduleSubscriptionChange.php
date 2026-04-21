<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\SubscriptionChangeApplyFailed;
use GraystackIT\MollieBilling\Events\SubscriptionChangeCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionChangeRescheduled;
use GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled;
use GraystackIT\MollieBilling\MollieBilling;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ScheduleSubscriptionChange
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    public function schedule(Billable $billable, array|SubscriptionUpdateRequest $request): void
    {
        $dto = SubscriptionUpdateRequest::from($request);

        DB::transaction(function () use ($billable, $dto): void {
            /** @var Model&Billable $billable */
            if ($billable instanceof Model) {
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
            $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
            $currentSeats = $billable->getBillingSeatCount();
            $currentAddons = $billable->getActiveBillingAddonCodes();

            $newPlan = $dto->planCode ?? $currentPlan;
            $newInterval = $dto->interval ?? $currentInterval;
            $planChanged = $newPlan !== $currentPlan;

            // Auto-derive seats from the new plan.
            $usedSeats = $billable->getUsedBillingSeats();
            $newSeats = $dto->seats ?? max($usedSeats, $this->catalog->includedSeats($newPlan));

            // Auto-filter incompatible addons.
            $newAddons = $dto->addons !== null
                ? array_keys(array_filter($dto->addons, fn ($q) => (int) $q > 0))
                : $currentAddons;

            if ($planChanged) {
                $newAddons = array_values(array_filter(
                    $newAddons,
                    fn (string $code) => $this->catalog->planAllowsAddon($newPlan, $code),
                ));
            }

            $scheduledAt = $billable->nextBillingDate() ?? now();

            $scheduledChange = [
                'plan_code' => $newPlan,
                'interval' => $newInterval,
                'seats' => $newSeats,
                'addons' => $dto->addons,
                'coupon_code' => $dto->couponCode,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ];

            $meta = $billable->getBillingSubscriptionMeta();
            $previous = $meta['scheduled_change'] ?? null;
            $meta['scheduled_change'] = $scheduledChange;

            if ($billable instanceof Model) {
                $billable->forceFill([
                    'subscription_meta' => $meta,
                    'scheduled_change_at' => $scheduledAt,
                ])->save();
            }

            if ($previous !== null) {
                event(new SubscriptionChangeRescheduled($billable, $scheduledChange, (array) $previous));
            } else {
                event(new SubscriptionChangeScheduled($billable, $scheduledChange, $scheduledAt));
            }
        });
    }

    public function cancel(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable instanceof Model) {
            $billable->refresh();
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $previous = $meta['scheduled_change'] ?? null;

        if ($previous === null) {
            return;
        }

        unset($meta['scheduled_change']);

        if ($billable instanceof Model) {
            $billable->forceFill([
                'subscription_meta' => $meta,
                'scheduled_change_at' => null,
            ])->save();
        }

        event(new SubscriptionChangeCancelled($billable, (array) $previous));
    }

    public function apply(Billable $billable): void
    {
        /** @var Model&Billable $billable */
        if ($billable instanceof Model) {
            $billable->refresh();
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $scheduledChange = $meta['scheduled_change'] ?? null;

        if ($scheduledChange === null) {
            return;
        }

        $payload = (array) $scheduledChange;
        $payload['apply_at'] = 'immediate';
        unset($payload['scheduled_at']);

        try {
            app(UpdateSubscription::class)->update($billable, $payload);

            // Clear scheduled change on success.
            if ($billable instanceof Model) {
                $billable->refresh();
                $meta = $billable->getBillingSubscriptionMeta();
                unset($meta['scheduled_change']);
                $billable->forceFill([
                    'subscription_meta' => $meta,
                    'scheduled_change_at' => null,
                ])->save();
            }
        } catch (\Throwable $e) {
            event(new SubscriptionChangeApplyFailed($billable, (array) $scheduledChange, $e));

            // Notify admins (best effort).
            try {
                $recipients = MollieBilling::notifyAdmin();
                if (! empty($recipients)) {
                    // Consumer notifications are app-defined; we just log the intent.
                    // A proper Notification class is out of scope for this phase.
                }
            } catch (\Throwable) {
                // ignore
            }

            throw $e;
        }
    }

}

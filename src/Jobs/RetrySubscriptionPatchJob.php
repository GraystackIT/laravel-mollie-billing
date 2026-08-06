<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Retry for Mollie subscription PATCH (for future recurring periods).
 *
 * Backoff: 1min, 5min, 30min, 2h, then every 2h.
 * Hard limit: 24h, or an exhausted `tries` budget — whichever comes first. Either
 * way an admin notification is sent and the `pending_subscription_patch` marker is
 * dropped so SweepPendingRetriesJob stops re-queueing the same PATCH.
 *
 * Payload: the marker's inner `intent` (PlanChangeIntent::toArray()), or the flat
 * `reason = recurring_coupon_expiry_reset` shape SubscriptionPaymentHandler writes.
 * Both are dispatched by SweepPendingRetriesJob, which injects billable_type /
 * billable_id where the marker itself carries none.
 *
 * Self-healing property: the recurring webhook uses live billable state, so a
 * lingering Mollie subscription amount is a display issue for the customer's Mollie
 * dashboard — but it is the amount Mollie actually collects, so leaving it unpatched
 * means charging the old price every period.
 */
class RetrySubscriptionPatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * Matches the 24h hard limit: the hourly sweep must not be able to start a
     * second chain while the first one is still working through its backoff.
     */
    public int $uniqueFor = 86400;

    public int $tries = 30;

    public function __construct(
        public readonly array $intentData,
        public readonly string $firstAttemptAt,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return ($this->intentData['billable_type'] ?? '').':'.($this->intentData['billable_id'] ?? '').':patch';
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 7200, 7200];
    }

    public function handle(MollieSubscriptionPatcher $patcher, ?CouponService $coupons = null): void
    {
        $firstAttempt = \Carbon\Carbon::parse((string) $this->firstAttemptAt)->setTimezone('UTC');
        if ($firstAttempt->diffInHours(BillingTime::nowUtc()) >= 24) {
            $this->giveUp();

            return;
        }

        if (($this->intentData['reason'] ?? null) === 'recurring_coupon_expiry_reset') {
            $this->resetRecurringAmount($patcher, $coupons);
        } else {
            $intent = PlanChangeIntent::fromArray($this->intentData);
            $patcher->updateForIntent($intent->billable, $intent);
        }

        // Only reached when the PATCH went through — clearing the marker here is
        // what stops SweepPendingRetriesJob from queueing this PATCH again.
        $this->clearMarker();
    }

    /**
     * Reached when `tries` runs out before the 24h window does. Without this the
     * marker would survive and every later sweep would restart the whole ladder.
     */
    public function failed(?Throwable $exception): void
    {
        $this->giveUp();
    }

    /**
     * The coupon-expiry variant carries no PlanChangeIntent — the subscription is
     * not changing, its amount just has to go back to the undiscounted price.
     */
    private function resetRecurringAmount(MollieSubscriptionPatcher $patcher, ?CouponService $coupons): void
    {
        $billable = $this->resolveBillable();

        if ($billable === null) {
            return;
        }

        $patcher->updateRecurringAmount(
            billable: $billable,
            planCode: (string) ($this->intentData['plan_code'] ?? ''),
            interval: (string) ($this->intentData['interval'] ?? ''),
            addons: (array) ($this->intentData['addons'] ?? []),
            extraSeats: (int) ($this->intentData['extra_seats'] ?? 0),
            intervalChanged: false,
            couponDiscountNet: 0,
        );

        ($coupons ?? app(CouponService::class))->clearActiveRecurringCouponMarker($billable);
    }

    private function giveUp(): void
    {
        $this->clearMarker();
        $this->notifyAdminOfDeadLetter();
    }

    private function clearMarker(): void
    {
        $billable = $this->resolveBillable();

        if (! $billable instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();

        if (! array_key_exists('pending_subscription_patch', $meta)) {
            return;
        }

        unset($meta['pending_subscription_patch']);
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    private function resolveBillable(): ?\GraystackIT\MollieBilling\Contracts\Billable
    {
        $class = (string) ($this->intentData['billable_type'] ?? '');
        $id = $this->intentData['billable_id'] ?? null;

        if ($class === '' || ! class_exists($class) || $id === null) {
            return null;
        }

        // Scope-free like the sibling retry jobs: a queue worker has no request or
        // tenant context for an app-defined applyBillingScope() to key off.
        $billable = $class::withoutGlobalScope(BillingScope::class)->find($id);

        return $billable instanceof \GraystackIT\MollieBilling\Contracts\Billable ? $billable : null;
    }

    private function notifyAdminOfDeadLetter(): void
    {
        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Mollie subscription PATCH kept failing (retry budget exhausted)',
                $this->intentData,
            ),
        );
    }
}

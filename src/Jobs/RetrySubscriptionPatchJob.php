<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Retry for Mollie subscription PATCH (for future recurring periods).
 *
 * Backoff: 1min, 5min, 30min, 2h, then every 2h.
 * Hard limit: 24h, or an exhausted `tries` budget — whichever comes first. Either
 * way an admin notification is sent and the `pending_subscription_patch` marker is
 * dropped so SweepPendingRetriesJob stops re-queueing the same PATCH — but only when
 * the marker still describes the intent this chain ran (see clearMarker()).
 *
 * Payload: the marker's inner `intent` (PlanChangeIntent::toArray()), or the flat
 * `reason = recurring_coupon_expiry_reset` shape SubscriptionPaymentHandler writes.
 * Both are dispatched by SweepPendingRetriesJob, which injects billable_type /
 * billable_id where the marker itself carries none. UpdateSubscription and
 * ProrataExecutor dispatch the intent shape directly as well.
 *
 * Unique per (billable, intent): two pending PATCHes for one billable are two
 * different target amounts, so they must not collapse into one chain.
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
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * Must outlive the whole ladder, not just the 24h hard limit: the limit only
     * fires on the first attempt *after* 24h, and with `tries = 30` the backoff below
     * spans ~52h before `failed()` would end the chain. A `uniqueFor` equal to the
     * limit expired while the chain was still backing off, so the hourly sweep found
     * the marker unlocked and started a second chain — which immediately hit the limit,
     * cleared the marker and mailed the admins, and then the original chain mailed
     * them again.
     */
    public int $uniqueFor = 259200;

    public int $tries = 30;

    public function __construct(
        public readonly array $intentData,
        public readonly string $firstAttemptAt,
    ) {
        $this->initializeBillingQueue();
    }

    /**
     * Keyed on the intent as well as the billable. Without the intent, a second plan
     * change during a Mollie outage collapsed into the first: `queuePatchRetry()`
     * overwrote the marker with intent B while chain A still held the unique lock, so
     * B's dispatch was silently dropped, A later PATCHed Mollie to *A's* amount and
     * then cleared B's marker — leaving Mollie billing the stale price every period
     * with nothing left for the sweep to find.
     */
    public function uniqueId(): string
    {
        return ($this->intentData['billable_type'] ?? '')
            .':'.($this->intentData['billable_id'] ?? '')
            .':patch:'.self::intentSignature($this->intentData);
    }

    /**
     * Identity of the target state this job PATCHes towards.
     *
     * Billable identity is already in the uniqueId prefix, and the sweep injects it
     * into the flat coupon-expiry payload that the marker itself does not carry — so
     * both are excluded to keep the signature comparable between the marker on the row
     * and the payload in flight. `first_attempt_at` / `last_error` are excluded for the
     * same reason: they describe the failure, not the intent, and a re-failure of the
     * *same* intent must keep addressing the same chain.
     *
     * @param  array<string, mixed>  $data
     */
    private static function intentSignature(array $data): string
    {
        unset(
            $data['billable_type'],
            $data['billable_id'],
            $data['first_attempt_at'],
            $data['last_error'],
        );

        return substr(hash('sha256', (string) json_encode(self::normalize($data))), 0, 16);
    }

    /**
     * Key order survives the JSON round-trip through `subscription_meta`, but the
     * marker and the dispatched payload are built by different call sites — sorting
     * recursively makes the signature depend on content only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            }
        }

        return $data;
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

        $signature = self::intentSignature($this->intentData);

        $this->mutateBillingMeta($billable, function (array $meta) use ($signature): ?array {
            $marker = $meta['pending_subscription_patch'] ?? null;

            if (! is_array($marker)) {
                return null;
            }

            // Only ever clear the marker this job actually ran. A newer plan change may
            // have overwritten it while this chain was backing off, and dropping *that*
            // intent would leave Mollie charging the newer plan's predecessor price with
            // no marker left for the sweep to retry.
            $payload = is_array($marker['intent'] ?? null) ? $marker['intent'] : $marker;

            if (self::intentSignature($payload) !== $signature) {
                Log::info('RetrySubscriptionPatchJob: marker belongs to a newer intent, leaving it for its own chain', [
                    'billable_id' => $this->intentData['billable_id'] ?? null,
                ]);

                return null;
            }

            unset($meta['pending_subscription_patch']);

            return $meta;
        });
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

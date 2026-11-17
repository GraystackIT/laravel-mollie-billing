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
use Illuminate\Database\Eloquent\Model;
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

        try {
            $this->attempt($patcher, $coupons);
        } catch (Throwable $e) {
            // On `sync` a throw is final: `failed()` would drop the marker on the first
            // Mollie error and mail the admins, leaving Mollie on the old amount. The
            // marker stays instead, and SweepPendingRetriesJob runs the PATCH again every
            // hour until the 24-hour window above gives up.
            if (! \GraystackIT\MollieBilling\Support\BillingQueue::isSyncRun($this->job)) {
                throw $e;
            }

            $this->keepMarkerForSweep($e);

            Log::warning('RetrySubscriptionPatchJob: PATCH failed again — left for the hourly sweep (sync queue)', [
                'billable_id' => $this->intentData['billable_id'] ?? null,
                'first_attempt_at' => $this->firstAttemptAt,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attempt(MollieSubscriptionPatcher $patcher, ?CouponService $coupons): void
    {
        if (($this->intentData['reason'] ?? null) === 'recurring_coupon_expiry_reset') {
            $this->resetRecurringAmount($patcher, $coupons);
        } elseif (($this->intentData['reason'] ?? null) === 'next_charge_date') {
            $this->applyNextChargeDate($patcher);
        } elseif (($this->intentData['reason'] ?? null) === 'anchor_pin') {
            $this->applyAnchorPin($patcher);
        } elseif (($this->intentData['reason'] ?? null) === 'plan_waiver_sync') {
            $this->syncPlanWaiver();
        } else {
            $intent = PlanChangeIntent::fromArray($this->intentData);

            // The local state is authoritative, and this chain backs off for up to 52
            // hours. A newer plan change can land in that window and PATCH Mollie
            // successfully — nothing clears this marker or stops this chain — and
            // `updateForIntent()` prices from the STORED intent, not from live state. So
            // the retry PATCHed Mollie back to the superseded plan and then cleared its
            // own marker because the signature still matched: local plan C, Mollie
            // collecting B's price every period, nothing left for the sweep to find. The
            // renewal handler reconciles each invoice down to whatever cleared, so it
            // never self-healed either.
            if (! $this->intentIsStillCurrent($intent)) {
                Log::info('Skipping a superseded subscription PATCH retry — the billable has moved on', [
                    'billable_id' => $intent->billable instanceof Model ? $intent->billable->getKey() : null,
                    'intent_plan' => $intent->newPlan,
                    'intent_interval' => $intent->newInterval,
                ]);

                $this->clearMarker();

                return;
            }

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
     * Whether the stored intent still describes where the billable actually is.
     *
     * Compared against the live row, because that is what the customer sees and pays
     * for. Seats are read from the same accessor the patcher would use, and addons
     * compared as sets — order is not meaningful.
     */
    private function intentIsStillCurrent(PlanChangeIntent $intent): bool
    {
        $billable = $intent->billable;

        if (! ($billable instanceof Model)) {
            return true;
        }

        $billable->refresh();

        if ((string) ($billable->getBillingSubscriptionPlanCode() ?? '') !== $intent->newPlan) {
            return false;
        }

        if ((string) ($billable->getBillingSubscriptionInterval() ?? '') !== $intent->newInterval) {
            return false;
        }

        // Only when the row states a seat count explicitly. `getBillingSeatCount()`
        // falls back to the plan's `included_seats`, so a billable that never bought an
        // extra seat reports the plan default — which an intent built before a plan
        // change legitimately does not carry. Treating that as "superseded" would skip a
        // retry that should run, leaving Mollie on the OLD amount: the exact harm this
        // guard exists to prevent, inverted.
        $storedSeats = $billable->getBillingSubscriptionMeta()['seat_count'] ?? null;

        if ($storedSeats !== null && (int) $storedSeats !== $intent->newSeats) {
            return false;
        }

        $live = $billable->getActiveBillingAddonCodes();
        $wanted = array_keys($intent->newAddons);
        sort($live);
        sort($wanted);

        return $live === $wanted;
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

        $coupons ??= app(CouponService::class);

        // A later discount is not this reset's to end. The marker names the coupon whose
        // expiry it was written for (older markers do not, and are judged as before); a
        // live marker for a different coupon means a newer one was applied in the
        // meantime, and PATCHing to full price would take it away.
        $expiredCouponId = $this->intentData['coupon_id'] ?? null;
        $liveMarker = $coupons->getActiveRecurringCouponMarker($billable);

        if ($expiredCouponId !== null
            && $liveMarker !== null
            && (int) ($liveMarker['coupon_id'] ?? 0) !== (int) $expiredCouponId) {
            Log::info('Skipping a superseded coupon-expiry reset — another recurring coupon is active', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'expired_coupon_id' => $expiredCouponId,
                'live_coupon_id' => $liveMarker['coupon_id'] ?? null,
            ]);

            return;
        }

        // Priced from the live subscription, not from the configuration captured when the
        // reset first failed. This chain backs off for up to a day, a plan change can land
        // in that window, and the captured plan, seats and addons then PATCHed Mollie back
        // to the old (cheaper) price while the customer kept the new entitlements — the
        // same harm intentIsStillCurrent() guards against on the plan-change branch.
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if ($planCode === '' || $interval === '') {
            return;
        }

        $patcher->updateRecurringAmount(
            billable: $billable,
            planCode: $planCode,
            interval: $interval,
            addons: $billable->getActiveBillingAddonCodes(),
            extraSeats: $billable->getExtraBillingSeats(),
            intervalChanged: false,
            couponDiscountNet: 0,
        );

        $coupons->clearActiveRecurringCouponMarker($billable);
    }

    /**
     * The extension variant: a coupon (or an admin's trial extension) moved the next
     * charge and the PATCH did not land.
     *
     * It carries an absolute `target`, not a number of days, and that is what makes it
     * retryable at all: `pushNextChargeDate()` reads the current next-charge date and
     * adds to it, so re-running it after a partial success would extend a second time.
     * The local write already happened — the customer is being shown the later date —
     * so all this has to do is make Mollie agree, and setting the same absolute date
     * twice is harmless.
     */
    private function applyNextChargeDate(MollieSubscriptionPatcher $patcher): void
    {
        $billable = $this->resolveBillable();
        $target = (string) ($this->intentData['target'] ?? '');

        if ($billable === null || $target === '') {
            return;
        }

        $patcher->setNextChargeDate($billable, \Carbon\Carbon::parse($target)->setTimezone('UTC'));
    }

    /**
     * The renewal could not keep the next charge on the anchor day
     * (SubscriptionPaymentHandler::pinNextChargeOnAnchor()). Sent again until the day has
     * come; after that Mollie has charged on its own date and the renewal of that charge
     * sets the anchor anew, so there is nothing left to pin.
     */
    private function applyAnchorPin(MollieSubscriptionPatcher $patcher): void
    {
        $billable = $this->resolveBillable();
        $target = (string) ($this->intentData['target'] ?? '');

        if ($billable === null || $target === '') {
            return;
        }

        $day = \Carbon\Carbon::parse($target)->setTimezone('UTC')->startOfDay();

        if (! $day->greaterThan(BillingTime::nowUtc()->setTimezone('UTC')->startOfDay())) {
            return;
        }

        $patcher->pinNextChargeDate($billable, $day);
    }

    /**
     * A plan waiver was given, extended or taken away and the PATCH that follows did not
     * land. Nothing is carried in the payload: the billable is priced as it stands now —
     * waiver or none — so a retry can neither undo a gift nor resurrect a revoked one.
     */
    private function syncPlanWaiver(): void
    {
        $billable = $this->resolveBillable();

        if ($billable === null) {
            return;
        }

        app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)->syncMollie($billable);
    }

    private function giveUp(): void
    {
        $this->clearMarker();
        $this->notifyAdminOfDeadLetter();
    }

    /**
     * The marker the hourly sweep reads, kept for this intent with the first failure's
     * time — so the 24-hour window counts from there, not from each new attempt. A marker
     * that already describes a newer intent is that intent's chain, and left alone.
     */
    private function keepMarkerForSweep(Throwable $e): void
    {
        $billable = $this->resolveBillable();

        if (! $billable instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $signature = self::intentSignature($this->intentData);
        $firstAttemptAt = $this->firstAttemptAt;
        $intentData = $this->intentData;

        $this->mutateBillingMeta($billable, function (array $meta) use ($signature, $firstAttemptAt, $intentData, $e): ?array {
            $marker = $meta['pending_subscription_patch'] ?? null;

            if (is_array($marker) && $marker !== []) {
                $payload = is_array($marker['intent'] ?? null) ? $marker['intent'] : $marker;

                if (self::intentSignature($payload) !== $signature) {
                    return null;
                }
            } else {
                // Cleared under this chain's feet: written again, in the shape it came in.
                $marker = isset($intentData['reason']) ? $intentData : ['intent' => $intentData];
            }

            $marker['first_attempt_at'] = $firstAttemptAt;
            $marker['last_error'] = $e->getMessage();
            $meta['pending_subscription_patch'] = $marker;

            return $meta;
        });
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

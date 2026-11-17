<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReferenceCleared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Read-side companion to MollieSubscriptionPatcher: looks up the live state of
 * a billable's Mollie subscription so callers can decide whether to (a) gate
 * the checkout flow when a Mollie sub is still active despite local PastDue
 * status, or (b) surface the upcoming-charge date on the dashboard.
 *
 * A stale `mollie_subscription_id` (Mollie says canceled/completed/suspended
 * or returns 404) is removed from `subscription_meta` so the next checkout
 * attempt cleanly creates a new subscription.
 */
class MollieSubscriptionGate
{
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Snapshot of a Mollie subscription as we care about it.
     *
     * @param  MollieSubscriptionStatus  $status  Mollie's current status.
     * @param  ?CarbonInterface  $startDate  Next/initial billing date as reported by Mollie (UTC).
     */
    public function snapshot(Billable $billable): ?MollieSubscriptionSnapshot
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            return null;
        }

        $cacheKey = self::cacheKey($subscriptionId);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof MollieSubscriptionSnapshot) {
            return $cached;
        }

        try {
            $subscription = MollieCall::send(new GetSubscriptionRequest($customerId, $subscriptionId));
        } catch (ApiException $e) {
            // 410 as well as 404, because they are different answers to the same question
            // and both are definitive. Mollie says 404 for an id that never existed and
            // **410 Gone** for one that did and was removed — which is what a lookup returns
            // once the customer has been deleted at Mollie, whether by an operator tidying
            // the dashboard or by a GDPR erasure. Treating only 404 as final left the stale
            // id on the billable forever while `hasLiveMollieSubscription()` answered false:
            // unreachable, un-cancellable, and unable to start a replacement — the exact
            // state this gate exists to prevent, reached from the other direction.
            if (in_array($e->getCode(), [404, 410], true)) {
                $this->clearStaleId($billable, $e->getCode() === 410 ? 'gone' : 'not_found');
                return null;
            }

            Log::warning('MollieSubscriptionGate lookup failed', [
                'billable' => $billable->getKey(),
                'mollie_subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('MollieSubscriptionGate lookup failed (non-API)', [
                'billable' => $billable->getKey(),
                'mollie_subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $status = MollieSubscriptionStatus::tryFrom((string) ($subscription->status ?? ''));
        if ($status === null) {
            return null;
        }

        // Only the two terminal statuses drop the id. `suspended` is NOT one of them:
        // Mollie has merely paused charging after repeated failures and hands the
        // subscription straight back from `GetSubscriptionRequest` — it resumes on its own
        // once a usable mandate exists again. Forgetting its id made it unreachable
        // forever: nothing could cancel or PATCH it, the documented past-due recovery had
        // no target, and `hasLiveMollieSubscription()` answering false let the customer
        // back into checkout, where `CreateSubscriptionRequest` 422s with "same description
        // already exists" — the very collision that guard exists to prevent.
        if (in_array($status, [
            MollieSubscriptionStatus::Canceled,
            MollieSubscriptionStatus::Completed,
        ], true)) {
            $this->clearStaleId($billable, $status->value);
            return null;
        }

        $startDate = isset($subscription->startDate) && $subscription->startDate !== null
            ? CarbonImmutable::parse((string) $subscription->startDate, 'UTC')
            : null;

        $snapshot = new MollieSubscriptionSnapshot(
            id: $subscriptionId,
            status: $status,
            startDate: $startDate,
        );

        Cache::put($cacheKey, $snapshot, self::CACHE_TTL_SECONDS);

        return $snapshot;
    }

    /**
     * True when the subscription still exists at Mollie under its current description.
     *
     * Use this to gate the checkout entry: a subscription Mollie still holds means a new
     * `CreateSubscriptionRequest` would 422 with "same description already exists".
     * `suspended` counts — Mollie kept the subscription and will resume it once a usable
     * mandate exists, so a second one would collide exactly like an active one.
     */
    public function hasLiveMollieSubscription(Billable $billable): bool
    {
        // Between the cancel and the create of a replacement (MollieSubscriptionReplacer)
        // the old id is cancelled and the new one not there yet. The subscription is
        // live in every sense that matters here: a checkout now would start a second one.
        if (MollieSubscriptionReplacer::pending($billable)) {
            return true;
        }

        $snapshot = $this->snapshot($billable);

        return $snapshot !== null && in_array($snapshot->status, [
            MollieSubscriptionStatus::Active,
            MollieSubscriptionStatus::Pending,
            MollieSubscriptionStatus::Suspended,
        ], true);
    }

    /**
     * Move the Mollie subscription's next charge to today, then bust the cache.
     * Used by the dashboard's "charge now" button when the user wants to skip
     * the wait until Mollie's originally scheduled startDate.
     */
    public function forceImmediateCharge(Billable $billable): bool
    {
        if (! $billable instanceof Model) {
            return false;
        }

        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $subscriptionId === '') {
            return false;
        }

        try {
            MollieCall::send(new MollieUpdateSubscriptionRequest(
                customerId: $customerId,
                subscriptionId: $subscriptionId,
                startDate: new Date(CarbonImmutable::now('UTC')),
            ));
        } catch (\Throwable $e) {
            Log::warning('MollieSubscriptionGate forceImmediateCharge failed', [
                'billable' => $billable->getKey(),
                'mollie_subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        Cache::forget(self::cacheKey($subscriptionId));

        return true;
    }

    private function clearStaleId(Billable $billable, string $reason): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $subscriptionId = $meta['mollie_subscription_id'] ?? null;
        if ($subscriptionId === null) {
            return;
        }

        // Cancelled by a replacement that has not created its successor yet: the id is
        // the replacer's to swap, and dropping it would announce a lost subscription.
        if (MollieSubscriptionReplacer::pending($billable)) {
            return;
        }

        unset($meta['mollie_subscription_id']);
        $billable->forceFill(['subscription_meta' => $meta])->save();

        Cache::forget(self::cacheKey((string) $subscriptionId));

        Log::info('MollieSubscriptionGate cleared stale mollie_subscription_id', [
            'billable' => $billable->getKey(),
            'mollie_subscription_id' => $subscriptionId,
            'reason' => $reason,
        ]);

        // A log line is not the extension seam. The billable just lost the reference every
        // later cancel, patch and renewal needs, and only an event lets an app notice —
        // `hasLiveMollieSubscription()` answering false afterwards looks exactly like a
        // billable that never had one.
        event(new MollieSubscriptionReferenceCleared($billable, (string) $subscriptionId, $reason));
    }

    /** Drop the cached snapshot of a subscription whose state has just changed. */
    public static function forget(string $subscriptionId): void
    {
        Cache::forget(self::cacheKey($subscriptionId));
    }

    private static function cacheKey(string $subscriptionId): string
    {
        return 'mollie_billing.sub_snapshot.'.$subscriptionId;
    }
}

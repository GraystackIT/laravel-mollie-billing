<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

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
            $subscription = Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId));
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                $this->clearStaleId($billable, 'not_found');
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

        if (in_array($status, [
            MollieSubscriptionStatus::Canceled,
            MollieSubscriptionStatus::Completed,
            MollieSubscriptionStatus::Suspended,
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
     * True when Mollie still considers the subscription alive (active or pending).
     * Use this to gate the checkout entry: a live Mollie sub means a new
     * `CreateSubscriptionRequest` would 422 with "same description already exists".
     */
    public function hasLiveMollieSubscription(Billable $billable): bool
    {
        $snapshot = $this->snapshot($billable);

        return $snapshot !== null && in_array($snapshot->status, [
            MollieSubscriptionStatus::Active,
            MollieSubscriptionStatus::Pending,
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
            Mollie::send(new MollieUpdateSubscriptionRequest(
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

        unset($meta['mollie_subscription_id']);
        $billable->forceFill(['subscription_meta' => $meta])->save();

        Cache::forget(self::cacheKey((string) $subscriptionId));

        Log::info('MollieSubscriptionGate cleared stale mollie_subscription_id', [
            'billable' => $billable->getKey(),
            'mollie_subscription_id' => $subscriptionId,
            'reason' => $reason,
        ]);
    }

    private static function cacheKey(string $subscriptionId): string
    {
        return 'mollie_billing.sub_snapshot.'.$subscriptionId;
    }
}

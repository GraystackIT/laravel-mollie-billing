<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReplaced;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionReplacementJob;
use GraystackIT\MollieBilling\Support\BillingQueue;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;

/**
 * Move the next charge of a subscription Mollie has already charged: cancel it and
 * create it again, starting on the new date.
 *
 * Mollie refuses a new `startDate` on a subscription that has been charged once ("The
 * start date cannot be changed when the subscription is already charged", 422 — measured
 * 17 November 2026, not in the reference). Amount, interval, description and metadata
 * are still accepted; the date is not. Every place that has to move the next charge of a
 * running subscription — the anchor day after a short month, a period or trial
 * extension, a fully covering code given mid-subscription, an interval change, the
 * past-due reset — therefore ends here once Mollie has said no
 * (`MollieSubscriptionPatcher::sendUpdate()`).
 *
 * **Cancel first, then create.** Measured, both orders: Mollie keeps a customer's
 * subscription descriptions unique among the ACTIVE ones, so creating the replacement
 * first would need a different description; and an interruption between create and
 * cancel leaves two live subscriptions, the old one charging on its old date — a double
 * charge. Cancelled first, an interruption leaves none: nothing is collected until the
 * replacement exists, and the marker written before the cancel (`META_KEY`, the complete
 * specification) lets `RetrySubscriptionReplacementJob` and the hourly sweep finish it.
 * The uniqueness of descriptions then works for us: once the old one is cancelled, at most
 * one subscription with this description can exist, so a create whose answer was lost and
 * that is sent again is refused ("same description already exists") instead of creating
 * a second — and the retry adopts the one that is there. The create also carries an
 * idempotency key stable across retries of this one replacement.
 *
 * **Never a start date of today or earlier.** A subscription created with today's date is
 * charged today (Mollie moves `nextPaymentDate` one interval on the moment it is created),
 * and the old subscription has already collected for the cycle that is running. A target
 * that has passed by the time a retry runs is moved to tomorrow.
 *
 * No Mollie call runs inside a transaction (`MollieCall`); the marker and the new id are
 * written in short ones of their own. Serialised per billable by `withLock()`, which the
 * patcher holds around every subscription request it sends.
 */
class MollieSubscriptionReplacer
{
    use MutatesBillingMeta;

    /** The replacement in flight: everything needed to finish it, written before the cancel. */
    public const META_KEY = 'pending_subscription_replacement';

    private const LOCK_SECONDS = 120;

    private const LOCK_WAIT_SECONDS = 15;

    /**
     * Run `$callback` holding the billable's subscription lock. Every request that targets
     * the billable's Mollie subscription by its id goes through here, so a PATCH can never
     * be sent to an id a replacement is cancelling under it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withLock(Model&Billable $billable, callable $callback): mixed
    {
        $name = 'billing:mollie-subscription:'.$billable->getMorphClass().':'.$billable->getKey();

        return Cache::lock($name, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    /**
     * The subscription id as stored, not as the caller's instance last saw it: another
     * process may have replaced the subscription since that instance was loaded.
     */
    public static function storedSubscriptionId(Model&Billable $billable): string
    {
        $id = (string) (self::fresh($billable)?->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        return $id !== '' ? $id : (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
    }

    /** Whether a replacement has been started and not finished. */
    public static function pending(Billable $billable): bool
    {
        return is_array($billable->getBillingSubscriptionMeta()[self::META_KEY] ?? null);
    }

    /**
     * Replace the billable's Mollie subscription by one first charged on `$startDate`.
     *
     * Throws while nothing has changed at Mollie (the lookup of the old subscription
     * failed, or it no longer runs) — the caller's own failure path applies. Once the
     * marker is written it never throws: a failure from there on is the retry job's to
     * finish, and the caller's change stands, because the marker carries it.
     *
     * Call it holding `withLock()`.
     *
     * @param  array{amount?: Money, interval?: string, description?: string, metadata?: array<string, mixed>}  $changes
     * @return ?string the new subscription id; null when it was left to the retry
     */
    public function replace(Model&Billable $billable, CarbonInterface $startDate, array $changes, string $reason): ?string
    {
        $customerId = (string) ($billable->getMollieCustomerId() ?? '');
        $oldId = self::storedSubscriptionId($billable);

        if ($customerId === '' || $oldId === '') {
            throw new \RuntimeException('Cannot replace a Mollie subscription the billable does not reference.');
        }

        $old = MollieCall::send(new GetSubscriptionRequest($customerId, $oldId));

        if (! in_array((string) ($old->status ?? ''), ['active', 'pending', 'suspended'], true)) {
            throw new \RuntimeException("Mollie subscription {$oldId} is {$old->status}; there is nothing to move.");
        }

        $amount = $changes['amount'] ?? new Money((string) $old->amount->currency, (string) $old->amount->value);
        $metadata = $changes['metadata'] ?? self::toArray($old->metadata ?? []);
        $mandateId = (string) ($old->mandateId ?? '') !== '' ? (string) $old->mandateId : (string) ($billable->getMollieMandateId() ?? '');
        $webhookUrl = (string) ($old->webhookUrl ?? '') !== '' ? (string) $old->webhookUrl : route(BillingRoute::webhook());

        $marker = [
            'reason' => $reason,
            'mollie_customer_id' => $customerId,
            'old_subscription_id' => $oldId,
            'old_cancelled' => false,
            'start_date' => Carbon::instance($startDate)->setTimezone('UTC')->toDateString(),
            'amount' => ['currency' => $amount->currency, 'value' => $amount->value],
            'interval' => (string) ($changes['interval'] ?? $old->interval),
            'description' => (string) ($changes['description'] ?? $old->description),
            'metadata' => $metadata,
            'mandate_id' => $mandateId !== '' ? $mandateId : null,
            'webhook_url' => $webhookUrl,
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
        ];

        $this->mutateBillingMeta($billable, static function (array $meta) use ($marker): array {
            $meta[self::META_KEY] = $marker;

            return $meta;
        });

        try {
            return $this->finish($billable);
        } catch (\Throwable $e) {
            $this->leaveForRetry($billable, $e);

            return null;
        }
    }

    /**
     * Finish a replacement that was started and not completed. Throws when Mollie is
     * still not answering — the marker stays for the next attempt.
     *
     * Call it holding `withLock()`.
     *
     * @return ?string the subscription id the billable now carries
     */
    public function resume(Model&Billable $billable): ?string
    {
        if (! is_array(self::fresh($billable)?->getBillingSubscriptionMeta()[self::META_KEY] ?? null)) {
            return $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null;
        }

        return $this->finish($billable);
    }

    /**
     * The row as it is stored, read without touching the caller's instance: callers hand
     * in billables that may carry attributes they have not saved yet.
     */
    private static function fresh(Model&Billable $billable): (Model&Billable)|null
    {
        $row = $billable->newQuery()
            ->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
            ->whereKey($billable->getKey())
            ->first();

        return $row instanceof Billable ? $row : null;
    }

    private function finish(Model&Billable $billable): ?string
    {
        $stored = self::fresh($billable);
        $marker = $stored?->getBillingSubscriptionMeta()[self::META_KEY] ?? null;

        if ($stored === null || ! is_array($marker)) {
            return $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null;
        }

        $customerId = (string) $marker['mollie_customer_id'];
        $oldId = (string) $marker['old_subscription_id'];

        // The customer cancelled while the replacement waited: there is nothing to replace
        // any more. CancelSubscription cancelled the id the billable carried, which is the
        // old one, so nothing is left running.
        if (! self::billableState($stored)) {
            $this->dropMarker($billable);

            Log::info('Mollie subscription replacement abandoned — the subscription no longer runs', [
                'billable_id' => $billable->getKey(),
                'old_subscription_id' => $oldId,
                'status' => $stored->getBillingSubscriptionStatus()->value,
            ]);

            return null;
        }

        if (($marker['old_cancelled'] ?? false) !== true) {
            try {
                MollieCall::send(new CancelSubscriptionRequest($customerId, $oldId));
            } catch (\Throwable $e) {
                if (! MollieSubscriptionAnswer::cancelledAlready($e)) {
                    throw $e;
                }
            }

            $this->mutateBillingMeta($billable, static function (array $meta): ?array {
                if (! is_array($meta[self::META_KEY] ?? null)) {
                    return null;
                }

                $meta[self::META_KEY]['old_cancelled'] = true;

                return $meta;
            });
        }

        // Never today or earlier: see the class docblock.
        $tomorrow = BillingTime::nowUtc()->setTimezone('UTC')->startOfDay()->addDay();
        $start = Carbon::parse((string) $marker['start_date'], 'UTC')->startOfDay();
        $start = $start->lessThan($tomorrow) ? $tomorrow : $start;

        $amount = new Money((string) $marker['amount']['currency'], (string) $marker['amount']['value']);
        $key = 'subscription-replace:'.$oldId.':'.$start->toDateString().':'.$amount->value.':'.$marker['interval'];

        try {
            $created = MollieCall::send(new CreateSubscriptionRequest(
                customerId: $customerId,
                amount: $amount,
                interval: (string) $marker['interval'],
                description: (string) $marker['description'],
                startDate: new Date($start),
                metadata: (array) $marker['metadata'],
                webhookUrl: (string) $marker['webhook_url'],
                mandateId: $marker['mandate_id'] !== null ? (string) $marker['mandate_id'] : null,
            ), $key);
        } catch (\Throwable $e) {
            if (! MollieSubscriptionAnswer::sameDescriptionExists($e)) {
                throw $e;
            }

            $created = $this->replacementCreatedBefore($billable, $customerId, $oldId, (string) $marker['description'])
                ?? throw $e;
        }

        $newId = (string) $created->id;

        $this->mutateBillingMeta($billable, static function (array $meta) use ($newId): array {
            $meta['mollie_subscription_id'] = $newId;
            unset($meta[self::META_KEY]);

            return $meta;
        });

        MollieSubscriptionGate::forget($oldId);
        MollieSubscriptionGate::forget($newId);

        Log::info('Mollie subscription replaced to move its next charge', [
            'billable_id' => $billable->getKey(),
            'old_subscription_id' => $oldId,
            'new_subscription_id' => $newId,
            'start_date' => $start->toDateString(),
            'reason' => $marker['reason'] ?? null,
        ]);

        event(new MollieSubscriptionReplaced($billable, $oldId, $newId, $start, (string) ($marker['reason'] ?? '')));

        // Cancelled in the meantime, by a path that knew only the old id: the new one has
        // to go as well, or it would charge a customer who has left.
        if (! self::billableState(self::fresh($billable) ?? $stored)) {
            app(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class)
                ->cancelMollieSubscriptionById($billable, $customerId, $newId);
        }

        return $newId;
    }

    /** A replacement an earlier attempt created and whose answer was lost. */
    private function replacementCreatedBefore(Model&Billable $billable, string $customerId, string $oldId, string $description): ?object
    {
        foreach (MollieCall::send(new GetPaginatedSubscriptionsRequest($customerId)) as $subscription) {
            $metadata = self::toArray($subscription->metadata ?? []);

            if ((string) ($subscription->id ?? '') !== $oldId
                && in_array((string) ($subscription->status ?? ''), ['active', 'pending'], true)
                && (string) ($subscription->description ?? '') === $description
                && (string) ($metadata['billable_id'] ?? '') === (string) $billable->getKey()
            ) {
                return $subscription;
            }
        }

        return null;
    }

    private function leaveForRetry(Model&Billable $billable, \Throwable $e): void
    {
        Log::warning('Mollie subscription replacement did not finish — left for the retry', [
            'billable_id' => $billable->getKey(),
            'error' => $e->getMessage(),
        ]);

        $this->mutateBillingMeta($billable, static function (array $meta) use ($e): ?array {
            if (! is_array($meta[self::META_KEY] ?? null)) {
                return null;
            }

            $meta[self::META_KEY]['last_error'] = mb_substr($e->getMessage(), 0, 500);

            return $meta;
        });

        // On `sync` the hourly sweep is the ladder; dispatching would run the retry inside
        // this very call.
        if (! BillingQueue::runsInline()) {
            try {
                RetrySubscriptionReplacementJob::dispatch($billable::class, $billable->getKey())
                    ->delay(BillingTime::nowUtc()->addSeconds(RetrySubscriptionReplacementJob::FIRST_RETRY_SECONDS));
            } catch (\Throwable $dispatchError) {
                Log::warning('Could not dispatch the replacement retry — the hourly sweep picks it up', [
                    'billable_id' => $billable->getKey(),
                    'error' => $dispatchError->getMessage(),
                ]);
            }
        }
    }

    private function dropMarker(Model&Billable $billable): void
    {
        $this->mutateBillingMeta($billable, static function (array $meta): ?array {
            if (! array_key_exists(self::META_KEY, $meta)) {
                return null;
            }

            unset($meta[self::META_KEY]);

            return $meta;
        });
    }

    private static function billableState(Billable $billable): bool
    {
        return in_array($billable->getBillingSubscriptionStatus(), [
            SubscriptionStatus::Active,
            SubscriptionStatus::Trial,
            SubscriptionStatus::PastDue,
        ], true);
    }

    /** @return array<string, mixed> */
    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode((string) json_encode($value), true) ?: [];
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Mollie\Api\Exceptions\ApiException;

/**
 * Whether Mollie *definitely refused* a request — and the idempotency key the next attempt
 * of that operation carries.
 *
 * Measured against the real test API on 26 November 2026
 * (`tests/Sandbox/MollieIdempotencyContractTest`): a request Mollie refuses with a 4xx binds
 * its idempotency key to that refusal for an hour. Sent again, identical, with the same key,
 * it gets the same 4xx — even after the reason has gone (the balance topped up, the mandate
 * replaced, the competing refund booked). A fresh key is taken. So a stable key, which is
 * what keeps a retry after a *lost answer* from paying twice, also kept a retry after a
 * *refusal* from ever succeeding inside that hour.
 *
 * The rule (maintainer's decision): after a definite refusal — Mollie answered, with a 4xx,
 * so it acted on nothing — the next attempt of the same operation carries a new key: the
 * operation's stable key plus the number of refusals it has had ({@see key()}). After a
 * timeout, a 5xx or an answer that never arrived the key stays the same, because Mollie may
 * have acted and only that key lets it say so instead of acting twice.
 *
 * Every caller persists the refusal count where its own retry reads it — `subscription_meta`
 * through `MutatesBillingMeta`, or the retry marker the retry is driven by.
 */
final class MollieRefusal
{
    /**
     * True only for an answer that proves Mollie did nothing.
     *
     * A 4xx from the API, with three exceptions that do not prove it:
     *  - 408: the SDK's request timeout, not an answer about the request;
     *  - 409: a conflict — Mollie's answer to a request it may be processing under the same
     *    key, or to a duplicate of something that exists; a new key would make it a second one;
     *  - a refusal of the *key itself* ("used before with different parameters / on a
     *    different URL"): something already happened under that key, and a new key would
     *    repeat it.
     *
     * Anything that is not an `ApiException` (a network error, a read timeout, a throw of our
     * own) and every 5xx is not a refusal: the outcome is unknown.
     */
    public static function isDefinite(\Throwable $e): bool
    {
        if (! $e instanceof ApiException) {
            return false;
        }

        $status = (int) $e->getCode();

        if ($status < 400 || $status >= 500 || in_array($status, [408, 409], true)) {
            return false;
        }

        return ! str_contains(strtolower($e->getMessage()), 'idempotency key');
    }

    /**
     * The key for the next attempt: the operation's stable key, moved on by every refusal.
     *
     * Unchanged while nothing was refused, so every key sent before this rule existed — and
     * every retry of a lost answer — stays byte-identical.
     */
    public static function key(string $stableKey, int $refusals): string
    {
        return $refusals > 0 ? $stableKey.':refused-'.$refusals : $stableKey;
    }

    /**
     * The refusals recorded for `$stableKey` in a `['key' => …, 'refusals' => n]` record, or 0
     * when the record belongs to another key — the amount moved, the cycle moved on, a
     * refund booked — and so to another operation.
     */
    public static function refusalsFor(mixed $record, string $stableKey): int
    {
        if (! is_array($record) || ($record['key'] ?? null) !== $stableKey) {
            return 0;
        }

        return max(0, (int) ($record['refusals'] ?? 0));
    }

    /**
     * The record after one more refusal of `$stableKey`.
     *
     * @return array{key: string, refusals: int, at: string}
     */
    public static function recordRefusal(mixed $record, string $stableKey): array
    {
        return [
            'key' => $stableKey,
            'refusals' => self::refusalsFor($record, $stableKey) + 1,
            'at' => BillingTime::nowUtc()->toIso8601String(),
        ];
    }
}

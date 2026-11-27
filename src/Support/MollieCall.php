<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mollie\Laravel\Facades\Mollie;

/**
 * The single door every Mollie request in this package goes through.
 *
 * It exists for the invariants that are cheaper to enforce once than to review at thirty
 * call sites.
 *
 * **No Mollie call inside a database transaction.** A
 * transaction can be rolled back, an HTTP call to a payment provider cannot — so a throw
 * after the money moved undoes the local half while Mollie keeps its half. That is how a
 * customer got refunded with `refunded_net` back at 0 (and the retry refunded again), how
 * a subscription was created at Mollie while the local row was rolled back (and the
 * re-delivery created a second one, charging every period twice), and how a collected
 * payment lost the snapshot that was the only thing able to reconcile it.
 *
 * CLAUDE.md states the rule; every service that touches money is written in the
 * decide → call Mollie → write shape to honour it. This class is what makes the rule
 * *enforced* rather than merely documented: reviewing 30 call sites against every caller
 * that might open a transaction is exactly the kind of check that silently rots, and it
 * did — the addon services and the plan-change phase-3 body both drifted back.
 *
 * **Every amount is written with its currency's own number of decimals.** Mollie reads
 * the number as written, so a hand-built `"10.5"` is a wrong charge rather than a rejected
 * one, and `"500.00"` for a zero-decimal currency like JPY is off by a factor of a hundred
 * in a request the API happily accepts. `BillingMoney::mollieAmount()` takes the scale from
 * the currency; anything else — `number_format($cents / 100, 2)`, a `(string)` cast, a
 * float that dropped its trailing zero — does not.
 *
 * Production behaviour is deliberately non-fatal: a warning naming the request class,
 * because refusing a payment the customer is waiting for is worse than the risk being
 * warned about. Tests and local development throw, so a regression fails loudly where
 * someone can fix it.
 */
final class MollieCall
{
    /**
     * Transaction depth that counts as "no transaction open".
     *
     * 0 in production. The test suite wraps every case in a transaction of its own
     * (`RefreshDatabase`), so the harness raises this to that depth in `setUp()` —
     * see `MollieCall::useCurrentTransactionLevelAsBaseline()`.
     */
    private static int $baselineTransactionLevel = 0;

    /**
     * Send a request to Mollie.
     *
     * @template T
     *
     * @param  object  $request  a typed Mollie request object
     * @param  string|null  $idempotencyKey  a key that is *stable across retries of this same
     *                                       operation*. Mollie replays the original response
     *                                       for a repeated key instead of acting twice — for
     *                                       one hour, and only when the parameters are
     *                                       identical (a mismatch is a 400). See
     *                                       {@see ChargeUsageOverageDirectly} for the one
     *                                       caller that has such a key, and why. A 4xx binds
     *                                       the key to that refusal for the hour as well, so
     *                                       after a definite refusal the next attempt moves
     *                                       the key on — {@see MollieRefusal}.
     */
    public static function send(object $request, ?string $idempotencyKey = null): mixed
    {
        self::assertSendable($request);

        if ($idempotencyKey === null) {
            return Mollie::send($request);
        }

        // The SDK already puts an idempotency key on every POST — a *random* one from
        // `DefaultIdempotencyKeyGenerator`, which guards a duplicated send of one request
        // object and nothing else: a retry generates a new key and Mollie sees a new payment.
        // A caller-supplied key supersedes the generator (`ApplyIdempotencyKey` reads
        // `$connector->getIdempotencyKey()` first), and it has to reach the connector rather
        // than the request's own headers — the same middleware overwrites that header whenever
        // a generator is configured, which it always is.
        Mollie::setIdempotencyKey($idempotencyKey);

        try {
            return Mollie::send($request);
        } finally {
            // Whatever happened. The SDK clears the key from a *response* middleware, and a
            // throw — which is the very case a stable key exists for — never reaches it, so a
            // failed call left its key on the shared client and the next mutating request
            // through it carried it: either answered with this operation's cached response, or
            // rejected with a 400 for reusing a key with different parameters.
            self::forgetIdempotencyKey();
        }
    }

    /**
     * The checks `send()` makes before anything goes out — no open transaction, every
     * amount at its currency's scale.
     *
     * Public for a test double that answers a request itself instead of sending it (see
     * `MolliePaymentCredits`): the request it answers is then held to the same rules as
     * one that goes over the wire, so the guard stays live in a test that stubs the reply.
     */
    public static function assertSendable(object $request): void
    {
        self::assertNoOpenTransaction($request);
        self::assertAmountsAreScaled($request);
    }

    /**
     * Clear any idempotency key left on the shared Mollie client.
     *
     * Tolerant by design: the accessor differs across SDK versions and a test double may
     * expose neither, and failing to clean up a key must never take down the caller that just
     * moved money.
     */
    public static function forgetIdempotencyKey(): void
    {
        try {
            $client = Mollie::api();

            if (method_exists($client, 'resetIdempotencyKey')) {
                $client->resetIdempotencyKey();

                return;
            }

            if (method_exists($client, 'setIdempotencyKey')) {
                $client->setIdempotencyKey(null);
            }
        } catch (\Throwable) {
            // Nothing to clean up, or nothing that can be.
        }
    }

    /**
     * Pin the current transaction depth as the "nothing held" baseline.
     *
     * Called from the test harness after `RefreshDatabase` has opened its wrapper
     * transaction. Never call it from application code — it would define away the very
     * condition this class checks.
     */
    public static function useCurrentTransactionLevelAsBaseline(): void
    {
        self::$baselineTransactionLevel = DB::transactionLevel();
    }

    public static function resetBaselineTransactionLevel(): void
    {
        self::$baselineTransactionLevel = 0;
    }

    public static function baselineTransactionLevel(): int
    {
        return self::$baselineTransactionLevel;
    }

    private static function assertNoOpenTransaction(object $request): void
    {
        $level = DB::transactionLevel();

        if ($level <= self::$baselineTransactionLevel) {
            return;
        }

        $message = sprintf(
            'Mollie request %s was made inside a database transaction (depth %d). '
            .'A rollback cannot undo it: whatever it creates, cancels or refunds at Mollie '
            .'survives while the local writes are discarded. Move the call out of the '
            .'transaction — decide, then call Mollie, then write.',
            $request::class,
            $level - self::$baselineTransactionLevel,
        );

        if (self::shouldThrow()) {
            throw new \LogicException($message);
        }

        Log::warning($message, ['request' => $request::class, 'transaction_level' => $level]);
    }

    /**
     * Every `Money` on the request must carry its currency's own number of decimals.
     *
     * The second invariant this door enforces, and for the same reason as the first: the
     * scale is a property of the currency, not the constant 2. `BillingMoney::mollieAmount()`
     * knows that; a value assembled any other way — `number_format($cents / 100, 2)`, a
     * `(string)` cast, a float that lost its trailing zero — does not. Mollie reads
     * `"10.5"` as ten euros fifty, so a hand-built amount is a wrong charge rather than a
     * rejected one, and for a zero-decimal currency like JPY `"500.00"` is off by a factor
     * of a hundred in a request the API accepts. Both land in a booked invoice.
     *
     * Checked here because this is the only place that sees every request. The check is
     * on the string's shape, not on its value: a correct amount is unaffected, and there
     * is nothing to get wrong about it later.
     */
    private static function assertAmountsAreScaled(object $request): void
    {
        foreach (self::moneyValues($request) as $money) {
            $currency = (string) ($money->currency ?? '');
            $value = (string) ($money->value ?? '');

            if ($currency === '' || $value === '') {
                continue;
            }

            $expected = BillingMoney::fractionDigits($currency);
            $actual = self::decimalsIn($value);

            if ($actual === $expected) {
                continue;
            }

            $message = sprintf(
                'Mollie request %s carries the amount "%s %s", which has %d decimal place(s) '
                .'where %s has %d. Mollie reads the number as written, so this is a wrong '
                .'amount rather than a rejected one. Build it with '
                .'BillingMoney::mollieAmount() / InvoiceService::molliePrice(), which take '
                .'the scale from the currency.',
                $request::class,
                $currency,
                $value,
                $actual,
                $currency,
                $expected,
            );

            if (self::shouldThrow()) {
                throw new \LogicException($message);
            }

            Log::warning($message, ['request' => $request::class, 'currency' => $currency, 'value' => $value]);
        }
    }

    /**
     * Every Money-shaped value held directly on the request.
     *
     * Through reflection, because the SDK declares these properties `private` — the amount
     * on a `CreatePaymentRequest` is invisible to `get_object_vars()` from out here, which
     * made an earlier version of this check silently pass everything. Deliberately shallow
     * and duck-typed otherwise: one level, anything carrying `currency` + `value`. The cost
     * is nothing next to the HTTP round trip that follows.
     *
     * @return list<object>
     */
    private static function moneyValues(object $request): array
    {
        $found = [];

        foreach ((new \ReflectionObject($request))->getProperties() as $property) {
            if (! $property->isInitialized($request)) {
                continue;
            }

            $value = $property->getValue($request);

            if (is_object($value) && property_exists($value, 'value') && property_exists($value, 'currency')) {
                $found[] = $value;
            }
        }

        return $found;
    }

    private static function decimalsIn(string $value): int
    {
        $dot = strrpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    private static function shouldThrow(): bool
    {
        $app = function_exists('app') ? app() : null;

        if ($app === null) {
            return true;
        }

        return $app->runningUnitTests() || $app->environment('local', 'testing');
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * State of the automatic activation retry — see `Jobs\RetrySubscriptionActivationJob`.
 *
 * Two things live here because both the handlers and the job need them and neither should
 * own them: the name of the marker a failed activation leaves on the billable, and the
 * flag that says "this delivery is the retry job replaying a payment".
 *
 * The flag changes exactly three things in the handlers. A payment whose invoice already
 * exists but whose subscription does not is *resumed* instead of reported; a Mollie
 * subscription is looked for before one is created, because the first attempt may have
 * created it and lost the answer; and a failure is not announced again — the job owns the
 * ladder and reports once, at its end.
 */
final class ActivationRetry
{
    /** `subscription_meta` key: payment id => {context, first_failed_at}. */
    public const META_KEY = 'activation_retries';

    private static int $depth = 0;

    public static function resuming(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileResuming(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}

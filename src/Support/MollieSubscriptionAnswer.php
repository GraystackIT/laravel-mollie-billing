<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Mollie\Api\Exceptions\ApiException;

/**
 * What a refusal from Mollie's subscriptions API means — read off the answer, measured
 * against the real test system (tests/Sandbox/MollieChargedSubscriptionContractTest,
 * 17 November 2026), not inferred from the reference, which documents none of them.
 *
 * Mollie answers every one of these with a 422; only the detail tells them apart.
 */
final class MollieSubscriptionAnswer
{
    /**
     * "The start date cannot be changed when the subscription is already charged."
     *
     * A PATCH carrying a `startDate` other than the one the subscription already has is
     * refused as soon as Mollie has created one charge for it — a later date as well as an
     * earlier one; only the date it already holds passes, as a no-op. Measured with the
     * charge still `pending`; whether a settled charge lifts it is what the canary's
     * `pinned` subscription keeps asking. The package does not depend on the answer: it
     * tries the PATCH and replaces the subscription only when this is the refusal.
     * Amount, interval, description and metadata are still accepted, and a refused PATCH
     * changes nothing (it is atomic).
     */
    public static function refusesStartDateAfterCharge(\Throwable $e): bool
    {
        return $e instanceof ApiException
            && $e->getCode() === 422
            && str_contains(strtolower($e->getMessage()), 'already charged');
    }

    /**
     * The subscription a cancel was sent for no longer runs: never existed (404), its
     * customer was removed (410), or it was cancelled before ("The subscription has been
     * cancelled.", 422). All three are the outcome a cancel wants.
     */
    public static function cancelledAlready(\Throwable $e): bool
    {
        if (! $e instanceof ApiException) {
            return false;
        }

        if (in_array($e->getCode(), [404, 410], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return $e->getCode() === 422
            && (str_contains($message, 'has been cancel') || str_contains($message, 'already cancel')
                || str_contains($message, 'has been completed'));
    }

    /**
     * "A subscription with the same description already exists for this customer."
     *
     * Mollie keeps descriptions unique among a customer's active subscriptions. For a
     * replacement that is a guarantee, not an obstacle: the old subscription is cancelled
     * first, so this answer can only mean that an earlier attempt already created the
     * replacement and its answer was lost.
     */
    public static function sameDescriptionExists(\Throwable $e): bool
    {
        return $e instanceof ApiException
            && $e->getCode() === 422
            && str_contains(strtolower($e->getMessage()), 'same description already exists');
    }
}

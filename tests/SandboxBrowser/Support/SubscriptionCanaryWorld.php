<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser\Support;

/**
 * The subscription canary's judgement of what it read from Mollie — pure, no network, so
 * `tests/Unit/Canary/SubscriptionCanaryWorldTest` pins every rule here.
 *
 * A world is **dead** when nothing more can be learned from it: its mandate is gone (Mollie
 * answers 404/410, measured 410 on the world of 15 November 2026) or `invalid`, a subscription
 * that should still run is gone or ended, or the `times` subscription ended before its last
 * charge. The canary itself never revokes or cancels anything outside a reset (which deletes the
 * state with the world), so every death it sees was done by somebody else — that is reported
 * once, and the world is rebuilt.
 */
final class SubscriptionCanaryWorld
{
    /** HTTP answers that mean "this resource no longer exists". */
    public const GONE = [404, 410];

    /** Statuses after which a subscription never charges again. */
    public const ENDED = ['canceled', 'suspended', 'completed'];

    /**
     * Why the world is dead — empty while it lives.
     *
     * @param  array{id: string, status?: ?string, http?: ?int}  $mandate  `http` is the error status of the GET, null when it answered
     * @param  array<string, array{id: string, times?: ?int, status?: ?string, http?: ?int, charges?: int}>  $subscriptions
     * @return list<string>
     */
    public static function deathReasons(array $mandate, array $subscriptions): array
    {
        $reasons = [];

        if (in_array($mandate['http'] ?? null, self::GONE, true)) {
            $reasons[] = "mandate {$mandate['id']} is gone ({$mandate['http']})";
        } elseif (($mandate['status'] ?? null) === 'invalid') {
            $reasons[] = "mandate {$mandate['id']} is invalid";
        }

        foreach ($subscriptions as $name => $subscription) {
            $times = $subscription['times'] ?? null;
            $status = (string) ($subscription['status'] ?? '');

            if (in_array($subscription['http'] ?? null, self::GONE, true)) {
                $reasons[] = "subscription {$name} ({$subscription['id']}) is gone ({$subscription['http']})";

                continue;
            }

            if (! in_array($status, self::ENDED, true)) {
                continue;
            }

            if ($times === null) {
                $reasons[] = "subscription {$name} ({$subscription['id']}) is {$status}";
            } elseif (($subscription['charges'] ?? 0) < $times) {
                $reasons[] = "subscription {$name} ({$subscription['id']}) is {$status} after ".($subscription['charges'] ?? 0)." of {$times} charges";
            }
        }

        return $reasons;
    }

    /**
     * How a subscription with `times` ended once its last charge was made.
     *
     * Measured 25 November 2026: the `daily` subscription (`times: 10`) read `canceled`, its
     * `canceledAt` the moment of the tenth charge — not `completed`, as the old canary assumed
     * and failed on every day after. Both are an end; which one is recorded. Only a
     * subscription that goes on (more charges than `times`, or still active/pending after the
     * last) is a problem: that would be Mollie charging past what it was told.
     *
     * @return array{ended: ?string, problem: ?string}
     */
    public static function timesEnd(string $name, int $times, int $charges, string $status, ?string $canceledAt, ?string $lastChargeCreatedAt): array
    {
        if ($charges > $times) {
            return ['ended' => null, 'problem' => "{$name}: {$charges} charges made, the subscription was created with times {$times}"];
        }

        if ($charges < $times) {
            return ['ended' => null, 'problem' => null];
        }

        if ($status === 'completed') {
            return ['ended' => 'completed', 'problem' => null];
        }

        if ($status === 'canceled') {
            $atLastCharge = $canceledAt !== null && $lastChargeCreatedAt !== null
                && strtotime($canceledAt) !== false && strtotime($lastChargeCreatedAt) !== false
                && abs(strtotime($canceledAt) - strtotime($lastChargeCreatedAt)) <= 60;

            return ['ended' => $atLastCharge ? 'canceled at its last charge' : 'canceled at '.($canceledAt ?? 'an unknown time'), 'problem' => null];
        }

        return ['ended' => null, 'problem' => "{$name}: {$times} charges made and the subscription is {$status}, not ended"];
    }
}

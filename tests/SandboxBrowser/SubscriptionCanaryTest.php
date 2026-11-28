<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer;
use GraystackIT\MollieBilling\Tests\Sandbox\SandboxTestCase;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryReset;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\SubscriptionCanaryWorld;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreateMandateRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\DeleteCustomerRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionPaymentsRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The canary: long-lived subscriptions in the Mollie sandbox, looked at once a day.
 *
 * No single test run can watch Mollie's scheduler work — it runs daily and the earliest
 * start date is tomorrow. So this test is not a run, it is a visit. The first call creates
 * the subscriptions and writes down their ids; every later call reads what Mollie has done
 * with them since and holds it against what the package assumes:
 *
 *  - a subscription's own charge is created ON its cycle date — the premise of
 *    `SubscriptionPaymentHandler::cycleStart()`, which anchors the billing period on the
 *    payment's `createdAt`;
 *  - it is created at the amount the subscription holds, and carries the subscription's
 *    metadata and id — which is how a renewal webhook finds its billable;
 *  - what the cadence does after a month end (a subscription started on the 31st), which
 *    the long-run tests can only model both ways;
 *  - a subscription with `times` ends after its last charge (measured 25 November 2026:
 *    `canceled` at the tenth charge, not `completed` — both count as an end, which one is
 *    recorded; charging on is the problem);
 *  - whether Mollie takes a start date PATCHed onto a subscription after its first charge.
 *    It does not: on 17 November 2026 the `pinned` subscription was refused ("The start
 *    date cannot be changed when the subscription is already charged", 422) — which is why
 *    the package now replaces a charged subscription to move its next charge
 *    (`MollieSubscriptionReplacer`). The canary keeps asking, as an observation, not a
 *    failure: every visit it sends a later date and writes down the answer together with
 *    the status of the newest charge (`start_date_answers`) — so the day the charge
 *    settles shows whether a settled charge lifts the refusal — and once, on a day that is
 *    not its start date, it sends TODAY (`start_date_today_answer`). If Mollie ever takes
 *    a date, the charges from then on are expected on it (`pinned_to`).
 *
 * **A dead world is rebuilt.** The world of 15 November 2026 lost its mandate between
 * 25 November 00:16 and 26 November 19:49 UTC (Mollie answers 410; nobody in the canary
 * revoked it), after which its subscriptions read `pending` with no next payment date and
 * nothing more could be observed. Every visit now reads the mandate; when it is gone or
 * invalid, or a subscription that should run is gone or ended
 * ({@see SubscriptionCanaryWorld::deathReasons()}), the visit reports that **once** as a
 * problem (the canary itself never revokes or cancels outside a reset, so somebody else
 * did), moves the old world to `previous_worlds`, tears down what is left of it at Mollie
 * and builds a new one. The next visit looks at the new world.
 *
 * Run it daily (the cloud canary, `.github/workflows/renewal-canary.yml`, after the renewal
 * replay canary). It is `incomplete`, not failed, while there is nothing new to observe.
 * State lives in a JSON file — `MOLLIE_BILLING_CANARY_STATE`, default
 * `workbench/storage/sandbox-canary.json`. `MOLLIE_BILLING_SUBSCRIPTION_CANARY_RESET=1`
 * (or `MOLLIE_BILLING_CANARY_RESET=1`, which resets both canaries) cancels everything and
 * deletes the file.
 *
 * Deliberately NOT cleaned up by the base class: surviving the run is the point.
 */
class SubscriptionCanaryTest extends SandboxTestCase
{
    /** Visits kept in the state file. */
    private const VISITS_KEPT = 120;

    private static function statePath(): string
    {
        $configured = $_SERVER['MOLLIE_BILLING_CANARY_STATE'] ?? getenv('MOLLIE_BILLING_CANARY_STATE') ?: null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 2).'/workbench/storage/sandbox-canary.json';
    }

    /** @return array<string, mixed>|null */
    private static function readState(): ?array
    {
        $path = self::statePath();

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private static function writeState(array $state): void
    {
        $path = self::statePath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * The subscriptions the canary keeps.
     *
     * @return array<string, array{interval: string, amount: string, start: string, times: ?int}>
     */
    private static function plan(CarbonImmutable $today): array
    {
        // The next 31st that lies ahead.
        $monthEnd = $today->addDay();
        while ($monthEnd->day !== 31) {
            $monthEnd = $monthEnd->addDay();
        }

        return [
            // The fast signal: a charge every day, ten of them, then it ends.
            'daily' => ['interval' => '1 day', 'amount' => '1.00', 'start' => $today->addDay()->toDateString(), 'times' => 10],
            // The ordinary case, from tomorrow.
            'monthly' => ['interval' => '1 month', 'amount' => '34.51', 'start' => $today->addDay()->toDateString(), 'times' => null],
            // The month-end question: 31st → February → ?
            'month_end' => ['interval' => '1 month', 'amount' => '34.51', 'start' => $monthEnd->toDateString(), 'times' => null],
            // A start date PATCHed after a charge: refused since 17 November 2026 — watched.
            'pinned' => ['interval' => '1 month', 'amount' => '34.51', 'start' => $today->addDay()->toDateString(), 'times' => null],
        ];
    }

    public function test_the_canary_subscriptions_behave_the_way_the_package_assumes(): void
    {
        if (CanaryReset::requested('subscription')) {
            $this->reset();
            $this->markTestIncomplete('Subscription canary reset: everything cancelled at Mollie and the state file removed.');
        }

        try {
            $this->visit();
        } catch (\Throwable $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e; // red, incomplete or skipped: the visit finished and wrote itself down
            }

            // An attempt that died (network, Mollie 5xx) is written down, so the workflow's
            // retry and the summary can tell it from a finding. Every check runs again on the
            // next attempt — nothing is lost by repeating the visit.
            $state = self::readState();

            if ($state !== null) {
                $state['visits'] = array_slice([...($state['visits'] ?? []), [
                    'at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'aborted' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 500),
                    'observations' => [],
                    'problems' => [],
                ]], -self::VISITS_KEPT);
                self::writeState($state);
            }

            throw $e;
        }
    }

    private function visit(): void
    {
        $now = CarbonImmutable::now('UTC');
        $state = self::readState();
        $created = $state === null || ! isset($state['customer_id']);
        $previousVisitAt = isset($state['visits']) && $state['visits'] !== [] ? (string) end($state['visits'])['at'] : ($state['last_checked_at'] ?? null);

        $observations = [];
        $problems = [];

        // Builds a new world, or whatever a world is missing (a subscription added to the
        // plan later, a build that died half-way).
        $state = $this->ensureWorld($state ?? [], $observations);

        if ($created) {
            $this->finishVisit($state, $now, $observations, $problems, created: true, rebuilt: false);

            $ids = array_map(static fn (array $s): string => $s['id'], $state['subscriptions']);

            $this->markTestIncomplete(
                'Canary created — nothing to observe before Mollie\'s scheduler has run. '
                .'Customer '.$state['customer_id'].', mandate '.$state['mandate_id'].', subscriptions '.json_encode($ids).'.'
            );
        }

        $mandate = $this->readMandate($state);
        $alive = SubscriptionCanaryWorld::deathReasons($mandate, []) === [];

        if ($alive && ($mandate['status'] ?? null) === 'valid') {
            $state['mandate_last_valid_at'] = $now->toIso8601String();
        }

        $seen = [];

        foreach ($state['subscriptions'] as $name => $plan) {
            try {
                $subscription = Mollie::send(new GetSubscriptionRequest($state['customer_id'], $plan['id']));
            } catch (ApiException $e) {
                if (! in_array($e->getCode(), SubscriptionCanaryWorld::GONE, true)) {
                    throw $e;
                }

                $seen[$name] = ['id' => $plan['id'], 'times' => $plan['times'] ?? null, 'http' => $e->getCode()];

                continue;
            }

            $payments = [];

            foreach (Mollie::send(new GetPaginatedSubscriptionPaymentsRequest($state['customer_id'], $plan['id'])) as $payment) {
                $payments[] = $payment;
            }

            usort($payments, static fn ($a, $b): int => strcmp((string) $a->createdAt, (string) $b->createdAt));

            // After a charge, ask Mollie again whether it takes another start date. The
            // refusal is the expected answer (measured 17 November 2026) and is written down,
            // not failed on. Not on a dead world: there is nothing left to charge.
            if ($alive && $name === 'pinned' && $payments !== [] && ! isset($plan['pinned_to'])) {
                $answersBefore = $plan['start_date_answers'] ?? [];
                $todayBefore = $plan['start_date_today_answer'] ?? null;

                [$plan, $subscription, $problem] = $this->askForAnotherStartDate($state['customer_id'], $plan, $subscription, $payments);
                $state['subscriptions'][$name] = $plan;

                $answersAfter = $plan['start_date_answers'] ?? [];
                $newest = $answersAfter === [] ? null : end($answersAfter);
                $previous = $answersBefore === [] ? null : end($answersBefore);

                if ($newest !== null && ($previous === null || $previous['answer'] !== $newest['answer'] || $previous['newest_charge_status'] !== $newest['newest_charge_status'])) {
                    $observations[] = "pinned: a later start date ({$newest['sent']}) was {$newest['answer']}, newest charge {$newest['newest_charge_status']}";
                }

                if ($todayBefore === null && isset($plan['start_date_today_answer'])) {
                    $observations[] = "pinned: today's date ({$plan['start_date_today_answer']['sent']}) was {$plan['start_date_today_answer']['answer']}";
                }

                if (isset($plan['pinned_to'])) {
                    $observations[] = "pinned: Mollie TOOK start date {$plan['pinned_to']} after a charge — the refusal of 17 November 2026 no longer holds";
                }

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }

            $known = array_column($plan['payments'] ?? [], 'id');
            $rows = [];

            foreach ($payments as $index => $payment) {
                $chargedAt = CarbonImmutable::parse((string) $payment->createdAt)->utc();
                $candidates = self::cycleCandidates($plan, $index);

                $rows[] = [
                    'id' => (string) $payment->id,
                    'created_at' => $chargedAt->toIso8601String(),
                    'paid_at' => isset($payment->paidAt) ? (string) $payment->paidAt : null,
                    'status' => (string) $payment->status,
                    'amount' => (string) $payment->amount->value,
                    'expected_cycle' => $candidates,
                ];

                if (! in_array((string) $payment->id, $known, true)) {
                    $observations[] = "{$name}: charge #{$index} {$payment->id} created {$chargedAt->toIso8601String()} ({$payment->status}, {$payment->amount->value})";
                }

                // The claim cycleStart() rests on.
                if (! in_array($chargedAt->toDateString(), $candidates, true)) {
                    $problems[] = "{$name} #{$index} ({$payment->id}): created {$chargedAt->toIso8601String()}, expected the cycle date ".implode(' or ', $candidates);
                }

                if ((string) $payment->amount->value !== $plan['amount']) {
                    $problems[] = "{$name} #{$index}: charged {$payment->amount->value}, the subscription holds {$plan['amount']}";
                }

                if ((string) ($payment->subscriptionId ?? '') !== $plan['id']) {
                    $problems[] = "{$name} #{$index}: the payment does not name its subscription";
                }

                if (($payment->metadata->canary ?? null) !== $name) {
                    $problems[] = "{$name} #{$index}: the subscription's metadata was not copied onto the payment";
                }

                if ((string) ($payment->sequenceType ?? '') !== 'recurring') {
                    $problems[] = "{$name} #{$index}: sequenceType is ".($payment->sequenceType ?? 'missing');
                }
            }

            // Which way the cadence goes after February — recorded the day it is known.
            if ($name === 'month_end') {
                foreach ($rows as $row) {
                    if (count($row['expected_cycle']) === 2) {
                        $cadence = substr($row['created_at'], 0, 10) === $row['expected_cycle'][0] ? 'clamp' : 'drift';

                        if (($state['month_end_cadence'] ?? null) !== $cadence) {
                            $observations[] = "month_end: Mollie's cadence after a month end is {$cadence}";
                        }

                        $state['month_end_cadence'] = $cadence;
                    }
                }
            }

            $status = (string) $subscription->status;
            $canceledAt = isset($subscription->canceledAt) ? (string) $subscription->canceledAt : null;

            if (($plan['times'] ?? null) !== null) {
                $end = SubscriptionCanaryWorld::timesEnd($name, (int) $plan['times'], count($payments), $status, $canceledAt, $rows === [] ? null : end($rows)['created_at']);

                if ($end['problem'] !== null) {
                    $problems[] = $end['problem'];
                }

                if ($end['ended'] !== null && ($plan['ended'] ?? null) === null) {
                    $state['subscriptions'][$name]['ended'] = $end['ended'];
                    $observations[] = "{$name}: ended after its {$plan['times']} charges — {$end['ended']}";
                }
            }

            // Until its date has come, the pinned date stays what Mollie holds.
            if ($alive && $name === 'pinned' && isset($plan['pinned_to']) && count($payments) === 1
                && (string) ($subscription->nextPaymentDate ?? '') !== $plan['pinned_to']) {
                $problems[] = "pinned: Mollie's next payment date is ".($subscription->nextPaymentDate ?? 'missing').", pinned to {$plan['pinned_to']}";
            }

            $state['subscriptions'][$name]['status'] = $status;
            $state['subscriptions'][$name]['canceled_at'] = $canceledAt;
            $state['subscriptions'][$name]['next_payment_date'] = isset($subscription->nextPaymentDate) ? (string) $subscription->nextPaymentDate : null;
            $state['subscriptions'][$name]['payments'] = $rows;

            $seen[$name] = ['id' => $plan['id'], 'times' => $plan['times'] ?? null, 'status' => $status, 'charges' => count($payments)];
        }

        $reasons = SubscriptionCanaryWorld::deathReasons($mandate, $seen);
        $rebuilt = false;

        if ($reasons !== []) {
            [$state, $problems, $observations] = $this->rebuild($state, $reasons, $now, $previousVisitAt, $problems, $observations);
            $rebuilt = true;
        }

        $this->finishVisit($state, $now, $observations, $problems, created: false, rebuilt: $rebuilt);

        $this->assertSame([], $problems, "Mollie did something the package does not assume:\n".implode("\n", $problems));

        if ($observations === []) {
            $this->markTestIncomplete('Subscription canary alive, nothing new since '.($previousVisitAt ?? 'the last visit').'.');
        }
    }

    /**
     * The mandate as Mollie has it now. A 404/410 is an answer (the world is dead), not an error.
     *
     * @param  array<string, mixed>  $state
     * @return array{id: string, status: ?string, http: ?int}
     */
    private function readMandate(array $state): array
    {
        $mandate = ['id' => (string) $state['mandate_id'], 'status' => null, 'http' => null];

        try {
            $mandate['status'] = (string) Mollie::send(new GetMandateRequest((string) $state['customer_id'], (string) $state['mandate_id']))->status;
        } catch (ApiException $e) {
            if (! in_array($e->getCode(), SubscriptionCanaryWorld::GONE, true)) {
                throw $e;
            }

            $mandate['http'] = $e->getCode();
        }

        return $mandate;
    }

    /**
     * Report the dead world once, keep it as history, tear down what is left of it at
     * Mollie and build a new one.
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $reasons
     * @param  list<string>  $problems
     * @param  list<string>  $observations
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>}
     */
    private function rebuild(array $state, array $reasons, CarbonImmutable $now, ?string $previousVisitAt, array $problems, array $observations): array
    {
        $lastAlive = isset($state['mandate_last_valid_at'])
            ? 'mandate last seen valid '.$state['mandate_last_valid_at']
            : 'visit before this one '.($previousVisitAt ?? 'unknown');

        $problems[] = 'The canary\'s world (created '.($state['created_at'] ?? '?').') is dead: '.implode('; ', $reasons)
            ." ({$lastAlive}). The canary never revokes or cancels outside a reset, so somebody or something else did."
            .' The world was moved to previous_worlds and rebuilt; the next visit looks at the new one.';

        $archived = array_diff_key($state, array_flip(['previous_worlds', 'visits']));
        $archived['died'] = [
            'detected_at' => $now->toIso8601String(),
            'reasons' => $reasons,
            'mandate_last_valid_at' => $state['mandate_last_valid_at'] ?? null,
            'previous_visit_at' => $previousVisitAt,
        ];

        // From here on the old world is history only: whatever fails below cannot report it twice.
        $carry = [
            'previous_worlds' => [...($state['previous_worlds'] ?? []), $archived],
            'visits' => $state['visits'] ?? [],
        ];
        self::writeState($carry);

        $this->tearDownWorld($archived);

        try {
            $state = $this->ensureWorld($carry, $observations);
            $observations[] = 'World rebuilt: customer '.$state['customer_id'].', mandate '.$state['mandate_id']
                .', subscriptions '.json_encode(array_map(static fn (array $s): string => $s['id'], $state['subscriptions']));
        } catch (\Throwable $e) {
            $state = self::readState() ?? $carry;
            $problems[] = 'Rebuilding the world failed half-way ('.$e->getMessage().'); the next visit builds the rest.';
        }

        return [$state, $problems, $observations];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $observations
     * @param  list<string>  $problems
     */
    private function finishVisit(array $state, CarbonImmutable $now, array $observations, array $problems, bool $created, bool $rebuilt): void
    {
        $state['visits'] = array_slice([...($state['visits'] ?? []), [
            'at' => $now->toIso8601String(),
            'created_world' => $created,
            'rebuilt_world' => $rebuilt,
            'observations' => $observations,
            'problems' => $problems,
        ]], -self::VISITS_KEPT);
        $state['last_checked_at'] = $now->toIso8601String();
        $state['last_problems'] = $problems;

        self::writeState($state);
    }

    /**
     * Send the `pinned` subscription a later start date — and, once, today's — and write
     * down what Mollie answers.
     *
     * A refusal ("…already charged") is the measured rule and only recorded. A start date
     * Mollie takes is recorded too, and from then on the charges are expected on it
     * (`pinned_to`); a date taken but not held is the one thing reported as a problem.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<object>  $payments  oldest first
     * @return array{0: array<string, mixed>, 1: object, 2: ?string}
     */
    private function askForAnotherStartDate(string $customerId, array $plan, object $subscription, array $payments): array
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->startOfDay();
        $newest = $payments[count($payments) - 1];
        $later = isset($subscription->nextPaymentDate)
            ? CarbonImmutable::parse((string) $subscription->nextPaymentDate, 'UTC')->addDays(3)
            : $today->addDays(3);

        $attempts = [['later', $later]];

        // Today, once, on a day that is not the subscription's own start date (that one
        // passes as a no-op and tells nothing).
        if (! isset($plan['start_date_today_answer']) && $today->toDateString() !== (string) $subscription->startDate) {
            $attempts[] = ['today', $today];
        }

        foreach ($attempts as [$kind, $date]) {
            try {
                $patched = Mollie::send(new UpdateSubscriptionRequest(
                    customerId: $customerId,
                    subscriptionId: $plan['id'],
                    startDate: new Date($date),
                ));
                $answer = 'taken';
            } catch (ApiException $e) {
                if (! MollieSubscriptionAnswer::refusesStartDateAfterCharge($e)) {
                    throw $e;
                }

                $patched = null;
                $answer = 'refused: already charged';
            }

            $row = [
                'at' => $now->toIso8601String(),
                'sent' => $date->toDateString(),
                'kind' => $kind,
                'newest_charge' => (string) $newest->id,
                'newest_charge_status' => (string) $newest->status,
                'answer' => $answer,
            ];

            if ($kind === 'today') {
                $plan['start_date_today_answer'] = $row;
            } else {
                $plan['start_date_answers'][] = $row;
            }

            if ($patched !== null) {
                $plan['pinned_to'] = $date->toDateString();
                $plan['pinned_at'] = $now->toIso8601String();
                $plan['pinned_after_charge'] = (string) $newest->id;
                $subscription = $patched;

                $problem = (string) ($patched->nextPaymentDate ?? '') !== $date->toDateString()
                    ? "pinned: Mollie took startDate {$date->toDateString()} after charge {$newest->id}, but its next payment date is ".($patched->nextPaymentDate ?? 'missing')
                    : null;

                return [$plan, $subscription, $problem];
            }
        }

        return [$plan, $subscription, null];
    }

    /**
     * The UTC date(s) charge number `$index` may be created on. Two for a month-end
     * subscription once February is behind it: `start + n months` (clamp) and
     * "previous + 1 month" (drift).
     *
     * @param  array{interval: string, start: string, pinned_to?: string}  $plan
     * @return list<string>
     */
    private static function cycleCandidates(array $plan, int $index): array
    {
        $start = CarbonImmutable::parse($plan['start'], 'UTC');

        if ($plan['interval'] === '1 day') {
            return [$start->addDays($index)->toDateString()];
        }

        // After the pin, the charges follow the pinned date.
        if (isset($plan['pinned_to']) && $index >= 1) {
            return [CarbonImmutable::parse($plan['pinned_to'], 'UTC')->addMonthsNoOverflow($index - 1)->toDateString()];
        }

        $clamp = $start->addMonthsNoOverflow($index);

        $drift = $start;
        for ($i = 0; $i < $index; $i++) {
            $drift = $drift->addMonthNoOverflow();
        }

        return array_values(array_unique([$clamp->toDateString(), $drift->toDateString()]));
    }

    /**
     * Build whatever the world lacks — all of it on the first visit — writing the state
     * after every step: a failure half-way must not orphan what exists at Mollie.
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $observations
     * @return array<string, mixed>
     */
    private function ensureWorld(array $state, array &$observations): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        if (! isset($state['customer_id'])) {
            $customer = Mollie::send(new CreateCustomerRequest(
                name: 'Billing canary — do not delete',
                email: 'billing-canary-'.bin2hex(random_bytes(4)).'@example.test',
            ));

            $state['created_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $state['customer_id'] = (string) $customer->id;
            $state['subscriptions'] = [];
            self::writeState($state);
        }

        if (! isset($state['mandate_id'])) {
            $mandate = Mollie::send(new CreateMandateRequest(
                customerId: (string) $state['customer_id'],
                method: 'directdebit',
                consumerName: 'Billing canary',
                consumerAccount: self::TEST_IBAN,
            ));

            $state['mandate_id'] = (string) $mandate->id;
            self::writeState($state);
        }

        $fresh = ($state['subscriptions'] ?? []) === [];

        foreach (self::plan($today) as $name => $plan) {
            if (isset($state['subscriptions'][$name])) {
                continue;
            }

            $state['subscriptions'][$name] = $this->createSubscription($state, $name, $plan, $today);
            self::writeState($state);

            if (! $fresh) {
                $observations[] = "{$name}: added to the world ({$state['subscriptions'][$name]['id']}, from {$plan['start']})";
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{interval: string, amount: string, start: string, times: ?int}  $plan
     * @return array<string, mixed>
     */
    private function createSubscription(array $state, string $name, array $plan, CarbonImmutable $today): array
    {
        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: (string) $state['customer_id'],
            amount: new Money('EUR', $plan['amount']),
            interval: $plan['interval'],
            description: 'Billing canary '.$name.' '.$today->toDateString().' '.bin2hex(random_bytes(2)),
            times: $plan['times'],
            startDate: new Date(CarbonImmutable::parse($plan['start'], 'UTC')),
            metadata: ['canary' => $name],
            mandateId: (string) $state['mandate_id'],
        ));

        return $plan + [
            'id' => (string) $subscription->id,
            'status' => (string) $subscription->status,
            'next_payment_date' => (string) ($subscription->nextPaymentDate ?? ''),
        ];
    }

    /**
     * Cancel, revoke, delete — whatever of a world still exists at Mollie. Never fails:
     * what is already gone is the point.
     *
     * @param  array<string, mixed>  $world
     */
    private function tearDownWorld(array $world): void
    {
        $customerId = (string) ($world['customer_id'] ?? '');

        if ($customerId === '') {
            return;
        }

        foreach ($world['subscriptions'] ?? [] as $plan) {
            try {
                Mollie::send(new CancelSubscriptionRequest($customerId, (string) $plan['id']));
            } catch (\Throwable) {
                // already ended
            }
        }

        foreach ([
            fn () => Mollie::send(new RevokeMandateRequest($customerId, (string) ($world['mandate_id'] ?? ''))),
            fn () => Mollie::send(new DeleteCustomerRequest($customerId)),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // already gone
            }
        }
    }

    private function reset(): void
    {
        $state = self::readState();

        if ($state === null) {
            return;
        }

        // The current world and, should one of their tear-downs have failed, the earlier ones.
        foreach ([$state, ...($state['previous_worlds'] ?? [])] as $world) {
            $this->tearDownWorld($world);
        }

        @unlink(self::statePath());
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Tests\Sandbox\SandboxTestCase;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreateMandateRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\DeleteCustomerRequest;
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
 *  - a subscription with `times` ends up `completed`;
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
 * Run it daily: `composer test:sandbox:canary` (cron or CI). It is `incomplete`, not
 * failed, while there is nothing to observe yet. State lives in a JSON file —
 * `MOLLIE_BILLING_CANARY_STATE`, default `workbench/storage/sandbox-canary.json` — and
 * `MOLLIE_BILLING_CANARY_RESET=1` cancels everything and deletes it.
 *
 * Deliberately NOT cleaned up by the base class: surviving the run is the point.
 */
class SubscriptionCanaryTest extends SandboxTestCase
{
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
            // The fast signal: a charge every day, ten of them, then `completed`.
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
        if (($_SERVER['MOLLIE_BILLING_CANARY_RESET'] ?? getenv('MOLLIE_BILLING_CANARY_RESET')) === '1') {
            $this->reset();
            $this->markTestIncomplete('Canary reset: everything cancelled and the state file removed.');
        }

        $state = self::readState();

        if ($state === null) {
            $state = $this->create();
            self::writeState($state);

            $ids = array_map(static fn (array $s): string => $s['id'], $state['subscriptions']);

            $this->markTestIncomplete(
                'Canary created — nothing to observe before Mollie\'s scheduler has run. '
                .'Customer '.$state['customer_id'].', mandate '.$state['mandate_id'].', subscriptions '.json_encode($ids).'.'
            );
        }

        // A canary created before `pinned` existed grows it on its next visit.
        if (! isset($state['subscriptions']['pinned'])) {
            $today = CarbonImmutable::now('UTC')->startOfDay();
            $state['subscriptions']['pinned'] = $this->createSubscription($state, 'pinned', self::plan($today)['pinned'], $today);
            self::writeState($state);
        }

        $observed = 0;
        $problems = [];

        foreach ($state['subscriptions'] as $name => $plan) {
            $subscription = Mollie::send(new GetSubscriptionRequest($state['customer_id'], $plan['id']));
            $payments = [];

            foreach (Mollie::send(new GetPaginatedSubscriptionPaymentsRequest($state['customer_id'], $plan['id'])) as $payment) {
                $payments[] = $payment;
            }

            usort($payments, static fn ($a, $b): int => strcmp((string) $a->createdAt, (string) $b->createdAt));

            // After a charge, ask Mollie again whether it takes another start date. The
            // refusal is the expected answer (measured 17 November 2026) and is written down,
            // not failed on.
            if ($name === 'pinned' && $payments !== [] && ! isset($plan['pinned_to'])) {
                [$plan, $subscription, $problem] = $this->askForAnotherStartDate($state['customer_id'], $plan, $subscription, $payments);
                $state['subscriptions'][$name] = $plan;

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }

            $rows = [];

            foreach ($payments as $index => $payment) {
                $created = CarbonImmutable::parse((string) $payment->createdAt)->utc();
                $candidates = self::cycleCandidates($plan, $index);

                $rows[] = [
                    'id' => (string) $payment->id,
                    'created_at' => $created->toIso8601String(),
                    'paid_at' => isset($payment->paidAt) ? (string) $payment->paidAt : null,
                    'status' => (string) $payment->status,
                    'amount' => (string) $payment->amount->value,
                    'expected_cycle' => $candidates,
                ];

                // The claim cycleStart() rests on.
                if (! in_array($created->toDateString(), $candidates, true)) {
                    $problems[] = "{$name} #{$index} ({$payment->id}): created {$created->toIso8601String()}, expected the cycle date ".implode(' or ', $candidates);
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

                $observed++;
            }

            // Which way the cadence goes after February — recorded the day it is known.
            if ($name === 'month_end') {
                foreach ($rows as $index => $row) {
                    if (count($row['expected_cycle']) === 2) {
                        $state['month_end_cadence'] = substr($row['created_at'], 0, 10) === $row['expected_cycle'][0] ? 'clamp' : 'drift';
                    }
                }
            }

            if (($plan['times'] ?? null) !== null && count($payments) >= $plan['times'] && (string) $subscription->status !== 'completed') {
                $problems[] = "{$name}: {$plan['times']} charges made and the subscription is {$subscription->status}, not completed";
            }

            // Until its date has come, the pinned date stays what Mollie holds.
            if ($name === 'pinned' && isset($plan['pinned_to']) && count($payments) === 1
                && (string) ($subscription->nextPaymentDate ?? '') !== $plan['pinned_to']) {
                $problems[] = "pinned: Mollie's next payment date is ".($subscription->nextPaymentDate ?? 'missing').", pinned to {$plan['pinned_to']}";
            }

            $state['subscriptions'][$name]['status'] = (string) $subscription->status;
            $state['subscriptions'][$name]['next_payment_date'] = isset($subscription->nextPaymentDate) ? (string) $subscription->nextPaymentDate : null;
            $state['subscriptions'][$name]['payments'] = $rows;
        }

        $state['last_checked_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        $state['last_problems'] = $problems;
        self::writeState($state);

        if ($observed === 0) {
            $this->markTestIncomplete('Canary alive, no charge created yet (created '.$state['created_at'].').');
        }

        $this->assertSame([], $problems, "Mollie did something the package does not assume:\n".implode("\n", $problems));
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
        $later = CarbonImmutable::parse((string) $subscription->nextPaymentDate, 'UTC')->addDays(3);

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
            } catch (\Mollie\Api\Exceptions\ApiException $e) {
                if (! \GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer::refusesStartDateAfterCharge($e)) {
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

    /** @return array<string, mixed> */
    private function create(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $customer = Mollie::send(new CreateCustomerRequest(
            name: 'Billing canary — do not delete',
            email: 'billing-canary-'.bin2hex(random_bytes(4)).'@example.test',
        ));

        $mandate = Mollie::send(new CreateMandateRequest(
            customerId: (string) $customer->id,
            method: 'directdebit',
            consumerName: 'Billing canary',
            consumerAccount: self::TEST_IBAN,
        ));

        $state = [
            'created_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'customer_id' => (string) $customer->id,
            'mandate_id' => (string) $mandate->id,
            'subscriptions' => [],
        ];

        foreach (self::plan($today) as $name => $plan) {
            $state['subscriptions'][$name] = $this->createSubscription($state, $name, $plan, $today);

            // Written after every one: a failure half-way must not orphan what exists.
            self::writeState($state);
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

    private function reset(): void
    {
        $state = self::readState();

        if ($state === null) {
            return;
        }

        foreach ($state['subscriptions'] ?? [] as $plan) {
            try {
                Mollie::send(new CancelSubscriptionRequest($state['customer_id'], $plan['id']));
            } catch (\Throwable) {
                // already ended
            }
        }

        foreach ([
            fn () => Mollie::send(new RevokeMandateRequest($state['customer_id'], $state['mandate_id'])),
            fn () => Mollie::send(new DeleteCustomerRequest($state['customer_id'])),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // already gone
            }
        }

        @unlink(self::statePath());
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieSubscriptionAnswer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * What Mollie does with a subscription once it has been charged — measured on 17 November
 * 2026, after the canary's `pinned` subscription was refused a new start date the morning
 * after its first charge. None of this is in Mollie's reference.
 *
 * Measured:
 *
 *  - A charged subscription refuses every start date but the one it holds — a later one,
 *    the next payment date itself, an earlier one: 422 "The start date cannot be changed
 *    when the subscription is already charged." Its own start date passes, as a no-op.
 *    Amount, interval, description and metadata are still taken, and a refused PATCH
 *    changes nothing (amount + start date together: the amount stays).
 *  - An interval PATCHed onto a charged subscription moves `nextPaymentDate` to
 *    start date + n × the new interval, counted from the START date.
 *  - A subscription that has not been charged takes any start date from today on; an
 *    earlier one is refused ("Start date is not today or in the future."). Today is taken,
 *    and Mollie moves `nextPaymentDate` one interval on at once: today counts as charged
 *    (the payment itself is created by Mollie's scheduler later).
 *  - Descriptions are unique among a customer's ACTIVE subscriptions: a second one with
 *    the same description is refused ("A subscription with the same description already
 *    exists for this customer."), and accepted once the first is cancelled.
 *  - A second cancel, and a PATCH, of a cancelled subscription are refused: "The
 *    subscription has been cancelled." (422, not 404 or 410).
 *  - A create sent again with the same idempotency key returns the same subscription.
 *
 * The charged cases need a subscription Mollie has charged, and Mollie's scheduler
 * charges only at night — no run can make one. They use the canary's `pinned`
 * subscription (`tests/SandboxBrowser/SubscriptionCanaryTest`) and are skipped where it
 * does not exist yet or has no charge. Every change made to it is undone in a `finally`.
 *
 * What is NOT measured here, because no run can produce it: whether the refusal ends once
 * the charge has settled (a test-mode direct debit stays `pending`), and what Mollie
 * answers for a start date of today on a charged subscription whose start date is not
 * today. The canary records both (`docs/sandbox-testing.md`). The package does not depend
 * on either: it tries the PATCH, and replaces the subscription only on this refusal, and
 * never with a start date of today or earlier.
 */
class MollieChargedSubscriptionContractTest extends SandboxTestCase
{
    // ── A subscription that has been charged (the canary's `pinned`) ──────────

    /** @return array{customer: string, id: string, start: string, next: string, amount: string}|null */
    private function chargedCanarySubscription(): ?array
    {
        $configured = $_SERVER['MOLLIE_BILLING_CANARY_STATE'] ?? getenv('MOLLIE_BILLING_CANARY_STATE') ?: null;
        $path = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__, 2).'/workbench/storage/sandbox-canary.json';

        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $pinned = is_array($state) ? ($state['subscriptions']['pinned'] ?? null) : null;

        if (! is_array($pinned) || empty($pinned['id'])) {
            return null;
        }

        $customer = (string) $state['customer_id'];
        $subscription = Mollie::send(new GetSubscriptionRequest($customer, (string) $pinned['id']));

        $charged = false;
        foreach (Mollie::send(new GetPaginatedSubscriptionPaymentsRequest($customer, (string) $pinned['id'])) as $payment) {
            $charged = true;
        }

        if (! $charged || (string) $subscription->status !== 'active') {
            return null;
        }

        return [
            'customer' => $customer,
            'id' => (string) $subscription->id,
            'start' => (string) $subscription->startDate,
            'next' => (string) $subscription->nextPaymentDate,
            'amount' => (string) $subscription->amount->value,
        ];
    }

    /** @return array{customer: string, id: string, start: string, next: string, amount: string} */
    private function requireChargedSubscription(): array
    {
        $charged = $this->chargedCanarySubscription();

        if ($charged === null) {
            $this->markTestSkipped('No charged subscription: the canary\'s `pinned` subscription does not exist yet or has no charge (composer test:sandbox:canary).');
        }

        return $charged;
    }

    private function refusal(callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Mollie took a request it was expected to refuse.');
    }

    public function test_a_charged_subscription_refuses_any_start_date_but_its_own(): void
    {
        $s = $this->requireChargedSubscription();

        $later = CarbonImmutable::parse($s['next'], 'UTC')->addDays(3);
        $earlier = CarbonImmutable::parse($s['start'], 'UTC')->subDays(7);

        foreach ([$later, CarbonImmutable::parse($s['next'], 'UTC'), $earlier] as $date) {
            $e = $this->refusal(fn () => Mollie::send(new UpdateSubscriptionRequest(
                customerId: $s['customer'],
                subscriptionId: $s['id'],
                startDate: new Date($date),
            )));

            $this->assertSame(422, $e->getCode(), $date->toDateString());
            $this->assertStringContainsString('cannot be changed when the subscription is already charged', $e->getMessage());
            $this->assertTrue(MollieSubscriptionAnswer::refusesStartDateAfterCharge($e), 'The package must recognise the refusal.');
        }

        // Its own start date passes, and changes nothing.
        $same = Mollie::send(new UpdateSubscriptionRequest(
            customerId: $s['customer'],
            subscriptionId: $s['id'],
            startDate: new Date(CarbonImmutable::parse($s['start'], 'UTC')),
        ));

        $this->assertSame($s['next'], (string) $same->nextPaymentDate);
    }

    public function test_a_refused_start_date_takes_the_amount_sent_with_it_down_too(): void
    {
        $s = $this->requireChargedSubscription();

        $this->refusal(fn () => Mollie::send(new UpdateSubscriptionRequest(
            customerId: $s['customer'],
            subscriptionId: $s['id'],
            amount: new Money('EUR', number_format((float) $s['amount'] + 0.01, 2, '.', '')),
            startDate: new Date(CarbonImmutable::parse($s['next'], 'UTC')->addDays(3)),
        )));

        $after = Mollie::send(new GetSubscriptionRequest($s['customer'], $s['id']));

        $this->assertSame($s['amount'], (string) $after->amount->value, 'A refused PATCH is atomic.');
        $this->assertSame($s['next'], (string) $after->nextPaymentDate);
    }

    public function test_a_charged_subscription_still_takes_amount_interval_description_and_metadata(): void
    {
        $s = $this->requireChargedSubscription();
        $current = Mollie::send(new GetSubscriptionRequest($s['customer'], $s['id']));
        $bumped = number_format((float) $s['amount'] + 0.01, 2, '.', '');

        try {
            $amount = Mollie::send(new UpdateSubscriptionRequest(customerId: $s['customer'], subscriptionId: $s['id'], amount: new Money('EUR', $bumped)));
            $this->assertSame($bumped, (string) $amount->amount->value);

            // Counted from the start date: start + 2 months, not the next date + 1 month.
            $interval = Mollie::send(new UpdateSubscriptionRequest(customerId: $s['customer'], subscriptionId: $s['id'], interval: '2 months'));
            $this->assertSame('2 months', (string) $interval->interval);
            $this->assertSame(
                CarbonImmutable::parse($s['start'], 'UTC')->addMonthsNoOverflow(2)->toDateString(),
                (string) $interval->nextPaymentDate,
            );

            // The shape the package sends for an amount change after a charge.
            $all = Mollie::send(new UpdateSubscriptionRequest(
                customerId: $s['customer'],
                subscriptionId: $s['id'],
                amount: new Money('EUR', $s['amount']),
                description: (string) $current->description,
                interval: '1 month',
                metadata: json_decode((string) json_encode($current->metadata), true),
            ));
            $this->assertSame($s['amount'], (string) $all->amount->value);
            $this->assertSame($s['next'], (string) $all->nextPaymentDate);
        } finally {
            // The canary checks every charge against this amount and cadence.
            Mollie::send(new UpdateSubscriptionRequest(
                customerId: $s['customer'],
                subscriptionId: $s['id'],
                amount: new Money('EUR', $s['amount']),
                interval: '1 month',
            ));
        }
    }

    // ── A subscription that has not been charged ──────────────────────────────

    public function test_an_uncharged_subscription_takes_today_and_refuses_the_past(): void
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Uncharged');
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: $ids['customerId'],
            amount: new Money('EUR', '10.00'),
            interval: '1 month',
            description: 'Sandbox uncharged '.bin2hex(random_bytes(3)),
            startDate: new Date($today->addDays(10)),
            mandateId: $ids['mandateId'],
        ));
        $this->trackMollieResource('subscription', (string) $subscription->id, $ids['customerId']);

        $later = Mollie::send(new UpdateSubscriptionRequest(customerId: $ids['customerId'], subscriptionId: (string) $subscription->id, startDate: new Date($today->addDays(20))));
        $this->assertSame($today->addDays(20)->toDateString(), (string) $later->nextPaymentDate);

        $e = $this->refusal(fn () => Mollie::send(new UpdateSubscriptionRequest(
            customerId: $ids['customerId'],
            subscriptionId: (string) $subscription->id,
            startDate: new Date($today->subDays(3)),
        )));
        $this->assertSame(422, $e->getCode());
        $this->assertStringContainsString('Start date is not today or in the future', $e->getMessage());
        $this->assertFalse(MollieSubscriptionAnswer::refusesStartDateAfterCharge($e), 'Not the charged refusal — no replacement for this one.');

        // Today: taken, and today counts as charged at once.
        $now = Mollie::send(new UpdateSubscriptionRequest(customerId: $ids['customerId'], subscriptionId: (string) $subscription->id, startDate: new Date($today)));
        $this->assertSame($today->toDateString(), (string) $now->startDate);
        $this->assertSame($today->addMonthNoOverflow()->toDateString(), (string) $now->nextPaymentDate);
    }

    public function test_descriptions_are_unique_among_active_subscriptions_and_a_cancelled_one_refuses_everything(): void
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Descriptions');
        $description = 'Sandbox description '.bin2hex(random_bytes(3));
        $start = new Date(CarbonImmutable::now('UTC')->addDays(30));

        $create = fn (): object => Mollie::send(new CreateSubscriptionRequest(
            customerId: $ids['customerId'],
            amount: new Money('EUR', '10.00'),
            interval: '1 month',
            description: $description,
            startDate: $start,
            mandateId: $ids['mandateId'],
        ));

        $first = $create();
        $this->trackMollieResource('subscription', (string) $first->id, $ids['customerId']);

        $clash = $this->refusal($create);
        $this->assertSame(422, $clash->getCode());
        $this->assertTrue(MollieSubscriptionAnswer::sameDescriptionExists($clash), $clash->getMessage());

        Mollie::send(new CancelSubscriptionRequest($ids['customerId'], (string) $first->id));

        $again = $this->refusal(fn () => Mollie::send(new CancelSubscriptionRequest($ids['customerId'], (string) $first->id)));
        $this->assertSame(422, $again->getCode());
        $this->assertStringContainsString('has been cancelled', $again->getMessage());
        $this->assertTrue(MollieSubscriptionAnswer::cancelledAlready($again), 'A second cancel is the outcome a cancel wants.');

        $patch = $this->refusal(fn () => Mollie::send(new UpdateSubscriptionRequest(customerId: $ids['customerId'], subscriptionId: (string) $first->id, amount: new Money('EUR', '11.00'))));
        $this->assertStringContainsString('has been cancelled', $patch->getMessage());

        // Free again once the first is cancelled.
        $second = $create();
        $this->trackMollieResource('subscription', (string) $second->id, $ids['customerId']);
        $this->assertNotSame((string) $first->id, (string) $second->id);
        $this->assertSame($description, (string) $second->description);
    }

    public function test_a_create_sent_again_with_the_same_idempotency_key_returns_the_same_subscription(): void
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Idempotent Create');
        $key = 'subscription-replace:sandbox-'.bin2hex(random_bytes(4));

        $request = fn (): CreateSubscriptionRequest => new CreateSubscriptionRequest(
            customerId: $ids['customerId'],
            amount: new Money('EUR', '10.00'),
            interval: '1 month',
            description: 'Sandbox idempotent '.$key,
            startDate: new Date(CarbonImmutable::now('UTC')->addDays(30)),
            mandateId: $ids['mandateId'],
        );

        $first = \GraystackIT\MollieBilling\Support\MollieCall::send($request(), $key);
        $this->trackMollieResource('subscription', (string) $first->id, $ids['customerId']);
        $second = \GraystackIT\MollieBilling\Support\MollieCall::send($request(), $key);

        $this->assertSame((string) $first->id, (string) $second->id);
    }

    // ── The package's replacement, against the real API ───────────────────────

    /** @return array{0: TestBillable, 1: string, 2: array{customerId: string, mandateId: string}} */
    private function billableWithSubscription(): array
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Replacement');

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Replacement Co',
            'email' => 'sandbox-replacement-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $metadata = [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'pro',
            'interval' => 'monthly',
        ];

        $subscription = Mollie::send(new CreateSubscriptionRequest(
            customerId: $ids['customerId'],
            amount: new Money('EUR', '34.51'),
            interval: '1 month',
            description: 'pro subscription',
            startDate: new Date(CarbonImmutable::now('UTC')->addDays(5)),
            metadata: $metadata,
            webhookUrl: route(BillingRoute::webhook()),
            mandateId: $ids['mandateId'],
        ));
        $this->trackMollieResource('subscription', (string) $subscription->id, $ids['customerId']);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => now(),
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'subscription_meta' => ['mollie_subscription_id' => (string) $subscription->id],
        ])->save();

        return [$billable->refresh(), (string) $subscription->id, $ids];
    }

    public function test_the_package_replaces_a_subscription_with_everything_kept_and_the_new_start_date(): void
    {
        [$billable, $oldId, $ids] = $this->billableWithSubscription();
        $old = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $oldId));
        $target = CarbonImmutable::now('UTC')->startOfDay()->addDays(40);

        $newId = MollieSubscriptionReplacer::withLock($billable, fn () => app(MollieSubscriptionReplacer::class)->replace($billable, $target, [], 'sandbox'));
        $this->assertIsString($newId);
        $this->trackMollieResource('subscription', $newId, $ids['customerId']);

        $this->assertSame('canceled', (string) Mollie::send(new GetSubscriptionRequest($ids['customerId'], $oldId))->status);

        $new = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $newId));

        $this->assertSame('active', (string) $new->status);
        $this->assertSame($target->toDateString(), (string) $new->startDate);
        $this->assertSame($target->toDateString(), (string) $new->nextPaymentDate);
        $this->assertSame((string) $old->amount->value, (string) $new->amount->value);
        $this->assertSame((string) $old->interval, (string) $new->interval);
        $this->assertSame((string) $old->description, (string) $new->description);
        $this->assertSame((string) $old->mandateId, (string) $new->mandateId);
        $this->assertSame((string) $old->webhookUrl, (string) $new->webhookUrl);
        $this->assertEquals(json_decode((string) json_encode($old->metadata), true), json_decode((string) json_encode($new->metadata), true));

        $meta = $billable->refresh()->getBillingSubscriptionMeta();
        $this->assertSame($newId, $meta['mollie_subscription_id']);
        $this->assertArrayNotHasKey(MollieSubscriptionReplacer::META_KEY, $meta);
    }

    public function test_a_replacement_whose_create_answer_was_lost_is_finished_without_a_second_subscription(): void
    {
        [$billable, $oldId, $ids] = $this->billableWithSubscription();
        $target = CarbonImmutable::now('UTC')->startOfDay()->addDays(40);

        $newId = MollieSubscriptionReplacer::withLock($billable, fn () => app(MollieSubscriptionReplacer::class)->replace($billable, $target, [], 'sandbox'));
        $this->trackMollieResource('subscription', (string) $newId, $ids['customerId']);
        $old = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $oldId));
        $new = Mollie::send(new GetSubscriptionRequest($ids['customerId'], (string) $newId));

        // The state a crash after the create, before its answer was stored, leaves behind:
        // the old id on the row, the marker saying the old one is cancelled.
        $billable->forceFill(['subscription_meta' => [
            'mollie_subscription_id' => $oldId,
            MollieSubscriptionReplacer::META_KEY => [
                'reason' => 'sandbox',
                'mollie_customer_id' => $ids['customerId'],
                'old_subscription_id' => $oldId,
                'old_cancelled' => true,
                'start_date' => $target->toDateString(),
                'amount' => ['currency' => 'EUR', 'value' => (string) $old->amount->value],
                'interval' => (string) $old->interval,
                'description' => (string) $old->description,
                'metadata' => json_decode((string) json_encode($old->metadata), true),
                'mandate_id' => (string) $old->mandateId,
                'webhook_url' => (string) $old->webhookUrl,
                'first_attempt_at' => now()->toIso8601String(),
            ],
        ]])->save();

        $resumed = MollieSubscriptionReplacer::withLock($billable->refresh(), fn () => app(MollieSubscriptionReplacer::class)->resume($billable));

        $this->assertSame((string) $new->id, $resumed, 'The retry adopts the replacement instead of creating another.');

        $active = [];
        foreach (Mollie::send(new GetPaginatedSubscriptionsRequest($ids['customerId'])) as $subscription) {
            if ((string) $subscription->status === 'active') {
                $active[] = (string) $subscription->id;
            }
        }

        $this->assertSame([(string) $new->id], $active, 'Exactly one subscription charges the customer.');
        $this->assertSame((string) $new->id, $billable->refresh()->getBillingSubscriptionMeta()['mollie_subscription_id']);
    }
}

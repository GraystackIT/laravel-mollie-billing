<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use GraystackIT\MollieBilling\Support\MollieCall;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * What Mollie does with an idempotency key, and the other answers the fakes model —
 * measured on 26 November 2026 instead of taken from the reference.
 *
 * The test Mollies (`FakeMollieHttpAdapter`, `MollieIdempotencyMemory`, `UsageLongRunHarness`)
 * each answer a reused key in their own way, and the code comments named a status nobody had
 * seen. Each case here sends exactly what the question needs and asserts the answer Mollie
 * gave on that day, so a change on Mollie's side turns this red instead of the fakes quietly
 * drifting from the API they stand for. Every answer is also printed as a `MEASURED` line.
 *
 * What cannot be measured here: a key whose first request met a 5xx (no request provokes one
 * on demand), and the one-hour lifetime of a key (a run would have to wait an hour). Refund
 * keys need a settled payment and are measured in `tests/SandboxBrowser`.
 */
class MollieIdempotencyContractTest extends SandboxTestCase
{
    /**
     * Send a request and return Mollie's answer as data, a refusal included.
     *
     * @return array{ok: bool, status: int, title?: string|null, detail?: string|null, field?: string|null, resource?: object}
     */
    private function attempt(object $request, ?string $key = null): array
    {
        try {
            $resource = MollieCall::send($request, $key);

            return ['ok' => true, 'status' => 0, 'id' => (string) ($resource->id ?? ''), 'resource' => $resource];
        } catch (RequestException $e) {
            $body = $e->getResponse()->json();

            return [
                'ok' => false,
                'status' => $e->getStatusCode(),
                'title' => $body->title ?? null,
                'detail' => $body->detail ?? null,
                'field' => $body->field ?? null,
            ];
        }
    }

    /** @param array<string, mixed> $measured */
    private function record(string $what, array $measured): void
    {
        $printable = array_map(
            static fn (mixed $v): mixed => is_array($v) ? array_diff_key($v, ['resource' => true]) : $v,
            $measured,
        );

        fwrite(STDERR, "\nMEASURED {$what}: ".json_encode($printable, JSON_UNESCAPED_SLASHES)."\n");
    }

    private function recurringPayment(array $ids, string $value, string $description = 'Idempotency contract'): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            description: $description,
            amount: new Money('EUR', $value),
            metadata: ['type' => 'sandbox-idempotency'],
            sequenceType: 'recurring',
            mandateId: $ids['mandateId'],
            customerId: $ids['customerId'],
        );
    }

    private function subscription(string $customerId, string $value, string $description): CreateSubscriptionRequest
    {
        return new CreateSubscriptionRequest(
            customerId: $customerId,
            amount: new Money('EUR', $value),
            interval: '1 month',
            description: $description,
            startDate: now()->addMonth()->toDateString(),
        );
    }

    private static function key(string $what): string
    {
        return 'sandbox-'.$what.':'.bin2hex(random_bytes(6));
    }

    /** (a) POST /payments: the same key with another amount. */
    public function test_the_same_key_with_other_parameters_on_a_payment_is_refused_with_a_400(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $key = self::key('payment-params');

        $first = $this->attempt($this->recurringPayment($ids, '1.00'), $key);
        $this->assertTrue($first['ok']);
        $this->trackMollieResource('payment', $first['id'], $ids['customerId']);

        $other = $this->attempt($this->recurringPayment($ids, '2.00'), $key);
        $this->record('payment_same_key_other_amount', ['answer' => $other]);

        $this->assertFalse($other['ok'], 'Mollie took a reused key with another amount.');
        $this->assertSame(400, $other['status']);
        $this->assertStringContainsString('used before with different parameters', (string) $other['detail']);
    }

    /** (a) POST /customers/{id}/subscriptions: the same key with another amount. */
    public function test_the_same_key_with_other_parameters_on_a_subscription_is_refused_with_a_400(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $key = self::key('subscription-params');
        $description = 'Idempotency params '.$key;

        $first = $this->attempt($this->subscription($ids['customerId'], '10.00', $description), $key);
        $this->assertTrue($first['ok']);
        $this->trackMollieResource('subscription', $first['id'], $ids['customerId']);

        $other = $this->attempt($this->subscription($ids['customerId'], '11.00', $description), $key);
        $this->record('subscription_same_key_other_amount', ['answer' => $other]);

        $this->assertFalse($other['ok'], 'Mollie took a reused key with another amount.');
        $this->assertSame(400, $other['status']);
        $this->assertStringContainsString('used before with different parameters', (string) $other['detail']);
    }

    /** (b) The same key on another URL — a payment's key on a subscription, one customer's on another's. */
    public function test_the_same_key_on_another_url_is_refused_with_a_400(): void
    {
        $a = $this->sandboxCustomerWithMandate('Sandbox A');
        $b = $this->sandboxCustomerWithMandate('Sandbox B');

        $key = self::key('url');
        $payment = $this->attempt($this->recurringPayment($a, '1.00'), $key);
        $this->assertTrue($payment['ok']);
        $this->trackMollieResource('payment', $payment['id'], $a['customerId']);

        $onSubscriptions = $this->attempt($this->subscription($a['customerId'], '1.00', 'Idempotency url '.$key), $key);

        $key2 = self::key('url-customer');
        $description = 'Idempotency url customer '.$key2;
        $onA = $this->attempt($this->subscription($a['customerId'], '1.00', $description), $key2);
        $this->assertTrue($onA['ok']);
        $onB = $this->attempt($this->subscription($b['customerId'], '1.00', $description), $key2);

        $this->record('same_key_other_url', ['payment_then_subscription' => $onSubscriptions, 'customer_a_then_b' => $onB]);

        $this->assertFalse($onSubscriptions['ok']);
        $this->assertSame(400, $onSubscriptions['status']);
        $this->assertFalse($onB['ok']);
        $this->assertSame(400, $onB['status']);
        $this->assertStringContainsString('used before on a different URL', (string) $onB['detail']);
    }

    /**
     * (c) Does a key bind when its first request was refused?
     *
     * The same description twice is refused while the first subscription is active and taken
     * once it is cancelled (measured 17 November 2026). So the refused create is sent again,
     * byte for byte with the same key, after the condition has gone: a replayed refusal means
     * the 4xx bound the key; a new subscription means it did not (the control, the same request
     * with a fresh key, is taken). A different request with the key that was refused tells the
     * same from the other side.
     */
    public function test_a_refused_request_binds_its_key_and_is_replayed(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $description = 'Idempotency refused '.bin2hex(random_bytes(4));

        $blocker = $this->attempt($this->subscription($ids['customerId'], '5.00', $description));
        $this->assertTrue($blocker['ok']);

        $key = self::key('refused');
        $refused = $this->attempt($this->subscription($ids['customerId'], '5.00', $description), $key);
        $this->assertFalse($refused['ok'], 'The duplicate description was taken — the premise of this measurement is gone.');

        Mollie::send(new CancelSubscriptionRequest($ids['customerId'], $blocker['id']));

        $retried = $this->attempt($this->subscription($ids['customerId'], '5.00', $description), $key);

        // The control: the same request with a fresh key is taken now — so a refusal of the
        // retry above was the first answer replayed, not a refusal on the merits.
        $control = $this->attempt($this->subscription($ids['customerId'], '5.00', $description), self::key('control'));

        // And from the other side: a request with other parameters, sent with a key whose
        // only use so far was a refusal (an invalid mandate id → 4xx).
        $key2 = self::key('refused-then-other');
        $invalid = $this->attempt(new CreatePaymentRequest(
            description: 'Idempotency refused payment',
            amount: new Money('EUR', '1.00'),
            sequenceType: 'recurring',
            mandateId: 'mdt_doesnotexist',
            customerId: $ids['customerId'],
        ), $key2);
        $valid = $this->attempt($this->recurringPayment($ids, '1.00'), $key2);

        if ($valid['ok']) {
            $this->trackMollieResource('payment', $valid['id'], $ids['customerId']);
        }

        $this->record('refused_request_and_its_key', [
            'refused' => $refused,
            'identical_retry_after_condition_gone' => $retried,
            'same_request_fresh_key' => ['ok' => $control['ok'], 'status' => $control['status'], 'detail' => $control['detail'] ?? null],
            'invalid_payment' => $invalid,
            'other_request_same_key' => $valid,
        ]);

        // Measured 26 November 2026: a 4xx binds the key like a 2xx does. The identical retry
        // is answered with the first refusal although the condition has gone, and the key
        // cannot be used for anything else for as long as Mollie keeps it.
        $this->assertTrue($control['ok'], 'The control failed — the description is still blocked, so the retry proves nothing.');
        $this->assertFalse($retried['ok'], 'Mollie executed the retry: a refused request no longer binds its key.');
        $this->assertSame(422, $retried['status']);
        $this->assertSame($refused['detail'], $retried['detail']);
        $this->assertFalse($invalid['ok']);
        $this->assertSame(422, $invalid['status']);
        $this->assertFalse($valid['ok'], 'A key whose first request was refused was taken for another request.');
        $this->assertSame(400, $valid['status']);
        $this->assertStringContainsString('used before with different parameters', (string) $valid['detail']);
    }

    /** (d) A replay is the response as it was then, or the resource as it is now? */
    public function test_a_replay_is_the_first_answer_not_the_resource_as_it_is_now(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $key = self::key('replay-state');
        $request = fn (): CreateSubscriptionRequest => $this->subscription($ids['customerId'], '10.00', 'Idempotency replay '.$key);

        $first = $this->attempt($request(), $key);
        $this->assertTrue($first['ok']);

        Mollie::send(new UpdateSubscriptionRequest($ids['customerId'], $first['id'], amount: new Money('EUR', '12.00')));
        Mollie::send(new CancelSubscriptionRequest($ids['customerId'], $first['id']));

        $replay = $this->attempt($request(), $key);
        $live = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $first['id']));

        $this->record('replay_after_change', [
            'replay_id' => $replay['id'] ?? null,
            'replay_amount' => $replay['resource']->amount->value ?? null,
            'replay_status' => $replay['resource']->status ?? null,
            'live_amount' => $live->amount->value,
            'live_status' => $live->status,
        ]);

        $this->assertTrue($replay['ok']);
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame('canceled', (string) $live->status);
        $this->assertSame('10.00', (string) $replay['resource']->amount->value, 'The replay showed the resource as it is now, not the first answer.');
        $this->assertSame('active', (string) $replay['resource']->status, 'The replay showed the resource as it is now, not the first answer.');
    }

    /**
     * The fakes key PATCH and DELETE as well as POST. Does Mollie?
     *
     * The SDK puts a key on every mutating request, so a PATCH and a DELETE carry one too.
     */
    public function test_patch_and_delete_with_a_reused_key(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $created = $this->attempt($this->subscription($ids['customerId'], '10.00', 'Idempotency patch '.bin2hex(random_bytes(4))));
        $this->assertTrue($created['ok']);
        $id = $created['id'];

        $key = self::key('patch');
        $patch = fn (string $v): UpdateSubscriptionRequest => new UpdateSubscriptionRequest($ids['customerId'], $id, amount: new Money('EUR', $v));

        $patch1 = $this->attempt($patch('12.00'), $key);
        $patchSame = $this->attempt($patch('12.00'), $key);
        $patchOther = $this->attempt($patch('13.00'), $key);
        $afterPatches = (string) Mollie::send(new GetSubscriptionRequest($ids['customerId'], $id))->amount->value;

        // Replayed or executed? Move the amount with a fresh key, then repeat the first PATCH
        // with its key: a replay leaves 15.00, an execution writes 12.00.
        $this->attempt($patch('15.00'));
        $patchRepeat = $this->attempt($patch('12.00'), $key);
        $afterRepeat = (string) Mollie::send(new GetSubscriptionRequest($ids['customerId'], $id))->amount->value;

        $key2 = self::key('delete');
        $delete1 = $this->attempt(new CancelSubscriptionRequest($ids['customerId'], $id), $key2);
        $delete2 = $this->attempt(new CancelSubscriptionRequest($ids['customerId'], $id), $key2);
        $deleteNoKey = $this->attempt(new CancelSubscriptionRequest($ids['customerId'], $id));

        $this->record('patch_and_delete_keys', [
            'patch_first' => ['ok' => $patch1['ok'], 'amount' => $patch1['resource']->amount->value ?? null],
            'patch_same_key_same_body' => ['ok' => $patchSame['ok'], 'status' => $patchSame['status'], 'detail' => $patchSame['detail'] ?? null],
            'patch_same_key_other_body' => ['ok' => $patchOther['ok'], 'status' => $patchOther['status'], 'detail' => $patchOther['detail'] ?? null],
            'amount_after_patches' => $afterPatches,
            'patch_repeat_ok' => $patchRepeat['ok'],
            'amount_after_repeat' => $afterRepeat,
            'delete_first' => ['ok' => $delete1['ok'], 'status' => $delete1['status']],
            'delete_same_key' => ['ok' => $delete2['ok'], 'status' => $delete2['status'], 'detail' => $delete2['detail'] ?? null, 'sub_status' => $delete2['resource']->status ?? null],
            'delete_fresh_key' => ['ok' => $deleteNoKey['ok'], 'status' => $deleteNoKey['status'], 'detail' => $deleteNoKey['detail'] ?? null],
        ]);

        // Measured 26 November 2026: a PATCH is executed whatever key it carries — the same
        // key with another body is taken, and a repeat is applied again, not replayed.
        $this->assertTrue($patch1['ok']);
        $this->assertTrue($patchOther['ok'], 'Mollie refused a PATCH for its reused key.');
        $this->assertSame('13.00', $afterPatches);
        $this->assertSame('12.00', $afterRepeat, 'Mollie replayed a PATCH for its reused key.');

        // A DELETE with a reused key is replayed: the second cancel answers 200 with the
        // cancelled subscription, where a fresh key gets the 422 of a second cancel.
        $this->assertTrue($delete1['ok']);
        $this->assertTrue($delete2['ok'], 'A repeated DELETE with the same key was not replayed.');
        $this->assertFalse($deleteNoKey['ok']);
        $this->assertSame(422, $deleteNoKey['status']);
    }

    /**
     * (e) Can an open payment be cancelled through the API?
     *
     * `PendingFirstPayment::withdraw()` cancels the open payment of an order the customer has
     * since changed. Measured for a first payment that has picked no method, and for ones
     * created with a method.
     */
    public function test_which_open_payments_can_be_cancelled(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $answers = [];

        foreach ([null, 'creditcard', 'eps', 'banktransfer'] as $method) {
            $created = $this->attempt(new CreatePaymentRequest(
                description: 'Cancel probe '.($method ?? 'no method'),
                amount: new Money('EUR', '1.00'),
                redirectUrl: 'https://example.com/return',
                method: $method,
                sequenceType: 'first',
                customerId: $ids['customerId'],
            ));

            if (! $created['ok']) {
                $answers[$method ?? 'none'] = ['create' => $created];

                continue;
            }

            $payment = Mollie::send(new GetPaymentRequest($created['id']));
            $cancel = $this->attempt(new CancelPaymentRequest($created['id']));
            $after = Mollie::send(new GetPaymentRequest($created['id']));

            $answers[$method ?? 'none'] = [
                'status_before' => (string) $payment->status,
                'isCancelable' => $payment->isCancelable ?? null,
                'cancel' => ['ok' => $cancel['ok'], 'status' => $cancel['status'], 'detail' => $cancel['detail'] ?? null],
                'status_after' => (string) $after->status,
            ];
        }

        // The €0.00 mandate capture of a trial — what `PendingFirstPayment` withdraws when the
        // customer comes back from the hosted page and picks a plan without a trial.
        $zero = $this->attempt(new CreatePaymentRequest(
            description: 'Cancel probe zero',
            amount: new Money('EUR', '0.00'),
            redirectUrl: 'https://example.com/return',
            sequenceType: 'first',
            customerId: $ids['customerId'],
        ));
        $this->assertTrue($zero['ok'], 'Mollie refused the €0.00 first payment.');
        $zeroBefore = Mollie::send(new GetPaymentRequest($zero['id']));
        $zeroCancel = $this->attempt(new CancelPaymentRequest($zero['id']));
        $answers['zero'] = [
            'status_before' => (string) $zeroBefore->status,
            'isCancelable' => $zeroBefore->isCancelable ?? null,
            'cancel' => ['ok' => $zeroCancel['ok'], 'status' => $zeroCancel['status'], 'detail' => $zeroCancel['detail'] ?? null],
            'status_after' => (string) Mollie::send(new GetPaymentRequest($zero['id']))->status,
        ];

        // And a recurring direct-debit payment on the mandate — what the overage and prorata
        // clean-ups cancel.
        $recurring = $this->attempt($this->recurringPayment($ids, '1.00', 'Cancel probe recurring'));
        $this->assertTrue($recurring['ok']);
        $before = Mollie::send(new GetPaymentRequest($recurring['id']));
        $cancel = $this->attempt(new CancelPaymentRequest($recurring['id']));
        $after = Mollie::send(new GetPaymentRequest($recurring['id']));
        $answers['recurring directdebit'] = [
            'method' => (string) ($before->method ?? ''),
            'status_before' => (string) $before->status,
            'isCancelable' => $before->isCancelable ?? null,
            'cancel' => ['ok' => $cancel['ok'], 'status' => $cancel['status'], 'detail' => $cancel['detail'] ?? null],
            'status_after' => (string) $after->status,
        ];

        $this->record('cancel_open_payment', $answers);

        // Measured 26 November 2026: no open first payment can be cancelled through the API —
        // not before a method is picked, not with one. `isCancelable` says so up front.
        foreach (['none', 'creditcard', 'eps', 'zero'] as $method) {
            $this->assertSame('open', $answers[$method]['status_before']);
            $this->assertFalse($answers[$method]['isCancelable']);
            $this->assertFalse($answers[$method]['cancel']['ok'], "An open first payment ({$method}) was cancelled.");
            $this->assertSame(422, $answers[$method]['cancel']['status']);
            $this->assertSame('open', $answers[$method]['status_after']);
        }

        // A pending recurring direct debit can: `isCancelable: true`, and the cancel takes.
        $this->assertSame('pending', $answers['recurring directdebit']['status_before']);
        $this->assertTrue($answers['recurring directdebit']['isCancelable']);
        $this->assertTrue($answers['recurring directdebit']['cancel']['ok'], 'A pending recurring direct debit could not be cancelled.');
        $this->assertSame('canceled', $answers['recurring directdebit']['status_after']);
    }

    /** (g) Revoking a mandate cancels the subscriptions that charge it? And how does it read back? */
    public function test_revoking_a_mandate_and_its_subscription(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $created = $this->attempt(new CreateSubscriptionRequest(
            customerId: $ids['customerId'],
            amount: new Money('EUR', '10.00'),
            interval: '1 month',
            description: 'Revoke probe '.bin2hex(random_bytes(4)),
            startDate: now()->addMonth()->toDateString(),
            mandateId: $ids['mandateId'],
        ));
        $this->assertTrue($created['ok']);

        Mollie::send(new RevokeMandateRequest($ids['customerId'], $ids['mandateId']));

        $subscription = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $created['id']));
        $mandate = $this->attempt(new GetMandateRequest($ids['customerId'], $ids['mandateId']));

        $this->record('revoked_mandate', [
            'subscription_status' => (string) $subscription->status,
            'subscription_canceled_at' => $subscription->canceledAt ?? null,
            'subscription_mandate' => $subscription->mandateId ?? null,
            'mandate_read' => $mandate['ok'] ? ['ok' => true, 'status' => $mandate['resource']->status ?? null] : $mandate,
        ]);

        // Measured 26 November 2026: revoking the mandate cancels the subscription at once,
        // and the revoked mandate reads back as 410 Gone.
        $this->assertSame('canceled', (string) $subscription->status);
        $this->assertNotNull($subscription->canceledAt ?? null);
        $this->assertFalse($mandate['ok']);
        $this->assertSame(410, $mandate['status']);
    }
}

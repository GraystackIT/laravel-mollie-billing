<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld;
use GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory;
use GraystackIT\MollieBilling\Tests\Support\MollieIdempotencyMemory;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;

require_once __DIR__.'/../Webhook/Support/RenewalLongRun.php';

/**
 * The feature suite's stateful Mollie doubles honour idempotency keys by default, like the
 * workbench fake and the real API (decision 15 of 26 November 2026; the sandbox measured a
 * replay for a repeated key, a refusal for other parameters, and 400 for another URL).
 *
 * They used to take every request as a new one, so a retry that should have been replayed —
 * or refused — passed as a second payment or refund.
 *
 * Re-measured on 26 November 2026 (`tests/Sandbox/MollieIdempotencyContractTest`): other
 * parameters are a 400 like another URL, a refused (4xx) request binds its key, and a PATCH is
 * not keyed at all.
 */
dataset('idempotent worlds', [
    // `?? null`: the first case also runs against a world without the memory, where it is red.
    'ChangeMatrixWorld' => [function (): ?MollieIdempotencyMemory {
        ChangeMatrixWorld::boot();

        return ChangeMatrixWorld::$idempotency ?? null;
    }],
    'BookingMatrix' => [function (): ?MollieIdempotencyMemory {
        return BookingMatrix::boot()->idempotency ?? null;
    }],
    'RenewalLongRun' => [function (): ?MollieIdempotencyMemory {
        longRunBoot();
        config()->set('mollie-billing.billable_model', TestBillable::class);
        LongRunWorld::$current = new LongRunWorld(TestBillable::create(['name' => 'Idem', 'email' => uniqid('idem-').'@x.test']), 'monthly');

        return LongRunWorld::$current->idempotency ?? null;
    }],
]);

function idempotentWorldPayment(string $value): CreatePaymentRequest
{
    return new CreatePaymentRequest(
        description: 'Idempotency in the worlds',
        amount: new Money('EUR', $value),
        metadata: ['type' => 'overage'],
        sequenceType: 'recurring',
        mandateId: 'mdt_idem',
        customerId: 'cst_idem',
    );
}

function idempotentWorldRefund(string $paymentId): CreatePaymentRefundRequest
{
    return new CreatePaymentRefundRequest(paymentId: $paymentId, description: 'Idempotency refund', amount: new Money('EUR', '1.00'));
}

function idempotentWorldRefusal(callable $call): ApiException
{
    try {
        $call();
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('The world took the request.');
}

it('answers a repeated key with the first answer', function (Closure $boot): void {
    $boot();

    $first = MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:payment');
    $again = MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:payment');

    expect((string) $again->id)->toBe((string) $first->id);

    // Without a key every request is its own.
    $third = MollieCall::send(idempotentWorldPayment('1.00'));
    expect((string) $third->id)->not->toBe((string) $first->id);
})->with('idempotent worlds');

it('refuses a reused key with other parameters, and on another URL', function (Closure $boot): void {
    $memory = $boot();

    MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:amount');
    // Measured 26 November 2026: 400 "used before with different parameters", not 422.
    expect(idempotentWorldRefusal(fn () => MollieCall::send(idempotentWorldPayment('2.00'), 'world-idem:amount'))->getCode())->toBe(400);

    MollieCall::send(idempotentWorldRefund('tr_one'), 'world-idem:refund');
    expect(idempotentWorldRefusal(fn () => MollieCall::send(idempotentWorldRefund('tr_two'), 'world-idem:refund'))->getCode())->toBe(400);

    expect($memory->conflicts)->toBe(2);
})->with('idempotent worlds');

it('forgets a key after the hour, and can be switched off', function (Closure $boot): void {
    $memory = $boot();

    $first = MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:hour');
    test()->travel(61)->minutes();
    $later = MollieCall::send(idempotentWorldPayment('2.00'), 'world-idem:hour');
    expect((string) $later->id)->not->toBe((string) $first->id);

    $memory->honour = false;
    $a = MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:off');
    $b = MollieCall::send(idempotentWorldPayment('1.00'), 'world-idem:off');
    expect((string) $b->id)->not->toBe((string) $a->id);
})->with('idempotent worlds');

it('binds a key to a refusal, not to an outage, and leaves a PATCH unkeyed', function (): void {
    // Measured 26 November 2026, against the memory itself: no world refuses on demand.
    $memory = new MollieIdempotencyMemory;
    $refuse = fn (int $status) => function () use ($status): never {
        throw MollieApiExceptionFactory::withStatus($status, "[{$status}] refused");
    };
    $take = fn (): object => (object) ['id' => 'tr_taken'];

    // A 4xx binds: the retry is answered with the refusal, another request with the key is a 400.
    $memory->setKey('mem:refused');
    expect(fn () => $memory->answer(idempotentWorldPayment('1.00'), $refuse(422)))->toThrow(ApiException::class);
    $memory->setKey('mem:refused');
    expect(idempotentWorldRefusal(fn () => $memory->answer(idempotentWorldPayment('1.00'), $take))->getCode())->toBe(422);
    $memory->setKey('mem:refused');
    expect(idempotentWorldRefusal(fn () => $memory->answer(idempotentWorldPayment('2.00'), $take))->getCode())->toBe(400);

    // A 5xx does not bind (not measurable; an outage lever must not stick to its retry).
    $memory->setKey('mem:outage');
    expect(fn () => $memory->answer(idempotentWorldPayment('1.00'), $refuse(503)))->toThrow(ApiException::class);
    $memory->setKey('mem:outage');
    expect($memory->answer(idempotentWorldPayment('1.00'), $take)->id)->toBe('tr_taken');

    // A PATCH is executed whatever key it carries.
    $patched = [];
    $patch = function (string $value) use ($memory, &$patched): void {
        $memory->setKey('mem:patch');
        $memory->answer(
            new UpdateSubscriptionRequest('cst_idem', 'sub_idem', amount: new Money('EUR', $value)),
            function () use ($value, &$patched): object {
                $patched[] = $value;

                return (object) ['id' => 'sub_idem'];
            },
        );
    };
    $patch('12.00');
    $patch('13.00');
    $patch('12.00');
    expect($patched)->toBe(['12.00', '13.00', '12.00']);
});

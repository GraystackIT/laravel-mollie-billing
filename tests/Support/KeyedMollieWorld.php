<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Mockery;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A Mollie for the keyed money paths — refunds and one-off payments — with the three ways a
 * request can fail, as measured on 26 November 2026 (`MollieIdempotencyMemory`):
 *
 *  - `$refuseWith`: Mollie refuses (a 4xx) for as long as the cause stands. The memory binds
 *    the key to that refusal, so a retry with the same key gets it again after the cause is
 *    gone; a new key is taken.
 *  - `$outagesLeft`: Mollie answers 5xx and acts on nothing. Binds no key.
 *  - `$loseNextAnswer`: Mollie acts, the answer never arrives. Binds the key to the act, so a
 *    retry with the same key is replayed rather than acted on twice.
 *
 * `$created` is what Mollie actually holds; `$sentKeys` the key every create carried.
 */
final class KeyedMollieWorld
{
    public MollieIdempotencyMemory $memory;

    /** @var list<object> refunds and payments Mollie created */
    public array $created = [];

    /** @var list<string|null> the key each create request was sent with */
    public array $sentKeys = [];

    public ?int $refuseWith = null;

    public string $refusalDetail = 'The amount is higher than the balance available';

    public int $outagesLeft = 0;

    public bool $loseNextAnswer = false;

    /** @var (\Closure(object): mixed)|null answers anything that is not a create */
    public ?\Closure $other = null;

    private ?string $key = null;

    public function __construct()
    {
        $this->memory = new MollieIdempotencyMemory;
    }

    public function install(): self
    {
        $client = Mockery::mock(\Mollie\Api\MollieApiClient::class);

        $client->shouldReceive('setIdempotencyKey')->andReturnUsing(function ($key) use ($client) {
            $this->key = is_string($key) && $key !== '' ? $key : null;
            $this->memory->setKey($this->key);

            return $client;
        });
        $client->shouldReceive('resetIdempotencyKey')->andReturnUsing(function () use ($client) {
            $this->key = null;
            $this->memory->setKey(null);

            return $client;
        });
        $client->shouldReceive('send')->andReturnUsing(function (object $request) {
            $key = $this->key;
            $this->key = null;

            if ($request instanceof CreatePaymentRefundRequest || $request instanceof CreatePaymentRequest) {
                $this->sentKeys[] = $key;
            }

            return $this->memory->answer($request, fn (object $request): mixed => $this->answer($request));
        });

        Mollie::swap($client);

        return $this;
    }

    /** @return list<object> */
    public function refunds(): array
    {
        return array_values(array_filter($this->created, static fn (object $o): bool => str_starts_with((string) $o->id, 're_')));
    }

    /** @return list<object> */
    public function payments(): array
    {
        return array_values(array_filter($this->created, static fn (object $o): bool => str_starts_with((string) $o->id, 'tr_')));
    }

    private function answer(object $request): mixed
    {
        if ($request instanceof GetPaginatedPaymentRefundsRequest) {
            return new \ArrayIterator($this->refunds());
        }

        if (! $request instanceof CreatePaymentRefundRequest && ! $request instanceof CreatePaymentRequest) {
            if ($this->other !== null) {
                return ($this->other)($request);
            }

            throw new \RuntimeException('KeyedMollieWorld: unexpected '.$request::class);
        }

        if ($this->refuseWith !== null) {
            throw MollieApiExceptionFactory::withStatus(
                $this->refuseWith,
                'Error executing API call ('.$this->refuseWith.': Unprocessable Entity): '.$this->refusalDetail,
            );
        }

        if ($this->outagesLeft > 0) {
            $this->outagesLeft--;

            throw MollieApiExceptionFactory::withStatus(503, 'Error executing API call (503: Service Unavailable)');
        }

        $read = static fn (string $property): mixed => (new \ReflectionObject($request))->getProperty($property)->getValue($request);
        $amount = $read('amount');
        $metadata = $read('metadata');
        $isRefund = $request instanceof CreatePaymentRefundRequest;

        $object = (object) [
            'id' => ($isRefund ? 're_' : 'tr_').(count($this->created) + 1),
            'status' => $isRefund ? 'pending' : 'open',
            'amount' => (object) ['value' => $amount->value, 'currency' => $amount->currency],
            'metadata' => $metadata === null ? null : (object) $metadata,
        ];

        $this->created[] = $object;

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;
            $this->memory->answerLost($object);

            throw new \RuntimeException('cURL error 28: Operation timed out after 10000 milliseconds');
        }

        return $object;
    }
}

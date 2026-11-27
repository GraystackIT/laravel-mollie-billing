<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Carbon\Carbon;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Request;

/**
 * Mollie's idempotency, for the feature suite's facade doubles of Mollie (the stateful
 * "worlds": `ChangeMatrixWorld`, `BookingMatrix`, `RenewalLongRun`).
 *
 * The same rule the workbench fake applies by default (`FakeMollieHttpAdapter`), as the sandbox
 * measured the real API (`tests/Sandbox/MollieIdempotencyContractTest`, 26 November 2026): a
 * POST or DELETE whose idempotency key was seen within the hour is answered with the first
 * request's answer — the world is not asked again — while the same key on another URL or with
 * other parameters is refused with a 400. A refusal (a 4xx the world throws) binds the key like
 * an answer: the retry is refused again. An outage (anything else the world throws) binds
 * nothing, so a retry after it is a new request — unless the world says Mollie acted and only
 * the answer was lost ({@see self::answerLost()}). A PATCH is not keyed: Mollie applies a
 * repeat again.
 *
 * The facade double has to route `setIdempotencyKey()` into {@see self::setKey()} and every
 * `send()` through {@see self::answer()}. A key belongs to the one request after it:
 * `MollieCall::send()` clears it on the real client, which the double does not model.
 *
 * Switched on by default; a test that deliberately sends one key twice for two operations
 * sets `$honour = false` on the world's memory.
 */
final class MollieIdempotencyMemory
{
    /** How long Mollie keeps a key (measured: one hour). */
    public const TTL_MINUTES = 60;

    public bool $honour = true;

    /** Replays answered from memory — what a test reads to tell a key was reused. */
    public int $replays = 0;

    /** Refusals for a key reused on another URL or with other parameters. */
    public int $conflicts = 0;

    private ?string $pendingKey = null;

    /** @var array{key: string, path: string, fingerprint: string}|null the keyed request being answered */
    private ?array $inFlight = null;

    /** @var array<string, array{path: string, fingerprint: string, answer: mixed, refusal?: \Throwable, at: string}> */
    private array $seen = [];

    public function setKey(mixed $key): void
    {
        $this->pendingKey = is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param  callable(object): mixed  $next  the world's own answer to the request
     * @param  (callable(object): void)|null  $replayed  told about a request answered from
     *                                                   memory — it was sent all the same
     */
    public function answer(object $request, callable $next, ?callable $replayed = null): mixed
    {
        $key = $this->pendingKey;
        $this->pendingKey = null;

        if ($key === null || ! $this->honour || ! $this->isMutating($request)) {
            return $next($request);
        }

        $path = $request instanceof Request ? $request->getMethod().' '.$request->resolveResourcePath() : $request::class;
        $fingerprint = $this->fingerprintOf($request);
        $entry = $this->seen[$key] ?? null;

        if ($entry !== null && Carbon::parse($entry['at'], 'UTC')->addMinutes(self::TTL_MINUTES)->greaterThan(Carbon::now('UTC'))) {
            if ($entry['path'] !== $path) {
                $this->conflicts++;

                throw $this->refusal(400, 'You are using an idempotency key that you used before on a different URL.');
            }

            if ($entry['fingerprint'] !== $fingerprint) {
                $this->conflicts++;

                throw $this->refusal(400, 'You are using an idempotency key that you used before with different parameters.');
            }

            $this->replays++;

            if ($replayed !== null) {
                $replayed($request);
            }

            if (isset($entry['refusal'])) {
                throw $entry['refusal'];
            }

            return $entry['answer'];
        }

        $this->inFlight = ['key' => $key, 'path' => $path, 'fingerprint' => $fingerprint];

        try {
            $answer = $next($request);
        } catch (ApiException $e) {
            // A refusal binds the key; an outage does not (see the class docblock). A key the
            // world already bound during this call (answerLost()) keeps that binding.
            if ($e->getCode() >= 400 && $e->getCode() < 500 && ($this->seen[$key] ?? null) === $entry) {
                $this->remember($key, $path, $fingerprint, null, $e);
            }

            throw $e;
        } finally {
            $this->inFlight = null;
        }

        $this->remember($key, $path, $fingerprint, $answer);

        return $answer;
    }

    /**
     * Mollie acted on the request being answered and its answer never arrived (a timeout): the
     * key is bound to that answer all the same, so a retry with it is replayed. Called by the
     * world from inside `answer()`'s `$next`, right before it throws.
     */
    public function answerLost(mixed $answer): void
    {
        if ($this->inFlight !== null) {
            $this->remember($this->inFlight['key'], $this->inFlight['path'], $this->inFlight['fingerprint'], $answer);
        }
    }

    private function remember(string $key, string $path, string $fingerprint, mixed $answer, ?\Throwable $refusal = null): void
    {
        $this->seen[$key] = array_filter([
            'path' => $path,
            'fingerprint' => $fingerprint,
            'answer' => $answer,
            'refusal' => $refusal,
            'at' => Carbon::now('UTC')->toIso8601String(),
        ], static fn (mixed $value, string $field): bool => $field !== 'refusal' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /** POST and DELETE: Mollie ignores the key on a PATCH (measured 26 November 2026). */
    private function isMutating(object $request): bool
    {
        return $request instanceof Request && in_array($request->getMethod(), ['POST', 'DELETE'], true);
    }

    /** Every parameter the request carries, as Mollie compares them. */
    private function fingerprintOf(object $request): string
    {
        try {
            return sha1(serialize($request));
        } catch (\Throwable) {
            return 'unserializable:'.spl_object_id($request);
        }
    }

    private function refusal(int $status, string $detail): ApiException
    {
        return MollieApiExceptionFactory::withStatus($status, '['.$status.'] '.$detail);
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Concurrency;

use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\Traits\HasDefaultFactories;
use Workbench\App\FakeMollie\FakeMollieHttpAdapter;

/**
 * The browser suite's SQLite-backed fake Mollie, slowed down and logged.
 *
 * The delay is what makes contention certain rather than likely: the process that wins
 * the lock is held inside its critical section — at the Mollie call, exactly where a real
 * request spends its time — for long enough that the other process, released by the same
 * barrier, arrives while it is still there.
 *
 * Every request is appended to `mollie.log` in the shared directory (one JSON line, written
 * under an exclusive lock), so the test can count what reached "Mollie" from both
 * processes together.
 */
final class SlowFakeMollieHttpAdapter implements HttpAdapterContract
{
    use HasDefaultFactories;

    /**
     * @param  list<string>  $slowRequests  short class names of the requests to delay
     */
    public function __construct(
        private readonly FakeMollieHttpAdapter $inner,
        private readonly string $directory,
        private readonly array $slowRequests,
        private readonly int $delayMs,
    ) {
    }

    public function sendRequest(PendingRequest $pendingRequest): Response
    {
        $request = (new \ReflectionClass($pendingRequest->getRequest()))->getShortName();

        file_put_contents($this->directory.'/mollie.log', json_encode([
            'worker' => (string) (getenv('CONCURRENCY_WORKER') ?: 'test'),
            'request' => $request,
            'at' => microtime(true),
        ])."\n", FILE_APPEND | LOCK_EX);

        if (in_array($request, $this->slowRequests, true) && $this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        // One request at a time, like the real API: the workbench fake numbers its objects
        // with a read-then-write counter (WorkbenchSetting::nextId()), which two processes
        // on Postgres can both read before either writes — and then the fake, not the
        // package, fails on a duplicate key. The delay above stays outside the lock.
        $lock = fopen($this->directory.'/fake-mollie.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            return $this->inner->sendRequest($pendingRequest);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function version(): ?string
    {
        return 'SlowFakeMollie/1.0';
    }
}

<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Concurrency;

use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;

/**
 * SyncSeatsJob with the lock wait set per run, through the seam the job provides for it.
 *
 * A copy it puts back on the queue is of this class too (`dispatch()` is `new static`),
 * with the default wait: the property is not a constructor argument.
 */
final class ShortWaitSyncSeatsJob extends SyncSeatsJob
{
    public int $waitSeconds = self::LOCK_WAIT_SECONDS;

    protected function lockWaitSeconds(): int
    {
        return $this->waitSeconds;
    }
}

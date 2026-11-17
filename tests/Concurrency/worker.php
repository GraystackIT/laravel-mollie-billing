<?php

declare(strict_types=1);

/*
 * One side of a concurrency test: boot the shared world, wait at the barrier, run the
 * operation, print the outcome as one JSON line. Started by ConcurrencyWorld::runPair().
 *
 * Usage: php tests/Concurrency/worker.php <scenario> <worker-id> <json-args>
 */

use GraystackIT\MollieBilling\Tests\Concurrency\ConcurrencyWorld;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $scenario, $worker, $args] = $argv + [null, null, null, '{}'];

$app = ConcurrencyWorld::createApplication();

// Touch the connection and the package once before the barrier, so the first query, the
// container resolutions and the autoloader are not what decides who reaches the lock first.
\Illuminate\Support\Facades\DB::select('select 1');
app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class);
app(\GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService::class);
app(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class);

$released = ConcurrencyWorld::barrier((string) $worker);

$result = ConcurrencyWorld::run((string) $scenario, (array) json_decode((string) $args, true));

echo json_encode($result + [
    'worker' => $worker,
    'environment' => $app->environment(),
    // What this process really ran on — asserted by the test, so a run that was meant for
    // Postgres or Redis cannot pass on the defaults.
    'database' => \Illuminate\Support\Facades\DB::connection()->getDriverName(),
    'cache_store' => get_class(\Illuminate\Support\Facades\Cache::store()->getStore()),
    'wallet_lock' => config('wallet.lock.driver'),
    'started' => $released,
    'finished' => microtime(true),
]), "\n";

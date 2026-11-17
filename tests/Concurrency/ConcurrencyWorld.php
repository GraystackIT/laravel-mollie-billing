<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Concurrency;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Process\Process;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The shared world of one concurrency test case, and the two-process harness around it.
 *
 * The test process builds the world (schema, a subscriber, a paid renewal), then
 * {@see runPair()} starts two workers (`worker.php`). Each boots its own application against
 * the same database, cache directory and fake Mollie, waits at a file barrier until the
 * other one is booted too, and only then runs the operation — so both enter the critical
 * section within a millisecond or two of each other. Each prints its outcome as JSON.
 */
final class ConcurrencyWorld
{
    public const ENV_DIRECTORY = 'BILLING_CONCURRENCY_DIR';

    /** `sqlite` (default) or `pgsql` — which database the world lives in. */
    public const ENV_DATABASE = 'BILLING_CONCURRENCY_DB';

    /** The dedicated Postgres database, created on first use. */
    public const PGSQL_DATABASE = 'mollie_billing_concurrency';

    /** How long the slowed Mollie request holds the winner inside its critical section. */
    public const DELAY_MS = 2500;

    /** @return list<class-string> */
    public static function providers(): array
    {
        return [
            \Mpociot\VatCalculator\VatCalculatorServiceProvider::class,
            \Bavix\Wallet\WalletServiceProvider::class,
            \Elegantly\Invoices\InvoiceServiceProvider::class,
            \Spatie\Activitylog\ActivitylogServiceProvider::class,
            \Mollie\Laravel\MollieServiceProvider::class,
            \GraystackIT\MollieBilling\MollieBillingServiceProvider::class,
            ConcurrencyServiceProvider::class,
        ];
    }

    public static function directory(): string
    {
        $directory = self::env(self::ENV_DIRECTORY);

        if ($directory === null) {
            throw new \RuntimeException(self::ENV_DIRECTORY.' is not set.');
        }

        return $directory;
    }

    public static function driver(): string
    {
        return self::env(self::ENV_DATABASE) === 'pgsql' ? 'pgsql' : 'sqlite';
    }

    /** @return array<string, mixed> the `concurrency` connection for the selected driver */
    public static function connection(): array
    {
        if (self::driver() === 'pgsql') {
            return self::pgsqlConnection(self::PGSQL_DATABASE);
        }

        return [
            'driver' => 'sqlite',
            'database' => self::directory().'/database.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 20000,
            'transaction_mode' => 'IMMEDIATE',
        ];
    }

    /**
     * Same variables as tests/Feature/Migrations/WalletMorphPostgresTest.php, except the
     * database, which is this suite's own — `migrate:fresh` drops everything in it.
     *
     * @return array<string, mixed>
     */
    public static function pgsqlConnection(string $database): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::env('BILLING_PG_HOST') ?? '127.0.0.1',
            'port' => (int) (self::env('BILLING_PG_PORT') ?? 5432),
            'username' => self::env('BILLING_PG_USER') ?? 'postgres',
            'password' => self::env('BILLING_PG_PASSWORD') ?? '',
            'database' => $database,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Make sure the Postgres database exists; returns why it cannot be used, or null.
     */
    public static function preparePgsql(): ?string
    {
        $config = self::pgsqlConnection('postgres');

        try {
            $pdo = new \PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=postgres', $config['host'], $config['port']),
                (string) $config['username'],
                (string) $config['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3],
            );

            $exists = $pdo->query("select 1 from pg_database where datname = '".self::PGSQL_DATABASE."'")->fetchColumn();

            if ($exists === false) {
                $pdo->exec('create database '.self::PGSQL_DATABASE);
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** `file` (default) or `redis` — where the cache, and with it every lock, lives. */
    public const ENV_CACHE = 'BILLING_CONCURRENCY_CACHE';

    public static function cacheDriver(): string
    {
        return self::env(self::ENV_CACHE) === 'redis' ? 'redis' : 'file';
    }

    /** @return array<string, mixed> */
    public static function redisConnection(): array
    {
        return [
            'host' => self::env('BILLING_REDIS_HOST') ?? '127.0.0.1',
            'port' => (int) (self::env('BILLING_REDIS_PORT') ?? 6379),
            'password' => self::env('BILLING_REDIS_PASSWORD'),
            'database' => (int) (self::env('BILLING_REDIS_DB') ?? 0),
        ];
    }

    /** Every key of this test case starts with it — the directory name is unique per case. */
    public static function redisPrefix(): string
    {
        return 'billing-concurrency:'.basename(self::directory()).':';
    }

    /** A raw client, outside Laravel; null with the reason when Redis cannot be used. */
    public static function rawRedis(?string &$reason = null): ?\Redis
    {
        if (! extension_loaded('redis')) {
            $reason = 'the phpredis extension is not loaded';

            return null;
        }

        $config = self::redisConnection();

        try {
            $redis = new \Redis;
            $redis->connect($config['host'], $config['port'], 2.0);

            if ($config['password'] !== null) {
                $redis->auth($config['password']);
            }

            $redis->select($config['database']);
            $redis->ping();

            return $redis;
        } catch (\Throwable $e) {
            $reason = $e->getMessage();

            return null;
        }
    }

    /** Remove every key this test case wrote. */
    public static function flushRedisPrefix(): void
    {
        $redis = self::rawRedis();

        if ($redis === null) {
            return;
        }

        $iterator = null;

        do {
            $keys = $redis->scan($iterator, self::redisPrefix().'*', 500);

            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }
        } while ($iterator > 0);
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Boot a worker's application — the same providers the test process runs on. */
    public static function createApplication(): \Illuminate\Foundation\Application
    {
        return Application::create(options: [
            'extra' => [
                'providers' => self::providers(),
                'dont-discover' => ['*'],
            ],
        ]);
    }

    /** The package's, the wallet's and the workbench's migrations, onto the shared file. */
    public static function migrationPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/tests/database/migrations',
            $root.'/workbench/database/migrations',
        ];
    }

    // ── World ────────────────────────────────────────────────────────────────

    /**
     * An active Mollie subscriber on `team` (2 seats), with customer, mandate and
     * subscription at the fake Mollie, whose renewal ten days ago has been booked — so
     * there is a paid invoice for the running period to prorate against or refund.
     */
    public static function subscriberWithPaidRenewal(): TestBillable
    {
        $billable = self::subscriber();

        $payment = FakeMollie::chargeSubscription((string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id']);
        $status = FakeMollie::deliverWebhook($payment['id']);

        if ($status !== 200) {
            throw new \RuntimeException("Seeding the renewal answered {$status}.");
        }

        return $billable->refresh();
    }

    /** An active Mollie subscriber whose next renewal is due ten days ago (not yet charged). */
    public static function subscriber(string $email = 'owner@acme.test'): TestBillable
    {
        $customerId = WorkbenchSetting::nextId('cst');
        DB::table('fake_mollie_customers')->insert([
            'id' => $customerId, 'name' => 'Acme GmbH', 'email' => 'acme@concurrency.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mandateId = FakeMollie::createMandate($customerId);

        $billable = self::customer($email);
        $billable->forceFill([
            'mollie_customer_id' => $customerId,
            'mollie_mandate_id' => $mandateId,
            'subscription_plan_code' => 'team',
            'subscription_interval' => 'monthly',
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => now()->subDays(10),
        ])->save();

        // Base 20.00 + one extra seat 5.00, at 19 % — what CreateSubscription would have sent.
        $subscriptionId = WorkbenchSetting::nextId('sub');
        DB::table('fake_mollie_subscriptions')->insert([
            'id' => $subscriptionId, 'customer_id' => $customerId, 'status' => 'active',
            'amount_value' => '29.75', 'amount_currency' => 'EUR', 'interval' => '1 month',
            'description' => 'Concurrency subscription', 'mandate_id' => $mandateId,
            'metadata' => json_encode([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'plan_code' => 'team',
                'interval' => 'monthly',
            ]),
            'start_date' => now()->subDays(10)->toDateString(),
            'next_payment_date' => now()->subDays(10)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $billable->forceFill(['subscription_meta' => [
            'mollie_subscription_id' => $subscriptionId,
            'seat_count' => 2,
        ]])->save();

        app(WalletUsageService::class)->credit($billable, 'Tokens', 50, 'concurrency_seed');
        app(WalletUsageService::class)->credit($billable, 'SMS', 50, 'concurrency_seed');

        return $billable->refresh();
    }

    /** A customer with a complete billing address and nothing booked yet. */
    public static function customer(string $email): TestBillable
    {
        $billable = new TestBillable;
        $billable->forceFill([
            'name' => 'Acme GmbH',
            'email' => $email,
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country' => 'DE',
            'tax_country_user' => 'DE',
            'tax_country_payment' => 'DE',
        ])->save();

        return $billable->refresh();
    }

    /**
     * Two checkouts of the same customer, both paid: two first payments, two mandates.
     *
     * @return array{0: string, 1: string} the payment ids
     */
    public static function twoPaidFirstPayments(TestBillable $billable): array
    {
        $ids = [];

        foreach ([1, 2] as $_) {
            $result = app(StartSubscriptionCheckout::class)->handle($billable->refresh(), [
                'plan_code' => 'team',
                'interval' => 'monthly',
                'addon_codes' => [],
                'extra_seats' => 0,
                'coupon_code' => null,
                'amount_gross' => 2380,
            ]);

            $id = (string) ($result['payment_id'] ?? '');

            if ($id === '') {
                throw new \RuntimeException('The checkout created no payment: '.json_encode($result));
            }

            FakeMollie::settle($id, 'paid', 'DE');
            $ids[] = $id;
        }

        return [$ids[0], $ids[1]];
    }

    /**
     * A reservation for `$paymentId` that a delivery took and never unwound — its process
     * died — and that is older than the takeover window.
     */
    public static function abandonedReservation(string $paymentId): BillingProcessedWebhook
    {
        $row = new BillingProcessedWebhook;
        $row->forceFill([
            'mollie_payment_id' => $paymentId,
            'event_signature' => BillingProcessedWebhook::pendingSignature($paymentId),
            'received_at' => BillingTime::nowUtc()->subMinutes(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::RESERVATION_TTL_MINUTES + 5),
        ])->save();

        return $row;
    }

    /** A single-payment code with exactly one redemption left. */
    public static function couponWithLastSlot(): Coupon
    {
        return Coupon::create([
            'code' => 'LASTSLOT',
            'name' => 'Last slot',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'max_redemptions' => 2,
            'redemptions_count' => 1,
        ]);
    }

    /** Laravel's `jobs` table, for the case in which a worker puts a job back on the queue. */
    public static function createJobsTable(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    // ── Scenarios (run inside a worker, after the barrier) ───────────────────

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function run(string $scenario, array $args): array
    {
        self::registerPauses((array) ($args['pause'] ?? []));

        if (isset($args['queue'])) {
            config(['queue.default' => (string) $args['queue']]);
        }

        return match ($scenario) {
            // A relative change, the way an app expresses "one more seat": the closure is
            // resolved under the change lock (see UpdateSubscription::update()).
            'add-seat' => self::outcome(fn () => app(UpdateSubscription::class)->update(
                TestBillable::query()->findOrFail($args['billable_id']),
                fn (TestBillable $b): array => ['seats' => $b->getBillingSeatCount() + 1],
            )),
            'refund' => self::outcome(fn () => app(RefundInvoiceService::class)->refundPartially(
                BillingInvoice::query()->findOrFail($args['invoice_id']),
                (int) $args['amount_net'],
                RefundReasonCode::Goodwill,
            )->getKey()),
            'refund-line' => self::outcome(fn () => app(RefundInvoiceService::class)->refundLine(
                BillingInvoice::query()->findOrFail($args['invoice_id']),
                (int) $args['line_index'],
                (int) $args['amount_net'],
                RefundReasonCode::Goodwill,
            )->getKey()),
            'webhook' => ['outcome' => 'http', 'status' => FakeMollie::deliverWebhook((string) $args['payment_id'])],
            // "A member was invited": SyncSeatsJob::byDelta(), run the way a worker runs it.
            'seat-job' => self::outcome(function () use ($args): bool {
                $job = new ShortWaitSyncSeatsJob(TestBillable::class, $args['billable_id'], 0, (int) $args['delta']);
                $job->waitSeconds = (int) ($args['wait_seconds'] ?? SyncSeatsJob::LOCK_WAIT_SECONDS);
                $job->handle(app(SyncSeats::class));

                return true;
            }),
            'usage' => self::outcome(function () use ($args): int {
                $billable = TestBillable::query()->findOrFail($args['billable_id']);
                $billable->recordBillingUsage((string) $args['type'], (int) $args['quantity']);

                return (int) $billable->getWallet((string) $args['type'])?->refresh()->balanceInt;
            }),
            'redeem' => self::outcome(fn () => app(CouponService::class)->redeem(
                Coupon::query()->findOrFail($args['coupon_id']),
                TestBillable::query()->findOrFail($args['billable_id']),
                ['discount_amount_net' => 0],
            )->getKey()),
            default => throw new \InvalidArgumentException("Unknown scenario {$scenario}"),
        };
    }

    /**
     * Widen a race window the package leaves only microseconds wide.
     *
     * Each entry sleeps for `ms` every time a model of `model` is read from the database —
     * after the read, before the code acts on it. That is the moment a guard has to cover:
     * the read of a balance, a counter or a reservation, and the write that depends on it.
     * With the guard in place the other process waits (or is turned away) for as long as
     * this one sleeps; without it, both have read the same value before either writes.
     *
     * @param  list<array{model: class-string<\Illuminate\Database\Eloquent\Model>, ms: int}>  $pauses
     */
    private static function registerPauses(array $pauses): void
    {
        foreach ($pauses as $pause) {
            $ms = (int) $pause['ms'];
            $pause['model']::retrieved(static function () use ($ms): void {
                usleep($ms * 1000);
            });
        }
    }

    /** @return array<string, mixed> */
    private static function outcome(\Closure $operation): array
    {
        try {
            return ['outcome' => 'ok', 'result' => $operation()];
        } catch (\Throwable $e) {
            return ['outcome' => 'exception', 'class' => $e::class, 'message' => $e->getMessage()];
        }
    }

    /**
     * Block until `$parties` workers have reached this point.
     *
     * Each worker announces itself with a file once its application is booted — booting
     * takes far longer than the operation, and is what would otherwise serialise the two.
     */
    public static function barrier(string $worker, int $parties = 2): float
    {
        $directory = self::directory().'/barrier';
        @mkdir($directory, 0755, true);
        touch($directory.'/'.$worker);

        $deadline = microtime(true) + 30;

        while (count(glob($directory.'/*') ?: []) < $parties) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Barrier timed out: the other worker never arrived.');
            }

            usleep(1000);
        }

        return microtime(true);
    }

    // ── Harness (test process) ───────────────────────────────────────────────

    /**
     * Run `$scenario` in two processes at once; returns both workers' JSON outcomes,
     * each with `started`/`finished` timestamps taken after the barrier.
     *
     * @param  array<string, mixed>  $args  for both workers
     * @param  list<string>  $slowRequests
     * @param  array{A?: array<string, mixed>, B?: array<string, mixed>}  $perWorker  merged over `$args`
     * @return array{A: array<string, mixed>, B: array<string, mixed>}
     */
    public static function runPair(string $scenario, array $args, array $slowRequests, array $perWorker = []): array
    {
        @array_map('unlink', glob(self::directory().'/barrier/*') ?: []);

        $processes = [];

        foreach (['A', 'B'] as $worker) {
            $process = new Process(
                [PHP_BINARY, __DIR__.'/worker.php', $scenario, $worker, json_encode(array_merge($args, $perWorker[$worker] ?? []))],
                dirname(__DIR__, 2),
                [
                    self::ENV_DIRECTORY => self::directory(),
                    self::ENV_DATABASE => self::driver(),
                    self::ENV_CACHE => self::cacheDriver(),
                    'CONCURRENCY_WORKER' => $worker,
                    'CONCURRENCY_SLOW_REQUESTS' => implode(',', $slowRequests),
                    'CONCURRENCY_DELAY_MS' => (string) self::DELAY_MS,
                    'APP_ENV' => 'testing',
                ],
                null,
                120,
            );
            $process->start();
            $processes[$worker] = $process;
        }

        $results = [];

        foreach ($processes as $worker => $process) {
            $process->wait();

            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $decoded = json_decode((string) end($lines), true);

            if (! $process->isSuccessful() || ! is_array($decoded)) {
                throw new \RuntimeException(sprintf(
                    "Worker %s failed (exit %s).\nstdout: %s\nstderr: %s",
                    $worker,
                    (string) $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            }

            $results[$worker] = $decoded;
        }

        return $results;
    }

    /**
     * Every request the fake Mollie saw from the workers, in order.
     *
     * @return list<array{worker: string, request: string, at: float}>
     */
    public static function mollieLog(): array
    {
        $file = self::directory().'/mollie.log';
        $entries = [];

        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && in_array($entry['worker'] ?? null, ['A', 'B'], true)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * What reached the fake Mollie, by request class, from the workers.
     *
     * @return array<string, int>
     */
    public static function mollieRequestsFromWorkers(): array
    {
        $counts = [];

        foreach (self::mollieLog() as $entry) {
            $counts[$entry['request']] = ($counts[$entry['request']] ?? 0) + 1;
        }

        return $counts;
    }
}

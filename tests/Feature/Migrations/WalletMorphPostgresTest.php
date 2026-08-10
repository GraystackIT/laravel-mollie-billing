<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The wallet morph migration on PostgreSQL, where dropping an index is not the same act as
 * dropping a unique constraint.
 *
 * `wallets` carries `unique(['holder_type', 'holder_id', 'slug'])` — bavix creates it through
 * the Blueprint, and Laravel's Postgres grammar emits `alter table … add constraint … unique`
 * for that. Postgres then refuses to drop the index the constraint is built on:
 *
 *   SQLSTATE[2BP01]: cannot drop index wallets_holder_type_holder_id_slug_unique because
 *   constraint wallets_holder_type_holder_id_slug_unique on table wallets requires it
 *
 * So `php artisan migrate` aborted for every consuming app on Postgres whose
 * `billable_key_type` is not `int`, halfway through retyping the morph. SQLite and MySQL
 * cannot see this: there `dropUnique()` and `dropIndex()` compile to the same statement, which
 * is exactly why the rest of the suite passed.
 *
 * Skipped unless a Postgres server is reachable. Point it somewhere with
 * `BILLING_PG_HOST` / `BILLING_PG_PORT` / `BILLING_PG_USER` / `BILLING_PG_PASSWORD` /
 * `BILLING_PG_DATABASE`.
 */
function pgConnectionOrSkip(): void
{
    config()->set('database.connections.pgcheck', [
        'driver' => 'pgsql',
        'host' => env('BILLING_PG_HOST', '127.0.0.1'),
        'port' => (int) env('BILLING_PG_PORT', 5432),
        'username' => env('BILLING_PG_USER', 'postgres'),
        'password' => (string) env('BILLING_PG_PASSWORD', ''),
        'database' => env('BILLING_PG_DATABASE', 'mollie_billing_pgcheck'),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);

    try {
        DB::connection('pgcheck')->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('No PostgreSQL server for the wallet morph migration: '.$e->getMessage());
    }

    // The migration reads and writes through the default connection.
    config()->set('database.default', 'pgcheck');
}

/** The two tables the migration touches, in the shape bavix ships them. */
function buildBavixWalletSchema(): void
{
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('wallets');

    Schema::create('wallets', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('holder_type');
        $table->unsignedBigInteger('holder_id');
        $table->string('name');
        $table->string('slug');
        $table->uuid('uuid');
        $table->decimal('balance', 64, 0)->default(0);
        $table->unsignedSmallInteger('decimal_places')->default(2);
        $table->timestamps();

        $table->index(['holder_type', 'holder_id']);
        // The constraint that makes `drop index` fail.
        $table->unique(['holder_type', 'holder_id', 'slug']);
    });

    Schema::create('transactions', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('payable_type');
        $table->unsignedBigInteger('payable_id');
        $table->unsignedBigInteger('wallet_id');
        $table->string('type', 8);
        $table->decimal('amount', 64, 0);
        $table->boolean('confirmed');
        $table->uuid('uuid');
        $table->timestamps();

        $table->index(['payable_type', 'payable_id'], 'payable_type_payable_id_ind');
        $table->index(['payable_type', 'payable_id', 'type'], 'payable_type_ind');
    });
}

afterEach(function (): void {
    if (config('database.default') !== 'pgcheck') {
        return;
    }

    Schema::dropIfExists('transactions');
    Schema::dropIfExists('wallets');

    // Back to the suite's own connection before RefreshDatabase tears down: its
    // transaction and its VACUUM belong to the in-memory SQLite database, and leaving the
    // default pointed at Postgres makes the teardown fail instead of the test.
    config()->set('database.default', 'testing');
});

it('retypes the wallet holder morph on PostgreSQL', function (): void {
    pgConnectionOrSkip();
    buildBavixWalletSchema();

    rerunWalletMorphMigration('uuid');

    $uuid = (string) Str::uuid();

    DB::table('wallets')->insert([
        'holder_type' => 'App\\Models\\Organization',
        'holder_id' => $uuid,
        'name' => 'Tokens',
        'slug' => 'tokens',
        'uuid' => (string) Str::uuid(),
        'balance' => 0,
        'decimal_places' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('wallets')->value('holder_id'))->toBe($uuid);
});

it('leaves the holder uniqueness enforced afterwards', function (): void {
    pgConnectionOrSkip();
    buildBavixWalletSchema();

    rerunWalletMorphMigration('uuid');

    // Dropping the constraint is a means, not the goal: one holder still gets one wallet per
    // slug, or a second `getWallet('Tokens')` would create a duplicate nothing reconciles.
    $uuid = (string) Str::uuid();

    $row = [
        'holder_type' => 'App\\Models\\Organization',
        'holder_id' => $uuid,
        'name' => 'Tokens',
        'slug' => 'tokens',
        'uuid' => (string) Str::uuid(),
        'balance' => 0,
        'decimal_places' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('wallets')->insert($row);

    expect(fn () => DB::table('wallets')->insert([...$row, 'uuid' => (string) Str::uuid()]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

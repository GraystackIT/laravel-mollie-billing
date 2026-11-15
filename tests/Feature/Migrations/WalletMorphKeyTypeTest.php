<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The wallet morph migration only runs when `billable_key_type` is not `int` — and
// `int` is what the suite pins, so nothing ever executed it. Both defects it carried
// only appear off that default: the migration aborted `php artisan migrate` halfway,
// and on the drivers where it did get through it destroyed bavix's `transfers` table.

// Guarded: WalletMorphPostgresTest uses it too and may be loaded by a different
// parallel worker than this file, or by the same one.
if (! function_exists('rerunWalletMorphMigration')) {
    function rerunWalletMorphMigration(string $keyType): void
    {
        config()->set('mollie-billing.billable_key_type', $keyType);

        $migration = require __DIR__.'/../../../database/migrations/2026_01_01_000006_alter_wallet_morphs_for_billable_key_type.php';
        $migration->up();
    }
}

/** @return array<int, string> */
function indexNamesOf(string $table): array
{
    return array_map(
        fn (array $index): string => (string) ($index['name'] ?? ''),
        Schema::getIndexes($table),
    );
}

it('retypes the wallet holder morph for uuid billable keys', function (): void {
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

it('retypes the wallet holder morph for ulid billable keys', function (): void {
    rerunWalletMorphMigration('ulid');

    $ulid = (string) Str::ulid();

    DB::table('wallets')->insert([
        'holder_type' => 'App\\Models\\Organization',
        'holder_id' => $ulid,
        'name' => 'Tokens',
        'slug' => 'tokens',
        'uuid' => (string) Str::uuid(),
        'balance' => 0,
        'decimal_places' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('wallets')->value('holder_id'))->toBe($ulid);
});

it('restores the holder/slug uniqueness the wallet package relies on', function (): void {
    // Dropping the morph columns drops this index with them. Losing it silently would
    // let one billable own two wallets of the same slug, which is how a balance stops
    // being a single number.
    rerunWalletMorphMigration('uuid');

    $holder = (string) Str::uuid();

    $row = fn (): array => [
        'holder_type' => 'App\\Models\\Organization',
        'holder_id' => $holder,
        'name' => 'Tokens',
        'slug' => 'tokens',
        'uuid' => (string) Str::uuid(),
        'balance' => 0,
        'decimal_places' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('wallets')->insert($row());

    expect(fn () => DB::table('wallets')->insert($row()))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('retypes the transaction payable morph and keeps every index bavix queries on', function (): void {
    rerunWalletMorphMigration('uuid');

    expect(indexNamesOf('transactions'))->toContain(
        'payable_type_payable_id_ind',
        'payable_type_ind',
        'payable_confirmed_ind',
        'payable_type_confirmed_ind',
    );

    $uuid = (string) Str::uuid();

    DB::table('transactions')->insert([
        'payable_type' => 'App\\Models\\Organization',
        'payable_id' => $uuid,
        'wallet_id' => 1,
        'type' => 'deposit',
        'amount' => 100,
        'confirmed' => true,
        'uuid' => (string) Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('transactions')->value('payable_id'))->toBe($uuid);
});

it('leaves the transfers table alone — from/to address a wallet, never the billable', function (): void {
    // `transfers.from_id`/`to_id` are wallet ids. Retyping them to uuid dropped the
    // host app's columns and re-added a NOT NULL `from_type` that bavix never fills,
    // so every transfer()/pay()/exchange() in the consuming app failed on insert.
    $before = Schema::getColumnListing('transfers');

    rerunWalletMorphMigration('uuid');

    expect(Schema::getColumnListing('transfers'))->toBe($before)
        ->and(Schema::hasColumn('transfers', 'from_type'))->toBeFalse()
        ->and(Schema::hasColumn('transfers', 'to_type'))->toBeFalse();

    DB::table('transfers')->insert([
        'from_id' => 1,
        'to_id' => 2,
        'status' => 'transfer',
        'deposit_id' => 1,
        'withdraw_id' => 2,
        'discount' => 0,
        'fee' => 0,
        'uuid' => (string) Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('transfers')->count())->toBe(1);
});

it('stays a no-op for integer billable keys', function (): void {
    $before = Schema::getColumnListing('wallets');

    rerunWalletMorphMigration('int');

    expect(Schema::getColumnListing('wallets'))->toBe($before);
});

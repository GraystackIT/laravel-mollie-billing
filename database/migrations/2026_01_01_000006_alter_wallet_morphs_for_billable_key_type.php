<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = config('mollie-billing.billable_key_type', 'uuid');

        if ($keyType === 'int') {
            return;
        }

        $walletsTable = (string) config('wallet.wallet.table', 'wallets');
        $transactionsTable = (string) config('wallet.transaction.table', 'transactions');

        // `wallets.holder` and `transactions.payable` are the only two morphs in the
        // wallet schema that point at the *billable*. `transfers.from` / `transfers.to`
        // deliberately are NOT touched: they have always addressed a wallet, never a
        // billable — bavix created them as `morphs('from')` whose type is the Wallet
        // model, and since bavix 9 (`extra_columns_removed`) they are plain bigint
        // wallet-id columns with no `_type` sibling at all. Retyping them to uuid
        // dropped the host app's `from_id`/`to_id` and re-added a NOT NULL `from_type`
        // that `Bavix\Wallet\Models\Transfer` never fills, so every `transfer()`,
        // `pay()` and `exchange()` in the consuming app failed on insert.
        $this->replaceMorph($walletsTable, 'holder', $keyType, function (Blueprint $table): void {
            $table->unique(['holder_type', 'holder_id', 'slug']);
        });

        $this->replaceMorph($transactionsTable, 'payable', $keyType, function (Blueprint $table): void {
            $table->index(['payable_type', 'payable_id'], 'payable_type_payable_id_ind');
            $table->index(['payable_type', 'payable_id', 'type'], 'payable_type_ind');
            $table->index(['payable_type', 'payable_id', 'confirmed'], 'payable_confirmed_ind');
            $table->index(['payable_type', 'payable_id', 'type', 'confirmed'], 'payable_type_confirmed_ind');
        });
    }

    public function down(): void
    {
        // Not reversible: bavix ships the original bigint morphs; reverting would
        // destroy holder references. Intentionally a no-op.
    }

    private function replaceMorph(string $table, string $name, string $keyType, ?\Closure $afterRecreate = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $typeColumn = $name.'_type';
        $idColumn = $name.'_id';

        // A half-present morph is schema drift in the wallet package, not something
        // to guess at: retyping one half would leave a column pair the models cannot
        // write. Skip rather than corrupt.
        if (! Schema::hasColumn($table, $typeColumn) || ! Schema::hasColumn($table, $idColumn)) {
            return;
        }

        $this->dropIndexesCovering($table, [$typeColumn, $idColumn]);

        Schema::table($table, function (Blueprint $t) use ($typeColumn, $idColumn): void {
            $t->dropColumn([$typeColumn, $idColumn]);
        });

        Schema::table($table, function (Blueprint $t) use ($typeColumn, $idColumn, $keyType, $afterRecreate): void {
            $t->string($typeColumn)->after('id');
            match ($keyType) {
                'ulid' => $t->ulid($idColumn)->after($typeColumn),
                default => $t->uuid($idColumn)->after($typeColumn),
            };
            $t->index([$typeColumn, $idColumn]);

            if ($afterRecreate) {
                $afterRecreate($t);
            }
        });
    }

    /**
     * Drop every index that touches one of `$columns`, whatever it is called.
     *
     * Enumerated from the live schema rather than by guessing names. bavix puts more
     * than one index on each morph — `wallets` also carries
     * `unique(holder_type, holder_id, slug)` and `transactions` four composite indexes —
     * and dropping a column that a surviving index still references is a hard error on
     * SQLite ("error in index … after drop column"), which aborted `php artisan migrate`
     * halfway for every install whose `billable_key_type` is not `int`.
     *
     * Going through the Blueprint also keeps this portable: the previous raw
     * `DROP INDEX IF EXISTS \`name\`` is not valid MySQL syntax at all (MySQL needs
     * `DROP INDEX … ON <table>`), so the same migration failed immediately there.
     *
     * @param  array<int, string>  $columns
     */
    private function dropIndexesCovering(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            $indexColumns = array_map(
                static fn (mixed $column): string => (string) $column,
                (array) ($index['columns'] ?? []),
            );

            if (array_intersect($indexColumns, $columns) === []) {
                continue;
            }

            $name = (string) ($index['name'] ?? '');

            if ($name === '') {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->dropIndex($name);
            });
        }
    }
};

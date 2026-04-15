<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $transfersTable = (string) config('wallet.transfer.table', 'transfers');

        $this->replaceMorph($walletsTable, 'holder', $keyType, function (Blueprint $table): void {
            $table->unique(['holder_type', 'holder_id', 'slug']);
        });

        foreach ([
            'payable_type_payable_id_ind',
            'payable_type_ind',
            'payable_confirmed_ind',
            'payable_type_confirmed_ind',
        ] as $extraIndex) {
            $this->dropIndexIfExists($extraIndex);
        }

        $this->replaceMorph($transactionsTable, 'payable', $keyType, function (Blueprint $table): void {
            $table->index(['payable_type', 'payable_id'], 'payable_type_payable_id_ind');
            $table->index(['payable_type', 'payable_id', 'type'], 'payable_type_ind');
            $table->index(['payable_type', 'payable_id', 'confirmed'], 'payable_confirmed_ind');
            $table->index(['payable_type', 'payable_id', 'type', 'confirmed'], 'payable_type_confirmed_ind');
        });

        $this->replaceMorph($transfersTable, 'from', $keyType);
        $this->replaceMorph($transfersTable, 'to', $keyType);
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

        $this->dropIndexIfExists($table.'_'.$name.'_type_'.$name.'_id_index');

        Schema::table($table, function (Blueprint $t) use ($table, $name): void {
            $columns = array_values(array_filter(
                [$name.'_type', $name.'_id'],
                fn (string $col): bool => Schema::hasColumn($table, $col),
            ));

            if ($columns !== []) {
                $t->dropColumn($columns);
            }
        });

        Schema::table($table, function (Blueprint $t) use ($name, $keyType, $afterRecreate): void {
            $t->string($name.'_type')->after('id');
            match ($keyType) {
                'ulid' => $t->ulid($name.'_id')->after($name.'_type'),
                default => $t->uuid($name.'_id')->after($name.'_type'),
            };
            $t->index([$name.'_type', $name.'_id']);

            if ($afterRecreate) {
                $afterRecreate($t);
            }
        });
    }

    private function dropIndexIfExists(string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement('DROP INDEX IF EXISTS "'.$indexName.'"'),
            'mysql', 'mariadb' => DB::statement('DROP INDEX IF EXISTS `'.$indexName.'`'),
            'sqlite' => DB::statement('DROP INDEX IF EXISTS "'.$indexName.'"'),
            default => null,
        };
    }
};

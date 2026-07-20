<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `activity_log`'s morph id columns when the table was created by
 * spatie/laravel-activitylog itself (i.e. the app published Spatie's migrations
 * before installing this package). Spatie's stub uses unsignedBigInteger, which
 * cannot hold this package's default uuid/ulid billable keys.
 *
 * Unlike alter_wallet_morphs_for_billable_key_type.php, this migration does NOT
 * drop and recreate the columns: the table may already hold the app's own
 * activity rows and dropping would destroy them. We widen to `string` in place,
 * which is lossless for existing integer ids and accommodates uuid/ulid alike.
 *
 * Skipped entirely when our own create migration built the table — detected via
 * the `subject_created` index, which only we add.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Our own create migration already sized the columns correctly.
        if ($this->hasIndex($table, 'subject_created')) {
            return;
        }

        $billableKeyType = (string) config('mollie-billing.billable_key_type', 'uuid');
        $userKeyType = (string) config('mollie-billing.user_key_type', 'int');

        // Only touch a column that is actually still numeric. Apps often widen
        // these themselves; re-running change() on an already-textual column is a
        // pointless table rebuild and could truncate a `text` column to varchar(255).
        $widenSubject = $billableKeyType !== 'int' && $this->isNumericColumn($table, 'subject_id');
        $widenCauser = $userKeyType !== 'int' && $this->isNumericColumn($table, 'causer_id');

        if ($widenSubject || $widenCauser) {
            $schema->table($table, function (Blueprint $blueprint) use ($widenSubject, $widenCauser): void {
                if ($widenSubject) {
                    $blueprint->string('subject_id')->nullable()->change();
                }

                if ($widenCauser) {
                    $blueprint->string('causer_id')->nullable()->change();
                }
            });
        }

        // The billable timeline filters by subject and orders by time; Spatie only
        // ships a plain (subject_type, subject_id) index.
        if (! $this->hasIndex($table, 'subject_created')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->index(['subject_type', 'subject_id', 'created_at'], 'subject_created');
            });
        }
    }

    public function down(): void
    {
        // Not reversible: narrowing the morph columns back to bigint would destroy
        // every uuid/ulid subject reference written since. Intentionally a no-op.
    }

    private function isNumericColumn(string $table, string $column): bool
    {
        $schema = Schema::connection(config('activitylog.database_connection'));

        if (! $schema->hasColumn($table, $column)) {
            return false;
        }

        try {
            $type = strtolower($schema->getColumnType($table, $column));
        } catch (\Throwable) {
            return false;
        }

        return in_array($type, ['integer', 'bigint', 'int', 'int4', 'int8', 'numeric', 'decimal'], true);
    }

    private function hasIndex(string $table, string $index): bool
    {
        $schema = Schema::connection(config('activitylog.database_connection'));

        foreach ($schema->getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};

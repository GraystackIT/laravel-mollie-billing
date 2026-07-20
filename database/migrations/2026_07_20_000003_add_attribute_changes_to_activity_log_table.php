<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog v5 writes model attribute diffs to a dedicated
 * `attribute_changes` column instead of nesting them in `properties`. Every
 * insert fails without it, and the billing audit listener swallows that error,
 * so the trail silently stays empty.
 *
 * Covers tables created before this package shipped the column, as well as
 * tables owned by the app or by spatie's own published migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'attribute_changes')) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->json('attribute_changes')->nullable()->after('properties');
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'attribute_changes')) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('attribute_changes');
        });
    }
};

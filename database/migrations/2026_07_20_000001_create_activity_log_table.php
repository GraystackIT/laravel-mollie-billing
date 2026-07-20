<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates spatie/laravel-activitylog's table ourselves instead of asking apps to
 * run `vendor:publish --tag=activitylog-migrations`.
 *
 * Spatie's own stub uses `nullableMorphs()`, i.e. an unsignedBigInteger id. This
 * package defaults `billable_key_type` to `uuid`, so that stub would break on the
 * very first insert. We therefore create `subject_id` / `causer_id` as plain
 * strings: the table is polymorphic, so it must accept integer-keyed *and*
 * uuid/ulid-keyed subjects and causers side by side — sizing it from the
 * configured key types would reject every other model the app audits.
 *
 * Also consolidates Spatie's three stubs (create + event column + batch_uuid
 * column) into one table definition.
 *
 * Idempotent: if the app already publishes/runs Spatie's migrations, this is a
 * no-op and the companion `alter_activity_log_morphs_for_key_types` migration
 * widens the morph columns instead.
 */
return new class extends Migration
{
    /** Schema-level proof that this migration created the table. */
    private const OWNERSHIP_MARKER_INDEX = 'billing_owns_activity_log';

    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $blueprint): void {
            $blueprint->bigIncrements('id');
            $blueprint->string('log_name')->nullable();
            $blueprint->text('description');

            $blueprint->string('subject_type')->nullable();
            $blueprint->string('subject_id')->nullable();
            $blueprint->string('event')->nullable();

            $blueprint->string('causer_type')->nullable();
            $blueprint->string('causer_id')->nullable();

            $blueprint->json('properties')->nullable();
            $blueprint->uuid('batch_uuid')->nullable();
            $blueprint->timestamps();

            // Deliberately named: down() only drops the table when this marker
            // index is present, i.e. when *we* created it. Rolling back must never
            // delete a table the app (or spatie's own migration) owns.
            $blueprint->index('log_name', self::OWNERSHIP_MARKER_INDEX);
            $blueprint->index(['causer_type', 'causer_id'], 'causer');

            // Drives the billable audit timeline: filter by subject, order by time.
            // Spatie only ships a plain (subject_type, subject_id) index.
            $blueprint->index(['subject_type', 'subject_id', 'created_at'], 'subject_created');
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection($connection);

        // up() was a no-op because the table already existed — the app (or
        // spatie's own migration) owns it, along with its rows. Leave it alone.
        if (! $schema->hasTable($table) || ! $this->hasOwnershipMarker($table)) {
            return;
        }

        $schema->drop($table);
    }

    private function hasOwnershipMarker(string $table): bool
    {
        $schema = Schema::connection(config('activitylog.database_connection'));

        foreach ($schema->getIndexes($table) as $index) {
            if (($index['name'] ?? null) === self::OWNERSHIP_MARKER_INDEX) {
                return true;
            }
        }

        return false;
    }
};

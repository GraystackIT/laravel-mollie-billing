<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Regression guard for the reason this package ships its own activity_log
// migration: spatie's stub uses nullableMorphs() (unsignedBigInteger), while
// mollie-billing defaults billable_key_type to 'uuid'. With spatie's schema the
// very first audit insert would fail. Same collision bavix had — see
// database/migrations/2026_01_01_000006_alter_wallet_morphs_for_billable_key_type.php.

function rerunActivityLogMigration(string $billableKeyType, string $userKeyType): void
{
    config()->set('mollie-billing.billable_key_type', $billableKeyType);
    config()->set('mollie-billing.user_key_type', $userKeyType);

    Schema::dropIfExists('activity_log');

    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000001_create_activity_log_table.php';
    $migration->up();
}

it('sizes the subject column so uuid billable keys fit', function (): void {
    rerunActivityLogMigration('uuid', 'int');

    $uuid = (string) Str::uuid();

    DB::table('activity_log')->insert([
        'log_name' => 'billing',
        'description' => 'audit.plan_changed',
        'subject_type' => 'App\\Models\\Organization',
        'subject_id' => $uuid,
        'event' => 'plan_changed',
        'properties' => json_encode(['category' => 'subscription']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('activity_log')->value('subject_id'))->toBe($uuid);
});

it('sizes the subject column so ulid billable keys fit', function (): void {
    rerunActivityLogMigration('ulid', 'int');

    $ulid = (string) Str::ulid();

    DB::table('activity_log')->insert([
        'log_name' => 'billing',
        'description' => 'audit.plan_changed',
        'subject_type' => 'App\\Models\\Organization',
        'subject_id' => $ulid,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('activity_log')->value('subject_id'))->toBe($ulid);
});

it('sizes the causer column from user_key_type independently of the billable', function (): void {
    rerunActivityLogMigration('int', 'uuid');

    $causer = (string) Str::uuid();

    DB::table('activity_log')->insert([
        'log_name' => 'billing',
        'description' => 'audit.plan_changed',
        'subject_type' => 'App\\Models\\Organization',
        'subject_id' => 42,
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $causer,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('activity_log')->value('causer_id'))->toBe($causer);
});

it('is a no-op when the table already exists', function (): void {
    // The app may have published spatie's migrations. We must not blow up, and we
    // must not touch their table.
    Schema::dropIfExists('activity_log');
    Schema::create('activity_log', function ($table): void {
        $table->bigIncrements('id');
        $table->string('description');
    });

    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000001_create_activity_log_table.php';

    expect(fn () => $migration->up())->not->toThrow(Throwable::class)
        ->and(Schema::hasColumn('activity_log', 'log_name'))->toBeFalse();
});

it('adds the timeline index the admin tab queries on', function (): void {
    rerunActivityLogMigration('uuid', 'int');

    $indexes = array_map(
        fn (array $i): string => (string) ($i['name'] ?? ''),
        Schema::getIndexes('activity_log'),
    );

    expect($indexes)->toContain('subject_created');
});

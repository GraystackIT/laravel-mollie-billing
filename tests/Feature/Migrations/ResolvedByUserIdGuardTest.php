<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('keeps resolver references when the column type already matches user_key_type', function (): void {
    expect(Schema::hasTable('billing_country_mismatches'))->toBeTrue();

    // Default user_key_type is `int`, and the create migration already made the column
    // an unsignedBigInteger — so re-running the alter migration must not drop it.
    config()->set('mollie-billing.user_key_type', 'int');

    DB::table('billing_country_mismatches')->insert([
        'billable_type' => 'App\\Models\\Organization',
        'billable_id' => 1,
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'status' => 'resolved',
        'resolved_at' => now(),
        'resolved_by_user_id' => 4242,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require __DIR__.'/../../../database/migrations/2026_05_05_000001_alter_resolved_by_user_id_for_user_key_type.php';
    $migration->up();

    // Dropping and recreating the column wiped the record of who resolved which
    // VAT-country mismatch on every migrate run.
    expect(DB::table('billing_country_mismatches')->value('resolved_by_user_id'))->toBe(4242);
});

it('is idempotent across repeated runs', function (): void {
    config()->set('mollie-billing.user_key_type', 'int');

    $migration = require __DIR__.'/../../../database/migrations/2026_05_05_000001_alter_resolved_by_user_id_for_user_key_type.php';

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('billing_country_mismatches', 'resolved_by_user_id'))->toBeTrue();
});

it('indexes subscription_status on the billable table', function (): void {
    // Every scheduler pass filters on it: PrepareUsageOverageJob passes 1/3/4,
    // ProcessTrialLifecycleJob, CleanupOrphanedBillablesJob.
    $stub = file_get_contents(__DIR__.'/../../../database/migrations/add_billing_columns_to_billable_table.php.stub');

    expect($stub)->toContain("\$table->string('subscription_status')->default(SubscriptionStatus::New->value)->index()");

    // …and the stub stays table-agnostic and idempotent.
    expect($stub)->toContain("config('mollie-billing.billable_model')");
    expect($stub)->toContain('Schema::getColumnListing($tableName)');
    expect($stub)->toContain('hasIndexOn');
});

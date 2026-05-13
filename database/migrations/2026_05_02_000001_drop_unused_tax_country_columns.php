<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('billing_country_mismatches', 'tax_country_ip')) {
            Schema::table('billing_country_mismatches', function (Blueprint $table): void {
                $table->dropColumn('tax_country_ip');
            });
        }

        $model = config('mollie-billing.billable_model');
        if (! is_string($model) || $model === '' || ! class_exists($model)) {
            return;
        }

        $tableName = (new $model)->getTable();
        $drop = array_values(array_filter(
            ['tax_country_ip', 'tax_country_verified'],
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));
        if ($drop !== []) {
            Schema::table($tableName, function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }

    public function down(): void
    {
        // No-op: column data cannot be restored.
    }
};

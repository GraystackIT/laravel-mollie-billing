<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_country_mismatches')) {
            return;
        }

        BillingCountryMismatch::query()->delete();

        try {
            Schema::table('billing_country_mismatches', function (Blueprint $table): void {
                $table->dropForeign(['corrective_invoice_id']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('billing_country_mismatches', function (Blueprint $table): void {
                $table->dropIndex('billing_country_mismatches_status_last_auto_idx');
            });
        } catch (\Throwable) {
        }

        $dropCandidates = [
            'resolved_strategy',
            'correction_invoices',
            'auto_resolve_attempts',
            'last_auto_attempt_at',
            'failure_reason',
            'corrective_invoice_id',
        ];
        $dropExisting = array_values(array_filter(
            $dropCandidates,
            fn (string $col): bool => Schema::hasColumn('billing_country_mismatches', $col),
        ));
        if ($dropExisting !== []) {
            Schema::table('billing_country_mismatches', function (Blueprint $table) use ($dropExisting): void {
                $table->dropColumn($dropExisting);
            });
        }

        Schema::table('billing_country_mismatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_country_mismatches', 'tax_country_ip')) {
                $table->string('tax_country_ip', 2)->nullable()->after('tax_country_payment');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'notified_at')) {
                $table->timestamp('notified_at')->nullable();
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'chosen_country')) {
                $table->string('chosen_country', 2)->nullable();
            }
        });

        $model = config('mollie-billing.billable_model');
        if ($model) {
            $tableName = (new $model)->getTable();
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'tax_country_ip')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('tax_country_ip', 2)->nullable();
                });
            }
        }

        if (Schema::hasTable('billing_invoices') && ! Schema::hasColumn('billing_invoices', 'mismatch_id')) {
            Schema::table('billing_invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('mismatch_id')->nullable();
                $table->foreign('mismatch_id')
                    ->references('id')->on('billing_country_mismatches')
                    ->nullOnDelete();
                $table->index('mismatch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_invoices') && Schema::hasColumn('billing_invoices', 'mismatch_id')) {
            Schema::table('billing_invoices', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['mismatch_id']);
                } catch (\Throwable) {
                }
                try {
                    $table->dropIndex(['mismatch_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('mismatch_id');
            });
        }

        $model = config('mollie-billing.billable_model');
        if ($model) {
            $tableName = (new $model)->getTable();
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tax_country_ip')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('tax_country_ip');
                });
            }
        }

        if (Schema::hasTable('billing_country_mismatches')) {
            Schema::table('billing_country_mismatches', function (Blueprint $table): void {
                if (Schema::hasColumn('billing_country_mismatches', 'tax_country_ip')) {
                    $table->dropColumn('tax_country_ip');
                }
                if (Schema::hasColumn('billing_country_mismatches', 'notified_at')) {
                    $table->dropColumn('notified_at');
                }
            });
        }
    }
};

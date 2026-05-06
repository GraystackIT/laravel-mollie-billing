<?php

declare(strict_types=1);

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

        Schema::table('billing_country_mismatches', function (Blueprint $t): void {
            if (! Schema::hasColumn('billing_country_mismatches', 'chosen_country')) {
                $t->string('chosen_country', 2)->nullable()->after('tax_country_payment');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'resolved_strategy')) {
                $t->string('resolved_strategy', 32)->nullable()->after('resolved_at');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'correction_invoices')) {
                $t->json('correction_invoices')->nullable()->after('corrective_invoice_id');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'auto_resolve_attempts')) {
                $t->unsignedSmallInteger('auto_resolve_attempts')->default(0)->after('correction_invoices');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'last_auto_attempt_at')) {
                $t->timestamp('last_auto_attempt_at')->nullable()->after('auto_resolve_attempts');
            }
            if (! Schema::hasColumn('billing_country_mismatches', 'failure_reason')) {
                $t->string('failure_reason')->nullable()->after('last_auto_attempt_at');
            }
        });

        if (! Schema::hasIndex('billing_country_mismatches', 'billing_country_mismatches_status_last_auto_idx')) {
            Schema::table('billing_country_mismatches', function (Blueprint $t): void {
                $t->index(['status', 'last_auto_attempt_at'], 'billing_country_mismatches_status_last_auto_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('billing_country_mismatches')) {
            return;
        }

        if (Schema::hasIndex('billing_country_mismatches', 'billing_country_mismatches_status_last_auto_idx')) {
            Schema::table('billing_country_mismatches', function (Blueprint $t): void {
                $t->dropIndex('billing_country_mismatches_status_last_auto_idx');
            });
        }

        Schema::table('billing_country_mismatches', function (Blueprint $t): void {
            $t->dropColumn([
                'chosen_country',
                'resolved_strategy',
                'correction_invoices',
                'auto_resolve_attempts',
                'last_auto_attempt_at',
                'failure_reason',
            ]);
        });
    }
};

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

        $userKeyType = (string) config('mollie-billing.user_key_type', 'int');

        Schema::table('billing_country_mismatches', function (Blueprint $t): void {
            if (Schema::hasColumn('billing_country_mismatches', 'resolved_by_user_id')) {
                $t->dropColumn('resolved_by_user_id');
            }
        });

        Schema::table('billing_country_mismatches', function (Blueprint $t) use ($userKeyType): void {
            match ($userKeyType) {
                'uuid' => $t->uuid('resolved_by_user_id')->nullable()->after('resolved_at'),
                'ulid' => $t->ulid('resolved_by_user_id')->nullable()->after('resolved_at'),
                default => $t->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolved_at'),
            };
        });
    }

    public function down(): void
    {
        // Not reversible: dropping and recreating the column would destroy resolver references.
        // Intentionally a no-op.
    }
};

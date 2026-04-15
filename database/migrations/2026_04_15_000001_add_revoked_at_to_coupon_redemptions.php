<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('coupon_redemptions', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('applied_at');
                $table->string('revoked_reason')->nullable()->after('revoked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            if (Schema::hasColumn('coupon_redemptions', 'revoked_reason')) {
                $table->dropColumn('revoked_reason');
            }
            if (Schema::hasColumn('coupon_redemptions', 'revoked_at')) {
                $table->dropColumn('revoked_at');
            }
        });
    }
};

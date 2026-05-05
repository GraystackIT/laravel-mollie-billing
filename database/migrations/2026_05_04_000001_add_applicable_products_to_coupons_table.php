<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->json('applicable_products')->nullable()->after('applicable_usages');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn('applicable_products');
        });
    }
};

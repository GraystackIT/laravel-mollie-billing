<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('vat_validation_id')->nullable()->after('country');
            $table->foreign('vat_validation_id')
                ->references('id')->on('billing_vat_validations')
                ->nullOnDelete();
            $table->index('vat_validation_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropForeign(['vat_validation_id']);
            $table->dropIndex(['vat_validation_id']);
            $table->dropColumn('vat_validation_id');
        });
    }
};

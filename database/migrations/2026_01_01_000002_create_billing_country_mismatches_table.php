<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_country_mismatches', function (Blueprint $table): void {
            $table->id();
            $table->string('billable_type');
            $this->morphIdColumn($table);

            $table->string('tax_country_user', 2);
            $table->string('tax_country_ip', 2)->nullable();
            $table->string('tax_country_payment', 2)->nullable();
            $table->string('status'); // CountryMismatchStatus enum
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by_user_id')->nullable();
            $table->unsignedBigInteger('corrective_invoice_id')->nullable();

            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
            $table->index('status');
            $table->foreign('corrective_invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_country_mismatches');
    }

    private function morphIdColumn(Blueprint $table): void
    {
        match (config('mollie-billing.billable_key_type', 'uuid')) {
            'int' => $table->unsignedBigInteger('billable_id'),
            'ulid' => $table->ulid('billable_id'),
            default => $table->uuid('billable_id'),
        };
    }
};

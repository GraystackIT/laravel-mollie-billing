<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('billable_type');
            $this->morphIdColumn($table);

            $table->string('mollie_payment_id')->unique();
            $table->string('mollie_subscription_id')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_path')->nullable();

            $table->string('invoice_kind'); // subscription | overage | prorata | credit_note
            $table->string('status');       // InvoiceStatus enum
            $table->string('country', 2);
            $table->decimal('vat_rate', 5, 2);
            $table->string('currency', 3)->default('EUR');
            $table->integer('amount_net');
            $table->integer('amount_vat');
            $table->integer('amount_gross');
            $table->json('line_items');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // Refund fields (populated for credit notes)
            $table->unsignedBigInteger('parent_invoice_id')->nullable();
            $table->integer('refunded_net')->default(0);
            $table->string('refund_reason_code')->nullable();
            $table->text('refund_reason_text')->nullable();

            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['billable_type', 'billable_id', 'created_at']);
            $table->foreign('parent_invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
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
